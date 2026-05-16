# Hooks de Welow RRHH

Referencia completa de los **actions** y **filtros** públicos del plugin. Todos los hooks usan el prefijo `welow_rrhh/` con barras como separador semántico.

> Convención: los hooks marcados como **filtro** esperan que devuelvas el primer argumento (o un `WP_Error` cuando aplique). Los **actions** son notificaciones; no necesitan devolver nada.

---

## Núcleo

### `welow_rrhh/booted` — action
Disparado al final de `Plugin::boot()`, cuando todo el núcleo está arrancado y los módulos han hecho `boot()`.

| Parámetro | Tipo | Descripción |
|---|---|---|
| `$plugin` | `Welow\RRHH\Plugin` | Instancia del plugin. |

```php
add_action( 'welow_rrhh/booted', function ( $plugin ) {
    // $plugin->container()->get( '...' );
} );
```

### `welow_rrhh/modules` — filtro
Permite a integradores registrar **módulos adicionales** descubiertos fuera del directorio `/modules/` (por ejemplo, en otro plugin).

| Parámetro | Tipo | Descripción |
|---|---|---|
| `$modules` | `array<string, ModuleInterface>` | Módulos descubiertos, indexados por slug. |
| `$registry` | `ModuleRegistry` | Registro completo, por si necesitas inspeccionar. |

Devuelve un array `string => ModuleInterface` con tu módulo añadido.

### `welow_rrhh/module_activated` / `welow_rrhh/module_deactivated` / `welow_rrhh/module_booted` — actions
Disparados al activar/desactivar/arrancar un módulo.

| Parámetro | Tipo | Descripción |
|---|---|---|
| `$slug` | `string` | Slug del módulo (e.g. `time-tracking`, `vacations`). |
| `$module` *(sólo `_booted`)* | `ModuleInterface` | Instancia. |

### `welow_rrhh/rest/controllers` — filtro
Permite añadir controladores REST al namespace `welow-rrhh/v1`. Cada elemento debe extender `Welow\RRHH\REST\AbstractController` e implementar `register_routes()`.

| Parámetro | Tipo |
|---|---|
| `$controllers` | `AbstractController[]` |

### `welow_rrhh/dashboard/tabs` — filtro
Filtra los tabs visibles en el shortcode `[welow_rrhh_dashboard]`. Cada tab debe implementar `Welow\RRHH\Frontend\Tabs\TabInterface`.

| Parámetro | Tipo | Descripción |
|---|---|---|
| `$tabs` | `TabInterface[]` | Tabs base + los añadidos por módulos. |
| `$user` | `\WP_User` | Usuario actual (útil para añadir tabs condicionales). |

```php
add_filter( 'welow_rrhh/dashboard/tabs', function ( array $tabs, \WP_User $user ): array {
    $tabs[] = new \Mi\Plugin\Frontend\AvisosTab();
    return $tabs;
}, 10, 2 );
```

---

## Notificaciones

### `welow_rrhh/notifications/channels` — filtro
Lista de canales disponibles para el `Dispatcher`. Por defecto incluye `email`. Cada canal debe implementar `Welow\RRHH\Notifications\Channels\ChannelInterface`.

| Parámetro | Tipo |
|---|---|
| `$channels` | `ChannelInterface[]` |
| `$user_id` | `int` |
| `$type` | `string` |
| `$payload` | `array<string, mixed>` |

### `welow_rrhh/notifications/email_template` — filtro
Override del render final de un email (tras combinar subject + html + text + layout).

| Parámetro | Tipo |
|---|---|
| `$result` | `array{subject:string, html:string, text:string}` |
| `$type` | `string` |
| `$vars` | `array<string, mixed>` |

### `welow_rrhh/notifications/sent` — action
Auditoría: invocado tras intentar enviar a todos los canales.

| Parámetro | Tipo |
|---|---|
| `$user_id` | `int` |
| `$type` | `string` |
| `$payload` | `array` |
| `$results` | `array<string, bool>` |

---

## Exportadores

### `welow_rrhh/exporters/sources` — filtro
Fuentes de datos disponibles. Cada fuente describe qué exporta (slug + cabeceras + filas).

| Parámetro | Tipo |
|---|---|
| `$sources` | `array<string, SourceInterface>` |

### `welow_rrhh/exporters/drivers` — filtro
Drivers de formato (`csv`, `pdf`, ...). Cada driver convierte filas en bytes.

| Parámetro | Tipo |
|---|---|
| `$drivers` | `array<string, DriverInterface>` |

---

## Módulo Fichajes

### `welow_rrhh/time_tracking/booted` — action
Disparado tras `Module::boot()` del módulo.

### `welow_rrhh/time_tracking/can_punch` — filtro
Veto previo a registrar un fichaje. Devuelve `true` para permitir o un `WP_Error` para denegar (el código del error se devuelve al cliente REST).

