<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

try {
    Sesion::iniciar();
    $ruta = $_GET['r'] ?? 'sesion';
    $metodo = $_SERVER['REQUEST_METHOD'];

    // Imagen de una orden: única respuesta que no es JSON.
    if ($metodo === 'GET' && $ruta === 'foto') {
        $t = new Taller(conectar());
        $u = Sesion::usuario($t);
        [$archivo, $tipo] = $t->foto($u, entero($_GET['id'] ?? 0));
        session_write_close();
        header('Content-Type: ' . $tipo);
        header('Content-Length: ' . filesize($archivo));
        header('Cache-Control: private, max-age=86400');
        header("Content-Security-Policy: default-src 'none'");
        readfile($archivo);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    if ($metodo === 'GET' && $ruta === 'sesion') {
        $usuario = null;
        if (!empty($_SESSION['usuario'])) {
            try {
                $usuario = Sesion::usuario(new Taller(conectar()));
            } catch (Regla $e) {
                if ($e->estado !== 401) throw $e;
            }
        }
        $respuesta = ['usuario' => $usuario, 'csrf' => $_SESSION['csrf']];
    } else {
        $t = new Taller(conectar());
        if ($metodo === 'POST') {
            Sesion::csrf();
            if ($ruta === 'orden/foto') {
                // Envío multipart con la imagen; el tamaño lo limita la propia regla de fotos.
                $u = Sesion::usuario($t);
                $descripcion = texto($_POST, 'descripcion', 250, false);
                $respuesta = ['ok' => true, 'id' => $t->guardarFoto($u, entero($_POST['id'] ?? 0), $_FILES['foto'] ?? [], $descripcion)];
            } else {
                if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 40000) throw new Regla('La solicitud es demasiado grande.', 413);
                $d = json_decode(file_get_contents('php://input', false, null, 0, 40001), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($d)) throw new Regla('No se recibieron datos válidos.');
                if ($ruta === 'login') {
                    $respuesta = Sesion::entrar($t, $d);
                } elseif ($ruta === 'logout') {
                    $_SESSION = [];
                    session_destroy();
                    $respuesta = ['ok' => true];
                } else {
                    $u = Sesion::usuario($t);
                    $respuesta = ['ok' => true];
                    $id = fn() => entero($d['id'] ?? 0);
                    switch ($ruta) {
                        case 'guardar': $respuesta['id'] = $t->guardar($u, texto($d, 'entidad', 30), $d); break;
                        case 'activar': $t->activar($u, texto($d, 'entidad', 30), $d); break;
                        case 'cuenta/clave':
                            $t->cambiarClave($u, $d);
                            session_regenerate_id(true);
                            break;
                        case 'orden/crear': $respuesta['id'] = $t->crearOrden($u, $d); break;
                        case 'orden/recepcion': $t->recepcion($u, $id(), $d); break;
                        case 'orden/tecnica': $t->tecnica($u, $id(), $d); break;
                        case 'orden/autorizar': $t->autorizar($u, $id(), $d); break;
                        case 'orden/asignar': $t->asignar($u, $id(), $d); break;
                        case 'orden/estado': $t->estado($u, $id(), $d); break;
                        case 'orden/cancelar': $t->cancelar($u, $id(), $d); break;
                        case 'orden/servicio': $t->agregarServicio($u, $id(), $d); break;
                        case 'orden/servicio/quitar': $t->quitarServicio($u, $id(), $d); break;
                        case 'orden/refaccion': $t->usarRefaccion($u, $id(), $d); break;
                        case 'orden/ajustes': $t->ajustes($u, $id(), $d); break;
                        case 'orden/pago': $t->pagar($u, $id(), $d); break;
                        case 'pago/anular': $t->anularPago($u, $d); break;
                        case 'inventario/entrada': $t->entrada($u, $d); break;
                        default: throw new Regla('Esta operación no está disponible.', 405);
                    }
                }
            }
        } elseif ($metodo === 'GET') {
            $u = Sesion::usuario($t);
            switch ($ruta) {
                case 'datos': $respuesta = $t->transaccion(fn() => $t->datos($u)); break;
                case 'orden': $respuesta = $t->transaccion(fn() => $t->detalle($u, entero($_GET['id'] ?? 0))); break;
                case 'movimientos': $respuesta = $t->movimientos($u, entero($_GET['id'] ?? 0)); break;
                case 'reportes': $respuesta = $t->transaccion(fn() => $t->reportes($u, (string)($_GET['desde'] ?? ''), (string)($_GET['hasta'] ?? ''))); break;
                default: throw new Regla('No se encontró la página solicitada.', 404);
            }
        } else {
            throw new Regla('Método no permitido.', 405);
        }
    }
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Regla $e) {
    http_response_code($e->estado);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (JsonException $e) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'La solicitud no tiene un formato válido.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log(date('c') . ' ' . get_class($e) . ' código ' . $e->getCode() . "\n", 3, dirname(__DIR__) . '/storage/errores.log');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'No fue posible completar la operación. Comprueba que el servicio esté disponible e inténtalo de nuevo.'], JSON_UNESCAPED_UNICODE);
}
