<?php
/**
 * Layout principal del dashboard frontend.
 *
 * Variables:
 *   $vars['user']        \WP_User
 *   $vars['tabs']        array<string, TabInterface>
 *   $vars['active_slug'] string
 *   $vars['current_url'] string  URL base para construir links de tabs.
 *
 * @package Welow\RRHH\Frontend
 */

defined( 'ABSPATH' ) || exit;

$user        = $vars['user'];
$tabs        = is_array( $vars['tabs'] ?? null ) ? $vars['tabs'] : array();
$active_slug = isset( $vars['active_slug'] ) ? (string) $vars['active_slug'] : '';
$current_url = isset( $vars['current_url'] ) ? (string) $vars['current_url'] : '';
?>
<div class="welow-rrhh__dashboard">
	<header class="welow-rrhh__greeting">
		<h2>
		<?php
			/* translators: %s: display name. */
			printf( esc_html__( 'Hola, %s', 'welow-rrhh' ), esc_html( $user->display_name ) );
		?>
		</h2>
	</header>

	<nav class="welow-rrhh__nav" aria-label="<?php esc_attr_e( 'Secciones del dashboard', 'welow-rrhh' ); ?>">
		<ul class="welow-rrhh__nav-list">
			<?php foreach ( $tabs as $tab_slug => $tab ) : ?>
				<?php
				$tab_url     = add_query_arg( 'welow_tab', $tab_slug, $current_url );
				$is_active   = $active_slug === $tab_slug;
				$active_attr = $is_active ? ' welow-rrhh__nav-item--active' : '';
				?>
				<li class="welow-rrhh__nav-item<?php echo esc_attr( $active_attr ); ?>">
					<a href="<?php echo esc_url( $tab_url ); ?>"<?php echo $is_active ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $tab->label() ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<div class="welow-rrhh__panel" role="region" aria-live="polite">
		<?php
		if ( isset( $tabs[ $active_slug ] ) ) {
			$tabs[ $active_slug ]->render( $user );
		}
		?>
	</div>
</div>
