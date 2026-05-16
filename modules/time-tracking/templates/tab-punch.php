<?php
/**
 * Tab "Fichar".
 *
 * Variables disponibles en $vars:
 *   - state         string  out|in|on_break
 *   - last          TimeEntry|null
 *   - next_actions  array<int, array{event:EventType,label:string,style:string}>
 *   - require_geo   bool
 *   - user          \WP_User
 *
 * @package Welow\RRHH\Modules\TimeTracking
 */

defined( 'ABSPATH' ) || exit;

$state        = (string) ( $vars['state'] ?? 'out' );
$last         = $vars['last'] ?? null;
$next_actions = is_array( $vars['next_actions'] ?? null ) ? $vars['next_actions'] : array();
$require_geo  = ! empty( $vars['require_geo'] );

$state_labels = array(
	'out'      => __( 'Fuera de jornada', 'welow-rrhh' ),
	'in'       => __( 'Jornada en curso', 'welow-rrhh' ),
	'on_break' => __( 'En pausa', 'welow-rrhh' ),
);
$state_label  = $state_labels[ $state ] ?? $state;
?>
<section class="welow-rrhh__panel-section welow-tt__punch">
	<h3 class="welow-rrhh__panel-title"><?php esc_html_e( 'Fichar', 'welow-rrhh' ); ?></h3>

	<div class="welow-tt__state welow-tt__state--<?php echo esc_attr( $state ); ?>">
		<span class="welow-tt__state-label"><?php esc_html_e( 'Estado:', 'welow-rrhh' ); ?></span>
		<strong><?php echo esc_html( $state_label ); ?></strong>
		<?php if ( null !== $last ) : ?>
			<span class="welow-tt__state-detail">
				<?php
				/* translators: 1: event label, 2: formatted timestamp. */
				$detail_template = __( 'Último evento: %1$s a las %2$s', 'welow-rrhh' );
				$detail_text     = sprintf(
					$detail_template,
					$last->event_type->label(),
					wp_date( get_option( 'time_format' ) . ' (' . get_option( 'date_format' ) . ')', $last->occurred_at->getTimestamp() )
				);
				echo esc_html( $detail_text );
				?>
			</span>
		<?php endif; ?>
	</div>

	<div class="welow-tt__actions"
		data-require-geo="<?php echo $require_geo ? '1' : '0'; ?>">
		<?php foreach ( $next_actions as $action ) : ?>
			<button type="button"
				class="welow-tt__btn welow-tt__btn--<?php echo esc_attr( $action['style'] ); ?>"
				data-event-type="<?php echo esc_attr( $action['event']->value ); ?>"
				data-label="<?php echo esc_attr( $action['label'] ); ?>">
				<?php echo esc_html( $action['label'] ); ?>
			</button>
		<?php endforeach; ?>
	</div>

	<div class="welow-tt__feedback" role="status" aria-live="polite"></div>

	<?php if ( $require_geo ) : ?>
		<p class="welow-rrhh__hint">
			<?php esc_html_e( 'Tu empresa requiere geolocalización para fichar. El navegador te pedirá permiso.', 'welow-rrhh' ); ?>
		</p>
	<?php endif; ?>
</section>
