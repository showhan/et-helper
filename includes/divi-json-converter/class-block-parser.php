<?php
defined( 'ABSPATH' ) || exit;

/**
 * Divi JSON Converter — Block Parser
 * Tokenises raw Divi HTML-comment markup and builds a nested block tree.
 */
class ETH_Block_Parser {

    /** Parse raw Divi markup string into a nested block array. */
    public static function parse( string $raw ): array {
        preg_match_all( '/<!--(.*?)-->/s', $raw, $matches );
        $tokens = [];

        foreach ( $matches[1] as $inner ) {
            $inner = trim( $inner );
            if ( preg_match( '/^\/?wp:divi\/placeholder/', $inner ) ) continue;

            // Closing tag
            if ( preg_match( '/^\/wp:divi\/([\w-]+)$/', $inner, $m ) ) {
                $tokens[] = [ 'kind' => 'close', 'type' => $m[1] ];
                continue;
            }

            // Open or self-closing
            if ( preg_match( '/^wp:divi\/([\w-]+)(?:\s+(\{.*\}))?\s*(\/)?$/s', $inner, $m ) ) {
                $js    = $m[2] ?? '';
                $attrs = [];
                if ( $js !== '' ) {
                    $decoded = json_decode( str_replace( '\\"', '"', $js ), true );
                    $attrs   = $decoded ?? [ '_parseError' => json_last_error_msg(), '_raw' => substr( $js, 0, 300 ) ];
                }
                $tokens[] = [
                    'kind'  => empty( $m[3] ) ? 'open' : 'self',
                    'type'  => $m[1],
                    'attrs' => $attrs,
                ];
            }
        }

        return self::build_tree( $tokens );
    }

    private static function build_tree( array $tokens ): array {
        $stack = [ [ 'type' => 'root', 'children' => [] ] ];
        foreach ( $tokens as $tok ) {
            $top = count( $stack ) - 1;
            if ( $tok['kind'] === 'open' ) {
                $node = [ 'type' => $tok['type'], 'attributes' => $tok['attrs'], 'children' => [] ];
                $stack[$top]['children'][] = &$node;
                $stack[] = &$node;
                unset( $node );
            } elseif ( $tok['kind'] === 'self' ) {
                $stack[$top]['children'][] = [ 'type' => $tok['type'], 'attributes' => $tok['attrs'] ];
            } elseif ( $tok['kind'] === 'close' && count( $stack ) > 1 ) {
                array_pop( $stack );
            }
        }
        return $stack[0]['children'];
    }
}
