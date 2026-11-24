'use strict';
// MecaniGest · interfaz del panel del taller.
// Sin dependencias: cada vista se genera como HTML con los datos de la API y todo texto se escapa con esc().

// ====================================================================== Constantes y utilidades
const $ = (s, r = document) => r.querySelector(s);
const raiz = $('#aplicacion'), modal = $('#modal');
const estados = ['RECIBIDO', 'EN DIAGNÓSTICO', 'EN REPARACIÓN', 'LISTO PARA ENTREGAR', 'ENTREGADO'];
const nombresEstado = ['Recibido', 'En diagnóstico', 'En reparación', 'Listo para entregar', 'Entregado'];
const CERRADOS = ['ENTREGADO', 'CANCELADA'];
const COMBUSTIBLE = ['Reserva', '1/4', '1/2', '3/4', 'Lleno'];
const MEDIOS = ['Presencial', 'Teléfono', 'WhatsApp', 'Correo'];
const roles = { administrador: 'Administrador', recepcionista: 'Recepcionista', mecanico: 'Mecánico' };

let csrf = '', datos = null, vista = 'inicio', busqueda = '', filtro = '', pagina = 1, ordenActual = null, version = 0;

const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const dinero = v => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v || 0) / 100);
const decimal = v => (Number(v || 0) / 100).toFixed(2);
const aFecha = v => new Date(v.replace(' ', 'T') + (v.length === 10 ? 'T12:00:00' : ''));
const fecha = v => v ? new Intl.DateTimeFormat('es-MX', { day: '2-digit', month: 'short', year: 'numeric' }).format(aFecha(v)) : 'Sin registrar';
const fechaHora = v => v ? fecha(v) + ' ' + v.slice(11, 16) : 'Sin registrar';
const km = v => v === null || v === undefined || v === '' ? 'Sin registrar' : new Intl.NumberFormat('es-MX').format(v) + ' km';
const folio = id => 'OS-' + String(id).padStart(4, '0');
const admin = () => datos?.usuario.rol === 'administrador';
const mecanico = () => datos?.usuario.rol === 'mecanico';
const recepcionOAdmin = () => ['administrador', 'recepcionista'].includes(datos?.usuario.rol);
const activa = o => !CERRADOS.includes(o.estado);

/** Clave única por formulario para evitar operaciones duplicadas.
 *  crypto.randomUUID solo existe en contextos seguros (localhost o HTTPS);
 *  getRandomValues también funciona al entrar por la IP de la red local. */
function claveOperacion() {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const b = crypto.getRandomValues(new Uint8Array(16));
  return Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
}

// Trazos de los iconos (formato del atributo d de <path>).
const iconos={inicio:'M3 10 12 3l9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z',clientes:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8m8-7a4 4 0 0 1 0 8',vehiculos:'M5 17H3V9l3-5h12l3 5v8h-2M5 17v3H3v-3m16 0v3h2v-3M5 17h14M3 9h18M6 13h2m8 0h2',ordenes:'M9 4H5v17h14V4h-4M9 2h6v4H9V2m-1 9h8m-8 4h8',inventario:'m3 7 9-4 9 4v11l-9 4-9-4V7Zm0 0 9 4 9-4m-9 4v11M7 5l9 4',pagos:'M3 5h18v14H3V5m0 5h18M6 15h3',reportes:'M4 3v18h17M8 16v-5m5 5V7m5 9V4',usuarios:'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8m-8 9v-2a7 7 0 0 1 14 0v2',buscar:'M21 21l-5-5M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16',mas:'M12 5v14M5 12h14',flecha:'M5 12h14m-5-5 5 5-5 5',volver:'M19 12H5m5-5-5 5 5 5',salir:'M9 4H3v16h6m5-12 4 4-4 4m-7-4h11',cerrar:'m6 6 12 12M6 18 18 6',editar:'m16 3 5 5-12 12-6 1 1-6L16 3ZM14 5l5 5',check:'m5 12 4 4L20 5',reloj:'M12 7v5l3 2m-3 7a9 9 0 1 0 0-18 9 9 0 0 0 0 18',calendario:'M4 5h16v16H4V5m4-3v6m8-6v6M4 10h16',escudo:'m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Zm-4 9 3 3 5-6',menu:'M3 6h18M3 12h18M3 18h18',historial:'M3 11a9 9 0 1 1 2 7M3 4v7h7m2-4v6l4 2',alerta:'m12 3 10 18H2L12 3Zm0 6v5m0 3v1',llave:'m3 21 11-11m0-6a6 6 0 0 0 7 7l-4-2-2-4 3-3a6 6 0 0 0-4 2Z'};
const iconosExtra = {
  servicios: 'M14 7a5 5 0 0 0 6 6l-9 9-5-5 9-9a5 5 0 0 0-1-1ZM5 3l3 3-2 2-3-3',
  imprimir: 'M6 9V3h12v6M6 18H4v-7h16v7h-2M7 14h10v7H7v-7',
  camara: 'M4 7h4l2-3h4l2 3h4v13H4V7Zm8 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
  cancelar: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM6 6l12 12',
  firma: 'm3 17 4 4 12-12-4-4L3 17Zm12-12 4 4M3 21h18',
  cuenta: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8m-8 9v-2a7 7 0 0 1 14 0v2m3-15 1 1m-2 2 3-3',
};
const ico = n => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${iconos[n] || iconosExtra[n] || iconos.ordenes}"/></svg>`;

function estadoBadge(e) {
  if (e === 'CANCELADA') return '<span class="badge cancelada">Cancelada</span>';
  const i = estados.indexOf(e);
  return `<span class="badge s${i}">${nombresEstado[i] || esc(e)}</span>`;
}
const pagoBadge = o => `<span class="badge ${o.estado_pago === 'PAGADO' ? 'pago' : o.estado_pago === 'PARCIAL' ? 'parcial' : 'deuda'}">${{ PAGADO: 'Pagado', PARCIAL: 'Pago parcial', PENDIENTE: 'Pendiente', 'SIN CARGO': 'Sin cargo' }[o.estado_pago]}</span>`;
const vacio = (t = 'Todavía no hay registros para mostrar.') => `<div class="vacio">${ico('ordenes')}${esc(t)}</div>`;
const boton = (texto, accion, extra = '', clase = '') => `<button class="boton ${clase}" data-accion="${accion}" ${extra}>${esc(texto)}</button>`;

// ====================================================================== Comunicación con la API
async function api(r, d = null) {
  const res = await fetch(`api.php?r=${r}`, {
    method: d ? 'POST' : 'GET',
    credentials: 'same-origin',
    headers: d ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {},
    body: d ? JSON.stringify(d) : undefined,
  });
  return respuesta(res, r);
}

async function apiArchivo(r, formData) {
  const res = await fetch(`api.php?r=${r}`, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf }, body: formData });
  return respuesta(res, r);
}

async function respuesta(res, r) {
  let valor;
  try { valor = await res.json(); } catch { throw new Error('No se pudo leer la respuesta. Verifica que el servicio esté disponible.'); }
  if (!res.ok) {
    if (res.status === 401 && r !== 'login' && r !== 'cuenta/clave') { datos = null; if (modal.open) modal.close(); mostrarLogin(); }
    throw new Error(valor.error || 'No fue posible completar la operación.');
  }
  return valor;
}

function avisar(t) {
  $('#notificacion').textContent = t;
  clearTimeout(avisar.t);
  avisar.t = setTimeout(() => $('#notificacion').textContent = '', 4500);
}

// ====================================================================== Acceso y estructura
function mostrarLogin() {
  raiz.innerHTML = `<div class="login"><section class="login-presentacion"><div class="marca"><img src="assets/favicon.svg" alt="">Mecani<em>Gest</em></div><div><div class="eyebrow">ADMINISTRACIÓN DE TALLERES</div><h1>Más control.<br>Un taller <em>en orden.</em></h1><p>Clientes, vehículos y reparaciones en un solo lugar. La información que necesitas para atender mejor cada servicio.</p><div class="login-ilustracion"><div>${ico('vehiculos')}Sigue cada<br>vehículo</div><div>${ico('pagos')}Consulta costos<br>y pagos</div><div>${ico('escudo')}Conserva todo<br>el historial</div></div></div><div class="login-pie">MecaniGest · Herramientas para el trabajo de cada día</div></section>
  <section class="login-formulario"><div class="login-caja"><a class="login-return" href="index.html">← Volver al inicio</a><div class="eyebrow">BIENVENIDO A MECANIGEST</div><h2>Inicia sesión en tu taller</h2><p>Ingresa con la cuenta que te asignó el administrador.</p>
  <form id="login"><div class="error-form" role="alert"></div><label class="campo">Correo electrónico<input name="correo" type="email" placeholder="tu.correo@taller.com" required maxlength="190" autocomplete="username"></label><label class="campo">Contraseña<input name="clave" type="password" placeholder="Escribe tu contraseña" required maxlength="72" autocomplete="current-password"></label><button class="boton primario ancho" type="submit">Iniciar sesión ${ico('flecha')}</button></form>
  <div class="ayuda-login">${ico('escudo')} El acceso depende de tu perfil. Si necesitas una cuenta o recuperar tu contraseña, contacta al administrador del taller.</div></div></section></div>`;
  $('#login').onsubmit = async e => {
    e.preventDefault();
    const f = e.currentTarget, b = $('button', f);
    b.disabled = true;
    $('.error-form', f).textContent = '';
    try {
      const s = await api('login', Object.fromEntries(new FormData(f)));
      csrf = s.csrf;
      vista = 'inicio';
      await cargar();
    } catch (err) {
      $('.error-form', f).textContent = err.message;
    } finally {
      b.disabled = false;
    }
  };
}

function menu() {
  return [
    ['inicio', 'Resumen general'], ['clientes', 'Clientes'], ['vehiculos', 'Vehículos'],
    ['ordenes', mecanico() ? 'Mis órdenes' : 'Órdenes de servicio'], ['servicios', 'Servicios'], ['inventario', 'Inventario'],
    ...(!mecanico() ? [['pagos', 'Pagos']] : []),
    ...(admin() ? [['reportes', 'Reportes'], ['usuarios', 'Usuarios']] : []),
  ];
}

