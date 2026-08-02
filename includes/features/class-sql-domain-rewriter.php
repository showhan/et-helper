<?php
defined( 'ABSPATH' ) || exit;

/**
 * Feature: SQL Domain Rewriter
 * Rewrites an old site URL/domain to a new one throughout a SQL dump,
 * safely handling PHP-serialized values (so byte-length prefixes stay
 * correct and the import doesn't produce corrupted options/postmeta).
 * Also supports swapping the wp_users row's login/password/email for
 * the currently logged-in admin's own values.
 */
class ETH_SQL_Domain_Rewriter {

    // ── Public: domain rewriting ────────────────────────────────────────────

    /** Best-effort detection of the dump's current site URL. */
    public static function detect_old_url( string $sql ): ?string {
        if ( preg_match( "/'siteurl'\s*,\s*'([^']*)'/", $sql, $m ) ) return rtrim( $m[1], '/' );
        if ( preg_match( "/'home'\s*,\s*'([^']*)'/", $sql, $m ) )    return rtrim( $m[1], '/' );
        if ( preg_match( '#https?://[A-Za-z0-9.\-]+#', $sql, $m ) )  return rtrim( $m[0], '/' );
        return null;
    }

    /** Build the (old,new) string pairs worth trying: exact URL, both schemes, bare host. */
    public static function build_url_pairs( string $old_url, string $new_url ): array {
        $old_url = untrailingslashit( $old_url );
        $new_url = untrailingslashit( $new_url );
        $pairs   = [ [ $old_url, $new_url ] ];

        $old_host = wp_parse_url( $old_url, PHP_URL_HOST ) ?: $old_url;
        $new_host = wp_parse_url( $new_url, PHP_URL_HOST ) ?: $new_url;

        if ( $old_host && $new_host && $old_host !== $new_host ) {
            $pairs[] = [ 'http://' . $old_host,  'http://' . $new_host ];
            $pairs[] = [ 'https://' . $old_host, 'https://' . $new_host ];
            $pairs[] = [ $old_host, $new_host ];
        }
        return $pairs;
    }

    /** Apply a sequence of (old,new) replacements across the whole dump. */
    public static function rewrite_domain_multi( string $sql, array $pairs ): string {
        foreach ( $pairs as [ $old, $new ] ) {
            if ( $old === '' || $old === $new ) continue;
            $sql = self::rewrite_domain( $sql, $old, $new );
        }
        return $sql;
    }

    /** Serialization-safe replace of $old with $new across every string literal in the dump. */
    public static function rewrite_domain( string $sql, string $old, string $new ): string {
        if ( $old === '' || $old === $new ) return $sql;

        $statements = ETH_SQL_Splitter::split( $sql );
        $out        = [];
        foreach ( $statements as $stmt ) {
            $out[] = self::rewrite_literals_in_statement(
                $stmt,
                fn( string $v ) => self::transform_value( $v, $old, $new )
            );
        }
        return implode( ";\n", $out ) . ";\n";
    }

    /**
     * WordPress bakes its table prefix directly into several specific option_name /
     * meta_key VALUES (not identifiers) — most critically wp_options' "{$prefix}user_roles"
     * (the role→capabilities definitions) and wp_usermeta's "{$prefix}capabilities" /
     * "{$prefix}user_level" (which role a given user has). This is so a shared,
     * multisite-wide install can tell each site's roles and users apart. Renaming
     * table/column identifiers alone never touches these plain string values, so if
     * they're missed: a user can still have the "administrator" capability flag, but
     * WordPress can't find any role definitions to resolve what that flag actually
     * grants, and denies everything — "Sorry, you are not allowed to access this page."
     * This does an exact-match replace of just these known key names — nothing else in
     * the dump is touched, so there's no risk of collateral damage to real content.
     */
    public static function rewrite_prefixed_wp_keys( string $sql, string $new_prefix, string $old_prefix = 'wp_' ): string {
        if ( $new_prefix === $old_prefix ) return $sql;

        $map = [];
        foreach ( [ 'user_roles', 'capabilities', 'user_level', 'dashboard_quick_press_last_post_id', 'user-settings', 'user-settings-time' ] as $suffix ) {
            $map[ $old_prefix . $suffix ] = $new_prefix . $suffix;
        }

        $statements = ETH_SQL_Splitter::split( $sql );
        $out        = [];
        foreach ( $statements as $stmt ) {
            $out[] = self::rewrite_literals_in_statement(
                $stmt,
                fn( string $v ) => $map[ $v ] ?? $v
            );
        }
        return implode( ";\n", $out ) . ";\n";
    }

