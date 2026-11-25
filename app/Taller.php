<?php
declare(strict_types=1);

/**
 * Reglas del taller. Cada operación valida permisos y datos en el servidor
 * y se ejecuta en una transacción con bloqueo de las filas que modifica.
 */
final class Taller
{
    public PDO $db;

    /** Flujo lineal de una orden. CANCELADA es un cierre alternativo fuera de este flujo. */
    const ESTADOS = ['RECIBIDO', 'EN DIAGNÓSTICO', 'EN REPARACIÓN', 'LISTO PARA ENTREGAR', 'ENTREGADO'];
    const CERRADOS = ['ENTREGADO', 'CANCELADA'];
    const ACTIVA_SQL = "estado NOT IN ('ENTREGADO','CANCELADA')";
    const COMBUSTIBLE = ['Reserva', '1/4', '1/2', '3/4', 'Lleno'];
    const MEDIOS_AUTORIZACION = ['Presencial', 'Teléfono', 'WhatsApp', 'Correo'];
    const METODOS_PAGO = ['Efectivo', 'Transferencia', 'Tarjeta'];
    const LIMITE_TOTAL = 999999999999;
    const FOTOS_POR_ORDEN = 20;
    const FOTO_MAX_BYTES = 8 * 1024 * 1024;

    /** Sumas por orden calculadas en la misma consulta (evita consultas por cada orden). */
    const SUMAS_SQL = '(SELECT COALESCE(SUM(d.cantidad*d.precio_centavos),0) FROM detalles_orden d WHERE d.orden_id=o.id) refacciones_suma,
        (SELECT COALESCE(SUM(d.cantidad*d.costo_centavos),0) FROM detalles_orden d WHERE d.orden_id=o.id) refacciones_costo_suma,
        (SELECT COALESCE(SUM(s.cantidad*s.precio_centavos),0) FROM servicios_orden s WHERE s.orden_id=o.id) servicios_suma,
        (SELECT COALESCE(SUM(p.monto_centavos),0) FROM pagos p WHERE p.orden_id=o.id AND p.anulado_en IS NULL) pagado_suma';

    function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ================================================================ Utilidades

    function filas(string $sql, array $valores = []): array
    {
        $q = $this->db->prepare($sql);
        $q->execute($valores);
        return $q->fetchAll();
    }

    function ejecutar(string $sql, array $valores = []): void
    {
        $this->db->prepare($sql)->execute($valores);
    }

    function uno(string $sql, array $valores = []): array
    {
        $r = $this->filas($sql, $valores);
        if (!$r) throw new Regla('No se encontró el registro.', 404);
        return $r[0];
    }

    function auditoria(array $u, string $entidad, int $id, string $accion): void
    {
        $this->ejecutar('INSERT INTO auditoria(usuario_id,entidad,registro_id,accion) VALUES(?,?,?,?)', [$u['id'], $entidad, $id, mb_substr($accion, 0, 250)]);
    }