function estructura() {
  const opciones = menu();
  const activas = datos.ordenes.filter(activa).length;
  raiz.innerHTML = `<aside class="sidebar"><div class="marca"><img src="assets/favicon.svg" alt="">Mecani<em>Gest</em></div><div class="etiqueta-menu">MI TALLER</div>
  <nav class="nav" aria-label="Menú principal">${opciones.map(([id, n]) => `<button data-vista="${id}" class="${vista === id ? 'seleccion' : ''}">${ico(id)}<span>${n}</span>${id === 'ordenes' && activas ? `<span class="numero">${activas}</span>` : ''}</button>`).join('')}</nav>
  <div class="sidebar-pie"><div class="persona"><div class="avatar">${esc(datos.usuario.nombre.charAt(0))}</div><button class="persona-cuenta" data-accion="cuenta" title="Mi cuenta"><strong>${esc(datos.usuario.nombre)}</strong><small>${roles[datos.usuario.rol]} · Mi cuenta</small></button><button class="salir" data-accion="salir" aria-label="Cerrar sesión">${ico('salir')}</button></div></div></aside>
  <div class="contenido"><header class="topbar"><button class="boton-icono movil-menu" data-accion="menu" aria-label="Abrir menú">${ico('menu')}</button><div class="miga"><span>Mi taller</span><span>/</span><strong>${esc(opciones.find(x => x[0] === vista)?.[1] || 'Detalle de orden')}</strong></div>
  <form class="busqueda-global" id="buscar-global">${ico('buscar')}<input aria-label="Buscar orden, cliente o placas" placeholder="Buscar orden, cliente o placas…" maxlength="120"></form><div class="fecha-top">${ico('calendario')}${fecha(datos.hoy)}</div></header><main class="main" id="main"></main></div>`;
  $('#buscar-global').onsubmit = e => {
    e.preventDefault();
    const q = $('input', e.currentTarget).value;
    ir('ordenes');
    busqueda = q;
    renderVista();
  };
}

async function cargar() {
  datos = await api('datos');
  estructura();
  await renderVista();
}

function ir(v) {
  vista = v; busqueda = ''; filtro = ''; pagina = 1; ordenActual = null;
  estructura();
  renderVista().catch(e => avisar(e.message));
}

function cabecera(t, s, accion = '') {
  return `<div class="cabecera"><div><div class="eyebrow">${mecanico() ? 'ESPACIO DEL MECÁNICO' : 'OPERACIÓN DEL TALLER'}</div><h1>${t}</h1><p class="subtitulo">${s}</p></div><div class="acciones">${accion}</div></div>`;
}

function stat(label, valor, nota, icono = 'ordenes', fin = false) {
  return `<div class="stat ${fin ? 'stat-finanzas' : ''}"><div class="stat-encabezado">${label}<span class="stat-icono">${ico(icono)}</span></div><div class="stat-numero">${valor}</div><small>${nota}</small></div>`;
}

function tablaOrdenes(rows, corta = false) {
  if (!rows.length) return vacio('No hay órdenes que coincidan con esta búsqueda.');
  return `<div class="tabla-scroll"><table><thead><tr><th>Orden</th><th>Vehículo / Cliente</th><th>Estado del servicio</th>${corta ? '' : '<th>Mecánico</th><th>Pago</th>'}<th class="num">${corta ? 'Total' : 'Saldo pendiente'}</th><th></th></tr></thead><tbody>${rows.map(o => `<tr>
    <td><button class="folio" data-accion="orden" data-id="${o.id}">${folio(o.id)}</button><small>${fecha(o.fecha_ingreso)}${o.kilometraje != null ? ' · ' + km(o.kilometraje) : ''}</small></td>
    <td><strong>${esc(o.marca)} ${esc(o.modelo)}</strong><small>${esc(o.cliente)} · ${esc(o.placas)}</small></td>
    <td>${estadoBadge(o.estado)}</td>
    ${corta ? '' : `<td>${esc(o.mecanico)}</td><td>${pagoBadge(o)}</td>`}
    <td class="num"><strong>${dinero(corta ? o.total_centavos : o.saldo_centavos)}</strong></td>
    <td><button class="boton-icono" data-accion="orden" data-id="${o.id}" aria-label="Abrir ${folio(o.id)}">${ico('flecha')}</button></td></tr>`).join('')}</tbody></table></div>`;
}

// ====================================================================== Resumen general
function avisoRespaldo() {
  if (!admin()) return '';
  const ultimo = datos.ultimo_respaldo;
  const dias = ultimo ? (aFecha(datos.hoy).getTime() - aFecha(ultimo.slice(0, 10)).getTime()) / 86400000 : Infinity;
  if (dias <= 2) return '';
  return `<div class="nota-franja alerta-franja">${ico('alerta')} ${ultimo ? `El último respaldo de la base es del ${fecha(ultimo)}.` : 'Todavía no hay respaldos de la base de datos.'} Ejecuta <b>respaldar.ps1</b> o prográmalo para que se haga diario.</div>`;
}

function dashboard() {
  const activas = datos.ordenes.filter(activa), listas = activas.filter(o => o.estado === estados[3]);
  const saldo = datos.ordenes.reduce((a, o) => a + o.saldo_centavos, 0);
  const bajo = datos.refacciones.filter(r => r.activo == 1 && r.existencia <= 3);
  const hoy = datos.pagos.filter(p => !p.anulado_en && p.creado_en.startsWith(datos.hoy)).reduce((a, p) => a + Number(p.monto_centavos), 0);
  const sinAutorizar = activas.filter(o => o.estado === estados[1] && !o.autorizado_en);
  return cabecera(mecanico() ? 'Mi jornada de trabajo' : 'Resumen general', mecanico() ? 'Consulta tus vehículos asignados y continúa con las reparaciones.' : 'Todo lo que necesitas saber de tu taller, en un vistazo.', !mecanico() ? `<button class="boton primario" data-accion="nueva-orden">${ico('mas')} Nueva orden de servicio</button>` : '')
    + avisoRespaldo()
    + (datos.demo ? `<div class="nota-franja">${ico('alerta')} Entorno de demostración · Los clientes y operaciones de ejemplo son ficticios.</div>` : '')
    + `<div class="stats">${stat('Órdenes activas', activas.length, 'Servicios que siguen en proceso', 'ordenes')}${stat('Vehículos en el taller', new Set(activas.map(o => o.vehiculo_id)).size, 'Registrados y aún sin entregar', 'vehiculos')}${stat('Listos para entregar', listas.length, 'Trabajos terminados', 'check')}${mecanico() ? stat('En reparación', activas.filter(o => o.estado === estados[2]).length, 'Órdenes asignadas a ti', 'llave') : stat('Saldo por cobrar', dinero(saldo), 'Pendiente de todas las órdenes', 'pagos', true)}</div>
  <div class="rejilla-dashboard"><div><section class="panel"><div class="panel-titulo"><div><h2>${mecanico() ? 'Mis órdenes activas' : 'Órdenes en el taller'}</h2><small>Seguimiento de los servicios en curso</small></div><button class="boton ligero" data-vista="ordenes">Ver todas ${ico('flecha')}</button></div>${tablaOrdenes(activas.slice(0, 5), true)}<div class="paginacion">${activas.length} órdenes activas · Información actualizada</div></section>
  <div class="panel-doble"><section class="panel"><div class="panel-titulo"><h2>${mecanico() ? 'Próximas entregas' : 'Esperan autorización'}</h2>${ico(mecanico() ? 'vehiculos' : 'firma')}</div><div class="panel-cuerpo">${mecanico()
      ? (listas.length ? listas.slice(0, 3).map(filaAviso).join('') : '<p class="nota">Los vehículos terminados aparecerán aquí.</p>')
      : (sinAutorizar.length ? sinAutorizar.slice(0, 3).map(filaAviso).join('') : '<p class="nota">No hay diagnósticos esperando la autorización del cliente.</p>')}</div></section>
  <section class="panel"><div class="panel-titulo"><h2>${mecanico() ? 'Tu siguiente paso' : 'Ingresos de hoy'}</h2>${ico(mecanico() ? 'llave' : 'pagos')}</div><div class="panel-cuerpo">${mecanico() ? '<p class="nota">Abre una orden asignada para registrar el diagnóstico y actualizar el avance de la reparación.</p>' : `<div class="stat-numero">${dinero(hoy)}</div><p class="nota">Solo pagos registrados hoy. Los saldos pendientes no se cuentan como ingresos.</p>`}</div></section></div></div>
  <aside class="dashboard-lateral"><section class="panel"><div class="panel-titulo"><h2>Estado de los servicios</h2></div><div class="panel-cuerpo">${estados.slice(0, 4).map((e, i) => { const n = activas.filter(o => o.estado === e).length; return `<div class="fila-etapas"><span>${nombresEstado[i]}</span><b>${n}</b></div><progress class="barra" value="${n}" max="${Math.max(activas.length, 1)}" aria-label="${nombresEstado[i]}"></progress>`; }).join('')}<div class="resumen-inferior">${ico('escudo')}Cada vehículo tiene su propio historial de servicio.</div></div></section>
  <section class="panel"><div class="panel-titulo"><h2>Existencias bajas</h2><span class="badge parcial">${bajo.length}</span></div><div class="panel-cuerpo">${bajo.length ? bajo.slice(0, 4).map(r => `<div class="fila-aviso"><div>${esc(r.nombre)}<small>${esc(r.proveedor) || 'Sin proveedor'}</small></div><strong>${r.existencia} pzas.</strong></div>`).join('') : '<p class="nota">No hay refacciones activas con tres unidades o menos.</p>'}<button class="boton ligero" data-vista="inventario">Consultar inventario ${ico('flecha')}</button></div></section></aside></div>`;
}

const filaAviso = o => `<div class="fila-aviso"><div><strong>${esc(o.marca)} ${esc(o.modelo)}</strong><small>${esc(o.cliente)} · ${folio(o.id)}</small></div><button class="boton ligero" data-accion="orden" data-id="${o.id}">Ver orden</button></div>`;