    // ── Public: wp_users credential swap ────────────────────────────────────

    /**
     * Replace user_login / user_pass / user_nicename / user_email / display_name
     * in every row of the dump's {$prefix}users INSERT statement(s) with $creds.
     * Only touches tuples that have exactly the 10 columns of WP core's default
     * wp_users schema (ID, user_login, user_pass, user_nicename, user_email,
     * user_url, user_registered, user_activation_key, user_status, display_name);
     * anything else is left untouched as a safety measure.
     */
    public static function rewrite_users_credentials( string $sql, string $prefix, array $creds ): string {
        $table      = preg_quote( $prefix . 'users', '/' );
        $statements = ETH_SQL_Splitter::split( $sql );
        $out        = [];

        foreach ( $statements as $stmt ) {
            if ( preg_match( '/^INSERT\s+INTO\s+`?' . $table . '`?\s+VALUES\s*(.*)$/is', trim( $stmt ), $m ) ) {
                $stmt = self::replace_users_values( $stmt, $creds );
            }
            $out[] = $stmt;
        }
        return implode( ";\n", $out ) . ";\n";
    }

    private static function replace_users_values( string $stmt, array $creds ): string {
        if ( ! preg_match( '/^(.*?VALUES\s*)(.*)$/is', $stmt, $m ) ) return $stmt;
        [ , $prefix, $tuples_blob ] = $m;

        $tuple_strs = self::split_top_level( $tuples_blob, ',' );
        $rebuilt    = [];

        foreach ( $tuple_strs as $tuple ) {
            $tuple = trim( $tuple );
            if ( $tuple === '' ) continue;
            $inner = $tuple;
            if ( str_starts_with( $inner, '(' ) && str_ends_with( rtrim( $inner, " \t\n" ), ')' ) ) {
                $inner = substr( rtrim( $inner ), 1, -1 );
            }
            $fields = self::split_top_level( $inner, ',' );

            // Only touch rows matching WP core's standard 10-column wp_users schema.
            if ( count( $fields ) === 10 ) {
                $map = [ 1 => 'user_login', 2 => 'user_pass', 3 => 'user_nicename', 4 => 'user_email', 9 => 'display_name' ];
                foreach ( $map as $idx => $key ) {
                    if ( ! isset( $creds[ $key ] ) ) continue;
                    $fields[ $idx ] = "'" . self::escape_string_literal( (string) $creds[ $key ] ) . "'";
                }
            }
            $rebuilt[] = '(' . implode( ',', $fields ) . ')';
        }

        return $prefix . implode( ',', $rebuilt );
    }

    // ── Statement-level literal rewriting ───────────────────────────────────

    private static function rewrite_literals_in_statement( string $stmt, callable $transform ): string {
        $out = ''; $i = 0; $len = strlen( $stmt );

        while ( $i < $len ) {
            $ch = $stmt[ $i ];

            if ( $ch === '`' ) {
                $out .= $ch; $i++;
                while ( $i < $len && $stmt[ $i ] !== '`' ) { $out .= $stmt[ $i ]; $i++; }
                if ( $i < $len ) { $out .= $stmt[ $i ]; $i++; }
                continue;
            }

            if ( $ch === "'" ) {
                $parsed  = self::parse_string_literal( $stmt, $i );
                $newVal  = $transform( $parsed['value'] );
                $out    .= "'" . self::escape_string_literal( $newVal ) . "'";
                $i       = $parsed['end_pos'];
                continue;
            }

            $out .= $ch; $i++;
        }
        return $out;
    }

