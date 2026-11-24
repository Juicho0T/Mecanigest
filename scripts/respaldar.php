<?php
declare(strict_types=1);
// Genera un respaldo comprimido de la base en storage/respaldos y conserva los más recientes.
// Uso: php scripts/respaldar.php
// Opcional en .env: RESPALDOS_CONSERVAR=30 y RESPALDO_COPIA_DIR=D:\Respaldos (por ejemplo una carpeta de OneDrive o Google Drive).
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/app/bootstrap.php';

try {
    $c = configuracion();
    $archivo = Mantenimiento::respaldar(conectar(), $c['DB_NAME']);
    echo 'Respaldo creado: ' . $archivo . ' (' . round(filesize($archivo) / 1024, 1) . " KB)\n";

    $copia = trim($c['RESPALDO_COPIA_DIR'] ?? '');
    if ($copia !== '') {
        if (!is_dir($copia) && !mkdir($copia, 0700, true)) throw new RuntimeException('No existe la carpeta de copia: ' . $copia);
        $destino = rtrim($copia, '\\/') . DIRECTORY_SEPARATOR . basename($archivo);
        if (!copy($archivo, $destino)) throw new RuntimeException('No se pudo copiar el respaldo a ' . $copia);
        echo "Copia guardada en: $destino\n";
    }

    $conservar = (int)($c['RESPALDOS_CONSERVAR'] ?? 30);
    foreach (Mantenimiento::depurar($conservar > 0 ? $conservar : 30) as $viejo) echo 'Respaldo antiguo eliminado: ' . basename($viejo) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: no se pudo crear el respaldo. ' . $e->getMessage() . "\n");
    exit(1);
}