// ====================================================================== Listados
function herramientas(opciones = []) {
  const ayuda = { clientes: 'nombre o teléfono', vehiculos: 'placas, VIN, marca o modelo' }[vista] || 'registros';
  return `<div class="toolbar"><input id="buscador" aria-label="Buscar registros" placeholder="Buscar ${ayuda}…" value="${esc(busqueda)}"><select id="filtro" aria-label="Filtrar registros"><option value="">Todos los registros</option>${opciones.map(([v, t]) => `<option value="${esc(v)}" ${filtro === v ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select><span class="contador" id="contador"></span></div><div id="resultados"></div>`;
}

function paginar(rows, tabla) {
  const total = rows.length, max = Math.max(1, Math.ceil(total / 10));
  pagina = Math.min(pagina, max);
  $('#contador').textContent = `${total} registros`;
  $('#resultados').innerHTML = tabla(rows.slice((pagina - 1) * 10, pagina * 10))
    + `<div class="paginacion"><span>Página ${pagina} de ${max}</span><button class="boton chico" data-accion="pagina" data-num="${pagina - 1}" ${pagina === 1 ? 'disabled' : ''}>Anterior</button><button class="boton chico" data-accion="pagina" data-num="${pagina + 1}" ${pagina === max ? 'disabled' : ''}>Siguiente</button></div>`;
}

const entidadVista = () => ({ inventario: 'refacciones' }[vista] || vista);

function filtrar() {
  const q = busqueda.toLocaleLowerCase('es');
  let rows = datos[entidadVista()] || [];
  rows = rows.filter(r => Object.entries(r).filter(([k]) => !['diagnostico', 'reparaciones'].includes(k)).some(([, v]) => String(v ?? '').toLocaleLowerCase('es').includes(q)) || (vista === 'ordenes' && folio(r.id).toLowerCase().includes(q)));
  if (filtro) {
    if (vista === 'ordenes') rows = rows.filter(r => filtro === 'deuda' ? r.saldo_centavos > 0 : filtro === 'pagado' ? r.estado_pago === 'PAGADO' : r.estado === filtro);
    else if (vista === 'inventario' && filtro === 'bajo') rows = rows.filter(r => r.activo == 1 && r.existencia <= 3);
    else if (vista === 'pagos') rows = rows.filter(r => filtro === 'anulado' ? r.anulado_en : !r.anulado_en && r.metodo === filtro);
    else rows = rows.filter(r => String(r.activo) === filtro);
  }
  const tab = (headers, body) => `<div class="tabla-scroll"><table><thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead><tbody>${body}</tbody></table></div>`;
  const estado = r => `<span class="badge ${r.activo == 1 ? 'pago' : ''}">${r.activo == 1 ? 'Activo' : 'Inactivo'}</span>`;
  const editar = r => !mecanico() ? `<button class="boton-icono" data-accion="editar" data-id="${r.id}" aria-label="Editar ${esc(r.nombre || r.placas)}">${ico('editar')}</button>` : '';
  const activar = r => admin() ? `<button class="boton chico" data-accion="activar" data-id="${r.id}" data-valor="${r.activo == 1 ? 0 : 1}">${r.activo == 1 ? 'Desactivar' : 'Activar'}</button>` : '';

  paginar(rows, lista => {
    if (!lista.length) return vacio('No hay registros que coincidan. Puedes cambiar la búsqueda o el filtro.');
    if (vista === 'ordenes') return tablaOrdenes(lista);
    if (vista === 'clientes') return tab(['Cliente', 'Contacto', 'Vehículos', 'Estado', 'Acciones'], lista.map(r => `<tr><td><strong>${esc(r.nombre)}</strong><small>Cliente #${r.id}</small></td><td>${esc(r.telefono)}<small>${esc(r.correo) || 'Sin correo'}</small></td><td>${datos.vehiculos.filter(v => v.cliente_id == r.id).length}</td><td>${estado(r)}</td><td><div class="acciones">${boton('Ver historial', 'historial', `data-entidad="clientes" data-id="${r.id}"`, 'chico')}${editar(r)}${activar(r)}</div></td></tr>`).join(''));
    if (vista === 'vehiculos') return tab(['Vehículo', 'Placas', 'Propietario actual', 'Último kilometraje', 'Estado', 'Acciones'], lista.map(r => `<tr><td><strong>${esc(r.marca)} ${esc(r.modelo)}</strong><small>Modelo ${r.anio}${r.vin ? ' · VIN ' + esc(r.vin) : ''}</small></td><td><span class="placa">${esc(r.placas)}</span></td><td>${esc(r.propietario)}</td><td>${km(r.ultimo_km)}</td><td>${estado(r)}</td><td><div class="acciones">${boton('Historial de servicios', 'historial', `data-entidad="vehiculos" data-id="${r.id}"`, 'chico')}${editar(r)}${activar(r)}</div></td></tr>`).join(''));
    if (vista === 'inventario') return tab(['Refacción', 'Proveedor', 'Existencia', 'Precio de venta', ...(admin() ? ['Costo', 'Margen'] : []), 'Estado', 'Acciones'], lista.map(r => `<tr><td><strong>${esc(r.nombre)}</strong><small>#REF-${String(r.id).padStart(3, '0')}</small></td><td>${esc(r.proveedor) || 'Sin proveedor'}</td><td><span class="badge ${r.existencia <= 3 ? 'parcial' : 'pago'}">${r.existencia} unidades</span></td><td>${dinero(r.precio_centavos)}</td>${admin() ? `<td>${Number(r.costo_centavos) ? dinero(r.costo_centavos) : '<span class="nota">Sin costo</span>'}</td><td>${margen(r.precio_centavos, r.costo_centavos)}</td>` : ''}<td>${estado(r)}</td><td><div class="acciones">${admin() ? `${boton('Entrada', 'entrada', `data-id="${r.id}"`, 'chico')}${boton('Movimientos', 'movimientos', `data-id="${r.id}"`, 'chico')}${editar(r)}${activar(r)}` : 'Consulta de existencias'}</div></td></tr>`).join(''));
    if (vista === 'servicios') return tab(['Servicio', 'Descripción', 'Precio', 'Estado', 'Acciones'], lista.map(r => `<tr><td><strong>${esc(r.nombre)}</strong><small>#SRV-${String(r.id).padStart(3, '0')}</small></td><td>${esc(r.descripcion) || '<span class="nota">Sin descripción</span>'}</td><td>${dinero(r.precio_centavos)}</td><td>${estado(r)}</td><td><div class="acciones">${admin() ? editar(r) + activar(r) : 'Consulta del catálogo'}</div></td></tr>`).join(''));
    if (vista === 'usuarios') return tab(['Usuario', 'Correo', 'Perfil', 'Estado', 'Acciones'], lista.map(r => `<tr><td><strong>${esc(r.nombre)}</strong></td><td>${esc(r.correo)}</td><td>${roles[r.rol]}</td><td>${estado(r)}</td><td><div class="acciones">${editar(r)}${activar(r)}</div></td></tr>`).join(''));
    return tab(['Pago', 'Orden / Cliente', 'Fecha', 'Método', 'Monto recibido', 'Registró'], lista.map(r => `<tr class="${r.anulado_en ? 'anulado' : ''}"><td>#P-${String(r.id).padStart(4, '0')}${r.anulado_en ? '<br><span class="badge deuda">Anulado</span>' : ''}</td><td><button class="folio" data-accion="orden" data-id="${r.orden_id}">${folio(r.orden_id)}</button><small>${esc(r.cliente)}</small></td><td>${fecha(r.creado_en)}</td><td>${esc(r.metodo)}</td><td><strong class="${r.anulado_en ? 'tachado' : ''}">${dinero(r.monto_centavos)}</strong>${r.anulado_en ? `<small>${esc(r.anulacion_motivo)}</small>` : ''}</td><td>${esc(r.registrado_por)}${r.anulado_en ? `<small>Anuló: ${esc(r.anulado_por_nombre)}</small>` : ''}</td></tr>`).join(''));
  });
}

function margen(precio, costo) {
  precio = Number(precio); costo = Number(costo);
  if (!costo) return '<span class="nota">—</span>';
  const pct = precio ? Math.round((precio - costo) * 100 / precio) : 0;
  return `${dinero(precio - costo)}<small>${pct} %</small>`;
}

// ====================================================================== Navegación de vistas
async function renderVista() {
  const main = $('#main');
  if (!main) return;
  if (vista === 'inicio') {
    main.innerHTML = dashboard();
  } else if (vista === 'orden') {
    await mostrarOrden(ordenActual);
    return;
  } else if (vista === 'reportes') {
    await mostrarReportes();
    return;
  } else {
    const titulos = {
      clientes: ['Clientes', 'Personas que confían sus vehículos a tu taller.', 'Nuevo cliente'],
      vehiculos: ['Vehículos', 'Información de cada vehículo, su kilometraje y su historial de servicios.', 'Nuevo vehículo'],
      ordenes: [mecanico() ? 'Mis órdenes de servicio' : 'Órdenes de servicio', 'Del ingreso a la entrega, cada servicio tiene seguimiento.', 'Nueva orden'],
      servicios: ['Catálogo de servicios', 'Trabajos con precio fijo que se agregan a las órdenes.', 'Nuevo servicio'],
      inventario: ['Inventario de refacciones', 'Consulta las piezas disponibles, sus costos y sus movimientos.', 'Nueva refacción'],
      pagos: ['Pagos registrados', 'Ingresos recibidos, con el detalle de quién y cuándo los registró.', 'Registrar pago'],
      usuarios: ['Usuarios y permisos', 'Administra el acceso del equipo a MecaniGest.', 'Nuevo usuario'],
    };
    const [titulo, sub, nuevo] = titulos[vista];
    const puede = !mecanico() && (!['inventario', 'servicios'].includes(vista) || admin());
    const opts = vista === 'ordenes'
      ? [...estados.map((e, i) => [e, nombresEstado[i]]), ['CANCELADA', 'Canceladas'], ['deuda', 'Con saldo pendiente'], ['pagado', 'Pagadas']]
      : vista === 'pagos'
        ? [...['Efectivo', 'Transferencia', 'Tarjeta'].map(v => [v, v]), ['anulado', 'Anulados']]
        : [['1', 'Activos'], ['0', 'Inactivos'], ...(vista === 'inventario' ? [['bajo', 'Existencias bajas']] : [])];
    main.innerHTML = cabecera(titulo, sub, puede ? `<button class="boton primario" data-accion="nuevo">${ico('mas')}${nuevo}</button>` : '')
      + (vista === 'inventario' ? `<div class="nota-franja">${ico('inventario')}Las existencias reflejan movimientos registrados. Verifica periódicamente las cantidades mediante un conteo físico.</div>` : '')
      + `<section class="panel">${herramientas(opts)}</section>`;
    $('#buscador').oninput = e => { busqueda = e.target.value; pagina = 1; filtrar(); };
    $('#filtro').onchange = e => { filtro = e.target.value; pagina = 1; filtrar(); };
    filtrar();
  }
  main.insertAdjacentHTML('beforeend', '<footer class="pie-app"><span>MecaniGest · Administración y control del taller</span><span>Importes en MXN</span></footer>');
}