    /** Ejecuta en una transacción. Si ya hay una abierta, la operación forma parte de ella. */
    function transaccion(callable $accion)
    {
        if ($this->db->inTransaction()) return $accion();
        $this->db->beginTransaction();
        try {
            $r = $accion();
            $this->db->commit();
            return $r;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
                throw new Regla('El registro ya existe o esta operación ya fue registrada. Actualiza la información antes de volver a intentarlo.', 409);
            }
            throw $e;
        }
    }

    /** Clave única que envía cada formulario para impedir que una operación se registre dos veces. */
    function clave(array $d): string
    {
        $v = texto($d, 'operacion', 64);
        if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $v)) throw new Regla('La operación no es válida. Vuelve a abrir el formulario.');
        return $v;
    }

    static function pesos(int $centavos): string
    {
        return '$' . number_format($centavos / 100, 2, '.', ',') . ' MXN';
    }

    /** Tasa de IVA configurada, en centésimas de punto (16 % = 1600). */
    static function tasaIva(): int
    {
        $v = trim((string)(configuracion()['IVA_TASA'] ?? '16'));
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/D', $v)) return 1600;
        return (int)round((float)$v * 100);
    }

    static function datosTaller(): array
    {
        $c = configuracion();
        return [
            'nombre' => $c['TALLER_NOMBRE'] ?? ($c['APP_NAME'] ?? 'MecaniGest'),
            'direccion' => $c['TALLER_DIRECCION'] ?? '',
            'telefono' => $c['TALLER_TELEFONO'] ?? '',
            'rfc' => $c['TALLER_RFC'] ?? '',
        ];
    }

    // ================================================================ Órdenes: lectura y totales

    function orden(array $u, int $id, bool $bloquear = false): array
    {
        $o = $this->uno('SELECT * FROM ordenes_servicio WHERE id=?' . ($bloquear ? ' FOR UPDATE' : ''), [$id]);
        if ($u['rol'] === 'mecanico' && (int)$o['mecanico_id'] !== (int)$u['id']) {
            throw new Regla('Solo puedes consultar tus órdenes asignadas.', 403);
        }
        return $o;
    }

    /** Agrega a la orden las sumas de refacciones, servicios y pagos vigentes. */
    function conSumas(array $o): array
    {
        $sumas = $this->uno('SELECT ' . self::SUMAS_SQL . ' FROM ordenes_servicio o WHERE o.id=?', [$o['id']]);
        return array_merge($o, $sumas);
    }

    /**
     * Total = (mano de obra + servicios + refacciones − descuento) + IVA.
     * Todo en centavos enteros; el IVA se redondea al centavo más cercano.
     */
    static function calcular(array $o): array
    {
        $refacciones = (int)$o['refacciones_suma'];
        $servicios = (int)$o['servicios_suma'];
        $subtotal = (int)$o['mano_obra_centavos'] + $servicios + $refacciones;
        $descuento = (int)$o['descuento_centavos'];
        $base = $subtotal - $descuento;
        $iva = $base > 0 ? intdiv($base * (int)$o['iva_tasa'] + 5000, 10000) : 0;
        $total = $base + $iva;
        $pagado = (int)$o['pagado_suma'];
        return [
            'servicios_centavos' => $servicios,
            'refacciones_centavos' => $refacciones,
            'subtotal_centavos' => $subtotal,
            'iva_centavos' => $iva,
            'total_centavos' => $total,
            'pagado_centavos' => $pagado,
            'saldo_centavos' => $total - $pagado,
            'estado_pago' => $total === 0 ? 'SIN CARGO' : ($pagado >= $total ? 'PAGADO' : ($pagado > 0 ? 'PARCIAL' : 'PENDIENTE')),
        ];
    }

    function totales(array $o): array
    {
        return self::calcular($this->conSumas($o));
    }

    /** Comprueba que una orden (con cambios propuestos) tenga importes válidos. */
    static function validarImportes(array $o): array
    {
        $t = self::calcular($o);
        if ($t['subtotal_centavos'] - (int)$o['descuento_centavos'] < 0) throw new Regla('El descuento no puede ser mayor que el subtotal de la orden.');
        if ($t['total_centavos'] > self::LIMITE_TOTAL) throw new Regla('El importe total excede el límite admitido.');
        if ($t['total_centavos'] < $t['pagado_centavos']) throw new Regla('El total no puede quedar por debajo de los pagos registrados (' . self::pesos($t['pagado_centavos']) . ').');
        return $t;
    }

    /** Prepara una fila de orden para enviarla: agrega totales y oculta costos a quien no administra. */
    private function publicarOrden(array $u, array $o): array
    {
        $o = array_merge($o, self::calcular($o));
        if ($u['rol'] === 'administrador') {
            $o['refacciones_costo_centavos'] = (int)$o['refacciones_costo_suma'];
        }
        unset($o['refacciones_suma'], $o['servicios_suma'], $o['pagado_suma'], $o['refacciones_costo_suma']);
        return $o;
    }

    function listadoOrdenes(array $u): array
    {
        $sql = 'SELECT o.*, c.nombre cliente, c.telefono cliente_telefono, v.marca, v.modelo, v.placas, u.nombre mecanico, ' . self::SUMAS_SQL . '
            FROM ordenes_servicio o
            JOIN clientes c ON c.id=o.cliente_id
            JOIN vehiculos v ON v.id=o.vehiculo_id
            JOIN usuarios u ON u.id=o.mecanico_id';
        $valores = [];
        if ($u['rol'] === 'mecanico') {
            $sql .= ' WHERE o.mecanico_id=?';
            $valores = [$u['id']];
        }
        return array_map(fn($o) => $this->publicarOrden($u, $o), $this->filas($sql . ' ORDER BY o.id DESC', $valores));
    }

    function datos(array $u): array
    {
        $esMecanico = $u['rol'] === 'mecanico';
        $esAdmin = $u['rol'] === 'administrador';
        $clientes = $this->filas('SELECT c.* FROM clientes c'
            . ($esMecanico ? ' WHERE c.id IN (SELECT cliente_id FROM ordenes_servicio WHERE mecanico_id=?)' : '')
            . ' ORDER BY c.nombre', $esMecanico ? [$u['id']] : []);
        $vehiculos = $this->filas('SELECT v.*, c.nombre propietario,
                (SELECT o.kilometraje FROM ordenes_servicio o WHERE o.vehiculo_id=v.id AND o.kilometraje IS NOT NULL ORDER BY o.id DESC LIMIT 1) ultimo_km
            FROM vehiculos v JOIN clientes c ON c.id=v.cliente_id'
            . ($esMecanico ? ' WHERE v.id IN (SELECT vehiculo_id FROM ordenes_servicio WHERE mecanico_id=?)' : '')
            . ' ORDER BY v.id DESC', $esMecanico ? [$u['id']] : []);
        $usuarios = $this->filas($esAdmin
            ? 'SELECT id,nombre,correo,rol,activo FROM usuarios ORDER BY nombre'
            : "SELECT id,nombre,rol,activo FROM usuarios WHERE rol='mecanico' ORDER BY nombre");
        $pagos = $esMecanico ? [] : $this->filas('SELECT p.*, u.nombre registrado_por, ua.nombre anulado_por_nombre, c.nombre cliente
            FROM pagos p
            JOIN usuarios u ON u.id=p.usuario_id
            LEFT JOIN usuarios ua ON ua.id=p.anulado_por
            JOIN ordenes_servicio o ON o.id=p.orden_id
            JOIN clientes c ON c.id=o.cliente_id
            ORDER BY p.id DESC');
        $refacciones = $this->filas('SELECT * FROM refacciones ORDER BY nombre');
        if (!$esAdmin) {
            foreach ($refacciones as &$r) unset($r['costo_centavos']);
            unset($r);
        }
        return [
            'usuario' => $u,
            'clientes' => $clientes,
            'vehiculos' => $vehiculos,
            'ordenes' => $this->listadoOrdenes($u),
            'refacciones' => $refacciones,
            'servicios' => $this->filas('SELECT * FROM servicios ORDER BY nombre'),
            'usuarios' => $usuarios,
            'pagos' => $pagos,
            'demo' => (configuracion()['APP_DEMO'] ?? '0') === '1',
            'hoy' => date('Y-m-d'),
            'iva_tasa' => self::tasaIva(),
            'taller' => self::datosTaller(),
            'ultimo_respaldo' => $esAdmin ? Mantenimiento::ultimoRespaldo() : null,
        ];
    }

    function detalle(array $u, int $id): array
    {
        $this->orden($u, $id);
        $esAdmin = $u['rol'] === 'administrador';
        $o = $this->uno('SELECT o.*, ' . self::SUMAS_SQL . ',
                c.nombre cliente, c.telefono cliente_telefono, c.correo cliente_correo, c.direccion cliente_direccion,
                v.marca, v.modelo, v.anio, v.placas, v.vin,
                m.nombre mecanico, ua.nombre autorizado_por_nombre, uc.nombre cancelado_por_nombre, ur.nombre creado_por_nombre
            FROM ordenes_servicio o
            JOIN clientes c ON c.id=o.cliente_id
            JOIN vehiculos v ON v.id=o.vehiculo_id
            JOIN usuarios m ON m.id=o.mecanico_id
            JOIN usuarios ur ON ur.id=o.creado_por
            LEFT JOIN usuarios ua ON ua.id=o.autorizado_por
            LEFT JOIN usuarios uc ON uc.id=o.cancelado_por
            WHERE o.id=?', [$id]);
        $o = $this->publicarOrden($u, $o);
        $o['detalles'] = $this->filas('SELECT * FROM detalles_orden WHERE orden_id=? ORDER BY id', [$id]);
        if (!$esAdmin) {
            foreach ($o['detalles'] as &$d) unset($d['costo_centavos']);
            unset($d);
        }
        $o['servicios'] = $this->filas('SELECT s.*, u.nombre registrado_por FROM servicios_orden s JOIN usuarios u ON u.id=s.usuario_id WHERE orden_id=? ORDER BY s.id', [$id]);
        $o['pagos'] = $u['rol'] === 'mecanico' ? [] : $this->filas('SELECT p.*, u.nombre registrado_por, ua.nombre anulado_por_nombre
            FROM pagos p JOIN usuarios u ON u.id=p.usuario_id LEFT JOIN usuarios ua ON ua.id=p.anulado_por
            WHERE orden_id=? ORDER BY p.id', [$id]);
        $o['fotos'] = $this->filas('SELECT f.id, f.descripcion, f.creado_en, u.nombre usuario FROM fotos_orden f JOIN usuarios u ON u.id=f.usuario_id WHERE orden_id=? ORDER BY f.id', [$id]);
        $o['actividad'] = $this->filas("SELECT a.*, u.nombre usuario FROM auditoria a JOIN usuarios u ON u.id=a.usuario_id WHERE entidad='orden' AND registro_id=? ORDER BY a.id DESC", [$id]);
        $o['taller'] = self::datosTaller();
        return $o;
    }

    // ================================================================ Catálogos

    function guardar(array $u, string $entidad, array $d): int
    {
        $tablas = ['clientes', 'vehiculos', 'refacciones', 'usuarios', 'servicios'];
        if (!in_array($entidad, $tablas, true)) throw new Regla('Operación no disponible.', 404);
        rol($u, in_array($entidad, ['usuarios', 'refacciones', 'servicios'], true) ? ['administrador'] : ['administrador', 'recepcionista']);

        return $this->transaccion(function () use ($u, $entidad, $d) {
            $id = empty($d['id']) ? 0 : entero($d['id']);
            $anterior = $id ? $this->uno("SELECT * FROM $entidad WHERE id=? FOR UPDATE", [$id]) : [];

            if ($entidad === 'clientes') {
                $campos = [
                    'nombre' => texto($d, 'nombre', 120),
                    'telefono' => texto($d, 'telefono', 25),
                    'correo' => texto($d, 'correo', 190, false),
                    'direccion' => texto($d, 'direccion', 250, false),
                ];
                if (!preg_match('/^[+0-9 ()-]{7,25}$/D', $campos['telefono'])) throw new Regla('Escribe un teléfono válido, con al menos 7 caracteres.');
                if ($campos['correo'] !== '' && !filter_var($campos['correo'], FILTER_VALIDATE_EMAIL)) throw new Regla('El correo electrónico no es válido.');
            } elseif ($entidad === 'vehiculos') {
                $cid = entero($d['cliente_id'] ?? 0);
                $c = $this->uno('SELECT activo FROM clientes WHERE id=? FOR UPDATE', [$cid]);
                if (!$c['activo']) throw new Regla('Selecciona un cliente activo.');
                if ($id && $cid !== (int)$anterior['cliente_id']) {
                    $n = $this->uno('SELECT COUNT(*) n FROM ordenes_servicio WHERE vehiculo_id=? AND ' . self::ACTIVA_SQL, [$id]);
                    if ($n['n']) throw new Regla('Entrega o cancela las órdenes activas antes de cambiar al propietario.');
                }
                $vin = mb_strtoupper(texto($d, 'vin', 17, false));
                if ($vin !== '' && !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/D', $vin)) {
                    throw new Regla('El número de serie (VIN) tiene 17 caracteres, letras y números, sin I, O ni Q.');
                }
                $campos = [
                    'cliente_id' => $cid,
                    'marca' => texto($d, 'marca', 60),
                    'modelo' => texto($d, 'modelo', 80),
                    'anio' => entero($d['anio'] ?? 0, 1900, (int)date('Y') + 1),
                    'placas' => mb_strtoupper(texto($d, 'placas', 20)),
                    'vin' => $vin === '' ? null : $vin,
                ];
                if (!preg_match('/^[A-Z0-9 -]{3,20}$/D', $campos['placas'])) throw new Regla('Revisa las placas del vehículo.');
            } elseif ($entidad === 'refacciones') {
                $campos = [
                    'nombre' => texto($d, 'nombre', 120),
                    'descripcion' => texto($d, 'descripcion', 500, false),
                    'proveedor' => texto($d, 'proveedor', 120, false),
                    'precio_centavos' => centavos($d['precio'] ?? ''),
                    'costo_centavos' => centavosOpcional($d['costo'] ?? '') ?? 0,
                ];
            } elseif ($entidad === 'servicios') {
                $campos = [
                    'nombre' => texto($d, 'nombre', 120),
                    'descripcion' => texto($d, 'descripcion', 500, false),
                    'precio_centavos' => centavos($d['precio'] ?? ''),
                ];
            } else {
                $campos = [
                    'nombre' => texto($d, 'nombre', 120),
                    'correo' => mb_strtolower(texto($d, 'correo', 190)),
                    'rol' => texto($d, 'rol', 20),
                ];
                if (!filter_var($campos['correo'], FILTER_VALIDATE_EMAIL)) throw new Regla('El correo electrónico no es válido.');
                if (!in_array($campos['rol'], ['administrador', 'recepcionista', 'mecanico'], true)) throw new Regla('Selecciona un perfil válido.');
                if ($id && $anterior['rol'] !== $campos['rol']) $this->puedeCambiarUsuario($u, $anterior);
                $clave = texto($d, 'clave', 72, !$id);
                if ($clave !== '') {
                    validarClaveNueva($clave);
                    $campos['clave_hash'] = password_hash($clave, PASSWORD_DEFAULT);
                }
            }

            if ($id) {
                $set = implode(',', array_map(fn($k) => "$k=?", array_keys($campos)));
                $this->ejecutar("UPDATE $entidad SET $set WHERE id=?", array_merge(array_values($campos), [$id]));
            } else {
                $columnas = implode(',', array_keys($campos));
                $marcas = implode(',', array_fill(0, count($campos), '?'));
                $this->ejecutar("INSERT INTO $entidad($columnas) VALUES($marcas)", array_values($campos));
                $id = (int)$this->db->lastInsertId();
            }
            $this->auditoria($u, $entidad, $id, $anterior ? 'Información actualizada' : 'Registro creado');
            return $id;
        });
    }

    function puedeCambiarUsuario(array $actor, array $anterior): void
    {
        if ((int)$actor['id'] === (int)$anterior['id']) throw new Regla('No puedes cambiar tu propio perfil ni desactivar tu sesión.');
        $admins = $this->filas("SELECT id FROM usuarios WHERE rol='administrador' AND activo=1 FOR UPDATE");
        if ($anterior['rol'] === 'administrador' && $anterior['activo'] && count($admins) <= 1) throw new Regla('Debe permanecer al menos un administrador activo.');
        $n = $this->uno('SELECT COUNT(*) n FROM ordenes_servicio WHERE mecanico_id=? AND ' . self::ACTIVA_SQL, [$anterior['id']]);
        if ($n['n']) throw new Regla('Reasigna las órdenes activas de este usuario antes de modificar su perfil o desactivarlo.');
    }

    function activar(array $u, string $entidad, array $d): void
    {
        if (!in_array($entidad, ['clientes', 'vehiculos', 'refacciones', 'usuarios', 'servicios'], true)) {
            throw new Regla('No se permite eliminar ni desactivar órdenes históricas.', 405);
        }
        rol($u, ['administrador']);
        $this->transaccion(function () use ($u, $entidad, $d) {
            $id = entero($d['id'] ?? 0);
            $activo = entero($d['activo'] ?? -1, 0, 1);
            $anterior = $this->uno("SELECT * FROM $entidad WHERE id=? FOR UPDATE", [$id]);
            if ($entidad === 'usuarios' && !$activo) $this->puedeCambiarUsuario($u, $anterior);
            $this->ejecutar("UPDATE $entidad SET activo=? WHERE id=?", [$activo, $id]);
            $this->auditoria($u, $entidad, $id, $activo ? 'Registro reactivado' : 'Registro inactivado; historial conservado');
        });
    }

    /** Cambio de contraseña de la propia cuenta. */
    function cambiarClave(array $u, array $d): void
    {
        $actual = texto($d, 'actual', 72);
        $nueva = texto($d, 'nueva', 72);
        validarClaveNueva($nueva);
        if ($actual === $nueva) throw new Regla('La contraseña nueva debe ser distinta de la actual.');
        $this->transaccion(function () use ($u, $actual, $nueva) {
            $fila = $this->uno('SELECT clave_hash FROM usuarios WHERE id=? AND activo=1 FOR UPDATE', [$u['id']]);
            if (!password_verify($actual, $fila['clave_hash'])) throw new Regla('La contraseña actual no es correcta.');
            $this->ejecutar('UPDATE usuarios SET clave_hash=? WHERE id=?', [password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
            $this->auditoria($u, 'usuarios', (int)$u['id'], 'Contraseña cambiada por el propio usuario');
        });
    }

    // ================================================================ Recepción y flujo de la orden

    /** Datos del vehículo al recibirlo: kilometraje, combustible y notas de su estado. */
    private function datosRecepcion(array $d): array
    {
        $km = trim((string)($d['kilometraje'] ?? ''));
        $combustible = texto($d, 'nivel_combustible', 10, false);
        if ($combustible !== '' && !in_array($combustible, self::COMBUSTIBLE, true)) throw new Regla('Selecciona un nivel de combustible válido.');
        return [
            'kilometraje' => $km === '' ? null : entero($km, 0, 3000000),
            'nivel_combustible' => $combustible === '' ? null : $combustible,
            'notas_recepcion' => texto($d, 'notas_recepcion', 2000, false),
        ];
    }

    function crearOrden(array $u, array $d): int
    {
        rol($u, ['administrador', 'recepcionista']);
        return $this->transaccion(function () use ($u, $d) {
            $v = $this->uno('SELECT * FROM vehiculos WHERE id=? FOR UPDATE', [entero($d['vehiculo_id'] ?? 0)]);
            $c = $this->uno('SELECT * FROM clientes WHERE id=? FOR UPDATE', [$v['cliente_id']]);
            if (!$v['activo'] || !$c['activo']) throw new Regla('El cliente y el vehículo deben estar activos.');
            $m = $this->uno("SELECT id FROM usuarios WHERE id=? AND activo=1 AND rol='mecanico' FOR UPDATE", [entero($d['mecanico_id'] ?? 0)]);
            if ($this->filas('SELECT id FROM ordenes_servicio WHERE vehiculo_id=? AND ' . self::ACTIVA_SQL, [$v['id']])) {
                throw new Regla('Este vehículo ya tiene una orden activa. Consulta esa orden antes de abrir otra.');
            }
            $r = $this->datosRecepcion($d);
            $this->ejecutar("INSERT INTO ordenes_servicio(cliente_id,vehiculo_id,mecanico_id,falla,kilometraje,nivel_combustible,notas_recepcion,diagnostico,reparaciones,creado_por)
                VALUES(?,?,?,?,?,?,?,'','',?)", [$c['id'], $v['id'], $m['id'], texto($d, 'falla', 4000), $r['kilometraje'], $r['nivel_combustible'], $r['notas_recepcion'], $u['id']]);
            $id = (int)$this->db->lastInsertId();
            $this->auditoria($u, 'orden', $id, 'Vehículo recibido y orden abierta' . ($r['kilometraje'] !== null ? ' · ' . number_format($r['kilometraje']) . ' km' : ''));
            return $id;
        });
    }

    /**
     * Recibir un vehículo en un solo paso: usa el vehículo existente o registra cliente
     * y vehículo nuevos, y abre la orden. Si algo falla no se guarda nada.
     * $d: vehiculo_id | (cliente_id | cliente{nombre,telefono,correo}) + vehiculo{marca,modelo,anio,placas,vin}
     */
    function recibirVehiculo(array $u, array $d): int
    {
        rol($u, ['administrador', 'recepcionista']);
        return $this->transaccion(function () use ($u, $d) {
            $vehiculoId = empty($d['vehiculo_id']) ? 0 : entero($d['vehiculo_id']);
            if (!$vehiculoId) {
                $clienteId = empty($d['cliente_id']) ? 0 : entero($d['cliente_id']);
                if (!$clienteId) {
                    if (!is_array($d['cliente'] ?? null)) throw new Regla('Indica el cliente: elige uno registrado o captura sus datos.');
                    $clienteId = $this->guardar($u, 'clientes', array_intersect_key($d['cliente'], array_flip(['nombre', 'telefono', 'correo', 'direccion'])));
                }
                if (!is_array($d['vehiculo'] ?? null)) throw new Regla('Indica los datos del vehículo.');
                $vehiculo = array_intersect_key($d['vehiculo'], array_flip(['marca', 'modelo', 'anio', 'placas', 'vin']));
                $vehiculoId = $this->guardar($u, 'vehiculos', ['cliente_id' => $clienteId] + $vehiculo);
            }
            return $this->crearOrden($u, ['vehiculo_id' => $vehiculoId] + $d);
        });
    }

    function recepcion(array $u, int $id, array $d): void
    {
        rol($u, ['administrador', 'recepcionista']);
        $this->transaccion(function () use ($u, $id, $d) {
            $o = $this->orden($u, $id, true);
            if (in_array($o['estado'], self::CERRADOS, true)) throw new Regla('La orden está cerrada; sus datos de recepción ya no se modifican.');
            $r = $this->datosRecepcion($d);
            $this->ejecutar('UPDATE ordenes_servicio SET kilometraje=?, nivel_combustible=?, notas_recepcion=? WHERE id=?', [$r['kilometraje'], $r['nivel_combustible'], $r['notas_recepcion'], $id]);
            $this->auditoria($u, 'orden', $id, 'Datos de recepción actualizados');
        });
    }

    function tecnica(array $u, int $id, array $d): void
    {
        rol($u, ['administrador', 'mecanico']);
        $this->transaccion(function () use ($u, $id, $d) {
            $o = $this->orden($u, $id, true);
            if (!in_array($o['estado'], ['EN DIAGNÓSTICO', 'EN REPARACIÓN'], true)) throw new Regla('Solo puedes editar el trabajo durante diagnóstico o reparación.');
            $mano = centavos($d['mano_obra'] ?? '');
            $presupuesto = centavosOpcional($d['presupuesto'] ?? '');
            self::validarImportes(array_merge($this->conSumas($o), ['mano_obra_centavos' => $mano]));
            $this->ejecutar('UPDATE ordenes_servicio SET diagnostico=?, reparaciones=?, mano_obra_centavos=?, presupuesto_centavos=?, presupuesto_detalle=? WHERE id=?', [
                texto($d, 'diagnostico', 6000, false),
                texto($d, 'reparaciones', 6000, false),
                $mano,
                $presupuesto,
                texto($d, 'presupuesto_detalle', 2000, false),
                $id,
            ]);
            $cambio = $presupuesto !== null && $presupuesto !== ($o['presupuesto_centavos'] === null ? null : (int)$o['presupuesto_centavos'])
                ? ' · presupuesto ' . self::pesos($presupuesto) : '';
            $this->auditoria($u, 'orden', $id, 'Diagnóstico, reparación y mano de obra actualizados' . $cambio);
        });
    }

    /** Autorización del cliente sobre el presupuesto. Necesaria para iniciar la reparación. */
    function autorizar(array $u, int $id, array $d): void
    {
        rol($u, ['administrador', 'recepcionista']);
        $this->transaccion(function () use ($u, $id, $d) {
            $o = $this->orden($u, $id, true);
            if (!in_array($o['estado'], ['RECIBIDO', 'EN DIAGNÓSTICO', 'EN REPARACIÓN', 'LISTO PARA ENTREGAR'], true)) throw new Regla('Esta orden ya no admite autorizaciones.');
            $monto = centavos($d['monto'] ?? '');
            if ($monto <= 0) throw new Regla('Indica el monto que autorizó el cliente.');
            $medio = texto($d, 'medio', 20);
            if (!in_array($medio, self::MEDIOS_AUTORIZACION, true)) throw new Regla('Selecciona cómo autorizó el cliente.');
            $this->ejecutar('UPDATE ordenes_servicio SET autorizado_centavos=?, autorizacion_medio=?, autorizacion_nota=?, autorizado_por=?, autorizado_en=NOW() WHERE id=?', [
                $monto, $medio, texto($d, 'nota', 250, false), $u['id'], $id,
            ]);
            $this->auditoria($u, 'orden', $id, 'Cliente autorizó ' . self::pesos($monto) . ' · ' . $medio);
        });
    }

    function asignar(array $u, int $id, array $d): void
    {
        rol($u, ['administrador', 'recepcionista']);
        $this->transaccion(function () use ($u, $id, $d) {
            $o = $this->orden($u, $id, true);
            if (in_array($o['estado'], self::CERRADOS, true)) throw new Regla('La orden cerrada conserva su mecánico histórico.');
            $m = $this->uno("SELECT id,nombre FROM usuarios WHERE id=? AND rol='mecanico' AND activo=1 FOR UPDATE", [entero($d['mecanico_id'] ?? 0)]);
            $this->ejecutar('UPDATE ordenes_servicio SET mecanico_id=? WHERE id=?', [$m['id'], $id]);
            $this->auditoria($u, 'orden', $id, 'Mecánico asignado: ' . $m['nombre']);
        });
    }

    function estado(array $u, int $id, array $d): void
    {
        $this->transaccion(function () use ($u, $id, $d) {
            $o = $this->orden($u, $id, true);
            $nuevo = texto($d, 'estado', 30);
            rol($u, $nuevo === 'ENTREGADO' ? ['administrador', 'recepcionista'] : ['administrador', 'mecanico']);
            $idx = array_search($o['estado'], self::ESTADOS, true);
            if ($idx === false || (self::ESTADOS[$idx + 1] ?? null) !== $nuevo) {
                throw new Regla('El estado cambió o la transición no está permitida. Actualiza la orden.', 409);
            }
            if ($nuevo === 'EN REPARACIÓN') {
                if (trim($o['diagnostico']) === '') throw new Regla('Registra el diagnóstico antes de iniciar la reparación.');
                if ($o['autorizado_en'] === null) throw new Regla('Falta la autorización del cliente. Recepción debe registrarla antes de iniciar la reparación.');
            }
            if ($nuevo === 'LISTO PARA ENTREGAR') {
                if (trim($o['reparaciones']) === '') throw new Regla('Documenta las reparaciones antes de terminar el trabajo.');
                $t = $this->totales($o);
                if ($o['autorizado_centavos'] !== null && $t['total_centavos'] > (int)$o['autorizado_centavos']) {
                    throw new Regla('El total (' . self::pesos($t['total_centavos']) . ') supera lo autorizado por el cliente (' . self::pesos((int)$o['autorizado_centavos']) . '). Recepción debe registrar una nueva autorización.');
                }
            }
            $this->ejecutar('UPDATE ordenes_servicio SET estado=?, fecha_entrega=? WHERE id=?', [$nuevo, $nuevo === 'ENTREGADO' ? date('Y-m-d H:i:s') : null, $id]);
            $this->auditoria($u, 'orden', $id, $o['estado'] . ' → ' . $nuevo);
        });
    }

    /** Cierra una orden sin reparar (por ejemplo, el cliente no autorizó). Conserva importes y pagos. */
    function cancelar(array $u, int $id, array $d): void
    {
        rol($u, ['administrador', 'recepcionista']);
        $this->transaccion(function () use ($u, $id, $d) {
            $o = $this->orden($u, $id, true);
            if (in_array($o['estado'], self::CERRADOS, true)) throw new Regla('La orden ya está cerrada.');
            $motivo = texto($d, 'motivo', 250);
            $this->ejecutar("UPDATE ordenes_servicio SET estado='CANCELADA', cancelado_motivo=?, cancelado_por=?, cancelado_en=NOW() WHERE id=?", [$motivo, $u['id'], $id]);
            $this->auditoria($u, 'orden', $id, $o['estado'] . ' → CANCELADA · ' . $motivo);
        });
    }

    // ================================================================ Conceptos de la orden

    function agregarServicio(array $u, int $id, array $d): void
    {
        rol($u, ['administrador', 'recepcionista', 'mecanico']);
        $this->transaccion(function () use ($u, $id, $d) {
            $o = $this->orden($u, $id, true);
            if (!in_array($o['estado'], ['RECIBIDO', 'EN DIAGNÓSTICO', 'EN REPARACIÓN'], true)) throw new Regla('Los servicios se agregan antes de terminar el trabajo.');
            $s = $this->uno('SELECT * FROM servicios WHERE id=? FOR UPDATE', [entero($d['servicio_id'] ?? 0)]);
            if (!$s['activo']) throw new Regla('Selecciona un servicio activo del catálogo.');
            $n = entero($d['cantidad'] ?? 0, 1, 100);
            $op = $this->clave($d);
            $sumas = $this->conSumas($o);
            $sumas['servicios_suma'] = (int)$sumas['servicios_suma'] + $n * (int)$s['precio_centavos'];
            self::validarImportes($sumas);
            $this->ejecutar('INSERT INTO servicios_orden(orden_id,servicio_id,nombre_historico,cantidad,precio_centavos,usuario_id,operacion) VALUES(?,?,?,?,?,?,?)', [
                $id, $s['id'], $s['nombre'], $n, $s['precio_centavos'], $u['id'], $op,
            ]);
            $this->auditoria($u, 'orden', $id, 'Servicio agregado: ' . $n . ' × ' . $s['nombre'] . ' (' . self::pesos((int)$s['precio_centavos']) . ')');
        });
    }

    function quitarServicio(array $u, int $id, array $d): void
    {
        rol($u, ['administrador', 'recepcionista', 'mecanico']);
        $this->transaccion(function () use ($u, $id, $d) {
            $o = $this->orden($u, $id, true);
            if (!in_array($o['estado'], ['RECIBIDO', 'EN DIAGNÓSTICO', 'EN REPARACIÓN'], true)) throw new Regla('Los servicios ya no se pueden modificar en esta etapa.');
            $linea = $this->uno('SELECT * FROM servicios_orden WHERE id=? AND orden_id=? FOR UPDATE', [entero($d['linea_id'] ?? 0), $id]);
            $sumas = $this->conSumas($o);
            $sumas['servicios_suma'] = (int)$sumas['servicios_suma'] - (int)$linea['cantidad'] * (int)$linea['precio_centavos'];
            self::validarImportes($sumas);
            $this->ejecutar('DELETE FROM servicios_orden WHERE id=?', [$linea['id']]);
            $this->auditoria($u, 'orden', $id, 'Servicio quitado: ' . $linea['cantidad'] . ' × ' . $linea['nombre_historico']);
        });
    }

    function usarRefaccion(array $u, int $id, array $d): void
    {
        rol($u, ['administrador', 'mecanico']);
        $this->transaccion(function () use ($u, $id, $d) {
            $o = $this->orden($u, $id, true);
            if ($o['estado'] !== 'EN REPARACIÓN') throw new Regla('Las refacciones se registran durante la reparación.');
            $r = $this->uno('SELECT * FROM refacciones WHERE id=? FOR UPDATE', [entero($d['refaccion_id'] ?? 0)]);
            $n = entero($d['cantidad'] ?? 0, 1, 10000);
            $op = $this->clave($d);
            if (!$r['activo'] || $n > (int)$r['existencia']) throw new Regla('No hay existencias suficientes de esta refacción activa.');
            $sumas = $this->conSumas($o);
            $sumas['refacciones_suma'] = (int)$sumas['refacciones_suma'] + $n * (int)$r['precio_centavos'];
            self::validarImportes($sumas);
            $this->ejecutar('INSERT INTO detalles_orden(orden_id,refaccion_id,nombre_historico,cantidad,precio_centavos,costo_centavos,operacion) VALUES(?,?,?,?,?,?,?)', [
                $id, $r['id'], $r['nombre'], $n, $r['precio_centavos'], $r['costo_centavos'], $op,
            ]);
            $detalle = (int)$this->db->lastInsertId();
            $this->ejecutar('UPDATE refacciones SET existencia=existencia-? WHERE id=?', [$n, $r['id']]);
            $this->ejecutar("INSERT INTO movimientos_inventario(refaccion_id,tipo,cantidad,costo_unitario_centavos,usuario_id,orden_id,detalle_id,motivo,operacion) VALUES(?,'SALIDA POR ORDEN',?,?,?,?,?,?,?)", [
                $r['id'], $n, $r['costo_centavos'], $u['id'], $id, $detalle, 'Utilización en reparación', $op,
            ]);
            $this->auditoria($u, 'orden', $id, 'Refacción utilizada: ' . $n . ' × ' . $r['nombre']);
        });
    }

    /** Descuento e IVA de la orden. */
    function ajustes(array $u, int $id, array $d): void
    {
        rol($u, ['administrador', 'recepcionista']);
        $this->transaccion(function () use ($u, $id, $d) {
            $o = $this->orden($u, $id, true);
            if (in_array($o['estado'], self::CERRADOS, true)) throw new Regla('Los importes de una orden cerrada no se modifican.');
            $descuento = centavosOpcional($d['descuento'] ?? '') ?? 0;
            $motivo = texto($d, 'descuento_motivo', 250, false);
            if ($descuento > 0 && $motivo === '') throw new Regla('Indica el motivo del descuento.');
            $tasa = in_array((string)($d['aplicar_iva'] ?? '0'), ['1', 'true', 'on'], true) ? ((int)$o['iva_tasa'] ?: self::tasaIva()) : 0;
            $t = self::validarImportes(array_merge($this->conSumas($o), ['descuento_centavos' => $descuento, 'iva_tasa' => $tasa]));
            $this->ejecutar('UPDATE ordenes_servicio SET descuento_centavos=?, descuento_motivo=?, iva_tasa=? WHERE id=?', [$descuento, $descuento > 0 ? $motivo : '', $tasa, $id]);
            $this->auditoria($u, 'orden', $id, 'Descuento ' . self::pesos($descuento) . ($tasa ? ' · IVA ' . ($tasa / 100) . ' %' : ' · sin IVA') . ' · total ' . self::pesos($t['total_centavos']));
        });
    }

    // ================================================================ Inventario

    function entrada(array $u, array $d): void
    {
        rol($u, ['administrador']);
        $this->transaccion(function () use ($u, $d) {
            $r = $this->uno('SELECT * FROM refacciones WHERE id=? FOR UPDATE', [entero($d['refaccion_id'] ?? 0)]);
            $n = entero($d['cantidad'] ?? 0, 1, 10000);
            if (!$r['activo'] || $n + (int)$r['existencia'] > 1000000) throw new Regla('Activa la refacción y verifica que la existencia no exceda un millón de unidades.');
            $costo = centavosOpcional($d['costo'] ?? '');
            $this->ejecutar("INSERT INTO movimientos_inventario(refaccion_id,tipo,cantidad,costo_unitario_centavos,usuario_id,motivo,operacion) VALUES(?,'ENTRADA',?,?,?,?,?)", [
                $r['id'], $n, $costo, $u['id'], texto($d, 'motivo', 250), $this->clave($d),
            ]);
            $this->ejecutar('UPDATE refacciones SET existencia=existencia+?' . ($costo !== null ? ', costo_centavos=?' : '') . ' WHERE id=?', $costo !== null ? [$n, $costo, $r['id']] : [$n, $r['id']]);
            $this->auditoria($u, 'refacciones', (int)$r['id'], 'Entrada de ' . $n . ' unidades' . ($costo !== null ? ' · costo ' . self::pesos($costo) . ' c/u' : ''));
        });
    }

    function movimientos(array $u, int $id): array
    {
        rol($u, ['administrador']);
        return $this->filas('SELECT m.*, u.nombre usuario FROM movimientos_inventario m JOIN usuarios u ON u.id=m.usuario_id WHERE refaccion_id=? ORDER BY m.id DESC', [$id]);
    }

    // ================================================================ Pagos

    function pagar(array $u, int $id, array $d): void
    {
        rol($u, ['administrador', 'recepcionista']);
        $this->transaccion(function () use ($u, $id, $d) {
            $o = $this->orden($u, $id, true);
            $t = $this->totales($o);
            $m = centavos($d['monto'] ?? '');
            if ($m <= 0 || $m > $t['saldo_centavos']) throw new Regla('El pago debe ser mayor a cero y no superar el saldo pendiente.');
            $metodo = texto($d, 'metodo', 20);
            if (!in_array($metodo, self::METODOS_PAGO, true)) throw new Regla('Selecciona un método de pago válido.');
            $this->ejecutar('INSERT INTO pagos(orden_id,monto_centavos,metodo,usuario_id,operacion) VALUES(?,?,?,?,?)', [$id, $m, $metodo, $u['id'], $this->clave($d)]);
            $this->auditoria($u, 'orden', $id, 'Pago registrado: ' . self::pesos($m));
        });
    }

    /** Anula un pago registrado por error. El pago se conserva marcado como anulado. */
    function anularPago(array $u, array $d): void
    {
        rol($u, ['administrador']);
        $this->transaccion(function () use ($u, $d) {
            $p = $this->uno('SELECT * FROM pagos WHERE id=? FOR UPDATE', [entero($d['pago_id'] ?? 0)]);
            $this->orden($u, (int)$p['orden_id'], true);
            if ($p['anulado_en'] !== null) throw new Regla('Este pago ya fue anulado.');
            $motivo = texto($d, 'motivo', 250);
            $this->ejecutar('UPDATE pagos SET anulado_en=NOW(), anulado_por=?, anulacion_motivo=? WHERE id=?', [$u['id'], $motivo, $p['id']]);
            $this->auditoria($u, 'orden', (int)$p['orden_id'], 'Pago anulado: ' . self::pesos((int)$p['monto_centavos']) . ' · ' . $motivo);
        });
    }

    // ================================================================ Fotos

    static function carpetaFotos(): string
    {
        return dirname(__DIR__) . '/storage/fotos';
    }

    function guardarFoto(array $u, int $id, array $archivo, string $descripcion): int
    {
        rol($u, ['administrador', 'recepcionista', 'mecanico']);
        $this->orden($u, $id);
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'] ?? '')) {
            throw new Regla(($archivo['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'La foto es demasiado grande.' : 'No se recibió la foto. Inténtalo de nuevo.');
        }
        if ($archivo['size'] > self::FOTO_MAX_BYTES) throw new Regla('La foto supera 8 MB.');
        $info = @getimagesize($archivo['tmp_name']);
        $tipos = [IMAGETYPE_JPEG => ['jpg', 'image/jpeg'], IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_WEBP => ['webp', 'image/webp']];
        if (!$info || !isset($tipos[$info[2]])) throw new Regla('El archivo debe ser una imagen JPG, PNG o WEBP.');
        [$extension, $mime] = $tipos[$info[2]];

        $carpeta = self::carpetaFotos();
        if (!is_dir($carpeta) && !mkdir($carpeta, 0700, true)) throw new RuntimeException('No se pudo preparar la carpeta de fotos.');
        $nombre = bin2hex(random_bytes(16)) . '.' . $extension;
        $destino = $carpeta . '/' . $nombre;
        if (!move_uploaded_file($archivo['tmp_name'], $destino)) throw new RuntimeException('No se pudo guardar la foto.');

        try {
            return $this->transaccion(function () use ($u, $id, $nombre, $mime, $archivo, $descripcion) {
                $this->orden($u, $id, true);
                $n = (int)$this->uno('SELECT COUNT(*) n FROM fotos_orden WHERE orden_id=?', [$id])['n'];
                if ($n >= self::FOTOS_POR_ORDEN) throw new Regla('La orden ya tiene el máximo de ' . self::FOTOS_POR_ORDEN . ' fotos.');
                $this->ejecutar('INSERT INTO fotos_orden(orden_id,archivo,tipo,tamano,descripcion,usuario_id) VALUES(?,?,?,?,?,?)', [$id, $nombre, $mime, (int)$archivo['size'], $descripcion, $u['id']]);
                $fotoId = (int)$this->db->lastInsertId();
                $this->auditoria($u, 'orden', $id, 'Foto agregada' . ($descripcion !== '' ? ': ' . $descripcion : ''));
                return $fotoId;
            });
        } catch (Throwable $e) {
            @unlink($destino);
            throw $e;
        }
    }

    /** @return array{0:string,1:string} ruta del archivo y tipo MIME */
    function foto(array $u, int $fotoId): array
    {
        $f = $this->uno('SELECT * FROM fotos_orden WHERE id=?', [$fotoId]);
        $this->orden($u, (int)$f['orden_id']);
        $ruta = self::carpetaFotos() . '/' . basename($f['archivo']);
        if (!is_file($ruta)) throw new Regla('No se encontró el archivo de la foto.', 404);
        return [$ruta, $f['tipo']];
    }

    // ================================================================ Reportes

    function reportes(array $u, string $desde, string $hasta): array
    {
        rol($u, ['administrador']);
        foreach ([$desde, $hasta] as $v) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) || !($f = DateTime::createFromFormat('!Y-m-d', $v)) || $f->format('Y-m-d') !== $v) throw new Regla('Selecciona fechas válidas.');
        }
        if ($desde > $hasta) throw new Regla('La fecha inicial no puede ser posterior a la final.');
        $limite = (new DateTime($hasta))->modify('+1 day')->format('Y-m-d');
        $rango = [$desde, $limite];

        $pagos = $this->filas('SELECT p.*, c.nombre cliente FROM pagos p JOIN ordenes_servicio o ON o.id=p.orden_id JOIN clientes c ON c.id=o.cliente_id
            WHERE p.creado_en>=? AND p.creado_en<? AND p.anulado_en IS NULL ORDER BY p.creado_en DESC', $rango);
        $anulados = $this->filas('SELECT p.*, c.nombre cliente, ua.nombre anulado_por_nombre FROM pagos p JOIN ordenes_servicio o ON o.id=p.orden_id JOIN clientes c ON c.id=o.cliente_id JOIN usuarios ua ON ua.id=p.anulado_por
            WHERE p.anulado_en>=? AND p.anulado_en<? ORDER BY p.anulado_en DESC', $rango);
        $piezas = $this->filas('SELECT d.nombre_historico nombre, SUM(d.cantidad) cantidad, SUM(d.cantidad*d.precio_centavos) importe_centavos, SUM(d.cantidad*d.costo_centavos) costo_centavos
            FROM detalles_orden d WHERE d.creado_en>=? AND d.creado_en<? GROUP BY d.refaccion_id, d.nombre_historico ORDER BY importe_centavos DESC', $rango);
        $servicios = $this->filas('SELECT s.nombre_historico nombre, SUM(s.cantidad) cantidad, SUM(s.cantidad*s.precio_centavos) importe_centavos
            FROM servicios_orden s WHERE s.creado_en>=? AND s.creado_en<? GROUP BY s.servicio_id, s.nombre_historico ORDER BY importe_centavos DESC', $rango);

        $ordenes = $this->listadoOrdenes($u);
        $entregadas = array_values(array_filter($ordenes, fn($o) => $o['estado'] === 'ENTREGADO' && $o['fecha_entrega'] >= $desde && $o['fecha_entrega'] < $limite));
        $canceladas = array_values(array_filter($ordenes, fn($o) => $o['estado'] === 'CANCELADA' && $o['cancelado_en'] >= $desde && $o['cancelado_en'] < $limite));
        usort($entregadas, fn($a, $b) => strcmp($b['fecha_entrega'], $a['fecha_entrega']));

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'pagos' => $pagos,
            'pagos_anulados' => $anulados,
            'servicios' => $entregadas,
            'canceladas' => $canceladas,
            'piezas' => $piezas,
            'servicios_vendidos' => $servicios,
            'ingresos_centavos' => array_sum(array_map('intval', array_column($pagos, 'monto_centavos'))),
            'refacciones_venta_centavos' => array_sum(array_map('intval', array_column($piezas, 'importe_centavos'))),
            'refacciones_costo_centavos' => array_sum(array_map('intval', array_column($piezas, 'costo_centavos'))),
            'descuentos_centavos' => array_sum(array_map('intval', array_column($entregadas, 'descuento_centavos'))),
            'iva_centavos' => array_sum(array_column($entregadas, 'iva_centavos')),
            'vehiculos_atendidos' => count(array_unique(array_column($entregadas, 'vehiculo_id'))),
            'pendientes' => array_values(array_filter($ordenes, fn($o) => !in_array($o['estado'], self::CERRADOS, true))),
            'por_cobrar' => array_values(array_filter($ordenes, fn($o) => $o['saldo_centavos'] > 0)),
        ];
    }
}
