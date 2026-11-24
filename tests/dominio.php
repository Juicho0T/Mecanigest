<?php
declare(strict_types=1);
// Pruebas de las reglas del taller en una base temporal que se elimina al terminar.
// Uso: php tests/dominio.php
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit;

$c = configuracion();
$nombre = 'mecanigest_test_' . bin2hex(random_bytes(5));
$raiz = new PDO('mysql:host=' . $c['DB_HOST'] . ';port=' . $c['DB_PORT'], $c['DB_USER'], $c['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raiz->exec("CREATE DATABASE `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$restaurada = $nombre . '_r';
$n = 0;

function verifica(bool $ok, string $mensaje): void
{
    global $n;
    if (!$ok) throw new RuntimeException('FALLO: ' . $mensaje);
    echo 'OK ' . ++$n . ' ' . $mensaje . "\n";
}
function rechaza(callable $fn, string $mensaje, string $texto = ''): void
{
    try {
        $fn();
    } catch (Regla $e) {
        if ($texto !== '' && mb_stripos($e->getMessage(), $texto) === false) throw new RuntimeException("FALLO: $mensaje (mensaje inesperado: {$e->getMessage()})");
        verifica(true, $mensaje);
        return;
    }
    throw new RuntimeException('FALLO: se permitió ' . $mensaje);
}
$op = fn() => bin2hex(random_bytes(16));

try {
    $db = conectar($nombre);
    $db->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    verifica(count(Mantenimiento::migrar($db)) >= 1, 'Migraciones aplicadas sobre el esquema base');
    verifica(Mantenimiento::pendientes($db) === [], 'No quedan migraciones pendientes');
    $t = new Taller($db);
    foreach (['administrador', 'recepcionista', 'mecanico', 'mecanico'] as $i => $r) {
        $t->ejecutar('INSERT INTO usuarios(nombre,correo,rol,clave_hash) VALUES(?,?,?,?)', ['Prueba ' . $i, $i . '@ejemplo.local', $r, password_hash('clave-inicial-' . $i, PASSWORD_DEFAULT)]);
    }
    [$a, $r, $m, $otro] = $t->filas('SELECT * FROM usuarios ORDER BY id');

    // ---------- Clientes, vehículos y recepción
    $cid = $t->guardar($r, 'clientes', ['nombre' => 'Cliente de prueba', 'telefono' => '6180000000']);
    verifica($cid > 0, 'Registrar cliente');
    rechaza(fn() => $t->guardar($r, 'vehiculos', ['cliente_id' => $cid, 'marca' => 'M', 'modelo' => 'X', 'anio' => 2020, 'placas' => 'VIN-01', 'vin' => 'ABC']), 'Rechazar VIN inválido', 'VIN');
    $vid = $t->guardar($r, 'vehiculos', ['cliente_id' => $cid, 'marca' => 'Marca', 'modelo' => 'Modelo', 'anio' => 2020, 'placas' => 'TEST-01', 'vin' => '3n1cn7ad5kl123456']);
    verifica($t->uno('SELECT vin FROM vehiculos WHERE id=?', [$vid])['vin'] === '3N1CN7AD5KL123456', 'Registrar vehículo con VIN en mayúsculas');
    rechaza(fn() => $t->crearOrden($r, ['vehiculo_id' => $vid, 'mecanico_id' => $m['id'], 'falla' => 'X', 'nivel_combustible' => 'Medio']), 'Rechazar nivel de combustible inválido');
    $id = $t->crearOrden($r, ['vehiculo_id' => $vid, 'mecanico_id' => $m['id'], 'falla' => 'Falla reportada', 'kilometraje' => '85400', 'nivel_combustible' => '1/4', 'notas_recepcion' => 'Golpe en defensa trasera']);
    $o = $t->detalle($a, $id);
    verifica($o['kilometraje'] == 85400 && $o['nivel_combustible'] === '1/4' && $o['notas_recepcion'] === 'Golpe en defensa trasera', 'Crear orden con kilometraje, combustible y notas de recepción');
    verifica($t->datos($a)['vehiculos'][0]['ultimo_km'] == 85400, 'El vehículo muestra su último kilometraje');
    $t->recepcion($r, $id, ['kilometraje' => '85410', 'nivel_combustible' => '1/2', 'notas_recepcion' => 'Golpe en defensa trasera; sin tapón']);
    verifica($t->detalle($a, $id)['kilometraje'] == 85410, 'Corregir datos de recepción');
    rechaza(fn() => $t->recepcion($m, $id, ['kilometraje' => '1']), 'Mecánico no modifica la recepción');
    rechaza(fn() => $t->crearOrden($r, ['vehiculo_id' => $vid, 'mecanico_id' => $m['id'], 'falla' => 'Duplicada']), 'Evitar dos órdenes activas para un vehículo');
    rechaza(fn() => $t->detalle($otro, $id), 'Impedir acceso a orden ajena');
    rechaza(fn() => $t->estado($r, $id, ['estado' => 'EN DIAGNÓSTICO']), 'Recepción no altera estado técnico');

    // ---------- Catálogo de servicios, presupuesto y autorización
    $afinacion = $t->guardar($a, 'servicios', ['nombre' => 'Afinación', 'precio' => '1200.00']);
    $revision = $t->guardar($a, 'servicios', ['nombre' => 'Revisión', 'precio' => '250.00']);
    rechaza(fn() => $t->guardar($r, 'servicios', ['nombre' => 'X', 'precio' => '1.00']), 'Solo administración edita el catálogo de servicios');
    $t->estado($m, $id, ['estado' => 'EN DIAGNÓSTICO']);
    verifica($t->orden($m, $id)['estado'] === 'EN DIAGNÓSTICO', 'Cambiar a diagnóstico');
    rechaza(fn() => $t->estado($m, $id, ['estado' => 'EN REPARACIÓN']), 'Exigir diagnóstico', 'diagnóstico');
    $t->tecnica($m, $id, ['diagnostico' => 'Diagnóstico manual', 'reparaciones' => '', 'mano_obra' => '100.00', 'presupuesto' => '2200.00', 'presupuesto_detalle' => 'Afinación y piezas']);
    $t->agregarServicio($m, $id, ['servicio_id' => $afinacion, 'cantidad' => 1, 'operacion' => $op()]);
    $t->agregarServicio($m, $id, ['servicio_id' => $revision, 'cantidad' => 1, 'operacion' => $op()]);
    verifica($t->detalle($a, $id)['servicios_centavos'] === 145000, 'Servicios del catálogo suman $1,450.00');
    $linea = $t->detalle($a, $id)['servicios'][1]['id'];
    $t->quitarServicio($m, $id, ['linea_id' => $linea]);
    verifica($t->detalle($a, $id)['servicios_centavos'] === 120000, 'Quitar un servicio de la orden');
    rechaza(fn() => $t->estado($m, $id, ['estado' => 'EN REPARACIÓN']), 'Exigir autorización del cliente para reparar', 'autorización');
    rechaza(fn() => $t->autorizar($m, $id, ['monto' => '2200.00', 'medio' => 'Teléfono']), 'Mecánico no registra autorizaciones');
    rechaza(fn() => $t->autorizar($r, $id, ['monto' => '2200.00', 'medio' => 'Paloma mensajera']), 'Rechazar medio de autorización inválido');
    $t->autorizar($r, $id, ['monto' => '2200.00', 'medio' => 'WhatsApp', 'nota' => 'Aceptó por mensaje']);
    verifica($t->detalle($a, $id)['autorizado_centavos'] == 220000, 'Registrar autorización del cliente');
    $t->estado($m, $id, ['estado' => 'EN REPARACIÓN']);
    $t->tecnica($m, $id, ['diagnostico' => 'Diagnóstico manual', 'reparaciones' => 'Trabajo documentado', 'mano_obra' => '100.00', 'presupuesto' => '2200.00']);
    verifica($t->orden($m, $id)['reparaciones'] === 'Trabajo documentado', 'Registrar reparación');

    // ---------- Refacciones con costo
    $pieza = $t->guardar($a, 'refacciones', ['nombre' => 'Pieza A', 'precio' => '300.00', 'costo' => '180.00']);
    $t->entrada($a, ['refaccion_id' => $pieza, 'cantidad' => 3, 'motivo' => 'Prueba', 'operacion' => $op()]);
    $t->entrada($a, ['refaccion_id' => $pieza, 'cantidad' => 1, 'costo' => '200.00', 'motivo' => 'Compra nueva', 'operacion' => $op()]);
    verifica((int)$t->uno('SELECT costo_centavos FROM refacciones WHERE id=?', [$pieza])['costo_centavos'] === 20000, 'La entrada con costo actualiza el último costo');
    $datos = ['refaccion_id' => $pieza, 'cantidad' => 2, 'operacion' => $op()];
    $t->usarRefaccion($m, $id, $datos);
    verifica((int)$t->uno('SELECT existencia FROM refacciones WHERE id=?', [$pieza])['existencia'] === 2, 'Agregar refacción y descontar stock');
    rechaza(fn() => $t->usarRefaccion($m, $id, $datos), 'No duplicar uso de refacción');
    rechaza(fn() => $t->usarRefaccion($m, $id, ['refaccion_id' => $pieza, 'cantidad' => 3, 'operacion' => $op()]), 'Rechazar stock insuficiente');
    rechaza(fn() => $t->usarRefaccion($m, $id, ['refaccion_id' => $pieza, 'cantidad' => -1, 'operacion' => $op()]), 'Rechazar cantidad negativa');
    $det = $t->detalle($a, $id);
    verifica($det['refacciones_costo_centavos'] === 40000 && (int)$det['detalles'][0]['costo_centavos'] === 20000, 'Costo histórico de la refacción guardado');
    verifica(!array_key_exists('costo_centavos', $t->detalle($r, $id)['detalles'][0]) && !array_key_exists('refacciones_costo_centavos', $t->detalle($r, $id)), 'Recepción no ve costos');
    verifica(!array_key_exists('costo_centavos', $t->datos($m)['refacciones'][0]), 'El mecánico no ve costos del inventario');
    // Total: 100 mano + 1200 servicio + 600 piezas = 1900
    verifica($t->detalle($a, $id)['total_centavos'] === 190000, 'Total exacto de $1,900.00');

    // ---------- Descuento e IVA
    rechaza(fn() => $t->ajustes($r, $id, ['descuento' => '100.00', 'descuento_motivo' => '', 'aplicar_iva' => '0']), 'Exigir motivo del descuento');
    rechaza(fn() => $t->ajustes($r, $id, ['descuento' => '5000.00', 'descuento_motivo' => 'X', 'aplicar_iva' => '0']), 'Descuento no mayor al subtotal');
    rechaza(fn() => $t->ajustes($m, $id, ['descuento' => '0', 'aplicar_iva' => '1']), 'Mecánico no aplica descuentos');
    $t->ajustes($r, $id, ['descuento' => '100.00', 'descuento_motivo' => 'Cliente frecuente', 'aplicar_iva' => '1']);
    $det = $t->detalle($a, $id);
    // (1900 − 100) × 1.16 = 2088
    verifica($det['iva_centavos'] === 28800 && $det['total_centavos'] === 208800, 'Descuento e IVA 16 %: total $2,088.00');
    verifica(Taller::calcular(['refacciones_suma' => 0, 'servicios_suma' => 0, 'mano_obra_centavos' => 3, 'descuento_centavos' => 0, 'iva_tasa' => 1600, 'pagado_suma' => 0])['iva_centavos'] === 0
        && Taller::calcular(['refacciones_suma' => 0, 'servicios_suma' => 0, 'mano_obra_centavos' => 4, 'descuento_centavos' => 0, 'iva_tasa' => 1600, 'pagado_suma' => 0])['iva_centavos'] === 1, 'IVA redondeado al centavo');

    // ---------- Pagos y anulación
    $t->pagar($r, $id, ['monto' => '1000.00', 'metodo' => 'Efectivo', 'operacion' => $op()]);
    verifica($t->detalle($a, $id)['saldo_centavos'] === 108800, 'Pago parcial y saldo de $1,088.00');
    rechaza(fn() => $t->pagar($m, $id, ['monto' => '1.00', 'metodo' => 'Efectivo', 'operacion' => $op()]), 'Mecánico no registra pagos');
    rechaza(fn() => $t->pagar($r, $id, ['monto' => '1088.01', 'metodo' => 'Efectivo', 'operacion' => $op()]), 'Rechazar sobrepago');
    rechaza(fn() => $t->pagar($r, $id, ['monto' => '0.00', 'metodo' => 'Efectivo', 'operacion' => $op()]), 'Rechazar pago cero');
    rechaza(fn() => $t->ajustes($r, $id, ['descuento' => '1900.00', 'descuento_motivo' => 'Todo', 'aplicar_iva' => '0']), 'El descuento no deja el total bajo lo pagado', 'pagos');
    $t->pagar($r, $id, ['monto' => '500.00', 'metodo' => 'Tarjeta', 'operacion' => $op()]);
    $pagoErroneo = (int)$t->detalle($a, $id)['pagos'][1]['id'];
    rechaza(fn() => $t->anularPago($r, ['pago_id' => $pagoErroneo, 'motivo' => 'Error']), 'Recepción no anula pagos');
    rechaza(fn() => $t->anularPago($a, ['pago_id' => $pagoErroneo, 'motivo' => '']), 'Exigir motivo de anulación');
    $t->anularPago($a, ['pago_id' => $pagoErroneo, 'motivo' => 'Cobro duplicado en terminal']);
    $det = $t->detalle($a, $id);
    verifica($det['pagado_centavos'] === 100000 && $det['saldo_centavos'] === 108800 && count($det['pagos']) === 2, 'Pago anulado deja de contar y se conserva en el historial');
    rechaza(fn() => $t->anularPago($a, ['pago_id' => $pagoErroneo, 'motivo' => 'Otra vez']), 'No anular dos veces');

    // ---------- Precio histórico y autorización excedida
    $t->guardar($a, 'refacciones', ['id' => $pieza, 'nombre' => 'Pieza A actualizada', 'precio' => '650.00', 'costo' => '400.00']);
    verifica((int)$t->detalle($a, $id)['detalles'][0]['precio_centavos'] === 30000, 'Precio histórico conservado');
    verifica($t->detalle($a, $id)['total_centavos'] === 208800, 'Cambiar catálogo no modifica total anterior');
    $t->agregarServicio($m, $id, ['servicio_id' => $revision, 'cantidad' => 1, 'operacion' => $op()]);
    // (100 + 1200 + 250 + 600 − 100) × 1.16 = 2378, mayor que los 2200 autorizados
    verifica($t->detalle($a, $id)['total_centavos'] === 237800, 'Servicio adicional: total $2,378.00');
    rechaza(fn() => $t->estado($m, $id, ['estado' => 'LISTO PARA ENTREGAR']), 'Bloquear terminar si el total supera lo autorizado', 'autoriz');
    $t->autorizar($r, $id, ['monto' => '2378.00', 'medio' => 'Teléfono', 'nota' => 'Aceptó revisión adicional']);
    $t->estado($m, $id, ['estado' => 'LISTO PARA ENTREGAR']);
    rechaza(fn() => $t->agregarServicio($m, $id, ['servicio_id' => $revision, 'cantidad' => 1, 'operacion' => $op()]), 'No agregar servicios a una orden terminada');
    $t->estado($r, $id, ['estado' => 'ENTREGADO']);
    verifica($t->detalle($a, $id)['saldo_centavos'] === 137800, 'Entrega con saldo pendiente');
    rechaza(fn() => $t->estado($a, $id, ['estado' => 'EN DIAGNÓSTICO']), 'No retroceder orden entregada');
    rechaza(fn() => $t->tecnica($a, $id, ['diagnostico' => 'Cambio', 'reparaciones' => 'Cambio', 'mano_obra' => '0.00']), 'Bloquear importes históricos');
    rechaza(fn() => $t->ajustes($a, $id, ['descuento' => '0', 'aplicar_iva' => '0']), 'Bloquear descuento e IVA tras la entrega');
    rechaza(fn() => $t->cancelar($a, $id, ['motivo' => 'X']), 'No cancelar una orden entregada');
    $t->pagar($r, $id, ['monto' => '1378.00', 'metodo' => 'Transferencia', 'operacion' => $op()]);
    verifica($t->detalle($a, $id)['estado_pago'] === 'PAGADO', 'Segundo pago liquida el saldo');
    verifica($t->detalle($a, $id)['total_centavos'] === 237800, 'Pagos no alteran total');

    // ---------- Cancelación
    $vid2 = $t->guardar($r, 'vehiculos', ['cliente_id' => $cid, 'marca' => 'Marca', 'modelo' => 'Otro', 'anio' => 2015, 'placas' => 'TEST-02']);
    $id2 = $t->crearOrden($r, ['vehiculo_id' => $vid2, 'mecanico_id' => $m['id'], 'falla' => 'Revisión']);
    $t->estado($m, $id2, ['estado' => 'EN DIAGNÓSTICO']);
    $t->tecnica($m, $id2, ['diagnostico' => 'Motor dañado', 'reparaciones' => '', 'mano_obra' => '300.00', 'presupuesto' => '25000.00']);
    rechaza(fn() => $t->cancelar($m, $id2, ['motivo' => 'X']), 'Mecánico no cancela órdenes');
    rechaza(fn() => $t->cancelar($r, $id2, ['motivo' => '']), 'Exigir motivo de cancelación');
    $t->cancelar($r, $id2, ['motivo' => 'El cliente no autorizó el presupuesto']);
    $o2 = $t->detalle($a, $id2);
    verifica($o2['estado'] === 'CANCELADA' && $o2['saldo_centavos'] === 30000, 'Cancelar conserva el cargo de revisión pendiente');
    rechaza(fn() => $t->estado($m, $id2, ['estado' => 'EN REPARACIÓN']), 'Una orden cancelada no avanza');
    rechaza(fn() => $t->asignar($r, $id2, ['mecanico_id' => $otro['id']]), 'Una orden cancelada conserva su mecánico');
    $t->pagar($r, $id2, ['monto' => '300.00', 'metodo' => 'Efectivo', 'operacion' => $op()]);
    verifica($t->detalle($a, $id2)['estado_pago'] === 'PAGADO', 'Cobrar la revisión de una orden cancelada');
    $id3 = $t->crearOrden($r, ['vehiculo_id' => $vid2, 'mecanico_id' => $m['id'], 'falla' => 'Regresa']);
    verifica($id3 > $id2, 'Tras cancelar se puede abrir una nueva orden del vehículo');
    $t->cancelar($a, $id3, ['motivo' => 'Prueba']);

    // ---------- Fotos (validación de archivo; la subida real se prueba por HTTP)
    rechaza(fn() => $t->guardarFoto($r, $id, ['error' => UPLOAD_ERR_NO_FILE], ''), 'Rechazar foto no recibida');
    rechaza(fn() => $t->foto($otro, 999999), 'Foto inexistente');

    // ---------- Cuentas
    rechaza(fn() => $t->cambiarClave($r, ['actual' => 'incorrecta-123', 'nueva' => 'NuevaClaveSegura1']), 'Cambio de contraseña exige la actual');
    rechaza(fn() => $t->cambiarClave($r, ['actual' => 'clave-inicial-1', 'nueva' => 'corta']), 'Contraseña nueva de al menos 12 caracteres');
    $t->cambiarClave($r, ['actual' => 'clave-inicial-1', 'nueva' => 'NuevaClaveSegura1']);
    verifica(password_verify('NuevaClaveSegura1', $t->uno('SELECT clave_hash FROM usuarios WHERE id=?', [$r['id']])['clave_hash']), 'Cada usuario cambia su propia contraseña');

    // ---------- Historial, permisos y reportes
    rechaza(fn() => $t->activar($a, 'ordenes', ['id' => $id, 'activo' => 0]), 'No eliminar orden con historial');
    $t->activar($a, 'clientes', ['id' => $cid, 'activo' => 0]);
    verifica(count($t->listadoOrdenes($a)) === 3, 'Inactivar cliente conserva historial');
    rechaza(fn() => $t->activar($a, 'usuarios', ['id' => $a['id'], 'activo' => 0]), 'Proteger al administrador actual');
    rechaza(fn() => $t->guardar($r, 'usuarios', ['nombre' => 'Intruso']), 'Recepción no administra usuarios');
    rechaza(fn() => $t->reportes($m, date('Y-m-d'), date('Y-m-d')), 'Reportes restringidos al administrador');
    $rep = $t->reportes($a, date('Y-m-d'), date('Y-m-d'));
    verifica($rep['ingresos_centavos'] === 267800, 'Ingresos excluyen el pago anulado ($2,678.00)');
    verifica(count($rep['pagos_anulados']) === 1 && count($rep['servicios']) === 1 && count($rep['canceladas']) === 2, 'Reporte de anulados, entregas y cancelaciones');
    verifica($rep['refacciones_venta_centavos'] - $rep['refacciones_costo_centavos'] === 20000, 'Utilidad en refacciones $200.00');
    verifica($rep['descuentos_centavos'] === 10000 && $rep['iva_centavos'] === 32800, 'Reporte de descuentos e IVA');
    verifica(count($rep['servicios_vendidos']) === 2, 'Reporte de servicios vendidos');
    verifica(centavos('0.29') === 29 && centavos('1850.01') === 185001, 'Decimales convertidos sin pérdida');
    rechaza(fn() => centavos('1.001'), 'Rechazar exceso de decimales');
    verifica(count($t->detalle($a, $id)['actividad']) >= 15, 'Trazabilidad conservada');

    // ---------- Respaldo y restauración
    $archivo = Mantenimiento::respaldar(conectar($nombre), $nombre, sys_get_temp_dir() . '/' . $nombre . '.sql.gz');
    $raiz->exec("CREATE DATABASE `$restaurada` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    Mantenimiento::restaurar(conectar($restaurada), $archivo);
    $tr = new Taller(conectar($restaurada));
    verifica($tr->detalle($a, $id)['total_centavos'] === 237800 && $tr->detalle($a, $id)['notas_recepcion'] === $t->detalle($a, $id)['notas_recepcion'], 'Respaldo restaurado con importes y textos idénticos');
    foreach (['usuarios', 'ordenes_servicio', 'pagos', 'auditoria', 'servicios_orden', 'migraciones'] as $tabla) {
        if ($t->uno("SELECT COUNT(*) n FROM $tabla")['n'] !== $tr->uno("SELECT COUNT(*) n FROM $tabla")['n']) throw new RuntimeException("FALLO: tabla $tabla distinta tras restaurar");
    }
    verifica(true, 'Todas las tablas restauradas con el mismo número de registros');
    @unlink($archivo);

    echo "\n$n comprobaciones superadas.\n";
} finally {
    $db = null;
    $t = null;
    $tr = null;
    foreach ([$nombre, $restaurada] as $base) {
        if (preg_match('/^mecanigest_test_[a-f0-9]{10}(_r)?$/D', $base)) $raiz->exec("DROP DATABASE IF EXISTS `$base`");
    }
}