// ====================================================================== Formularios
function campo(c) {
  const v = c.valor ?? '';
  const atrib = `name="${c.nombre}" ${c.requerido === false ? '' : 'required'} ${c.max ? `maxlength="${c.max}"` : ''}`;
  if (c.tipo === 'checkbox') {
    return `<label class="campo casilla ${c.completo ? 'completo' : ''}"><input type="checkbox" name="${c.nombre}" value="1" ${c.valor ? 'checked' : ''}> ${esc(c.etiqueta)}${c.ayuda ? `<small>${esc(c.ayuda)}</small>` : ''}</label>`;
  }
  let control;
  if (c.tipo === 'select') {
    control = `<select ${atrib}><option value="">${esc(c.vacio || 'Selecciona una opción')}</option>${c.opciones.map(([id, n]) => `<option value="${esc(id)}" ${String(v) === String(id) ? 'selected' : ''}>${esc(n)}</option>`).join('')}</select>`;
  } else if (c.tipo === 'textarea') {
    control = `<textarea ${atrib}>${esc(v)}</textarea>`;
  } else if (c.tipo === 'file') {
    control = `<input ${atrib} type="file" accept="${c.accept || ''}">`;
  } else {
    const numero = c.tipo === 'number' ? `min="${c.min ?? 0}" max="${c.limite ?? 99999999}" step="${c.paso ?? 1}"` : '';
    control = `<input ${atrib} type="${c.tipo || 'text'}" value="${esc(v)}" ${numero} ${c.tipo === 'password' ? `autocomplete="${c.autocompletar || 'new-password'}"` : ''} ${c.placeholder ? `placeholder="${esc(c.placeholder)}"` : ''}>`;
  }
  return `<label class="campo ${c.completo ? 'completo' : ''}">${esc(c.etiqueta)}${c.requerido === false ? '' : ' *'}${control}${c.ayuda ? `<small>${esc(c.ayuda)}</small>` : ''}</label>`;
}

/**
 * Abre un formulario modal. `enviar` recibe los datos (más una clave de operación única)
 * y el formulario; si termina sin error se cierra el modal y se recargan los datos.
 */
function formulario(titulo, campos, enviar, opciones = {}) {
  if (modal.open) modal.close();
  modal.innerHTML = `<form id="formulario"><header class="modal-head"><h2 id="modal-titulo">${esc(titulo)}</h2><button type="button" class="boton-icono" data-accion="cerrar" aria-label="Cerrar">${ico('cerrar')}</button></header>
    <div class="modal-body">${opciones.descripcion ? `<p class="nota">${esc(opciones.descripcion)}</p>` : ''}<div class="error-form" role="alert"></div><div class="form-grid">${campos.map(campo).join('')}</div></div>
    <footer class="modal-pie"><button class="boton" type="button" data-accion="cerrar">Cancelar</button><button type="submit" class="boton primario ${opciones.peligro ? 'peligro' : ''}">${esc(opciones.boton || 'Guardar')}</button></footer></form>`;
  const operacion = claveOperacion();
  modal.showModal();
  $('#formulario').onsubmit = async e => {
    e.preventDefault();
    const f = e.currentTarget;
    if (f.dataset.busy) return;
    f.dataset.busy = '1';
    const b = $('button[type=submit]', f);
    b.disabled = true;
    $('.error-form', f).textContent = '';
    try {
      const valores = Object.fromEntries([...new FormData(f)].filter(([, v]) => typeof v === 'string'));
      await enviar({ ...valores, operacion }, f);
      if (opciones.continuacion) return;
      modal.close();
      avisar(opciones.exito || 'Información guardada correctamente.');
      await cargar();
    } catch (err) {
      $('.error-form', f).textContent = err.message;
    } finally {
      delete f.dataset.busy;
      b.disabled = false;
    }
  };
}

function editarRegistro(id = 0) {
  const entidad = entidadVista(), r = (datos[entidad] || []).find(x => x.id == id) || {};
  const t = (nombre, etiqueta, max, requerido = true) => ({ nombre, etiqueta, max, requerido, valor: r[nombre] });
  let campos = [];
  if (entidad === 'clientes') campos = [{ ...t('nombre', 'Nombre del cliente', 120), completo: true }, t('telefono', 'Teléfono', 25), { ...t('correo', 'Correo electrónico', 190, false), tipo: 'email' }, { ...t('direccion', 'Dirección', 250, false), completo: true }];
  if (entidad === 'vehiculos') campos = [
    { nombre: 'cliente_id', etiqueta: 'Cliente propietario', tipo: 'select', valor: r.cliente_id, opciones: datos.clientes.filter(c => c.activo == 1).map(c => [c.id, c.nombre + ' · ' + c.telefono]), completo: true },
    t('marca', 'Marca', 60), t('modelo', 'Modelo', 80),
    { nombre: 'anio', etiqueta: 'Año del vehículo', tipo: 'number', min: 1900, limite: new Date().getFullYear() + 1, valor: r.anio },
    t('placas', 'Placas', 20),
    { ...t('vin', 'Número de serie (VIN)', 17, false), completo: true, ayuda: '17 caracteres. Aparece en la tarjeta de circulación y en el tablero, del lado del parabrisas.' },
  ];
  if (entidad === 'refacciones') campos = [
    { ...t('nombre', 'Nombre de la refacción', 120), completo: true },
    { nombre: 'precio', etiqueta: 'Precio de venta por unidad · MXN', tipo: 'number', paso: '.01', valor: id ? decimal(r.precio_centavos) : '' },
    { nombre: 'costo', etiqueta: 'Costo de compra por unidad · MXN', tipo: 'number', paso: '.01', requerido: false, valor: id ? decimal(r.costo_centavos) : '', ayuda: 'Solo lo ve administración. Se usa para calcular la utilidad.' },
    t('proveedor', 'Proveedor', 120, false),
    { ...t('descripcion', 'Descripción', 500, false), tipo: 'textarea', completo: true },
  ];
  if (entidad === 'servicios') campos = [
    { ...t('nombre', 'Nombre del servicio', 120), completo: true },
    { nombre: 'precio', etiqueta: 'Precio · MXN', tipo: 'number', paso: '.01', valor: id ? decimal(r.precio_centavos) : '', completo: true },
    { ...t('descripcion', 'Descripción', 500, false), tipo: 'textarea', completo: true },
  ];
  if (entidad === 'usuarios') campos = [
    { ...t('nombre', 'Nombre completo', 120), completo: true }, { ...t('correo', 'Correo electrónico', 190), tipo: 'email' },
    { nombre: 'rol', etiqueta: 'Perfil de acceso', tipo: 'select', valor: r.rol, opciones: Object.entries(roles) },
    { nombre: 'clave', etiqueta: id ? 'Nueva contraseña' : 'Contraseña', tipo: 'password', max: 72, requerido: !id, completo: true, ayuda: 'Mínimo 12 caracteres. ' + (id ? 'Deja vacío para conservar la contraseña actual.' : '') },
  ];
  const nombres = { clientes: 'cliente', vehiculos: 'vehículo', refacciones: 'refacción', usuarios: 'usuario', servicios: 'servicio' };
  formulario((id ? 'Editar ' : 'Registrar ') + nombres[entidad], campos, d => api('guardar', { ...d, entidad, id }), {
    descripcion: entidad === 'refacciones' ? 'Los cambios de precio solo se aplican a futuros usos. Las entradas de stock se registran por separado.'
      : entidad === 'servicios' ? 'Los cambios de precio solo se aplican a las órdenes donde se agregue el servicio después del cambio.' : undefined,
  });
}

function nuevaOrden() {
  const vehiculos = datos.vehiculos.filter(v => v.activo == 1 && datos.clientes.some(c => c.id == v.cliente_id && c.activo == 1));
  formulario('Nueva orden de servicio', [
    { nombre: 'vehiculo_id', etiqueta: 'Vehículo y propietario', tipo: 'select', completo: true, opciones: vehiculos.map(v => [v.id, `${v.placas} · ${v.marca} ${v.modelo} · ${v.propietario}${v.ultimo_km != null ? ' · último ' + km(v.ultimo_km) : ''}`]) },
    { nombre: 'mecanico_id', etiqueta: 'Mecánico asignado', tipo: 'select', completo: true, opciones: datos.usuarios.filter(u => u.rol === 'mecanico' && u.activo == 1).map(u => [u.id, u.nombre]) },
    { nombre: 'falla', etiqueta: 'Falla reportada por el cliente', tipo: 'textarea', max: 4000, completo: true },
    ...camposRecepcion({}),
  ], async d => {
    const r = await api('orden/crear', d);
    vista = 'orden';
    ordenActual = r.id;
  }, { descripcion: 'Registra primero al cliente y su vehículo si todavía no aparecen en el catálogo. Después podrás agregar fotos e imprimir la hoja de recepción.', boton: 'Crear orden' });
}

const camposRecepcion = o => [
  { nombre: 'kilometraje', etiqueta: 'Kilometraje', tipo: 'number', requerido: false, limite: 3000000, valor: o.kilometraje ?? '', placeholder: 'Ej. 85400' },
  { nombre: 'nivel_combustible', etiqueta: 'Nivel de combustible', tipo: 'select', requerido: false, vacio: 'Sin registrar', valor: o.nivel_combustible ?? '', opciones: COMBUSTIBLE.map(x => [x, x]) },
  { nombre: 'notas_recepcion', etiqueta: 'Estado del vehículo al recibirlo', tipo: 'textarea', requerido: false, max: 2000, completo: true, valor: o.notas_recepcion ?? '', ayuda: 'Golpes, rayones, testigos encendidos, objetos de valor, accesorios faltantes…' },
];

