<?php
/**
 * Tab "Mis vacaciones".
 *
 * Variables en $vars:
 *   - user        \WP_User
 *   - year        int
 *   - balance     VacationBalance
 *   - available   float
 *   - requests    VacationRequest[]
 *   - year_cfg    VacationYear|null
 *   - can_request bool
 *   - can_cancel  bool
 *   - today       \DateTimeImmutable
 *
 * @package Welow\RRHH\Modules\Vacations
 */

defined( 'ABSPATH' ) || exit;

$year        = (int) ( $vars['year'] ?? 0 );
$balance     = $vars['balance'] ?? null;
$available   = (float) ( $vars['available'] ?? 0 );
$requests    = is_array( $vars['requests'] ?? null ) ? $vars['requests'] : array();
$year_cfg    = $vars['year_cfg'] ?? null;
$can_request = ! empty( $vars['can_request'] );
$can_cancel  = ! empty( $vars['can_cancel'] );
$today       = $vars['today'] ?? null;

$date_format = (string) get_option( 'date_format', 'Y-m-d' );

$days_fmt = static function ( float $d ): string {
	if ( abs( $d - round( $d ) ) < 0.001 ) {
		return (string) (int) round( $d );
	}
	return rtrim( rtrim( number_format( $d, 1, '.', '' ), '0' ), '.' );
};
?>
<section class="welow-rrhh__panel-section welow-vac__my">
	<h3 class="welow-rrhh__panel-title">
		<?php
		/* translators: %d: year. */
		echo esc_html( sprintf( __( 'Mis vacaciones %d', 'welow-rrhh' ), $year ) );
		?>
	</h3>

	<?php if ( null !== $balance ) : ?>
		<div class="welow-vac__balance" role="status">
			<div class="welow-vac__balance-tile">
				<span class="welow-vac__balance-label"><?php esc_html_e( 'Días acreditados', 'welow-rrhh' ); ?></span>
				<strong class="welow-vac__balance-value"><?php echo esc_html( $days_fmt( $balance->accrued ) ); ?></strong>
			</div>
			<div class="welow-vac__balance-tile">
				<span class="welow-vac__balance-label"><?php esc_html_e( 'Disfrutados', 'welow-rrhh' ); ?></span>
				<strong class="welow-vac__balance-value"><?php echo esc_html( $days_fmt( $balance->used ) ); ?></strong>
			</div>
			<?php if ( $balance->carried_over_from_prev > 0 ) : ?>
				<div class="welow-vac__balance-tile">
					<span class="welow-vac__balance-label"><?php esc_html_e( 'Arrastrados', 'welow-rrhh' ); ?></span>
					<strong class="welow-vac__balance-value">
						<?php echo esc_html( $days_fmt( $balance->carried_over_from_prev ) ); ?>
						<?php if ( $balance->carry_over_expires_at ) : ?>
							<small>
								<?php
								/* translators: %s: expiry date. */
								echo esc_html( sprintf( __( '(caducan %s)', 'welow-rrhh' ), wp_date( $date_format, $balance->carry_over_expires_at->getTimestamp() ) ) );
								?>
							</small>
						<?php endif; ?>
					</strong>
				</div>
			<?php endif; ?>
			<div class="welow-vac__balance-tile welow-vac__balance-tile--available">
				<span class="welow-vac__balance-label"><?php esc_html_e( 'Disponibles', 'welow-rrhh' ); ?></span>
				<strong class="welow-vac__balance-value"><?php echo esc_html( $days_fmt( $available ) ); ?></strong>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( ! $can_request ) : ?>
		<p class="welow-rrhh__hint">
			<?php
			if ( null === $year_cfg ) {
				esc_html_e( 'El año actual no está abierto para solicitar vacaciones. Habla con tu departamento de RRHH.', 'welow-rrhh' );
			} elseif ( ! $year_cfg->is_open ) {
				esc_html_e( 'El año actual está cerrado para nuevas solicitudes.', 'welow-rrhh' );
			} else {
				esc_html_e( 'No tienes permisos para crear solicitudes.', 'welow-rrhh' );
			}
			?>
		</p>
	<?php else : ?>
		<form class="welow-vac__form" data-action="create">
			<h4 class="welow-vac__form-title"><?php esc_html_e( 'Solicitar vacaciones', 'welow-rrhh' ); ?></h4>
			<div class="welow-vac__form-row">
				<label>
					<?php esc_html_e( 'Desde', 'welow-rrhh' ); ?>
					<input type="date" name="start_date" required>
				</label>
				<label>
					<?php esc_html_e( 'Hasta', 'welow-rrhh' ); ?>
					<input type="date" name="end_date" required>
				</label>
			</div>
			<div class="welow-vac__form-row">
				<label class="welow-vac__checkbox">
					<input type="checkbox" name="start_half_day" value="1">
					<?php esc_html_e( 'Empezar por la tarde (medio día)', 'welow-rrhh' ); ?>
				</label>
				<label class="welow-vac__checkbox">
					<input type="checkbox" name="end_half_day" value="1">
					<?php esc_html_e( 'Terminar por la mañana (medio día)', 'welow-rrhh' ); ?>
				</label>
			</div>
			<div class="welow-vac__form-row">
				<label class="welow-vac__textarea">
					<?php esc_html_e( 'Motivo (opcional)', 'welow-rrhh' ); ?>
					<textarea name="reason" rows="2"></textarea>
				</label>
			</div>
			<div class="welow-vac__form-row">
				<button type="submit" class="welow-vac__btn welow-vac__btn--primary">
					<?php esc_html_e( 'Enviar solicitud', 'welow-rrhh' ); ?>
				</button>
				<span class="welow-vac__feedback" role="status" aria-live="polite"></span>
			</div>
		</form>
	<?php endif; ?>

	<h4 class="welow-vac__list-title"><?php esc_html_e( 'Tus solicitudes', 'welow-rrhh' ); ?></h4>
	<?php if ( empty( $requests ) ) : ?>
		<p><?php esc_html_e( 'Aún no has enviado solicitudes este año.', 'welow-rrhh' ); ?></p>
	<?php else : ?>
		<table class="welow-rrhh__table welow-vac__list">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tipo', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Desde', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Hasta', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Días', 'welow-rrhh' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'welow-rrhh' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $requests as $r ) : ?>
					<?php
					$can_cancel_this = $can_cancel
						&& in_array( $r->status->value, array( 'pending', 'approved' ), true )
						&& ( $today instanceof \DateTimeImmutable ? $r->start_date > $today : true );
					?>
					<tr data-request-id="<?php echo esc_attr( (string) $r->id ); ?>" data-status="<?php echo esc_attr( $r->status->value ); ?>">
						<td><?php echo esc_html( $r->type->label() ); ?></td>
						<td><?php echo esc_html( wp_date( $date_format, $r->start_date->getTimestamp() ) ); ?></td>
						<td><?php echo esc_html( wp_date( $date_format, $r->end_date->getTimestamp() ) ); ?></td>
						<td><?php echo esc_html( $days_fmt( $r->requested_days ) ); ?></td>
						<td>
							<span class="welow-vac__status welow-vac__status--<?php echo esc_attr( $r->status->value ); ?>">
								<?php echo esc_html( $r->status->label() ); ?>
							</span>
							<?php if ( '' !== (string) $r->decision_note && ( 'rejected' === $r->status->value || 'approved' === $r->status->value ) ) : ?>
								<small class="welow-vac__note"><?php echo esc_html( $r->decision_note ); ?></small>
							<?php endif; ?>
						</td>
						<td class="welow-vac__row-actions">
							<?php if ( $can_cancel_this ) : ?>
								<button type="button" class="welow-vac__btn welow-vac__btn--ghost" data-action="cancel" data-request-id="<?php echo esc_attr( (string) $r->id ); ?>">
									<?php esc_html_e( 'Cancelar', 'welow-rrhh' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
