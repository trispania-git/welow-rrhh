<?php
/**
 * Roles y capabilities del núcleo de Welow RRHH (§5 de la especificación).
 *
 * Define cuatro roles (welow_employee, welow_manager, welow_hr,
 * welow_rrhh_admin) y un conjunto de capabilities granulares para el Core.
 * Las capabilities específicas de cada módulo (Fichajes, Vacaciones…) se
 * añaden por sus respectivos `Module::capabilities()` en activación.
 *
 * @package Welow\RRHH\Roles
 */

declare( strict_types=1 );

namespace Welow\RRHH\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Registro y baja de roles y capabilities.
 */
final class Capabilities {

	public const ROLE_EMPLOYEE = 'welow_employee';
	public const ROLE_MANAGER  = 'welow_manager';
	public const ROLE_HR       = 'welow_hr';
	public const ROLE_ADMIN    = 'welow_rrhh_admin';

	public const CAP_MANAGE_EMPLOYEES = 'welow_manage_employees';
	public const CAP_MANAGE_HOLIDAYS  = 'welow_manage_holidays';
	public const CAP_MANAGE_PLUGIN    = 'welow_manage_plugin';
	public const CAP_EXPORT_DATA      = 'welow_export_data';
	public const CAP_VIEW_AUDIT_LOG   = 'welow_view_audit_log';

	/**
	 * Instala roles y caps. Idempotente.
	 *
	 * @return void
	 */
	public static function install(): void {
		self::install_roles();
		self::grant_admin_capabilities();
	}

	/**
	 * Elimina los roles introducidos y revoca las caps al administrador.
	 * Pensado para uninstall, no para deactivate.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		foreach ( array_keys( self::role_definitions() ) as $role_slug ) {
			remove_role( $role_slug );
		}

		$administrator = get_role( 'administrator' );
		if ( $administrator instanceof \WP_Role ) {
			foreach ( self::core_capabilities() as $cap ) {
				$administrator->remove_cap( $cap );
			}
		}
	}

	/**
	 * Capabilities del Core (no incluye las de los módulos).
	 *
	 * @return string[]
	 */
	public static function core_capabilities(): array {
		return array(
			self::CAP_MANAGE_EMPLOYEES,
			self::CAP_MANAGE_HOLIDAYS,
			self::CAP_MANAGE_PLUGIN,
			self::CAP_EXPORT_DATA,
			self::CAP_VIEW_AUDIT_LOG,
		);
	}

	/**
	 * Definición de los roles Welow: etiqueta + caps por defecto.
	 *
	 * @return array<string, array{label: string, caps: array<string, bool>}>
	 */
	public static function role_definitions(): array {
		return array(
			self::ROLE_EMPLOYEE => array(
				'label' => __( 'Empleado (Welow)', 'welow-rrhh' ),
				'caps'  => array(
					'read' => true,
				),
			),
			self::ROLE_MANAGER  => array(
				'label' => __( 'Manager (Welow)', 'welow-rrhh' ),
				'caps'  => array(
					'read' => true,
				),
			),
			self::ROLE_HR       => array(
				'label' => __( 'RRHH (Welow)', 'welow-rrhh' ),
				'caps'  => array(
					'read'                     => true,
					self::CAP_MANAGE_EMPLOYEES => true,
					self::CAP_MANAGE_HOLIDAYS  => true,
					self::CAP_EXPORT_DATA      => true,
					self::CAP_VIEW_AUDIT_LOG   => true,
				),
			),
			self::ROLE_ADMIN    => array(
				'label' => __( 'Admin Welow RRHH', 'welow-rrhh' ),
				'caps'  => array(
					'read'                     => true,
					self::CAP_MANAGE_EMPLOYEES => true,
					self::CAP_MANAGE_HOLIDAYS  => true,
					self::CAP_MANAGE_PLUGIN    => true,
					self::CAP_EXPORT_DATA      => true,
					self::CAP_VIEW_AUDIT_LOG   => true,
				),
			),
		);
	}

	/**
	 * Crea los roles o, si ya existen, asegura que tengan las caps actualizadas.
	 *
	 * @return void
	 */
	private static function install_roles(): void {
		foreach ( self::role_definitions() as $slug => $definition ) {
			$existing = get_role( $slug );
			if ( null === $existing ) {
				add_role( $slug, $definition['label'], $definition['caps'] );
				continue;
			}
			foreach ( $definition['caps'] as $cap => $grant ) {
				if ( $grant ) {
					$existing->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Asegura que el rol administrator tenga todas las caps Core.
	 *
	 * @return void
	 */
	private static function grant_admin_capabilities(): void {
		$administrator = get_role( 'administrator' );
		if ( ! $administrator instanceof \WP_Role ) {
			return;
		}
		foreach ( self::core_capabilities() as $cap ) {
			$administrator->add_cap( $cap );
		}
	}
}