// ====================================================================== Detalle de la orden
async function mostrarOrden(id) {
  const ticket = ++version, o = await api(`orden&id=${id}`);
  if (ticket !== version || vista !== 'orden') return;
  ordenActual = id;
  window.detalleActual = o;

  const idx = estados.indexOf(o.estado), cancelada = o.estado === 'CANCELADA', abierta = activa(o);
  const editable = [1, 2].includes(idx) && (admin() || mecanico());
  const siguiente = abierta ? estados[idx + 1] : null;
  const puedeAvanzar = siguiente && (siguiente === 'ENTREGADO' ? !mecanico() : admin() || mecanico());
  const serviciosEditables = [0, 1, 2].includes(idx);
  const excedeAutorizado = abierta && o.autorizado_centavos != null && o.total_centavos > Number(o.autorizado_centavos);

  const acciones = boton('Volver a órdenes', 'volver')
    + (abierta && recepcionOAdmin() ? boton('Cancelar orden', 'cancelar-orden', '', 'peligro') : '')
    + (puedeAvanzar ? boton(siguiente === 'ENTREGADO' ? 'Registrar entrega' : 'Cambiar a ' + nombresEstado[idx + 1], 'avanzar', `data-id="${id}"`, 'primario') : '');

  const avisoAvance = idx === 1 && !o.autorizado_en
    ? `<div class="nota-franja alerta-franja">${ico('firma')} La reparación inicia cuando el cliente autoriza el presupuesto. ${recepcionOAdmin() ? 'Regístralo en «Autorización del cliente».' : 'Recepción debe registrar la autorización.'}</div>`
    : excedeAutorizado
      ? `<div class="nota-franja alerta-franja">${ico('alerta')} El total actual (${dinero(o.total_centavos)}) supera lo autorizado por el cliente (${dinero(o.autorizado_centavos)}). Hace falta una nueva autorización antes de terminar el trabajo.</div>`
      : '';

  $('#main').innerHTML = cabecera(folio(id), `${esc(o.marca)} ${esc(o.modelo)} · ${esc(o.placas)}`, acciones)
    + (cancelada ? `<div class="nota-franja alerta-franja">${ico('cancelar')} Orden cancelada el ${fechaHora(o.cancelado_en)} por ${esc(o.cancelado_por_nombre)}. Motivo: ${esc(o.cancelado_motivo)}</div>` : '')
    + avisoAvance
    + `<div class="detalle-grid"><div>
    ${panelInformacion(o, idx)}
    ${panelRecepcion(o, abierta)}
    ${panelTrabajo(o, editable)}
    ${panelServicios(o, serviciosEditables)}
    ${panelRefacciones(o, idx)}
    ${!mecanico() ? panelPagos(o) : ''}
    </div><aside>
    ${panelAutorizacion(o, idx, excedeAutorizado)}
    ${panelCostos(o, abierta)}
    ${recepcionOAdmin() ? panelImprimir() : ''}
    <section class="panel"><div class="panel-titulo"><h2>Actividad de la orden</h2></div><div class="panel-cuerpo">${o.actividad.map(a => `<div class="actividad">${esc(a.accion)}<small>${esc(a.usuario)} · ${fechaHora(a.creado_en)}</small></div>`).join('')}</div></section>
    </aside></div>`;
}

function panelInformacion(o, idx) {
  return `<section class="panel"><div class="panel-titulo"><h2>Información del servicio</h2>${estadoBadge(o.estado)}</div><div class="panel-cuerpo"><div class="datos-orden">
    <div class="dato"><span>Cliente de la orden</span><strong>${esc(o.cliente)}</strong><small>${esc(o.cliente_telefono)}</small></div>
    <div class="dato"><span>Vehículo</span><strong>${esc(o.marca)} ${esc(o.modelo)} ${esc(o.anio)}</strong>${o.vin ? `<small>VIN ${esc(o.vin)}</small>` : ''}</div>
    <div class="dato"><span>Placas</span><strong class="placa">${esc(o.placas)}</strong></div>
    <div class="dato"><span>Fecha de ingreso</span><strong>${fecha(o.fecha_ingreso)}</strong></div>
    <div class="dato"><span>Mecánico asignado</span><strong>${esc(o.mecanico)}</strong>${!mecanico() && activa(o) ? `<br><button class="boton ligero" data-accion="asignar">Reasignar</button>` : ''}</div>
    <div class="dato"><span>Fecha de entrega</span><strong>${fecha(o.fecha_entrega)}</strong></div>
  </div></div>${o.estado === 'CANCELADA' ? '' : `<div class="pasos">${nombresEstado.map((n, i) => `<span class="paso ${i === idx ? 'actual' : i < idx ? 'hecho' : ''}">${i < idx ? '✓' : i + 1} ${n}</span>`).join('')}</div>`}</section>`;
}

function panelRecepcion(o, abierta) {
  const fotos = o.fotos.length
    ? `<div class="fotos">${o.fotos.map(f => `<a class="foto" href="api.php?r=foto&id=${f.id}" target="_blank" rel="noopener"><img src="api.php?r=foto&id=${f.id}" alt="${esc(f.descripcion || 'Foto del vehículo')}" loading="lazy"><span>${esc(f.descripcion || 'Sin descripción')}<small>${esc(f.usuario)} · ${fechaHora(f.creado_en)}</small></span></a>`).join('')}</div>`
    : '<p class="nota">Sin fotos. Toma fotos de golpes, rayones o del tablero para respaldar el estado en que se recibió el vehículo.</p>';
  return `<section class="panel"><div class="panel-titulo"><div><h2>Recepción del vehículo</h2><small>Estado en que se recibió</small></div><div class="acciones">${abierta && recepcionOAdmin() ? boton('Editar recepción', 'recepcion', '', 'chico') : ''}${o.fotos.length < 20 ? boton('Agregar foto', 'foto', '', 'chico') : ''}</div></div><div class="panel-cuerpo">
    <div class="datos-orden"><div class="dato"><span>Kilometraje</span><strong>${km(o.kilometraje)}</strong></div><div class="dato"><span>Combustible</span><strong>${esc(o.nivel_combustible) || 'Sin registrar'}</strong></div><div class="dato"><span>Recibió</span><strong>${esc(o.creado_por_nombre)}</strong></div></div>
    <div class="separador"></div><h3>Notas de recepción</h3><p class="texto-multilinea">${esc(o.notas_recepcion) || 'Sin observaciones registradas.'}</p>
    <div class="separador"></div><h3>Fotos (${o.fotos.length})</h3>${fotos}</div></section>`;
}

function panelTrabajo(o, editable) {
  return `<section class="panel"><div class="panel-titulo"><h2>Diagnóstico y reparación</h2>${editable ? boton('Editar trabajo', 'tecnica', '', 'chico') : ''}</div><div class="panel-cuerpo">
    <h3>Falla reportada</h3><p class="texto-multilinea">${esc(o.falla)}</p><div class="separador"></div>
    <h3>Diagnóstico del mecánico</h3><p class="texto-multilinea">${esc(o.diagnostico) || 'Aún no se ha registrado un diagnóstico.'}</p><div class="separador"></div>
    <h3>Presupuesto estimado</h3><p class="texto-multilinea">${o.presupuesto_centavos != null ? `<strong>${dinero(o.presupuesto_centavos)}</strong>${o.presupuesto_detalle ? ' · ' + esc(o.presupuesto_detalle) : ''}` : 'Aún no se ha registrado un presupuesto.'}</p><div class="separador"></div>
    <h3>Reparaciones realizadas</h3><p class="texto-multilinea">${esc(o.reparaciones) || 'Aún no se han documentado reparaciones.'}</p></div></section>`;
}

function panelServicios(o, editables) {
  const puede = editables && (recepcionOAdmin() || mecanico());
  return `<section class="panel"><div class="panel-titulo"><div><h2>Servicios</h2><small>Trabajos del catálogo con precio fijo</small></div>${puede ? boton('Agregar servicio', 'servicio', '', 'chico') : ''}</div>${o.servicios.length
    ? `<div class="tabla-scroll"><table><thead><tr><th>Servicio</th><th>Cantidad</th><th>Precio</th><th>Importe</th>${puede ? '<th></th>' : ''}</tr></thead><tbody>${o.servicios.map(s => `<tr><td>${esc(s.nombre_historico)}<small>${esc(s.registrado_por)}</small></td><td>${s.cantidad}</td><td>${dinero(s.precio_centavos)}</td><td>${dinero(s.cantidad * s.precio_centavos)}</td>${puede ? `<td><button class="boton chico" data-accion="quitar-servicio" data-id="${s.id}">Quitar</button></td>` : ''}</tr>`).join('')}</tbody></table></div>`
    : vacio('Esta orden no tiene servicios del catálogo.')}</section>`;
}

function panelRefacciones(o, idx) {
  return `<section class="panel"><div class="panel-titulo"><div><h2>Refacciones utilizadas</h2><small>Precios guardados al momento de usar cada pieza</small></div>${idx === 2 && (admin() || mecanico()) ? boton('Agregar refacción', 'pieza', '', 'chico') : ''}</div>${o.detalles.length
    ? `<div class="tabla-scroll"><table><thead><tr><th>Refacción</th><th>Cantidad</th><th>Precio unitario</th><th>Importe</th>${admin() ? '<th>Costo</th><th>Utilidad</th>' : ''}</tr></thead><tbody>${o.detalles.map(d => `<tr><td>${esc(d.nombre_historico)}</td><td>${d.cantidad}</td><td>${dinero(d.precio_centavos)}</td><td>${dinero(d.cantidad * d.precio_centavos)}</td>${admin() ? `<td>${dinero(d.cantidad * d.costo_centavos)}</td><td>${Number(d.costo_centavos) ? dinero(d.cantidad * (d.precio_centavos - d.costo_centavos)) : '<span class="nota">Sin costo</span>'}</td>` : ''}</tr>`).join('')}</tbody></table></div>`
    : vacio('Esta orden todavía no tiene refacciones registradas.')}</section>`;
}

