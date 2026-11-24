# MecaniGest

Aplicación web para la administración interna de un taller mecánico pequeño o mediano. Centraliza clientes, vehículos, órdenes de servicio, diagnósticos, reparaciones, refacciones, inventario y pagos. Conserva el historial y diferencia los ingresos recibidos de los saldos pendientes.

## Tecnologías y requisitos

- PHP 8.0 o superior con PDO MySQL, mbstring y sesiones.
- MariaDB 10.4 o MySQL 8.0.16 o superior, motor InnoDB.
- XAMPP en Windows; navegador moderno.
- HTML5, CSS3 y JavaScript sin bibliotecas externas ni proceso de compilación.

## Abrir esta instalación

La página inicial presenta MecaniGest con una estética oscura en tonos café y crema. El botón **Entrar al taller** abre `taller.html`, donde se inicia sesión y se utilizan los módulos existentes. La portada no necesita base de datos; sus ejemplos están identificados como ilustrativos. El panel conserva la API PHP y requiere MySQL activo.

La presentación utiliza `public/assets/editorial.css`, `editorial.js` y la ilustración vectorial local `rotor.svg`. El panel aplica `taller-dark.css` sobre sus estilos de estructura. No requiere descargar fuentes ni instalar dependencias de interfaz.

La instalación de demostración utiliza MySQL de XAMPP en el puerto **3308** y la base **mecanigest**. La dirección de desarrollo es **http://127.0.0.1:8097** mientras el servidor esté iniciado.

Ejecuta `iniciar.ps1` desde PowerShell para volver a iniciar el servidor local. El script comprueba MySQL y mantiene la ventana del servidor disponible. No configura servicios públicos.

Las cuentas son `admin@mecanigest.local`, `recepcion@mecanigest.local` y `mecanico@mecanigest.local`. Cada contraseña se genera aleatoriamente durante la instalación y se guarda únicamente en el archivo local **storage/accesos-locales.txt**, fuera de la carpeta pública. No existe una contraseña universal en el código. No publiques ese archivo.

## Instalación en otra computadora con XAMPP

1. Copia el proyecto a `C:\xampp\htdocs\MecaniGest` o a otra carpeta elegida. Conserva su estructura.
2. Inicia MySQL y Apache desde el panel de XAMPP.
3. Copia `.env.example` a `.env`. Define `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASSWORD`. El puerto usual es 3306; esta computadora utiliza 3308. La contraseña de MySQL debe permanecer en `.env`, nunca en archivos PHP.
4. Abre una consola dentro del proyecto y ejecuta:

   ```powershell
   & 'C:\xampp\php\php.exe' 'scripts\instalar.php' --demo
   ```

   Esto crea la base si no existe, ejecuta `database/schema.sql` y genera cuentas y registros ficticios. No borra datos existentes. Vuelve a ejecutarlo solo para completar una instalación, no para reiniciar una base.
5. Abre `http://localhost/MecaniGest/public/` si copiaste el proyecto dentro de `htdocs`. Los archivos `.htaccess` niegan el acceso al directorio raíz y permiten únicamente `public`. Apache necesita `AllowOverride All` para respetarlos. También puedes configurar un VirtualHost cuyo `DocumentRoot` sea directamente `public`.
6. Como alternativa, inicia el servidor de desarrollo desde la carpeta del proyecto:

   ```powershell
   & 'C:\xampp\php\php.exe' -S 127.0.0.1:8097 -t public
   ```

7. Inicia sesión con una cuenta del archivo privado `storage/accesos-locales.txt`.

### Actualizar una instalación existente

Después de descargar una versión nueva, conserva tu `.env` y la carpeta `storage`, inicia MySQL y ejecuta el instalador **sin** `--demo`:

```powershell
& 'C:\xampp\php\php.exe' 'scripts\instalar.php'
```

El instalador detecta las actualizaciones pendientes de la base (carpeta `database/migraciones`), genera primero un respaldo en `storage/respaldos` y después aplica los cambios. Solo agrega tablas y columnas; no borra información. Puedes ejecutarlo varias veces sin riesgo.

### Instalación sin datos ficticios

