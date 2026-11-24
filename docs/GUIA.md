# Guía de uso

## Recepción

En Clientes busca por nombre o teléfono. Para registrar un cliente bastan el nombre y el teléfono; correo y dirección son opcionales. En Vehículos selecciona al propietario y registra marca, modelo, año y placas, y el número de serie (VIN) si lo tienes a la mano. Las placas y el VIN son únicos. Un vehículo tiene un propietario actual; las órdenes anteriores conservan al cliente que las abrió. La lista de vehículos muestra el último kilometraje registrado.

Desde Nueva orden selecciona el vehículo, el mecánico y la falla que describe el cliente. Registra también:

- **Kilometraje** del tablero. La lista de vehículos te muestra el último registrado, para detectar errores de captura.
- **Nivel de combustible.**
- **Estado del vehículo al recibirlo**: golpes, rayones, testigos encendidos, objetos de valor o accesorios faltantes.

Al crear la orden se abre su detalle. Con **Agregar foto** puedes tomar fotos con la cámara de la tablet o el celular. La foto se reduce automáticamente antes de enviarse y caben hasta 20 por orden. Después, en **Imprimir o guardar PDF**, abre la **Hoja de recepción** para que el cliente la firme. Si te equivocaste en el kilometraje o las notas, usa **Editar recepción** mientras la orden siga abierta.

Si el vehículo ya tiene una orden activa, el sistema pide continuar con esa orden. El diagnóstico lo registra el mecánico, no recepción.

## Presupuesto y autorización del cliente

Cuando el mecánico registra el diagnóstico y el presupuesto estimado, la orden aparece en el Resumen general como **Esperan autorización**. Comunica el presupuesto al cliente; puedes imprimirlo o guardarlo como PDF para enviarlo por WhatsApp.

Si el cliente acepta, abre la orden y usa **Registrar autorización**. Indica el monto que aceptó, cómo lo autorizó (presencial, teléfono, WhatsApp o correo) y una nota, por ejemplo la hora de la llamada. **Sin autorización el mecánico no puede iniciar la reparación.**

Si durante la reparación el total supera lo autorizado, la orden muestra un aviso y el mecánico no puede marcarla como terminada. Consulta al cliente y registra una **nueva autorización** por el monto actualizado.

Si el cliente no autoriza, usa **Cancelar orden** e indica el motivo. Los cargos ya registrados, por ejemplo una revisión, se conservan y pueden cobrarse; si no se cobrarán, aplica antes un descuento. Una orden cancelada no puede reabrirse, pero el vehículo ya puede recibir una orden nueva.

## Mecánico

Al ingresar verás tus órdenes. Abre una y avanza a En diagnóstico. **Editar trabajo** permite escribir el diagnóstico, el presupuesto estimado con su detalle, la mano de obra adicional y las reparaciones realizadas. Con **Agregar servicio** sumas trabajos del catálogo con precio fijo, por ejemplo una afinación o un cambio de aceite. Puedes quitarlos mientras la orden no esté terminada.

El diagnóstico y la autorización del cliente son obligatorios para avanzar a En reparación. Durante la reparación podrás usar piezas del catálogo activo. El formulario muestra existencias y precio; al confirmar, la operación descuenta unidades y conserva el precio en el detalle.

Documenta lo que efectivamente realizaste antes de marcar Listo para entregar. El sistema no genera diagnósticos ni elige piezas. Al quedar lista, la edición técnica se bloquea para evitar cambios accidentales en el importe presentado.

## Descuentos e IVA

En el resumen de costos de una orden abierta, **Descuento e IVA** permite:

- **Descuento**: indica el monto y el motivo, por ejemplo «Cliente frecuente».
- **Agregar IVA**: márcalo cuando el cliente requiera factura y los precios no incluyan IVA. La tasa se configura con `IVA_TASA` en `.env` (16 % por omisión).

El descuento se resta antes de calcular el IVA. El total nunca puede quedar por debajo de lo ya pagado. Al entregar la orden, estos importes se bloquean. MecaniGest no emite facturas electrónicas: el recibo es un comprobante interno.

## Pagos

Abre la orden y selecciona Registrar pago. También puedes elegir una orden con saldo desde Pagos. El formulario consulta el saldo actual antes de abrirse. Verifica total, pagos previos y saldo, e indica monto y método. Confirma únicamente si el dinero fue recibido. Se admiten pagos parciales, pero no importes cero, negativos o superiores al saldo.

