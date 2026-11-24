<?php
declare(strict_types=1);
final class Taller {
    public PDO $db;
    const ESTADOS=['RECIBIDO','EN DIAGNÓSTICO','EN REPARACIÓN','LISTO PARA ENTREGAR','ENTREGADO'];
    function __construct(PDO $db) { $this->db=$db; }
    function filas(string $sql, array $valores=[]): array { $q=$this->db->prepare($sql);$q->execute($valores);return $q->fetchAll(); }
    function ejecutar(string $sql, array $valores=[]): void { $q=$this->db->prepare($sql);$q->execute($valores); }
    function uno(string $sql,array $v=[]): array { $r=$this->filas($sql,$v);if(!$r)throw new Regla('No se encontró el registro.',404);return $r[0]; }
    function auditoria(array $u,string $entidad,int $id,string $accion): void { $this->ejecutar('INSERT INTO auditoria(usuario_id,entidad,registro_id,accion) VALUES(?,?,?,?)',[$u['id'],$entidad,$id,$accion]); }
    function transaccion(callable $accion) {
        $this->db->beginTransaction();
        try {$r=$accion();$this->db->commit();return $r;} catch(Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            if($e instanceof PDOException && ($e->errorInfo[1]??0)===1062)throw new Regla('El registro ya existe o esta operación ya fue registrada. Actualiza la información antes de volver a intentarlo.',409);
            throw $e;
        }
    }
    function clave(array $d): string { $v=texto($d,'operacion',64);if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$v))throw new Regla('La operación no es válida. Vuelve a abrir el formulario.');return $v; }
    function orden(array $u,int $id,bool $bloquear=false): array {
        $o=$this->uno('SELECT * FROM ordenes_servicio WHERE id=?'.($bloquear?' FOR UPDATE':''),[$id]);
        if($u['rol']==='mecanico' && (int)$o['mecanico_id']!==(int)$u['id'])throw new Regla('Solo puedes consultar tus órdenes asignadas.',403);
        return $o;
    }
    function totales(array $o): array {
        $p=(int)$this->uno('SELECT COALESCE(SUM(cantidad*precio_centavos),0) valor FROM detalles_orden WHERE orden_id=?',[$o['id']])['valor'];
        $pagado=(int)$this->uno('SELECT COALESCE(SUM(monto_centavos),0) valor FROM pagos WHERE orden_id=?',[$o['id']])['valor'];
        $total=(int)$o['mano_obra_centavos']+$p;
        if($total>999999999999)throw new Regla('El importe total excede el límite admitido.');
        return ['refacciones_centavos'=>$p,'total_centavos'=>$total,'pagado_centavos'=>$pagado,'saldo_centavos'=>$total-$pagado,'estado_pago'=>$total===0?'SIN CARGO':($pagado===$total?'PAGADO':($pagado>0?'PARCIAL':'PENDIENTE'))];
    }
    function listadoOrdenes(array $u): array {
        $sql='SELECT o.*, c.nombre cliente, v.marca, v.modelo, v.placas, u.nombre mecanico FROM ordenes_servicio o JOIN clientes c ON c.id=o.cliente_id JOIN vehiculos v ON v.id=o.vehiculo_id JOIN usuarios u ON u.id=o.mecanico_id';
        $v=[];if($u['rol']==='mecanico'){$sql.=' WHERE o.mecanico_id=?';$v=[$u['id']];}
        $rows=$this->filas($sql.' ORDER BY o.id DESC',$v);
        foreach($rows as &$o)$o=array_merge($o,$this->totales($o));return $rows;
    }
    function datos(array $u): array {
        $m=$u['rol']==='mecanico';
        $clientes=$this->filas('SELECT c.* FROM clientes c'.($m?' WHERE c.id IN (SELECT cliente_id FROM ordenes_servicio WHERE mecanico_id=?)':'').' ORDER BY c.nombre',$m?[$u['id']]:[]);
        $vehiculos=$this->filas('SELECT v.*, c.nombre propietario FROM vehiculos v JOIN clientes c ON c.id=v.cliente_id'.($m?' WHERE v.id IN (SELECT vehiculo_id FROM ordenes_servicio WHERE mecanico_id=?)':'').' ORDER BY v.id DESC',$m?[$u['id']]:[]);
        $usuarios=$this->filas($u['rol']==='administrador'?'SELECT id,nombre,correo,rol,activo FROM usuarios ORDER BY nombre':"SELECT id,nombre,rol,activo FROM usuarios WHERE rol='mecanico' ORDER BY nombre");
        $pagos=$m?[]:$this->filas('SELECT p.*,u.nombre registrado_por,c.nombre cliente FROM pagos p JOIN usuarios u ON u.id=p.usuario_id JOIN ordenes_servicio o ON o.id=p.orden_id JOIN clientes c ON c.id=o.cliente_id ORDER BY p.id DESC');
        return ['usuario'=>$u,'clientes'=>$clientes,'vehiculos'=>$vehiculos,'ordenes'=>$this->listadoOrdenes($u),'refacciones'=>$this->filas('SELECT * FROM refacciones ORDER BY nombre'),'usuarios'=>$usuarios,'pagos'=>$pagos,'demo'=>(configuracion()['APP_DEMO']??'0')==='1','hoy'=>date('Y-m-d')];
    }
    function detalle(array $u,int $id): array {
        $o=$this->orden($u,$id);
        $o=array_merge($o,$this->totales($o));
        $o['detalles']=$this->filas('SELECT * FROM detalles_orden WHERE orden_id=? ORDER BY id',[$id]);
        $o['pagos']=$u['rol']==='mecanico'?[]:$this->filas('SELECT p.*,u.nombre registrado_por FROM pagos p JOIN usuarios u ON u.id=p.usuario_id WHERE orden_id=? ORDER BY id',[$id]);
        $o['actividad']=$this->filas("SELECT a.*,u.nombre usuario FROM auditoria a JOIN usuarios u ON u.id=a.usuario_id WHERE entidad='orden' AND registro_id=? ORDER BY a.id DESC",[$id]);
        return $o;
    }
    function guardar(array $u,string $entidad,array $d): int {
        $mapa=['clientes'=>'clientes','vehiculos'=>'vehiculos','refacciones'=>'refacciones','usuarios'=>'usuarios'];
        if(!isset($mapa[$entidad]))throw new Regla('Operación no disponible.',404);
        rol($u,in_array($entidad,['usuarios','refacciones'])?['administrador']:['administrador','recepcionista']);
        return $this->transaccion(function()use($u,$entidad,$d){
            $id=empty($d['id'])?0:entero($d['id']);
            $anterior=$id?$this->uno("SELECT * FROM $entidad WHERE id=? FOR UPDATE",[$id]):[];
            if($entidad==='clientes') {
                $campos=['nombre'=>texto($d,'nombre',120),'telefono'=>texto($d,'telefono',25),'correo'=>texto($d,'correo',190,false),'direccion'=>texto($d,'direccion',250,false)];
                if(!preg_match('/^[+0-9 ()-]{7,25}$/D',$campos['telefono']))throw new Regla('Escribe un teléfono válido, con al menos 7 caracteres.');
                if($campos['correo']!==''&&!filter_var($campos['correo'],FILTER_VALIDATE_EMAIL))throw new Regla('El correo electrónico no es válido.');
            } elseif($entidad==='vehiculos') {
                $cid=entero($d['cliente_id']??0);$c=$this->uno('SELECT activo FROM clientes WHERE id=? FOR UPDATE',[$cid]);
                if(!$c['activo'])throw new Regla('Selecciona un cliente activo.');
                if($id && $cid!==(int)$anterior['cliente_id']) {
                    $n=$this->uno("SELECT COUNT(*) n FROM ordenes_servicio WHERE vehiculo_id=? AND estado<>'ENTREGADO'",[$id]);
                    if($n['n'])throw new Regla('Entrega las órdenes activas antes de cambiar al propietario.');
                }
                $campos=['cliente_id'=>$cid,'marca'=>texto($d,'marca',60),'modelo'=>texto($d,'modelo',80),'anio'=>entero($d['anio']??0,1900,(int)date('Y')+1),'placas'=>mb_strtoupper(texto($d,'placas',20))];
                if(!preg_match('/^[A-Z0-9 -]{3,20}$/D',$campos['placas']))throw new Regla('Revisa las placas del vehículo.');
            } elseif($entidad==='refacciones') {
                $campos=['nombre'=>texto($d,'nombre',120),'descripcion'=>texto($d,'descripcion',500,false),'proveedor'=>texto($d,'proveedor',120,false),'precio_centavos'=>centavos($d['precio']??'')];
            } else {
                $campos=['nombre'=>texto($d,'nombre',120),'correo'=>mb_strtolower(texto($d,'correo',190)),'rol'=>texto($d,'rol',20)];
                if(!filter_var($campos['correo'],FILTER_VALIDATE_EMAIL))throw new Regla('El correo electrónico no es válido.');
                if(!in_array($campos['rol'],['administrador','recepcionista','mecanico'],true))throw new Regla('Selecciona un perfil válido.');
                if($id && $anterior['rol']!==$campos['rol'])$this->puedeCambiarUsuario($u,$anterior);
                $clave=texto($d,'clave',72,!$id);
                if($clave!=='') {if(strlen($clave)<12||strlen($clave)>72)throw new Regla('La contraseña debe tener entre 12 y 72 bytes.');$campos['clave_hash']=password_hash($clave,PASSWORD_DEFAULT);}
            }
            if($id) {
                $set=implode(',',array_map(fn($k)=>"$k=?",array_keys($campos)));
                $this->ejecutar("UPDATE $entidad SET $set WHERE id=?",array_merge(array_values($campos),[$id]));
            } else {
                $cols=implode(',',array_keys($campos));$qs=implode(',',array_fill(0,count($campos),'?'));
                $this->ejecutar("INSERT INTO $entidad($cols) VALUES($qs)",array_values($campos));$id=(int)$this->db->lastInsertId();
            }
            $this->auditoria($u,$entidad,$id,$anterior?'Información actualizada':'Registro creado');return $id;
        });
    }
    function puedeCambiarUsuario(array $actor,array $anterior):void {
        if((int)$actor['id']===(int)$anterior['id'])throw new Regla('No puedes cambiar tu propio perfil ni desactivar tu sesión.');
        $admins=$this->filas("SELECT id FROM usuarios WHERE rol='administrador' AND activo=1 FOR UPDATE");
        if($anterior['rol']==='administrador'&&$anterior['activo']&&count($admins)<=1)throw new Regla('Debe permanecer al menos un administrador activo.');
        $n=$this->uno("SELECT COUNT(*) n FROM ordenes_servicio WHERE mecanico_id=? AND estado<>'ENTREGADO'",[$anterior['id']]);
        if($n['n'])throw new Regla('Reasigna las órdenes activas de este usuario antes de modificar su perfil o desactivarlo.');
    }
    function activar(array $u,string $entidad,array $d): void {
        if(!in_array($entidad,['clientes','vehiculos','refacciones','usuarios'],true))throw new Regla('No se permite eliminar ni desactivar órdenes históricas.',405);
        rol($u,['administrador']);
        $this->transaccion(function()use($u,$entidad,$d){
            $id=entero($d['id']??0);$a=entero($d['activo']??-1,0,1);
            $anterior=$this->uno("SELECT * FROM $entidad WHERE id=? FOR UPDATE",[$id]);
            if($entidad==='usuarios'&&!$a)$this->puedeCambiarUsuario($u,$anterior);
            $this->ejecutar("UPDATE $entidad SET activo=? WHERE id=?",[$a,$id]);
            $this->auditoria($u,$entidad,$id,$a?'Registro reactivado':'Registro inactivado; historial conservado');
        });
    }
    function crearOrden(array $u,array $d): int {
        rol($u,['administrador','recepcionista']);
        return $this->transaccion(function()use($u,$d){
            $v=$this->uno('SELECT * FROM vehiculos WHERE id=? FOR UPDATE',[entero($d['vehiculo_id']??0)]);
            $c=$this->uno('SELECT * FROM clientes WHERE id=? FOR UPDATE',[$v['cliente_id']]);
            if(!$v['activo']||!$c['activo'])throw new Regla('El cliente y el vehículo deben estar activos.');
            $m=$this->uno("SELECT id FROM usuarios WHERE id=? AND activo=1 AND rol='mecanico' FOR UPDATE",[entero($d['mecanico_id']??0)]);
            $prev=$this->filas("SELECT id FROM ordenes_servicio WHERE vehiculo_id=? AND estado<>'ENTREGADO'",[$v['id']]);
            if($prev)throw new Regla('Este vehículo ya tiene una orden activa. Consulta esa orden antes de abrir otra.');
            $this->ejecutar("INSERT INTO ordenes_servicio(cliente_id,vehiculo_id,mecanico_id,falla,diagnostico,reparaciones,creado_por) VALUES(?,?,?,?,'','',?)",[$c['id'],$v['id'],$m['id'],texto($d,'falla',4000),$u['id']]);
            $id=(int)$this->db->lastInsertId();$this->auditoria($u,'orden',$id,'Vehículo recibido y orden abierta');return $id;
        });
    }
    function tecnica(array $u,int $id,array $d):void {
        rol($u,['administrador','mecanico']);
        $this->transaccion(function()use($u,$id,$d){
            $o=$this->orden($u,$id,true);
            if(!in_array($o['estado'],['EN DIAGNÓSTICO','EN REPARACIÓN'],true))throw new Regla('Solo puedes editar el trabajo durante diagnóstico o reparación.');
            $mano=centavos($d['mano_obra']??'');$t=$this->totales($o);
            if($mano+$t['refacciones_centavos']<$t['pagado_centavos'])throw new Regla('El total no puede quedar por debajo de los pagos registrados.');
            if($mano+$t['refacciones_centavos']>999999999999)throw new Regla('El total excede el límite admitido.');
            $this->ejecutar('UPDATE ordenes_servicio SET diagnostico=?,reparaciones=?,mano_obra_centavos=? WHERE id=?',[texto($d,'diagnostico',6000,false),texto($d,'reparaciones',6000,false),$mano,$id]);
            $this->auditoria($u,'orden',$id,'Diagnóstico, reparación y mano de obra actualizados');
        });
    }
    function asignar(array $u,int $id,array $d): void {
        rol($u,['administrador','recepcionista']);
        $this->transaccion(function()use($u,$id,$d){
            $o=$this->orden($u,$id,true);if($o['estado']==='ENTREGADO')throw new Regla('La orden entregada conserva su mecánico histórico.');
            $m=$this->uno("SELECT id,nombre FROM usuarios WHERE id=? AND rol='mecanico' AND activo=1 FOR UPDATE",[entero($d['mecanico_id']??0)]);
            $this->ejecutar('UPDATE ordenes_servicio SET mecanico_id=? WHERE id=?',[$m['id'],$id]);
            $this->auditoria($u,'orden',$id,'Mecánico asignado: '.$m['nombre']);
        });
    }
    function estado(array $u,int $id,array $d):void {
        $this->transaccion(function()use($u,$id,$d){
            $o=$this->orden($u,$id,true);$nuevo=texto($d,'estado',30);
            rol($u,$nuevo==='ENTREGADO'?['administrador','recepcionista']:['administrador','mecanico']);
            $idx=array_search($o['estado'],self::ESTADOS,true);
            if((self::ESTADOS[$idx+1]??null)!==$nuevo)throw new Regla('El estado cambió o la transición no está permitida. Actualiza la orden.',409);
            if($nuevo==='EN REPARACIÓN'&&trim($o['diagnostico'])==='')throw new Regla('Registra el diagnóstico antes de iniciar la reparación.');
            if($nuevo==='LISTO PARA ENTREGAR'&&trim($o['reparaciones'])==='')throw new Regla('Documenta las reparaciones antes de terminar el trabajo.');
            $this->ejecutar('UPDATE ordenes_servicio SET estado=?, fecha_entrega=? WHERE id=?',[$nuevo,$nuevo==='ENTREGADO'?date('Y-m-d H:i:s'):null,$id]);
            $this->auditoria($u,'orden',$id,$o['estado'].' → '.$nuevo);
        });
    }
    function usarRefaccion(array $u,int $id,array $d):void {
        rol($u,['administrador','mecanico']);
        $this->transaccion(function()use($u,$id,$d){
            $o=$this->orden($u,$id,true);
            if($o['estado']!=='EN REPARACIÓN')throw new Regla('Las refacciones se registran durante la reparación.');
            $r=$this->uno('SELECT * FROM refacciones WHERE id=? FOR UPDATE',[entero($d['refaccion_id']??0)]);
            $n=entero($d['cantidad']??0,1,10000);$op=$this->clave($d);
            if(!$r['activo']||$n>(int)$r['existencia'])throw new Regla('No hay existencias suficientes de esta refacción activa.');
            $t=$this->totales($o);if($t['total_centavos']+$n*(int)$r['precio_centavos']>999999999999)throw new Regla('El total excede el límite admitido.');
            $this->ejecutar('INSERT INTO detalles_orden(orden_id,refaccion_id,nombre_historico,cantidad,precio_centavos,operacion) VALUES(?,?,?,?,?,?)',[$id,$r['id'],$r['nombre'],$n,$r['precio_centavos'],$op]);
            $detalle=(int)$this->db->lastInsertId();
            $this->ejecutar('UPDATE refacciones SET existencia=existencia-? WHERE id=?',[$n,$r['id']]);
            $this->ejecutar("INSERT INTO movimientos_inventario(refaccion_id,tipo,cantidad,usuario_id,orden_id,detalle_id,motivo,operacion) VALUES(?,'SALIDA POR ORDEN',?,?,?,?,?,?)",[$r['id'],$n,$u['id'],$id,$detalle,'Utilización en reparación',$op]);
            $this->auditoria($u,'orden',$id,'Refacción utilizada: '.$n.' × '.$r['nombre']);
        });
    }
    function entrada(array $u,array $d):void {
        rol($u,['administrador']);
        $this->transaccion(function()use($u,$d){
            $r=$this->uno('SELECT * FROM refacciones WHERE id=? FOR UPDATE',[entero($d['refaccion_id']??0)]);
            $n=entero($d['cantidad']??0,1,10000);if(!$r['activo']||$n+(int)$r['existencia']>1000000)throw new Regla('Activa la refacción y verifica que la existencia no exceda un millón de unidades.');
            $this->ejecutar("INSERT INTO movimientos_inventario(refaccion_id,tipo,cantidad,usuario_id,motivo,operacion) VALUES(?,'ENTRADA',?,?,?,?)",[$r['id'],$n,$u['id'],texto($d,'motivo',250),$this->clave($d)]);
            $this->ejecutar('UPDATE refacciones SET existencia=existencia+? WHERE id=?',[$n,$r['id']]);
            $this->auditoria($u,'refacciones',(int)$r['id'],'Entrada de '.$n.' unidades');
        });
    }
    function pagar(array $u,int $id,array $d):void {
        rol($u,['administrador','recepcionista']);
        $this->transaccion(function()use($u,$id,$d){
            $o=$this->orden($u,$id,true);$t=$this->totales($o);$m=centavos($d['monto']??'');
            if($m<=0||$m>$t['saldo_centavos'])throw new Regla('El pago debe ser mayor a cero y no superar el saldo pendiente.');
            $metodo=texto($d,'metodo',20);if(!in_array($metodo,['Efectivo','Transferencia','Tarjeta'],true))throw new Regla('Selecciona un método de pago válido.');
            $this->ejecutar('INSERT INTO pagos(orden_id,monto_centavos,metodo,usuario_id,operacion) VALUES(?,?,?,?,?)',[$id,$m,$metodo,$u['id'],$this->clave($d)]);
            $this->auditoria($u,'orden',$id,'Pago registrado: $'.number_format($m/100,2,'.',',').' MXN');
        });
    }
    function movimientos(array $u,int $id):array {
        rol($u,['administrador']);
        return $this->filas('SELECT m.*,u.nombre usuario FROM movimientos_inventario m JOIN usuarios u ON u.id=m.usuario_id WHERE refaccion_id=? ORDER BY m.id DESC',[$id]);
    }
    function reportes(array $u,string $desde,string $hasta):array {
        rol($u,['administrador']);
        foreach([$desde,$hasta] as $v)if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)||!($f=DateTime::createFromFormat('!Y-m-d',$v))||$f->format('Y-m-d')!==$v)throw new Regla('Selecciona fechas válidas.');
        if($desde>$hasta)throw new Regla('La fecha inicial no puede ser posterior a la final.');
        $limite=(new DateTime($hasta))->modify('+1 day')->format('Y-m-d');
        $pagos=$this->filas('SELECT p.*,c.nombre cliente FROM pagos p JOIN ordenes_servicio o ON o.id=p.orden_id JOIN clientes c ON c.id=o.cliente_id WHERE p.creado_en>=? AND p.creado_en<? ORDER BY p.creado_en DESC',[$desde,$limite]);
        $servicios=$this->filas('SELECT o.id,o.vehiculo_id,o.fecha_entrega,c.nombre cliente,v.placas FROM ordenes_servicio o JOIN clientes c ON c.id=o.cliente_id JOIN vehiculos v ON v.id=o.vehiculo_id WHERE o.fecha_entrega>=? AND o.fecha_entrega<? ORDER BY o.fecha_entrega DESC',[$desde,$limite]);
        $piezas=$this->filas('SELECT d.nombre_historico nombre,SUM(d.cantidad) cantidad,SUM(d.cantidad*d.precio_centavos) importe_centavos FROM detalles_orden d WHERE d.creado_en>=? AND d.creado_en<? GROUP BY d.refaccion_id,d.nombre_historico',[$desde,$limite]);
        $ordenes=$this->listadoOrdenes($u);
        return ['desde'=>$desde,'hasta'=>$hasta,'pagos'=>$pagos,'servicios'=>$servicios,'piezas'=>$piezas,'ingresos_centavos'=>array_sum(array_column($pagos,'monto_centavos')),'vehiculos_atendidos'=>count(array_unique(array_column($servicios,'vehiculo_id'))),'pendientes'=>array_values(array_filter($ordenes,fn($o)=>$o['estado']!=='ENTREGADO')),'por_cobrar'=>array_values(array_filter($ordenes,fn($o)=>$o['saldo_centavos']>0))];
    }
}
