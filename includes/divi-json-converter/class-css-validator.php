<?php
defined( 'ABSPATH' ) || exit;

/**
 * Divi JSON Converter — CSS Validator
 * Validates CSS strings from code modules, freeForm fields, and element CSS.
 */
class ETH_CSS_Validator {

    private static array $typos = [
        'widght'=>'width','widhth'=>'width','widht'=>'width','wdith'=>'width',
        'heigth'=>'height','hieght'=>'height','hegith'=>'height',
        'margni'=>'margin','mragin'=>'margin','magin'=>'margin',
        'paddin'=>'padding','paddng'=>'padding','padidng'=>'padding',
        'backgorund'=>'background','backgroud'=>'background','backround'=>'background',
        'bordr'=>'border','borde'=>'border','fontt'=>'font','fnt'=>'font',
        'colro'=>'color','cloor'=>'color','clor'=>'color',
        'dipslay'=>'display','disply'=>'display','dispaly'=>'display',
        'positon'=>'position','postion'=>'position',
        'transtion'=>'transition','transiton'=>'transition',
        'transfrom'=>'transform','transofrm'=>'transform',
        'animaton'=>'animation','animaiton'=>'animation',
        'flot'=>'float','foat'=>'float',
        'visiblity'=>'visibility','visibiltiy'=>'visibility',
        'z-idnex'=>'z-index','zindex'=>'z-index',
        'backgrond-color'=>'background-color',
        'text-alighn'=>'text-align','text-algn'=>'text-align',
        'font-wieght'=>'font-weight','font-wheight'=>'font-weight',
        'border-radus'=>'border-radius','border-raidus'=>'border-radius',
        'max-widht'=>'max-width','min-widht'=>'min-width',
        'aling-items'=>'align-items','alighn-items'=>'align-items',
        'jusitfy-content'=>'justify-content','justfiy-content'=>'justify-content',
        'felx'=>'flex','flrx'=>'flex',
        'overfow'=>'overflow','ovreflow'=>'overflow',
    ];

    private static array $valid_props = [
        'margin','margin-top','margin-right','margin-bottom','margin-left',
        'padding','padding-top','padding-right','padding-bottom','padding-left',
        'width','min-width','max-width','height','min-height','max-height',
        'box-sizing','overflow','overflow-x','overflow-y',
        'display','visibility','opacity','float','clear',
        'position','top','right','bottom','left','z-index',
        'flex','flex-direction','flex-wrap','flex-flow','flex-grow','flex-shrink','flex-basis',
        'align-items','align-self','align-content','justify-content','justify-items','justify-self',
        'gap','row-gap','column-gap','order',
        'grid','grid-template','grid-template-columns','grid-template-rows','grid-template-areas',
        'grid-column','grid-row','grid-area','grid-gap',
        'font','font-family','font-size','font-weight','font-style','font-variant',
        'line-height','letter-spacing','word-spacing','text-align','text-decoration',
        'text-transform','text-indent','text-shadow','text-overflow','white-space',
        'word-break','word-wrap','overflow-wrap','vertical-align','direction',
        'color','background','background-color','background-image','background-repeat',
        'background-position','background-size','background-attachment','background-clip',
        'background-origin','background-blend-mode',
        'border','border-top','border-right','border-bottom','border-left',
        'border-width','border-style','border-color','border-radius',
        'border-top-left-radius','border-top-right-radius',
        'border-bottom-left-radius','border-bottom-right-radius',
        'outline','outline-color','outline-style','outline-width','outline-offset',
        'box-shadow','filter','backdrop-filter','transform','transform-origin',
        'transition','transition-property','transition-duration',
        'transition-timing-function','transition-delay',
        'animation','animation-name','animation-duration','animation-timing-function',
        'animation-delay','animation-iteration-count','animation-direction',
        'animation-fill-mode','animation-play-state',
        'list-style','list-style-type','list-style-position','list-style-image',
        'border-collapse','border-spacing','table-layout','caption-side',
        'cursor','pointer-events','user-select','resize','content',
        'clip','clip-path','mask','mask-image','mix-blend-mode',
        'object-fit','object-position','aspect-ratio','scroll-behavior','appearance',
        'will-change','isolation','columns','column-count','column-rule','column-width','column-span',
    ];

