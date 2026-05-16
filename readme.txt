=== Welow RRHH ===
Contributors: trispania
Tags: hr, vacaciones, fichajes, time-tracking, empleados, rrhh, recursos-humanos
Requires at least: 6.4
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin white-label para centralizar procesos de RRHH en PYMES: empleados, fichajes (control horario RDL 8/2019) y vacaciones.

== Description ==

**Welow RRHH** es un plugin de WordPress white-label pensado para que pequeñas y medianas empresas gestionen sus procesos de RRHH desde el propio WP, sin servicios externos.

= Núcleo =

* Empleados, departamentos y festivos (importación CSV).
* Settings centralizado (Empresa, Calendario, Vacaciones, Fichajes, Notificaciones).
* Wizard de configuración inicial.
* Dashboard frontend con shortcode `[welow_rrhh_dashboard]` y sistema de tabs extensible vía filtro.
* REST API propia (`/wp-json/welow-rrhh/v1/`).
* Auditoría de acciones críticas (welow_audit_log).
* Sistema de notificaciones con canal email y soporte para añadir más canales.
* Cifrado AES-256-GCM de DNI/NIE en base de datos.

= Módulo Fichajes (control horario RDL 8/2019) =

* Registro de entrada/salida/pausa desde el dashboard frontend.
* Restricciones opcionales por geolocalización (radio Haversine alrededor de oficinas) y/o IP (allowlist CIDR IPv4).
* Cierre de mes auditado: una vez cerrado el periodo, los registros quedan en sólo lectura (sólo admins con cap pueden editar con motivo extendido).
* Exportación PDF/CSV del "Registro horario" mensual por empleado conforme al RDL 8/2019.
* Rate limit configurable sobre el endpoint REST de fichaje.

= Módulo Vacaciones =

* Solicitudes con medio día opcional, validación de saldo, deadline, solapes y máximos consecutivos.
* Pantalla "Años de vacaciones" donde HR define, año a año: apertura, fecha límite de solicitud, días anuales, política de arrastre (carry-over) y fecha límite para disfrutar los días arrastrados.
* Devengo anual con tres modos configurables: año completo, prorrateado por meses trabajados u override por empleado.
* Flujo de aprobación con notificación al solicitante.
* Tabs frontend: Mis vacaciones (saldo + form + listado), Aprobaciones equipo, Calendario equipo.
* Endpoint REST `/vacations/{requests,balance}` con rate limit.

= Branding white-label =

El plugin respeta tres constantes definibles en `wp-config.php`:

* `WELOW_RRHH_BRAND_NAME` — nombre comercial visible en emails y dashboard.
* `WELOW_RRHH_BRAND_LOGO_URL` — URL del logo para los emails.
* `WELOW_RRHH_BRAND_PRIMARY_COLOR` — color primario.

= Extensibilidad =

Todos los puntos de inserción (REST, dashboard, exportadores, notificaciones, validaciones) están abiertos vía actions y filtros documentados en [HOOKS.md](https://github.com/trispania-git/welow-rrhh/blob/main/HOOKS.md).

== Installation ==

1. Sube la carpeta `welow-rrhh` a `/wp-content/plugins/` (o instala el ZIP desde el admin).
2. Ejecuta `composer install --no-dev` dentro de la carpeta del plugin para generar el autoload.
3. Activa el plugin en "Plugins".
4. Sigue el wizard de configuración (Empresa → Calendario → Vacaciones → Fichajes → Notificaciones).
5. Activa los módulos que necesites desde el submenú "Módulos".

= Requisitos =

* PHP 8.1 o superior con extensiones `json`, `mbstring`, `openssl`.
* WordPress 6.4 o superior.
* MySQL 5.7+ o MariaDB 10.3+ con soporte `utf8mb4`.

== Frequently Asked Questions ==

= ¿Cómo configuro las restricciones de geolocalización para los fichajes? =

En "Ajustes → Fichajes" activa "Requerir geolocalización" y añade una o más oficinas con su latitud, longitud y radio en metros. El navegador del empleado pedirá permiso para acceder a la ubicación. Si la posición está fuera del radio de cualquier oficina configurada, el fichaje se rechaza y queda auditado.

= ¿Y si un empleado trabaja en remoto puntualmente? =

Cada ficha de empleado admite un override de política. Puedes ponerle "Relajada" (sin geo/IP) o una política custom con sus propias oficinas/IPs.

= ¿Cómo modelo la política "los días no usados se pierden el 31 de marzo del año siguiente"? =

Entra en "Welow RRHH → Años de vacaciones", crea o edita el año en cuestión, marca "Permitir arrastre al año siguiente" y fija "Caducidad de los días arrastrados" en `31/03/AAAA+1`. El cálculo del saldo disponible respetará esa fecha y excluirá los arrastrados pasada la caducidad.

= ¿Cómo desactivo un año para que nadie pueda solicitar vacaciones? =

Edita el año en "Años de vacaciones" y desmarca "Abierto a solicitudes". Las solicitudes nuevas para ese año se rechazarán con el código `welow_vacation_year_closed`. Las ya aprobadas se mantienen y se disfrutan.

= ¿El exportador de fichajes requiere dompdf? =

Si el plugin detecta `\Dompdf\Dompdf` autoloadable, el botón "Exportar mes (PDF)" genera el PDF. Si no, devuelve un CSV equivalente sin perder información. El botón CSV está disponible siempre.

= ¿Puedo extender el plugin con un módulo propio? =

Sí. Crea `wp-content/plugins/welow-rrhh-mi-modulo/Module.php` que extienda `Welow\RRHH\Modules\AbstractModule`, o engánchate al filtro `welow_rrhh/modules` para registrar tu instancia desde fuera. Los puntos de extensión están en `HOOKS.md`.

= ¿Cómo cambio el remitente de los emails? =

En "Ajustes → Notificaciones" puedes definir `email_from_name` y `email_from_address`. Si los dejas vacíos se usa el remitente de WordPress.

== Screenshots ==

1. Dashboard frontend con tab "Fichar".
2. Tab "Mis vacaciones" con saldo y formulario.
3. Pantalla admin "Años de vacaciones".
4. Cierre de mes y exportación PDF del Registro horario.

== Changelog ==

= 0.1.0 — 2026-05 =

* Versión inicial.
* Núcleo: Empleados, Departamentos, Festivos, Settings, Wizard, REST API, Dashboard frontend, Sistema de notificaciones, Auditoría, Cifrado AES-256-GCM.
* Módulo Fichajes completo (registro, geo/IP, cierre de mes, exportación PDF/CSV).
* Módulo Vacaciones completo (saldo, solicitudes con medio día, flujo de aprobación, configuración de años con carry-over y caducidad).

== Upgrade Notice ==

= 0.1.0 =

Primera versión pública.
