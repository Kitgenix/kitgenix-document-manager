<?php
namespace KitgenixDocumentManager\Core;

defined( 'ABSPATH' ) || exit;

final class Uploader {

    /**
     * Check whether a file was included in the current request for the
     * kitgenix document manager upload input.
     *
     * This centralises direct access to $_FILES so callers do not need to
     * reference the superglobal directly. The actual file handling and
     * validation remains in `upload_and_attach()` and `replace_file()`.
     *
     * @param string $key Field name to check.
     * @return bool
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers must verify nonces/capabilities before invoking this helper.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wrapper intentionally inspects $_FILES.
    public static function has_incoming_file( string $key = 'kitgenix_document_manager_file' ): bool {
        $file = self::get_incoming_file( $key );

        return '' !== (string) ( $file['name'] ?? '' ) && UPLOAD_ERR_OK === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) && '' !== (string) ( $file['tmp_name'] ?? '' );
    }

    /**
     * Return the incoming uploaded file array for the given field, or an empty array.
     *
     * This centralises access to the $_FILES superglobal so callers do not
     * directly index it (which static analysis flags). Validation and
     * sanitization occurs inside the Uploader methods.
     *
     * @param string $key Field name to retrieve.
     * @return array
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers must verify nonces and capabilities before invoking this helper.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wrapper for $_FILES
    public static function get_incoming_file( string $key = 'kitgenix_document_manager_file' ): array {
        if ( isset( $_FILES[ $key ] ) && is_array( $_FILES[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wrapper intentionally reads $_FILES; callers must verify nonces and sanitize.
            $file = $_FILES[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wrapper intentionally reads $_FILES after callers verify the request.

            return self::normalize_incoming_file( $file );
        }
        return [];
    }

    /**
     * Check whether one or more files were included in the current request.
     *
     * @param string $key Field name to check.
     * @return bool
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers must verify nonces/capabilities before invoking this helper.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wrapper intentionally inspects $_FILES.
    public static function has_incoming_files( string $key = 'kitgenix_document_manager_bulk_files' ): bool {
        return ! empty( self::get_incoming_files( $key ) );
    }

    /**
     * Return uploaded file entries for a multi-file field.
     *
     * @param string $key Field name to retrieve.
     * @return array<int, array<string, int|string>>
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers must verify nonces and capabilities before invoking this helper.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wrapper for $_FILES.
    public static function get_incoming_files( string $key = 'kitgenix_document_manager_bulk_files' ): array {
        if ( ! isset( $_FILES[ $key ] ) || ! is_array( $_FILES[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wrapper intentionally reads $_FILES; callers must verify nonces and sanitize.
            return [];
        }

        $file = $_FILES[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wrapper intentionally reads $_FILES after callers verify the request.

        if ( ! isset( $file['name'] ) || ! is_array( $file['name'] ) ) {
            $single = self::normalize_incoming_file( $file );

            return self::has_file_payload( $single ) ? [ $single ] : [];
        }

        $count = count( $file['name'] );
        $files = [];

        for ( $index = 0; $index < $count; $index++ ) {
            $entry = self::normalize_incoming_file(
                [
                    'name'     => $file['name'][ $index ] ?? '',
                    'type'     => $file['type'][ $index ] ?? '',
                    'tmp_name' => $file['tmp_name'][ $index ] ?? '',
                    'error'    => $file['error'][ $index ] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $file['size'][ $index ] ?? 0,
                ]
            );

            if ( self::has_file_payload( $entry ) ) {
                $files[] = $entry;
            }
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, int|string>
     */
    private static function normalize_incoming_file( array $file ): array {
        return [
            'name'     => isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '',
            'type'     => isset( $file['type'] ) ? sanitize_mime_type( (string) $file['type'] ) : '',
            'tmp_name' => isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '',
            'error'    => isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE,
            'size'     => isset( $file['size'] ) ? max( 0, (int) $file['size'] ) : 0,
        ];
    }