function panelPagos(o) {
  return `<section class="panel"><div class="panel-titulo"><h2>Historial de pagos</h2>${pagoBadge(o)}</div>${o.pagos.length
    ? `<div class="tabla-scroll"><table><thead><tr><th>Fecha</th><th>Método</th><th>Monto</th><th>Registró</th>${admin() ? '<th></th>' : ''}</tr></thead><tbody>${o.pagos.map(p => `<tr class="${p.anulado_en ? 'anulado' : ''}"><td>${fechaHora(p.creado_en)}</td><td>${esc(p.metodo)}${p.anulado_en ? '<br><span class="badge deuda">Anulado</span>' : ''}</td><td><span class="${p.anulado_en ? 'tachado' : ''}">${dinero(p.monto_centavos)}</span>${p.anulado_en ? `<small>${esc(p.anulacion_motivo)} · ${esc(p.anulado_por_nombre)} · ${fechaHora(p.anulado_en)}</small>` : ''}</td><td>${esc(p.registrado_por)}</td>${admin() ? `<td>${p.anulado_en ? '' : `<button class="boton chico" data-accion="anular-pago" data-id="${p.id}">Anular</button>`}</td>` : ''}</tr>`).join('')}</tbody></table></div>`
    : vacio('No hay pagos registrados para esta orden.')}</section>`;
}

function panelAutorizacion(o, idx, excede) {
  const puede = recepcionOAdmin() && [0, 1, 2, 3].includes(idx);
  const estado = o.autorizado_en
    ? `<div class="total-linea"><span>Monto autorizado</span><strong>${dinero(o.autorizado_centavos)}</strong></div><p class="nota">${esc(o.autorizacion_medio)} · ${esc(o.autorizado_por_nombre)} · ${fechaHora(o.autorizado_en)}${o.autorizacion_nota ? '<br>' + esc(o.autorizacion_nota) : ''}</p>${excede ? '<p class="nota aviso-texto">El total actual supera lo autorizado.</p>' : ''}`
    : `<p class="nota">${idx >= 2 && o.estado !== 'CANCELADA' ? 'Orden registrada antes del control de autorizaciones.' : 'El cliente todavía no autoriza el presupuesto.'}</p>`;
  return `<section class="panel"><div class="panel-titulo"><h2>Autorización del cliente</h2>${o.autorizado_en ? `<span class="badge ${excede ? 'deuda' : 'pago'}">${excede ? 'Excedida' : 'Autorizada'}</span>` : '<span class="badge parcial">Pendiente</span>'}</div><div class="panel-cuerpo">${estado}${puede ? boton(o.autorizado_en ? 'Registrar nueva autorización' : 'Registrar autorización', 'autorizar', '', 'ancho') : ''}</div></section>`;
}

function panelCostos(o, abierta) {
  const linea = (t, v, clase = '') => `<div class="total-linea ${clase}"><span>${t}</span><strong>${v}</strong></div>`;
  return `<section class="panel"><div class="panel-titulo"><h2>Resumen de costos</h2>${abierta && recepcionOAdmin() ? boton('Descuento e IVA', 'ajustes', '', 'chico') : ''}</div><div class="panel-cuerpo">
    ${linea('Mano de obra', dinero(o.mano_obra_centavos))}${linea('Servicios', dinero(o.servicios_centavos))}${linea('Refacciones', dinero(o.refacciones_centavos))}
    ${Number(o.descuento_centavos) || Number(o.iva_tasa) ? linea('Subtotal', dinero(o.subtotal_centavos)) : ''}
    ${Number(o.descuento_centavos) ? linea(`Descuento<small>${esc(o.descuento_motivo)}</small>`, '−' + dinero(o.descuento_centavos)) : ''}
    ${Number(o.iva_tasa) ? linea(`IVA ${Number(o.iva_tasa) / 100} %`, dinero(o.iva_centavos)) : ''}
    ${linea('Total', dinero(o.total_centavos), 'grande')}
    ${admin() && o.refacciones_costo_centavos ? linea('Utilidad en refacciones', dinero(o.refacciones_centavos - o.refacciones_costo_centavos)) : ''}
    ${linea('Pagos registrados', dinero(o.pagado_centavos))}${linea('Saldo pendiente', dinero(o.saldo_centavos), 'saldo')}
    ${!mecanico() && o.saldo_centavos > 0 ? boton('Registrar pago', 'pagar', `data-id="${o.id}"`, 'primario ancho') : ''}
    <p class="nota">Importes en MXN${Number(o.iva_tasa) ? ', IVA incluido en el total' : ', sin IVA'}. La entrega del vehículo no modifica los pagos ni liquida el saldo.</p></div></section>`;
}

function panelImprimir() {
  return `<section class="panel"><div class="panel-titulo"><h2>Imprimir o guardar PDF</h2>${ico('imprimir')}</div><div class="panel-cuerpo botones-columna">
    ${boton('Hoja de recepción', 'imprimir', 'data-tipo="recepcion"', 'ancho')}${boton('Presupuesto', 'imprimir', 'data-tipo="presupuesto"', 'ancho')}${boton('Orden y recibo', 'imprimir', 'data-tipo="recibo"', 'ancho')}
    <p class="nota">Se abre en una pestaña nueva. Para enviarlo por WhatsApp elige «Guardar como PDF» en el diálogo de impresión.</p></div></section>`;
}

// ====================================================================== Operaciones de la orden
async function pago(id) {
  // Saldo actual desde el servidor: otra persona pudo registrar o anular pagos desde que se cargó la página.
  const o = await api(`orden&id=${Number(id)}`);
  if (o.saldo_centavos <= 0) { avisar('Esta orden ya no tiene saldo pendiente.'); await cargar(); return; }
  formulario('Registrar pago · ' + folio(id), [
    { nombre: 'monto', etiqueta: 'Monto recibido · MXN', tipo: 'number', paso: '.01', min: '.01', limite: decimal(o.saldo_centavos), valor: decimal(o.saldo_centavos) },
    { nombre: 'metodo', etiqueta: 'Método de pago', tipo: 'select', opciones: ['Efectivo', 'Transferencia', 'Tarjeta'].map(x => [x, x]) },
  ], d => api('orden/pago', { ...d, id }), {
    descripcion: `Total ${dinero(o.total_centavos)} · Pagado ${dinero(o.pagado_centavos)} · Saldo ${dinero(o.saldo_centavos)}. Confirma que el dinero ya fue recibido. Este registro no realiza cobros bancarios; si te equivocas, administración puede anularlo indicando el motivo.`,
    boton: 'Confirmar pago recibido',
  });
}

function subirFoto() {
  formulario('Agregar foto · ' + folio(ordenActual), [
    { nombre: 'foto', etiqueta: 'Foto', tipo: 'file', accept: 'image/*', completo: true, ayuda: 'En tablet o celular puedes tomarla con la cámara. Se reduce automáticamente antes de enviarla.' },
    { nombre: 'descripcion', etiqueta: 'Descripción', requerido: false, max: 250, completo: true, placeholder: 'Ej. Rayón en puerta trasera derecha' },
  ], async (d, f) => {
    const archivo = f.querySelector('[name=foto]').files[0];
    if (!archivo) throw new Error('Selecciona una foto.');
    const imagen = await reducirImagen(archivo);
    const envio = new FormData();
    envio.append('id', ordenActual);
    envio.append('descripcion', d.descripcion || '');
    envio.append('foto', imagen, 'foto.jpg');
    await apiArchivo('orden/foto', envio);
  }, { boton: 'Guardar foto', exito: 'Foto guardada.' });
}

/** Reduce la foto a un máximo de 1600 px y la convierte a JPEG para ahorrar espacio y datos. */
async function reducirImagen(archivo) {
  const url = URL.createObjectURL(archivo);
  try {
    const img = await new Promise((ok, mal) => { const i = new Image(); i.onload = () => ok(i); i.onerror = () => mal(new Error('El archivo no es una imagen que el navegador pueda abrir.')); i.src = url; });
    const escala = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
    const lienzo = document.createElement('canvas');
    lienzo.width = Math.round(img.naturalWidth * escala);
    lienzo.height = Math.round(img.naturalHeight * escala);
    lienzo.getContext('2d').drawImage(img, 0, 0, lienzo.width, lienzo.height);
    return await new Promise((ok, mal) => lienzo.toBlob(b => b ? ok(b) : mal(new Error('No se pudo procesar la foto.')), 'image/jpeg', 0.82));
  } finally {
    URL.revokeObjectURL(url);
  }
}

