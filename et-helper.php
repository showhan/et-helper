<?php
/**
 * Plugin Name: ET Helper
 * Description: Developer tools for Elegant Themes / Divi — debug controls, SVG support, the Divi JSON Converter (with CSS/HTML validation), and a QA database reset tool.
 * Version:     1.3
 * Author:      Shohan
 * License:     GPL-2.0+
 * Text Domain: et-helper
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'ETH_VERSION',    '2.1.0' );
define( 'ETH_FILE',       __FILE__ );
define( 'ETH_DIR',        plugin_dir_path( __FILE__ ) );
define( 'ETH_URL',        plugin_dir_url( __FILE__ ) );
define( 'ETH_ASSETS_URL', ETH_URL . 'assets/' );
define( 'ETH_INC_DIR',    ETH_DIR . 'includes/' );

// ── Features ──────────────────────────────────────────────────────────────────
require_once ETH_INC_DIR . 'features/class-debug-tools.php';
require_once ETH_INC_DIR . 'features/class-svg-support.php';
require_once ETH_INC_DIR . 'features/class-sql-splitter.php';
require_once ETH_INC_DIR . 'features/class-sql-domain-rewriter.php';
require_once ETH_INC_DIR . 'features/class-db-backup.php';
require_once ETH_INC_DIR . 'features/class-db-reset.php';
require_once ETH_INC_DIR . 'features/class-admin-bar.php';

// ── Divi JSON Converter ───────────────────────────────────────────────────────
require_once ETH_INC_DIR . 'divi-json-converter/class-css-validator.php';
require_once ETH_INC_DIR . 'divi-json-converter/class-html-validator.php';
require_once ETH_INC_DIR . 'divi-json-converter/class-block-parser.php';
require_once ETH_INC_DIR . 'divi-json-converter/class-converter.php';
require_once ETH_INC_DIR . 'divi-json-converter/class-divi-json-converter.php';

// ── Vendored: Reset Divi Presets & Global Variables ─────────────────────────────
// Source: github.com/eduard-un/reset-divi-presets-and-global-variables
// Pulled in via `git subtree` — do not hand-edit the vendored file; ET Helper-
// specific integration (admin bar placement) lives in the adapter class below.
// Pull upstream updates with:
//   git fetch reset-divi-presets
//   git subtree pull --prefix=includes/vendor/reset-divi-presets reset-divi-presets main --squash
require_once ETH_INC_DIR . 'vendor/reset-divi-presets/reset-divi-presets.php';
require_once ETH_INC_DIR . 'features/class-reset-divi-presets-adapter.php';

// ── Vendored: Restore Missing TB Templates ──────────────────────────────────────
// Source: github.com/eduard-un/restore-missing-tb-templates
// Pulled in via `git subtree` — do not hand-edit the vendored file; ET Helper-
// specific integration (menu placement) lives in the adapter class below.
// Pull upstream updates with:
//   git fetch restore-missing-tb-templates
//   git subtree pull --prefix=includes/vendor/restore-missing-tb-templates restore-missing-tb-templates main --squash
require_once ETH_INC_DIR . 'vendor/restore-missing-tb-templates/restore-missing-tb-templates.php';
require_once ETH_INC_DIR . 'features/class-restore-tb-templates-adapter.php';

// ── Activation ────────────────────────────────────────────────────────────────
// Note: has no effect when this plugin is loaded from mu-plugins/, since WordPress
// never fires activation hooks for must-use plugins. In that setup, ETH_DB_Reset's
// own admin_init check is what actually triggers the background URL regeneration.
register_activation_hook( __FILE__, [ 'ETH_DB_Reset', 'on_plugin_activation' ] );

// ── Boot ──────────────────────────────────────────────────────────────────────
new ETH_Debug_Tools();
new ETH_SVG_Support();
new ETH_DB_Reset();
new ETH_Admin_Bar();
new ETH_Divi_JSON_Converter();
// Note: the vendored Reset_Divi_Presets class self-boots at the bottom of its
// own file (reset_divi_presets_run()), so it isn't instantiated here.
new ETH_Reset_Divi_Presets_Adapter();
// Note: the vendored RMTBT_Admin class self-boots via its own plugins_loaded
// hook (inside restore-missing-tb-templates.php), so it isn't instantiated here.
new ETH_Restore_TB_Templates_Adapter();