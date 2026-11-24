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

### Instalación sin datos ficticios

Usa una base nueva, configura `APP_DEMO=0` en `.env` y ejecuta el instalador **sin** `--demo`. Solo creará la cuenta de administración; registra al personal desde Usuarios. Nunca reutilices la base de demostración para información del negocio sin una revisión previa. No hay una función de borrado masivo.

## Estructura

| Carpeta | Responsabilidad |
| --- | --- |
| `public` | HTML, CSS, JavaScript y entrada de la API |
| `app` | Conexión, sesiones, validaciones, permisos y reglas del taller |
| `database` | Esquema SQL con relaciones, restricciones e índices |
| `scripts` | Instalación y generación controlada de datos ficticios |
| `tests` | Pruebas de dominio y solicitudes HTTP |
| `docs` | Arquitectura, guía de uso y verificación |
| `storage` | Credenciales iniciales, sesiones y registros privados |

## Perfiles

| Función | Administrador | Recepcionista | Mecánico |
| --- | --- | --- | --- |
| Clientes y vehículos | Registrar, editar, inactivar | Registrar y editar | Consultar los relacionados con sus órdenes |
| Abrir y asignar órdenes | Sí | Sí | No |
| Diagnóstico, reparación, piezas | Sí | No | Solo sus órdenes |
| Avance técnico | Sí | No | Solo sus órdenes |
| Entregar y cobrar | Sí | Sí | No |
| Inventario | Catálogo, entradas, movimientos | Consulta | Consulta y uso en órdenes propias |
| Usuarios y reportes | Sí | No | No |

El administrador no puede desactivar su propia sesión ni degradar su propio perfil. Se protege al último administrador activo. Un mecánico con órdenes activas debe ser sustituido en esas órdenes antes de desactivarlo o cambiar su perfil.

## Módulos implementados

Panel con datos de la base; autenticación; gestión de clientes y vehículos; búsqueda y filtros; historial; órdenes y asignación; diagnóstico y reparación; mano de obra; inventario; precios históricos; entradas y salidas auditadas; pagos parciales; saldos independientes de la entrega; reportes por periodo; usuarios y permisos; confirmaciones; mensajes de error; diseño adaptable a computadora y tablet.

## Flujo principal

1. Busca o registra al cliente.
2. Registra su vehículo y abre una orden con falla reportada y mecánico asignado.
3. El mecánico cambia a **En diagnóstico** y registra su diagnóstico.
4. Cambia a **En reparación**, documenta el trabajo, la mano de obra y las piezas utilizadas.
5. Marca **Listo para entregar** cuando el trabajo esté documentado.
6. Recepción confirma la entrega. La orden queda en **Entregado** y conserva sus importes.
7. Registra pagos parciales o completos cuando el dinero se reciba, antes o después de la entrega.

Una orden entregada puede seguir pendiente de pago. Total = mano de obra + refacciones. Pagado = suma de pagos; saldo = total menos pagado. Los valores se almacenan en centavos enteros y se muestran en MXN. No se generan cargos ni impuestos adicionales.

## Pruebas

```powershell
& 'C:\xampp\php\php.exe' 'tests\dominio.php'
node 'tests\http.cjs'
```

La primera prueba crea una base efímera con prefijo `mecanigest_test_` y elimina únicamente esa base al finalizar. La prueba HTTP requiere el servidor iniciado, Node 20 o superior con `fetch` y las tres cuentas de demostración; no altera órdenes ni inventario. Puede configurarse otra URL mediante `MECANIGEST_URL`. Los intentos inválidos usan una identidad ficticia específica.

Consulta `docs/VERIFICACION.md` para los resultados y `docs/GUIA.md` para el uso de los módulos.

## Operación y límites

Esta entrega está preparada para **uso local en XAMPP**. El servidor incorporado de PHP se utiliza para desarrollo y pruebas. La publicación en Internet requerirá revisar HTTPS, configuración del servidor, copias de seguridad, permisos del usuario de base de datos y requisitos de operación del taller.

El inventario digital refleja los movimientos registrados y requiere conteo físico. Los pagos son un registro interno; el método Transferencia o Tarjeta no ejecuta una transacción bancaria. Los reportes no reemplazan la contabilidad.

No hay borrado de órdenes o pagos, devoluciones automáticas ni reaperturas de órdenes entregadas. Si se registra un cobro o consumo equivocado, conserva el registro y solicita revisión administrativa; no modifiques la base manualmente. Las anulaciones con contraasientos y justificación podrán diseñarse en una versión posterior. Los listados consultan el conjunto del taller y paginan en la interfaz; para volúmenes grandes se necesitará paginación y búsqueda en servidor.

## Fuera del alcance y ampliaciones

No se implementan aplicación móvil independiente, citas en línea, WhatsApp, SMS, facturación electrónica, cobros bancarios, múltiples sucursales, contabilidad completa ni diagnósticos automáticos. Podrán evaluarse posteriormente, como proyectos separados y con reglas explícitas.

Conserva copias periódicas de la base mediante las herramientas de MySQL/phpMyAdmin. Respalda la configuración de forma privada. No incluyas credenciales ni registros reales en repositorios o entregas públicas.
