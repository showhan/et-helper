<?php
defined( 'ABSPATH' ) || exit;

/**
 * Feature: Command Palette
 *
 * Keyboard-driven command palette (default Ctrl+Shift+C / Cmd+Shift+C,
 * customizable per user) for wp-admin and the frontend. Fuzzy-searches admin
 * menu pages, admin bar links, and Divi pages; offers context actions for the
 * current post/page (Edit with Divi, Edit in WordPress, View); built-in
 * commands (Open…, Edit with Divi…, Edit in WordPress…, Clear Cache, Change
 * Shortcut…); plugin activate/deactivate; pinned and recent actions; and an
 * accent color that follows the Divi Visual Builder color scheme.
 *
 * Ported from the standalone must-use plugin at
 * github.com/VladET/et-command-palette (originally a set of `acp_`-prefixed
 * global functions hooked directly at file-load time) into an ET Helper
 * feature class, following the same construction/instantiation pattern as
 * the rest of includes/features/. Method names drop the `acp_` prefix;
 * behavior is otherwise unchanged. AJAX nonce action names, the
 * `acp_searchable_post_types` filter, user-meta keys (`acp_shortcut`,
 * `acp_admin_pages_v2`, etc.), and the `window.acpConfig` / `acpMergePages`
 * JS globals are all left as-is.
 */
class ETH_Command_Palette {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_bar_menu', [ $this, 'capture_admin_bar_pages' ], 99999 );
		add_action( 'wp_after_admin_bar_render', [ $this, 'print_admin_bar_merge_script' ] );
		add_action( 'admin_menu', [ $this, 'cache_admin_pages' ], 99999 );
		add_action( 'wp_ajax_acp_save_pins', [ $this, 'ajax_save_pins' ] );
		add_action( 'wp_ajax_acp_save_shortcut', [ $this, 'ajax_save_shortcut' ] );
		add_action( 'wp_ajax_acp_clear_cache', [ $this, 'ajax_clear_cache' ] );
		add_action( 'wp_ajax_acp_search_posts', [ $this, 'ajax_search_posts' ] );
		add_action( 'wp_ajax_acp_toggle_plugin', [ $this, 'ajax_toggle_plugin' ] );
	}


/**
 * Whether the current request should load the command palette.
 *
 * @return bool
 */
public function should_load() {
	if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
		return false;
	}

	if ( function_exists( 'is_login' ) && is_login() ) {
		return false;
	}

	return true;
}

/**
 * Enqueue command palette assets on admin and frontend.
 */
public function enqueue_assets() {
	if ( ! $this->should_load() ) {
		return;
	}

	$context  = $this->get_context_actions();
	$commands = $this->get_builtin_commands();
	$pages    = $this->merge_pages( $this->get_admin_pages(), $context );
	$pages    = $this->merge_pages( $pages, $commands );
	$config   = array(
		'pages'           => $pages,
		'contextActions'  => $context,
		'commands'        => $commands,
		'defaultPins'     => $this->get_default_pins(),
		'pinnedActions'   => $this->get_user_pins(),
		'shortcut'        => $this->get_user_shortcut(),
		'defaultShortcut' => $this->default_shortcut(),
		'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
		'nonce'           => wp_create_nonce( 'acp_toggle_plugin' ),
		'searchNonce'     => wp_create_nonce( 'acp_search_posts' ),
		'pinsNonce'       => wp_create_nonce( 'acp_save_pins' ),
		'clearCacheNonce' => wp_create_nonce( 'acp_clear_cache' ),
		'shortcutNonce'   => wp_create_nonce( 'acp_save_shortcut' ),
		'accentColor'     => $this->get_app_accent_color(),
	);

	$handle = 'et-command-palette';

	wp_enqueue_style(
		$handle,
		ETH_ASSETS_URL . 'css/command-palette.css',
		array(),
		ETH_VERSION
	);

	wp_enqueue_script(
		$handle,
		ETH_ASSETS_URL . 'js/command-palette.js',
		array(),
		ETH_VERSION,
		true
	);
	wp_add_inline_script(
		$handle,
		'window.acpConfig = ' . wp_json_encode( $config ) . ';',
		'before'
	);
}

/**
 * Capture admin-bar nodes after all callbacks have registered them.
 *
 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
 */
public function capture_admin_bar_pages( $wp_admin_bar ) {
	if ( ! $this->should_load() ) {
		return;
	}

	$GLOBALS['acp_admin_bar_pages'] = $this->pages_from_admin_bar( $wp_admin_bar );
}

/**
 * Print a small script that merges admin-bar links into the palette.
 *
 * Runs after the bar renders so ordering vs. the main footer script does not matter.
 */
public function print_admin_bar_merge_script() {
	if ( ! $this->should_load() ) {
		return;
	}

	$pages = array();

	if ( ! empty( $GLOBALS['acp_admin_bar_pages'] ) && is_array( $GLOBALS['acp_admin_bar_pages'] ) ) {
		$pages = $GLOBALS['acp_admin_bar_pages'];
	} else {
		global $wp_admin_bar;
		if ( is_object( $wp_admin_bar ) ) {
			$pages = $this->pages_from_admin_bar( $wp_admin_bar );
		}
	}

	if ( empty( $pages ) ) {
		return;
	}

	printf(
		'<script>window.acpAdminBarPages = %1$s; if (window.acpMergePages) { window.acpMergePages(window.acpAdminBarPages); }</script>' . "\n",
		wp_json_encode( $pages )
	);
}

/**
 * Resolve the Visual Builder UI color scheme name (blue, purple, green, …).
 *
 * Prefers the active/default VB workspace setting, then the global
 * `et_fb_pref_app_color_scheme` option.
 *
 * @return string
 */
