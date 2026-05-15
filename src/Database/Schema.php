<?php
/**
 * Definición de tablas custom del núcleo Welow RRHH.
 *
 * Cubre las cinco tablas Core descritas en §4.1 de la especificación:
 *   - welow_employees
 *   - welow_departments
 *   - welow_holidays
 *   - welow_notifications
 *   - welow_audit_log
 *
 * Los módulos crean sus propias tablas en sus respectivos Schema/Migrator.
 *
 * @package Welow\RRHH\Database
 */

declare( strict_types=1 );

namespace Welow\RRHH\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Construye e instala las tablas del Core mediante dbDelta().
 */
final class Schema {

	/**
	 * Versión del schema Core. Incrementar al cambiar la estructura.
	 */
	public const VERSION = '1.0.0';

	/**
	 * Crea o actualiza las tablas del Core.
	 *
	 * Es idempotente: se puede ejecutar tantas veces como sea necesario.
	 * dbDelta() compara la estructura y aplica ALTER cuando difiere.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = self::charset_collate( $wpdb );

		dbDelta( self::table_departments( $wpdb->prefix, $charset_collate ) );
		dbDelta( self::table_employees( $wpdb->prefix, $charset_collate ) );
		dbDelta( self::table_holidays( $wpdb->prefix, $charset_collate ) );
		dbDelta( self::table_notifications( $wpdb->prefix, $charset_collate ) );
		dbDelta( self::table_audit_log( $wpdb->prefix, $charset_collate ) );

		update_option( 'welow_rrhh_db_version', self::VERSION, false );
	}

	/**
	 * Lista de nombres de tablas Core (sin prefijo de WP).
	 *
	 * @return string[]
	 */
	public static function table_basenames(): array {
		return array(
			'welow_employees',
			'welow_departments',
			'welow_holidays',
			'welow_notifications',
			'welow_audit_log',
		);
	}

	/**
	 * Calcula la declaración de charset/collate. La especificación pide
	 * `utf8mb4_unicode_520_ci`; si la instalación de MySQL no lo soporta,
	 * caemos al collate por defecto de WordPress.
	 *
	 * @param \wpdb $wpdb Instancia de wpdb.
	 * @return string
	 */
	private static function charset_collate( \wpdb $wpdb ): string {
		$wp_charset_collate = $wpdb->get_charset_collate();

		// Reemplaza el collate si el motor lo soporta; si no, deja el de WP.
		// Nota: detectar disponibilidad de un collate puntual requeriría una
		// query SHOW COLLATION, lo cual es costoso en el flujo de activación.
		// Como utf8mb4_unicode_520_ci está disponible en MySQL 5.6+/MariaDB 10+,
		// nos alineamos con los requisitos declarados (§1).
		if ( false !== stripos( $wp_charset_collate, 'utf8mb4' ) ) {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		}

		return $wp_charset_collate;
	}

	/**
	 * CREATE TABLE de welow_departments.
	 *
	 * @param string $prefix          Prefijo de tablas de WP.
	 * @param string $charset_collate Cláusula de charset/collate.
	 * @return string
	 */
	private static function table_departments( string $prefix, string $charset_collate ): string {
		$table = $prefix . 'welow_departments';
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(150) NOT NULL,
			slug VARCHAR(150) NOT NULL,
			parent_id BIGINT UNSIGNED DEFAULT NULL,
			manager_user_id BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_slug (slug),
			KEY idx_parent (parent_id),
			KEY idx_manager (manager_user_id)
		) ENGINE=InnoDB {$charset_collate};";
	}

	/**
	 * CREATE TABLE de welow_employees.
	 *
	 * Nota: dni_nie almacena el texto cifrado (AES-256-GCM, ver §12).
	 * dni_nie_hash mantiene un HMAC-SHA256 keyed con AUTH_KEY para permitir
	 * lookups por DNI sin necesidad de descifrar la columna.
	 *
	 * @param string $prefix          Prefijo de tablas de WP.
	 * @param string $charset_collate Cláusula de charset/collate.
	 * @return string
	 */
	private static function table_employees( string $prefix, string $charset_collate ): string {
		$table = $prefix . 'welow_employees';
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			employee_code VARCHAR(50) DEFAULT NULL,
			dni_nie VARCHAR(255) DEFAULT NULL,
			dni_nie_hash CHAR(64) DEFAULT NULL,
			first_name VARCHAR(100) NOT NULL DEFAULT '',
			last_name VARCHAR(150) NOT NULL DEFAULT '',
			department_id BIGINT UNSIGNED DEFAULT NULL,
			position VARCHAR(150) NOT NULL DEFAULT '',
			manager_user_id BIGINT UNSIGNED DEFAULT NULL,
			hire_date DATE DEFAULT NULL,
			termination_date DATE DEFAULT NULL,
			weekly_hours DECIMAL(5,2) DEFAULT NULL,
			vacation_days_override SMALLINT DEFAULT NULL,
			geo_policy_override LONGTEXT DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			meta LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_user (user_id),
			UNIQUE KEY uk_employee_code (employee_code),
			UNIQUE KEY uk_dni_hash (dni_nie_hash),
			KEY idx_department (department_id),
			KEY idx_status (status),
			KEY idx_manager (manager_user_id)
		) ENGINE=InnoDB {$charset_collate};";
	}

	/**
	 * CREATE TABLE de welow_holidays.
	 *
	 * @param string $prefix          Prefijo de tablas de WP.
	 * @param string $charset_collate Cláusula de charset/collate.
	 * @return string
	 */
	private static function table_holidays( string $prefix, string $charset_collate ): string {
		$table = $prefix . 'welow_holidays';
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			holiday_date DATE NOT NULL,
			name VARCHAR(255) NOT NULL,
			scope VARCHAR(20) NOT NULL DEFAULT 'national',
			year SMALLINT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_date_scope (holiday_date, scope),
			KEY idx_year (year)
		) ENGINE=InnoDB {$charset_collate};";
	}

	/**
	 * CREATE TABLE de welow_notifications.
	 *
	 * @param string $prefix          Prefijo de tablas de WP.
	 * @param string $charset_collate Cláusula de charset/collate.
	 * @return string
	 */
	private static function table_notifications( string $prefix, string $charset_collate ): string {
		$table = $prefix . 'welow_notifications';
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(100) NOT NULL,
			payload LONGTEXT DEFAULT NULL,
			read_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user_unread (user_id, read_at),
			KEY idx_type (type),
			KEY idx_created (created_at)
		) ENGINE=InnoDB {$charset_collate};";
	}

	/**
	 * CREATE TABLE de welow_audit_log.
	 *
	 * @param string $prefix          Prefijo de tablas de WP.
	 * @param string $charset_collate Cláusula de charset/collate.
	 * @return string
	 */
	private static function table_audit_log( string $prefix, string $charset_collate ): string {
		$table = $prefix . 'welow_audit_log';
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			entity_type VARCHAR(50) NOT NULL,
			entity_id BIGINT UNSIGNED DEFAULT NULL,
			action VARCHAR(50) NOT NULL,
			diff LONGTEXT DEFAULT NULL,
			ip VARCHAR(45) DEFAULT NULL,
			user_agent VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_actor (actor_user_id),
			KEY idx_entity (entity_type, entity_id),
			KEY idx_action (action),
			KEY idx_created (created_at)
		) ENGINE=InnoDB {$charset_collate};";
	}
}
