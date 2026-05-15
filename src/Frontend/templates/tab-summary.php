<?php
/**
 * Contenido del tab "Mi resumen".
 *
 * Variables:
 *   $vars['user']       \WP_User
 *   $vars['employee']   Employee|null
 *   $vars['department'] Department|null
 *   $vars['manager']    \WP_User|false
 *
 * @package Welow\RRHH\Frontend
 */

defined( 'ABSPATH' ) || exit;

$employee   = $vars['employee'] ?? null;
$department = $vars['department'] ?? null;
$manager    = $vars['manager'] ?? null;
?>
<section class="welow-rrhh__summary">
	<h3 class="welow-rrhh__panel-title"><?php esc_html_e( 'Mi resumen', 'welow-rrhh' ); ?></h3>

	<?php if ( null === $employee ) : ?>
		<div class="welow-rrhh__notice welow-rrhh__notice--warning">
			<p><?php esc_html_e( 'Tu usuario aún no está vinculado a un empleado de Welow RRHH. Pide a RRHH que te dé de alta.', 'welow-rrhh' ); ?></p>
		</div>
	<?php else : ?>
		<dl class="welow-rrhh__data-list">
			<div class="welow-rrhh__data-row">
				<dt><?php esc_html_e( 'Nombre', 'welow-rrhh' ); ?></dt>
				<dd><?php echo esc_html( $employee->full_name() ); ?></dd>
			</div>
			<?php if ( '' !== $employee->position ) : ?>
				<div class="welow-rrhh__data-row">
					<dt><?php esc_html_e( 'Cargo', 'welow-rrhh' ); ?></dt>
					<dd><?php echo esc_html( $employee->position ); ?></dd>
				</div>
			<?php endif; ?>
			<?php if ( null !== $department ) : ?>
				<div class="welow-rrhh__data-row">
					<dt><?php esc_html_e( 'Departamento', 'welow-rrhh' ); ?></dt>
					<dd><?php echo esc_html( $department->name ); ?></dd>
				</div>
			<?php endif; ?>
			<?php if ( $manager instanceof WP_User ) : ?>
				<div class="welow-rrhh__data-row">
					<dt><?php esc_html_e( 'Manager directo', 'welow-rrhh' ); ?></dt>
					<dd><?php echo esc_html( $manager->display_name ); ?></dd>
				</div>
			<?php endif; ?>
			<?php if ( null !== $employee->hire_date ) : ?>
				<div class="welow-rrhh__data-row">
					<dt><?php esc_html_e( 'Fecha de alta', 'welow-rrhh' ); ?></dt>
					<dd><?php echo esc_html( wp_date( get_option( 'date_format' ), $employee->hire_date->getTimestamp() ) ); ?></dd>
				</div>
			<?php endif; ?>
			<?php if ( null !== $employee->weekly_hours ) : ?>
				<div class="welow-rrhh__data-row">
					<dt><?php esc_html_e( 'Horas semanales', 'welow-rrhh' ); ?></dt>
					<dd>
					<?php
						/* translators: %s: weekly hours. */
						printf( esc_html__( '%s h', 'welow-rrhh' ), esc_html( number_format_i18n( $employee->weekly_hours, 2 ) ) );
					?>
					</dd>
				</div>
			<?php endif; ?>
		</dl>

		<p class="welow-rrhh__hint">
			<?php esc_html_e( 'Próximamente: resumen de horas trabajadas y saldo de vacaciones (cuando se activen los módulos correspondientes).', 'welow-rrhh' ); ?>
		</p>
	<?php endif; ?>
</section>