public function get_app_color_scheme() {
	$scheme       = null;
	$workspace_id = 'global';
	$workspaces   = null;

	// Workspace-aware preference (matches VB load behavior).
	if ( class_exists( '\ET\Builder\VisualBuilder\Workspace\Workspace' ) ) {
		$workspaces = \ET\Builder\VisualBuilder\Workspace\Workspace::get_preferences_workspaces();
	} else {
		$saved = get_user_meta( get_current_user_id(), 'et_divi_builder_preferences_workspaces', true );
		if ( is_array( $saved ) ) {
			$workspaces = $saved;
		}
	}

	if ( is_array( $workspaces ) ) {
		if ( ! empty( $workspaces['defaultWorkspaceId'] ) && is_string( $workspaces['defaultWorkspaceId'] ) ) {
			$workspace_id = $workspaces['defaultWorkspaceId'];
		} elseif ( ! empty( $workspaces['activeWorkspaceId'] ) && is_string( $workspaces['activeWorkspaceId'] ) ) {
			$workspace_id = $workspaces['activeWorkspaceId'];
		}

		if ( 'global' === $workspace_id ) {
			$settings = isset( $workspaces['global']['settings'] ) && is_array( $workspaces['global']['settings'] )
				? $workspaces['global']['settings']
				: array();
		} else {
			$settings = isset( $workspaces['custom'][ $workspace_id ]['settings'] ) && is_array( $workspaces['custom'][ $workspace_id ]['settings'] )
				? $workspaces['custom'][ $workspace_id ]['settings']
				: array();
		}

		if ( ! empty( $settings['appColorScheme'] ) && is_string( $settings['appColorScheme'] ) ) {
			$scheme = $settings['appColorScheme'];
		}
	}

	if ( null === $scheme && function_exists( 'et_get_option' ) ) {
		$saved = et_get_option( 'et_fb_pref_app_color_scheme', 'blue', '', true );
		if ( is_string( $saved ) && '' !== $saved ) {
			$scheme = $saved;
		}
	}

	if ( ! is_string( $scheme ) || '' === $scheme ) {
		return 'blue';
	}

	return $scheme;
}

/**
 * Get the Visual Builder UI accent color from builder color scheme settings.
 *
 * Mirrors Divi VB `appColorScheme` tokens from common/scss/_colors.scss.
 *
 * @return string Hex color.
 */
public function get_app_accent_color() {
	$colors = array(
		'blue'   => '#326BFF',
		'purple' => '#7432FF',
		'green'  => '#0ACFA0',
		'orange' => '#ff9232',
		'red'    => '#ef5555',
	);

	$scheme = $this->get_app_color_scheme();

	if ( ! isset( $colors[ $scheme ] ) ) {
		return $colors['blue'];
	}

	return $colors[ $scheme ];
}

/**
 * User meta key for the cached admin page index.
 *
 * @return string
 */
public function cache_meta_key() {
	return 'acp_admin_pages_v2';
}

/**
 * Collect searchable admin menu pages.
 *
 * Builds from $menu/$submenu in wp-admin and stores them in user meta. On the
 * frontend we never bootstrap admin menus (theme/plugin callbacks may fatal);
 * we use the saved index, merged with a fallback that includes Divi pages.
 *
 * @return array<int, array{title: string, url: string, parent: string}>
 */
public function get_admin_pages() {
	if ( is_admin() ) {
		$pages = $this->build_pages_from_menu();
		$pages = $this->filter_excluded_pages( $pages );

		if ( ! empty( $pages ) ) {
			update_user_meta( get_current_user_id(), $this->cache_meta_key(), $pages );
		}

		return $this->merge_pages( $pages, $this->get_plugin_actions() );
	}

	$user_id = get_current_user_id();
	$cached  = get_user_meta( $user_id, $this->cache_meta_key(), true );
	$pages   = is_array( $cached ) ? $cached : array();

	// Migrate legacy transient cache if user meta is empty.
	if ( empty( $pages ) ) {
		$legacy = get_transient( 'acp_admin_pages_' . $user_id );
		if ( is_array( $legacy ) && ! empty( $legacy ) ) {
			$pages = $legacy;
			update_user_meta( $user_id, $this->cache_meta_key(), $pages );
		}
	}

	// Supplement with fallback so Divi pages exist even before the first admin visit.
	$pages = $this->merge_pages( $pages, $this->get_fallback_pages() );
	$pages = $this->filter_excluded_pages( $pages );

	return $this->merge_pages( $pages, $this->get_plugin_actions() );
}

/**
 * Persist menu index as soon as all admin menus are registered.
 */
public function cache_admin_pages() {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$pages = $this->build_pages_from_menu();
	$pages = $this->filter_excluded_pages( $pages );
	if ( ! empty( $pages ) ) {
		update_user_meta( get_current_user_id(), $this->cache_meta_key(), $pages );
	}
}

/**
 * Normalize a URL for duplicate detection.
 *
 * Strips hash, trailing slashes / index.php, and sorts query args so equivalent
 * admin links collapse even when title or formatting differs.
 *
 * @param string $url Raw URL.
 * @return string
 */
public function normalize_url( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return '';
	}

	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return strtolower( untrailingslashit( $url ) );
	}

	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
	$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
	$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
	$path   = isset( $parts['path'] ) ? $parts['path'] : '';
	$query  = isset( $parts['query'] ) ? $parts['query'] : '';

	$path = preg_replace( '#/index\.php$#i', '', $path );
	$path = untrailingslashit( $path );

	if ( '' !== $query ) {
		parse_str( $query, $qs );
		if ( is_array( $qs ) ) {
			ksort( $qs );
			$query = http_build_query( $qs );
		}
	}

	$out = '';
	if ( '' !== $scheme && '' !== $host ) {
		$out = $scheme . '://' . $host;
		if ( $port > 0 ) {
			$out .= ':' . $port;
		}
	}

	$out .= $path;

	if ( '' !== $query ) {
		$out .= '?' . $query;
	}

	return strtolower( $out );
}

/**
 * Merge page lists, keyed to avoid duplicates.
 *
 * Navigational items dedupe by normalized URL (same link, different titles).
 * Plugin / command / context items keep type-specific keys.
 *
 * @param array $base  Base pages.
 * @param array $extra Extra pages to add.
 * @return array
 */
