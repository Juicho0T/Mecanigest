<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli')exit;
$c=configuracion();$nombre='mecanigest_test_'.bin2hex(random_bytes(5));
$raiz=new PDO('mysql:host='.$c['DB_HOST'].';port='.$c['DB_PORT'],$c['DB_USER'],$c['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$raiz->exec("CREATE DATABASE `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$n=0;
function verifica(bool $ok,string $mensaje):void {global $n;if(!$ok)throw new RuntimeException('FALLO: '.$mensaje);echo 'OK '.++$n.' '.$mensaje."\n";}
function rechaza(callable $fn,string $mensaje):void {try{$fn();}catch(Regla $e){verifica(true,$mensaje);return;}throw new RuntimeException('FALLO: se permitió '.$mensaje);}
try {
    $db=conectar($nombre);$db->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));$t=new Taller($db);
    foreach(['administrador','recepcionista','mecanico','mecanico'] as $i=>$r)$t->ejecutar('INSERT INTO usuarios(nombre,correo,rol,clave_hash) VALUES(?,?,?,?)',['Prueba '.$i,$i.'@ejemplo.local',$r,password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
    [$a,$r,$m,$otro]=$t->filas('SELECT * FROM usuarios ORDER BY id');
    $cid=$t->guardar($r,'clientes',['nombre'=>'Cliente de prueba','telefono'=>'6180000000']);verifica($cid>0,'Registrar cliente');
    $vid=$t->guardar($r,'vehiculos',['cliente_id'=>$cid,'marca'=>'Marca','modelo'=>'Modelo','anio'=>2020,'placas'=>'TEST-01']);verifica($vid>0,'Registrar vehículo');
    $id=$t->crearOrden($r,['vehiculo_id'=>$vid,'mecanico_id'=>$m['id'],'falla'=>'Falla reportada']);verifica($id>0,'Crear orden y asignar mecánico');
    rechaza(fn()=>$t->crearOrden($r,['vehiculo_id'=>$vid,'mecanico_id'=>$m['id'],'falla'=>'Duplicada']),'Evitar dos órdenes activas para un vehículo');
    rechaza(fn()=>$t->detalle($otro,$id),'Impedir acceso a orden ajena');
    rechaza(fn()=>$t->estado($r,$id,['estado'=>'EN DIAGNÓSTICO']),'Recepción no altera estado técnico');
    $t->estado($m,$id,['estado'=>'EN DIAGNÓSTICO']);verifica($t->orden($m,$id)['estado']==='EN DIAGNÓSTICO','Cambiar a diagnóstico');
    rechaza(fn()=>$t->estado($m,$id,['estado'=>'EN REPARACIÓN']),'Exigir diagnóstico');
    $t->tecnica($m,$id,['diagnostico'=>'Diagnóstico manual','reparaciones'=>'Trabajo documentado','mano_obra'=>'800.00']);
    $t->estado($m,$id,['estado'=>'EN REPARACIÓN']);verifica($t->orden($m,$id)['reparaciones']==='Trabajo documentado','Registrar reparación');
    $pieza=$t->guardar($a,'refacciones',['nombre'=>'Pieza A','precio'=>'300.00']);
    $t->entrada($a,['refaccion_id'=>$pieza,'cantidad'=>3,'motivo'=>'Prueba','operacion'=>bin2hex(random_bytes(16))]);
    $op=bin2hex(random_bytes(16));$datos=['refaccion_id'=>$pieza,'cantidad'=>2,'operacion'=>$op];
    $t->usarRefaccion($m,$id,$datos);verifica((int)$t->uno('SELECT existencia FROM refacciones WHERE id=?',[$pieza])['existencia']===1,'Agregar refacción y descontar stock');
    rechaza(fn()=>$t->usarRefaccion($m,$id,$datos),'No duplicar uso de refacción');
    rechaza(fn()=>$t->usarRefaccion($m,$id,['refaccion_id'=>$pieza,'cantidad'=>2,'operacion'=>bin2hex(random_bytes(16))]),'Rechazar stock insuficiente');
    verifica((int)$t->uno('SELECT existencia FROM refacciones WHERE id=?',[$pieza])['existencia']===1,'Fallo no altera existencia');
    rechaza(fn()=>$t->usarRefaccion($m,$id,['refaccion_id'=>$pieza,'cantidad'=>-1,'operacion'=>bin2hex(random_bytes(16))]),'Rechazar cantidad negativa');
    $pieza2=$t->guardar($a,'refacciones',['nombre'=>'Pieza B','precio'=>'450.00']);
    $t->entrada($a,['refaccion_id'=>$pieza2,'cantidad'=>2,'motivo'=>'Prueba','operacion'=>bin2hex(random_bytes(16))]);
    $t->usarRefaccion($m,$id,['refaccion_id'=>$pieza2,'cantidad'=>1,'operacion'=>bin2hex(random_bytes(16))]);
    verifica($t->detalle($a,$id)['total_centavos']===185000,'Total exacto de $1,850.00');
    $pago=['monto'=>'1000.00','metodo'=>'Efectivo','operacion'=>bin2hex(random_bytes(16))];$t->pagar($r,$id,$pago);
    verifica($t->detalle($a,$id)['saldo_centavos']===85000,'Pago parcial y saldo de $850.00');
    rechaza(fn()=>$t->pagar($m,$id,['monto'=>'1.00','metodo'=>'Efectivo','operacion'=>bin2hex(random_bytes(16))]),'Mecánico no registra pagos');
    rechaza(fn()=>$t->pagar($r,$id,['monto'=>'851.00','metodo'=>'Efectivo','operacion'=>bin2hex(random_bytes(16))]),'Rechazar sobrepago');
    rechaza(fn()=>$t->pagar($r,$id,['monto'=>'0.00','metodo'=>'Efectivo','operacion'=>bin2hex(random_bytes(16))]),'Rechazar pago cero');
    $t->guardar($a,'refacciones',['id'=>$pieza,'nombre'=>'Pieza A actualizada','precio'=>'650.00']);
    verifica((int)$t->detalle($a,$id)['detalles'][0]['precio_centavos']===30000,'Precio histórico conservado');
    verifica($t->detalle($a,$id)['total_centavos']===185000,'Cambiar catálogo no modifica total anterior');
    $t->estado($m,$id,['estado'=>'LISTO PARA ENTREGAR']);$t->estado($r,$id,['estado'=>'ENTREGADO']);
    verifica($t->detalle($a,$id)['saldo_centavos']===85000,'Entrega con saldo pendiente');
    rechaza(fn()=>$t->estado($a,$id,['estado'=>'EN DIAGNÓSTICO']),'No retroceder orden entregada');
    rechaza(fn()=>$t->tecnica($a,$id,['diagnostico'=>'Cambio','reparaciones'=>'Cambio','mano_obra'=>'0.00']),'Bloquear importes históricos');
    $t->pagar($r,$id,['monto'=>'850.00','metodo'=>'Transferencia','operacion'=>bin2hex(random_bytes(16))]);
    verifica($t->detalle($a,$id)['estado_pago']==='PAGADO','Segundo pago liquida el saldo');
    verifica($t->detalle($a,$id)['total_centavos']===185000,'Pagos no alteran total');
    rechaza(fn()=>$t->activar($a,'ordenes',['id'=>$id,'activo'=>0]),'No eliminar orden con historial');
    $t->activar($a,'clientes',['id'=>$cid,'activo'=>0]);
    verifica(count($t->listadoOrdenes($a))===1,'Inactivar cliente conserva historial');
    rechaza(fn()=>$t->activar($a,'usuarios',['id'=>$a['id'],'activo'=>0]),'Proteger al administrador actual');
    rechaza(fn()=>$t->guardar($r,'usuarios',['nombre'=>'Intruso']),'Recepción no administra usuarios');
    rechaza(fn()=>$t->reportes($m,date('Y-m-d'),date('Y-m-d')),'Reportes restringidos al administrador');
    $reporte=$t->reportes($a,date('Y-m-d'),date('Y-m-d'));
    verifica($reporte['ingresos_centavos']===185000 && count($reporte['servicios'])===1,'Reportes calculados con pagos y entregas reales');
    verifica(centavos('0.29')===29 && centavos('1850.01')===185001,'Decimales convertidos sin pérdida');
    rechaza(fn()=>centavos('1.001'),'Rechazar exceso de decimales');
    verifica(count($t->detalle($a,$id)['actividad'])>=8,'Trazabilidad conservada');
    echo "\n$n comprobaciones superadas.\n";
} finally {
    $db=null;$t=null;
    if(preg_match('/^mecanigest_test_[a-f0-9]{10}$/D',$nombre))$raiz->exec("DROP DATABASE `$nombre`");
}