Usa una base nueva, configura `APP_DEMO=0` en `.env` y ejecuta el instalador **sin** `--demo`. Solo creará la cuenta de administración; registra al personal desde Usuarios. Nunca reutilices la base de demostración para información del negocio sin una revisión previa. No hay una función de borrado masivo.

## Estructura

| Carpeta | Responsabilidad |
| --- | --- |
| `public` | HTML, CSS, JavaScript y entrada de la API |
| `app` | Conexión, sesiones, validaciones, permisos y reglas del taller |
| `database` | Esquema SQL base y migraciones con relaciones, restricciones e índices |
| `scripts` | Instalación y actualización, respaldos, restauración y datos ficticios |
| `tests` | Pruebas de dominio y solicitudes HTTP |
| `docs` | Arquitectura, guía de uso y verificación |
| `storage` | Credenciales iniciales, sesiones, fotos de órdenes, respaldos y registros privados |

## Perfiles

| Función | Administrador | Recepcionista | Mecánico |
| --- | --- | --- | --- |
| Clientes y vehículos | Registrar, editar, inactivar | Registrar y editar | Consultar los relacionados con sus órdenes |
| Abrir, asignar y cancelar órdenes; datos de recepción | Sí | Sí | No |
| Fotos del vehículo | Sí | Sí | Solo sus órdenes |
| Diagnóstico, presupuesto, reparación, piezas | Sí | No | Solo sus órdenes |
| Agregar servicios del catálogo a una orden | Sí | Sí | Solo sus órdenes |
| Registrar la autorización del cliente | Sí | Sí | No |
| Avance técnico | Sí | No | Solo sus órdenes |
| Descuento e IVA, entregar y cobrar | Sí | Sí | No |
| Anular pagos | Sí | No | No |
| Imprimir hoja de recepción, presupuesto y recibo | Sí | Sí | No |
| Catálogo de servicios | Catálogo y precios | Consulta | Consulta |
| Inventario | Catálogo, costos, entradas, movimientos | Consulta sin costos | Consulta sin costos y uso en órdenes propias |
| Usuarios y reportes | Sí | No | No |
| Cambiar su propia contraseña | Sí | Sí | Sí |

El administrador no puede desactivar su propia sesión ni degradar su propio perfil. Se protege al último administrador activo. Un mecánico con órdenes activas debe ser sustituido en esas órdenes antes de desactivarlo o cambiar su perfil.

## Módulos implementados

Panel con datos de la base; autenticación y cambio de contraseña propia; gestión de clientes y vehículos con VIN y último kilometraje; búsqueda y filtros; historial; órdenes y asignación; recepción del vehículo con kilometraje, combustible, notas y fotos; diagnóstico, presupuesto y autorización del cliente; reparación; catálogo de servicios con precio; mano de obra; inventario con costo de compra y margen; precios y costos históricos; entradas y salidas auditadas; descuentos e IVA; pagos parciales y anulación de pagos con motivo; cancelación de órdenes; saldos independientes de la entrega; impresión o PDF de hoja de recepción, presupuesto y recibo; reportes por periodo con utilidad, anulaciones y cancelaciones; respaldos automáticos; usuarios y permisos; confirmaciones; mensajes de error; diseño adaptable a computadora, tablet y teléfono.

## Flujo principal

1. Busca o registra al cliente.
2. Registra su vehículo y abre una orden con falla reportada, mecánico asignado, kilometraje, combustible y estado del vehículo. Agrega fotos e imprime la **hoja de recepción** para que el cliente la firme.
3. El mecánico cambia a **En diagnóstico**, registra el diagnóstico, el presupuesto estimado y los servicios del catálogo.
4. Recepción informa el presupuesto (puede imprimirlo) y registra la **autorización del cliente**: monto y medio (presencial, teléfono, WhatsApp o correo). Sin autorización no se puede iniciar la reparación. Si el cliente no autoriza, se **cancela la orden** con su motivo.
5. El mecánico cambia a **En reparación**, documenta el trabajo, la mano de obra y las piezas utilizadas.
6. Marca **Listo para entregar** cuando el trabajo esté documentado. Si el total supera lo autorizado, el sistema pide una nueva autorización antes de terminar.
7. Recepción aplica descuento o IVA si corresponde y confirma la entrega. La orden queda en **Entregado** y conserva sus importes.
8. Registra pagos parciales o completos cuando el dinero se reciba, antes o después de la entrega, e imprime la **orden y recibo**. Si un pago se capturó por error, administración lo anula indicando el motivo.