public function merge_pages( $base, $extra ) {
	$keyed = array();

	foreach ( array_merge( $base, $extra ) as $page ) {
		if ( empty( $page['title'] ) ) {
			continue;
		}

		$is_plugin  = ! empty( $page['type'] ) && 'plugin' === $page['type'];
		$is_context = ! empty( $page['type'] ) && 'context' === $page['type'];
		$is_command = ! empty( $page['type'] ) && 'command' === $page['type'];

		if ( ! $is_plugin && ! $is_command && empty( $page['url'] ) ) {
			continue;
		}

		if ( ! empty( $page['url'] ) && $this->is_excluded_url( $page['url'] ) ) {
			continue;
		}

		if ( ! empty( $page['title'] ) && $this->is_excluded_label( $page['title'] ) ) {
			continue;
		}

		if ( $is_plugin ) {
			$key = 'plugin|' . ( isset( $page['plugin'] ) ? $page['plugin'] : $page['title'] );
		} elseif ( $is_command && ! empty( $page['id'] ) ) {
			$key = 'command|' . $page['id'];
		} elseif ( $is_context && ! empty( $page['id'] ) ) {
			$key = 'context|' . $page['id'];
		} else {
			$normalized = $this->normalize_url( $page['url'] );
			$key        = '' !== $normalized
				? 'url|' . $normalized
				: 'title|' . strtolower( $page['title'] );
		}

		// Prefer the first occurrence (admin menu over admin bar / fallback).
		if ( isset( $keyed[ $key ] ) ) {
			continue;
		}

		$entry = array(
			'title'  => $page['title'],
			'url'    => isset( $page['url'] ) ? $page['url'] : '',
			'parent' => isset( $page['parent'] ) ? $page['parent'] : '',
		);

		if ( $is_plugin ) {
			$entry['type']         = 'plugin';
			$entry['plugin']       = isset( $page['plugin'] ) ? $page['plugin'] : '';
			$entry['pluginAction'] = isset( $page['pluginAction'] ) ? $page['pluginAction'] : '';
			$entry['pluginName']   = isset( $page['pluginName'] ) ? $page['pluginName'] : '';
			$entry['network']      = ! empty( $page['network'] );
		}

		if ( $is_context ) {
			$entry['type'] = 'context';
			$entry['id']   = isset( $page['id'] ) ? $page['id'] : '';
		}

		if ( $is_command ) {
			$entry['type'] = 'command';
			$entry['id']   = isset( $page['id'] ) ? $page['id'] : '';
		}

		$keyed[ $key ] = $entry;
	}

	return array_values( $keyed );
}

/**
 * Labels (titles) to omit from the palette.
 *
 * Matching is case-insensitive and ignores surrounding whitespace.
 *
 * @return string[]
 */
public function get_excluded_labels() {
	return array(
		'About WordPress',
		'Learn WordPress',
	);
}

/**
 * Whether a label/title should be omitted from the palette.
 *
 * @param string $title Page title.
 * @return bool
 */
