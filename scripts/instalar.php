<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require_once dirname(__DIR__).'/app/bootstrap.php';
$root=dirname(__DIR__);
if(!file_exists($root.'/.env'))copy($root.'/.env.example',$root.'/.env');
$c=configuracion();$nombre=$c['DB_NAME'];
if(!preg_match('/^[a-zA-Z0-9_]+$/D',$nombre))exit("Nombre de base no válido.\n");
$db=new PDO('mysql:host='.$c['DB_HOST'].';port='.$c['DB_PORT'].';charset=utf8mb4',$c['DB_USER'],$c['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE DATABASE IF NOT EXISTS `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db=conectar();$db->exec(file_get_contents($root.'/database/schema.sql'));$t=new Taller($db);
$demo=in_array('--demo',$argv,true);
if((int)$t->uno('SELECT COUNT(*) n FROM usuarios')['n']===0) {
    $cuentas=[['Administración','admin@mecanigest.local','administrador']];
    if($demo)$cuentas=array_merge($cuentas,[['Recepción','recepcion@mecanigest.local','recepcionista'],['Daniel Torres','mecanico@mecanigest.local','mecanico']]);
    $accesos="MecaniGest — accesos locales generados\nNo publiques ni compartas este archivo.\n\n";
    foreach($cuentas as [$nombre,$correo,$rol]) {
        $clave=bin2hex(random_bytes(10));
        $t->ejecutar('INSERT INTO usuarios(nombre,correo,rol,clave_hash) VALUES(?,?,?,?)',[$nombre,$correo,$rol,password_hash($clave,PASSWORD_DEFAULT)]);
        $accesos.="$nombre\nCorreo: $correo\nContraseña: $clave\n\n";
    }
    file_put_contents($root.'/storage/accesos-locales.txt',$accesos);
    echo "Usuarios creados. Credenciales privadas en storage/accesos-locales.txt\n";
}
if($demo && (int)$t->uno('SELECT COUNT(*) n FROM clientes')['n']===0) {
    $u=$t->uno("SELECT * FROM usuarios WHERE rol='administrador' AND activo=1 LIMIT 1");
    $m=$t->uno("SELECT id FROM usuarios WHERE rol='mecanico' AND activo=1 LIMIT 1");
    $clientes=[];$vehiculos=[];
    $datos=[['Carlos Mendoza','Nissan','Versa',2021,'DGO-241-A'],['Ana Martínez','Volkswagen','Jetta',2019,'DGO-852-B'],['Roberto Salas','Chevrolet','Aveo',2020,'DGO-307-C'],['Lucía Hernández','Toyota','Corolla',2022,'DGO-614-D'],['Miguel Navarro','Ford','Ranger',2018,'DGO-923-E'],['Sofía Reyes','Kia','Rio',2023,'DGO-480-F'],['Jorge Medina','Mazda','3',2020,'DGO-716-G']];
    foreach($datos as $i=>[$nombre,$marca,$modelo,$anio,$placas]) {
        $cid=$t->guardar($u,'clientes',['nombre'=>$nombre,'telefono'=>'61800000'.str_pad((string)$i,2,'0',STR_PAD_LEFT),'correo'=>'','direccion'=>'']);$clientes[]=$cid;
        $vehiculos[]=$t->guardar($u,'vehiculos',['cliente_id'=>$cid,'marca'=>$marca,'modelo'=>$modelo,'anio'=>$anio,'placas'=>$placas]);
    }
    $piezas=[];
    foreach([['Filtro de aceite','180.00',18],['Aceite sintético 5W-30','320.00',24],['Balatas delanteras','850.00',8],['Bujía de encendido','145.00',32],['Filtro de aire','260.00',3],['Banda de accesorios','540.00',2]] as [$nombre,$precio,$stock]) {
        $id=$t->guardar($u,'refacciones',['nombre'=>$nombre,'precio'=>$precio,'descripcion'=>'Refacción de demostración. Verificar compatibilidad con el vehículo.','proveedor'=>'Proveedor de ejemplo']);$piezas[]=$id;
        $t->entrada($u,['refaccion_id'=>$id,'cantidad'=>$stock,'motivo'=>'Inventario inicial de demostración','operacion'=>bin2hex(random_bytes(16))]);
    }
    $fallas=['Servicio de mantenimiento y cambio de aceite.','Ruido al frenar en la parte delantera.','El motor presenta vibración al encender.','Revisión por ruido en la banda de accesorios.','Revisión general antes de un viaje.','Cambio de aceite y revisión de filtros.','Mantenimiento preventivo de motor.'];
    foreach($vehiculos as $i=>$vid) {
        $id=$t->crearOrden($u,['vehiculo_id'=>$vid,'mecanico_id'=>$m['id'],'falla'=>$fallas[$i]]);
        if($i>=2)continue;
        $t->estado($u,$id,['estado'=>'EN DIAGNÓSTICO']);
        $t->tecnica($u,$id,['diagnostico'=>$i===0?'Mantenimiento periódico; se requiere sustituir aceite y filtro.':'Desgaste de balatas delanteras identificado en revisión.','reparaciones'=>'','mano_obra'=>$i===0?'550.00':'800.00']);
        $t->estado($u,$id,['estado'=>'EN REPARACIÓN']);
        $t->usarRefaccion($u,$id,['refaccion_id'=>$piezas[$i===0?0:2],'cantidad'=>1,'operacion'=>bin2hex(random_bytes(16))]);
    }
    // Additional stages are created through the same service rules as normal use.
    $ordenes=$t->listadoOrdenes($u);
    $ids=array_reverse(array_column($ordenes,'id'));
    $t->estado($u,(int)$ids[2],['estado'=>'EN DIAGNÓSTICO']);
    foreach([5,6] as $i){
        $id=(int)$ids[$i];$t->estado($u,$id,['estado'=>'EN DIAGNÓSTICO']);
        $t->tecnica($u,$id,['diagnostico'=>'Servicio preventivo solicitado; revisión manual completada.','reparaciones'=>'Cambio de aceite, filtro y revisión general realizados.','mano_obra'=>'600.00']);
        $t->estado($u,$id,['estado'=>'EN REPARACIÓN']);
        $t->usarRefaccion($u,$id,['refaccion_id'=>$piezas[1],'cantidad'=>4,'operacion'=>bin2hex(random_bytes(16))]);
        $t->estado($u,$id,['estado'=>'LISTO PARA ENTREGAR']);
        if($i===6){$t->pagar($u,$id,['monto'=>'1880.00','metodo'=>'Efectivo','operacion'=>bin2hex(random_bytes(16))]);$t->estado($u,$id,['estado'=>'ENTREGADO']);}
    }
    $t->pagar($u,(int)$ids[0],['monto'=>'500.00','metodo'=>'Efectivo','operacion'=>bin2hex(random_bytes(16))]);
    echo "Datos ficticios de demostración creados mediante las reglas del sistema.\n";
}
echo "Base de datos lista. Publicar únicamente la carpeta public.\n";