Una orden entregada o cancelada puede seguir pendiente de pago. Subtotal = mano de obra + servicios + refacciones. Total = (subtotal − descuento) + IVA, si se marcó. Pagado = suma de pagos no anulados; saldo = total menos pagado. Los valores se almacenan en centavos enteros y se muestran en MXN. El IVA solo se agrega en las órdenes donde se indique.

## Pruebas

```powershell
& 'C:\xampp\php\php.exe' 'tests\dominio.php'
node 'tests\http.cjs'
```

La primera prueba crea una base efímera con prefijo `mecanigest_test_` y elimina únicamente esa base al finalizar. La prueba HTTP requiere el servidor iniciado, Node 20 o superior con `fetch` y las tres cuentas de demostración; no altera órdenes ni inventario. Puede configurarse otra URL mediante `MECANIGEST_URL`. Los intentos inválidos usan una identidad ficticia específica.

La prueba de dominio también comprueba las migraciones y que un respaldo se restaure con los mismos datos. Consulta `docs/GUIA.md` para el uso de los módulos.

## Respaldos

```powershell
.\respaldar.ps1
```

Genera `storage/respaldos/mecanigest-AAAA-MM-DD-HHMMSS.sql.gz` y conserva los 30 más recientes (`RESPALDOS_CONSERVAR`). Si defines `RESPALDO_COPIA_DIR` en `.env`, por ejemplo una carpeta de OneDrive o Google Drive, también guarda una copia allí. El resumen del administrador avisa cuando el último respaldo tiene más de dos días.

Para hacerlo diario a las 20:10, ejecuta una vez en PowerShell, ajustando la ruta:

```powershell
schtasks /Create /SC DAILY /ST 20:10 /TN "MecaniGest respaldo" /TR "powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\MecaniGest\respaldar.ps1"
```

La computadora debe estar encendida y MySQL activo a esa hora. Para restaurar, usa siempre una base **nueva**; el script nunca sobrescribe la base en uso:

```powershell
& 'C:\xampp\php\php.exe' 'scripts\restaurar.php' 'storage\respaldos\mecanigest-AAAA-MM-DD-HHMMSS.sql.gz' mecanigest_restaurada
```

Revisa la información y, si es correcta, cambia `DB_NAME` en `.env`. El archivo también puede importarse desde phpMyAdmin. Las fotos de las órdenes están en `storage/fotos`; inclúyelas en tus copias de la carpeta del proyecto.

## Operación y límites

Esta entrega está preparada para **uso local en XAMPP**. El servidor incorporado de PHP se utiliza para desarrollo y pruebas. La publicación en Internet requerirá revisar HTTPS, configuración del servidor, copias de seguridad, permisos del usuario de base de datos y requisitos de operación del taller.

El inventario digital refleja los movimientos registrados y requiere conteo físico. Los pagos son un registro interno; el método Transferencia o Tarjeta no ejecuta una transacción bancaria. Los reportes no reemplazan la contabilidad.

No hay borrado de órdenes o pagos, devoluciones automáticas de piezas ni reaperturas de órdenes entregadas o canceladas. Un pago equivocado se **anula** con motivo y queda en el historial. Una refacción usada por error no regresa sola al inventario: regístrala como entrada. Los servicios del catálogo pueden quitarse de una orden mientras no esté terminada. No modifiques la base manualmente. Los listados consultan el conjunto del taller y paginan en la interfaz; para volúmenes grandes se necesitará paginación y búsqueda en servidor.

## Fuera del alcance y ampliaciones

No se implementan aplicación móvil independiente, citas en línea, avisos automáticos por WhatsApp o SMS, facturación electrónica (los recibos no son CFDI), cobros bancarios, múltiples sucursales, contabilidad completa ni diagnósticos automáticos. Podrán evaluarse posteriormente, como proyectos separados y con reglas explícitas.

Programa los respaldos diarios descritos arriba y guarda copias fuera de la computadora del taller. Respalda la configuración de forma privada. No incluyas credenciales ni registros reales en repositorios o entregas públicas.
