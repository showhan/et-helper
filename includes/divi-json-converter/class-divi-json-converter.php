<?php
defined( 'ABSPATH' ) || exit;

/**
 * Divi JSON Converter — Page Controller
 */
class ETH_Divi_JSON_Converter {

    const MENU_SLUG   = 'eth-divi-json-converter';
    const AJAX_ACTION = 'eth_djc_convert';

    public function __construct() {
        add_action( 'admin_menu',                   [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',        [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle_ajax' ] );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        // Register the page with null parent — accessible via direct URL and
        // the admin bar link, but never shown in the left sidebar menu.
        add_submenu_page(
            'tools.php',
            __( 'Debug D5 Layout', 'et-helper' ),
            __( 'Debug D5 Layout', 'et-helper' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'tools_page_' . self::MENU_SLUG ) return;
        wp_enqueue_style( 'eth-djc', ETH_ASSETS_URL . 'css/divi-json-converter.css', [], ETH_VERSION );
        wp_enqueue_script( 'eth-djc', ETH_ASSETS_URL . 'js/divi-json-converter.js', [], ETH_VERSION, true );
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    public function handle_ajax(): void {
        if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
            wp_send_json_error( 'Security check failed. Please refresh the page and try again.' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        if ( empty( $_FILES['file']['tmp_name'] ) ) {
            wp_send_json_error( 'No file received. Please select a JSON file.' );
        }

        $file = $_FILES['file'];

        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( 'Upload failed with error code: ' . $file['error'] );
        }

        if ( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'json' ) {
            wp_send_json_error( 'Only .json files are accepted.' );
        }

        if ( $file['size'] > 50 * 1024 * 1024 ) {
            wp_send_json_error( 'File exceeds the 50 MB limit.' );
        }

        $raw = file_get_contents( $file['tmp_name'] );
        if ( $raw === false || $raw === '' ) {
            wp_send_json_error( 'Could not read the uploaded file.' );
        }

        try {
            $json = ETH_Converter::convert( $raw, ! empty( $_POST['remove_images'] ) );
            wp_send_json_success( [ 'json' => $json ] );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Conversion failed: ' . $e->getMessage() );
        }
    }

    // ── Page ──────────────────────────────────────────────────────────────────

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        // Nonce embedded directly in page — no dependency on wp_localize_script
        $nonce    = wp_create_nonce( self::AJAX_ACTION );
        $ajax_url = admin_url( 'admin-ajax.php' );
        ?>
        <div class="wrap eth-djc-wrap">

            <?php /* Inline config — always available before the JS runs */ ?>
            <script>
                window.ETH_DJC = {
                    ajax_url: <?php echo json_encode( $ajax_url ); ?>,
                    nonce:    <?php echo json_encode( $nonce ); ?>,
                    action:   <?php echo json_encode( self::AJAX_ACTION ); ?>
                };
            </script>

            <div class="eth-djc-header">
                <span class="dashicons dashicons-editor-code eth-djc-header-icon"></span>
                <div>
                    <h1>Debug D5 Layout</h1>
                    <p class="eth-djc-subtitle">Convert a raw Divi layout export into a fully nested, indexed JSON — with CSS extraction, CSS validation, and HTML validation.</p>
                </div>
            </div>

            <!-- Upload Card -->
            <div class="eth-djc-card" id="eth-djc-upload-card">
                <h2>Upload Layout JSON</h2>
                <p>Export your layout from the Divi Builder <em>(Portability &rarr; Export)</em>, then upload the <code>.json</code> file below.</p>

                <div class="eth-djc-dropzone" id="eth-djc-dropzone">
                    <span class="dashicons dashicons-upload eth-djc-upload-icon"></span>
                    <p class="eth-djc-dz-label">Drag &amp; drop your JSON file here, or <span class="eth-djc-browse-link">browse file</span></p>
                    <p class="eth-djc-dz-hint">Accepts .json files &middot; up to 50 MB</p>
                    <input type="file" id="eth-djc-file-input" accept=".json" />
                    <div class="eth-djc-file-info" id="eth-djc-file-info">
                        <span class="dashicons dashicons-media-code"></span>
                        <span id="eth-djc-file-name"></span>
                        <button type="button" id="eth-djc-clear-file" title="Remove">&#x2715;</button>
                    </div>
                </div>

                <div class="eth-djc-options">
                    <label class="eth-djc-opt-label">
                        <input type="checkbox" id="eth-djc-remove-images" checked />
                        Remove <code>"images"</code> object from output
                    </label>
                </div>

                <button class="eth-djc-btn eth-djc-btn--primary" id="eth-djc-convert-btn" disabled>
                    <span class="dashicons dashicons-controls-repeat"></span> Convert
                </button>

                <div class="eth-djc-progress" id="eth-djc-progress">
                    <div class="eth-djc-progress-track">
                        <div class="eth-djc-progress-bar" id="eth-djc-progress-bar"></div>
                    </div>
                    <span id="eth-djc-progress-label">Processing&hellip;</span>
                </div>

                <div class="eth-djc-notice eth-djc-notice--error" id="eth-djc-error"></div>
            </div>

            <!-- Result Card -->
            <div class="eth-djc-card eth-djc-result-card" id="eth-djc-result-card">

                <div class="eth-djc-result-header">
                    <div class="eth-djc-result-title">
                        <span class="dashicons dashicons-yes-alt eth-djc-ok-icon"></span>
                        <h2>Conversion Complete</h2>
                    </div>
                    <div class="eth-djc-result-actions">
                        <button class="eth-djc-btn eth-djc-btn--ghost" id="eth-djc-copy-btn">
                            <span class="dashicons dashicons-clipboard"></span> Copy JSON
                        </button>
                        <button class="eth-djc-btn eth-djc-btn--primary" id="eth-djc-download-btn">
                            <span class="dashicons dashicons-download"></span> Download JSON
                        </button>
                        <button class="eth-djc-btn eth-djc-btn--ghost" id="eth-djc-reset-btn">
                            <span class="dashicons dashicons-update"></span> Convert Another
                        </button>
                    </div>
                </div>

                <div class="eth-djc-stats" id="eth-djc-stats"></div>

                <div class="eth-djc-tabs">
                    <button class="eth-djc-tab active" data-tab="full">
                        <span class="dashicons dashicons-editor-code"></span> Full JSON
                    </button>
                    <button class="eth-djc-tab" data-tab="freeform">
                        <span class="dashicons dashicons-editor-paste-text"></span>
                        Free Form CSS <span class="eth-djc-tab-badge" id="eth-djc-badge-freeform">0</span>
                    </button>
                    <button class="eth-djc-tab" data-tab="element">
                        <span class="dashicons dashicons-art"></span>
                        Element CSS <span class="eth-djc-tab-badge" id="eth-djc-badge-element">0</span>
                    </button>
                    <button class="eth-djc-tab" data-tab="invalid-css">
                        <span class="dashicons dashicons-warning"></span>
                        Invalid CSS <span class="eth-djc-tab-badge eth-djc-badge--error" id="eth-djc-badge-invalid-css">0</span>
                    </button>
                    <button class="eth-djc-tab" data-tab="invalid-html">
                        <span class="dashicons dashicons-warning"></span>
                        Invalid HTML <span class="eth-djc-tab-badge eth-djc-badge--error" id="eth-djc-badge-invalid-html">0</span>
                    </button>
                </div>

                <div class="eth-djc-tab-panel" id="eth-djc-panel-full">
                    <div class="eth-djc-toolbar">
                        <span class="eth-djc-toolbar-label">Preview</span>
                        <div class="eth-djc-toolbar-right">
                            <button class="eth-djc-tbtn" id="eth-djc-expand-all">Expand all</button>
                            <button class="eth-djc-tbtn" id="eth-djc-collapse-all">Collapse all</button>
                            <div class="eth-djc-search-wrap">
                                <span class="dashicons dashicons-search"></span>
                                <input type="text" id="eth-djc-search" placeholder="Search keys / values&hellip;" />
                            </div>
                        </div>
                    </div>
                    <div class="eth-djc-preview-outer" id="eth-djc-preview-outer">
                        <div class="eth-djc-preview-inner" id="eth-djc-preview"></div>
                    </div>
                </div>

                <div class="eth-djc-tab-panel" id="eth-djc-panel-freeform" style="display:none">
                    <div class="eth-djc-css-intro"><span class="dashicons dashicons-info-outline"></span>
                    <p><strong>Free Form CSS</strong> — custom CSS from Divi's "Custom CSS &rarr; Free Form" field.</p></div>
                    <div id="eth-djc-freeform-list" class="eth-djc-css-list"></div>
                </div>

                <div class="eth-djc-tab-panel" id="eth-djc-panel-element" style="display:none">
                    <div class="eth-djc-css-intro"><span class="dashicons dashicons-info-outline"></span>
                    <p><strong>Element CSS</strong> — styles applied to sub-elements (e.g. <code>mainElement</code>, <code>blurbImage</code>).</p></div>
                    <div id="eth-djc-element-list" class="eth-djc-css-list"></div>
                </div>

                <div class="eth-djc-tab-panel" id="eth-djc-panel-invalid-css" style="display:none">
                    <div class="eth-djc-css-intro eth-djc-css-intro--warn"><span class="dashicons dashicons-warning"></span>
                    <p><strong>Invalid CSS</strong> detected across Code Modules, Free Form CSS, and Element CSS.</p></div>
                    <div id="eth-djc-invalid-css-list" class="eth-djc-css-list"></div>
                </div>

                <div class="eth-djc-tab-panel" id="eth-djc-panel-invalid-html" style="display:none">
                    <div class="eth-djc-css-intro eth-djc-css-intro--warn"><span class="dashicons dashicons-warning"></span>
                    <p><strong>Invalid HTML</strong> detected in text, blurb, button, number-counter, icon-list-item, contact-field, and image modules.</p></div>
                    <div id="eth-djc-invalid-html-list" class="eth-djc-css-list"></div>
                </div>

            </div><!-- /.eth-djc-result-card -->
        </div><!-- /.eth-djc-wrap -->
        <?php
    }
}
