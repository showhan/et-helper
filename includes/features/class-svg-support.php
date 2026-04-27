<?php
defined( 'ABSPATH' ) || exit;

/**
 * Feature: SVG Support
 * Safe SVG uploads restricted to administrators.
 */
class ETH_SVG_Support {

    public function __construct() {
        add_filter( 'upload_mimes',                [ $this, 'allow_svg_mimes' ] );
        add_filter( 'wp_check_filetype_and_ext',   [ $this, 'fix_svg_filetype' ], 10, 5 );
        add_filter( 'wp_handle_upload_prefilter',  [ $this, 'svg_prefilter_validate' ] );
        add_filter( 'file_is_displayable_image',   [ $this, 'mark_svg_displayable' ], 10, 2 );
        add_filter( 'wp_prepare_attachment_for_js',[ $this, 'inject_svg_dimensions_for_js' ], 10, 3 );
    }

    public function allow_svg_mimes( array $mimes ): array {
        if ( current_user_can( 'manage_options' ) ) {
            $mimes['svg']  = 'image/svg+xml';
            $mimes['svgz'] = 'image/svg+xml';
        }
        return $mimes;
    }

    public function fix_svg_filetype( array $data, $file, string $filename, $mimes, $real_mime = null ): array {
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'svg', 'svgz' ], true ) ) return $data;
        if ( ! current_user_can( 'manage_options' ) ) {
            return [ 'ext' => false, 'type' => false, 'proper_filename' => false ];
        }
        $data['ext']             = 'svg';
        $data['type']            = 'image/svg+xml';
        $data['proper_filename'] = $filename;
        return $data;
    }

    public function svg_prefilter_validate( array $file ): array {
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'svg', 'svgz' ], true ) ) return $file;

        if ( ! current_user_can( 'manage_options' ) ) {
            $file['error'] = __( 'SVG uploads are restricted to administrators.', 'et-helper' );
            return $file;
        }

        $contents = @file_get_contents( $file['tmp_name'] );
        if ( $contents === false )                          { $file['error'] = __( 'Unable to read uploaded SVG.', 'et-helper' ); return $file; }
        if ( filesize( $file['tmp_name'] ) > 2 * 1024 * 1024 ) { $file['error'] = __( 'SVG is too large (max 2MB).', 'et-helper' ); return $file; }

        $check = strtolower( $contents );
        $bad   = [
            '<script', 'onload=', 'onerror=', 'onmouseover=', 'onclick=', 'javascript:',
            '<foreignobject', 'feimage', 'base64,',
            'xlink:href="http:', "xlink:href='http:",
            'xlink:href="https:', "xlink:href='https:",
            'href="http:', "href='http:", 'href="https:', "href='https:",
            'file:', 'data:text/html', 'document.cookie', '<?xml-stylesheet',
        ];
        foreach ( $bad as $needle ) {
            if ( strpos( $check, $needle ) !== false ) {
                $file['error'] = __( 'Blocked potentially unsafe SVG content.', 'et-helper' );
                return $file;
            }
        }
        if ( strpos( $check, '<svg' ) === false ) {
            $file['error'] = __( 'Invalid SVG file.', 'et-helper' );
            return $file;
        }

        $clean = preg_replace( '#^\s*(<\?xml[^>]*>\s*)?#i', '', $contents );
        $clean = preg_replace( '#<!DOCTYPE[^>]*>#i', '', $clean );
        if ( is_string( $clean ) && $clean !== $contents ) @file_put_contents( $file['tmp_name'], $clean );

        return $file;
    }

    public function mark_svg_displayable( bool $result, string $path ): bool {
        if ( $result ) return true;
        return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), [ 'svg', 'svgz' ], true );
    }

    public function inject_svg_dimensions_for_js( array $response, $attachment, $meta ): array {
        if ( 'image/svg+xml' !== get_post_mime_type( $attachment ) ) return $response;
        $file = get_attached_file( $attachment->ID );
        if ( $file && file_exists( $file ) ) {
            $svg = @file_get_contents( $file );
            if ( $svg && preg_match( '/viewBox="([\d\.\s\-]+)"/i', $svg, $m ) ) {
                $parts = preg_split( '/\s+/', trim( $m[1] ) );
                if ( count( $parts ) === 4 && (float) $parts[2] > 0 && (float) $parts[3] > 0 ) {
                    $response['width']  = (int) round( (float) $parts[2] );
                    $response['height'] = (int) round( (float) $parts[3] );
                }
            }
        }
        if ( empty( $response['width'] ) || empty( $response['height'] ) ) {
            $response['width'] = $response['height'] = 512;
        }
        $response['sizes'] = $response['sizes'] ?? [];
        $response['icon']  = $response['url'];
        return $response;
    }
}
