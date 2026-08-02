<?php
defined( 'ABSPATH' ) || exit;

/**
 * Feature: DB Reset (QA)
 * Wipes the current database and imports one of several bundled SQL dumps
 * (registered in bundled_files() — currently fresh-sql.sql and with-data.sql),
 * or a custom uploaded dump, for local/QA/debugging use. Also supports:
 *   - rewriting domain/URL references in an uploaded dump to match this site
 *     (serialization-safe, so it doesn't corrupt options/postmeta), either as
 *     a standalone "generate & download" step or inline before importing
 *   - regenerating any bundled file in place so its URLs match this site,
 *     optionally swapping its wp_users row for the currently logged-in
 *     admin's actual login + password hash — keeping the 5 most recent
 *     timestamped backups per file and pruning older ones
 *   - syncing a bundled source's dummy images into wp-content/uploads on
 *     import, either from a local folder shipped with the plugin or from a
 *     remote .zip (hosted anywhere — see WITH_DATA_UPLOADS_ZIP_URL below)
 *
 * Guarded by:
 *   - manage_options capability
 *   - a nonce per action
 *   - an explicit acknowledgement checkbox + a native browser confirm(),
 *     required only for the actual destructive reset/import
 */
class ETH_DB_Reset {

    const MENU_SLUG        = 'eth-db-reset';

    const ACTION_IMPORT             = 'eth_db_reset_import';
    const ACTION_GENERATE           = 'eth_db_reset_generate';
    const ACTION_DOWNLOAD_BACKUP    = 'eth_db_reset_download_backup';
    const ACTION_DELETE_BACKUP      = 'eth_db_reset_delete_backup';
    const ACTION_DELETE_ALL_BACKUPS = 'eth_db_reset_delete_all_backups';

    const NONCE_MAIN          = 'eth_db_reset_main_nonce';   // shared by Import + Generate (same form)
    const NONCE_BACKUP        = 'eth_db_reset_backup_nonce'; // read-only: download
    const NONCE_DELETE_BACKUP = 'eth_db_reset_delete_backup_nonce'; // destructive: delete one / delete all

    const FLASH_TRANSIENT_PREFIX = 'et_dbreset_flash_';

    const OPT_LAST_REGEN_URL      = 'eth_dbreset_last_regen_url';      // URL the bundled files were last auto-regenerated for
    const OPT_ORIGINAL_BACKUP_DONE = 'eth_dbreset_original_backup_done'; // permanent flag: has the one-time original snapshot ever been made
    const NOTICE_TRANSIENT        = 'eth_dbreset_auto_regen_notice';   // one-shot "done" notice after an auto-regen
    const NOTICE_TRANSIENT_BACKUP = 'eth_dbreset_auto_backup_notice';  // one-shot notice after the original-snapshot backup

    /**
     * Static URL to a .zip whose internal structure mirrors wp-content/uploads/
     * (e.g. 2026/07/photo.jpg), used to populate with-data.sql's dummy images
     * without shipping the images inside the plugin. Host it anywhere (S3, a
     * CDN, your own server) and set the URL here. Leave as '' to fall back to
     * the local includes/db-reset/dummy-uploads/ folder instead.
     */
    const WITH_DATA_UPLOADS_ZIP_URL = 'https://showhan.net/dummy-uploads.zip';

