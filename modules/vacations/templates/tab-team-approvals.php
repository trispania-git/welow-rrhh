<?php
/**
 * Tab "Aprobaciones equipo".
 *
 * Variables en $vars:
 *   - user  \WP_User
 *   - items VacationRequest[] (sólo PENDING dentro del ámbito visible).
 *
 * @package Welow\RRHH\Modules\Vacations
 */

defined( 'ABSPATH' ) || exit;

$items = is_array( $vars['items'] ?? null ) ? $vars['items'] : array();

$date_format = (string) get_option( 'date_format', 'Y-m-d' );

$days_fmt = static function ( float $d ): string {
	if ( abs( $d - round( $d ) ) < 0.001 ) {
		return (string) (int) round( $d );
	}
	return rtrim( rtrim( number_format( $d, 1, '.', '' ), '0' ), '.' );
};
?>
<section class="welow-rrhh__panel-section welow-vac__team">
	<h3 class="welow-rrhh__panel-title"><?php esc_html_e( 'Aprobaciones pendientes', 'welow-rrhh' ); ?></h3>

	<?php if ( empty( $items ) ) : ?>
		<p><?php esc_html_e( 'No hay solicitudes pendientes en tu equipo.', 'welow-rrhh' ); ?></p>
	<?php else : ?>
		<table class="welow-rrhh__table welow-vac__list">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Empleado', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Tipo', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Desde', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Hasta', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Días', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Motivo', 'welow-rrhh' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $r ) : ?>
					<?php
					$wp_user = get_userdata( $r->user_id );
					$label   = $wp_user ? $wp_user->display_name : '#' . $r->user_id;
					?>
					<tr data-request-id="<?php echo esc_attr( (string) $r->id ); ?>">
						<td><?php echo esc_html( $label ); ?></td>
						<td><?php echo esc_html( $r->type->label() ); ?></td>
						<td><?php echo esc_html( wp_date( $date_format, $r->start_date->getTimestamp() ) ); ?></td>
						<td><?php echo esc_html( wp_date( $date_format, $r->end_date->getTimestamp() ) ); ?></td>
						<td><?php echo esc_html( $days_fmt( $r->requested_days ) ); ?></td>
						<td><?php echo esc_html( (string) ( $r->reason ?? '' ) ); ?></td>
						<td class="welow-vac__row-actions">
							<button type="button" class="welow-vac__btn welow-vac__btn--primary" data-action="approve" data-request-id="<?php echo esc_attr( (string) $r->id ); ?>">
								<?php esc_html_e( 'Aprobar', 'welow-rrhh' ); ?>
							</button>
							<button type="button" class="welow-vac__btn welow-vac__btn--ghost" data-action="reject" data-request-id="<?php echo esc_attr( (string) $r->id ); ?>">
								<?php esc_html_e( 'Rechazar', 'welow-rrhh' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
