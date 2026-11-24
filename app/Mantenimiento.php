<?php
declare(strict_types=1);

/**
 * Migraciones de esquema y respaldos de la base de datos.
 * Solo se usa desde la línea de comandos (scripts/) y para consultar la fecha del último respaldo.
 */
final class Mantenimiento
{
    public static function raiz(): string
    {
        return dirname(__DIR__);
    }

    public static function carpetaRespaldos(): string
    {
        return self::raiz() . '/storage/respaldos';
    }

    // ---------------------------------------------------------------- Migraciones

    /** Separa un archivo SQL en sentencias. Los archivos del proyecto no usan ';' dentro de textos. */
    public static function sentencias(string $sql): array
    {
        $sin_comentarios = preg_replace('/^\s*--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', preg_split('/;\s*(\n|$)/', $sin_comentarios)), fn($s) => $s !== ''));
    }

    /** @return string[] nombres de migraciones pendientes */
    public static function pendientes(PDO $db): array
    {
        $db->exec('CREATE TABLE IF NOT EXISTS migraciones (
            nombre VARCHAR(100) PRIMARY KEY,
            aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $aplicadas = $db->query('SELECT nombre FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
        $archivos = glob(self::raiz() . '/database/migraciones/*.sql') ?: [];
        sort($archivos, SORT_STRING);
        return array_values(array_filter(array_map('basename', $archivos), fn($n) => !in_array($n, $aplicadas, true)));
    }

    /** Aplica en orden las migraciones pendientes. Devuelve las aplicadas. */
    public static function migrar(PDO $db, ?callable $avisar = null): array
    {
        $hechas = [];
        foreach (self::pendientes($db) as $nombre) {
            $sql = file_get_contents(self::raiz() . '/database/migraciones/' . $nombre);
            foreach (self::sentencias($sql) as $sentencia) {
                $db->exec($sentencia);
            }
            $db->prepare('INSERT INTO migraciones(nombre) VALUES(?)')->execute([$nombre]);
            $hechas[] = $nombre;
            if ($avisar) $avisar("Migración aplicada: $nombre");
        }
        return $hechas;
    }

    // ---------------------------------------------------------------- Respaldos

    /** Escapa un valor para un INSERT en una sola línea (sin saltos de línea literales). */
    private static function literal($valor): string
    {
        if ($valor === null) return 'NULL';
        $reemplazos = ['\\' => '\\\\', "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "'" => "\\'", '"' => '\\"', "\x1a" => '\\Z'];
        return "'" . strtr((string)$valor, $reemplazos) . "'";
    }

    /**
     * Genera un respaldo SQL comprimido de toda la base configurada.
     * El archivo puede importarse con phpMyAdmin, con el cliente mysql o con scripts/restaurar.php.
     */
    public static function respaldar(PDO $db, string $nombreBase, ?string $destino = null): string
    {
        $carpeta = self::carpetaRespaldos();
        if (!is_dir($carpeta) && !mkdir($carpeta, 0700, true)) {
            throw new RuntimeException('No se pudo crear la carpeta de respaldos.');
        }
        if ($destino === null) {
            // Nombre único aunque se generen dos respaldos en el mismo segundo.
            $base = $carpeta . '/mecanigest-' . date('Y-m-d-His');
            $destino = $base . '.sql.gz';
            for ($i = 2; file_exists($destino); $i++) $destino = $base . '-' . $i . '.sql.gz';
        }
        $temporal = $destino . '.parcial';
        $gz = gzopen($temporal, 'wb6');
        if (!$gz) throw new RuntimeException('No se pudo escribir el respaldo.');

        try {
            // Lectura consistente de todas las tablas, sin bloquear el uso normal del sistema.
            $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            gzwrite($gz, "-- Respaldo de MecaniGest\n-- Base: $nombreBase\n-- Fecha: " . date('Y-m-d H:i:s') . "\n");
            gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET time_zone='-06:00';\n");
            $tablas = $db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
            foreach ($tablas as [$tabla]) {
                $crear = $db->query("SHOW CREATE TABLE `$tabla`")->fetch(PDO::FETCH_NUM)[1];
                gzwrite($gz, "\n-- Tabla $tabla\nDROP TABLE IF EXISTS `$tabla`;\n" . preg_replace('/\s*\n\s*/', ' ', $crear) . ";\n");
                $consulta = $db->query("SELECT * FROM `$tabla`", PDO::FETCH_NUM);
                $lote = [];
                foreach ($consulta as $fila) {
                    $lote[] = '(' . implode(',', array_map([self::class, 'literal'], $fila)) . ')';
                    if (count($lote) === 200) {
                        gzwrite($gz, "INSERT INTO `$tabla` VALUES " . implode(',', $lote) . ";\n");
                        $lote = [];
                    }
                }
                if ($lote) gzwrite($gz, "INSERT INTO `$tabla` VALUES " . implode(',', $lote) . ";\n");
            }
            gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n-- Fin del respaldo\n");
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            gzclose($gz);
            @unlink($temporal);
            throw $e;
        }
        gzclose($gz);
        if (!rename($temporal, $destino)) throw new RuntimeException('No se pudo completar el archivo de respaldo.');
        return $destino;
    }

    /** Conserva solo los respaldos más recientes. Devuelve los archivos eliminados. */
    public static function depurar(int $conservar): array
    {
        $archivos = self::respaldos();
        $eliminados = array_slice($archivos, max(1, $conservar));
        foreach ($eliminados as $archivo) @unlink($archivo);
        return $eliminados;
    }

    /** Respaldos existentes, del más reciente al más antiguo. */
    public static function respaldos(): array
    {
        $archivos = glob(self::carpetaRespaldos() . '/mecanigest-*.sql.gz') ?: [];
        usort($archivos, fn($a, $b) => [filemtime($b), $b] <=> [filemtime($a), $a]);
        return $archivos;
    }

    public static function ultimoRespaldo(): ?string
    {
        $archivos = self::respaldos();
        if (!$archivos) return null;
        return date('Y-m-d H:i:s', filemtime($archivos[0]));
    }

    /** Restaura un respaldo dentro de una base vacía. */
    public static function restaurar(PDO $db, string $archivo): int
    {
        $gz = gzopen($archivo, 'rb');
        if (!$gz) throw new RuntimeException('No se pudo abrir el respaldo.');
        $n = 0;
        $sentencia = '';
        while (($linea = gzgets($gz)) !== false) {
            if ($sentencia === '' && (str_starts_with($linea, '--') || trim($linea) === '')) continue;
            $sentencia .= $linea;
            // Cada sentencia del respaldo ocupa una sola línea terminada en ';'
            if (str_ends_with(rtrim($linea), ';')) {
                $db->exec($sentencia);
                $sentencia = '';
                $n++;
            }
        }
        gzclose($gz);
        if (trim($sentencia) !== '') throw new RuntimeException('El respaldo está incompleto.');
        return $n;
    }
}