    public function __construct() {
        add_action( 'admin_menu',                                  [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',                        [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_' . self::ACTION_IMPORT,            [ $this, 'handle_import' ] );
        add_action( 'admin_post_' . self::ACTION_GENERATE,          [ $this, 'handle_generate' ] );
        add_action( 'admin_post_' . self::ACTION_DOWNLOAD_BACKUP,   [ $this, 'handle_download_backup' ] );
        add_action( 'admin_post_' . self::ACTION_DELETE_BACKUP,     [ $this, 'handle_delete_backup' ] );
        add_action( 'admin_post_' . self::ACTION_DELETE_ALL_BACKUPS,[ $this, 'handle_delete_all_backups' ] );

        // Background auto-regen: fixes bundled SQL files' URLs (and swaps in whichever
        // admin's credentials triggered it) without any manual action. Fires on every
        // admin page load, but only does real work when the site's URL has changed
        // since the last time it ran — so effectively once per URL change, including
        // the very first admin page load after this file is deployed.
        add_action( 'admin_init',            [ $this, 'maybe_auto_regenerate' ] );
        add_action( 'admin_init',            [ $this, 'maybe_create_original_backup' ] );
        add_action( 'admin_notices',         [ $this, 'maybe_show_auto_regen_notice' ] );
        add_action( 'network_admin_notices', [ $this, 'maybe_show_auto_regen_notice' ] );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        add_submenu_page(
            'tools.php',
            __( 'Reset & Import', 'et-helper' ),
            __( 'Reset & Import', 'et-helper' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'tools_page_' . self::MENU_SLUG ) return;
        wp_enqueue_style( 'eth-djc', ETH_ASSETS_URL . 'css/divi-json-converter.css', [], ETH_VERSION ); // base card/button styles
        wp_enqueue_style( 'eth-db-reset', ETH_ASSETS_URL . 'css/db-reset.css', [ 'eth-djc' ], ETH_VERSION );
        wp_enqueue_script( 'eth-db-reset', ETH_ASSETS_URL . 'js/db-reset.js', [], ETH_VERSION, true );
    }

    // ── Bundled-file registry ────────────────────────────────────────────────

    /** Registry of bundled SQL dumps selectable in the UI. Add more here to expose more.
     *  'uploads_dir' (optional) is a folder under includes/db-reset/ that mirrors the
     *  wp-content/uploads/ structure referenced by that dump's attachment rows.
     *  'uploads_remote_zip' (optional) is a URL to a .zip with that same internal
     *  structure, hosted anywhere instead of shipped with the plugin. If both are
     *  present, the remote zip takes priority; if neither is set, no image sync
     *  happens for that source. */
    private static function bundled_files(): array {
        return [
            'fresh'     => [ 'filename' => 'fresh-sql.sql', 'label' => 'Fresh install (no data)' ],
            'with_data' => [
                'filename'           => 'with-data.sql',
                'label'              => 'With dummy data',
                'uploads_dir'        => 'dummy-uploads',
                'uploads_remote_zip' => self::WITH_DATA_UPLOADS_ZIP_URL,
            ],
        ];
    }

    private static function bundled_file_path( string $key ): ?string {
        $files = self::bundled_files();
        if ( ! isset( $files[ $key ] ) ) return null;
        return ETH_INC_DIR . 'db-reset/' . $files[ $key ]['filename'];
    }

    private static function bundled_uploads_dir( string $key ): ?string {
        $files = self::bundled_files();
        if ( empty( $files[ $key ]['uploads_dir'] ) ) return null;
        return ETH_INC_DIR . 'db-reset/' . $files[ $key ]['uploads_dir'];
    }

    private static function bundled_remote_zip( string $key ): ?string {
        $files = self::bundled_files();
        return ! empty( $files[ $key ]['uploads_remote_zip'] ) ? $files[ $key ]['uploads_remote_zip'] : null;
    }

    /** Count of files in a bundled source's local dummy-uploads folder (0 if none configured/found; not applicable to remote zips). */
    private static function count_dummy_uploads( string $key ): int {
        $dir = self::bundled_uploads_dir( $key );
        if ( ! $dir || ! is_dir( $dir ) ) return 0;
        $count = 0;
        foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
            if ( $f->isFile() ) $count++;
        }
        return $count;
    }

    /**
     * Copy a bundled source's local dummy-uploads folder into WP's real uploads directory,
     * preserving the relative subpath (e.g. 2026/07/photo.jpg) and overwriting existing files.
     * @return array{0:int,1:int,2:string[]} [copied_count, failed_count, error_messages]
     */
    private static function copy_dummy_uploads( string $key ): array {
        $src_root = self::bundled_uploads_dir( $key );
        if ( ! $src_root || ! is_dir( $src_root ) ) return [ 0, 0, [] ]; // nothing configured — not an error

        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) ) return [ 0, 0, [ 'wp_upload_dir() error: ' . $upload_dir['error'] ] ];
        $dest_root = untrailingslashit( $upload_dir['basedir'] );

