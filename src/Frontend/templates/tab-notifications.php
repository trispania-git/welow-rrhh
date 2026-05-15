<?php
/**
 * Contenido del tab "Notificaciones".
 *
 * Variables:
 *   $vars['items']            Notification[]
 *   $vars['unread_count']     int
 *   $vars['filter']           string (all|unread)
 *   $vars['mark_all_url']     string
 *   $vars['filter_all_url']   string
 *   $vars['filter_unread_url'] string
 *
 * @package Welow\RRHH\Frontend
 */

defined( 'ABSPATH' ) || exit;

$items             = is_array( $vars['items'] ?? null ) ? $vars['items'] : array();
$unread_count      = (int) ( $vars['unread_count'] ?? 0 );
$filter            = (string) ( $vars['filter'] ?? 'all' );
$mark_all_url      = (string) ( $vars['mark_all_url'] ?? '' );
$filter_all_url    = (string) ( $vars['filter_all_url'] ?? '' );
$filter_unread_url = (string) ( $vars['filter_unread_url'] ?? '' );
?>
<section class="welow-rrhh__notifications">
	<header class="welow-rrhh__panel-header">
		<h3 class="welow-rrhh__panel-title">
			<?php esc_html_e( 'Notificaciones', 'welow-rrhh' ); ?>
			<?php if ( $unread_count > 0 ) : ?>
				<span class="welow-rrhh__badge"><?php echo (int) $unread_count; ?></span>
			<?php endif; ?>
		</h3>
		<div class="welow-rrhh__panel-actions">
			<a class="welow-rrhh__link <?php echo 'all' === $filter ? 'welow-rrhh__link--active' : ''; ?>" href="<?php echo esc_url( $filter_all_url ); ?>">
				<?php esc_html_e( 'Todas', 'welow-rrhh' ); ?>
			</a>
			<a class="welow-rrhh__link <?php echo 'unread' === $filter ? 'welow-rrhh__link--active' : ''; ?>" href="<?php echo esc_url( $filter_unread_url ); ?>">
				<?php esc_html_e( 'No leídas', 'welow-rrhh' ); ?>
			</a>
			<?php if ( $unread_count > 0 ) : ?>
				<a class="welow-rrhh__link" href="<?php echo esc_url( $mark_all_url ); ?>">
					<?php esc_html_e( 'Marcar todo como leído', 'welow-rrhh' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</header>

	<?php if ( empty( $items ) ) : ?>
		<div class="welow-rrhh__notice welow-rrhh__notice--info">
			<p><?php esc_html_e( 'No hay notificaciones por aquí.', 'welow-rrhh' ); ?></p>
		</div>
	<?php else : ?>
		<ul class="welow-rrhh__notification-list">
			<?php foreach ( $items as $item ) : ?>
				<?php
				$is_unread    = ! $item->is_read();
				$unread_class = $is_unread ? ' welow-rrhh__notification--unread' : '';
				$notif_title  = isset( $item->payload['title'] ) ? (string) $item->payload['title'] : __( 'Notificación', 'welow-rrhh' );
				$notif_body   = isset( $item->payload['body'] ) ? (string) $item->payload['body'] : '';
				$notif_url    = isset( $item->payload['action_url'] ) ? (string) $item->payload['action_url'] : '';
				$notif_label  = isset( $item->payload['action_label'] ) ? (string) $item->payload['action_label'] : __( 'Ver', 'welow-rrhh' );
				?>
				<li class="welow-rrhh__notification<?php echo esc_attr( $unread_class ); ?>">
					<div class="welow-rrhh__notification-head">
						<h4 class="welow-rrhh__notification-title"><?php echo esc_html( $notif_title ); ?></h4>
						<time class="welow-rrhh__notification-time" datetime="<?php echo esc_attr( $item->created_at->format( 'c' ) ); ?>">
							<?php
							/* translators: %s: human-readable time difference. */
							$time_label = sprintf( __( 'hace %s', 'welow-rrhh' ), human_time_diff( $item->created_at->getTimestamp() ) );
							echo esc_html( $time_label );
							?>
						</time>
					</div>
					<?php if ( '' !== $notif_body ) : ?>
						<p class="welow-rrhh__notification-body"><?php echo esc_html( $notif_body ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $notif_url ) : ?>
						<a class="welow-rrhh__link" href="<?php echo esc_url( $notif_url ); ?>"><?php echo esc_html( $notif_label ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