| Parámetro | Tipo |
|---|---|
| `$allowed` | `true\|\WP_Error` |
| `$user_id` | `int` |
| `$event_type` | `EventType` |
| `$context` | `array<string,mixed>` |

Usado internamente por `PunchGuard` (geo + IP) y por `ClosureGuard` (mes cerrado).

### `welow_rrhh/time_tracking/can_edit_entry` — filtro
Veto previo a editar un fichaje existente.

| Parámetro | Tipo |
|---|---|
| `$allowed` | `true\|\WP_Error` |
| `$entry` | `TimeEntry` (actual antes de la edición) |
| `$editor_id` | `int` |
| `$reason` | `string` |

### `welow_rrhh/time_tracking/can_delete_entry` — filtro
Veto previo a borrar un fichaje.

| Parámetro | Tipo |
|---|---|
| `$allowed` | `true\|\WP_Error` |
| `$entry` | `TimeEntry` |
| `$actor_id` | `int` |

### `welow_rrhh/punch_created` — action
Tras registrar un evento correctamente.

| Parámetro | Tipo |
|---|---|
| `$id` | `int` (id del evento creado) |
| `$user_id` | `int` |
| `$event_type` | `EventType` |

### `welow_rrhh/month_closed` — action
Tras cerrar un mes con éxito.

| Parámetro | Tipo |
|---|---|
| `$year` | `int` |
| `$month` | `int` |
| `$closed_by` | `int` (user id) |

---

## Módulo Vacaciones

### `welow_rrhh/vacations/booted` — action
Tras `Module::boot()` del módulo. Recibe el `Container` por conveniencia.

### `welow_rrhh/vacations/can_request` — filtro
Veto previo a crear una solicitud. Devuelve `true` o un `WP_Error` para denegar.

| Parámetro | Tipo |
|---|---|
| `$allowed` | `true\|\WP_Error` |
| `$user_id` | `int` |
| `$type` | `RequestType` |
| `$start` | `\DateTimeImmutable` |
| `$end` | `\DateTimeImmutable` |
| `$requested_days` | `float` |
| `$context` | `array<string,mixed>` (`reason`, `start_half_day`, `end_half_day`, ...) |

### `welow_rrhh/vacation_request_created` — action
Tras crear una solicitud (status `PENDING`).

| Parámetro | Tipo |
|---|---|
| `$id` | `int` |
| `$user_id` | `int` |
| `$request` | `VacationRequest` |

### `welow_rrhh/vacation_request_approved` — action
Tras aprobar.

| Parámetro | Tipo |
|---|---|
| `$id` | `int` |
| `$approver_id` | `int` |
| `$request` | `VacationRequest\|null` |

### `welow_rrhh/vacation_request_rejected` — action
Tras rechazar.

| Parámetro | Tipo |
|---|---|
| `$id` | `int` |
| `$approver_id` | `int` |
| `$request` | `VacationRequest\|null` |

### `welow_rrhh/vacation_request_cancelled` — action
Tras cancelar (por el solicitante o por HR).

| Parámetro | Tipo |
|---|---|
| `$id` | `int` |
| `$actor_id` | `int` |

---

## Patrón "permission gate" con WP_Error

Los filtros marcados como **veto** (`can_punch`, `can_edit_entry`, `can_delete_entry`, `can_request`) siguen un patrón uniforme:

1. El valor por defecto es `true` (acción permitida).
2. Para denegar, devuelve un `\WP_Error` con un código `welow_*_*` legible.
3. El código se propaga: al REST como cuerpo `{ok:false, error:{code, message}}`, al admin como notice y al frontend como mensaje en el formulario.

```php
add_filter( 'welow_rrhh/time_tracking/can_punch', function ( $allowed, $user_id, $event_type, $context ) {
    if ( welow_es_festivo_corporativo() ) {
        return new \WP_Error( 'mi_plugin_dia_no_laborable', __( 'Hoy es festivo de empresa.', 'mi-textdomain' ) );
    }
    return $allowed;
}, 10, 4 );
```

---

## Convenciones para añadir tu propio módulo

* Crea `wp-content/plugins/<tu-plugin>/Module.php` con una clase que extienda `Welow\RRHH\Modules\AbstractModule`.
* Registra el módulo en el filtro `welow_rrhh/modules` o colócalo en `/modules/<slug>/Module.php` dentro del propio Welow RRHH.
* Declara `dependencies()` si tu módulo necesita otros activos previamente (el orden de boot es topológico).
* Usa el `Container` del Core (`welow_rrhh()->container()`) para inyectar repos y servicios.
* Si añades hooks propios, prefíjalos con tu namespace (no con `welow_rrhh/`).