        $ok = $fail = 0; $errors = [];
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src_root, FilesystemIterator::SKIP_DOTS ) );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) continue;
            $relative  = ltrim( str_replace( $src_root, '', $file->getPathname() ), '/\\' );
            $relative  = str_replace( '\\', '/', $relative );
            $dest_path = $dest_root . '/' . $relative;
            $dest_dir  = dirname( $dest_path );

            if ( ! is_dir( $dest_dir ) && ! wp_mkdir_p( $dest_dir ) ) {
                $fail++; $errors[] = "Could not create directory for: {$relative}";
                continue;
            }
            if ( @copy( $file->getPathname(), $dest_path ) ) {
                $ok++;
            } else {
                $fail++; $errors[] = "Could not copy: {$relative}";
            }
        }
        return [ $ok, $fail, $errors ];
    }

    /**
     * Download a zip from a remote URL and merge its contents into WP's real uploads
     * directory. If everything in the zip sits inside one top-level wrapper folder
     * (e.g. the zip contains "dummy-uploads/2026/07/photo.jpg" instead of just
     * "2026/07/photo.jpg"), that wrapper is detected and skipped automatically —
     * only its contents get merged in, not the wrapper folder itself.
     * @return array{0:int,1:int,2:string[]} [files_copied, failed_count, error_messages]
     */
    private static function fetch_and_extract_remote_uploads( string $zip_url ): array {
        if ( ! function_exists( 'download_url' ) || ! function_exists( 'unzip_file' ) || ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp_file = download_url( $zip_url, 300 );
        if ( is_wp_error( $tmp_file ) ) {
            return [ 0, 1, [ 'Could not download dummy-uploads zip: ' . $tmp_file->get_error_message() ] ];
        }

        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) ) {
            @unlink( $tmp_file );
            return [ 0, 1, [ 'wp_upload_dir() error: ' . $upload_dir['error'] ] ];
        }
        $dest_root = untrailingslashit( $upload_dir['basedir'] );

        // Extract to a scratch directory first (rather than straight into uploads) so a
        // possible single wrapping top-level folder inside the zip can be flattened away
        // before anything is merged into the real uploads directory.
        $scratch = trailingslashit( get_temp_dir() ) . 'eth-dummy-uploads-' . wp_generate_password( 8, false, false );
        if ( ! wp_mkdir_p( $scratch ) ) {
            @unlink( $tmp_file );
            return [ 0, 1, [ 'Could not create a scratch directory for extraction.' ] ];
        }

        WP_Filesystem();
        $result = unzip_file( $tmp_file, $scratch );
        @unlink( $tmp_file );

        if ( is_wp_error( $result ) ) {
            self::rrmdir( $scratch );
            return [ 0, 1, [ 'Could not extract dummy-uploads zip: ' . $result->get_error_message() ] ];
        }

        $source_root = self::resolve_effective_root( $scratch );
        [ $ok, $fail, $errors ] = self::merge_directory_into( $source_root, $dest_root );

        self::rrmdir( $scratch );

        return [ $ok, $fail, $errors ];
    }

    /** If $dir contains exactly one entry and it's a directory, descend into it (unwraps a single top-level zip folder). */
    private static function resolve_effective_root( string $dir ): string {
        $entries = array_values( array_diff( scandir( $dir ) ?: [], [ '.', '..' ] ) );
        if ( count( $entries ) === 1 && is_dir( $dir . '/' . $entries[0] ) ) {
            return $dir . '/' . $entries[0];
        }
        return $dir;
    }

    /** Recursively copy every file from $src into $dest, preserving relative subpaths, overwriting existing files. */
    private static function merge_directory_into( string $src, string $dest ): array {
        $ok = $fail = 0; $errors = [];
        if ( ! is_dir( $src ) ) return [ $ok, $fail, $errors ];

        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) continue;
            $relative  = ltrim( str_replace( $src, '', $file->getPathname() ), '/\\' );
            $relative  = str_replace( '\\', '/', $relative );
            $dest_path = $dest . '/' . $relative;
            $dest_dir  = dirname( $dest_path );

            if ( ! is_dir( $dest_dir ) && ! wp_mkdir_p( $dest_dir ) ) {
                $fail++; $errors[] = "Could not create directory for: {$relative}";
                continue;
            }
            if ( @copy( $file->getPathname(), $dest_path ) ) {
                $ok++;
            } else {
                $fail++; $errors[] = "Could not copy: {$relative}";
            }
        }
        return [ $ok, $fail, $errors ];
    }

    /** Recursively delete a directory and everything in it. */
    private static function rrmdir( string $dir ): void {
        if ( ! is_dir( $dir ) ) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
        }
        @rmdir( $dir );
    }

    /**
     * Delete every file and subfolder inside WP's real uploads directory (the
     * directory itself is kept). A top-level .htaccess or index.php, if present,
     * is preserved since those are usually server-protection files, not media.
     * @return array{0:int,1:int,2:string[]} [deleted_count, failed_count, error_messages]
     */
    private static function empty_uploads_dir(): array {
        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) ) {
            return [ 0, 1, [ 'wp_upload_dir() error: ' . $upload_dir['error'] ] ];
        }
        $base = untrailingslashit( $upload_dir['basedir'] );
        if ( ! is_dir( $base ) ) return [ 0, 0, [] ];

        $preserve = [ '.htaccess', 'index.php' ];
        $deleted = $fail = 0; $errors = [];

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST // delete file contents before their parent directory
        );

        foreach ( $items as $item ) {
            $path = $item->getPathname();
            if ( $item->isFile() && in_array( $item->getFilename(), $preserve, true ) && dirname( $path ) === $base ) {
                continue; // keep a top-level .htaccess / index.php in place
            }
            if ( $item->isDir() ) {
                if ( @rmdir( $path ) ) { $deleted++; } else { $fail++; $errors[] = "Could not remove directory: {$path}"; }
            } else {
                if ( @unlink( $path ) ) { $deleted++; } else { $fail++; $errors[] = "Could not delete file: {$path}"; }
            }
        }
        return [ $deleted, $fail, $errors ];
    }

    /**
     * Sync a bundled source's dummy images into uploads, preferring a remote zip
     * over a local folder if both are configured.
     * @return array{method:string,ok:int,fail:int,errors:string[]}
     */
    private static function sync_dummy_uploads( string $key ): array {
        $zip_url = self::bundled_remote_zip( $key );
        if ( $zip_url ) {
            [ $ok, $fail, $errors ] = self::fetch_and_extract_remote_uploads( $zip_url );
            return [ 'method' => 'remote', 'ok' => $ok, 'fail' => $fail, 'errors' => $errors ];
        }
        if ( self::bundled_uploads_dir( $key ) ) {
            [ $ok, $fail, $errors ] = self::copy_dummy_uploads( $key );
            return [ 'method' => 'local', 'ok' => $ok, 'fail' => $fail, 'errors' => $errors ];
        }
        return [ 'method' => 'none', 'ok' => 0, 'fail' => 0, 'errors' => [] ];
    }

    /** Keep only the $keep most recent .bak-* files for a given bundled path. */
    private static function prune_backups( string $path, int $keep = 5 ): void {
        $matches = glob( $path . '.bak-*' );
        if ( ! $matches ) return;
        sort( $matches ); // Ymd-His timestamp suffix sorts oldest-first lexically
        $excess = count( $matches ) - $keep;
        for ( $i = 0; $i < $excess; $i++ ) {
            @unlink( $matches[ $i ] );
        }
    }

    /** Login/password/email pulled from the currently logged-in user's own DB row (never a typed password). */
    private static function current_user_credentials(): array {
        $u = wp_get_current_user();
        return [
            'user_login'    => $u->user_login,
            'user_pass'     => $u->user_pass, // already the real hash stored in wp_users — never handled as plaintext
            'user_email'    => $u->user_email,
            'user_nicename' => $u->user_nicename,
            'display_name'  => $u->display_name,
        ];
    }

    private static function apply_prefix( string $sql ): string {
        global $wpdb;
        if ( $wpdb->prefix !== 'wp_' ) {
            $sql = preg_replace( '/`wp_/', '`' . $wpdb->prefix, $sql );
        }
        return $sql;
    }

    /** Rewrite $sql's URLs from $old_url (auto-detected if empty) to this site's current home_url(). */
    private static function maybe_rewrite_domain( string $sql, string $old_url_override = '' ): string {
        $new_url = untrailingslashit( home_url() );
        $old_url = untrailingslashit( $old_url_override !== '' ? $old_url_override : ( ETH_SQL_Domain_Rewriter::detect_old_url( $sql ) ?? '' ) );
        if ( $old_url === '' || $old_url === $new_url ) return $sql;
        return ETH_SQL_Domain_Rewriter::rewrite_domain_multi( $sql, ETH_SQL_Domain_Rewriter::build_url_pairs( $old_url, $new_url ) );
    }

    // ── Background auto-regeneration ────────────────────────────────────────

    /**
     * Rewrite every bundled SQL file's URLs to match the current site, and swap its
     * wp_users row for whichever admin's session triggered this — but only if the
     * site's URL has changed since the last time this ran (tracked via
     * OPT_LAST_REGEN_URL), or always, if $force is true.
     */
    private static function regenerate_all_if_needed( bool $force = false ): void {
        $current_url = untrailingslashit( home_url() );

        if ( ! $force && get_option( self::OPT_LAST_REGEN_URL, '' ) === $current_url ) {
            return; // already up to date for this URL — nothing to do
        }

        global $wpdb;
        $current_user_id = get_current_user_id();
        $creds            = $current_user_id ? self::current_user_credentials() : null;

        $updated = [];
        foreach ( self::bundled_files() as $key => $meta ) {
            $path = self::bundled_file_path( $key );
            if ( ! $path || ! file_exists( $path ) ) continue;

            $sql = file_get_contents( $path );
            if ( $sql === false || trim( $sql ) === '' ) continue;

            $rewritten = self::maybe_rewrite_domain( $sql );
            if ( $creds ) {
                $rewritten = ETH_SQL_Domain_Rewriter::rewrite_users_credentials( $rewritten, $wpdb->prefix, $creds );
            }
            if ( $rewritten === $sql ) continue; // nothing actually changed

            @copy( $path, $path . '.bak-' . gmdate( 'Ymd-His' ) );
            self::prune_backups( $path, 5 );

            if ( file_put_contents( $path, $rewritten ) !== false ) {
                $updated[] = basename( $path );
            }
        }

        update_option( self::OPT_LAST_REGEN_URL, $current_url, false );

        if ( $updated ) {
            $msg = implode( ', ', $updated ) . ' automatically regenerated for ' . $current_url;
            $msg .= $creds ? ', with your login credentials applied.' : '.';
            set_transient( self::NOTICE_TRANSIENT, $msg, 60 );
        }
    }

    /** admin_init: cheap check on every admin page load, real work only when the URL has actually changed. */
    public function maybe_auto_regenerate(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        self::regenerate_all_if_needed();
    }

    /** register_activation_hook target — kept for portability if this ever runs as a normal (non-mu) plugin. */
    public static function on_plugin_activation(): void {
        self::regenerate_all_if_needed( true );
    }

    public function maybe_show_auto_regen_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( $this->is_own_page() ) return; // shown inline within render_page() instead, in the same spot as the page's own flash
        foreach ( [ self::NOTICE_TRANSIENT, self::NOTICE_TRANSIENT_BACKUP ] as $key ) {
            $msg = get_transient( $key );
            if ( ! $msg ) continue;
            delete_transient( $key );
            printf(
                '<div class="notice notice-success is-dismissible inline"><p><strong>ET Helper:</strong> %s</p></div>',
                esc_html( $msg )
            );
        }
    }

    private function is_own_page(): bool {
        return isset( $_GET['page'] ) && wp_unslash( $_GET['page'] ) === self::MENU_SLUG;
    }

    /** admin_init: creates one "original site" full-DB snapshot, the first time this ever runs — never repeated, even if rotation later deletes that file. */
    public function maybe_create_original_backup(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( get_option( self::OPT_ORIGINAL_BACKUP_DONE ) ) return;

        [ $ok, $filename, $error ] = ETH_DB_Backup::create_full_backup( ETH_DB_Backup::PREFIX_ORIGINAL );

        if ( $ok ) {
            update_option( self::OPT_ORIGINAL_BACKUP_DONE, 1, false );
            ETH_DB_Backup::prune_backups();
            set_transient(
                self::NOTICE_TRANSIENT_BACKUP,
                "Captured a full backup of the site's database ({$filename}) — see Database Backups below.",
                60
            );
        }
    }

    // ── Handler: Download a backup ──────────────────────────────────────────

    public function handle_download_backup(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
        check_admin_referer( self::NONCE_BACKUP );

        $requested = isset( $_GET['file'] ) ? basename( wp_unslash( $_GET['file'] ) ) : '';
        $match     = null;
        foreach ( ETH_DB_Backup::list_backups() as $b ) {
            if ( $b['filename'] === $requested ) { $match = $b; break; }
        }
        if ( ! $match ) wp_die( 'Backup file not found.' );

        nocache_headers();
        header( 'Content-Type: application/sql; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $match['filename'] . '"' );
        header( 'Content-Length: ' . $match['size'] );
        readfile( $match['path'] );
        exit;
    }

    // ── Handler: Delete backup(s) ───────────────────────────────────────────

    public function handle_delete_backup(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
        check_admin_referer( self::NONCE_DELETE_BACKUP );

        $filename = isset( $_POST['file'] ) ? basename( wp_unslash( $_POST['file'] ) ) : '';
        if ( $filename === '' || ! ETH_DB_Backup::delete_backup( $filename ) ) {
            $this->flash( 'error', 'Could not delete that backup — it may have already been removed.' );
        } else {
            $this->flash( 'success', esc_html( $filename ) . ' deleted.' );
        }
        $this->redirect_back();
    }

    public function handle_delete_all_backups(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
        check_admin_referer( self::NONCE_DELETE_BACKUP );

        $count = ETH_DB_Backup::delete_all_backups();
        $this->flash( 'success', "Deleted {$count} backup file(s)." );
        $this->redirect_back();
    }

    // ── Handler: Reset & Import ─────────────────────────────────────────────

    public function handle_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
        check_admin_referer( self::NONCE_MAIN );

        if ( empty( $_POST['confirm_ack'] ) ) {
            $this->flash( 'error', 'Confirmation failed. You must check the acknowledgement box before resetting.' );
            $this->redirect_back();
        }

        $source = $_POST['sql_source'] ?? 'fresh';

        if ( $source === 'custom' ) {
            $sql = $this->read_uploaded_sql( 'custom_sql' ); // flashes+redirects internally on failure
            $sql = self::apply_prefix( $sql );

            $old_override = isset( $_POST['old_url_override'] ) ? trim( wp_unslash( $_POST['old_url_override'] ) ) : '';
            $sql = self::maybe_rewrite_domain( $sql, $old_override );

            if ( ! empty( $_POST['use_my_credentials'] ) ) {
                global $wpdb;
                $sql = ETH_SQL_Domain_Rewriter::rewrite_users_credentials( $sql, $wpdb->prefix, self::current_user_credentials() );
            }
        } else {
            $path = self::bundled_file_path( $source );
            if ( $path === null ) {
                $this->flash( 'error', 'Unknown SQL source selected.' );
                $this->redirect_back();
            }
            if ( ! file_exists( $path ) ) {
                $this->flash( 'error', basename( $path ) . ' was not found in the plugin.' );
                $this->redirect_back();
            }
            $sql = file_get_contents( $path );
            if ( $sql === false || trim( $sql ) === '' ) {
                $this->flash( 'error', 'Could not read ' . basename( $path ) . ', or it was empty.' );
                $this->redirect_back();
            }
            $sql = self::apply_prefix( $sql );
        }

        [ $bk_ok, $bk_filename, $bk_error ] = ETH_DB_Backup::create_full_backup( ETH_DB_Backup::PREFIX_PRERESET );
        ETH_DB_Backup::prune_backups();

        $msg = $bk_ok
            ? "Backed up current database to {$bk_filename}. "
            : 'Warning: could not back up the current database (' . esc_html( $bk_error ) . '). ';

        if ( ! empty( $_POST['empty_uploads'] ) ) {
            [ $del, $del_fail, $del_errors ] = self::empty_uploads_dir();
            $msg .= "Emptied uploads folder ({$del} item(s) deleted" . ( $del_fail > 0 ? ", {$del_fail} failed" : '' ) . "). ";
        }

        [ $ok, $fail, $errors ] = $this->run_import( $sql );

        $msg .= "Import finished — {$ok} statement(s) succeeded, {$fail} failed.";

        // If the chosen source has bundled dummy images (local folder or remote zip), sync them into uploads.
        if ( $source !== 'custom' ) {
            $sync = self::sync_dummy_uploads( $source );
            if ( $sync['method'] === 'remote' ) {
                if ( $sync['fail'] === 0 ) {
                    $msg .= " Fetched {$sync['ok']} dummy image file(s) from remote storage.";
                } else {
                    $msg .= " Fetched from remote storage — {$sync['ok']} image file(s) copied, {$sync['fail']} failed: " . esc_html( $sync['errors'][0] ?? '' );
                }
            } elseif ( $sync['method'] === 'local' ) {
                if ( $sync['ok'] > 0 || $sync['fail'] > 0 ) {
                    $msg .= " Copied {$sync['ok']} dummy image file(s) to uploads";
                    $msg .= $sync['fail'] > 0 ? ", {$sync['fail']} failed." : '.';
                } else {
                    $msg .= ' No dummy images were copied — the local dummy-uploads folder was empty or missing.';
                }
            } elseif ( $sync['method'] === 'none' ) {
                $msg .= ' No dummy image source is configured for this dump (set WITH_DATA_UPLOADS_ZIP_URL, or add includes/db-reset/dummy-uploads/).';
            }
        }

        if ( $fail > 0 ) {
            $msg .= ' First error: ' . esc_html( $errors[0] );
            $this->flash( 'error', $msg );
        } else {
            $this->flash( 'success', $msg );
        }
        $this->redirect_back();
    }

    /**
     * @return array{0:int,1:int,2:string[]} [success_count, fail_count, error_messages]
     */
    private function run_import( string $sql ): array {
        global $wpdb;

        @set_time_limit( 0 );
        if ( function_exists( 'ignore_user_abort' ) ) ignore_user_abort( true );

        $statements = ETH_SQL_Splitter::split( $sql );
        $ok = $fail = 0;
        $errors = [];

        $wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
        foreach ( $statements as $stmt ) {
            $result = $wpdb->query( $stmt );
            if ( $result === false ) {
                $fail++;
                $errors[] = $wpdb->last_error . ' — near: ' . substr( $stmt, 0, 120 );
            } else {
                $ok++;
            }
        }
        $wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );

        return [ $ok, $fail, $errors ];
    }

    // ── Handler: Generate updated SQL (download only, no import) ───────────

    public function handle_generate(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
        check_admin_referer( self::NONCE_MAIN );

        $sql = $this->read_uploaded_sql( 'custom_sql' );
        $sql = self::apply_prefix( $sql );

        $old_override = isset( $_POST['old_url_override'] ) ? trim( wp_unslash( $_POST['old_url_override'] ) ) : '';
        $sql = self::maybe_rewrite_domain( $sql, $old_override );

        if ( ! empty( $_POST['use_my_credentials'] ) ) {
            global $wpdb;
            $sql = ETH_SQL_Domain_Rewriter::rewrite_users_credentials( $sql, $wpdb->prefix, self::current_user_credentials() );
        }

        $filename = 'et-helper-adjusted-' . gmdate( 'Ymd-His' ) . '.sql';
        nocache_headers();
        header( 'Content-Type: application/sql; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $sql ) );
        echo $sql;
        exit;
    }

    // ── Upload helper ────────────────────────────────────────────────────────

    /** Reads+validates an uploaded .sql file, or flashes an error and redirects (never returns on failure). */
    private function read_uploaded_sql( string $field ): string {
        if ( empty( $_FILES[ $field ]['tmp_name'] ) || $_FILES[ $field ]['error'] !== UPLOAD_ERR_OK ) {
            $this->flash( 'error', 'No valid SQL file was uploaded.' );
            $this->redirect_back();
        }
        if ( strtolower( pathinfo( $_FILES[ $field ]['name'], PATHINFO_EXTENSION ) ) !== 'sql' ) {
            $this->flash( 'error', 'Only .sql files are accepted.' );
            $this->redirect_back();
        }
        $sql = file_get_contents( $_FILES[ $field ]['tmp_name'] );
        if ( $sql === false || trim( $sql ) === '' ) {
            $this->flash( 'error', 'Could not read the uploaded file, or it was empty.' );
            $this->redirect_back();
        }
        return $sql;
    }

    // ── Page ──────────────────────────────────────────────────────────────────

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        global $wpdb;
        $notices = [];
        if ( $flash = $this->consume_flash() ) {
            $notices[] = $flash;
        }
        foreach ( [ self::NOTICE_TRANSIENT, self::NOTICE_TRANSIENT_BACKUP ] as $key ) {
            $msg = get_transient( $key );
            if ( ! $msg ) continue;
            delete_transient( $key );
            $notices[] = [ 'type' => 'success', 'msg' => $msg ];
        }
        $current_url   = untrailingslashit( home_url() );
        $current_user  = wp_get_current_user();

        $bundled_info = [];
        foreach ( self::bundled_files() as $key => $meta ) {
            $path   = self::bundled_file_path( $key );
            $exists = $path && file_exists( $path );
            $sql    = $exists ? file_get_contents( $path ) : '';
            $bundled_info[ $key ] = [
                'label'          => $meta['label'],
                'filename'       => $meta['filename'],
                'exists'         => $exists,
                'detected'       => $sql ? ETH_SQL_Domain_Rewriter::detect_old_url( $sql ) : null,
                'image_count'    => self::count_dummy_uploads( $key ),
                'remote_zip'     => self::bundled_remote_zip( $key ),
                'expects_images' => array_key_exists( 'uploads_dir', $meta ) || array_key_exists( 'uploads_remote_zip', $meta ),
            ];
        }
        ?>
        <div class="wrap eth-djc-wrap eth-dbreset-wrap">
            <div class="eth-djc-header">
                <span class="dashicons dashicons-database eth-djc-header-icon"></span>
                <div>
                    <h1>Reset &amp; Import</h1>
                    <p class="eth-djc-subtitle">Wipe the current database and import a SQL dump, with domain URLs (and optional dummy images) rewritten to match this site.</p>
                </div>
            </div>

            <?php foreach ( $notices as $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] === 'error' ? 'error' : 'success' ); ?> is-dismissible inline">
                    <p><strong>ET Helper:</strong> <?php echo esc_html( $notice['msg'] ); ?></p>
                </div>
            <?php endforeach; ?>

            <!-- Danger notice -->
            <div class="eth-djc-card eth-dbreset-card--danger">
                <h2><span class="dashicons dashicons-warning"></span> This is Irreversible</h2>
                <p>
                    Importing below <strong>drops and recreates every table in the dump</strong> (<code>wp_*</code>, rewritten to your prefix
                    <code><?php echo esc_html( $wpdb->prefix ); ?></code>), permanently deleting all existing posts, users, options, and
                    plugin data currently in this database. If "empty uploads" is checked, it also <strong>deletes every file in
                    <code>wp-content/uploads</code></strong>. A full backup of the current database is taken automatically right before
                    any of this happens — see below.
                </p>
                <p style="margin-bottom:0;">
                    Bundled dumps' URLs (and admin credentials) are kept in sync automatically in the background — nothing to do here.
                </p>
            </div>

            <!-- Reset & Import -->
            <div class="eth-djc-card">
                <h2><span class="dashicons dashicons-database-import"></span> Reset &amp; Import</h2>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="eth-dbreset-form">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_IMPORT ); ?>" id="eth-dbreset-action-field">
                    <?php wp_nonce_field( self::NONCE_MAIN ); ?>

                    <div class="eth-dbreset-source-list">
                        <?php $first = true; foreach ( $bundled_info as $key => $info ) : if ( ! $info['exists'] ) continue; ?>
                            <label class="eth-dbreset-source-option">
                                <input type="radio" name="sql_source" value="<?php echo esc_attr( $key ); ?>"
                                       <?php checked( $first ); ?> class="eth-dbreset-src-radio">
                                <span>
                                    <span class="eth-dbreset-source-main">
                                        <code><?php echo esc_html( $info['filename'] ); ?></code> — <?php echo esc_html( $info['label'] ); ?>
                                    </span>
                                    <?php if ( $info['remote_zip'] ) : ?>
                                        <span class="eth-dbreset-source-sub">Dummy images are fetched from remote storage during import.</span>
                                    <?php elseif ( $info['image_count'] > 0 ) : ?>
                                        <span class="eth-dbreset-source-sub"><?php echo (int) $info['image_count']; ?> bundled dummy image(s) will be copied to uploads.</span>
                                    <?php elseif ( $info['expects_images'] ) : ?>
                                        <span class="eth-dbreset-source-sub" style="color:#b91c1c;">No image source configured — set <code>WITH_DATA_UPLOADS_ZIP_URL</code> in class-db-reset.php, or add <code>includes/db-reset/dummy-uploads/</code>.</span>
                                    <?php endif; ?>
                                </span>
                            </label>
                            <?php $first = false; endforeach; ?>

                        <label class="eth-dbreset-source-option">
                            <input type="radio" name="sql_source" value="custom" <?php checked( $first ); ?> class="eth-dbreset-src-radio">
                            <span>
                                <span class="eth-dbreset-source-main">Upload a different <code>.sql</code> dump</span>
                                <span class="eth-dbreset-source-sub">Optionally rewrite its domain URLs and admin credentials before importing.</span>
                            </span>
                        </label>
                    </div>

                    <div id="eth-dbreset-custom-options" class="eth-dbreset-custom-panel" style="display:none;">
                        <div class="eth-dbreset-file-picker">
                            <input type="file" name="custom_sql" id="eth-dbreset-custom-file" accept=".sql" class="eth-dbreset-file-input-real">
                            <label for="eth-dbreset-custom-file" class="eth-dbreset-file-picker-btn">
                                <span class="dashicons dashicons-upload"></span> Choose .sql file
                            </label>
                            <span class="eth-dbreset-file-picker-name" id="eth-dbreset-file-picker-name">No file selected</span>
                        </div>

                        <p class="eth-dbreset-file-note">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <span>Domain URLs in the uploaded file are always rewritten to match this site (<code><?php echo esc_html( $current_url ); ?></code>) before it's used — serialization-safe, so options/postmeta won't be corrupted.</span>
                        </p>

                        <p style="margin:0 0 12px;">
                            <label style="display:block;font-size:12.5px;color:#6b7280;margin-bottom:4px;">
                                Old URL in the uploaded file (optional — auto-detected from its <code>siteurl</code>/<code>home</code> rows if left blank)
                            </label>
                            <input type="text" name="old_url_override" placeholder="http://old-site.test"
                                   style="width:280px;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;">
                        </p>

                        <label class="eth-djc-opt-label" style="margin-bottom:8px;">
                            <input type="checkbox" name="use_my_credentials" value="1">
                            Replace the uploaded file's admin user with my current login &amp; password
                            (<code><?php echo esc_html( $current_user->user_login ); ?></code>)
                        </label>

                        <div>
                            <button type="submit" class="eth-djc-btn eth-djc-btn--ghost" id="eth-dbreset-generate">
                                <span class="dashicons dashicons-download"></span> Generate updated SQL (download only, no import)
                            </button>
                        </div>
                    </div>

                    <label class="eth-dbreset-toggle-option">
                        <input type="checkbox" name="empty_uploads" value="1" checked id="eth-dbreset-empty-uploads">
                        <span>
                            <span class="eth-dbreset-toggle-main">Also empty <code>wp-content/uploads</code> before importing</span>
                            <span class="eth-dbreset-toggle-sub">Deletes every existing media file. A top-level <code>.htaccess</code> or <code>index.php</code>, if present, is kept.</span>
                        </span>
                    </label>

                    <div class="eth-dbreset-ack-box">
                        <label><input type="checkbox" name="confirm_ack" value="1" required id="eth-dbreset-ack"><span>I understand this will permanently delete all existing data in this database<span id="eth-dbreset-ack-uploads-note">, including every file in <code>wp-content/uploads</code>,</span> and cannot be undone.</span></label>
                    </div>

                    <button type="submit" class="eth-djc-btn eth-dbreset-danger-btn" id="eth-dbreset-submit" disabled>
                        <span class="dashicons dashicons-database-remove"></span> Reset &amp; Import
                    </button>
                </form>
            </div>

            <!-- Database Backups -->
            <div class="eth-djc-card">
                <h2><span class="dashicons dashicons-backup"></span> Database Backups</h2>
                <p class="eth-dbreset-card-intro">
                    A full snapshot of every table matching this site's prefix (<code><?php echo esc_html( $wpdb->prefix ); ?></code>) was
                    captured automatically the first time this ran, and another is taken right before every Reset &amp; Import — so
                    what was here can always be restored. Only the <?php echo (int) ETH_DB_Backup::KEEP_TOTAL; ?> most recent backups are
                    kept — older ones are deleted automatically.
                </p>

                <?php $backups = ETH_DB_Backup::list_backups(); ?>
                <?php if ( ! $backups ) : ?>
                    <div class="eth-dbreset-no-backups">
                        <span class="dashicons dashicons-info-outline"></span> No backups yet — one will appear here shortly.
                    </div>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eth-dbreset-delete-all-form">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE_ALL_BACKUPS ); ?>">
                        <?php wp_nonce_field( self::NONCE_DELETE_BACKUP ); ?>
                        <button type="submit" class="eth-djc-btn eth-djc-btn--ghost eth-dbreset-delete-all-btn">
                            <span class="dashicons dashicons-trash"></span> Delete All Backups
                        </button>
                    </form>

                    <div class="eth-dbreset-backup-list">
                        <?php foreach ( $backups as $i => $b ) :
                            $download_url = wp_nonce_url(
                                admin_url( 'admin-post.php?action=' . self::ACTION_DOWNLOAD_BACKUP . '&file=' . rawurlencode( $b['filename'] ) ),
                                self::NONCE_BACKUP
                            );
                        ?>
                            <div class="eth-dbreset-backup-row">
                                <div class="eth-dbreset-backup-info">
                                    <span class="eth-dbreset-badge eth-dbreset-badge--neutral">
                                        <?php echo esc_html( ETH_DB_Backup::generic_label( $i + 1 ) ); ?>
                                    </span>
                                    <code class="eth-dbreset-backup-filename"><?php echo esc_html( $b['filename'] ); ?></code>
                                    <span class="eth-dbreset-backup-meta">
                                        <?php echo esc_html( date_i18n( 'M j, Y g:i a', $b['time'] ) ); ?> · <?php echo esc_html( ETH_DB_Backup::human_size( $b['size'] ) ); ?>
                                    </span>
                                </div>
                                <div class="eth-dbreset-backup-actions">
                                    <a href="<?php echo esc_url( $download_url ); ?>" class="eth-djc-btn eth-djc-btn--ghost">
                                        <span class="dashicons dashicons-download"></span> Download
                                    </a>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eth-dbreset-delete-one-form">
                                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE_BACKUP ); ?>">
                                        <input type="hidden" name="file" value="<?php echo esc_attr( $b['filename'] ); ?>">
                                        <?php wp_nonce_field( self::NONCE_DELETE_BACKUP ); ?>
                                        <button type="submit" class="eth-djc-btn eth-djc-btn--ghost eth-dbreset-delete-one-btn" title="Delete this backup">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // ── Flash helpers ─────────────────────────────────────────────────────────

    private function flash_key(): string { return self::FLASH_TRANSIENT_PREFIX . get_current_user_id(); }

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
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) );
        exit;
    }
}