<?php
defined( 'ABSPATH' ) || exit;

/**
 * Divi JSON Converter — Converter
 * Orchestrates parsing, annotation, CSS extraction, and validation.
 */
class ETH_Converter {

    /**
     * Convert a raw Divi export JSON string into the fully annotated output JSON.
     *
     * @throws Exception on parse failure.
     */
    public static function convert( string $raw_json, bool $remove_images = true ): string {
        $root = json_decode( $raw_json, true );
        if ( $root === null )
            throw new Exception( 'Invalid JSON: could not parse the uploaded file.' );
        if ( ! isset( $root['data'] ) || ! is_array( $root['data'] ) )
            throw new Exception( 'Unexpected format: missing "data" object.' );

        $css_registry = [];
        $invalid_css  = [];
        $invalid_html = [];
        $converted    = [];

        foreach ( $root['data'] as $page_id => $raw_markup ) {
            if ( ! is_string( $raw_markup ) ) { $converted[$page_id] = $raw_markup; continue; }
            $tree = ETH_Block_Parser::parse( $raw_markup );
            $converted[$page_id] = self::annotate(
                $tree, null, null, null, null, null,
                $css_registry, $invalid_css, $invalid_html
            );
        }

        [ $free_form_css, $element_css ] = self::split_css_registry( $css_registry );

        $output = [
            'context'            => $root['context']            ?? null,
            'data'               => $converted,
            'free_form_css'      => $free_form_css,
            'element_css'        => $element_css,
            'invalid_css'        => $invalid_css,
            'invalid_html'       => $invalid_html,
            'presets'            => $root['presets']            ?? null,
            'global_colors'      => $root['global_colors']      ?? [],
            'global_variables'   => $root['global_variables']   ?? [],
            'page_settings_meta' => $root['page_settings_meta'] ?? [],
            'canvases'           => $root['canvases']           ?? [],
            'thumbnails'         => $root['thumbnails']         ?? [],
        ];
        if ( ! $remove_images && array_key_exists( 'images', $root ) ) {
            $output['images'] = $root['images'];
        }

        return json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    // ── Annotator ─────────────────────────────────────────────────────────────

    private static function annotate(
        array $blocks,
        ?int $sn, ?int $rn, ?int $cn, ?int $mn, $cc,
        array &$css_registry, array &$invalid_css, array &$invalid_html
    ): array {
        $result = [];
        $ls = $lr = $lc = $lm = $lch = 0;
        $inside = $cc !== null;

        foreach ( $blocks as $block ) {
            $bt    = $block['type'];
            $attrs = $block['attributes'];

            // ── Label / breadcrumb / index ────────────────────────────────
            if ( ! $inside ) {
                if ( $bt === 'section' ) {
                    $ls++; $label="Section $ls"; $crumb=$label;
                    $idx=['section'=>$ls]; $ra=[$ls,null,null,null,null];
                } elseif ( $bt === 'row' ) {
                    $lr++; $label="Row $lr"; $crumb="Section $sn > Row $lr";
                    $idx=['section'=>$sn,'row'=>$lr]; $ra=[$sn,$lr,null,null,null];
                } elseif ( $bt === 'column' ) {
                    $lc++; $label="Column $lc"; $crumb="Section $sn > Row $rn > Column $lc";
                    $idx=['section'=>$sn,'row'=>$rn,'column'=>$lc]; $ra=[$sn,$rn,$lc,null,null];
                } else {
                    $lm++; $label="Module $lm";
                    $crumb="Section $sn > Row $rn > Column $cn > Module $lm";
                    $idx=['section'=>$sn,'row'=>$rn,'column'=>$cn,'module'=>$lm];
                    $ra=[$sn,$rn,$cn,$lm,[]];
                }
            } else {
                $lch++; $label="$bt Child $lch";
                $base="Section $sn > Row $rn > Column $cn > Module $mn";
                $chain = ! empty($cc) ? implode(' > ', array_map(fn($p)=>"{$p[0]} Child {$p[1]}",$cc)) : '';
                $crumb = $base . ( $chain ? " > $chain" : '' ) . " > $label";
                $idx   = ['section'=>$sn,'row'=>$rn,'column'=>$cn,'module'=>$mn];
                foreach ($cc as $d=>$p) $idx['child_level_'.($d+1)]=[$p[0]=>$p[1]];
                $idx['child_level_'.(count($cc)+1)]=[$bt=>$lch];
                $nc = array_merge($cc,[[$bt,$lch]]); $ra=[$sn,$rn,$cn,$mn,$nc];
            }

            // ── CSS: code module <style> blocks ───────────────────────────
            if ( $bt === 'code' ) {
                $raw_html = $attrs['content']['innerContent']['desktop']['value'] ?? '';
                preg_match_all( '/<style[^>]*>(.*?)<\/style>/si', $raw_html, $sm );
                foreach ( $sm[1] as $css_text ) {
                    $issues = ETH_CSS_Validator::validate( $css_text );
                    if ( $issues ) {
                        $invalid_css[] = self::css_entry(
                            'code_module', $bt, $label, $idx, $crumb, null, null, $css_text, $issues );
                    }
                }
            }

            // ── CSS: freeForm and element-level ───────────────────────────
            if ( ! empty( $attrs['css'] ) ) {
                $css_by_bp = [];
                foreach ( $attrs['css'] as $bp => $bpval ) {
                    $val = is_array( $bpval ) ? ( $bpval['value'] ?? [] ) : [];
                    if ( empty( $val ) ) continue;
                    $css_by_bp[$bp] = $val;
                    foreach ( $val as $subkey => $css_text ) {
                        if ( ! $css_text || ! trim( $css_text ) ) continue;
                        $issues = ETH_CSS_Validator::validate( $css_text );
                        if ( $issues ) {
                            $src = $subkey === 'freeForm' ? 'free_form_css' : 'element_css';
                            $invalid_css[] = self::css_entry(
                                $src, $bt, $label, $idx, $crumb, $bp, $subkey, $css_text, $issues );
                        }
                    }
                }
                if ( ! empty( $css_by_bp ) ) {
                    $css_registry[] = [
                        'component_type' => $bt, 'label' => $label,
                        '_index' => $idx, '_breadcrumb' => $crumb, 'css' => $css_by_bp,
                    ];
                }
            }

            // ── HTML validation ───────────────────────────────────────────
            foreach ( ETH_HTML_Validator::get_text_fields( $bt, $attrs ) as [ $field_label, $html_text ] ) {
                $issues = ETH_HTML_Validator::validate( $html_text );
                if ( $issues ) {
                    $invalid_html[] = [
                        'source'         => 'html_content',
                        'component_type' => $bt,
                        'label'          => $label,
                        '_index'         => $idx,
                        '_breadcrumb'    => $crumb,
                        'field'          => $field_label,
                        'html_snippet'   => substr( trim( $html_text ), 0, 400 ),
                        'issues'         => $issues,
                    ];
                }
            }

            // ── Recurse ───────────────────────────────────────────────────
            $children_out = ! empty( $block['children'] )
                ? self::annotate( $block['children'], ...$ra,
                    css_registry: $css_registry, invalid_css: $invalid_css, invalid_html: $invalid_html )
                : null;

            // ── Assemble node ─────────────────────────────────────────────
            $node = [
                '_start'      => "$label started",
                'type'        => $bt,
                '_index'      => $idx,
                '_breadcrumb' => $crumb,
                'attributes'  => $attrs,
            ];
            if ( $children_out !== null ) $node['children'] = $children_out;
            $node['_end'] = "$label ended";
            $result[] = $node;
        }
        return $result;
    }

    // ── CSS registry splitter ─────────────────────────────────────────────────

    private static function split_css_registry( array $registry ): array {
        $free = []; $elem = [];
        foreach ( $registry as $entry ) {
            $has_free = $has_elem = false;
            foreach ( $entry['css'] as $bpv ) {
                if ( isset($bpv['freeForm']) ) $has_free = true;
                if ( count(array_diff_key($bpv,['freeForm'=>1])) > 0 ) $has_elem = true;
            }
            $base = [ 'component_type'=>$entry['component_type'], 'label'=>$entry['label'],
                      '_index'=>$entry['_index'], '_breadcrumb'=>$entry['_breadcrumb'] ];
            if ( $has_free ) {
                $ffc = $base;
                $ffc['css'] = array_values( array_filter( array_map(
                    fn($bp,$bpv) => isset($bpv['freeForm'])
                        ? ['breakpoint'=>$bp,'freeForm'=>$bpv['freeForm']] : null,
                    array_keys($entry['css']), $entry['css']
                )));
                $free[] = $ffc;
            }
            if ( $has_elem ) {
                $ec = $base;
                $ec['css'] = array_values( array_filter( array_map(
                    fn($bp,$bpv) => ($f=array_diff_key($bpv,['freeForm'=>1]))
                        ? array_merge(['breakpoint'=>$bp],$f) : null,
                    array_keys($entry['css']), $entry['css']
                )));
                $elem[] = $ec;
            }
        }
        return [ $free, $elem ];
    }

    private static function css_entry(
        string $source, string $bt, string $label, array $idx, string $crumb,
        ?string $bp, ?string $subkey, string $css_text, array $issues
    ): array {
        $e = [
            'source'         => $source,
            'component_type' => $bt,
            'label'          => $label,
            '_index'         => $idx,
            '_breadcrumb'    => $crumb,
            'css_snippet'    => substr( trim( $css_text ), 0, 500 ),
            'issues'         => $issues,
        ];
        if ( $bp     !== null ) $e['breakpoint'] = $bp;
        if ( $subkey !== null ) $e['css_field']  = $subkey;
        return $e;
    }
}
