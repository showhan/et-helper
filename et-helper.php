<?php
/**
 * Plugin Name: ET Helper
 * Description: Developer tools for Elegant Themes / Divi — debug controls, SVG support, and the Divi JSON Converter (with CSS/HTML validation).
 * Version:     1.1
 * Author:      Shohan
 * License:     GPL-2.0+
 * Text Domain: et-helper
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'ETH_VERSION',    '2.0.0' );
define( 'ETH_FILE',       __FILE__ );
define( 'ETH_DIR',        plugin_dir_path( __FILE__ ) );
define( 'ETH_URL',        plugin_dir_url( __FILE__ ) );
define( 'ETH_ASSETS_URL', ETH_URL . 'assets/' );
define( 'ETH_INC_DIR',    ETH_DIR . 'includes/' );

// ── Features ──────────────────────────────────────────────────────────────────
require_once ETH_INC_DIR . 'features/class-debug-tools.php';
require_once ETH_INC_DIR . 'features/class-svg-support.php';
require_once ETH_INC_DIR . 'features/class-admin-bar.php';

// ── Divi JSON Converter ───────────────────────────────────────────────────────
require_once ETH_INC_DIR . 'divi-json-converter/class-css-validator.php';
require_once ETH_INC_DIR . 'divi-json-converter/class-html-validator.php';
require_once ETH_INC_DIR . 'divi-json-converter/class-block-parser.php';
require_once ETH_INC_DIR . 'divi-json-converter/class-converter.php';
require_once ETH_INC_DIR . 'divi-json-converter/class-divi-json-converter.php';

// ── Boot ──────────────────────────────────────────────────────────────────────
new ETH_Debug_Tools();
new ETH_SVG_Support();
new ETH_Admin_Bar();
new ETH_Divi_JSON_Converter();
