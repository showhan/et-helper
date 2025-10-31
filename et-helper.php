<?php
/**
 * Plugin Name: ET Helper
 * Description: Adds Admin Bar options to show/hide debug display and delete debug.log — clean, no URL params.
 * Version:     1.0.0
 * Author:      Shohan
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ET_Helper {
    const OPT_HIDE_DISPLAY = 'et_hide_debug_display';
    const ACTION_TOGGLE    = 'et_toggle_display';
    const ACTION_DELETE    = 'et_delete_log';
    const NONCE_TOGGLE     = 'et_toggle_display_nonce';
    const NONCE_DELETE     = 'et_delete_log_nonce';

    public function __construct() {
        add_action( 'muplugins_loaded', [ $this, 'maybe_hide_debug_display' ], 0 );
        add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_nodes' ], 90 );
        add_action( 'admin_footer', [ $this, 'output_hidden_forms' ] );
        add_action( 'wp_footer', [ $this, 'output_hidden_forms' ] );
        add_action( 'admin_post_' . self::ACTION_TOGGLE, [ $this, 'handle_toggle_display' ] );
        add_action( 'admin_post_' . self::ACTION_DELETE, [ $this, 'handle_delete_log' ] );
        add_action( 'admin_notices', [ $this, 'maybe_admin_notices' ] );
        add_action( 'network_admin_notices', [ $this, 'maybe_admin_notices' ] );
        add_action( 'init', [ $this, 'svg_boot' ] );

    }

    /** Hide PHP error display if enabled. */
    public function maybe_hide_debug_display() {
        if ( get_option( self::OPT_HIDE_DISPLAY ) ) {
            @ini_set( 'display_errors', '0' );
            @ini_set( 'display_startup_errors', '0' );
            error_reporting( E_ALL & ~E_NOTICE & ~E_USER_NOTICE & ~E_WARNING & ~E_USER_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED );
            error_reporting(0);
        }
    }

    /** Add ET Helper menu to admin bar. */
    public function add_admin_bar_nodes( $wp_admin_bar ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) return;

        $wp_admin_bar->add_node( [
            'id'    => 'et-helper',
            'title' => 'ET Helper',
            'href'  => false,
        ] );

        // Conditional text: Show or Hide Debug Display
        $is_hidden = (bool) get_option( self::OPT_HIDE_DISPLAY );
        $toggle_label = $is_hidden ? 'Show Debug Display' : 'Hide Debug Display';

        // Toggle display
        $wp_admin_bar->add_node( [
            'parent' => 'et-helper',
            'id'     => 'et-toggle-display',
            'title'  => $toggle_label,
            'href'   => '#',
            'meta'   => [ 'onclick' => 'var f=document.getElementById("et-toggle-form"); if(f){f.submit();} return false;' ],
        ] );

        // Delete debug.log
        $log_path = self::get_debug_log_path();
        $title    = 'Delete debug.log';
        if ( file_exists( $log_path ) && ($s = @filesize( $log_path )) !== false ) {
            $title .= ' (' . size_format( $s ) . ')';
        }

        $wp_admin_bar->add_node( [
            'parent' => 'et-helper',
            'id'     => 'et-delete-log',
            'title'  => $title,
            'href'   => '#',
            'meta'   => [ 'onclick' => 'var f=document.getElementById("et-delete-form"); if(f){if(confirm("Delete debug.log?")) f.submit();} return false;' ],
        ] );
    }

    /** Hidden POST forms for actions. */
    public function output_hidden_forms() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) return;
        $post_url = admin_url( 'admin-post.php' );
        $ref      = wp_get_referer();
        ?>
        <form id="et-toggle-form" method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:none">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TOGGLE ); ?>">
            <?php wp_nonce_field( self::NONCE_TOGGLE ); ?>
            <input type="hidden" name="_et_ref" value="<?php echo esc_url( $ref ?: admin_url() ); ?>">
        </form>

        <form id="et-delete-form" method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:none">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>">
            <?php wp_nonce_field( self::NONCE_DELETE ); ?>
            <input type="hidden" name="_et_ref" value="<?php echo esc_url( $ref ?: admin_url() ); ?>">
        </form>
        <?php
    }

    /** Toggle on-screen debug display. */
    public function handle_toggle_display() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Insufficient permissions.' ) );
        check_admin_referer( self::NONCE_TOGGLE );

        $current = (bool) get_option( self::OPT_HIDE_DISPLAY );
        update_option( self::OPT_HIDE_DISPLAY, ! $current, false );

        $msg = $current ? 'Debug display is now visible.' : 'Debug display is now hidden.';
        $this->flash( 'success', $msg );
        $this->redirect_back();
    }

    /** Delete debug.log file. */
    public function handle_delete_log() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Insufficient permissions.' ) );
        check_admin_referer( self::NONCE_DELETE );

        $log = self::get_debug_log_path();
        if ( file_exists( $log ) ) {
            if ( is_writable( $log ) && @unlink( $log ) ) {
                $this->flash( 'success', 'debug.log deleted.' );
            } else {
                $this->flash( 'error', 'Could not delete debug.log (not writable or failed).' );
            }
        } else {
            $this->flash( 'info', 'No debug.log file found.' );
        }

        $this->redirect_back();
    }

    /** Display one-time admin notice. */
    public function maybe_admin_notices() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $flash = $this->consume_flash();
        if ( ! $flash ) return;

        $class = [
            'success' => 'notice-success',
            'info'    => 'notice-info',
            'error'   => 'notice-error',
        ][ $flash['type'] ] ?? 'notice-info';

        printf(
            '<div class="notice %1$s is-dismissible"><p><strong>ET Helper:</strong> %2$s</p></div>',
            esc_attr( $class ),
            esc_html( $flash['msg'] )
        );
    }

    /** Detect debug.log path. */
    public static function get_debug_log_path() {
        if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && WP_DEBUG_LOG !== '' ) {
            $path = WP_DEBUG_LOG;
            if ( ! preg_match( '#^([a-zA-Z]:)?/#', $path ) ) {
                $path = trailingslashit( ABSPATH ) . ltrim( $path, '/\\' );
            }
            return $path;
        }
        return WP_CONTENT_DIR . '/debug.log';
    }

    /** Flash notice helpers. */
    private function flash_key() { return 'et_flash_' . get_current_user_id(); }
    private function flash( $type, $msg, $ttl = 60 ) { set_transient( $this->flash_key(), [ 'type' => $type, 'msg' => $msg ], $ttl ); }
    private function consume_flash() { $k = $this->flash_key(); $v = get_transient( $k ); if ( $v ) delete_transient( $k ); return $v; }

    private function redirect_back() {
        $ref = isset( $_POST['_et_ref'] ) ? esc_url_raw( wp_unslash( $_POST['_et_ref'] ) ) : admin_url();
        wp_safe_redirect( $ref ?: admin_url() );
        exit;
    }


    /** ---------------------------
     * SVG Support (secure-by-default)
     * ----------------------------*/
    public function svg_boot() {
        // Allow only trusted users (change capability if you want editors, etc.)
        add_filter( 'upload_mimes', [ $this, 'allow_svg_mimes' ] );
        add_filter( 'wp_check_filetype_and_ext', [ $this, 'fix_svg_filetype' ], 10, 5 );

        // Validate / sanitize before the file is accepted
        add_filter( 'wp_handle_upload_prefilter', [ $this, 'svg_prefilter_validate' ] );

        // Make SVGs behave like images in Media Library
        add_filter( 'file_is_displayable_image', [ $this, 'mark_svg_displayable' ], 10, 2 );
        add_filter( 'wp_prepare_attachment_for_js', [ $this, 'inject_svg_dimensions_for_js' ], 10, 3 );
    }

    /** Allow .svg and .svgz for trusted users only. */
    public function allow_svg_mimes( $mimes ) {
        if ( current_user_can( 'manage_options' ) ) {
            $mimes['svg']  = 'image/svg+xml';
            $mimes['svgz'] = 'image/svg+xml';
        }
        return $mimes;
    }

    /**
     * Fix filetype detection so WordPress trusts the extension+MIME for SVG.
     * Also prevents non-admins from uploading SVG even if server sniffs it.
     */
    public function fix_svg_filetype( $data, $file, $filename, $mimes, $real_mime = null ) {
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

        if ( in_array( $ext, [ 'svg', 'svgz' ], true ) ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                // Pretend it's unknown for non-admins (blocks upload)
                return [ 'ext' => false, 'type' => false, 'proper_filename' => false ];
            }

            $data['ext']  = 'svg';
            $data['type'] = 'image/svg+xml';
            $data['proper_filename'] = $filename;
        }

        return $data;
    }

    /** Reject risky SVGs before upload is finalized. */
    public function svg_prefilter_validate( $file ) {
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'svg', 'svgz' ], true ) ) {
            return $file;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            $file['error'] = __( 'SVG uploads are restricted to administrators.', 'et-helper' );
            return $file;
        }

        // Read raw
        $contents = @file_get_contents( $file['tmp_name'] );
        if ( $contents === false ) {
            $file['error'] = __( 'Unable to read uploaded SVG.', 'et-helper' );
            return $file;
        }

        // Quick size guard (optional): block >2MB SVGs (tweak as you like)
        if ( filesize( $file['tmp_name'] ) > 2 * 1024 * 1024 ) {
            $file['error'] = __( 'SVG is too large (max 2MB).', 'et-helper' );
            return $file;
        }

        // Normalize for checks
        $check = strtolower( $contents );

        // Disallow common attack vectors
        $bad_patterns = [
            '<script',              // inline scripts
            'onload=', 'onerror=', 'onmouseover=', 'onclick=', // event handlers
            'javascript:',          // js urls
            '<foreignobject',       // can embed HTML
            'feimage',              // external raster refs in filters
            'base64,',              // embedded payloads
            'xlink:href="http:', 'xlink:href=\'http:',
            'xlink:href="https:', 'xlink:href=\'https:',
            'href="http:', 'href=\'http:',
            'href="https:', 'href=\'https:',
            'file:', 'data:text/html', 'document.cookie',
            '<?xml-stylesheet',     // external stylesheets
        ];

        foreach ( $bad_patterns as $needle ) {
            if ( strpos( $check, $needle ) !== false ) {
                $file['error'] = __( 'Blocked potentially unsafe SVG content.', 'et-helper' );
                return $file;
            }
        }

        // Whitelist root SVG element presence
        if ( strpos( $check, '<svg' ) === false ) {
            $file['error'] = __( 'Invalid SVG file.', 'et-helper' );
            return $file;
        }

        // Optional: strip XML declaration and DOCTYPE to reduce parser quirks
        $clean = preg_replace( '#^\s*(<\?xml[^>]*>\s*)?#i', '', $contents );
        $clean = preg_replace( '#<!DOCTYPE[^>]*>#i', '', $clean );

        if ( is_string( $clean ) && $clean !== $contents ) {
            // Overwrite tmp with cleaned version
            @file_put_contents( $file['tmp_name'], $clean );
        }

        return $file;
    }

    /** Tell WP that SVGs are displayable images so thumbnails/previews work. */
    public function mark_svg_displayable( $result, $path ) {
        if ( $result ) return true;
        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, [ 'svg', 'svgz' ], true ) ) {
            return true;
        }
        return $result;
    }

    /**
     * Provide width/height for the media modal & attachment response.
     * Tries to read viewBox; falls back to 512x512 if unknown.
     */
    public function inject_svg_dimensions_for_js( $response, $attachment, $meta ) {
        if ( 'image/svg+xml' !== get_post_mime_type( $attachment ) ) {
            return $response;
        }

        $file = get_attached_file( $attachment->ID );
        if ( $file && file_exists( $file ) ) {
            $svg = @file_get_contents( $file );
            if ( $svg && preg_match( '/viewBox="([\d\.\s\-]+)"/i', $svg, $m ) ) {
                $parts = preg_split( '/\s+/', trim( $m[1] ) );
                if ( count( $parts ) === 4 ) {
                    $w = (float) $parts[2];
                    $h = (float) $parts[3];
                    if ( $w > 0 && $h > 0 ) {
                        $response['width']  = (int) round( $w );
                        $response['height'] = (int) round( $h );
                    }
                }
            }
        }

        // Fallback if missing
        if ( empty( $response['width'] ) || empty( $response['height'] ) ) {
            $response['width']  = 512;
            $response['height'] = 512;
        }

        // Ensure sizes & icon are present
        $response['sizes'] = $response['sizes'] ?? [];
        $response['icon']  = $response['url']; // let browser render the SVG

        return $response;
    }






}

