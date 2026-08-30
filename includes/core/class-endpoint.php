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
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check mirroring kitgenix_document_manager_evaluate_document_access()'s own share-link inspection.
            $share_attempted = isset( $_GET['kitgenix_dm_share'] ) && '1' === sanitize_key( wp_unslash( (string) $_GET['kitgenix_dm_share'] ) );
            if ( $share_attempted ) {
                $link_check = kitgenix_document_manager_validate_share_link_request( $document_id );
                if ( ! $link_check['valid'] && $link_check['code'] !== '' ) {
                    $context = Event_Log::get_category_and_note( $link_check['code'] );
                    Event_Log::record( 'share-link-denied', 'error', $context['note'], $link_check['code'] );
                }
            }

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
        header( 'Accept-Ranges: bytes' );

        // Content-Disposition filename handling:
        // - Provide a safe ASCII-ish fallback filename
        // - Provide RFC 5987 filename* for UTF-8 names
        // Both values are stripped of quote/CR/LF characters defensively – filenames
        // originate from sanitize_file_name()'d uploads, but this keeps header
        // construction safe even against unexpected attachment metadata.
        $fallback_name = sanitize_file_name( $filename );
        if ( $fallback_name === '' ) {
            $fallback_name = 'document';
        }
        $fallback_name = str_replace( [ '"', "\r", "\n" ], '', $fallback_name );
        $encoded_name  = rawurlencode( str_replace( [ "\r", "\n" ], '', $filename ) );
        header( 'Content-Disposition: ' . $disposition . '; filename="' . $fallback_name . '"; filename*=UTF-8\'\'' . $encoded_name );

        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

        $range = self::resolve_range_request( $size, $etag ?? '', $mtime );

        if ( is_array( $range ) && ! empty( $range['unsatisfiable'] ) ) {
            status_header( 416 );
            header( 'Content-Range: bytes */' . (string) ( $size ?? 0 ) );
            exit;
        }

        $start  = 0;
        $length = $size;

        if ( is_array( $range ) && ! empty( $range['satisfiable'] ) && $size !== null ) {
            $start  = (int) $range['start'];
            $end    = (int) $range['end'];
            $length = $end - $start + 1;

            status_header( 206 );
            header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . (string) $size );
        }

        if ( $length !== null ) {
            header( 'Content-Length: ' . (string) $length );
        }

        if ( $method === 'HEAD' ) {
            exit;
        }

        $event_type = ( $disposition === 'inline' ) ? 'view' : 'download';

        // Clean output buffers before streaming.
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        if ( is_array( $range ) && ! empty( $range['satisfiable'] ) ) {
            self::stream_range( $filepath, $start, (int) $length );
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- validated attachment path is streamed directly to the response.
            $bytes_sent = @readfile( $filepath );
            if ( false === $bytes_sent ) {
                exit;
            }
        }

        if ( ! empty( $access['is_signed_request'] ) ) {
            kitgenix_document_manager_increment_document_share_use_count( $document_id );
        }

        kitgenix_document_manager_record_document_analytics_event(
            $document_id,
            $event_type,
            [
                'signed' => ! empty( $access['is_signed_request'] ),
            ]
        );

        Lifecycle::maybe_record_transition( $document_id );

        /**
         * Fires immediately after a document's file has been streamed to the
         * requester (or its metadata prepared for a HEAD request).
         *
         * @param int    $document_id   Document post ID.
         * @param int    $attachment_id Attachment ID that was served.
         * @param string $event_type    'view' (inline) or 'download' (attachment).
         * @param array  $access        The access-decision array from
         *                              kitgenix_document_manager_evaluate_document_access().
         */
        do_action( 'kitgenix_document_manager_document_served', $document_id, $attachment_id, $event_type, $access );

        exit;
    }

    /**
     * Parse and validate an incoming Range request against the resource's
     * known size/validators. Only a single byte-range is supported; a
     * multi-range request, a malformed header, or a request without a
     * strong validator to check `If-Range` against falls back to a full
     * (200) response, per RFC 7233 §3.1 allowances.
     *
     * @return array{satisfiable?: bool, unsatisfiable?: bool, start?: int, end?: int}|null
     */
    private static function resolve_range_request( ?int $size, string $etag, ?int $mtime ): ?array {
        if ( $size === null || $size <= 0 ) {
            return null;
        }

        $raw_range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_RANGE'] ) ) : '';
        if ( $raw_range === '' ) {
            return null;
        }

        // If-Range: only honor the Range request if the validator still matches
        // (otherwise the underlying file changed – serve the full, current file).
        $if_range = isset( $_SERVER['HTTP_IF_RANGE'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_IF_RANGE'] ) ) ) : '';
        if ( $if_range !== '' ) {
            $matches_etag = ( $etag !== '' && $if_range === $etag );
            $if_range_time = strtotime( $if_range );
            $matches_time  = ( $if_range_time !== false && $mtime !== null && $if_range_time >= $mtime );
            if ( ! $matches_etag && ! $matches_time ) {
                return null;
            }
        }

        // Reject multi-range requests (fall back to a full response) rather
        // than attempt (and mis-format) a multipart/byteranges body.
        if ( strpos( $raw_range, ',' ) !== false ) {
            return null;
        }

        if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $raw_range ), $matches ) ) {
            return null;
        }

        $start_raw = $matches[1];
        $end_raw   = $matches[2];

        if ( $start_raw === '' && $end_raw === '' ) {
            return null;
        }

        if ( $start_raw === '' ) {
            // Suffix range: last N bytes.
            $suffix_length = (int) $end_raw;
            if ( $suffix_length <= 0 ) {
                return [ 'unsatisfiable' => true ];
            }
            $start = max( 0, $size - $suffix_length );
            $end   = $size - 1;
        } else {
            $start = (int) $start_raw;
            $end   = ( $end_raw === '' ) ? $size - 1 : (int) $end_raw;
        }

        if ( $start < 0 || $start >= $size || $end < $start ) {
            return [ 'unsatisfiable' => true ];
        }

        if ( $end > $size - 1 ) {
            $end = $size - 1;
        }

        return [ 'satisfiable' => true, 'start' => $start, 'end' => $end ];
    }

    /**
     * Stream a byte range from disk in fixed-size chunks so large files
     * never need to be held fully in PHP memory.
     */
    private static function stream_range( string $filepath, int $start, int $length ): void {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no chunked/seekable read API suitable for byte-range streaming; validated local path only.
        $handle = fopen( $filepath, 'rb' );
        if ( $handle === false ) {
            return;
        }

        $chunk_size  = 1048576; // 1MB
        $remaining   = $length;

        if ( fseek( $handle, $start ) !== 0 ) {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen() above.
            return;
        }

        while ( $remaining > 0 && ! feof( $handle ) ) {
            $read_size = (int) min( $chunk_size, $remaining );
            $data      = fread( $handle, $read_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- chunked streaming read from a validated local path.
            if ( false === $data || '' === $data ) {
                break;
            }

            echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary file content streamed to the response.
            flush();

            $remaining -= strlen( $data );
        }

        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen() above.
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
