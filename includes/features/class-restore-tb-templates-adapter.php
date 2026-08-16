<?php
defined( 'ABSPATH' ) || exit;

/**
 * Feature: Restore TB Templates — Admin Menu Adapter
 *
 * Wraps the vendored plugin at
 * includes/vendor/restore-missing-tb-templates/restore-missing-tb-templates.php
 * (github.com/eduard-un/restore-missing-tb-templates).
 *
 * That plugin registers its own "Restore TB Templates" page as a submenu
 * under Divi's top-level "Divi" admin menu (parent slug et_divi_options).
 * To keep the Divi menu clean and surface the tool from ET Helper instead,
 * this adapter:
 *   - visually hides that submenu row from the Divi sidebar menu via CSS
 *   - adds a "Restore TB Templates" node under the "ET Helper" top-level
 *     admin bar menu that links to the same page
 *
 * Note: we deliberately do NOT call remove_submenu_page() here. WordPress's
 * own access check (user_can_access_admin_page(), run by wp-admin/admin.php
 * before rendering any page) re-derives the page's parent by looking it up
 * in the live $submenu global. Removing the entry there leaves WP unable to
 * resolve the correct parent/hook pairing, so the page starts returning
 * "Sorry, you are not allowed to access this page." — even for admins, even
 * via the direct URL. Hiding it with CSS instead leaves the original,
 * correctly-wired registration completely untouched (so the page, its
 * assets, and its admin-post restore/export/revision handlers all keep
 * working), while still getting it off the Divi menu visually.
 *
 * Deliberately does NOT modify the vendored files themselves, so `git
 * subtree pull` updates from upstream stay conflict-free. Any future
 * ET-Helper-specific integration for this feature should go here, not in
 * the vendor copy.
 */
class ETH_Restore_TB_Templates_Adapter {

    const PAGE_SLUG = 'rmtbt';

    public function __construct() {
        add_action( 'admin_head', [ $this, 'hide_from_divi_menu' ] );

        // Runs after ETH_Admin_Bar (priority 90) creates the "et-helper" node.
        add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_node' ], 95 );
    }

    /**
     * Hide the "Restore TB Templates" row from Divi's sidebar submenu.
     * Cosmetic only — the page registration itself is left alone.
     */
    public function hide_from_divi_menu(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        echo '<style>#adminmenu .wp-submenu a[href*="page=' . esc_attr( self::PAGE_SLUG ) . '"]{display:none;}</style>';
    }

    public function add_admin_bar_node( WP_Admin_Bar $bar ): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) return;
        if ( ! $this->is_divi_active() ) return;

        $bar->add_node( [
            'parent' => 'et-helper',
            'id'     => 'et-helper-restore-tb-templates',
            'title'  => 'Restore TB Templates',
            'href'   => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
            'meta'   => [ 'title' => 'Restore TB Templates' ],
        ] );
    }

    private function is_divi_active(): bool {
        $theme = wp_get_theme();
        return 'Divi' === $theme->get( 'Name' ) || 'Divi' === $theme->get( 'Template' );
    }
}
