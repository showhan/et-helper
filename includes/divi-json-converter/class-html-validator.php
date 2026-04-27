<?php
defined( 'ABSPATH' ) || exit;

/**
 * Divi JSON Converter — HTML Validator
 * Validates HTML content in text-bearing Divi modules.
 */
class ETH_HTML_Validator {

    private static array $void_tags = [
        'area','base','br','col','embed','hr','img','input',
        'link','meta','param','source','track','wbr',
    ];

    private static array $valid_tags = [
        'a','abbr','address','article','aside','audio','b','bdi','bdo','blockquote',
        'br','button','canvas','caption','cite','code','col','colgroup','data',
        'datalist','dd','del','details','dfn','dialog','div','dl','dt','em',
        'fieldset','figcaption','figure','footer','form','h1','h2','h3','h4','h5','h6',
        'header','hr','i','iframe','img','input','ins','kbd','label','legend','li',
        'main','mark','menu','meter','nav','noscript','object','ol','optgroup','option',
        'output','p','picture','pre','progress','q','rp','rt','ruby','s','samp',
        'section','select','small','source','span','strong','sub','summary','sup',
        'table','tbody','td','template','textarea','tfoot','th','thead','time','tr',
        'track','u','ul','var','video','wbr','svg','path','circle','rect','g',
    ];

    /**
     * Return all text-bearing fields for a given module type.
     * @return array[] List of [ field_label, html_text ]
     */
    public static function get_text_fields( string $type, array $attrs ): array {
        $fields = [];
        switch ( $type ) {
            case 'text':
                foreach ( [ 'desktop', 'tablet', 'phone' ] as $bp ) {
                    $v = $attrs['content']['innerContent'][$bp]['value'] ?? '';
                    if ( $v ) $fields[] = [ "content [$bp]", $v ];
                }
                break;
            case 'blurb':
                $tv = $attrs['title']['innerContent']['desktop']['value'] ?? '';
                if ( is_array( $tv ) ) $tv = $tv['text'] ?? '';
                if ( $tv ) $fields[] = [ 'title', $tv ];
                $bv = $attrs['content']['innerContent']['desktop']['value'] ?? '';
                if ( $bv ) $fields[] = [ 'content', $bv ];
                break;
            case 'button':
                $v = $attrs['button']['innerContent']['desktop']['value'] ?? [];
                if ( is_array( $v ) && ! empty( $v['text'] ) ) $fields[] = [ 'button text', $v['text'] ];
                break;
            case 'number-counter':
                $v = $attrs['title']['innerContent']['desktop']['value'] ?? '';
                if ( $v ) $fields[] = [ 'title', $v ];
                break;
            case 'icon-list-item':
                $v = $attrs['content']['innerContent']['desktop']['value'] ?? '';
                if ( $v ) $fields[] = [ 'content', $v ];
                break;
            case 'contact-field':
                $v = $attrs['fieldItem']['innerContent']['desktop']['value'] ?? '';
                if ( $v ) $fields[] = [ 'label', $v ];
                break;
            case 'image':
                $img = $attrs['image']['innerContent']['desktop']['value'] ?? [];
                if ( ! empty( $img['alt'] ) )       $fields[] = [ 'alt',   $img['alt'] ];
                if ( ! empty( $img['titleText'] ) )  $fields[] = [ 'title', $img['titleText'] ];
                break;
        }
        return $fields;
    }

