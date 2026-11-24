const fs=require('node:fs');
const path=require('node:path');
const base=process.env.MECANIGEST_URL||'http://127.0.0.1:8097';
const contenido=fs.readFileSync(path.join(__dirname,'../storage/accesos-locales.txt'),'utf8');
const cuentas=[...contenido.matchAll(/Correo: (.+)\r?\nContraseña: (.+)/g)].map(m=>({correo:m[1].trim(),clave:m[2].trim()}));
let contador=0;
function verificar(ok,nombre){if(!ok)throw Error(nombre);console.log(`OK ${++contador} ${nombre}`);}
function cliente(){let cookie='',csrf='';return {async pedir(r,d=null,token=true){const res=await fetch(base+'/api.php?r='+r,{method:d?'POST':'GET',headers:{...(cookie?{Cookie:cookie}:{}),...(d?{'Content-Type':'application/json','X-CSRF-Token':token?csrf:'invalido'}:{})},body:d?JSON.stringify(d):undefined});const set=res.headers.getSetCookie();if(set.length)cookie=set.map(c=>c.split(';')[0]).join('; ');const json=await res.json();if(json.csrf)csrf=json.csrf;return {status:res.status,json,cookie};}};}
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
 const c=cliente();await c.pedir('sesion');const intento={correo:'limitacion-'+Date.now()+'@ejemplo.local',clave:'incorrecta'};
 for(let i=0;i<5;i++)verificar((await c.pedir('login',intento)).status===401,'Credenciales inválidas rechazadas');
 verificar((await c.pedir('login',intento)).status===429,'Sexto intento bloqueado durante 15 minutos');
 for(const ruta of ['/.env','/storage/accesos-locales.txt','/app/bootstrap.php','/database/schema.sql'])verificar((await fetch(base+ruta)).status===404,'Carpeta privada no publicada: '+ruta);
 console.log(`\n${contador} comprobaciones HTTP superadas.`);
})().catch(e=>{console.error('FALLO:',e.message);process.exitCode=1;});
