# Arquitectura de MecaniGest

Aplicación web local de un taller. PHP 8 y PDO sirven una API JSON; HTML, CSS y JavaScript proporcionan una interfaz responsive sin dependencias. MariaDB/MySQL con InnoDB conserva los registros. La carpeta pública es `public`; configuración, scripts y registros quedan fuera de ella.

## Estructura y relaciones

Usuarios autentican sesiones y registran operaciones. Un cliente tiene vehículos; cada vehículo tiene un propietario actual. Una orden conserva su cliente al momento de recepción, vehículo y mecánico asignado. Una orden tiene datos de recepción (kilometraje, combustible, notas), fotos, presupuesto y autorización, servicios del catálogo, detalles de refacciones y pagos. Cada salida enlaza un detalle, una refacción, una orden y un usuario. Las fotos se guardan en `storage/fotos`, fuera de la carpeta pública, y solo se sirven por la API a quien puede ver la orden. Auditoría conserva quién realizó las operaciones y cuándo.

## Reglas y flujo

Recibido → En diagnóstico → En reparación → Listo para entregar → Entregado. Solo se avanza un paso. Para iniciar la reparación se requieren el diagnóstico y la autorización del cliente; para terminarla, las reparaciones documentadas y un total que no supere lo autorizado. Cancelada es un cierre alternativo desde cualquier estado abierto, con motivo; conserva cargos y pagos. Entregar bloquea cambios técnicos y de precios, pero permite cobrar un saldo posterior. Recepción abre, asigna, entrega y cobra; administración tiene control general; mecánicos trabajan únicamente sus órdenes y no cobran.

Dinero en centavos enteros BIGINT, sin FLOAT ni cálculos decimales binarios. Cada detalle conserva su precio unitario. Subtotal = mano de obra + servicios + refacciones. Total = (subtotal − descuento) + IVA, con la tasa guardada en la orden en centésimas de punto y redondeo al centavo. Pagado = suma de pagos no anulados. Saldo = total menos pagado. El IVA solo se aplica a las órdenes marcadas. Cada detalle de refacción guarda también su costo, visible solo para administración. No se permiten sobrepagos, descuentos mayores al subtotal ni reducir el total por debajo de pagos existentes.

Agregar piezas, cobrar y cambiar estados utilizan transacciones y bloqueos `FOR UPDATE`. Entradas, salidas y pagos requieren una clave de operación única para evitar duplicados. El stock se valida bajo bloqueo. Los estados se validan nuevamente en el servidor. No hay rutas de borrado físico de órdenes ni de pagos. Un pago se anula con motivo, usuario y fecha, y se conserva. Solo las líneas de servicio pueden quitarse mientras la orden no está terminada, con registro en la actividad.

## Seguridad

PDO preparado sin emulación, escape DOM, sesiones HttpOnly/SameSite, regeneración al autenticar, caducidad por inactividad, CSRF para escritura e inicio de sesión, permisos en cada operación y consulta, contraseñas hash, limitación de intentos de acceso y errores públicos sin detalles internos. El usuario activo y su rol se consultan en cada petición. No se permite desactivar o degradar al último administrador activo.

## Etapas de verificación

1. Esquema y reglas de dominio en base aislada.
2. Autenticación y API con cookies, permisos y CSRF.
3. Interfaz de los módulos y flujo completo desde navegador.
4. Regresión de integridad, precios, pagos parciales, inventario e historial.

## Migraciones y respaldos

`database/schema.sql` es el esquema base. Los cambios posteriores están en `database/migraciones/NNN_nombre.sql` y se aplican en orden; la tabla `migraciones` registra las aplicadas. `scripts/instalar.php` genera un respaldo antes de migrar una base con datos. `app/Mantenimiento.php` produce respaldos SQL comprimidos con una lectura consistente (`START TRANSACTION WITH CONSISTENT SNAPSHOT`), una sentencia por línea, importables con phpMyAdmin o con `scripts/restaurar.php`, que solo restaura en una base nueva.
