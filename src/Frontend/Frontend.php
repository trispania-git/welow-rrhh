<?php
/**
 * Bootstrap del frontend (shortcodes + assets + página /area-empleado/).
 *
 * @package Welow\RRHH\Frontend
 */

declare( strict_types=1 );

namespace Welow\RRHH\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend bootstrap.
 */
final class Frontend {

	public const SHORTCODE             = 'welow_rrhh_dashboard';
	public const DASHBOARD_PAGE_SLUG   = 'area-empleado';
	private const DASHBOARD_OPTION_KEY = 'welow_rrhh_dashboard_page_id';

	/**
	 * Dashboard.
	 *
	 * @var Dashboard
	 */
	private Dashboard $dashboard;

	/**
	 * Constructor.
	 *
	 * @param Dashboard $dashboard Dashboard.
	 */
	public function __construct( Dashboard $dashboard ) {
		$this->dashboard = $dashboard;
	}

	/**
	 * Engancha shortcodes y assets.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, array( $this->dashboard, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Encola CSS/JS sólo cuando la página contiene el shortcode.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
			return;
		}

		wp_enqueue_style(
			'welow-rrhh-frontend',
			WELOW_RRHH_PLUGIN_URL . 'assets/css/welow-rrhh-frontend.css',
			array(),
			WELOW_RRHH_VERSION
		);

		wp_enqueue_script(
			'welow-rrhh-frontend',
			WELOW_RRHH_PLUGIN_URL . 'assets/js/welow-rrhh-frontend.js',
			array( 'jquery' ),
			WELOW_RRHH_VERSION,
			true
		);

		wp_localize_script(
			'welow-rrhh-frontend',
			'welowRrhh',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'restUrl'   => esc_url_raw( rest_url( 'welow-rrhh/v1/' ) ),
				// Nonce REST API estándar de WP (header X-WP-Nonce).
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				// Nonce adicional para flujos admin-ajax si se usan más adelante.
				'nonce'     => wp_create_nonce( 'welow_rrhh_nonce' ),
			)
		);
	}

	/**
	 * Crea la página /area-empleado/ con el shortcode si no existe.
	 *
	 * Pensado para llamarse desde Activator::activate().
	 *
	 * @return int|null ID de la página existente o creada.
	 */
	public static function ensure_dashboard_page(): ?int {
		$existing_id = (int) get_option( self::DASHBOARD_OPTION_KEY, 0 );
		if ( $existing_id > 0 ) {
			$post = get_post( $existing_id );
			if ( $post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
				return $existing_id;
			}
		}

		// Si ya existe una página con el slug pero sin opción persistida, vincularla.
		$page = get_page_by_path( self::DASHBOARD_PAGE_SLUG, OBJECT, 'page' );
		if ( $page ) {
			update_option( self::DASHBOARD_OPTION_KEY, (int) $page->ID, false );
			return (int) $page->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'     => __( 'Mi área', 'welow-rrhh' ),
				'post_name'      => self::DASHBOARD_PAGE_SLUG,
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'post_content'   => '[' . self::SHORTCODE . ']',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		if ( is_wp_error( $page_id ) || 0 === (int) $page_id ) {
			return null;
		}

		update_option( self::DASHBOARD_OPTION_KEY, (int) $page_id, false );
		return (int) $page_id;
	}
}
