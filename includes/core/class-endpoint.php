<?php
namespace KitgenixDocumentManager\Core;

defined( 'ABSPATH' ) || exit;

final class Endpoint {

    public const QUERY_VAR = 'kitgenix_document_manager_slug';
    public const BASE      = 'kitgenix-document-manager';

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ __CLASS__, 'add_query_var' ] );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_serve_document' ] );
    }

    public static function add_query_var( array $vars ): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^' . preg_quote( self::BASE, '/' ) . '/([^/]+)/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    public static function maybe_serve_document(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- front-end request routing
        $slug = (string) get_query_var( self::QUERY_VAR );
        $slug = sanitize_title_with_dashes( $slug );
        if ( $slug === '' ) {
            return;
        }

        $post = get_page_by_path( $slug, OBJECT, CPT::POST_TYPE );
        if ( ! ( $post instanceof \WP_Post ) || $post->post_type !== CPT::POST_TYPE ) {
            self::send_404();
        }

        if ( $post->post_status !== 'publish' ) {
            self::send_404();
        }

        $document_id = (int) $post->ID;

        $attachment_id = (int) get_post_meta( $document_id, '_kitgenix_document_manager_current_attachment_id', true );
        if ( $attachment_id <= 0 ) {
            self::send_404();
        }

        $access = kitgenix_document_manager_evaluate_document_access(
            $document_id,
            [
                'surface' => 'delivery',
            ]
        );

        if ( empty( $access['allowed'] ) ) {
            if ( ! empty( $access['requires_login'] ) ) {
                auth_redirect();
                exit;
            }

            $http_status = (int) ( $access['http_status'] ?? 403 );
            if ( $http_status === 410 ) {
                self::send_gone();
            }

            if ( $http_status === 404 ) {
                self::send_404();
            }

            self::send_403();
        }

        $visibility         = (string) ( $access['visibility'] ?? kitgenix_document_manager_get_document_visibility( $document_id ) );
        $has_granular_rules = ! empty( kitgenix_document_manager_get_document_allowed_roles( $document_id ) )
            || ! empty( kitgenix_document_manager_get_document_allowed_user_ids( $document_id ) )
            || kitgenix_document_manager_get_document_required_capability( $document_id ) !== '';
        $is_protected       = $visibility !== 'public' || $has_granular_rules || ! empty( $access['is_signed_request'] );

        $filepath = get_attached_file( $attachment_id );
        $filepath = is_string( $filepath ) ? $filepath : '';
        if ( $filepath === '' || ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
            self::send_404();
        }

        // Prevent path traversal via poisoned attachment meta.
        $upload = wp_upload_dir( null, false );
        $basedir = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
        if ( $basedir ) {
            $real_base = realpath( $basedir );
            $real_file = realpath( $filepath );
            if ( $real_base && $real_file && strpos( $real_file, $real_base ) !== 0 ) {
                self::send_404();
            }
        }

        $settings = kitgenix_document_manager_get_settings();
        $mode = (string) ( $settings['serving_mode'] ?? 'attachment' );
        $requested_disposition = ( $mode === 'inline' ) ? 'inline' : 'attachment';

        $filename = wp_basename( $filepath );
        // MIME detection order:
        // 1) WordPress smart detection (extension + file inspection)
        // 2) Stored attachment mime type
        // 3) Extension lookup
        // 4) PHP finfo/mime_content_type
        $mime = '';

        if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
            $checked = wp_check_filetype_and_ext( $filepath, $filename );
            if ( is_array( $checked ) && ! empty( $checked['type'] ) ) {
                $mime = (string) $checked['type'];
            }
        }

        if ( $mime === '' ) {
            $stored_mime = (string) get_post_mime_type( $attachment_id );
            if ( $stored_mime !== '' && strtolower( $stored_mime ) !== 'application/octet-stream' ) {
                $mime = $stored_mime;
            }
        }

        if ( $mime === '' ) {
            $type = wp_check_filetype( $filename );
            $mime = isset( $type['type'] ) && $type['type'] ? (string) $type['type'] : '';
        }

        if ( $mime === '' && function_exists( 'mime_content_type' ) ) {
            $detected = @mime_content_type( $filepath );
            if ( is_string( $detected ) && $detected !== '' ) {
                $mime = $detected;
            }
        }

        if ( $mime === '' ) {
            $mime = 'application/octet-stream';
        }

        // Only use inline disposition for types browsers can reasonably render.
        // Otherwise, even "inline" will feel like "download".
        $inline_allowed = false;
        $mime_lc = strtolower( $mime );
        if ( $mime_lc === 'application/pdf' ) {
            $inline_allowed = true;
        } elseif ( strpos( $mime_lc, 'image/' ) === 0 ) {
            $inline_allowed = true;
        } elseif ( strpos( $mime_lc, 'text/' ) === 0 ) {
            $inline_allowed = true;
        }

        $disposition = $requested_disposition;
        if ( $requested_disposition === 'inline' && ! $inline_allowed ) {
            $disposition = 'attachment';
        }

        $size = @filesize( $filepath );
        $size = ( $size !== false ) ? (int) $size : null;
        $mtime = @filemtime( $filepath );
        $mtime = ( $mtime !== false ) ? (int) $mtime : null;

        header( 'X-Content-Type-Options: nosniff' );

        // Cache behavior:
        // - Private docs: never store.
        // - Public docs: allow caching but require revalidation (stable URLs still update correctly).
        if ( $is_protected ) {
            if ( function_exists( 'nocache_headers' ) ) {
                nocache_headers();
            }
            header( 'Cache-Control: private, no-store, max-age=0' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
            header( 'X-Robots-Tag: noindex, nofollow', true );
        } else {
            header( 'Cache-Control: public, max-age=0, must-revalidate' );

            if ( $mtime !== null ) {
                $last_modified = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';
                header( 'Last-Modified: ' . $last_modified );
            }

            if ( $mtime !== null ) {
                $etag = '"' . md5( (string) $attachment_id . '|' . (string) $mtime . '|' . (string) ( $size ?? '' ) ) . '"';
                header( 'ETag: ' . $etag );

                // Conditional request handling.
                $if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
                $if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '';

                $client_etag = trim( $if_none_match );
                $client_time = $if_modified_since ? strtotime( $if_modified_since ) : false;

                if ( $client_etag !== '' && $client_etag === $etag ) {
                    status_header( 304 );
                    exit;
                }
                if ( $client_time !== false && $client_time >= $mtime ) {
                    status_header( 304 );
                    exit;
                }
            }
        }

        header( 'Content-Type: ' . $mime );

        // Content-Disposition filename handling:
        // - Provide a safe ASCII-ish fallback filename
        // - Provide RFC 5987 filename* for UTF-8 names
        $fallback_name = sanitize_file_name( $filename );
        if ( $fallback_name === '' ) {
            $fallback_name = 'document';
        }
        $encoded_name = rawurlencode( $filename );
        header( 'Content-Disposition: ' . $disposition . '; filename="' . $fallback_name . '"; filename*=UTF-8\'\'' . $encoded_name );

        if ( $size !== null ) {
            header( 'Content-Length: ' . (string) $size );
        }

        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
        if ( $method === 'HEAD' ) {
            exit;
        }

        $event_type = ( $disposition === 'inline' ) ? 'view' : 'download';

        // Clean output buffers before streaming.
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- validated attachment path is streamed directly to the response.
        $bytes_sent = @readfile( $filepath );
        if ( false === $bytes_sent ) {
            exit;
        }

        kitgenix_document_manager_record_document_analytics_event(
            $document_id,
            $event_type,
            [
                'signed' => ! empty( $access['is_signed_request'] ),
            ]
        );

        exit;
    }

    private static function send_404(): void {
        status_header( 404 );
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        exit;
    }

    private static function send_403(): void {
        status_header( 403 );
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        exit;
    }

    private static function send_gone(): void {
        status_header( 410 );
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        exit;
    }
}
