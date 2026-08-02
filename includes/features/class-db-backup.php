<?php
defined( 'ABSPATH' ) || exit;

/**
 * Feature: DB Backup
 * Full-database SQL dumps (every table matching the current $wpdb prefix),
 * stored outside wp-content/uploads — in a protected wp-content/et-helper-db-backups/
 * folder — so the "empty uploads" step of a reset can never touch them. Used to:
 *   - automatically snapshot the site's original state, once, the first time this runs
 *   - automatically snapshot state immediately before every destructive Reset & Import
 *   - list + serve any of the above for download from the plugin UI
 *
 * Dumps are written in the same DROP/CREATE/INSERT format the rest of the plugin
 * already parses (via ETH_SQL_Splitter), so a downloaded backup can be re-uploaded
 * through "Reset & Import → Upload a different .sql dump" to restore it.
 */
class ETH_DB_Backup {

    const DIR_NAME        = 'et-helper-db-backups';
    const PREFIX_ORIGINAL = 'original';
    const PREFIX_PRERESET = 'pre-reset';
    const KEEP_TOTAL       = 5; // total backups kept across ALL types, original included — oldest are deleted beyond this

    /** Protected storage directory, created (with access-denial files) on first use. */
    public static function dir(): string {
        $dir = trailingslashit( WP_CONTENT_DIR ) . self::DIR_NAME;
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            // Both directives included for old (2.2) and new (2.4+) Apache; irrelevant on nginx,
            // where the download route (admin-post.php, capability-gated) is the only access path anyway.
            @file_put_contents( $htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
        }
        return $dir;
    }

    /**
     * Dump every table matching the current $wpdb prefix into a new timestamped file.
     * @return array{0:bool,1:string,2:string} [success, filename (on success), error message (on failure)]
     */
    public static function create_full_backup( string $label ): array {
        global $wpdb;

        $dir        = self::dir();
        $safe_label = preg_replace( '/[^a-z0-9\-]/i', '', $label ) ?: 'backup';
        $filename   = $safe_label . '-' . gmdate( 'Ymd-His' ) . '.sql';
        $path       = $dir . '/' . $filename;

        $fh = @fopen( $path, 'w' );
        if ( ! $fh ) {
            return [ false, '', 'Could not open backup file for writing: ' . $path ];
        }

        @set_time_limit( 0 );

        $site_url = untrailingslashit( home_url() );
        fwrite( $fh, "-- ET Helper full database backup\n" );
        fwrite( $fh, "-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC for {$site_url}\n" );
        fwrite( $fh, "-- Table prefix: {$wpdb->prefix}\n\n" );
        fwrite( $fh, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

        $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );

        foreach ( $tables as $table ) {
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( ! $create || empty( $create[1] ) ) continue;

            fwrite( $fh, "--\n-- Table structure for `{$table}`\n--\n\n" );
            fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" );
            fwrite( $fh, $create[1] . ";\n\n" );

            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
            if ( $count === 0 ) { fwrite( $fh, "\n" ); continue; }

            fwrite( $fh, "--\n-- Dumping data for `{$table}`\n--\n\n" );

            $read_batch   = 500; // rows fetched per SELECT
            $insert_batch = 200; // rows per INSERT statement
            $offset       = 0;
            $columns      = null;
            $buffer       = [];

            while ( true ) {
                $rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$offset}, {$read_batch}", ARRAY_A );
                if ( ! $rows ) break;

                if ( $columns === null ) $columns = array_keys( $rows[0] );

                foreach ( $rows as $row ) {
                    $vals = array_map(
                        fn( $v ) => $v === null ? 'NULL' : "'" . esc_sql( $v ) . "'",
                        $row
                    );
                    $buffer[] = '(' . implode( ',', $vals ) . ')';

                    if ( count( $buffer ) >= $insert_batch ) {
                        self::write_insert( $fh, $table, $columns, $buffer );
                        $buffer = [];
                    }
                }

                $offset += $read_batch;
                if ( count( $rows ) < $read_batch ) break;
            }

            if ( $buffer ) self::write_insert( $fh, $table, $columns, $buffer );
            fwrite( $fh, "\n" );
        }

        fwrite( $fh, "SET FOREIGN_KEY_CHECKS=1;\n" );
        fclose( $fh );

        return [ true, $filename, '' ];
    }

    private static function write_insert( $fh, string $table, array $columns, array $value_tuples ): void {
        $cols = '`' . implode( '`,`', $columns ) . '`';
        fwrite( $fh, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode( ",\n", $value_tuples ) . ";\n" );
    }

    /** @return array<int,array{filename:string,path:string,type:string,size:int,time:int}> newest first */
    public static function list_backups(): array {
        $dir = self::dir();
        $out = [];
        foreach ( glob( $dir . '/*.sql' ) ?: [] as $path ) {
            $filename    = basename( $path );
            $is_original = str_starts_with( $filename, self::PREFIX_ORIGINAL . '-' );
            $out[] = [
                'filename' => $filename,
                'path'     => $path,
                'type'     => $is_original ? 'original' : 'pre-reset',
                'size'     => (int) @filesize( $path ),
                'time'     => (int) @filemtime( $path ),
            ];
        }
        usort( $out, fn( $a, $b ) => $b['time'] <=> $a['time'] );
        return $out;
    }

    /** "Backup One", "Backup Two", ... generic display name by position (1-based) — no type/origin implied. */
    public static function generic_label( int $position ): string {
        $words = [ 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten' ];
        return 'Backup ' . ( $words[ $position ] ?? $position );
    }

    /** Keep only the $keep most recent backups overall — original snapshots included, no exemptions. */
    public static function prune_backups( int $keep = self::KEEP_TOTAL ): void {
        $all = self::list_backups(); // newest first
        if ( count( $all ) <= $keep ) return;
        foreach ( array_slice( $all, $keep ) as $old ) {
            @unlink( $old['path'] );
        }
    }

    /** Delete one backup by filename. Only deletes files actually present in list_backups() — no arbitrary paths. */
    public static function delete_backup( string $filename ): bool {
        $filename = basename( $filename );
        foreach ( self::list_backups() as $b ) {
            if ( $b['filename'] === $filename ) {
                return (bool) @unlink( $b['path'] );
            }
        }
        return false;
    }

    /** Delete every backup file. @return int number of files deleted */
    public static function delete_all_backups(): int {
        $deleted = 0;
        foreach ( self::list_backups() as $b ) {
            if ( @unlink( $b['path'] ) ) $deleted++;
        }
        return $deleted;
    }

    public static function human_size( int $bytes ): string {
        if ( $bytes < 1024 )       return $bytes . ' B';
        if ( $bytes < 1048576 )    return round( $bytes / 1024, 1 ) . ' KB';
        if ( $bytes < 1073741824 ) return round( $bytes / 1048576, 1 ) . ' MB';
        return round( $bytes / 1073741824, 1 ) . ' GB';
    }
}