public function is_excluded_label( $title ) {
	$title = strtolower( trim( wp_strip_all_tags( (string) $title ) ) );

	if ( '' === $title ) {
		return false;
	}

	foreach ( $this->get_excluded_labels() as $excluded ) {
		if ( $title === strtolower( trim( (string) $excluded ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether a URL should be omitted from the palette.
 *
 * @param string $url Page URL.
 * @return bool
 */
public function is_excluded_url( $url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );

	if ( false !== strpos( $path, 'edit-comments.php' ) ) {
		return true;
	}

	if ( false !== strpos( $path, 'about.php' ) ) {
		return true;
	}

	return false;
}

/**
 * Whether a page entry should be omitted from the palette.
 *
 * @param array $page Page entry.
 * @return bool
 */
public function is_excluded_page( $page ) {
	if ( ! empty( $page['title'] ) && $this->is_excluded_label( $page['title'] ) ) {
		return true;
	}

	if ( ! empty( $page['url'] ) && $this->is_excluded_url( $page['url'] ) ) {
		return true;
	}

	return false;
}

/**
 * Resolve the post ID for the current screen (frontend singular or admin edit).
 *
 * @return int
 */
public function get_current_post_id() {
	if ( is_admin() ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context detection.
		if ( isset( $_GET['post'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context detection.
			return absint( $_GET['post'] );
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'post' === $screen->base && ! empty( $GLOBALS['post']->ID ) ) {
			return (int) $GLOBALS['post']->ID;
		}

		return 0;
	}

	if ( is_singular() ) {
		return (int) get_queried_object_id();
	}

	global $wp_the_query;
	if ( is_object( $wp_the_query ) && method_exists( $wp_the_query, 'get_queried_object_id' ) ) {
		$id = (int) $wp_the_query->get_queried_object_id();
		if ( $id > 0 ) {
			return $id;
		}
	}

	return 0;
}

/**
 * Whether the Visual Builder is currently active on this request.
 *
 * @return bool
 */
public function is_visual_builder_active() {
	if ( function_exists( 'et_fb_is_enabled' ) && et_fb_is_enabled() ) {
		return true;
	}

	if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context detection.
	return isset( $_GET['et_fb'] ) && '1' === $_GET['et_fb'];
}

/**
 * Build a Visual Builder / Edit with Divi URL for a post.
 *
 * @param int $post_id Post ID.
 * @return string Empty when Divi editing is unavailable.
 */
public function get_divi_edit_url( $post_id ) {
	$post_id = absint( $post_id );
	if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
		return '';
	}

	if ( ! function_exists( 'et_pb_is_allowed' ) || ! et_pb_is_allowed( 'use_visual_builder' ) ) {
		return '';
	}

	if ( function_exists( 'et_builder_fb_enabled_for_post' ) && ! et_builder_fb_enabled_for_post( $post_id ) ) {
		return '';
	}

	if ( ! function_exists( 'et_fb_get_builder_url' ) ) {
		return '';
	}

	$permalink = get_permalink( $post_id );
	if ( ! $permalink ) {
		return '';
	}

	$builder_used = function_exists( 'et_pb_is_pagebuilder_used' ) && et_pb_is_pagebuilder_used( $post_id );

	if ( $builder_used ) {
		$url = et_fb_get_builder_url( $permalink );
	} else {
		$url = add_query_arg(
			array(
				'et_fb_activation_nonce' => wp_create_nonce( 'et_fb_activation_nonce_' . $post_id ),
			),
			$permalink
		);
	}

	return is_string( $url ) ? $url : '';
}

/**
 * Build context actions for the current post/page.
 *
 * @return array<int, array<string, mixed>>
 */
public function get_context_actions() {
	$post_id = $this->get_current_post_id();

	if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
		return array();
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$actions    = array();
	$permalink  = get_permalink( $post_id );
	$edit_link  = get_edit_post_link( $post_id, 'raw' );
	$post_type  = get_post_type_object( $post->post_type );
	$type_label = $post_type && ! empty( $post_type->labels->singular_name )
		? $post_type->labels->singular_name
		: 'Post';
	$in_vb      = $this->is_visual_builder_active();
	$on_edit    = is_admin() && function_exists( 'get_current_screen' );
	$screen     = $on_edit ? get_current_screen() : null;
	$on_wp_edit = $screen && 'post' === $screen->base;

	// Edit with Divi — skip when already in the Visual Builder.
	if ( ! $in_vb ) {
		$vb_url = $this->get_divi_edit_url( $post_id );
		if ( '' !== $vb_url ) {
			$actions[] = array(
				'id'     => 'edit-with-divi',
				'title'  => 'Edit with Divi',
				'url'    => $vb_url,
				'parent' => 'Current',
				'type'   => 'context',
			);
		}
	}

	// Edit in WordPress — skip when already on the classic/block edit screen.
	if ( $edit_link && ! $on_wp_edit ) {
		$actions[] = array(
			'id'     => 'edit-in-wp',
			'title'  => 'Edit in WordPress',
			'url'    => $edit_link,
			'parent' => 'Current',
			'type'   => 'context',
		);
	}

	// View on frontend — when editing in admin or inside the Visual Builder.
	if ( $permalink && ( is_admin() || $in_vb || is_preview() ) ) {
		$actions[] = array(
			'id'     => 'view-frontend',
			'title'  => sprintf( 'View %s', $type_label ),
			'url'    => $permalink,
			'parent' => 'Current',
			'type'   => 'context',
		);
	}

	return $actions;
}

/**
 * Default pin suggestions when the user has not pinned anything yet.
 *
 * @return array<int, array{title: string, url: string, parent: string}>
 */
public function get_default_pins() {
	$candidates = array(
		array(
			'title' => 'Theme Options',
			'url'   => admin_url( 'admin.php?page=et_divi_options' ),
			'cap'   => 'edit_theme_options',
		),
		array(
			'title' => 'Plugins',
			'url'   => admin_url( 'plugins.php' ),
			'cap'   => 'activate_plugins',
		),
		array(
			'title' => 'Theme Customizer',
			'url'   => admin_url( 'customize.php?et_customizer_option_set=theme' ),
			'cap'   => 'edit_theme_options',
		),
	);

	$defaults = array();

	foreach ( $candidates as $item ) {
		if ( ! current_user_can( $item['cap'] ) ) {
			continue;
		}

		$defaults[] = array(
			'title'  => $item['title'],
			'url'    => $item['url'],
			'parent' => '',
		);
	}

	return $defaults;
}

/**
 * User meta key for persisted pinned commands.
 *
 * @return string
 */
public function pins_meta_key() {
	return 'acp_pinned_commands';
}

/**
 * Sanitize a single pinned command snapshot.
 *
 * @param mixed $item Raw pin entry.
 * @return array<string, mixed>|null
 */
public function sanitize_pin_entry( $item ) {
	if ( ! is_array( $item ) ) {
		return null;
	}

	$key   = isset( $item['key'] ) ? sanitize_text_field( (string) $item['key'] ) : '';
	$title = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';

	if ( '' === $key || '' === $title ) {
		return null;
	}

	$type = isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '';
	$url  = '';

	if ( ! empty( $item['url'] ) && is_string( $item['url'] ) ) {
		$url = esc_url_raw( $item['url'] );
	}

	// Navigational pins need a URL; plugin/command pins may not.
	if ( '' === $url && ! in_array( $type, array( 'plugin', 'command' ), true ) ) {
		return null;
	}

	$entry = array(
		'key'          => $key,
		'title'        => $title,
		'url'          => $url,
		'parent'       => isset( $item['parent'] ) ? sanitize_text_field( (string) $item['parent'] ) : '',
		'type'         => $type,
		'plugin'       => isset( $item['plugin'] ) ? sanitize_text_field( (string) $item['plugin'] ) : '',
		'pluginAction' => isset( $item['pluginAction'] ) ? sanitize_key( (string) $item['pluginAction'] ) : '',
		'pluginName'   => isset( $item['pluginName'] ) ? sanitize_text_field( (string) $item['pluginName'] ) : '',
		'network'      => ! empty( $item['network'] ),
		'id'           => isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '',
		'postId'       => isset( $item['postId'] ) ? absint( $item['postId'] ) : 0,
		'viewUrl'      => ! empty( $item['viewUrl'] ) && is_string( $item['viewUrl'] ) ? esc_url_raw( $item['viewUrl'] ) : '',
		'editUrl'      => ! empty( $item['editUrl'] ) && is_string( $item['editUrl'] ) ? esc_url_raw( $item['editUrl'] ) : '',
		'diviUrl'      => ! empty( $item['diviUrl'] ) && is_string( $item['diviUrl'] ) ? esc_url_raw( $item['diviUrl'] ) : '',
		'intent'       => isset( $item['intent'] ) ? sanitize_key( (string) $item['intent'] ) : '',
		'rawTitle'     => isset( $item['rawTitle'] ) ? sanitize_text_field( (string) $item['rawTitle'] ) : '',
	);

	return $entry;
}

/**
 * Sanitize a list of pinned command snapshots.
 *
 * @param mixed $pins Raw pins list.
 * @return array<int, array<string, mixed>>
 */
public function sanitize_pins_list( $pins ) {
	if ( ! is_array( $pins ) ) {
		return array();
	}

	$clean = array();
	$seen  = array();

	foreach ( $pins as $item ) {
		$entry = $this->sanitize_pin_entry( $item );
		if ( null === $entry ) {
			continue;
		}

		if ( isset( $seen[ $entry['key'] ] ) ) {
			continue;
		}

		$seen[ $entry['key'] ] = true;
		$clean[]               = $entry;
	}

	return $clean;
}

/**
 * Get the current user's pinned commands from user meta.
 *
 * @return array<int, array<string, mixed>>
 */
public function get_user_pins() {
	$user_id = get_current_user_id();
	if ( $user_id < 1 ) {
		return array();
	}

	$stored = get_user_meta( $user_id, $this->pins_meta_key(), true );
	return $this->sanitize_pins_list( $stored );
}

/**
 * Persist pinned commands for the current user.
 *
 * @param array $pins Pin snapshots.
 * @return array<int, array<string, mixed>> Sanitized list that was saved.
 */
public function save_user_pins( $pins ) {
	$user_id = get_current_user_id();
	if ( $user_id < 1 ) {
		return array();
	}

	$clean = $this->sanitize_pins_list( $pins );
	update_user_meta( $user_id, $this->pins_meta_key(), $clean );

	return $clean;
}

/**
 * AJAX: save the current user's pinned commands.
 */
public function ajax_save_pins() {
	check_ajax_referer( 'acp_save_pins', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	$raw = isset( $_POST['pins'] ) ? wp_unslash( $_POST['pins'] ) : '';
	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
	} elseif ( is_array( $raw ) ) {
		$decoded = $raw;
	} else {
		$decoded = array();
	}

	$saved = $this->save_user_pins( is_array( $decoded ) ? $decoded : array() );

	wp_send_json_success(
		array(
			'pins' => $saved,
		)
	);
}

/**
 * User meta key for the palette open shortcut.
 *
 * @return string
 */
public function shortcut_meta_key() {
	return 'acp_shortcut';
}

/**
 * Default open shortcut (Ctrl/Cmd+Shift+C).
 *
 * @return array{mod: bool, shift: bool, alt: bool, key: string}
 */
public function default_shortcut() {
	return array(
		'mod'   => true,
		'shift' => true,
		'alt'   => false,
		'key'   => 'c',
	);
}

/**
 * Sanitize a shortcut chord.
 *
 * Requires at least mod (Ctrl/Cmd) or alt so bare letters cannot steal typing.
 *
 * @param mixed $shortcut Raw shortcut.
 * @return array{mod: bool, shift: bool, alt: bool, key: string}|null
 */
public function sanitize_shortcut( $shortcut ) {
	if ( ! is_array( $shortcut ) ) {
		return null;
	}

	$mod   = ! empty( $shortcut['mod'] );
	$shift = ! empty( $shortcut['shift'] );
	$alt   = ! empty( $shortcut['alt'] );
	$key   = isset( $shortcut['key'] ) ? (string) $shortcut['key'] : '';

	if ( '' === $key ) {
		return null;
	}

	// Letters → lowercase; digits / punctuation (e.g. \) kept as KeyboardEvent.key.
	if ( 1 === strlen( $key ) ) {
		if ( preg_match( '/^\s$/u', $key ) ) {
			return null;
		}
		if ( preg_match( '/^[A-Za-z]$/', $key ) ) {
			$key = strtolower( $key );
		}
	} else {
		// Allow common named keys (F-keys, ArrowLeft, etc.).
		$key = sanitize_text_field( $key );
		if ( '' === $key || preg_match( '/^(Control|Meta|Alt|Shift|Dead)$/i', $key ) ) {
			return null;
		}
		// Normalize single-char after sanitize edge cases.
		if ( 1 === strlen( $key ) && preg_match( '/^[A-Za-z]$/', $key ) ) {
			$key = strtolower( $key );
		}
	}

	// Escape alone (or as open key) is reserved for cancel / close.
	if ( 'escape' === strtolower( $key ) || 'esc' === strtolower( $key ) ) {
		return null;
	}

	if ( ! $mod && ! $alt ) {
		return null;
	}

	return array(
		'mod'   => $mod,
		'shift' => $shift,
		'alt'   => $alt,
		'key'   => $key,
	);
}

/**
 * Get the current user's open shortcut from user meta.
 *
 * @return array{mod: bool, shift: bool, alt: bool, key: string}
 */
public function get_user_shortcut() {
	$user_id = get_current_user_id();
	if ( $user_id < 1 ) {
		return $this->default_shortcut();
	}

	$stored = get_user_meta( $user_id, $this->shortcut_meta_key(), true );
	$clean  = $this->sanitize_shortcut( $stored );

	return null !== $clean ? $clean : $this->default_shortcut();
}

/**
 * Persist the open shortcut for the current user.
 *
 * @param mixed $shortcut Raw shortcut.
 * @return array{mod: bool, shift: bool, alt: bool, key: string}|null Sanitized shortcut, or null on failure.
 */
public function save_user_shortcut( $shortcut ) {
	$user_id = get_current_user_id();
	if ( $user_id < 1 ) {
		return null;
	}

	$clean = $this->sanitize_shortcut( $shortcut );
	if ( null === $clean ) {
		return null;
	}

	// wp_slash() before saving: update_metadata() runs wp_unslash() on the value,
	// which would eat a lone backslash key (macOS reports Cmd+Shift+\ as "\").
	update_user_meta( $user_id, $this->shortcut_meta_key(), wp_slash( $clean ) );

	return $clean;
}

/**
 * AJAX: save the current user's open shortcut.
 */
public function ajax_save_shortcut() {
	check_ajax_referer( 'acp_save_shortcut', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	$decoded = null;

	// Prefer discrete fields — JSON + wp_unslash breaks keys like "\".
	if ( isset( $_POST['key'] ) ) {
		$decoded = array(
			'mod'   => ! empty( $_POST['mod'] ),
			'shift' => ! empty( $_POST['shift'] ),
			'alt'   => ! empty( $_POST['alt'] ),
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in $this->sanitize_shortcut().
			'key'   => (string) wp_unslash( $_POST['key'] ),
		);
	} elseif ( isset( $_POST['shortcut'] ) ) {
		$raw = wp_unslash( $_POST['shortcut'] );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		}
	}

	$saved = $this->save_user_shortcut( $decoded );
	if ( null === $saved ) {
		wp_send_json_error( array( 'message' => 'Invalid shortcut. Use Ctrl/Cmd or Alt plus a key.' ) );
	}

	wp_send_json_success(
		array(
			'shortcut' => $saved,
		)
	);
}

/**
 * Whether the current user can edit any show_ui post type.
 *
 * @return bool
 */
public function user_can_edit_content() {
	if ( current_user_can( 'edit_posts' ) ) {
		return true;
	}

	foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $post_type ) {
		$cap = ! empty( $post_type->cap->edit_posts ) ? $post_type->cap->edit_posts : 'edit_posts';
		if ( current_user_can( $cap ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Built-in palette commands (non-navigational).
 *
 * @return array<int, array<string, mixed>>
 */
public function get_builtin_commands() {
	$commands = array(
		array(
			'id'     => 'change-shortcut',
			'title'  => 'Change Shortcut…',
			'url'    => '',
			'parent' => 'Command',
			'type'   => 'command',
		),
	);

	if ( $this->user_can_edit_content() ) {
		$commands[] = array(
			'id'     => 'open-post',
			'title'  => 'Open…',
			'url'    => '',
			'parent' => 'Command',
			'type'   => 'command',
		);

		if ( function_exists( 'et_pb_is_allowed' ) && et_pb_is_allowed( 'use_visual_builder' ) ) {
			$commands[] = array(
				'id'     => 'edit-divi',
				'title'  => 'Edit with Divi…',
				'url'    => '',
				'parent' => 'Command',
				'type'   => 'command',
			);
		}

		$commands[] = array(
			'id'     => 'edit-wp',
			'title'  => 'Edit in WordPress…',
			'url'    => '',
			'parent' => 'Command',
			'type'   => 'command',
		);
	}

	if ( $this->user_can_clear_cache() ) {
		$commands[] = array(
			'id'     => 'clear-cache',
			'title'  => 'Clear Cache',
			'url'    => '',
			'parent' => 'Command',
			'type'   => 'command',
		);
	}

	return $commands;
}

/**
 * Whether the current user can clear Divi cache from the palette.
 *
 * @return bool
 */
public function user_can_clear_cache() {
	return current_user_can( 'manage_options' ) && class_exists( 'ET_Core_PageResource' );
}

/**
 * AJAX: clear Divi static CSS / page resources cache.
 */
public function ajax_clear_cache() {
	check_ajax_referer( 'acp_clear_cache', 'nonce' );

	if ( ! $this->user_can_clear_cache() ) {
		wp_send_json_error(
			array(
				'message' => 'You do not have permission to clear the cache.',
			),
			403
		);
	}

	// Match Theme Options "Clear" — delete static resources for the whole site.
	ET_Core_PageResource::remove_static_resources( 'all', 'all', false, 'all', false, true );

	wp_send_json_success(
		array(
			'message' => 'Cache cleared.',
			'reload'  => true,
		)
	);
}

/**
 * Post types available for the Open… search.
 *
 * @return string[]
 */
public function get_searchable_post_types() {
	$post_types = get_post_types(
		array(
			'show_ui' => true,
		),
		'names'
	);

	$exclude = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
	);

	$post_types = array_values( array_diff( (array) $post_types, $exclude ) );

	/**
	 * Filter post types included in the command palette Open… search.
	 *
	 * @param string[] $post_types Post type names.
	 */
	return apply_filters( 'acp_searchable_post_types', $post_types );
}

/**
 * AJAX: search posts/pages (any editable post type) for the Open… command.
 */
public function ajax_search_posts() {
	check_ajax_referer( 'acp_search_posts', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	$query      = isset( $_REQUEST['q'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) : '';
	$post_types = $this->get_searchable_post_types();

	if ( empty( $post_types ) ) {
		wp_send_json_success( array( 'results' => array() ) );
	}

	$args = array(
		'post_type'              => $post_types,
		'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page'         => 20,
		'orderby'                => '' !== $query ? 'relevance' : 'modified',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);

	if ( '' !== $query ) {
		if ( ctype_digit( $query ) ) {
			$args['p']        = absint( $query );
			$args['orderby']  = 'ID';
			unset( $args['s'] );
		} else {
			$args['s'] = $query;
		}
	}

	$posts   = get_posts( $args );
	$results = array();

	foreach ( $posts as $post ) {
		if ( ! $post instanceof WP_Post ) {
			continue;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			continue;
		}

		$edit_link = get_edit_post_link( $post->ID, 'raw' );
		if ( ! $edit_link ) {
			continue;
		}

		$view_link = get_permalink( $post );
		if ( ! $view_link ) {
			$view_link = $edit_link;
		} elseif ( 'publish' !== $post->post_status ) {
			$preview = get_preview_post_link( $post );
			if ( $preview ) {
				$view_link = $preview;
			}
		}

		$post_type  = get_post_type_object( $post->post_type );
		$type_label = $post_type && ! empty( $post_type->labels->singular_name )
			? $post_type->labels->singular_name
			: $post->post_type;

		$title = get_the_title( $post );
		if ( '' === $title ) {
			$title = sprintf( '(no title) #%d', $post->ID );
		}

		$status_label = '';
		if ( 'publish' !== $post->post_status ) {
			$status_obj   = get_post_status_object( $post->post_status );
			$status_label = $status_obj && ! empty( $status_obj->label ) ? $status_obj->label : $post->post_status;
		}

		$parent = $type_label;
		if ( '' !== $status_label ) {
			$parent = $type_label . ' · ' . $status_label;
		}

		$results[] = array(
			'title'   => $title,
			'url'     => $edit_link,
			'viewUrl' => $view_link,
			'editUrl' => $edit_link,
			'diviUrl' => $this->get_divi_edit_url( $post->ID ),
			'parent'  => $parent,
			'type'    => 'post',
			'postId'  => (int) $post->ID,
		);
	}

	wp_send_json_success( array( 'results' => $results ) );
}

/**
 * Build Activate / Deactivate actions for installed plugins.
 *
 * Fresh on every load so active state stays accurate (not stored in menu cache).
 * Handled via AJAX — no admin link navigation required.
 *
 * @return array<int, array<string, mixed>>
 */
public function get_plugin_actions() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return array();
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugins = get_plugins();
	if ( empty( $plugins ) || ! is_array( $plugins ) ) {
		return array();
	}

	$actions = array();

	foreach ( $plugins as $plugin_file => $plugin_data ) {
		if ( empty( $plugin_data['Name'] ) ) {
			continue;
		}

		$name    = $plugin_data['Name'];
		$network = false;

		// Network-active plugins need network caps to toggle.
		if ( is_multisite() && is_plugin_active_for_network( $plugin_file ) ) {
			if ( ! current_user_can( 'manage_network_plugins' ) ) {
				continue;
			}
			$network = true;
			$action  = 'deactivate';
		} elseif ( is_plugin_active( $plugin_file ) ) {
			$action = 'deactivate';
		} else {
			$action = 'activate';
		}

		$actions[] = array(
			'title'        => ( 'activate' === $action ? 'Activate ' : 'Deactivate ' ) . $name,
			'url'          => '',
			'parent'       => 'Plugins',
			'type'         => 'plugin',
			'plugin'       => $plugin_file,
			'pluginAction' => $action,
			'pluginName'   => $name,
			'network'      => $network,
		);
	}

	return $actions;
}

/**
 * AJAX: activate or deactivate a plugin, then reload so menus/assets refresh.
 */
public function ajax_toggle_plugin() {
	check_ajax_referer( 'acp_toggle_plugin', 'nonce' );

	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to manage plugins.' ), 403 );
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin  = isset( $_POST['plugin'] ) ? wp_unslash( $_POST['plugin'] ) : '';
	$action  = isset( $_POST['pluginAction'] ) ? sanitize_key( wp_unslash( $_POST['pluginAction'] ) ) : '';
	$network = ! empty( $_POST['network'] );
	$plugins = get_plugins();

	if ( ! is_string( $plugin ) || ! isset( $plugins[ $plugin ] ) ) {
		wp_send_json_error( array( 'message' => 'Plugin not found.' ) );
	}

	if ( ! in_array( $action, array( 'activate', 'deactivate' ), true ) ) {
		wp_send_json_error( array( 'message' => 'Invalid action.' ) );
	}

	if ( $network && ! current_user_can( 'manage_network_plugins' ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to manage network plugins.' ), 403 );
	}

	$name = $plugins[ $plugin ]['Name'];

	if ( 'activate' === $action ) {
		$result = activate_plugin( $plugin, '', $network );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'      => sprintf( 'Activated %s.', $name ),
				'plugin'       => $plugin,
				'pluginAction' => 'deactivate',
				'title'        => 'Deactivate ' . $name,
				'pluginName'   => $name,
				'network'      => $network,
				'reload'       => true,
			)
		);
	}

	deactivate_plugins( $plugin, false, $network );

	wp_send_json_success(
		array(
			'message'      => sprintf( 'Deactivated %s.', $name ),
			'plugin'       => $plugin,
			'pluginAction' => 'activate',
			'title'        => 'Activate ' . $name,
			'pluginName'   => $name,
			'network'      => $network,
			'reload'       => true,
		)
	);
}

/**
 * Drop excluded pages from a list.
 *
 * @param array $pages Page entries.
 * @return array
 */
public function filter_excluded_pages( $pages ) {
	if ( empty( $pages ) || ! is_array( $pages ) ) {
		return array();
	}

	return array_values(
		array_filter(
			$pages,
			function ( $page ) {
				$is_plugin  = ! empty( $page['type'] ) && 'plugin' === $page['type'];
				$is_command = ! empty( $page['type'] ) && 'command' === $page['type'];

				if ( ! $is_plugin && ! $is_command && empty( $page['url'] ) ) {
					return false;
				}

				return ! $this->is_excluded_page( $page );
			}
		)
	);
}

/**
 * Convert admin-bar nodes into palette page entries.
 *
 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
 * @return array<int, array{title: string, url: string, parent: string}>
 */
public function pages_from_admin_bar( $wp_admin_bar ) {
	$pages = array();

	if ( ! is_object( $wp_admin_bar ) || ! method_exists( $wp_admin_bar, 'get_nodes' ) ) {
		return $pages;
	}

	$nodes = $wp_admin_bar->get_nodes();
	if ( empty( $nodes ) || ! is_array( $nodes ) ) {
		return $pages;
	}

	$skip_ids = array(
		'menu-toggle',
		'search',
		'top-secondary',
		'wp-logo',
		'wp-logo-external',
		'about',
		'comments',
		'updates',
		'user-info',
	);

	foreach ( $nodes as $id => $node ) {
		if ( in_array( $id, $skip_ids, true ) ) {
			continue;
		}

		if ( empty( $node->href ) || '#' === $node->href ) {
			continue;
		}

		// Skip non-navigational / script URLs.
		if ( 0 === stripos( $node->href, 'javascript:' ) ) {
			continue;
		}

		$title = $this->clean_title( isset( $node->title ) ? $node->title : '' );
		if ( '' === $title ) {
			continue;
		}

		$parent = 'Admin Bar';
		if ( ! empty( $node->parent ) && isset( $nodes[ $node->parent ] ) ) {
			$parent_title = $this->clean_title( $nodes[ $node->parent ]->title );
			if ( '' !== $parent_title ) {
				$parent = $parent_title;
			}
		}

		$pages[] = array(
			'title'  => $title,
			'url'    => $node->href,
			'parent' => $parent,
		);
	}

	return $this->filter_excluded_pages( $pages );
}

/**
 * Common admin pages used when no menu cache exists yet.
 *
 * @return array<int, array{title: string, url: string, parent: string}>
 */
public function get_fallback_pages() {
	$candidates = array(
		array( 'title' => 'Dashboard', 'url' => admin_url( 'index.php' ), 'parent' => '', 'cap' => 'read' ),
		array( 'title' => 'Posts', 'url' => admin_url( 'edit.php' ), 'parent' => '', 'cap' => 'edit_posts' ),
		array( 'title' => 'Add New Post', 'url' => admin_url( 'post-new.php' ), 'parent' => 'Posts', 'cap' => 'edit_posts' ),
		array( 'title' => 'Media', 'url' => admin_url( 'upload.php' ), 'parent' => '', 'cap' => 'upload_files' ),
		array( 'title' => 'Pages', 'url' => admin_url( 'edit.php?post_type=page' ), 'parent' => '', 'cap' => 'edit_pages' ),
		array( 'title' => 'Add New Page', 'url' => admin_url( 'post-new.php?post_type=page' ), 'parent' => 'Pages', 'cap' => 'edit_pages' ),
		array( 'title' => 'Appearance', 'url' => admin_url( 'themes.php' ), 'parent' => '', 'cap' => 'switch_themes' ),
		array( 'title' => 'Themes', 'url' => admin_url( 'themes.php' ), 'parent' => 'Appearance', 'cap' => 'switch_themes' ),
		array( 'title' => 'Menus', 'url' => admin_url( 'nav-menus.php' ), 'parent' => 'Appearance', 'cap' => 'edit_theme_options' ),
		array( 'title' => 'Plugins', 'url' => admin_url( 'plugins.php' ), 'parent' => '', 'cap' => 'activate_plugins' ),
		array( 'title' => 'Users', 'url' => admin_url( 'users.php' ), 'parent' => '', 'cap' => 'list_users' ),
		array( 'title' => 'Tools', 'url' => admin_url( 'tools.php' ), 'parent' => '', 'cap' => 'manage_options' ),
		array( 'title' => 'Settings', 'url' => admin_url( 'options-general.php' ), 'parent' => '', 'cap' => 'manage_options' ),
		array( 'title' => 'General Settings', 'url' => admin_url( 'options-general.php' ), 'parent' => 'Settings', 'cap' => 'manage_options' ),
		array( 'title' => 'Profile', 'url' => admin_url( 'profile.php' ), 'parent' => '', 'cap' => 'read' ),
		// Divi.
		array( 'title' => 'Divi', 'url' => admin_url( 'admin.php?page=et_divi_options' ), 'parent' => '', 'cap' => 'edit_theme_options' ),
		array( 'title' => 'Theme Options', 'url' => admin_url( 'admin.php?page=et_divi_options' ), 'parent' => 'Divi', 'cap' => 'edit_theme_options' ),
		array( 'title' => 'Theme Builder', 'url' => admin_url( 'admin.php?page=et_theme_builder' ), 'parent' => 'Divi', 'cap' => 'edit_theme_options' ),
		array( 'title' => 'Theme Customizer', 'url' => admin_url( 'customize.php?et_customizer_option_set=theme' ), 'parent' => 'Divi', 'cap' => 'edit_theme_options' ),
		array( 'title' => 'Role Editor', 'url' => admin_url( 'admin.php?page=et_divi_role_editor' ), 'parent' => 'Divi', 'cap' => 'manage_options' ),
		array( 'title' => 'Divi Library', 'url' => admin_url( 'edit.php?post_type=et_pb_layout' ), 'parent' => 'Divi', 'cap' => 'edit_theme_options' ),
	);

	$pages = array();

	foreach ( $candidates as $item ) {
		if ( ! current_user_can( $item['cap'] ) ) {
			continue;
		}

		$pages[] = array(
			'title'  => $item['title'],
			'url'    => $item['url'],
			'parent' => $item['parent'],
		);
	}

	return $pages;
}

/**
 * Build the searchable pages list from $menu / $submenu.
 *
 * @return array<int, array{title: string, url: string, parent: string}>
 */
public function build_pages_from_menu() {
	global $menu, $submenu;

	$pages = array();

	if ( empty( $menu ) || ! is_array( $menu ) ) {
		return $pages;
	}

	foreach ( $menu as $menu_item ) {
		if ( empty( $menu_item[0] ) || empty( $menu_item[2] ) ) {
			continue;
		}

		// Skip separators.
		if ( false !== strpos( $menu_item[2], 'separator' ) ) {
			continue;
		}

		$title = $this->clean_title( $menu_item[0] );
		$url   = $this->resolve_url( $menu_item[2] );
		$slug  = $menu_item[2];

		if ( '' === $title || '' === $url ) {
			continue;
		}

		if ( ! empty( $menu_item[1] ) && ! current_user_can( $menu_item[1] ) ) {
			continue;
		}

		$pages[] = array(
			'title'  => $title,
			'url'    => $url,
			'parent' => '',
		);

		if ( empty( $submenu[ $slug ] ) || ! is_array( $submenu[ $slug ] ) ) {
			continue;
		}

		foreach ( $submenu[ $slug ] as $sub_item ) {
			if ( empty( $sub_item[0] ) || empty( $sub_item[2] ) ) {
				continue;
			}

			if ( ! empty( $sub_item[1] ) && ! current_user_can( $sub_item[1] ) ) {
				continue;
			}

			$sub_title = $this->clean_title( $sub_item[0] );
			$sub_url   = $this->resolve_url( $sub_item[2] );

			if ( '' === $sub_title || '' === $sub_url ) {
				continue;
			}

			$pages[] = array(
				'title'  => $sub_title,
				'url'    => $sub_url,
				'parent' => $title,
			);
		}
	}

	return $pages;
}

/**
 * Strip HTML tags and counts from menu titles.
 *
 * @param string $title Raw menu title.
 * @return string
 */
public function clean_title( $title ) {
	$title = wp_strip_all_tags( $title );
	$title = preg_replace( '/\s*\d+\s*$/', '', $title );
	$title = trim( preg_replace( '/\s+/', ' ', $title ) );

	return is_string( $title ) ? $title : '';
}

/**
 * Resolve a menu slug or path to a full admin URL.
 *
 * @param string $slug Menu slug or relative path.
 * @return string
 */
public function resolve_url( $slug ) {
	if ( empty( $slug ) ) {
		return '';
	}

	if ( 0 === strpos( $slug, 'http://' ) || 0 === strpos( $slug, 'https://' ) ) {
		return $slug;
	}

	if ( false !== strpos( $slug, '.php' ) ) {
		return admin_url( $slug );
	}

	return admin_url( 'admin.php?page=' . $slug );
}

}
