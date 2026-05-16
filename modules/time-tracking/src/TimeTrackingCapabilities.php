<?php
/**
 * Capabilities introducidas por el módulo Fichajes (§5).
 *
 * @package Welow\RRHH\Modules\TimeTracking
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\TimeTracking;

defined( 'ABSPATH' ) || exit;

/**
 * Capabilities slugs del módulo.
 */
final class TimeTrackingCapabilities {

	public const VIEW_OWN     = 'welow_view_own_time_entries';
	public const CREATE_OWN   = 'welow_create_own_time_entries';
	public const EDIT_OWN     = 'welow_edit_own_time_entries';
	public const VIEW_TEAM    = 'welow_view_team_time_entries';
	public const VIEW_ALL     = 'welow_view_all_time_entries';
	public const EDIT_ANY     = 'welow_edit_any_time_entries';
	public const CLOSE_PERIOD = 'welow_close_time_periods';

	/**
	 * Devuelve la lista completa de caps del módulo.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::VIEW_OWN,
			self::CREATE_OWN,
			self::EDIT_OWN,
			self::VIEW_TEAM,
			self::VIEW_ALL,
			self::EDIT_ANY,
			self::CLOSE_PERIOD,
		);
	}

	/**
	 * Mapa rol → caps que se asignan al activar el módulo.
	 *
	 * @return array<string, string[]>
	 */
	public static function role_map(): array {
		return array(
			'welow_employee'   => array(
				self::VIEW_OWN,
				self::CREATE_OWN,
				self::EDIT_OWN,
			),
			'welow_manager'    => array(
				self::VIEW_OWN,
				self::CREATE_OWN,
				self::EDIT_OWN,
				self::VIEW_TEAM,
			),
			'welow_hr'         => array(
				self::VIEW_OWN,
				self::CREATE_OWN,
				self::EDIT_OWN,
				self::VIEW_TEAM,
				self::VIEW_ALL,
				self::EDIT_ANY,
			),
			'welow_rrhh_admin' => self::all(),
			'administrator'    => self::all(),
		);
	}

	/**
	 * Aplica las caps al activar el módulo.
	 *
	 * @return void
	 */
	public static function install(): void {
		foreach ( self::role_map() as $role_slug => $caps ) {
			$role = get_role( $role_slug );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Retira las caps al desactivar el módulo (no destructivo por defecto: no se llama).
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		foreach ( self::role_map() as $role_slug => $caps ) {
			$role = get_role( $role_slug );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