    /** Validate an HTML string. Returns list of issue dicts. */
    public static function validate( string $html ): array {
        if ( ! trim( $html ) ) return [];
        $issues = [];

        // ── Regex pre-checks ─────────────────────────────────────────────────
        // Unquoted attribute values
        preg_match_all( '/<\w[^>]*\s([\w-]+)=([^"\'\s>][^\s>]*)/', $html, $uq, PREG_OFFSET_CAPTURE );
        foreach ( $uq[1] as $i => $am ) {
            $ln = substr_count( substr( $html, 0, $am[1] ), "\n" ) + 1;
            $issues[] = [ 'line'=>$ln, 'severity'=>'error',
                'issue'=>"Unquoted attribute value: {$am[0]}={$uq[2][$i][0]}",
                'snippet'=>substr( $html, $am[1], 80 ) ];
        }
        // Unclosed attribute quote
        preg_match_all( '/([\w-]+)="([^"]*=>[^"]*)/', $html, $uclose, PREG_OFFSET_CAPTURE );
        foreach ( $uclose[1] as $i => $am ) {
            $val = $uclose[2][$i][0];
            $ln  = substr_count( substr( $html, 0, $am[1] ), "\n" ) + 1;
            $issues[] = [ 'line'=>$ln, 'severity'=>'error',
                'issue'=>"Possibly unclosed quote in attribute: {$am[0]}=\"{$val}\"",
                'snippet'=>substr( $val, 0, 80 ) ];
        }
        // Block inside <span>
        if ( preg_match( '/<span[^>]*>.*?<(?:div|p|h[1-6]|ul|ol|table|section)[^>]*>/si', $html ) ) {
            $issues[] = [ 'line'=>null, 'severity'=>'warning',
                'issue'=>'Block-level element nested inside <span>', 'snippet'=>'' ];
        }
        // Nested <a>
        if ( preg_match( '/<a[^>]*>.*?<a[^>]*>/si', $html ) ) {
            $issues[] = [ 'line'=>null, 'severity'=>'warning',
                'issue'=>'Nested <a> element inside another <a>', 'snippet'=>'' ];
        }

        // ── Tag balance ───────────────────────────────────────────────────────
        preg_match_all( '/<\/?([a-zA-Z][a-zA-Z0-9-]*)(?:\s[^>]*)?>/', $html, $tm, PREG_OFFSET_CAPTURE );
        $stack = [];
        foreach ( $tm[0] as $i => $tag_match ) {
            $full     = $tag_match[0];
            $offset   = $tag_match[1];
            $tag      = strtolower( $tm[1][$i][0] );
            $ln       = substr_count( substr( $html, 0, $offset ), "\n" ) + 1;
            $is_close = str_starts_with( $full, '</' );
            $is_self  = str_ends_with( rtrim( $full ), '/>' );

            if ( $is_close ) {
                if ( in_array( $tag, self::$void_tags ) ) {
                    $issues[] = [ 'line'=>$ln, 'severity'=>'warning',
                        'issue'=>"Closing tag for void element: </{$tag}>", 'snippet'=>"</{$tag}>" ];
                } elseif ( empty( $stack ) ) {
                    $issues[] = [ 'line'=>$ln, 'severity'=>'error',
                        'issue'=>"Unexpected closing tag (nothing is open): </{$tag}>", 'snippet'=>"</{$tag}>" ];
                } elseif ( end( $stack )['tag'] === $tag ) {
                    array_pop( $stack );
                } else {
                    $found = false;
                    foreach ( array_reverse( $stack, true ) as $s ) {
                        if ( $s['tag'] === $tag ) { $found = true; break; }
                    }
                    if ( $found ) {
                        while ( ! empty( $stack ) && end( $stack )['tag'] !== $tag ) {
                            $u = array_pop( $stack );
                            $issues[] = [ 'line'=>$ln, 'severity'=>'error',
                                'issue'=>"Implicitly closed <{$u['tag']}> by </{$tag}>",
                                'snippet'=>"</{$u['tag']}> missing before </{$tag}>" ];
                        }
                        if ( ! empty( $stack ) ) array_pop( $stack );
                    } else {
                        $issues[] = [ 'line'=>$ln, 'severity'=>'error',
                            'issue'=>"Closing tag </{$tag}> has no matching opening tag",
                            'snippet'=>"</{$tag}>" ];
                    }
                }
            } elseif ( ! $is_self && ! in_array( $tag, self::$void_tags ) ) {
                if ( ! in_array( $tag, self::$valid_tags ) ) {
                    $issues[] = [ 'line'=>$ln, 'severity'=>'warning',
                        'issue'=>"Unknown or non-standard HTML tag: <{$tag}>",
                        'snippet'=>substr( $full, 0, 80 ) ];
                }
                preg_match_all( '/\s[\w:-]+(?:=(?:"([^"]*)"|\'([^\']*)\'|[^\s>]*))?/', $full, $am );
                foreach ( $am[1] as $aval ) {
                    if ( preg_match( '/[<>]/', $aval ) ) {
                        $issues[] = [ 'line'=>$ln, 'severity'=>'error',
                            'issue'=>"Attribute value contains unescaped < or >: \"" . substr( $aval, 0, 60 ) . '"',
                            'snippet'=>substr( $full, 0, 80 ) ];
                    }
                }
                $stack[] = [ 'tag'=>$tag, 'line'=>$ln ];
            }
        }
        // Remaining unclosed tags
        foreach ( array_reverse( $stack ) as $s ) {
            $issues[] = [ 'line'=>null, 'severity'=>'error',
                'issue'=>"Unclosed tag: <{$s['tag']}>",
                'snippet'=>"<{$s['tag']}> opened on line {$s['line']} was never closed" ];
        }

        return self::deduplicate( $issues );
    }

    private static function deduplicate( array $issues ): array {
        $seen = []; $out = [];
        foreach ( $issues as $i ) {
            $k = ( $i['line'] ?? '' ) . '|' . substr( $i['issue'], 0, 80 );
            if ( ! isset( $seen[$k] ) ) { $seen[$k] = true; $out[] = $i; }
        }
        return $out;
    }
}
