<?php
declare(strict_types=1);
// Restaura un respaldo en una base NUEVA. Nunca sobrescribe la base en uso.
// Uso: php scripts/restaurar.php storage/respaldos/mecanigest-AAAA-MM-DD-HHMMSS.sql.gz mecanigest_restaurada
// Después, revisa la información y, si es correcta, cambia DB_NAME en .env por la base restaurada.
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/app/bootstrap.php';

[$archivo, $base] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!is_file($archivo) || !preg_match('/^[a-zA-Z0-9_]+$/D', $base)) {
    exit("Uso: php scripts/restaurar.php <archivo.sql.gz> <nombre_de_base_nueva>\n");
}
$c = configuracion();
if ($base === $c['DB_NAME']) exit("Elige un nombre distinto a la base en uso ({$c['DB_NAME']}).\n");

$servidor = new PDO('mysql:host=' . $c['DB_HOST'] . ';port=' . $c['DB_PORT'] . ';charset=utf8mb4', $c['DB_USER'], $c['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$existe = $servidor->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=?');
$existe->execute([$base]);
if ((int)$existe->fetchColumn() > 0) exit("La base $base ya tiene tablas. Usa un nombre nuevo.\n");

$servidor->exec("CREATE DATABASE IF NOT EXISTS `$base` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $n = Mantenimiento::restaurar(conectar($base), $archivo);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: la restauración no se completó. ' . $e->getMessage() . "\n");
    exit(1);
}
echo "Restauración completa: $n sentencias ejecutadas en la base $base.\n";
foreach (['usuarios', 'clientes', 'vehiculos', 'ordenes_servicio', 'pagos'] as $tabla) {
    echo str_pad($tabla, 18) . conectar($base)->query("SELECT COUNT(*) FROM `$tabla`")->fetchColumn() . " registros\n";
}
echo "Para usarla, cambia DB_NAME=$base en el archivo .env.\n";
