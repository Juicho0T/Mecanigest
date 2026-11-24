# Arquitectura de MecaniGest

Aplicación web local de un taller. PHP 8 y PDO sirven una API JSON; HTML, CSS y JavaScript proporcionan una interfaz responsive sin dependencias. MariaDB/MySQL con InnoDB conserva los registros. La carpeta pública es `public`; configuración, scripts y registros quedan fuera de ella.

## Estructura y relaciones

Usuarios autentican sesiones y registran operaciones. Un cliente tiene vehículos; cada vehículo tiene un propietario actual. Una orden conserva su cliente al momento de recepción, vehículo y mecánico asignado. Una orden tiene detalles de refacciones y pagos. Cada salida enlaza un detalle, una refacción, una orden y un usuario. Auditoría conserva quién realizó las operaciones y cuándo.

## Reglas y flujo

Recibido → En diagnóstico → En reparación → Listo para entregar → Entregado. Solo se avanza un paso; diagnóstico y reparaciones se requieren antes de avanzar. Entregar bloquea cambios técnicos y de precios, pero permite cobrar un saldo posterior. Recepción abre, asigna, entrega y cobra; administración tiene control general; mecánicos trabajan únicamente sus órdenes y no cobran.

Dinero en centavos enteros BIGINT, sin FLOAT ni cálculos decimales binarios. Cada detalle conserva su precio unitario. Total = mano de obra + detalles. Pagado = suma de pagos. Saldo = total menos pagado. No hay impuestos ni cargos automáticos. No se permiten sobrepagos ni reducir el total por debajo de pagos existentes.

Agregar piezas, cobrar y cambiar estados utilizan transacciones y bloqueos `FOR UPDATE`. Entradas, salidas y pagos requieren una clave de operación única para evitar duplicados. El stock se valida bajo bloqueo. Los estados se validan nuevamente en el servidor. No hay rutas de borrado físico ni cancelación de cobros; las correcciones financieras requieren un procedimiento posterior explícito, fuera de esta versión.

## Seguridad

PDO preparado sin emulación, escape DOM, sesiones HttpOnly/SameSite, regeneración al autenticar, caducidad por inactividad, CSRF para escritura e inicio de sesión, permisos en cada operación y consulta, contraseñas hash, limitación de intentos de acceso y errores públicos sin detalles internos. El usuario activo y su rol se consultan en cada petición. No se permite desactivar o degradar al último administrador activo.

## Etapas de verificación

1. Esquema y reglas de dominio en base aislada.
2. Autenticación y API con cookies, permisos y CSRF.
3. Interfaz de los módulos y flujo completo desde navegador.
4. Regresión de integridad, precios, pagos parciales, inventario e historial.
