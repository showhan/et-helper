<?php
defined( 'ABSPATH' ) || exit;

/**
 * Feature: Reset Divi Presets — Admin Bar Adapter
 *
 * Wraps the vendored plugin at
 * includes/vendor/reset-divi-presets/reset-divi-presets.php
 * (github.com/eduard-un/reset-divi-presets-and-global-variables).
 *
 * That plugin registers its own top-level "Reset Divi Presets" admin bar
 * node. To keep the toolbar consistent with the rest of ET Helper, this
 * adapter re-parents that node — and everything nested under it (Divi 4/5
 * presets, global variables) — beneath the existing "ET Helper" top-level
 * menu instead.
 *
 * Deliberately does NOT modify the vendored file itself, so `git subtree
 * pull` updates from upstream stay conflict-free. Any future ET-Helper-
 * specific integration for this feature should go here, not in the vendor
 * copy.
 */
class ETH_Reset_Divi_Presets_Adapter {

    public function __construct() {
        // Runs after ETH_Admin_Bar (priority 90) creates the "et-helper"
        // node, and after the vendored plugin (priority 100) creates its
        // own "reset-divi-presets" node.
        add_action( 'admin_bar_menu', [ $this, 'reparent_node' ], 101 );
    }

    public function reparent_node( WP_Admin_Bar $bar ): void {
        $node = $bar->get_node( 'reset-divi-presets' );

        // Vendored plugin may not have rendered anything (e.g. Divi isn't
        // the active theme, or the current user can't manage_options).
        if ( ! $node ) {
            return;
        }

        $bar->add_node( [
            'id'     => $node->id,
            'title'  => $node->title,
            'href'   => $node->href,
            'parent' => 'et-helper',
            'meta'   => $node->meta,
        ] );
    }
}
