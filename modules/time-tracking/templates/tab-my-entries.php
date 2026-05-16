<?php
/**
 * Tab "Mis fichajes".
 *
 * Variables disponibles en $vars:
 *   - entries  TimeEntry[]
 *   - from     \DateTimeImmutable|null
 *   - to       \DateTimeImmutable|null
 *   - totals   array<string, array{worked_seconds:int,break_seconds:int}>
 *
 * @package Welow\RRHH\Modules\TimeTracking
 */

use Welow\RRHH\Modules\TimeTracking\Support\TimeFormat;

defined( 'ABSPATH' ) || exit;

$entries = is_array( $vars['entries'] ?? null ) ? $vars['entries'] : array();
$from    = $vars['from'] ?? null;
$to      = $vars['to'] ?? null;
$totals  = is_array( $vars['totals'] ?? null ) ? $vars['totals'] : array();

$from_str = $from instanceof \DateTimeImmutable ? $from->format( 'Y-m-d' ) : '';
$to_str   = $to instanceof \DateTimeImmutable ? $to->format( 'Y-m-d' ) : '';

$grouped = array();
foreach ( $entries as $tt_entry ) {
	$grouped[ $tt_entry->date_key() ][] = $tt_entry;
}
krsort( $grouped );
?>
<section class="welow-rrhh__panel-section welow-tt__my-entries">
	<h3 class="welow-rrhh__panel-title"><?php esc_html_e( 'Mis fichajes', 'welow-rrhh' ); ?></h3>

	<form method="get" class="welow-tt__filters">
		<input type="hidden" name="welow_tab" value="my-time-entries" />
		<label>
			<?php esc_html_e( 'Desde', 'welow-rrhh' ); ?>
			<input type="date" name="welow_from" value="<?php echo esc_attr( $from_str ); ?>" />
		</label>
		<label>
			<?php esc_html_e( 'Hasta', 'welow-rrhh' ); ?>
			<input type="date" name="welow_to" value="<?php echo esc_attr( $to_str ); ?>" />
		</label>
		<button type="submit" class="welow-tt__btn welow-tt__btn--secondary">
			<?php esc_html_e( 'Filtrar', 'welow-rrhh' ); ?>
		</button>
	</form>

	<?php if ( empty( $grouped ) ) : ?>
		<div class="welow-rrhh__notice welow-rrhh__notice--info">
			<p><?php esc_html_e( 'No hay fichajes en el rango seleccionado.', 'welow-rrhh' ); ?></p>
		</div>
	<?php else : ?>
		<?php foreach ( $grouped as $day => $day_entries ) : ?>
			<?php
			$day_dt   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day );
			$worked_s = (int) ( $totals[ $day ]['worked_seconds'] ?? 0 );
			$break_s  = (int) ( $totals[ $day ]['break_seconds'] ?? 0 );
			?>
			<details class="welow-tt__day" open>
				<summary>
					<strong><?php echo esc_html( false !== $day_dt ? wp_date( get_option( 'date_format' ), $day_dt->getTimestamp() ) : $day ); ?></strong>
					<span class="welow-tt__day-stats">
						<?php
						/* translators: 1: hours worked (HH:MM), 2: break duration. */
						$label_stats = sprintf( __( 'Trabajado: %1$s · Pausas: %2$s', 'welow-rrhh' ), TimeFormat::duration( $worked_s ), TimeFormat::duration( $break_s ) );
						echo esc_html( $label_stats );
						?>
					</span>
				</summary>
				<table class="welow-tt__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Hora', 'welow-rrhh' ); ?></th>
							<th><?php esc_html_e( 'Evento', 'welow-rrhh' ); ?></th>
							<th><?php esc_html_e( 'Origen', 'welow-rrhh' ); ?></th>
							<th><?php esc_html_e( 'Nota', 'welow-rrhh' ); ?></th>
							<th><?php esc_html_e( 'Editado', 'welow-rrhh' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $day_entries as $tt_row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'time_format' ), $tt_row->occurred_at->getTimestamp() ) ); ?></td>
							<td><?php echo esc_html( $tt_row->event_type->label() ); ?></td>
							<td><?php echo esc_html( $tt_row->source->value ); ?></td>
							<td><?php echo esc_html( $tt_row->note ?? '' ); ?></td>
							<td><?php echo $tt_row->is_edited ? esc_html__( 'Sí', 'welow-rrhh' ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endforeach; ?>
	<?php endif; ?>
</section>
