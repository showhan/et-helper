<?php
defined( 'ABSPATH' ) || exit;

/**
 * Feature: Debug Tools
 * Toggles PHP debug display on/off and deletes debug.log.
 * Admin-bar items are registered separately in ETH_Admin_Bar.
 */
class ETH_Debug_Tools {

    const OPT_HIDE_DISPLAY = 'et_hide_debug_display';
    const ACTION_TOGGLE    = 'et_toggle_display';
    const ACTION_DELETE    = 'et_delete_log';
    const NONCE_TOGGLE     = 'et_toggle_display_nonce';
    const NONCE_DELETE     = 'et_delete_log_nonce';

    public function __construct() {
        add_action( 'muplugins_loaded',                  [ $this, 'maybe_hide_debug_display' ], 0 );
        add_action( 'admin_footer',                      [ $this, 'output_hidden_forms' ] );
        add_action( 'wp_footer',                         [ $this, 'output_hidden_forms' ] );
        add_action( 'admin_post_' . self::ACTION_TOGGLE, [ $this, 'handle_toggle_display' ] );
        add_action( 'admin_post_' . self::ACTION_DELETE, [ $this, 'handle_delete_log' ] );
        add_action( 'admin_notices',                     [ $this, 'maybe_admin_notices' ] );
        add_action( 'network_admin_notices',             [ $this, 'maybe_admin_notices' ] );
    }

    public function maybe_hide_debug_display(): void {
        if ( ! get_option( self::OPT_HIDE_DISPLAY ) ) return;
        @ini_set( 'display_errors', '0' );
        @ini_set( 'display_startup_errors', '0' );
        error_reporting( 0 );
    }

    public function output_hidden_forms(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) return;
        $post_url    = admin_url( 'admin-post.php' );
        $current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        ?>
        <form id="et-toggle-form" method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:none">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TOGGLE ); ?>">
            <?php wp_nonce_field( self::NONCE_TOGGLE ); ?>
            <input type="hidden" name="_et_ref" value="<?php echo esc_url( $current_url ); ?>">
        </form>
        <form id="et-delete-form" method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:none">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>">
            <?php wp_nonce_field( self::NONCE_DELETE ); ?>
            <input type="hidden" name="_et_ref" value="<?php echo esc_url( $current_url ); ?>">
        </form>
        <?php
    }

    public function handle_toggle_display(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
        check_admin_referer( self::NONCE_TOGGLE );
        $current = (bool) get_option( self::OPT_HIDE_DISPLAY );
        update_option( self::OPT_HIDE_DISPLAY, ! $current, false );
        $this->flash( 'success', $current ? 'Debug display is now visible.' : 'Debug display is now hidden.' );
        $this->redirect_back();
    }

    public function handle_delete_log(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
        check_admin_referer( self::NONCE_DELETE );
        $log = self::get_debug_log_path();
        if ( file_exists( $log ) ) {
            ( is_writable( $log ) && @unlink( $log ) )
                ? $this->flash( 'success', 'debug.log deleted.' )
                : $this->flash( 'error', 'Could not delete debug.log (not writable or failed).' );
        } else {
            $this->flash( 'info', 'No debug.log file found.' );
        }
        $this->redirect_back();
    }

    public function maybe_admin_notices(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $flash = $this->consume_flash();
        if ( ! $flash ) return;
        $class = [ 'success' => 'notice-success', 'info' => 'notice-info', 'error' => 'notice-error' ][ $flash['type'] ] ?? 'notice-info';
        printf( '<div class="notice %s is-dismissible"><p><strong>ET Helper:</strong> %s</p></div>',
            esc_attr( $class ), esc_html( $flash['msg'] ) );
    }

    // ── Static helpers used by ETH_Admin_Bar ──────────────────────────────────

    public static function get_debug_log_path(): string {
        if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && WP_DEBUG_LOG !== '' ) {
            $path = WP_DEBUG_LOG;
            if ( ! preg_match( '#^([a-zA-Z]:)?/#', $path ) ) {
                $path = trailingslashit( ABSPATH ) . ltrim( $path, '/\\' );
            }
            return $path;
        }
        return WP_CONTENT_DIR . '/debug.log';
    }

    public static function is_debug_hidden(): bool {
        return (bool) get_option( self::OPT_HIDE_DISPLAY );
    }

    public static function get_log_size_label(): string {
        $log = self::get_debug_log_path();
        if ( file_exists( $log ) && ( $s = @filesize( $log ) ) !== false ) {
            return ' (' . size_format( $s ) . ')';
        }
        return '';
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function flash_key(): string { return 'et_flash_' . get_current_user_id(); }

    private function flash( string $type, string $msg ): void {
        set_transient( $this->flash_key(), [ 'type' => $type, 'msg' => $msg ], 60 );
    }

    private function consume_flash(): mixed {
        $k = $this->flash_key();
        $v = get_transient( $k );
        if ( $v ) delete_transient( $k );
        return $v;
    }

    private function redirect_back(): void {
        $ref = isset( $_POST['_et_ref'] ) ? esc_url_raw( wp_unslash( $_POST['_et_ref'] ) ) : admin_url();
        wp_safe_redirect( $ref ?: admin_url() );
        exit;
    }
}