Si un pago se registró por error, por ejemplo un monto mal capturado o un cobro duplicado en la terminal, administración puede usar **Anular** en el historial de pagos de la orden e indicar el motivo. El pago se conserva tachado y marcado como anulado: deja de contar como ingreso y el saldo vuelve a aumentar. En Pagos, el filtro **Anulados** los muestra todos.

Con **Orden y recibo** imprimes el detalle de conceptos, descuento, IVA, pagos vigentes y saldo, con espacio para la firma de conformidad. Sin cargo identifica un total de cero; no se presenta como un pago recibido. Entregar, cobrar y registrar reparaciones son operaciones independientes.

## Imprimir y enviar documentos

En el detalle de la orden, el panel **Imprimir o guardar PDF** abre en una pestaña nueva:

- **Hoja de recepción**: datos del cliente y del vehículo, kilometraje, combustible, falla, estado al recibirlo, fotos y firmas.
- **Presupuesto**: diagnóstico, monto estimado, conceptos registrados y espacio para la firma de autorización.
- **Orden y recibo**: trabajo realizado, conceptos, totales, pagos y saldo.

Para enviarlos por WhatsApp o correo, elige **Guardar como PDF** en el diálogo de impresión. Los datos del encabezado se configuran en `.env`: `TALLER_NOMBRE`, `TALLER_DIRECCION`, `TALLER_TELEFONO` y `TALLER_RFC`.

## Inventario y servicios

Administración registra el catálogo de refacciones con su precio de venta y su **costo de compra**. Una refacción nueva inicia con cero unidades. Usa Entrada para registrar piezas físicamente recibidas e indica el motivo y, si lo conoces, el costo por unidad; ese costo pasa a ser el costo actual de la refacción. Las salidas se producen exclusivamente al utilizar piezas en una orden y guardan el costo de ese momento. Movimientos permite revisar cantidad, costo, usuario, fecha y orden relacionada.

Solo administración ve costos, márgenes y utilidad; recepción y mecánicos ven existencias y precios de venta.

En **Servicios**, administración mantiene el catálogo de trabajos con precio fijo. Cambiar el precio de una refacción o de un servicio no cambia lo cobrado en órdenes anteriores. La indicación Existencias bajas se aplica a refacciones activas con tres unidades o menos; no compra materiales ni inicia pedidos.

## Historial y registros inactivos

Clientes y Vehículos tienen accesos al historial. El historial de un vehículo muestra su último kilometraje y su VIN. Abre cada orden para consultar recepción, fotos, falla, diagnóstico, presupuesto, autorización, servicios, piezas, mano de obra, total, pagos y actividad. Desactivar conserva los antecedentes y evita nuevas operaciones con ese registro. Administración puede reactivarlo posteriormente.

## Reportes y usuarios

Los reportes agrupan cada concepto por su propia fecha:

- **Ingresos**: pagos vigentes, por fecha de pago.
- **Anulaciones**: pagos anulados, por fecha de anulación.
- **Entregas**: órdenes entregadas, con descuentos e IVA, por fecha de entrega.
- **Cancelaciones**: órdenes canceladas, por fecha de cancelación.
- **Servicios y refacciones**: por fecha de uso. Las refacciones incluyen venta, costo y utilidad.

Órdenes pendientes y saldos por cobrar son la situación actual de todos los periodos, indicada explícitamente. Los ingresos solo incluyen dinero registrado como recibido y no anulado.

Usuarios permite crear cuentas, asignar perfil, cambiar datos y activar o desactivar personal. Una contraseña nueva requiere al menos 12 caracteres. Al editar puedes dejarla vacía para conservarla. Cada persona puede cambiar su propia contraseña haciendo clic en su nombre, abajo en el menú lateral (**Mi cuenta**); se pide la contraseña actual. No compartas cuentas entre personas si necesitas distinguir quién realizó cada operación.

## Respaldos

El resumen del administrador avisa si el último respaldo tiene más de dos días. Ejecuta `respaldar.ps1` o prográmalo con el Programador de tareas de Windows, como se describe en el README. Guarda copias fuera de la computadora del taller, por ejemplo en una carpeta de OneDrive o Google Drive configurada en `RESPALDO_COPIA_DIR`.

## Confirmaciones y errores

Antes de entregar, cancelar, desactivar, registrar o anular pagos, registrar entradas o consumo de piezas, revisa el texto de confirmación. Cancelar en un formulario no guarda cambios. Si aparece un error, el formulario conserva los datos para corregirlos. Una sesión sin actividad vence después de 30 minutos. Tras cinco accesos inválidos de la misma identidad desde una dirección, se bloquea ese acceso durante 15 minutos.
