<?php
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');
function configuracion(): array {
    static $config;
    if ($config !== null) return $config;
    $archivo = dirname(__DIR__) . '/.env';
    if (!is_file($archivo)) throw new RuntimeException('Configuración pendiente.');
    $config = parse_ini_file($archivo, false, INI_SCANNER_RAW);
    return $config;
}
function conectar(?string $base = null): PDO {
    $c = configuracion();
    $nombre = $base ?? $c['DB_NAME'];
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $nombre)) throw new RuntimeException('Base inválida');
    $pdo = new PDO('mysql:host='.$c['DB_HOST'].';port='.$c['DB_PORT'].';dbname='.$nombre.';charset=utf8mb4', $c['DB_USER'], $c['DB_PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '-06:00'");
    return $pdo;
}
class Regla extends RuntimeException {
    public int $estado;
    function __construct(string $mensaje, int $estado=422) { parent::__construct($mensaje); $this->estado=$estado; }
}
function texto(array $d, string $campo, int $max, bool $obligatorio=true): string {
    if (isset($d[$campo]) && !is_string($d[$campo])) throw new Regla('Revisa el campo '.$campo.'.');
    $v=trim($d[$campo]??'');
    if (($obligatorio && $v==='') || mb_strlen($v)>$max) throw new Regla('Revisa el campo '.$campo.': '.($obligatorio?'es obligatorio y ':'').'admite hasta '.$max.' caracteres.');
    return $v;
}
function entero($v, int $min=1, int $max=1000000): int {
    if (filter_var($v,FILTER_VALIDATE_INT)===false || (int)$v<$min || (int)$v>$max) throw new Regla('La cantidad o identificador no es válido.');
    return (int)$v;
}
function centavos($v): int {
    if (!is_string($v) && !is_int($v)) throw new Regla('Escribe el importe con hasta dos decimales.');
    if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D',(string)$v,$m)) throw new Regla('El importe debe ser positivo, sin comas y con hasta dos decimales.');
    return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}
/** Importe opcional: vacío devuelve null. */
function centavosOpcional($v): ?int {
    if ($v === null || (is_string($v) && trim($v) === '')) return null;
    return centavos(is_string($v) ? trim($v) : $v);
}
function validarClaveNueva(string $clave): void {
    if (strlen($clave) < 12 || strlen($clave) > 72) throw new Regla('La contraseña debe tener entre 12 y 72 bytes.');
}
function rol(array $u, array $roles): void {
    if (!in_array($u['rol'],$roles,true)) throw new Regla('Tu perfil no tiene permiso para realizar esta operación.',403);
}
require_once __DIR__.'/Taller.php';
require_once __DIR__.'/Sesion.php';
require_once __DIR__.'/Mantenimiento.php';