function accionOrden(accion, el) {
  const o = window.detalleActual;
  if (accion === 'tecnica') return formulario('Diagnóstico, presupuesto y reparación', [
    { nombre: 'diagnostico', etiqueta: 'Diagnóstico del mecánico', tipo: 'textarea', valor: o.diagnostico, max: 6000, requerido: false, completo: true },
    { nombre: 'presupuesto', etiqueta: 'Presupuesto estimado total · MXN', tipo: 'number', paso: '.01', requerido: false, valor: o.presupuesto_centavos != null ? decimal(o.presupuesto_centavos) : '', ayuda: 'Lo que se le comunicará al cliente para que autorice.' },
    { nombre: 'mano_obra', etiqueta: 'Mano de obra adicional · MXN', tipo: 'number', paso: '.01', valor: decimal(o.mano_obra_centavos), ayuda: 'Trabajo fuera del catálogo de servicios.' },
    { nombre: 'presupuesto_detalle', etiqueta: 'Detalle del presupuesto', tipo: 'textarea', valor: o.presupuesto_detalle || '', max: 2000, requerido: false, completo: true },
    { nombre: 'reparaciones', etiqueta: 'Reparaciones realizadas', tipo: 'textarea', valor: o.reparaciones, max: 6000, requerido: false, completo: true },
  ], d => api('orden/tecnica', { ...d, id: ordenActual }));

  if (accion === 'recepcion') return formulario('Datos de recepción', camposRecepcion(o), d => api('orden/recepcion', { ...d, id: ordenActual }));

  if (accion === 'foto') return subirFoto();

  if (accion === 'asignar') return formulario('Asignar mecánico', [
    { nombre: 'mecanico_id', etiqueta: 'Responsable del trabajo', tipo: 'select', valor: o.mecanico_id, completo: true, opciones: datos.usuarios.filter(u => u.rol === 'mecanico' && u.activo == 1).map(u => [u.id, u.nombre]) },
  ], d => api('orden/asignar', { ...d, id: ordenActual }));

  if (accion === 'autorizar') {
    const sugerido = o.autorizado_en && o.total_centavos > Number(o.autorizado_centavos) ? o.total_centavos : (o.presupuesto_centavos ?? o.total_centavos);
    return formulario('Autorización del cliente', [
      { nombre: 'monto', etiqueta: 'Monto autorizado · MXN', tipo: 'number', paso: '.01', min: '.01', valor: Number(sugerido) ? decimal(sugerido) : '' },
      { nombre: 'medio', etiqueta: 'Cómo autorizó', tipo: 'select', opciones: MEDIOS.map(x => [x, x]) },
      { nombre: 'nota', etiqueta: 'Nota', requerido: false, max: 250, completo: true, placeholder: 'Ej. Autorizó por llamada a las 12:30' },
    ], d => api('orden/autorizar', { ...d, id: ordenActual }), {
      descripcion: `Presupuesto: ${o.presupuesto_centavos != null ? dinero(o.presupuesto_centavos) : 'sin registrar'} · Total actual: ${dinero(o.total_centavos)}. Registra solo lo que el cliente aceptó. Si no lo autoriza, usa «Cancelar orden».`,
      boton: 'Registrar autorización', exito: 'Autorización registrada.',
    });
  }

  if (accion === 'servicio') return formulario('Agregar servicio', [
    { nombre: 'servicio_id', etiqueta: 'Servicio del catálogo', tipo: 'select', completo: true, opciones: datos.servicios.filter(s => s.activo == 1).map(s => [s.id, `${s.nombre} · ${dinero(s.precio_centavos)}`]) },
    { nombre: 'cantidad', etiqueta: 'Cantidad', tipo: 'number', min: 1, limite: 100, valor: 1, completo: true },
  ], d => api('orden/servicio', { ...d, id: ordenActual }), { boton: 'Agregar servicio' });

  if (accion === 'quitar-servicio') {
    const s = o.servicios.find(x => x.id == el.dataset.id);
    return formulario('Quitar servicio', [], () => api('orden/servicio/quitar', { id: ordenActual, linea_id: s.id }), { descripcion: `Se quitará «${s.nombre_historico}» (${dinero(s.cantidad * s.precio_centavos)}) de la orden. Quedará registrado en la actividad.`, boton: 'Quitar servicio', peligro: true });
  }

  if (accion === 'pieza') return formulario('Utilizar refacción', [
    { nombre: 'refaccion_id', etiqueta: 'Refacción disponible', tipo: 'select', completo: true, opciones: datos.refacciones.filter(r => r.activo == 1 && r.existencia > 0).map(r => [r.id, `${r.nombre} · ${r.existencia} disponibles · ${dinero(r.precio_centavos)} c/u`]) },
    { nombre: 'cantidad', etiqueta: 'Cantidad utilizada', tipo: 'number', min: 1, limite: 10000, valor: 1, completo: true },
  ], d => api('orden/refaccion', { ...d, id: ordenActual }), { descripcion: 'Esta operación descontará existencias y guardará el precio actual en la orden. Verifica la pieza y la cantidad antes de confirmar.', boton: 'Confirmar uso de refacción' });

  if (accion === 'ajustes') return formulario('Descuento e IVA', [
    { nombre: 'descuento', etiqueta: 'Descuento · MXN', tipo: 'number', paso: '.01', requerido: false, valor: Number(o.descuento_centavos) ? decimal(o.descuento_centavos) : '' },
    { nombre: 'descuento_motivo', etiqueta: 'Motivo del descuento', requerido: false, max: 250, valor: o.descuento_motivo, placeholder: 'Ej. Cliente frecuente' },
    { nombre: 'aplicar_iva', etiqueta: `Agregar IVA (${(Number(o.iva_tasa) || datos.iva_tasa) / 100} %) al total`, tipo: 'checkbox', valor: Number(o.iva_tasa) > 0, completo: true, ayuda: 'Márcalo cuando el cliente requiera factura y los precios no incluyan IVA.' },
  ], d => api('orden/ajustes', { ...d, id: ordenActual }), { descripcion: `Subtotal actual: ${dinero(o.subtotal_centavos)}. El descuento se resta antes de calcular el IVA.` });

  if (accion === 'cancelar-orden') return formulario('Cancelar orden · ' + folio(ordenActual), [
    { nombre: 'motivo', etiqueta: 'Motivo', max: 250, completo: true, placeholder: 'Ej. El cliente no autorizó el presupuesto' },
  ], d => api('orden/cancelar', { ...d, id: ordenActual }), {
    descripcion: `La orden se cerrará sin reparar y el vehículo podrá abrir una orden nueva. Los cargos registrados (${dinero(o.total_centavos)}) y los pagos se conservan; si el saldo no se cobrará, aplica antes un descuento. Las refacciones ya utilizadas no regresan solas al inventario.`,
    boton: 'Confirmar cancelación', peligro: true, exito: 'Orden cancelada.',
  });

  if (accion === 'anular-pago') {
    const p = o.pagos.find(x => x.id == el.dataset.id);
    return formulario('Anular pago', [{ nombre: 'motivo', etiqueta: 'Motivo de la anulación', max: 250, completo: true, placeholder: 'Ej. Monto capturado por error' }], d => api('pago/anular', { pago_id: p.id, motivo: d.motivo }), {
      descripcion: `Pago de ${dinero(p.monto_centavos)} (${p.metodo}) del ${fechaHora(p.creado_en)}. Dejará de contar como ingreso y el saldo de la orden aumentará. El registro se conserva marcado como anulado.`,
      boton: 'Anular pago', peligro: true, exito: 'Pago anulado.',
    });
  }

  if (accion === 'imprimir') {
    window.open(`imprimir.html?id=${ordenActual}&tipo=${el.dataset.tipo}`, '_blank', 'noopener');
    return;
  }

  if (accion === 'avanzar') {
    const next = estados[estados.indexOf(o.estado) + 1];
    return formulario('Confirmar cambio de estado', [], () => api('orden/estado', { id: ordenActual, estado: next }), {
      descripcion: `${folio(ordenActual)} pasará a ${next.toLocaleLowerCase('es')}. ${next === 'ENTREGADO' ? 'Confirma que el vehículo ya fue entregado. Se bloquearán los cambios técnicos y de precios; el saldo pendiente permanecerá igual.' : 'Verifica que la etapa anterior esté completada.'}`,
      boton: next === 'ENTREGADO' ? 'Confirmar entrega' : 'Confirmar avance',
    });
  }
}

// ====================================================================== Reportes
async function mostrarReportes() {
  const hoy = datos.hoy, desde = hoy.slice(0, 8) + '01';
  $('#main').innerHTML = cabecera('Reportes del taller', 'Consulta servicios, ingresos y utilidad del periodo seleccionado.')
    + `<form id="fechas" class="resumen-fechas"><label class="campo">Desde<input type="date" name="desde" value="${desde}" required></label><label class="campo">Hasta<input type="date" name="hasta" value="${hoy}" required></label><button class="boton primario">Consultar periodo</button></form><div id="reporte-contenido"></div>`;
  const tabla = (encabezados, filas) => filas.length ? `<div class="tabla-scroll"><table><thead><tr>${encabezados.map(h => `<th>${h}</th>`).join('')}</tr></thead><tbody>${filas.join('')}</tbody></table></div>` : vacio();
  const consultar = async d => {
    const r = await api(`reportes&desde=${encodeURIComponent(d.desde)}&hasta=${encodeURIComponent(d.hasta)}`);
    if (vista !== 'reportes') return;
    const utilidad = r.refacciones_venta_centavos - r.refacciones_costo_centavos;
    $('#reporte-contenido').innerHTML = `<div class="stats">${stat('Ingresos recibidos', dinero(r.ingresos_centavos), 'Pagos vigentes registrados en el periodo', 'pagos', true)}${stat('Servicios entregados', r.servicios.length, `${r.vehiculos_atendidos} vehículos · ${r.canceladas.length} canceladas`, 'check')}${stat('Utilidad en refacciones', dinero(utilidad), `Venta ${dinero(r.refacciones_venta_centavos)} − costo ${dinero(r.refacciones_costo_centavos)}`, 'inventario')}${stat('Órdenes aún activas', r.pendientes.length, 'Situación actual, todos los periodos', 'ordenes')}</div>
    <div class="panel-doble"><section class="panel"><div class="panel-titulo"><h2>Servicios del catálogo vendidos</h2></div>${tabla(['Servicio', 'Cantidad', 'Importe'], r.servicios_vendidos.map(s => `<tr><td>${esc(s.nombre)}</td><td>${s.cantidad}</td><td>${dinero(s.importe_centavos)}</td></tr>`))}</section>
    <section class="panel"><div class="panel-titulo"><h2>Refacciones utilizadas</h2></div>${tabla(['Refacción', 'Unidades', 'Venta', 'Costo', 'Utilidad'], r.piezas.map(p => `<tr><td>${esc(p.nombre)}</td><td>${p.cantidad}</td><td>${dinero(p.importe_centavos)}</td><td>${dinero(p.costo_centavos)}</td><td>${dinero(p.importe_centavos - p.costo_centavos)}</td></tr>`))}</section></div>
    <div class="panel-doble"><section class="panel"><div class="panel-titulo"><h2>Pagos recibidos en el periodo</h2></div>${tabla(['Orden', 'Fecha', 'Ingreso'], r.pagos.map(p => `<tr><td><button class="folio" data-accion="orden" data-id="${p.orden_id}">${folio(p.orden_id)}</button><small>${esc(p.cliente)}</small></td><td>${fecha(p.creado_en)}</td><td>${dinero(p.monto_centavos)}</td></tr>`))}</section>
    <section class="panel"><div class="panel-titulo"><h2>Pagos anulados en el periodo</h2></div>${tabla(['Orden', 'Monto', 'Motivo'], r.pagos_anulados.map(p => `<tr><td><button class="folio" data-accion="orden" data-id="${p.orden_id}">${folio(p.orden_id)}</button><small>${esc(p.anulado_por_nombre)} · ${fecha(p.anulado_en)}</small></td><td>${dinero(p.monto_centavos)}</td><td>${esc(p.anulacion_motivo)}</td></tr>`))}</section></div>
    <div class="panel-doble"><section class="panel"><div class="panel-titulo"><div><h2>Órdenes entregadas</h2><small>Descuentos ${dinero(r.descuentos_centavos)} · IVA ${dinero(r.iva_centavos)}</small></div></div>${tabla(['Orden', 'Cliente', 'Vehículo', 'Entrega', 'Total'], r.servicios.map(o => `<tr><td><button class="folio" data-accion="orden" data-id="${o.id}">${folio(o.id)}</button></td><td>${esc(o.cliente)}</td><td>${esc(o.placas)}</td><td>${fecha(o.fecha_entrega)}</td><td>${dinero(o.total_centavos)}</td></tr>`))}</section>
    <section class="panel"><div class="panel-titulo"><h2>Órdenes canceladas</h2></div>${tabla(['Orden', 'Cliente', 'Motivo'], r.canceladas.map(o => `<tr><td><button class="folio" data-accion="orden" data-id="${o.id}">${folio(o.id)}</button><small>${fecha(o.cancelado_en)}</small></td><td>${esc(o.cliente)}</td><td>${esc(o.cancelado_motivo)}</td></tr>`))}</section></div>
    <section class="panel"><div class="panel-titulo"><div><h2>Órdenes pendientes de pago</h2><small>Saldo actual de todas las órdenes · No se considera ingreso recibido</small></div><strong>${dinero(r.por_cobrar.reduce((s, o) => s + o.saldo_centavos, 0))}</strong></div>${tablaOrdenes(r.por_cobrar)}</section>
    <section class="panel"><div class="panel-titulo"><h2>Servicios pendientes actuales</h2></div>${tablaOrdenes(r.pendientes)}</section>
    <p class="nota">Las entregas se agrupan por fecha de entrega, las piezas y servicios por fecha de registro, los ingresos por fecha de pago y las anulaciones por fecha de anulación. Estos reportes apoyan la administración y no sustituyen un sistema contable.</p>`;
  };
  $('#fechas').onsubmit = async e => {
    e.preventDefault();
    const b = $('button', e.currentTarget);
    b.disabled = true;
    try { await consultar(Object.fromEntries(new FormData(e.currentTarget))); } catch (err) { avisar(err.message); } finally { b.disabled = false; }
  };
  await consultar({ desde, hasta: hoy });
}

