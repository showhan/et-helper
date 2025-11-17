<?php
/**
 * Description: Convert embedded Divi block objects inside WP block strings into a merged JSON, and output raw CSS from any `css` subtrees.
 */

if (!defined('ABSPATH')) exit;

class ET_Helper_Divi_JSON_Parser {
    const MENU_SLUG = 'et-helper-divi-json-parser';

    public function __construct() {
        add_action('admin_init', [$this, 'handle_upload']);
        add_action('admin_post_djc_download_json', [$this, 'handle_download']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function add_menu() {
        add_management_page(
            'Divi JSON Parser',
            'Divi JSON Parser',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    private function allowed_mime_types() {
        return [
            'application/json',
            'text/plain',
            'text/json',
            'application/octet-stream',
        ];
    }

    public function handle_upload() {
        if (
            !isset($_POST['djc_submit']) ||
            !isset($_POST['djc_nonce']) ||
            !wp_verify_nonce($_POST['djc_nonce'], 'djc_upload')
        ) return;

        if (!current_user_can('manage_options')) return;

        $result = [
            'error'       => '',
            'merged_json' => '',
            'css_text'    => '',
            'filename'    => 'divi-converted.json',
            'stats'       => [],
        ];

        if (!isset($_FILES['djc_file']) || empty($_FILES['djc_file']['tmp_name'])) {
            $result['error'] = 'Please upload a file.';
            set_transient('djc_result', $result, 180);
            return;
        }

        $file     = $_FILES['djc_file'];
        $mime     = $file['type'] ?? '';
        $allowed  = $this->allowed_mime_types();
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($mime, $allowed, true) && !in_array($ext, ['json','txt'], true)) {
            $result['error'] = 'Invalid file type. Please upload a .json or .txt file.';
            set_transient('djc_result', $result, 180);
            return;
        }

        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false || $raw === '') {
            $result['error'] = 'Could not read file contents.';
            set_transient('djc_result', $result, 180);
            return;
        }

        [$merged_json, $stats, $css_tree] = $this->convert_with_retries($raw);
        if ($merged_json === null) {
            $result['error'] = 'No Divi blocks found or JSON could not be parsed.';
            set_transient('djc_result', $result, 180);
            return;
        }

        $css_text = $this->css_tree_to_css_text($css_tree);

        $result['merged_json'] = $merged_json;
        $result['css_text']    = $css_text;
        $result['filename']    = 'divi-converted-' . date('Ymd-His') . '.json';
        $result['stats']       = $stats;

        set_transient('djc_result', $result, 180);
        wp_redirect(add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php')));
        exit;
    }

    /**
     * Convert with retries for escaped content.
     * @return array{0:?string,1:array,2:array} [merged_json|null, stats, css_tree]
     */
    private function convert_with_retries(string $raw): array {
        $stats = ['attempts' => [], 'counts' => [], 'css_counts' => []];
        $css_tree = [];

        // Attempt 1: raw
        [$json, $stats, $css_tree] = $this->convert_once($raw, $stats);
        if ($json !== null) return [$json, $stats, $css_tree];

        // Attempt 2: entire file is a quoted JSON string
        $decoded = json_decode($raw, true);
        if (is_string($decoded)) {
            $stats['attempts'][] = 'decoded_entire_file_as_string';
            [$json, $stats, $css_tree] = $this->convert_once($decoded, $stats);
            if ($json !== null) return [$json, $stats, $css_tree];
        }

        // Attempt 3: unescape via JSON wrapper
        $stats['attempts'][] = 'unescaped_with_json_wrapper';
        $unescaped = $this->djc_unescape($raw);
        if ($unescaped !== $raw) {
            [$json, $stats, $css_tree] = $this->convert_once($unescaped, $stats);
            if ($json !== null) return [$json, $stats, $css_tree];
        }

        // Attempt 4: stripcslashes fallback
        $stats['attempts'][] = 'stripcslashes_fallback';
        $fallback = stripcslashes($raw);
        if ($fallback !== $raw) {
            [$json, $stats, $css_tree] = $this->convert_once($fallback, $stats);
            if ($json !== null) return [$json, $stats, $css_tree];
        }

        return [null, $stats, []];
    }

    /**
     * Single conversion pass. Returns [json_string|null, stats, css_tree]
     * Output JSON: { blocks: {...|[...]}, css: {...|[...]} }
     */
    private function convert_once(string $text, array $stats): array {
        $pattern = '/<!--\s*wp:divi\/([\w\-]+)\s+(\{.*?\})\s*(?:\/)?-->/s';
        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [null, $stats, []];
        }

        $blocks = [];
        $cssMap = [];
        $counts = [];
        $css_counts = [];

        foreach ($matches as $m) {
            $type_raw = trim($m[1]);
            $json_raw = trim($m[2]);

            $key = 'divi_' . str_replace('-', '_', strtolower($type_raw));
            $decoded = json_decode($json_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) continue;

            // Split css subtree
            $cssSubtree = null;
            if (array_key_exists('css', $decoded)) {
                $cssSubtree = $decoded['css'];
                unset($decoded['css']);
            }

            if (!isset($blocks[$key])) { $blocks[$key] = []; $counts[$key] = 0; }
            $blocks[$key][] = $decoded; $counts[$key]++;

            if ($cssSubtree !== null) {
                if (!isset($cssMap[$key])) { $cssMap[$key] = []; $css_counts[$key] = 0; }
                $cssMap[$key][] = $cssSubtree; $css_counts[$key]++;
            }
        }

        $stats['counts'] = $counts;
        $stats['css_counts'] = $css_counts;

        if (empty($blocks) && empty($cssMap)) {
            return [null, $stats, []];
        }

        // Collapse common keys to single object if only one instance exists
        $prefer_single = ['divi_section','divi_row','divi_column','divi_blurb'];
        foreach ($prefer_single as $k) {
            if (isset($blocks[$k]) && count($blocks[$k]) === 1) $blocks[$k] = $blocks[$k][0];
            if (isset($cssMap[$k]) && count($cssMap[$k]) === 1)   $cssMap[$k] = $cssMap[$k][0];
        }

        $final = [
            'blocks' => $blocks,
            'css'    => $cssMap,
        ];

        $merged_json = json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return [$merged_json, $stats, $cssMap];
    }

    /**
     * Convert the decoded CSS tree into normal CSS text.
     */
    private function css_tree_to_css_text(array $css_tree): string {
        if (empty($css_tree)) return '';

        $out = [];

        foreach ($css_tree as $typeKey => $entries) {
            $instances = $this->is_assoc($entries) ? [$entries] : $entries;
            $parent_class = '.' . $typeKey;

            foreach ($instances as $idx => $inst) {
                if (!is_array($inst)) continue;

                foreach ($inst as $breakpoint => $node) {
                    if (!is_array($node)) continue;

                    // Allow either { value: {...} } or direct {...}
                    $value = isset($node['value']) && is_array($node['value']) ? $node['value'] : $node;
                    if (!is_array($value)) continue;

                    $out[] = sprintf("/* %s [%d] | %s */", $typeKey, $idx + 1, $breakpoint);

                    foreach ($value as $selectorKey => $declString) {
                        if (!is_string($declString)) continue;

                        $selector = $this->normalize_selector($selectorKey);
                        $decls = $this->normalize_declarations($declString);
                        if ($decls === '') continue;

                        $out[] = sprintf("%s %s { %s }", $parent_class, $selector, $decls);
                    }

                    $out[] = ""; // blank line after breakpoint block
                }
            }
        }

        return trim(implode("\n", $out));
    }

    private function normalize_selector(string $sel): string {
        $s = trim($sel);
        if ($s === '') return '.unknown';

        // If it already starts with '.', '#', '[' treat as a real selector
        $first = substr($s, 0, 1);
        if ($first === '.' || $first === '#' || $first === '[') return $s;

        // If it's a single word (no spaces, no combinators), make it a class
        if (strpos($s, ' ') === false && strpos($s, '>') === false && strpos($s, '+') === false && strpos($s, '~') === false && strpos($s, ',') === false) {
            // If it looks like a tag name (letters/digits/_- only) we could leave it as tag,
            // but to keep specificity low and predictable, prefix as class:
            return '.' . $s;
        }

        // Otherwise assume it's a compound selector already (e.g., h2 strong)
        return $s;
    }

    private function normalize_declarations(string $raw): string {
        // Convert newlines to spaces, split by ';'
        $flat = preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $raw));
        $parts = array_filter(array_map('trim', explode(';', $flat)));
        $decls = [];
        foreach ($parts as $p) {
            if ($p === '') continue;
            if (strpos($p, ':') === false) continue; // ensure has colon
            list($prop, $val) = array_map('trim', explode(':', $p, 2));
            if ($prop === '' || $val === '') continue;
            // basic sanitization for prop names
            $prop = preg_replace('/[^a-zA-Z0-9_-]/', '-', $prop);
            $decls[] = "{$prop}: {$val};";
        }
        return implode(' ', $decls);
    }