    /** Validate a CSS string (full rules or declarations only). */
    public static function validate( string $css ): array {
        $issues     = [];
        $has_braces = strpos( $css, '{' ) !== false;

        if ( $has_braces ) {
            $stripped = preg_replace( '/\/\*.*?\*\//s', '', $css );

            // Mismatched braces
            $opens  = substr_count( $stripped, '{' );
            $closes = substr_count( $stripped, '}' );
            if ( $opens !== $closes ) {
                $issues[] = [ 'line'=>null, 'severity'=>'error',
                    'issue'=>"Mismatched braces: {$opens} opening vs {$closes} closing", 'snippet'=>'' ];
            }

            // Empty rule blocks
            preg_match_all( '/([^{}]+)\{\s*\}/', $stripped, $em, PREG_OFFSET_CAPTURE );
            foreach ( $em[1] as $match ) {
                $ln = substr_count( substr( $css, 0, $match[1] ), "\n" ) + 1;
                $issues[] = [ 'line'=>$ln, 'severity'=>'warning',
                    'issue'=>'Empty rule block for selector: "' . trim( $match[0] ) . '"', 'snippet'=>'' ];
            }

            // Validate declarations inside each block
            preg_match_all( '/[^{}]*\{([^{}]*)\}/s', $stripped, $blocks, PREG_OFFSET_CAPTURE );
            foreach ( $blocks[1] as $bm ) {
                $base = substr_count( substr( $css, 0, $bm[1] ), "\n" );
                $issues = array_merge( $issues, self::validate_declarations( $bm[0], $base ) );
            }
        } else {
            $issues = self::validate_declarations( $css, 0 );
        }

        return self::deduplicate( $issues );
    }

    private static function validate_declarations( string $block, int $base_line ): array {
        $issues = [];
        foreach ( explode( "\n", $block ) as $rel => $raw ) {
            $line   = trim( $raw );
            $abs_ln = $base_line + $rel + 1;

            if ( ! $line || str_starts_with( $line, '/*' ) || str_starts_with( $line, '//' )
                 || $line === '{' || $line === '}' ) continue;
            if ( strpos( $line, ':' ) === false ) continue;

            [ $prop_raw, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
            $prop_raw = rtrim( $prop_raw, ';' );
            $value    = rtrim( $value ?? '', ';' );
            if ( ! $prop_raw ) continue;

            $prop_norm = preg_replace( '/^-(?:webkit|moz|ms|o)-/', '', strtolower( $prop_raw ) );

            if ( isset( self::$typos[ $prop_norm ] ) ) {
                $issues[] = [ 'line'=>$abs_ln, 'severity'=>'error',
                    'issue'=>"Typo in property \"{$prop_raw}\" — did you mean \"" . self::$typos[$prop_norm] . "\"?",
                    'snippet'=>$line ];
                continue;
            }
            if ( strpos( $prop_raw, ' ' ) !== false && ! str_starts_with( $prop_raw, '--' ) ) {
                $issues[] = [ 'line'=>$abs_ln, 'severity'=>'error',
                    'issue'=>"Space in property name: \"{$prop_raw}\"", 'snippet'=>$line ];
                continue;
            }
            if ( ! in_array( $prop_norm, self::$valid_props, true )
                 && ! str_starts_with( $prop_raw, '--' )
                 && ! preg_match( '/^-(?:webkit|moz|ms|o)-/', $prop_raw ) ) {
                $issues[] = [ 'line'=>$abs_ln, 'severity'=>'warning',
                    'issue'=>"Unknown CSS property: \"{$prop_raw}\"", 'snippet'=>$line ];
            }
            $sl = rtrim( $line );
            if ( $sl && ! str_ends_with( $sl, ';' ) && ! str_ends_with( $sl, '{' )
                 && ! str_ends_with( $sl, '}' ) && ! str_ends_with( $sl, ',' )
                 && strpos( $sl, ':' ) !== false && ! str_starts_with( $sl, '/*' ) ) {
                $issues[] = [ 'line'=>$abs_ln, 'severity'=>'warning',
                    'issue'=>"Missing semicolon: \"{$sl}\"", 'snippet'=>$line ];
            }
        }
        return $issues;
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
