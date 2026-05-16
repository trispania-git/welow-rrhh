<?php
/**
 * Schema del módulo Fichajes: tabla welow_time_entries (§4.2).
 *
 * @package Welow\RRHH\Modules\TimeTracking\Schema
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * TimeTrackingSchema.
 */
final class TimeTrackingSchema {

	/**
	 * Versión del schema del módulo.
	 */
	public const VERSION = '1.0.0';

	public const OPTION_DB_VERSION = 'welow_rrhh_time_tracking_db_version';

	/**
	 * Crea / actualiza la tabla welow_time_entries vía dbDelta.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = self::charset_collate( $wpdb );
		dbDelta( self::table_time_entries( $wpdb->prefix, $charset_collate ) );

		update_option( self::OPTION_DB_VERSION, self::VERSION, false );
	}

	/**
	 * Lista de tablas (para uninstall opcional).
	 *
	 * @return string[]
	 */
	public static function table_basenames(): array {
		return array( 'welow_time_entries' );
	}

	/**
	 * Charset/collate alineado con el Core.
	 *
	 * @param \wpdb $wpdb wpdb.
	 * @return string
	 */
	private static function charset_collate( \wpdb $wpdb ): string {
		$wp_charset = $wpdb->get_charset_collate();
		if ( false !== stripos( $wp_charset, 'utf8mb4' ) ) {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		}
		return $wp_charset;
	}

	/**
	 * CREATE TABLE de welow_time_entries (§4.2).
	 *
	 * @param string $prefix          Prefijo de tablas WP.
	 * @param string $charset_collate Cláusula de charset/collate.
	 * @return string
	 */
	private static function table_time_entries( string $prefix, string $charset_collate ): string {
		$table = $prefix . 'welow_time_entries';
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(20) NOT NULL DEFAULT 'punch_in',
			occurred_at DATETIME NOT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'web',
			latitude DECIMAL(10,7) DEFAULT NULL,
			longitude DECIMAL(10,7) DEFAULT NULL,
			ip VARCHAR(45) DEFAULT NULL,
			user_agent VARCHAR(255) DEFAULT NULL,
			note TEXT DEFAULT NULL,
			attachment_id BIGINT UNSIGNED DEFAULT NULL,
			is_edited TINYINT(1) NOT NULL DEFAULT 0,
			edited_by BIGINT UNSIGNED DEFAULT NULL,
			edit_reason VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user_occurred (user_id, occurred_at),
			KEY idx_event_type (event_type),
			KEY idx_occurred (occurred_at)
		) ENGINE=InnoDB {$charset_collate};";
	}
}