new ET_Helper();

/**
 * ET Helper add-on: registers the "Divi JSON Parser" submenu under ET Helper.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/includes/class-divi-json-parser.php';

/**
 * Register submenu under the existing ET Helper top-level menu.
 * This does NOT create a new top-level menu, preserving the base plugin's structure and SVG support code.
 */
add_action('admin_menu', function() {
    // Create singleton instance for the parser
    static $et_helper_djp = null;
    if ($et_helper_djp === null) {
        $et_helper_djp = new ET_Helper_Divi_JSON_Parser();
    }

    add_submenu_page(
        'et-helper', // parent slug from the base ET Helper plugin
        __('Divi JSON Parser','et-helper'),
        __('Divi JSON Parser','et-helper'),
        'manage_options',
        ET_Helper_Divi_JSON_Parser::MENU_SLUG,
        [$et_helper_djp, 'render_page']
    );
}, 30);

/**
 * Ensure upload/convert/download handlers are available.
 */
add_action('plugins_loaded', function() {
    static $djp_boot = null;
    if ($djp_boot === null) {
        $djp_boot = new ET_Helper_Divi_JSON_Parser();
    }
});

/**
 * Add Admin Bar (top toolbar) items for ET Helper → Divi JSON Parser.
 */
add_action('admin_bar_menu', function($wp_admin_bar) {
    if ( ! is_admin_bar_showing() || ! current_user_can('manage_options') ) return;

    // Ensure a parent "ET Helper" node exists
    if ( ! $wp_admin_bar->get_node('et-helper') ) {
        $wp_admin_bar->add_node([
            'id'    => 'et-helper',
            'title' => __('ET Helper','et-helper'),
            'href'  => false, // acts as a parent container
        ]);
    }

    // Add the child link to the parser
    $wp_admin_bar->add_node([
        'id'     => 'et-helper-divi-json-parser',
        'parent' => 'et-helper',
        'title'  => __('Divi JSON Parser','et-helper'),
        'href'   => admin_url('admin.php?page=' . ET_Helper_Divi_JSON_Parser::MENU_SLUG),
        'meta'   => ['title' => __('Divi JSON Parser','et-helper')],
    ]);
}, 100);
