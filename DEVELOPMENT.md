# Guía de desarrollo — Welow RRHH

## Requisitos

* PHP 8.1+ con extensiones `json`, `mbstring`, `openssl`.
* WordPress 6.4+ (instalación local recomendada con Laragon, LocalWP o Docker).
* MySQL 5.7+ o MariaDB 10.3+.
* [Composer](https://getcomposer.org/).
* Opcional: [wp-cli](https://wp-cli.org/) para tests funcionales rápidos vía `eval-file`.

## Arranque local

```bash
# 1. Clona el repo dentro de wp-content/plugins/ de tu WP local.
cd wp-content/plugins/
git clone https://github.com/trispania-git/welow-rrhh.git
cd welow-rrhh

# 2. Instala dependencias (incluyendo dev: PHPCS + PHPUnit).
composer install

# 3. Activa el plugin desde wp-admin → Plugins.
#    (O bien con wp-cli: wp plugin activate welow-rrhh)
```

Después abre wp-admin y completa el wizard inicial (Empresa → Calendario → Vacaciones → Fichajes → Notificaciones). Activa los módulos que vayas a usar desde el submenú "Módulos".

## Arquitectura

```
welow-rrhh/
├── welow-rrhh.php          # Plugin header + autoload + activation/deactivation.
├── src/                    # Núcleo (PSR-4: Welow\RRHH\)
│   ├── Plugin.php          # Singleton bootstrap + Container.
│   ├── Container.php       # DI ligero (closures con resolución perezosa).
│   ├── Modules/            # Registry, AbstractModule, ModuleInterface.
│   ├── Database/Schema.php # CREATE TABLE de tablas Core.
│   ├── Employees/          # CRUD + EmployeeService + import CSV.
│   ├── Departments/        # CRUD jerárquico.
│   ├── Holidays/           # CRUD + import CSV.
│   ├── Settings/           # CompanySettings + SettingsSanitizer.
│   ├── Wizard/             # 5 pasos guiados.
│   ├── Notifications/      # Dispatcher + canales + templates email.
│   ├── REST/               # AbstractController + RestRoutes.
│   ├── Frontend/           # Shortcode + Tabs base + Frontend assets.
│   ├── Admin/              # Submenús y pantallas.
│   ├── Exporters/          # Orchestrator + drivers (CSV/PDF) + sources.
│   ├── Audit/              # AuditLogger + AuditRepository.
│   ├── Security/Crypto.php # AES-256-GCM con HKDF (DNI/NIE).
│   └── Support/            # Helpers (DTOs, validators, RateLimiter).
└── modules/
    ├── time-tracking/      # Módulo Fichajes (RDL 8/2019).
    │   ├── Module.php
    │   ├── src/...         # Welow\RRHH\Modules\TimeTracking\*
    │   ├── templates/      # Tabs frontend.
    │   └── assets/         # CSS + JS.
    └── vacations/          # Módulo Vacaciones.
        ├── Module.php
        ├── src/...         # Welow\RRHH\Modules\Vacations\*
        ├── templates/
        └── assets/
```

### Patrones canónicos

* **Repositorios** (`*Repository`): único punto de acceso a `$wpdb`. Hidratan filas → DTOs inmutables.
* **DTOs** (`src/Support/Data`, `modules/*/src/Data`): readonly + tipados estrictos. Sin lógica de negocio.
* **Servicios** (`*Service`): validan, audítan y orquestan. Devuelven DTO o `\WP_Error`.
* **Controllers REST**: extienden `AbstractController`, responden con el formato uniforme `{ok, data, error:{code, message}}`.
* **Tabs frontend**: implementan `TabInterface`. Se añaden vía filtro `welow_rrhh/dashboard/tabs`.
* **Capabilities**: declaradas como constantes en una clase `*Capabilities`; método `install()` las inyecta en los roles del Core (`welow_employee`, `welow_manager`, `welow_hr`, `welow_rrhh_admin`).

## Estándares de código

Welow RRHH cumple **WordPress Coding Standards (WPCS)** y los sniffs de `phpcompatibility-wp`. Antes de un commit:

```bash
# Lint (sin modificar).
composer phpcs

# Auto-fix de los issues fixables.
composer phpcbf

# Sólo un subdirectorio.
vendor/bin/phpcs modules/vacations/
```

Reglas locales que solemos aplicar (ver `phpcs.xml.dist`):

* `declare( strict_types=1 );` obligatorio en cada archivo PHP.
* Templates frontend / emails excluidos de `PrefixAllGlobals` (no son globales reales).
* Comentarios `phpcs:ignore` admitidos sólo con justificación corta en la misma línea.

## Convenciones

* **i18n**: text domain `welow-rrhh`. Mensajes en español primero; el `.pot` se exporta para traducciones adicionales.
* **Commits**: usamos *Conventional Commits* (`feat(scope):`, `fix(scope):`, `refactor:`, `docs:`, ...).
* **Branches**: trabajo en `main` (proyecto pequeño). PR a `main` con CI verde si el repo lo configura.
* **Ambigüedad**: cuando la spec no es clara, marca el código con `TODO(welow):` explicando la decisión pendiente.

## Tests

Tests con PHPUnit 10 + WP test framework. Para correrlos en local:

```bash
# Una sola vez: instala wp-phpunit + descarga WP test scaffolding.
composer require --dev wp-phpunit/wp-phpunit

# Crea la base de datos de tests (no toca la BD de tu WP de dev).
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Ejecuta.
composer test
# o
vendor/bin/phpunit
```

Estructura:

```
tests/
├── bootstrap.php
├── Unit/             # No requieren WP. Validan lógica pura (DTOs, validators).
└── Integration/      # WP_UnitTestCase + base de datos efímera.
```

## Releases

1. Bump `WELOW_RRHH_VERSION` en `welow-rrhh.php` y el header `Version:`.
2. Añade entrada al `Changelog` de `readme.txt`.
3. Tag `git tag v0.x.0 && git push --tags`.
4. (Opcional) Subir ZIP de release a WordPress.org / GitHub Releases.

## Subsistemas con peculiaridades

### Cifrado DNI/NIE

Ver `src/Security/Crypto.php`. La clave AES se deriva por HKDF a partir de `AUTH_KEY` (constante de `wp-config.php`). Cambiar `AUTH_KEY` rota la clave y rompe la lectura de los DNIs cifrados — **no la cambies si ya hay datos**. El lookup por DNI usa una columna `dni_nie_hash` con HMAC-SHA256.

### Rate limiting

`Welow\RRHH\Support\RateLimiter` usa transients (`set_transient` + `get_transient`). Ventana fija (no rolling). Suficiente para defensa básica anti-flood; no sirve como medidor preciso.

### Cierre de mes (Fichajes)

`MonthClosure` persiste un array de strings `YYYY-MM` en la opción `welow_rrhh_time_tracking_closed_months`. `ClosureGuard` engancha los filtros `can_edit_entry` / `can_delete_entry` y rechaza ediciones a meses cerrados a menos que el usuario tenga `CLOSE_PERIOD` Y aporte un motivo de ≥30 caracteres.

### Carry-over de vacaciones

`VacationBalance::available()` invalida los días arrastrados una vez superada `carry_over_expires_at`. La fecha viene de la configuración del año (no del balance del año anterior). `BalanceCalculator::recalculate()` regenera la fila materializada de saldo combinando: accrual + carry-over del año anterior (sólo si vigente) − used.

## Cómo contribuir

1. Abre un issue describiendo el cambio.
2. Crea una rama (`git checkout -b feat/mi-cambio`).
3. Implementa con commits atómicos siguiendo *Conventional Commits*.
4. Pasa PHPCS + tests.
5. Abre PR con descripción + screenshots si aplica.

¿Dudas? Pregunta en el repo: <https://github.com/trispania-git/welow-rrhh/issues>.
