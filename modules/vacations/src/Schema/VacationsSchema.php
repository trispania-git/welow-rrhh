<?php
/**
 * Schema del módulo Vacaciones (§4.2).
 *
 * Define dos tablas:
 *   - welow_vacation_requests: solicitudes con su estado, motivo y decisión.
 *   - welow_vacation_balances: saldo materializado (acreditado/usado/carry-over)
 *     por (user_id, año) para listas rápidas y cálculo de carry-over.
 *
 * La configuración de "años abiertos" + reglas de carry-over se almacena en
 * la opción `welow_rrhh_vacation_years`, no en tabla (ver
 * Config\VacationYearsConfig).
 *
 * @package Welow\RRHH\Modules\Vacations\Schema
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * VacationsSchema.
 */
final class VacationsSchema {

	public const VERSION           = '1.0.0';
	public const OPTION_DB_VERSION = 'welow_rrhh_vacations_db_version';

	/**
	 * Crea / actualiza las tablas vía dbDelta.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$cc = self::charset_collate( $wpdb );

		dbDelta( self::table_requests( $wpdb->prefix, $cc ) );
		dbDelta( self::table_balances( $wpdb->prefix, $cc ) );

		update_option( self::OPTION_DB_VERSION, self::VERSION, false );
	}

	/**
	 * Lista de tablas (para uninstall opcional).
	 *
	 * @return string[]
	 */
	public static function table_basenames(): array {
		return array( 'welow_vacation_requests', 'welow_vacation_balances' );
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
	 * CREATE TABLE welow_vacation_requests.
	 *
	 * @param string $prefix Prefijo de tablas WP.
	 * @param string $cc     Charset/collate.
	 * @return string
	 */
	private static function table_requests( string $prefix, string $cc ): string {
		$table = $prefix . 'welow_vacation_requests';
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			year SMALLINT NOT NULL,
			type VARCHAR(30) NOT NULL DEFAULT 'vacation',
			start_date DATE NOT NULL,
			end_date DATE NOT NULL,
			start_half_day TINYINT(1) NOT NULL DEFAULT 0,
			end_half_day TINYINT(1) NOT NULL DEFAULT 0,
			requested_days DECIMAL(5,1) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			reason TEXT DEFAULT NULL,
			decided_by BIGINT UNSIGNED DEFAULT NULL,
			decided_at DATETIME DEFAULT NULL,
			decision_note TEXT DEFAULT NULL,
			cancelled_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user_year (user_id, year),
			KEY idx_status (status),
			KEY idx_range (start_date, end_date),
			KEY idx_type (type)
		) ENGINE=InnoDB {$cc};";
	}

	/**
	 * CREATE TABLE welow_vacation_balances.
	 *
	 * @param string $prefix Prefijo de tablas WP.
	 * @param string $cc     Charset/collate.
	 * @return string
	 */
	private static function table_balances( string $prefix, string $cc ): string {
		$table = $prefix . 'welow_vacation_balances';
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			year SMALLINT NOT NULL,
			accrued DECIMAL(5,1) NOT NULL DEFAULT 0,
			used DECIMAL(5,1) NOT NULL DEFAULT 0,
			carried_over_from_prev DECIMAL(5,1) NOT NULL DEFAULT 0,
			carry_over_expires_at DATE DEFAULT NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_user_year (user_id, year),
			KEY idx_year (year)
		) ENGINE=InnoDB {$cc};";
	}
}
