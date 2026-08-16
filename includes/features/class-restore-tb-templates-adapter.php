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
 * To keep the Divi menu untouched and surface the tool from ET Helper
 * instead, this adapter:
 *   - removes the page from the Divi sidebar menu (removing a submenu entry
 *     only hides it from the sidebar — WordPress still resolves
 *     admin.php?page=rmtbt to the same render callback, and the admin-post
 *     restore/export/revision handlers are untouched, so nothing breaks)
 *   - adds a "Restore TB Templates" node under the "ET Helper" top-level
 *     admin bar menu that links to that same page
 *
 * Deliberately does NOT modify the vendored files themselves, so `git
 * subtree pull` updates from upstream stay conflict-free. Any future
 * ET-Helper-specific integration for this feature should go here, not in
 * the vendor copy.
 */
class ETH_Restore_TB_Templates_Adapter {

    const PAGE_SLUG = 'rmtbt';

    public function __construct() {
        // Runs after RMTBT_Admin::register_menu() (priority 99) has added
        // its submenu under Divi, so there's something to remove.
        add_action( 'admin_menu', [ $this, 'remove_from_divi_menu' ], 100 );

        // Runs after ETH_Admin_Bar (priority 90) creates the "et-helper" node.
        add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_node' ], 95 );
    }

    public function remove_from_divi_menu(): void {
        remove_submenu_page( 'et_divi_options', self::PAGE_SLUG );
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
