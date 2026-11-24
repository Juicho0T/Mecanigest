const fs=require('node:fs');
const path=require('node:path');
const base=process.env.MECANIGEST_URL||'http://127.0.0.1:8097';
const contenido=fs.readFileSync(path.join(__dirname,'../storage/accesos-locales.txt'),'utf8');
const cuentas=[...contenido.matchAll(/Correo: (.+)\r?\nContraseña: (.+)/g)].map(m=>({correo:m[1].trim(),clave:m[2].trim()}));
let contador=0;
function verificar(ok,nombre){if(!ok)throw Error(nombre);console.log(`OK ${++contador} ${nombre}`);}
function cliente(){let cookie='',csrf='';return {cookie:()=>cookie,csrf:()=>csrf,async pedir(r,d=null,token=true){const res=await fetch(base+'/api.php?r='+r,{method:d?'POST':'GET',headers:{...(cookie?{Cookie:cookie}:{}),...(d?{'Content-Type':'application/json','X-CSRF-Token':token?csrf:'invalido'}:{})},body:d?JSON.stringify(d):undefined});const set=res.headers.getSetCookie();if(set.length)cookie=set.map(c=>c.split(';')[0]).join('; ');const json=await res.json();if(json.csrf)csrf=json.csrf;return {status:res.status,json,cookie};}};}
(async()=>{
 const anon=cliente();verificar((await anon.pedir('datos')).status===401,'API requiere sesión');
 for(const cuenta of cuentas){const c=cliente();const inicio=await c.pedir('sesion');verificar(inicio.status===200&&inicio.json.csrf.length===64,'Token CSRF emitido');
 verificar((await c.pedir('login',cuenta,false)).status===419,'Inicio de sesión rechaza CSRF inválido');
 const login=await c.pedir('login',cuenta);verificar(login.status===200,'Acceso de '+cuenta.correo);
 verificar(login.cookie!==inicio.cookie,'Identificador de sesión regenerado');
 const datos=await c.pedir('datos');verificar(datos.status===200,'Sesión persiste entre solicitudes');
 verificar(!JSON.stringify(datos.json).includes('clave_hash'),'Los hashes nunca salen por la API');
 verificar((await c.pedir('guardar',{entidad:'clientes',nombre:'No debe guardarse',telefono:'6180000000'},false)).status===419,'Escritura rechaza CSRF inválido');
 verificar((await c.pedir('eliminar',{id:1})).status===405,'No existe endpoint de borrado');
 if(login.json.usuario.rol!=='administrador')verificar((await c.pedir('guardar',{entidad:'usuarios',nombre:'No autorizado'})).status===403,'Permisos verificados en servidor');
 if(login.json.usuario.rol==='mecanico'){
   verificar(datos.json.ordenes.every(o=>o.mecanico_id==login.json.usuario.id),'Mecánico solo recibe órdenes asignadas');
   verificar(datos.json.pagos.length===0,'Mecánico no recibe historial general de cobros');
   verificar((await c.pedir('orden/tecnica',{id:1,diagnostico:'',reparaciones:'',mano_obra:'1.001'})).status===422,'Validación de dinero en API');
 }
 verificar((await c.pedir('logout',{})).status===200,'Cerrar sesión');
 verificar((await c.pedir('datos')).status===401,'Sesión cerrada no permite acceso');
 }
 // Fotos: subida multipart, tipo de archivo y acceso solo con sesión.
 {const rec=cliente();await rec.pedir('sesion');const cuenta=cuentas.find(x=>x.correo.startsWith('recepcion'));
  if(cuenta){const login=await rec.pedir('login',cuenta);const datos=await rec.pedir('datos');const orden=datos.json.ordenes[0].id;
   const subir=async(contenido,tipo,nombre)=>{const f=new FormData();f.append('id',String(orden));f.append('descripcion','Prueba HTTP');f.append('foto',new Blob([contenido],{type:tipo}),nombre);
     const r=await fetch(base+'/api.php?r=orden/foto',{method:'POST',headers:{Cookie:rec.cookie(),'X-CSRF-Token':rec.csrf()},body:f});return {status:r.status,json:await r.json()};};
   const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==','base64');
   verificar((await subir('<?php echo 1;','image/png','falsa.png')).status===422,'Rechaza archivos que no son imagen');
   const ok=await subir(png,'image/png','foto.png');verificar(ok.status===200&&ok.json.id>0,'Subir foto de la orden');
   const img=await fetch(base+'/api.php?r=foto&id='+ok.json.id,{headers:{Cookie:rec.cookie()}});
   verificar(img.status===200&&img.headers.get('content-type')==='image/png','Foto disponible con sesión');
   verificar((await fetch(base+'/api.php?r=foto&id='+ok.json.id)).status===401,'Foto protegida sin sesión');
   await rec.pedir('logout',{});}
 }
 verificar((await fetch(base+'/imprimir.html')).status===200,'Página de impresión disponible');
 const c=cliente();await c.pedir('sesion');const intento={correo:'limitacion-'+Date.now()+'@ejemplo.local',clave:'incorrecta'};
 for(let i=0;i<5;i++)verificar((await c.pedir('login',intento)).status===401,'Credenciales inválidas rechazadas');
 verificar((await c.pedir('login',intento)).status===429,'Sexto intento bloqueado durante 15 minutos');
 for(const ruta of ['/.env','/storage/accesos-locales.txt','/app/bootstrap.php','/database/schema.sql','/storage/respaldos/mecanigest.sql.gz','/storage/fotos/foto.jpg','/scripts/respaldar.php'])verificar((await fetch(base+ruta)).status===404,'Carpeta privada no publicada: '+ruta);
 console.log(`\n${contador} comprobaciones HTTP superadas.`);
})().catch(e=>{console.error('FALLO:',e.message);process.exitCode=1;});