    private function djc_unescape(string $raw): string {
        if (strpos($raw, '\\"') === false && strpos($raw, '\\n') === false && strpos($raw, '\\t') === false) {
            return $raw;
        }
        $wrapped = '"' . addcslashes($raw, "\\\"") . '"';
        $decoded = json_decode($wrapped, true);
        return (is_string($decoded)) ? $decoded : $raw;
    }

    private function is_assoc($arr): bool {
        if (!is_array($arr)) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    public function enqueue_admin_assets($hook) {
        if ('tools_page_' . self::MENU_SLUG !== $hook) {
            return;
        }

        $plugin_dir = plugin_dir_path(dirname(__FILE__));
        $plugin_url = plugin_dir_url(dirname(__FILE__));

        // CodeMirror CSS
        wp_enqueue_style('codemirror-css', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css');
        wp_enqueue_style('codemirror-theme-monokai', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css');

        // Custom admin styles (only if file exists)
        $css_file = $plugin_dir . 'assets/css/admin-styles.css';
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'et-helper-admin-styles',
                $plugin_url . 'assets/css/admin-styles.css',
                [],
                filemtime($css_file)
            );
        }
    }

    public function render_page() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');

        $result = get_transient('djc_result');
        delete_transient('djc_result');
        ?>
        <div class="wrap">
            <h1>Divi JSON Parser</h1>
            <p>Upload a file containing your WP block string (with <code>&lt;!-- wp:divi/... { ... } --&gt;</code>). You’ll get:</p>
            <ol>
                <li><strong>Only CSS</strong> (normal CSS rules)</li>
                <li><strong>Full merged JSON</strong> (<code>{ blocks, css }</code>)</li>
            </ol>

            <form method="post" enctype="multipart/form-data" class="djc-form">
                <?php wp_nonce_field('djc_upload', 'djc_nonce'); ?>
                <input type="file" name="djc_file" accept=".json,.txt" required />
                <p class="submit">
                    <button type="submit" name="djc_submit" class="button button-primary">Convert</button>
                </p>
            </form>

            <?php if ($result): ?>
                <?php if (!empty($result['error'])):
?>
                    <div class="notice notice-error"><p><strong>Error:</strong> <?php echo esc_html($result['error']); ?></p></div>
                <?php else: ?>
                    <div class="djc-results-container">
                        <div class="djc-success-message">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <strong>Conversion successful!</strong>
                        </div>

                        <div class="djc-result-block">
                            <div class="djc-result-header">
                                <h2>Only CSS</h2>
                                <div class="djc-actions">
                                    <button type="button" class="button djc-copy-btn" data-target="css">
                                        <span class="dashicons dashicons-clipboard"></span> Copy
                                    </button>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                        <?php
                                        $download_key_css = 'djc_download_' . wp_generate_password(12, false);
                                        set_transient($download_key_css, $result['css_text'], 180);
                                        ?>
                                        <input type="hidden" name="action" value="djc_download_json">
                                        <input type="hidden" name="key" value="<?php echo esc_attr($download_key_css); ?>">
                                        <input type="hidden" name="filename" value="<?php echo esc_attr(str_replace('.json', '.css', $result['filename'])); ?>">
                                        <button type="submit" class="button djc-download-btn">
                                            <span class="dashicons dashicons-download"></span> Download
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div id="djc-css-output"></div>
                        </div>

                        <div class="djc-result-block">
                            <div class="djc-result-header">
                                <h2>Full merged JSON</h2>
                                <div class="djc-actions">
                                    <button type="button" class="button djc-copy-btn" data-target="json">
                                        <span class="dashicons dashicons-clipboard"></span> Copy
                                    </button>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                        <?php
                                        $download_key_full = 'djc_download_' . wp_generate_password(12, false);
                                        set_transient($download_key_full, $result['merged_json'], 180);
                                        ?>
                                        <input type="hidden" name="action" value="djc_download_json">
                                        <input type="hidden" name="key" value="<?php echo esc_attr($download_key_full); ?>">
                                        <input type="hidden" name="filename" value="<?php echo esc_attr($result['filename']); ?>">
                                        <button type="submit" class="button djc-download-btn">
                                            <span class="dashicons dashicons-download"></span> Download
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div id="djc-json-output"></div>
                        </div>
                    </div>

                    <!-- Load CodeMirror CSS -->
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css">
                    
                    <!-- Load CodeMirror Scripts -->
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/css/css.min.js"></script>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
                    
                    <script>
                        (function() {
                            var cssContent = <?php echo json_encode($result['css_text']); ?>;
                            var jsonContent = <?php echo json_encode($result['merged_json']); ?>;
                            
                            function initCodeMirror() {
                                if (typeof CodeMirror === 'undefined') {
                                    console.error('CodeMirror is not loaded');
                                    return;
                                }
                                
                                var cssDiv = document.getElementById('djc-css-output');
                                var jsonDiv = document.getElementById('djc-json-output');
                                
                                var cssEditor, jsonEditor;
                                
                                if (cssDiv) {
                                    cssEditor = CodeMirror(cssDiv, {
                                        value: cssContent,
                                        lineNumbers: true,
                                        mode: 'css',
                                        theme: 'monokai',
                                        readOnly: true,
                                        viewportMargin: Infinity,
                                        lineWrapping: true,
                                    });
                                }
                                
                                if (jsonDiv) {
                                    jsonEditor = CodeMirror(jsonDiv, {
                                        value: jsonContent,
                                        lineNumbers: true,
                                        mode: { name: 'javascript', json: true },
                                        theme: 'monokai',
                                        readOnly: true,
                                        viewportMargin: Infinity,
                                        lineWrapping: true,
                                    });
                                }
                                
                                // Copy to clipboard fallback function
                                function copyToClipboard(text) {
                                    // Try modern clipboard API first
                                    if (navigator.clipboard && navigator.clipboard.writeText) {
                                        return navigator.clipboard.writeText(text);
                                    }
                                    
                                    // Fallback for older browsers or non-HTTPS
                                    return new Promise(function(resolve, reject) {
                                        var textarea = document.createElement('textarea');
                                        textarea.value = text;
                                        textarea.style.position = 'fixed';
                                        textarea.style.opacity = '0';
                                        document.body.appendChild(textarea);
                                        textarea.focus();
                                        textarea.select();
                                        
                                        try {
                                            var successful = document.execCommand('copy');
                                            document.body.removeChild(textarea);
                                            if (successful) {
                                                resolve();
                                            } else {
                                                reject(new Error('Copy command failed'));
                                            }
                                        } catch (err) {
                                            document.body.removeChild(textarea);
                                            reject(err);
                                        }
                                    });
                                }
                                
                                // Add copy button functionality
                                document.querySelectorAll('.djc-copy-btn').forEach(function(btn) {
                                    btn.addEventListener('click', function() {
                                        var target = this.getAttribute('data-target');
                                        var content = target === 'css' ? cssContent : jsonContent;
                                        
                                        copyToClipboard(content).then(function() {
                                            var originalText = btn.innerHTML;
                                            btn.innerHTML = '<span class="dashicons dashicons-yes"></span> Copied!';
                                            btn.classList.add('djc-copied');
                                            
                                            setTimeout(function() {
                                                btn.innerHTML = originalText;
                                                btn.classList.remove('djc-copied');
                                            }, 2000);
                                        }).catch(function(err) {
                                            console.error('Failed to copy:', err);
                                            alert('Failed to copy to clipboard. Please copy manually.');
                                        });
                                    });
                                });
                            }
                            
                            // Initialize when DOM is ready
                            if (document.readyState === 'loading') {
                                document.addEventListener('DOMContentLoaded', initCodeMirror);
                            } else {
                                initCodeMirror();
                            }
                        })();
                    </script>

                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_download() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : '';
        $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : 'divi-converted.json';
        $payload = $key ? get_transient($key) : '';

        if (!$payload) wp_die('Download expired or invalid.');
        delete_transient($key);

        // Detect .css vs .json for header (PHP7-safe)
        $ctype = (substr($filename, -4) === '.css') ? 'text/css' : 'application/json';

        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $ctype . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: ' . strlen($payload));
        echo $payload;
        exit;
    }
}

new ET_Helper_Divi_JSON_Parser();
