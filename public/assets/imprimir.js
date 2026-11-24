'use strict';
// Documentos imprimibles de una orden: hoja de recepción, presupuesto y orden con recibo.
// Uso: imprimir.html?id=12&tipo=recepcion|presupuesto|recibo

const $ = s => document.querySelector(s);
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const dinero = v => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v || 0) / 100);
const aFecha = v => new Date(v.replace(' ', 'T'));
const fecha = v => v ? new Intl.DateTimeFormat('es-MX', { day: '2-digit', month: 'long', year: 'numeric' }).format(aFecha(v)) : '—';
const fechaHora = v => v ? fecha(v) + ', ' + v.slice(11, 16) + ' h' : '—';
const km = v => v === null || v === undefined || v === '' ? 'Sin registrar' : new Intl.NumberFormat('es-MX').format(v) + ' km';
const folio = id => 'OS-' + String(id).padStart(4, '0');
const texto = (v, vacio = 'Sin registrar') => esc(v) || `<span class="tenue">${vacio}</span>`;

const TITULOS = { recepcion: 'Hoja de recepción', presupuesto: 'Presupuesto', recibo: 'Orden de servicio y recibo' };

function encabezado(o, tipo) {
  const t = o.taller;
  return `<header class="encabezado"><div><h1>${esc(t.nombre)}</h1>${[t.direccion, t.telefono ? 'Tel. ' + t.telefono : '', t.rfc ? 'RFC ' + t.rfc : ''].filter(Boolean).map(x => `<p>${esc(x)}</p>`).join('')}</div>
    <div class="folio"><span>${TITULOS[tipo]}</span><strong>${folio(o.id)}</strong><small>Emitido el ${new Intl.DateTimeFormat('es-MX', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date())}</small></div></header>
    ${o.estado === 'CANCELADA' ? `<div class="aviso">ORDEN CANCELADA · ${esc(o.cancelado_motivo)} · ${fechaHora(o.cancelado_en)}</div>` : ''}`;
}

function datosGenerales(o) {
  const dato = (etiqueta, valor) => `<div><span>${etiqueta}</span><strong>${valor}</strong></div>`;
  return `<section class="bloque rejilla">
    <div class="columna"><h2>Cliente</h2>${dato('Nombre', esc(o.cliente))}${dato('Teléfono', texto(o.cliente_telefono))}${o.cliente_correo ? dato('Correo', esc(o.cliente_correo)) : ''}${o.cliente_direccion ? dato('Dirección', esc(o.cliente_direccion)) : ''}</div>
    <div class="columna"><h2>Vehículo</h2>${dato('Vehículo', `${esc(o.marca)} ${esc(o.modelo)} ${esc(o.anio)}`)}${dato('Placas', esc(o.placas))}${dato('VIN', texto(o.vin))}${dato('Kilometraje', km(o.kilometraje))}</div>
    <div class="columna"><h2>Servicio</h2>${dato('Ingreso', fechaHora(o.fecha_ingreso))}${dato('Recibió', esc(o.creado_por_nombre))}${dato('Mecánico', esc(o.mecanico))}${o.fecha_entrega ? dato('Entrega', fechaHora(o.fecha_entrega)) : ''}</div>
  </section>`;
}

const parrafo = (titulo, valor, vacio) => `<section class="bloque"><h2>${titulo}</h2><p class="multilinea">${texto(valor, vacio)}</p></section>`;
const firmas = (izquierda, derecha) => `<section class="firmas"><div><span></span>${izquierda}</div><div><span></span>${derecha}</div></section>`;

function conceptos(o) {
  const filas = [
    ...o.servicios.map(s => [s.nombre_historico, s.cantidad, s.precio_centavos]),
    ...o.detalles.map(d => [d.nombre_historico, d.cantidad, d.precio_centavos]),
    ...(Number(o.mano_obra_centavos) ? [['Mano de obra', 1, o.mano_obra_centavos]] : []),
  ];
  if (!filas.length) return '<p class="tenue">Sin conceptos registrados.</p>';
  return `<table><thead><tr><th>Concepto</th><th class="num">Cantidad</th><th class="num">Precio</th><th class="num">Importe</th></tr></thead><tbody>${filas.map(([n, c, p]) => `<tr><td>${esc(n)}</td><td class="num">${c}</td><td class="num">${dinero(p)}</td><td class="num">${dinero(c * p)}</td></tr>`).join('')}</tbody></table>`;
}

function totales(o, conPagos) {
  const linea = (t, v, clase = '') => `<div class="${clase}"><span>${t}</span><strong>${v}</strong></div>`;
  return `<div class="totales">
    ${linea('Subtotal', dinero(o.subtotal_centavos))}
    ${Number(o.descuento_centavos) ? linea('Descuento' + (o.descuento_motivo ? ` (${esc(o.descuento_motivo)})` : ''), '−' + dinero(o.descuento_centavos)) : ''}
    ${Number(o.iva_tasa) ? linea(`IVA ${Number(o.iva_tasa) / 100} %`, dinero(o.iva_centavos)) : ''}
    ${linea('Total', dinero(o.total_centavos), 'total')}
    ${conPagos ? linea('Pagado', dinero(o.pagado_centavos)) + linea('Saldo pendiente', dinero(o.saldo_centavos), 'total') : ''}
  </div>`;
}

