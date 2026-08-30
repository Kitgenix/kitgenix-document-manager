<?php
namespace KitgenixDocumentManager\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Simple per-plugin activity log for Kitgenix Document Manager.
 *
 * Records admin actions (settings saves, document CRUD, version operations,
 * category changes) to a capped WP option visible in the admin Log tab.
 *
 * Storage format (per entry):
 *   [ 'time' => int, 'context' => string, 'outcome' => string, 'note' => string, 'code' => string ]
 */
final class Event_Log {

    private const OPTION_KEY  = 'kitgenix_document_manager_event_log';
    private const MAX_ENTRIES = 100;

    /**
     * Record an event to the log.
     *
     * @param string $context  Short slug (e.g. 'settings-saved', 'document-uploaded').
     * @param string $outcome  'success', 'error', or any short outcome label.
     * @param string $note     Optional plain-English detail.
     * @param string $code     Optional machine-readable failure/event code (see get_category_and_note()).
     */
    public static function record( string $context, string $outcome, string $note = '', string $code = '' ): void {
        $log   = self::get_raw_log();
        $log[] = [
            'time'    => time(),
            'context' => sanitize_text_field( $context ),
            'outcome' => sanitize_text_field( $outcome ),
            'note'    => sanitize_text_field( $note ),
            'code'    => sanitize_key( $code ),
        ];

        if ( count( $log ) > self::MAX_ENTRIES ) {
            $log = array_slice( $log, -self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $log, false );
    }

    /**
     * Return all stored entries (oldest first).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_raw_log(): array {
        $log = get_option( self::OPTION_KEY, [] );
        return is_array( $log ) ? $log : [];
    }

    /** Delete all stored entries. */
    public static function clear(): void {
        delete_option( self::OPTION_KEY );
    }

    /**
     * Map an event code to a short category slug and a plain-English note.
     *
     * Distinguishes genuine problems (need admin action) from likely false
     * positives (expected behavior, e.g. a share link that simply aged out)
     * so a non-technical site owner can tell them apart in the Log tab.
     *
     * @return array{category: string, note: string}
     */
    public static function get_category_and_note( string $code ): array {
        $map = [
            'settings-saved' => [
                'category' => 'settings',
                'note'     => __( 'An administrator saved changes on the plugin settings page. Routine – no action needed.', 'kitgenix-document-manager' ),
            ],

            // Upload failures (Uploader::upload_and_attach) – genuine, review if they recur.
            'no_capability' => [
                'category' => 'permission-denied',
                'note'     => __( 'The user attempting the upload did not have permission to manage documents. Expected if a non-admin tried to upload directly; check role/capability settings if this was unexpected.', 'kitgenix-document-manager' ),
            ],
            'no_tmp_file' => [
                'category' => 'upload-error',
                'note'     => __( 'No temporary file was received from the browser. Usually caused by a large file, a slow connection, or a server upload limit. Check your server\'s upload_max_filesize and post_max_size.', 'kitgenix-document-manager' ),
            ],
            'dir_not_writable' => [
                'category' => 'server-config',
                'note'     => __( 'The plugin\'s uploads folder could not be created or is not writable. Check file permissions on wp-content/uploads.', 'kitgenix-document-manager' ),
            ],
            'upload_handler_error' => [
                'category' => 'upload-error',
                'note'     => __( 'WordPress rejected the uploaded file (invalid type, oversized, or a partial upload). Check the file and your server\'s upload limits.', 'kitgenix-document-manager' ),
            ],
            'missing_filepath' => [
                'category' => 'upload-error',
                'note'     => __( 'The upload was reported as successful but the file could not be found on disk afterwards. Check available disk space and file permissions.', 'kitgenix-document-manager' ),
            ],
            'disallowed_extension' => [
                'category' => 'blocked-file-type',
                'note'     => __( 'The uploaded file\'s extension is not on the allowed list. If this was a legitimate file, add its extension in Settings → Allowed file types.', 'kitgenix-document-manager' ),
            ],
            'db_insert_failed' => [
                'category' => 'database-error',
                'note'     => __( 'The file was saved to disk but WordPress could not create the attachment record. Check database health and available storage.', 'kitgenix-document-manager' ),
            ],

            // Share-link rejections (kitgenix_document_manager_validate_share_link_request).
            'link_missing_flag' => [
                'category' => 'no-share-link',
                'note'     => __( 'The request did not include any share-link parameters. Normal for ordinary visits to a document page – usually not a problem.', 'kitgenix-document-manager' ),
            ],
            'link_expired' => [
                'category' => 'link-expired',
                'note'     => __( 'The share link\'s expiry time has passed. This is expected once a shared link reaches the end of its life – usually not a problem. Ask the user to request a new link.', 'kitgenix-document-manager' ),
            ],
            'secret_rotated' => [
                'category' => 'link-outdated',
                'note'     => __( 'The link was generated before the document\'s share settings were last changed (a new link was issued since), so its signature no longer matches. Usually not a problem – ask the user to request a new link.', 'kitgenix-document-manager' ),
            ],
            'signature_mismatch' => [
                'category' => 'signature-invalid',
                'note'     => __( 'The link\'s signature does not match its parameters – the URL was altered, corrupted, or fabricated. Genuine issue: monitor for repeated attempts from the same source.', 'kitgenix-document-manager' ),
            ],
            'link_use_limit_reached' => [
                'category' => 'link-use-limit',
                'note'     => __( 'The share link has reached its maximum number of uses. Regenerate or issue a new link if further access is needed.', 'kitgenix-document-manager' ),
            ],
        ];

        if ( isset( $map[ $code ] ) ) {
            return $map[ $code ];
        }

        return [
            'category' => 'unknown',
            'note'     => __( 'No details available for this event.', 'kitgenix-document-manager' ),
        ];
    }

    /**
     * Return the log as a formatted multi-line string for the admin textarea.
     */
    public static function get_log_text(): string {
        $entries = self::get_raw_log();
        if ( empty( $entries ) ) {
            return __( 'No recent events recorded yet.', 'kitgenix-document-manager' );
        }

        $format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
        $lines  = [ '# Columns: timestamp | context | outcome | note' ];

        foreach ( array_reverse( $entries ) as $entry ) {
            $time = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
            if ( function_exists( 'wp_date' ) ) {
                $when = $time ? (string) wp_date( $format, $time ) : '';
            } else {
                $when = $time ? (string) date_i18n( $format, $time ) : '';
            }

            $lines[] = sprintf(
                '%1$s | %2$s | %3$s | %4$s',
                $when ?: __( 'Unknown time', 'kitgenix-document-manager' ),
                (string) ( $entry['context'] ?? '' ),
                (string) ( $entry['outcome'] ?? '' ),
                (string) ( $entry['note']    ?? '' )
            );
        }

        return implode( "\n", $lines );
    }
}
