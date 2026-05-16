<?php
/**
 * Capabilities introducidas por el módulo Vacaciones (§5 / §8).
 *
 * @package Welow\RRHH\Modules\Vacations
 */

declare( strict_types=1 );

namespace Welow\RRHH\Modules\Vacations;

defined( 'ABSPATH' ) || exit;

/**
 * Capabilities slugs del módulo.
 */
final class VacationsCapabilities {

	public const REQUEST_OWN  = 'welow_request_own_vacations';
	public const VIEW_OWN     = 'welow_view_own_vacations';
	public const CANCEL_OWN   = 'welow_cancel_own_vacations';
	public const VIEW_TEAM    = 'welow_view_team_vacations';
	public const APPROVE_TEAM = 'welow_approve_team_vacations';
	public const VIEW_ALL     = 'welow_view_all_vacations';
	public const MANAGE_ANY   = 'welow_manage_any_vacations';
	public const CONFIGURE    = 'welow_configure_vacations';

	/**
	 * Lista completa de caps.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::REQUEST_OWN,
			self::VIEW_OWN,
			self::CANCEL_OWN,
			self::VIEW_TEAM,
			self::APPROVE_TEAM,
			self::VIEW_ALL,
			self::MANAGE_ANY,
			self::CONFIGURE,
		);
	}

	/**
	 * Mapa rol → caps que se asignan al activar.
	 *
	 * @return array<string, string[]>
	 */
	public static function role_map(): array {
		return array(
			'welow_employee'   => array(
				self::REQUEST_OWN,
				self::VIEW_OWN,
				self::CANCEL_OWN,
			),
			'welow_manager'    => array(
				self::REQUEST_OWN,
				self::VIEW_OWN,
				self::CANCEL_OWN,
				self::VIEW_TEAM,
				self::APPROVE_TEAM,
			),
			'welow_hr'         => array(
				self::REQUEST_OWN,
				self::VIEW_OWN,
				self::CANCEL_OWN,
				self::VIEW_TEAM,
				self::APPROVE_TEAM,
				self::VIEW_ALL,
				self::MANAGE_ANY,
				self::CONFIGURE,
			),
			'welow_rrhh_admin' => self::all(),
			'administrator'    => self::all(),
		);
	}

	/**
	 * Asigna caps a roles al activar el módulo.
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
	 * Retira caps (no se invoca al desactivar; presente para uninstall manual).
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
