<?php
/**
 * Plugin Name:       Reset Divi Presets
 * Description:       Adds an admin bar menu with options to reset Divi 4 and Divi 5 global presets and Divi 5 global variables.
 * Version:           1.0.0
 * Author:            Eduard Ungureanu
 * Author URI:        https://github.com/eduard-un/reset-divi-presets-and-global-variables
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       reset-divi-presets
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Block activation if the Divi theme is not active.
 */
register_activation_hook( __FILE__, 'rdp_activation_check' );
function rdp_activation_check() {
	if ( 'Divi' !== get_template() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'Reset Divi Presets requires the Divi theme to be active. The plugin has not been activated.', 'reset-divi-presets' ),
			esc_html__( 'Plugin Activation Error', 'reset-divi-presets' ),
			[ 'back_link' => true ]
		);
	}
}

/**
 * Main plugin class.
 */
final class Reset_Divi_Presets {

	/**
	 * The single instance of the class.
	 *
	 * @var Reset_Divi_Presets
	 */
	private static $instance = null;

	/**
	 * Global variable sub-types stored in et_divi_global_variables.
	 *
	 * @var array
	 */
	private static $gv_types = [ 'fonts', 'images', 'links', 'numbers', 'strings' ];

	/**
	 * Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_menu' ], 100 );
		add_action( 'init', [ $this, 'handle_reset_actions' ] );
		add_action( 'admin_notices', [ $this, 'show_admin_notices' ] );
		add_action( 'wp_footer', [ $this, 'show_frontend_notices' ] );
		add_action( 'wp_head', [ $this, 'print_admin_bar_styles' ] );
		add_action( 'admin_head', [ $this, 'print_admin_bar_styles' ] );
	}

	/**
	 * Check whether the Divi theme is active.
	 */
	private function is_divi_active() {
		return 'Divi' === get_template();
	}

	/**
	 * Check whether Divi 4 is the active builder (Divi theme active, D5 builder not enabled).
	 */
	private function is_divi_4_active() {
		if ( ! $this->is_divi_active() ) {
			return false;
		}
		return ! ( function_exists( 'et_builder_d5_enabled' ) && et_builder_d5_enabled() );
	}

	/**
	 * Check whether Divi 5 is the active builder (Divi theme active, D5 builder enabled).
	 */
	private function is_divi_5_active() {
		if ( ! $this->is_divi_active() ) {
			return false;
		}
		return function_exists( 'et_builder_d5_enabled' ) && et_builder_d5_enabled();
	}