    /** $sql[$start] must be the opening quote. Returns decoded value + position just past the closing quote. */
    private static function parse_string_literal( string $sql, int $start ): array {
        $i = $start + 1; $len = strlen( $sql ); $val = '';
        $escape_map = [ '0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => "\x1a", '\\' => '\\', "'" => "'", '"' => '"' ];

        while ( $i < $len ) {
            $ch = $sql[ $i ];
            if ( $ch === '\\' && $i + 1 < $len ) {
                $next = $sql[ $i + 1 ];
                $val .= $escape_map[ $next ] ?? $next;
                $i   += 2;
                continue;
            }
            if ( $ch === "'" ) {
                if ( $i + 1 < $len && $sql[ $i + 1 ] === "'" ) { $val .= "'"; $i += 2; continue; }
                $i++;
                break;
            }
            $val .= $ch; $i++;
        }
        return [ 'value' => $val, 'end_pos' => $i ];
    }

    private static function escape_string_literal( string $value ): string {
        return str_replace(
            [ '\\', "\0", "\n", "\r", "'", "\x1a" ],
            [ '\\\\', '\\0', '\\n', '\\r', "\\'", '\\Z' ],
            $value
        );
    }

    /** Split on a top-level delimiter, respecting quotes and parenthesis nesting. */
    private static function split_top_level( string $s, string $delim ): array {
        $parts = []; $buf = ''; $depth = 0; $quote = null; $len = strlen( $s ); $i = 0;

        while ( $i < $len ) {
            $ch = $s[ $i ];
            if ( $quote !== null ) {
                $buf .= $ch;
                if ( $ch === '\\' && $i + 1 < $len ) { $buf .= $s[ $i + 1 ]; $i += 2; continue; }
                if ( $ch === $quote ) $quote = null;
                $i++; continue;
            }
            if ( $ch === "'" || $ch === '"' ) { $quote = $ch; $buf .= $ch; $i++; continue; }
            if ( $ch === '(' ) { $depth++; $buf .= $ch; $i++; continue; }
            if ( $ch === ')' ) { $depth--; $buf .= $ch; $i++; continue; }
            if ( $ch === $delim && $depth === 0 ) { $parts[] = $buf; $buf = ''; $i++; continue; }
            $buf .= $ch; $i++;
        }
        if ( trim( $buf ) !== '' ) $parts[] = $buf;
        return $parts;
    }

    // ── Value-level (possibly serialized) replace ───────────────────────────

    private static function transform_value( string $value, string $old, string $new ): string {
        if ( self::is_serialized( $value ) ) {
            $data = @unserialize( $value, [ 'allowed_classes' => [ 'stdClass' ] ] );
            if ( $data !== false || $value === 'b:0;' ) {
                return (string) serialize( self::deep_replace( $data, $old, $new ) );
            }
        }
        return str_replace( $old, $new, $value );
    }

    private static function deep_replace( mixed $data, string $old, string $new ): mixed {
        if ( is_string( $data ) ) return str_replace( $old, $new, $data );
        if ( is_array( $data ) ) {
            $out = [];
            foreach ( $data as $k => $v ) {
                $nk = is_string( $k ) ? str_replace( $old, $new, $k ) : $k;
                $out[ $nk ] = self::deep_replace( $v, $old, $new );
            }
            return $out;
        }
        if ( is_object( $data ) ) {
            if ( $data instanceof __PHP_Incomplete_Class ) return $data; // unknown class — leave untouched, don't crash
            foreach ( $data as $k => $v ) {
                $data->$k = self::deep_replace( $v, $old, $new );
            }
            return $data;
        }
        return $data;
    }

    private static function is_serialized( string $value ): bool {
        $value = trim( $value );
        if ( $value === 'N;' ) return true;
        if ( strlen( $value ) < 4 || $value[1] !== ':' ) return false;
        switch ( $value[0] ) {
            case 's':
                return (bool) preg_match( '/^s:[0-9]+:"/', $value ) && str_ends_with( $value, '";' );
            case 'a':
            case 'O':
                return (bool) preg_match( '/^' . $value[0] . ':[0-9]+:/', $value );
            case 'b':
            case 'i':
            case 'd':
                return (bool) preg_match( '/^' . $value[0] . ':[0-9.E+-]+;$/', $value );
        }
        return false;
    }
}