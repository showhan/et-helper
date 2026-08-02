<?php
defined( 'ABSPATH' ) || exit;

/**
 * Feature: Admin Bar
 * Registers all ET Helper nodes in the WordPress top admin toolbar.
 */
class ETH_Admin_Bar {

    public function __construct() {
        add_action( 'admin_bar_menu', [ $this, 'register_nodes' ], 90 );
    }

    public function register_nodes( WP_Admin_Bar $bar ): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) return;

        $bar->add_node( [
            'id'    => 'et-helper',
            'title' => 'ET Helper',
            'href'  => false,
            'meta'  => [ 'title' => 'ET Helper' ],
        ] );

        $bar->add_node( [
            'parent' => 'et-helper',
            'id'     => 'et-toggle-display',
            'title'  => ETH_Debug_Tools::is_debug_hidden() ? 'Show Debug Display' : 'Hide Debug Display',
            'href'   => '#',
            'meta'   => [ 'onclick' => 'document.getElementById("et-toggle-form")?.submit();return false;' ],
        ] );

        $bar->add_node( [
            'parent' => 'et-helper',
            'id'     => 'et-delete-log',
            'title'  => 'Delete debug.log' . ETH_Debug_Tools::get_log_size_label(),
            'href'   => '#',
            'meta'   => [ 'onclick' => 'if(confirm("Delete debug.log?"))document.getElementById("et-delete-form")?.submit();return false;' ],
        ] );

        $bar->add_node( [
            'parent' => 'et-helper',
            'id'     => 'et-helper-divi-json-converter',
            'title'  => 'Debug D5 Layout',
            'href'   => admin_url( 'admin.php?page=' . ETH_Divi_JSON_Converter::MENU_SLUG ),
            'meta'   => [ 'title' => 'Debug D5 Layout' ],
        ] );

        $bar->add_node( [
            'parent' => 'et-helper',
            'id'     => 'et-helper-db-reset',
            'title'  => 'Reset & Import',
            'href'   => admin_url( 'tools.php?page=' . ETH_DB_Reset::MENU_SLUG ),
            'meta'   => [ 'title' => 'Reset & Import' ],
        ] );
    }
}