<?php
declare(strict_types=1);
// Instala o actualiza la base de datos. Seguro de volver a ejecutar: no borra información.
// Uso: php scripts/instalar.php [--demo]
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/app/bootstrap.php';

$root = dirname(__DIR__);
if (!file_exists($root . '/.env')) copy($root . '/.env.example', $root . '/.env');
$c = configuracion();
$nombre = $c['DB_NAME'];
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $nombre)) exit("Nombre de base no válido.\n");

$servidor = new PDO('mysql:host=' . $c['DB_HOST'] . ';port=' . $c['DB_PORT'] . ';charset=utf8mb4', $c['DB_USER'], $c['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$servidor->exec("CREATE DATABASE IF NOT EXISTS `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$db = conectar();
$db->exec(file_get_contents($root . '/database/schema.sql'));
$t = new Taller($db);

// Actualizaciones de esquema. Si la base ya tiene información, primero se genera un respaldo.
$pendientes = Mantenimiento::pendientes($db);
if ($pendientes) {
    if ((int)$t->uno('SELECT COUNT(*) n FROM usuarios')['n'] > 0) {
        $archivo = Mantenimiento::respaldar(conectar(), $nombre);
        echo "Respaldo previo a la actualización: " . substr($archivo, strlen($root) + 1) . "\n";
    }
    Mantenimiento::migrar($db, fn($m) => print($m . "\n"));
}

$demo = in_array('--demo', $argv, true);

if ((int)$t->uno('SELECT COUNT(*) n FROM usuarios')['n'] === 0) {
    $cuentas = [['Administración', 'admin@mecanigest.local', 'administrador']];
    if ($demo) {
        $cuentas[] = ['Recepción', 'recepcion@mecanigest.local', 'recepcionista'];
        $cuentas[] = ['Daniel Torres', 'mecanico@mecanigest.local', 'mecanico'];
    }
    $accesos = "MecaniGest — accesos locales generados\nNo publiques ni compartas este archivo.\n\n";
    foreach ($cuentas as [$persona, $correo, $rol]) {
        $clave = bin2hex(random_bytes(10));
        $t->ejecutar('INSERT INTO usuarios(nombre,correo,rol,clave_hash) VALUES(?,?,?,?)', [$persona, $correo, $rol, password_hash($clave, PASSWORD_DEFAULT)]);
        $accesos .= "$persona\nCorreo: $correo\nContraseña: $clave\n\n";
    }
    file_put_contents($root . '/storage/accesos-locales.txt', $accesos);
    echo "Usuarios creados. Credenciales privadas en storage/accesos-locales.txt\n";
}

if ($demo && (int)$t->uno('SELECT COUNT(*) n FROM clientes')['n'] === 0) {
    $u = $t->uno("SELECT * FROM usuarios WHERE rol='administrador' AND activo=1 LIMIT 1");
    $m = $t->uno("SELECT id FROM usuarios WHERE rol='mecanico' AND activo=1 LIMIT 1");
    $op = fn() => bin2hex(random_bytes(16));

    $vehiculos = [];
    $datos = [
        ['Carlos Mendoza', 'Nissan', 'Versa', 2021, 'DGO-241-A', 48210],
        ['Ana Martínez', 'Volkswagen', 'Jetta', 2019, 'DGO-852-B', 87350],
        ['Roberto Salas', 'Chevrolet', 'Aveo', 2020, 'DGO-307-C', 63900],
        ['Lucía Hernández', 'Toyota', 'Corolla', 2022, 'DGO-614-D', 25480],
        ['Miguel Navarro', 'Ford', 'Ranger', 2018, 'DGO-923-E', 132700],
        ['Sofía Reyes', 'Kia', 'Rio', 2023, 'DGO-480-F', 15320],
        ['Jorge Medina', 'Mazda', '3', 2020, 'DGO-716-G', 71045],
    ];
    foreach ($datos as $i => [$cliente, $marca, $modelo, $anio, $placas, $km]) {
        $cid = $t->guardar($u, 'clientes', ['nombre' => $cliente, 'telefono' => '61800000' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'correo' => '', 'direccion' => '']);
        $vehiculos[] = [$t->guardar($u, 'vehiculos', ['cliente_id' => $cid, 'marca' => $marca, 'modelo' => $modelo, 'anio' => $anio, 'placas' => $placas]), $km];
    }

    $piezas = [];
    foreach ([['Filtro de aceite', '180.00', '95.00', 18], ['Aceite sintético 5W-30', '320.00', '210.00', 24], ['Balatas delanteras', '850.00', '520.00', 8], ['Bujía de encendido', '145.00', '80.00', 32], ['Filtro de aire', '260.00', '150.00', 3], ['Banda de accesorios', '540.00', '330.00', 2]] as [$pieza, $precio, $costo, $stock]) {
        $id = $t->guardar($u, 'refacciones', ['nombre' => $pieza, 'precio' => $precio, 'costo' => $costo, 'descripcion' => 'Refacción de demostración. Verificar compatibilidad con el vehículo.', 'proveedor' => 'Proveedor de ejemplo']);
        $piezas[] = $id;
        $t->entrada($u, ['refaccion_id' => $id, 'cantidad' => $stock, 'costo' => $costo, 'motivo' => 'Inventario inicial de demostración', 'operacion' => $op()]);
    }

    $servicios = [];
    foreach ([['Cambio de aceite y filtro', '350.00'], ['Afinación mayor', '1200.00'], ['Cambio de balatas (por eje)', '400.00'], ['Diagnóstico por escáner', '300.00'], ['Revisión general', '250.00']] as [$servicio, $precio]) {
        $servicios[] = $t->guardar($u, 'servicios', ['nombre' => $servicio, 'precio' => $precio, 'descripcion' => 'Servicio de demostración.']);
    }

    $fallas = ['Servicio de mantenimiento y cambio de aceite.', 'Ruido al frenar en la parte delantera.', 'El motor presenta vibración al encender.', 'Revisión por ruido en la banda de accesorios.', 'Revisión general antes de un viaje.', 'Cambio de aceite y revisión de filtros.', 'Mantenimiento preventivo de motor.'];
    $ids = [];
    foreach ($vehiculos as $i => [$vid, $km]) {
        $ids[] = $t->crearOrden($u, ['vehiculo_id' => $vid, 'mecanico_id' => $m['id'], 'falla' => $fallas[$i], 'kilometraje' => $km, 'nivel_combustible' => ['1/2', '1/4', '3/4', 'Lleno', '1/2', 'Reserva', '1/4'][$i], 'notas_recepcion' => $i === 1 ? 'Rayón en la puerta trasera derecha. Sin tapón de gasolina.' : '']);
    }
    // Órdenes 1 y 2: diagnóstico, autorización y reparación en curso.
    foreach ([0, 1] as $i) {
        $id = $ids[$i];
        $t->estado($u, $id, ['estado' => 'EN DIAGNÓSTICO']);
        $t->tecnica($u, $id, ['diagnostico' => $i === 0 ? 'Mantenimiento periódico; se requiere sustituir aceite y filtro.' : 'Desgaste de balatas delanteras identificado en revisión.', 'reparaciones' => '', 'mano_obra' => '0.00', 'presupuesto' => $i === 0 ? '530.00' : '1250.00', 'presupuesto_detalle' => $i === 0 ? 'Cambio de aceite y filtro con refacción incluida.' : 'Cambio de balatas delanteras con refacción incluida.']);
        $t->agregarServicio($u, $id, ['servicio_id' => $servicios[$i === 0 ? 0 : 2], 'cantidad' => 1, 'operacion' => $op()]);
        $t->autorizar($u, $id, ['monto' => $i === 0 ? '530.00' : '1250.00', 'medio' => 'Presencial', 'nota' => '']);
        $t->estado($u, $id, ['estado' => 'EN REPARACIÓN']);
        $t->usarRefaccion($u, $id, ['refaccion_id' => $piezas[$i === 0 ? 0 : 2], 'cantidad' => 1, 'operacion' => $op()]);
    }
    $t->estado($u, $ids[2], ['estado' => 'EN DIAGNÓSTICO']);
    // Órdenes 6 y 7: terminadas; la 7 se paga y entrega.
    foreach ([5, 6] as $i) {
        $id = $ids[$i];
        $t->estado($u, $id, ['estado' => 'EN DIAGNÓSTICO']);
        $t->tecnica($u, $id, ['diagnostico' => 'Servicio preventivo solicitado; revisión manual completada.', 'reparaciones' => 'Cambio de aceite, filtro y revisión general realizados.', 'mano_obra' => '250.00', 'presupuesto' => '1880.00', 'presupuesto_detalle' => 'Cambio de aceite sintético y revisión general.']);
        $t->agregarServicio($u, $id, ['servicio_id' => $servicios[0], 'cantidad' => 1, 'operacion' => $op()]);
        $t->autorizar($u, $id, ['monto' => '1880.00', 'medio' => 'Teléfono', 'nota' => '']);
        $t->estado($u, $id, ['estado' => 'EN REPARACIÓN']);
        $t->usarRefaccion($u, $id, ['refaccion_id' => $piezas[1], 'cantidad' => 4, 'operacion' => $op()]);
        $t->estado($u, $id, ['estado' => 'LISTO PARA ENTREGAR']);
        if ($i === 6) {
            $t->pagar($u, $id, ['monto' => '1880.00', 'metodo' => 'Efectivo', 'operacion' => $op()]);
            $t->estado($u, $id, ['estado' => 'ENTREGADO']);
        }
    }
    $t->pagar($u, $ids[0], ['monto' => '500.00', 'metodo' => 'Efectivo', 'operacion' => $op()]);
    echo "Datos ficticios de demostración creados mediante las reglas del sistema.\n";
}
echo "Base de datos lista. Publicar únicamente la carpeta public.\n";