function recepcion(o) {
  return encabezado(o, 'recepcion') + datosGenerales(o)
    + `<section class="bloque rejilla dos"><div><h2>Nivel de combustible</h2><div class="combustible">${['Reserva', '1/4', '1/2', '3/4', 'Lleno'].map(n => `<span class="${o.nivel_combustible === n ? 'marcado' : ''}">${n}</span>`).join('')}</div></div><div><h2>Kilometraje al recibir</h2><p class="grande">${km(o.kilometraje)}</p></div></section>`
    + parrafo('Falla reportada por el cliente', o.falla)
    + parrafo('Estado del vehículo al recibirlo', o.notas_recepcion, 'Sin observaciones registradas.')
    + (o.fotos.length ? `<section class="bloque"><h2>Fotos de recepción (${o.fotos.length})</h2><div class="fotos">${o.fotos.map(f => `<figure><img src="api.php?r=foto&id=${f.id}" alt=""><figcaption>${esc(f.descripcion)}</figcaption></figure>`).join('')}</div></section>` : '')
    + `<p class="leyenda">El cliente confirma que la información anterior describe el vehículo al momento de su recepción. El diagnóstico y cualquier reparación se realizarán solo con su autorización.</p>`
    + firmas(`Firma del cliente<small>${esc(o.cliente)}</small>`, `Recibió por el taller<small>${esc(o.creado_por_nombre)}</small>`);
}

function presupuesto(o) {
  const autorizado = o.autorizado_en
    ? `<p>Autorizado por <strong>${dinero(o.autorizado_centavos)}</strong> · ${esc(o.autorizacion_medio)} · ${fechaHora(o.autorizado_en)}${o.autorizacion_nota ? ' · ' + esc(o.autorizacion_nota) : ''}</p>`
    : '<p>Autorizo la reparación descrita por un monto de: <span class="linea-monto"></span></p>';
  return encabezado(o, 'presupuesto') + datosGenerales(o)
    + parrafo('Falla reportada', o.falla)
    + parrafo('Diagnóstico', o.diagnostico, 'Diagnóstico pendiente.')
    + `<section class="bloque"><h2>Presupuesto estimado</h2><p class="grande">${o.presupuesto_centavos != null ? dinero(o.presupuesto_centavos) : '<span class="tenue">Sin registrar</span>'}</p>${o.presupuesto_detalle ? `<p class="multilinea">${esc(o.presupuesto_detalle)}</p>` : ''}</section>`
    + (o.servicios.length || o.detalles.length || Number(o.mano_obra_centavos) ? `<section class="bloque"><h2>Conceptos registrados a la fecha</h2>${conceptos(o)}${totales(o, false)}</section>` : '')
    + `<section class="bloque"><h2>Autorización del cliente</h2>${autorizado}</section>`
    + `<p class="leyenda">El presupuesto es una estimación. Si durante la reparación se requiere un trabajo o importe mayor, el taller solicitará una nueva autorización antes de realizarlo.</p>`
    + firmas(`Firma de autorización del cliente<small>${esc(o.cliente)}</small>`, `Elaboró por el taller<small>${esc(o.mecanico)}</small>`);
}

function recibo(o) {
  const pagos = o.pagos.filter(p => !p.anulado_en);
  return encabezado(o, 'recibo') + datosGenerales(o)
    + parrafo('Falla reportada', o.falla)
    + parrafo('Diagnóstico', o.diagnostico, 'Sin diagnóstico registrado.')
    + parrafo('Reparaciones realizadas', o.reparaciones, 'Sin reparaciones documentadas.')
    + `<section class="bloque"><h2>Conceptos</h2>${conceptos(o)}${totales(o, true)}</section>`
    + (pagos.length ? `<section class="bloque"><h2>Pagos recibidos</h2><table><thead><tr><th>Fecha</th><th>Método</th><th class="num">Monto</th></tr></thead><tbody>${pagos.map(p => `<tr><td>${fechaHora(p.creado_en)}</td><td>${esc(p.metodo)}</td><td class="num">${dinero(p.monto_centavos)}</td></tr>`).join('')}</tbody></table></section>` : '')
    + `<p class="leyenda">Importes en pesos mexicanos. Este documento es un comprobante interno del taller y no es un comprobante fiscal (CFDI).</p>`
    + firmas(`Recibí el vehículo y el trabajo descrito<small>${esc(o.cliente)}</small>`, `Entregó por el taller<small></small>`);
}

(async () => {
  const p = new URLSearchParams(location.search);
  const id = Number(p.get('id')), tipo = p.get('tipo');
  $('#imprimir').onclick = () => window.print();
  try {
    if (!id || !TITULOS[tipo]) throw new Error('Documento no válido.');
    const res = await fetch(`api.php?r=orden&id=${id}`, { credentials: 'same-origin' });
    const o = await res.json();
    if (!res.ok) throw new Error(res.status === 401 ? 'Inicia sesión en MecaniGest y vuelve a abrir el documento.' : o.error || 'No se pudo cargar la orden.');
    document.title = `${TITULOS[tipo]} ${folio(o.id)} · ${o.cliente}`;
    $('#documento').innerHTML = { recepcion, presupuesto, recibo }[tipo](o);
    $('#estado-impresion').textContent = `${TITULOS[tipo]} · ${folio(o.id)}`;
    document.body.dataset.listo = '1';
  } catch (e) {
    $('#documento').innerHTML = `<p class="error">${esc(e.message)}</p>`;
    $('#estado-impresion').textContent = 'No se pudo preparar el documento';
    $('#imprimir').disabled = true;
  }
})();