	/**
	 * Print inline styles for the admin bar separator.
	 */
	public function print_admin_bar_styles() {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<style>
			#wpadminbar #wp-admin-bar-reset-divi-5-all-presets,
			#wpadminbar #wp-admin-bar-reset-all-global-variables {
				border-top: 1px solid rgba(255,255,255,0.2);
				margin-top: 4px;
				padding-top: 4px;
			}
		</style>
		<?php
	}

	/**
	 * Add admin bar menu.
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$divi_4 = $this->is_divi_4_active();
		$divi_5 = $this->is_divi_5_active();

		if ( ! $divi_4 && ! $divi_5 ) {
			return;
		}

		$wp_admin_bar->add_node( [
			'id'    => 'reset-divi-presets',
			'title' => __( 'Reset Divi Presets', 'reset-divi-presets' ),
			'href'  => '#',
		] );

		$current_url = home_url( add_query_arg( [] ) );

		// ── Divi 4 Presets ──────────────────────────────────────────────────────
		if ( $divi_4 ) {
			$wp_admin_bar->add_node( [
				'id'     => 'reset-divi-4-presets',
				'title'  => __( 'Reset Divi 4 Presets', 'reset-divi-presets' ),
				'parent' => 'reset-divi-presets',
				'href'   => add_query_arg( [
					'action'   => 'reset_divi_4_presets',
					'_wpnonce' => wp_create_nonce( 'reset_divi_4_presets' ),
				], $current_url ),
				'meta'   => [
					'onclick' => 'return confirm("' . esc_js( __( 'Are you sure you want to reset all Divi 4 presets?', 'reset-divi-presets' ) ) . '");',
				],
			] );
		}

		// ── Divi 5 Presets ──────────────────────────────────────────────────────
		if ( $divi_5 ) {
			$wp_admin_bar->add_node( [
				'id'     => 'reset-divi-5-presets',
				'title'  => __( 'Reset Divi 5 Presets', 'reset-divi-presets' ),
				'parent' => 'reset-divi-presets',
				'href'   => '#',
			] );

			$wp_admin_bar->add_node( [
				'id'     => 'reset-divi-5-element-presets',
				'title'  => __( 'Reset Element Presets', 'reset-divi-presets' ),
				'parent' => 'reset-divi-5-presets',
				'href'   => add_query_arg( [
					'action'   => 'reset_divi_5_element_presets',
					'_wpnonce' => wp_create_nonce( 'reset_divi_5_element_presets' ),
				], $current_url ),
				'meta'   => [
					'onclick' => 'return confirm("' . esc_js( __( 'Are you sure you want to reset Divi 5 element presets?', 'reset-divi-presets' ) ) . '");',
				],
			] );

			$wp_admin_bar->add_node( [
				'id'     => 'reset-divi-5-option-group-presets',
				'title'  => __( 'Reset Option Group Presets', 'reset-divi-presets' ),
				'parent' => 'reset-divi-5-presets',
				'href'   => add_query_arg( [
					'action'   => 'reset_divi_5_option_group_presets',
					'_wpnonce' => wp_create_nonce( 'reset_divi_5_option_group_presets' ),
				], $current_url ),
				'meta'   => [
					'onclick' => 'return confirm("' . esc_js( __( 'Are you sure you want to reset Divi 5 option group presets?', 'reset-divi-presets' ) ) . '");',
				],
			] );

			$wp_admin_bar->add_node( [
				'id'     => 'reset-divi-5-all-presets',
				'title'  => __( 'Reset All Presets', 'reset-divi-presets' ),
				'parent' => 'reset-divi-5-presets',
				'href'   => add_query_arg( [
					'action'   => 'reset_divi_5_all_presets',
					'_wpnonce' => wp_create_nonce( 'reset_divi_5_all_presets' ),
				], $current_url ),
				'meta'   => [
					'onclick' => 'return confirm("' . esc_js( __( 'Are you sure you want to reset ALL Divi 5 presets?', 'reset-divi-presets' ) ) . '");',
				],
			] );

			// ── Divi 5 Global Variables (parent) ────────────────────────────────
			$wp_admin_bar->add_node( [
				'id'     => 'reset-divi-5-global-variables',
				'title'  => __( 'Reset Divi 5 Global Variables', 'reset-divi-presets' ),
				'parent' => 'reset-divi-presets',
				'href'   => '#',
			] );

			// Individual sub-types.
			// Colors are stored in et_divi → et_global_data.
			// Note: Divi 5 stores "text" variables under the key "strings" in the DB.
			$gv_labels = [
				'colors'  => __( 'Reset Global Colors', 'reset-divi-presets' ),
				'fonts'   => __( 'Reset Global Fonts', 'reset-divi-presets' ),
				'images'  => __( 'Reset Global Images', 'reset-divi-presets' ),
				'links'   => __( 'Reset Global Links', 'reset-divi-presets' ),
				'numbers' => __( 'Reset Global Numbers', 'reset-divi-presets' ),
				'strings' => __( 'Reset Global Text', 'reset-divi-presets' ),
			];

			foreach ( $gv_labels as $type => $label ) {
				$wp_admin_bar->add_node( [
					'id'     => 'reset-global-' . $type,
					'title'  => $label,
					'parent' => 'reset-divi-5-global-variables',
					'href'   => add_query_arg( [
						'action'   => 'reset_global_' . $type,
						'_wpnonce' => wp_create_nonce( 'reset_global_' . $type ),
					], $current_url ),
					'meta'   => [
						'onclick' => 'return confirm("' . esc_js( sprintf(
							/* translators: %s: variable type label */
							__( 'Are you sure you want to reset global %s?', 'reset-divi-presets' ),
							strtolower( $label )
						) ) . '");',
					],
				] );
			}

			// Reset All Global Variables (colors + all sub-types). Separated visually via CSS border-top.
			$wp_admin_bar->add_node( [
				'id'     => 'reset-all-global-variables',
				'title'  => __( 'Reset All Global Variables', 'reset-divi-presets' ),
				'parent' => 'reset-divi-5-global-variables',
				'href'   => add_query_arg( [
					'action'   => 'reset_all_global_variables',
					'_wpnonce' => wp_create_nonce( 'reset_all_global_variables' ),
				], $current_url ),
				'meta'   => [
					'onclick' => 'return confirm("' . esc_js( __( 'Are you sure you want to reset ALL global variables?', 'reset-divi-presets' ) ) . '");',
				],
			] );
		}
	}

	/**
	 * Handle reset actions.
	 */
	public function handle_reset_actions() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['action'] ) ) {
			return;
		}

		$action       = sanitize_key( $_GET['action'] );
		$redirect_url = remove_query_arg( [ 'action', '_wpnonce', 'rdp_notice' ] );

		// ── Divi 4 Presets ──────────────────────────────────────────────────────
		if ( 'reset_divi_4_presets' === $action ) {
			check_admin_referer( 'reset_divi_4_presets' );
			delete_option( 'et_divi_builder_global_presets_history_ng' );
			delete_option( 'et_divi_builder_global_presets_ng' );
			wp_safe_redirect( add_query_arg( 'rdp_notice', 'divi_4_presets_reset', $redirect_url ) );
			exit;
		}

		// ── Divi 5 Presets ──────────────────────────────────────────────────────
		if ( 'reset_divi_5_element_presets' === $action ) {
			check_admin_referer( 'reset_divi_5_element_presets' );
			$this->reset_d5_preset_type( 'module' );
			wp_safe_redirect( add_query_arg( 'rdp_notice', 'divi_5_element_presets_reset', $redirect_url ) );
			exit;
		}

		if ( 'reset_divi_5_option_group_presets' === $action ) {
			check_admin_referer( 'reset_divi_5_option_group_presets' );
			$this->reset_d5_preset_type( 'group' );
			wp_safe_redirect( add_query_arg( 'rdp_notice', 'divi_5_option_group_presets_reset', $redirect_url ) );
			exit;
		}

		if ( 'reset_divi_5_all_presets' === $action ) {
			check_admin_referer( 'reset_divi_5_all_presets' );
			delete_option( 'et_divi_builder_global_presets_d5' );
			$this->delete_d5_presets_history();
			wp_safe_redirect( add_query_arg( 'rdp_notice', 'divi_5_all_presets_reset', $redirect_url ) );
			exit;
		}

		// ── Global Colors (stored in et_divi → et_global_data) ──────────────────
		if ( 'reset_global_colors' === $action ) {
			check_admin_referer( 'reset_global_colors' );
			$et_divi = get_option( 'et_divi' );
			if ( is_array( $et_divi ) ) {
				$et_divi['et_global_data'] = '';
				update_option( 'et_divi', $et_divi );
			}
			wp_safe_redirect( add_query_arg( 'rdp_notice', 'global_colors_reset', $redirect_url ) );
			exit;
		}

		// ── All Global Variables at once ────────────────────────────────────────
		if ( 'reset_all_global_variables' === $action ) {
			check_admin_referer( 'reset_all_global_variables' );
			$et_divi = get_option( 'et_divi' );
			if ( is_array( $et_divi ) ) {
				$et_divi['et_global_data'] = '';
				update_option( 'et_divi', $et_divi );
			}
			delete_option( 'et_divi_global_variables' );
			wp_safe_redirect( add_query_arg( 'rdp_notice', 'all_global_variables_reset', $redirect_url ) );
			exit;
		}

		// ── Global Variables sub-types (stored in et_divi_global_variables) ──────
		foreach ( self::$gv_types as $type ) {
			if ( 'reset_global_' . $type === $action ) {
				check_admin_referer( 'reset_global_' . $type );
				$this->reset_global_variable_type( $type );
				wp_safe_redirect( add_query_arg( 'rdp_notice', 'global_' . $type . '_reset', $redirect_url ) );
				exit;
			}
		}
	}

	/**
	 * Remove a single type key from et_divi_global_variables.
	 *
	 * The option may be stored as a JSON string or a PHP array (WordPress
	 * serializes arrays automatically).
	 *
	 * @param string $type One of: fonts, images, links, numbers, strings.
	 */
	private function reset_global_variable_type( $type ) {
		$global_vars = get_option( 'et_divi_global_variables' );

		if ( empty( $global_vars ) ) {
			return;
		}

		if ( is_string( $global_vars ) ) {
			$decoded = json_decode( $global_vars, true );
			if ( is_array( $decoded ) ) {
				unset( $decoded[ $type ] );
				update_option( 'et_divi_global_variables', wp_json_encode( $decoded ) );
			}
			return;
		}

		if ( is_array( $global_vars ) ) {
			unset( $global_vars[ $type ] );
			update_option( 'et_divi_global_variables', $global_vars );
		}
	}

	/**
	 * Unset a single preset type key ('module' or 'group') from et_divi_builder_global_presets_d5.
	 *
	 * @param string $type 'module' for element presets, 'group' for option group presets.
	 */
	private function reset_d5_preset_type( $type ) {
		$presets = get_option( 'et_divi_builder_global_presets_d5' );

		if ( is_array( $presets ) ) {
			unset( $presets[ $type ] );
			update_option( 'et_divi_builder_global_presets_d5', $presets );
			return;
		}

		if ( is_string( $presets ) ) {
			$decoded = json_decode( $presets, true );
			if ( is_array( $decoded ) ) {
				unset( $decoded[ $type ] );
				update_option( 'et_divi_builder_global_presets_d5', wp_json_encode( $decoded ) );
			}
		}
	}

	/**
	 * Delete all Divi 5 preset history items and the history meta option.
	 *
	 * D5 stores history as et_divi_builder_presets_history_item_{n} (0…index)
	 * plus et_divi_builder_presets_history_meta which holds the current index.
	 */
	private function delete_d5_presets_history() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE 'et\_divi\_builder\_presets\_history\_item\_%'"
		);
		delete_option( 'et_divi_builder_presets_history_meta' );
	}

	/**
	 * Return all notice messages keyed by rdp_notice value.
	 */
	private function get_notices() {
		return [
			'divi_4_presets_reset'                => __( 'Divi 4 presets have been reset.', 'reset-divi-presets' ),
			'divi_5_element_presets_reset'        => __( 'Divi 5 element presets have been reset.', 'reset-divi-presets' ),
			'divi_5_option_group_presets_reset'   => __( 'Divi 5 option group presets have been reset.', 'reset-divi-presets' ),
			'divi_5_all_presets_reset'            => __( 'All Divi 5 presets have been reset.', 'reset-divi-presets' ),
			'global_colors_reset'  => __( 'Global colors have been reset.', 'reset-divi-presets' ),
			'global_fonts_reset'   => __( 'Global fonts have been reset.', 'reset-divi-presets' ),
			'global_images_reset'  => __( 'Global images have been reset.', 'reset-divi-presets' ),
			'global_links_reset'   => __( 'Global links have been reset.', 'reset-divi-presets' ),
			'global_numbers_reset' => __( 'Global numbers have been reset.', 'reset-divi-presets' ),
			'global_strings_reset'         => __( 'Global text has been reset.', 'reset-divi-presets' ),
			'all_global_variables_reset'   => __( 'All global variables have been reset.', 'reset-divi-presets' ),
		];
	}

	/**
	 * Show admin notices.
	 */
	public function show_admin_notices() {
		if ( ! isset( $_GET['rdp_notice'] ) ) {
			return;
		}

		$notices = $this->get_notices();
		$key     = sanitize_key( $_GET['rdp_notice'] );

		if ( isset( $notices[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notices[ $key ] ) . '</p></div>';
		}
	}

	/**
	 * Show frontend notices.
	 */
	public function show_frontend_notices() {
		if ( ! isset( $_GET['rdp_notice'] ) ) {
			return;
		}

		$notices = $this->get_notices();
		$key     = sanitize_key( $_GET['rdp_notice'] );

		if ( isset( $notices[ $key ] ) ) {
			$redirect_url = remove_query_arg( 'rdp_notice' );
			echo "<script>alert('" . esc_js( $notices[ $key ] ) . "'); window.history.replaceState(null, null, '" . esc_url( $redirect_url ) . "');</script>";
		}
	}
}

/**
 * Begins execution of the plugin.
 */
function reset_divi_presets_run() {
	return Reset_Divi_Presets::instance();
}
reset_divi_presets_run();
