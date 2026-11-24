<?php
declare(strict_types=1);
final class Sesion {
    static function iniciar():void {
        $ruta=dirname(__DIR__).'/storage/sesiones';
        if(!is_dir($ruta)&&!mkdir($ruta,0700,true))throw new RuntimeException('No se pudo preparar el almacenamiento de sesiones.');
        session_save_path($ruta);
        ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
        session_name('mecanigest_sesion');
        session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','path'=>'/']);
        if(!session_start())throw new RuntimeException('No se pudo iniciar la sesión.');
        if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    static function csrf():void {
        if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))throw new Regla('La sesión del formulario venció. Actualiza la página.',419);
    }
    static function usuario(Taller $t):array {
        if(empty($_SESSION['usuario'])||time()-($_SESSION['actividad']??0)>1800){unset($_SESSION['usuario']);throw new Regla('Inicia sesión para continuar.',401);}
        $rows=$t->filas('SELECT id,nombre,correo,rol FROM usuarios WHERE id=? AND activo=1',[$_SESSION['usuario']]);
        if(!$rows){unset($_SESSION['usuario']);throw new Regla('Tu sesión ya no está activa.',401);}
        $_SESSION['actividad']=time();return $rows[0];
    }
    static function entrar(Taller $t,array $d):array {
        $correo=mb_strtolower(texto($d,'correo',190));$clave=texto($d,'clave',72);
        $identificador=hash('sha256',($_SERVER['REMOTE_ADDR']??'local').'|'.$correo);
        $t->ejecutar('INSERT IGNORE INTO intentos_acceso(identificador,intentos,ultimo) VALUES(?,0,NOW())',[$identificador]);
        $fallo=$t->transaccion(function()use($t,$identificador,$correo,$clave){
            $i=$t->uno('SELECT *,TIMESTAMPDIFF(SECOND,ultimo,NOW()) segundos FROM intentos_acceso WHERE identificador=? FOR UPDATE',[$identificador]);
            if((int)$i['intentos']>=5&&(int)$i['segundos']<900)throw new Regla('Demasiados intentos. Espera 15 minutos e inténtalo de nuevo.',429);
            $rows=$t->filas('SELECT * FROM usuarios WHERE correo=? AND activo=1',[$correo]);
            $hash=$rows[0]['clave_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            if(!password_verify($clave,$hash)||!$rows){$n=(int)$i['segundos']>=900?1:(int)$i['intentos']+1;$t->ejecutar('UPDATE intentos_acceso SET intentos=?,ultimo=NOW() WHERE identificador=?',[$n,$identificador]);return true;}
            $t->ejecutar('UPDATE intentos_acceso SET intentos=0,ultimo=NOW() WHERE identificador=?',[$identificador]);
            session_regenerate_id(true);$_SESSION['usuario']=$rows[0]['id'];$_SESSION['actividad']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));return false;
        });
        if($fallo)throw new Regla('Correo o contraseña incorrectos.',401);
        return ['usuario'=>self::usuario($t),'csrf'=>$_SESSION['csrf']];
    }
}