// ====================================================================== Mi cuenta
function miCuenta() {
  formulario('Mi cuenta · cambiar contraseña', [
    { nombre: 'actual', etiqueta: 'Contraseña actual', tipo: 'password', max: 72, completo: true, autocompletar: 'current-password' },
    { nombre: 'nueva', etiqueta: 'Contraseña nueva', tipo: 'password', max: 72, completo: true, ayuda: 'Mínimo 12 caracteres. Puedes usar una frase.' },
    { nombre: 'confirmar', etiqueta: 'Confirma la contraseña nueva', tipo: 'password', max: 72, completo: true },
  ], async d => {
    if (d.nueva !== d.confirmar) throw new Error('La confirmación no coincide con la contraseña nueva.');
    await api('cuenta/clave', { actual: d.actual, nueva: d.nueva });
  }, { descripcion: `${datos.usuario.nombre} · ${datos.usuario.correo}`, boton: 'Cambiar contraseña', exito: 'Contraseña actualizada.' });
}

// ====================================================================== Eventos
document.addEventListener('click', async e => {
  const el = e.target.closest('[data-accion],[data-vista]');
  if (!el) return;
  try {
    if (el.dataset.vista) { ir(el.dataset.vista); return; }
    const accion = el.dataset.accion, id = Number(el.dataset.id);
    if (accion === 'cerrar') { modal.close(); return; }
    if (accion === 'menu') { $('.sidebar').classList.toggle('abierta'); return; }
    if (accion === 'salir') { await api('logout', {}); csrf = (await api('sesion')).csrf; datos = null; mostrarLogin(); return; }
    if (accion === 'cuenta') { miCuenta(); return; }
    if (accion === 'volver') { ir('ordenes'); return; }
    if (accion === 'pagina') { pagina = Number(el.dataset.num); filtrar(); return; }
    if (accion === 'orden') { vista = 'orden'; ordenActual = id; estructura(); await mostrarOrden(id); return; }
    if (accion === 'nueva-orden') { nuevaOrden(); return; }
    if (accion === 'nuevo') {
      if (vista === 'ordenes') nuevaOrden();
      else if (vista === 'pagos') formulario('Seleccionar orden para pago', [{ nombre: 'id', etiqueta: 'Orden con saldo pendiente', tipo: 'select', completo: true, opciones: datos.ordenes.filter(o => o.saldo_centavos > 0).map(o => [o.id, `${folio(o.id)} · ${o.cliente} · ${dinero(o.saldo_centavos)}`]) }], async d => { await pago(d.id); }, { boton: 'Continuar', continuacion: true });
      else editarRegistro();
      return;
    }
    if (accion === 'editar') { editarRegistro(id); return; }
    if (accion === 'activar') {
      const entidad = entidadVista(), a = Number(el.dataset.valor);
      formulario(a ? 'Activar registro' : 'Desactivar registro', [], () => api('activar', { entidad, id, activo: a }), { descripcion: a ? 'El registro volverá a estar disponible para nuevas operaciones.' : 'El registro dejará de estar disponible para nuevas operaciones. Su historial, órdenes y pagos se conservarán.', boton: a ? 'Confirmar activación' : 'Confirmar desactivación' });
      return;
    }
    if (accion === 'historial') {
      const ent = el.dataset.entidad, registro = datos[ent].find(x => x.id == id);
      const rows = datos.ordenes.filter(o => (ent === 'clientes' ? o.cliente_id : o.vehiculo_id) == id);
      vista = 'historial';
      estructura();
      $('#main').innerHTML = cabecera('Historial de servicios', esc(registro.nombre || `${registro.marca} ${registro.modelo} · ${registro.placas}`), `<button class="boton" data-vista="${ent}">Volver</button>`)
        + (ent === 'clientes' ? `<section class="panel"><div class="panel-titulo"><h2>Vehículos asociados</h2></div><div class="panel-cuerpo">${datos.vehiculos.filter(v => v.cliente_id == id).map(v => `<p>${esc(v.marca)} ${esc(v.modelo)} · <span class="placa">${esc(v.placas)}</span> · ${km(v.ultimo_km)}</p>`).join('') || '<p class="nota">Sin vehículos asociados.</p>'}</div></section>`
          : `<section class="panel"><div class="panel-titulo"><h2>Datos del vehículo</h2></div><div class="panel-cuerpo"><div class="datos-orden"><div class="dato"><span>Último kilometraje</span><strong>${km(registro.ultimo_km)}</strong></div><div class="dato"><span>VIN</span><strong>${esc(registro.vin) || 'Sin registrar'}</strong></div><div class="dato"><span>Propietario</span><strong>${esc(registro.propietario)}</strong></div></div></div></section>`)
        + `<section class="panel"><div class="panel-titulo"><h2>Órdenes registradas</h2><small>Abre una orden para consultar diagnóstico, piezas y pagos</small></div>${tablaOrdenes(rows)}</section>`;
      return;
    }
    if (accion === 'entrada') {
      const r = datos.refacciones.find(x => x.id == id);
      formulario('Registrar entrada de inventario', [
        { nombre: 'cantidad', etiqueta: 'Unidades recibidas', tipo: 'number', min: 1, limite: 10000 },
        { nombre: 'costo', etiqueta: 'Costo por unidad · MXN', tipo: 'number', paso: '.01', requerido: false, valor: Number(r.costo_centavos) ? decimal(r.costo_centavos) : '', ayuda: 'Si lo indicas, se guarda como el costo actual de la refacción.' },
        { nombre: 'motivo', etiqueta: 'Motivo de la entrada', max: 250, completo: true },
      ], d => api('inventario/entrada', { ...d, refaccion_id: id }), { descripcion: `${r.nombre} · Existencia actual: ${r.existencia} unidades. Confirma que las piezas fueron recibidas físicamente.`, boton: 'Confirmar entrada' });
      return;
    }
    if (accion === 'movimientos') {
      const rows = await api(`movimientos&id=${id}`);
      modal.innerHTML = `<header class="modal-head"><h2 id="modal-titulo">Movimientos de inventario</h2><button class="boton-icono" data-accion="cerrar" aria-label="Cerrar">${ico('cerrar')}</button></header><div class="modal-body movimientos-modal">${rows.length ? rows.map(m => `<div class="fila-aviso"><div><strong>${esc(m.tipo)} · ${m.cantidad} unidades</strong><small>${esc(m.usuario)} · ${fechaHora(m.creado_en)}${m.costo_unitario_centavos != null ? ' · costo ' + dinero(m.costo_unitario_centavos) + ' c/u' : ''}</small><p class="nota">${esc(m.motivo)} ${m.orden_id ? '· ' + folio(m.orden_id) : ''}</p></div></div>`).join('') : vacio()}</div><div class="modal-pie"><button class="boton" data-accion="cerrar">Cerrar</button></div>`;
      modal.showModal();
      return;
    }
    if (accion === 'pagar') { await pago(id); return; }
    accionOrden(accion, el);
  } catch (err) {
    avisar(err.message);
  }
});

(async () => {
  try {
    const s = await api('sesion');
    csrf = s.csrf;
    if (s.usuario) await cargar(); else mostrarLogin();
  } catch (e) {
    raiz.innerHTML = `<div class="inicio-carga"><div><h2>No se pudo conectar con MecaniGest</h2><p>${esc(e.message)}</p><button class="boton" id="reintentar">Volver a intentar</button></div></div>`;
    $('#reintentar').onclick = () => location.reload();
  }
})();
