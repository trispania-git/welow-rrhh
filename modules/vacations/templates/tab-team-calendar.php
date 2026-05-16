<?php
/**
 * Tab "Calendario equipo" — agenda agrupada por mes.
 *
 * Variables en $vars:
 *   - user     \WP_User
 *   - year     int
 *   - by_month array<string, VacationRequest[]>
 *
 * @package Welow\RRHH\Modules\Vacations
 */

defined( 'ABSPATH' ) || exit;

$year     = (int) ( $vars['year'] ?? 0 );
$by_month = is_array( $vars['by_month'] ?? null ) ? $vars['by_month'] : array();

$date_format = (string) get_option( 'date_format', 'Y-m-d' );

$days_fmt = static function ( float $d ): string {
	if ( abs( $d - round( $d ) ) < 0.001 ) {
		return (string) (int) round( $d );
	}
	return rtrim( rtrim( number_format( $d, 1, '.', '' ), '0' ), '.' );
};
?>
<section class="welow-rrhh__panel-section welow-vac__calendar">
	<h3 class="welow-rrhh__panel-title">
		<?php
		/* translators: %d: year. */
		echo esc_html( sprintf( __( 'Calendario del equipo — %d', 'welow-rrhh' ), $year ) );
		?>
	</h3>

	<?php if ( empty( $by_month ) ) : ?>
		<p><?php esc_html_e( 'No hay vacaciones planificadas en el ámbito visible para ti.', 'welow-rrhh' ); ?></p>
	<?php else : ?>
		<?php foreach ( $by_month as $month_key => $items ) : ?>
			<?php
			$first = \DateTimeImmutable::createFromFormat( '!Y-m', $month_key, wp_timezone() );
			$title = false !== $first ? wp_date( 'F Y', $first->getTimestamp() ) : $month_key;
			?>
			<h4 class="welow-vac__month-title"><?php echo esc_html( $title ); ?></h4>
			<table class="welow-rrhh__table welow-vac__list">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Empleado', 'welow-rrhh' ); ?></th>
						<th><?php esc_html_e( 'Desde', 'welow-rrhh' ); ?></th>
						<th><?php esc_html_e( 'Hasta', 'welow-rrhh' ); ?></th>
						<th><?php esc_html_e( 'Días', 'welow-rrhh' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'welow-rrhh' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $r ) : ?>
						<?php $u = get_userdata( $r->user_id ); ?>
						<tr>
							<td><?php echo esc_html( $u ? $u->display_name : '#' . $r->user_id ); ?></td>
							<td><?php echo esc_html( wp_date( $date_format, $r->start_date->getTimestamp() ) ); ?></td>
							<td><?php echo esc_html( wp_date( $date_format, $r->end_date->getTimestamp() ) ); ?></td>
							<td><?php echo esc_html( $days_fmt( $r->requested_days ) ); ?></td>
							<td>
								<span class="welow-vac__status welow-vac__status--<?php echo esc_attr( $r->status->value ); ?>">
									<?php echo esc_html( $r->status->label() ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
	<?php endif; ?>
</section>