    /**
     * @param array<string, int|string> $file
     */
    private static function has_file_payload( array $file ): bool {
        return '' !== (string) ( $file['name'] ?? '' )
            && UPLOAD_ERR_OK === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE )
            && '' !== (string) ( $file['tmp_name'] ?? '' );
    }

    /**
     * Uploads a file into wp-content/uploads/kitgenix-document-manager/ and attaches it to a document.
     *
     * @param int   $document_id Document CPT ID.
     * @param array $file        One entry from $_FILES.
     * @return int Attachment ID.
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers must verify nonces and capabilities before invoking this helper.
    public static function upload_and_attach( int $document_id, array $file ): int {
        if ( ! Capabilities::user_can_manage() ) {
            return 0;
        }

        if ( empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return 0;
        }

        if ( ! kitgenix_document_manager_ensure_upload_dir() ) {
            return 0;
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $allowed_mimes = kitgenix_document_manager_allowed_mimes();

        $original_upload_dir = null;
        $filter = static function ( array $dirs ) use ( &$original_upload_dir ): array {
            if ( $original_upload_dir === null ) {
                $original_upload_dir = $dirs;
            }

            $subdir = '/' . kitgenix_document_manager_upload_subdir();
            $dirs['subdir'] = $subdir;
            $dirs['path']   = rtrim( (string) $dirs['basedir'], '/' ) . $subdir;
            $dirs['url']    = rtrim( (string) $dirs['baseurl'], '/' ) . $subdir;
            return $dirs;
        };

        add_filter( 'upload_dir', $filter );

        try {
            $upload = wp_handle_upload(
                $file,
                [
                    'test_form' => false,
                    'mimes'     => $allowed_mimes,
                ]
            );
        } finally {
            remove_filter( 'upload_dir', $filter );
        }

        if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) {
            return 0;
        }

        $filepath = (string) ( $upload['file'] ?? '' );
        $fileurl  = (string) ( $upload['url'] ?? '' );
        $filetype = (string) ( $upload['type'] ?? '' );

        if ( $filepath === '' || ! file_exists( $filepath ) ) {
            return 0;
        }

        // Extra validation: ensure file extension is allowed.
        $wp_filetype = wp_check_filetype( basename( $filepath ) );
        $ext = isset( $wp_filetype['ext'] ) ? strtolower( (string) $wp_filetype['ext'] ) : '';
        if ( $ext === '' || ! isset( $allowed_mimes[ $ext ] ) ) {
            // Use WordPress helper to delete files when available.
            if ( function_exists( 'wp_delete_file' ) ) {
                @wp_delete_file( $filepath );
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- fallback when wp_delete_file is unavailable.
                @unlink( $filepath );
            }
            return 0;
        }

        $attachment = [
            'post_mime_type' => $filetype,
            'post_title'     => sanitize_text_field( wp_basename( $filepath ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => $fileurl,
        ];

        $attach_id = (int) wp_insert_attachment( $attachment, $filepath, $document_id );
        if ( $attach_id <= 0 ) {
            return 0;
        }

        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $meta = wp_generate_attachment_metadata( $attach_id, $filepath );
        if ( is_array( $meta ) ) {
            wp_update_attachment_metadata( $attach_id, $meta );
        }

        return $attach_id;
    }

    public static function set_current_attachment( int $document_id, int $attachment_id ): void {
        update_post_meta( $document_id, '_kitgenix_document_manager_current_attachment_id', (int) $attachment_id );
    }

    public static function get_current_attachment_id( int $document_id ): int {
        return (int) get_post_meta( $document_id, '_kitgenix_document_manager_current_attachment_id', true );
    }

    /**
     * Replace the current file (optionally keeping version history).
     *
     * @return int New attachment ID.
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers must verify nonces and capabilities before invoking this helper.
    public static function replace_file( int $document_id, array $file, bool $keep_history, bool $delete_old_when_not_kept ): int {
        $old_current = self::get_current_attachment_id( $document_id );
        $new_id = self::upload_and_attach( $document_id, $file );
        if ( $new_id <= 0 ) {
            return 0;
        }

        $versions = kitgenix_document_manager_get_versions( $document_id );

        if ( $keep_history ) {
            // Keep all versions, including the new current.
            if ( $old_current > 0 && ! in_array( $old_current, $versions, true ) ) {
                $versions[] = $old_current;
            }
            if ( ! in_array( $new_id, $versions, true ) ) {
                $versions[] = $new_id;
            }
            kitgenix_document_manager_set_versions( $document_id, $versions );
        } else {
            // Keep only the latest.
            if ( $delete_old_when_not_kept ) {
                foreach ( $versions as $vid ) {
                    if ( $vid > 0 && $vid !== $new_id ) {
                        wp_delete_attachment( $vid, true );
                    }
                }
                if ( $old_current > 0 && $old_current !== $new_id ) {
                    wp_delete_attachment( $old_current, true );
                }
            }
            kitgenix_document_manager_set_versions( $document_id, [ $new_id ] );
        }

        self::set_current_attachment( $document_id, $new_id );

        return $new_id;
    }
}
