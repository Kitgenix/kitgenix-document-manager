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
     * @return int|\WP_Error Attachment ID on success, WP_Error with a specific code on failure.
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers must verify nonces and capabilities before invoking this helper.
    public static function upload_and_attach( int $document_id, array $file ): int|\WP_Error {
        if ( ! Capabilities::user_can_manage() ) {
            Event_Log::record( 'document-upload', 'error', __( 'Upload blocked: the current user does not have permission to manage documents.', 'kitgenix-document-manager' ), 'no_capability' );
            return new \WP_Error( 'no_capability', __( 'You do not have permission to upload documents.', 'kitgenix-document-manager' ) );
        }

        if ( empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            Event_Log::record( 'document-upload', 'error', __( 'Upload failed: no temporary upload file was received from the server.', 'kitgenix-document-manager' ), 'no_tmp_file' );
            return new \WP_Error( 'no_tmp_file', __( 'No uploaded file was received. Please try again.', 'kitgenix-document-manager' ) );
        }

        if ( ! kitgenix_document_manager_ensure_upload_dir() ) {
            Event_Log::record( 'document-upload', 'error', __( 'Upload failed: the plugin uploads folder could not be created or is not writable.', 'kitgenix-document-manager' ), 'dir_not_writable' );
            return new \WP_Error( 'dir_not_writable', __( 'The uploads folder is not writable. Contact your host or administrator.', 'kitgenix-document-manager' ) );
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
            $handler_message = is_array( $upload ) ? sanitize_text_field( (string) ( $upload['error'] ?? '' ) ) : '';
            Event_Log::record(
                'document-upload',
                'error',
                $handler_message !== ''
                    ? sprintf(
                        /* translators: %s: error message returned by wp_handle_upload() */
                        __( 'Upload failed: %s', 'kitgenix-document-manager' ),
                        $handler_message
                    )
                    : __( 'Upload failed: WordPress could not process the uploaded file.', 'kitgenix-document-manager' ),
                'upload_handler_error'
            );
            return new \WP_Error(
                'upload_handler_error',
                $handler_message !== '' ? $handler_message : __( 'Upload failed. Please check the file and try again.', 'kitgenix-document-manager' )
            );
        }

        $filepath = (string) ( $upload['file'] ?? '' );
        $fileurl  = (string) ( $upload['url'] ?? '' );
        $filetype = (string) ( $upload['type'] ?? '' );

        if ( $filepath === '' || ! file_exists( $filepath ) ) {
            Event_Log::record( 'document-upload', 'error', __( 'Upload failed: the uploaded file could not be located on disk after processing.', 'kitgenix-document-manager' ), 'missing_filepath' );
            return new \WP_Error( 'missing_filepath', __( 'The uploaded file could not be found after processing. Please try again.', 'kitgenix-document-manager' ) );
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
            Event_Log::record( 'document-upload', 'error', __( 'Upload rejected: the file extension is not on the allowed list.', 'kitgenix-document-manager' ), 'disallowed_extension' );
            return new \WP_Error( 'disallowed_extension', __( 'This file type is not allowed. Check Settings → Allowed file types.', 'kitgenix-document-manager' ) );
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
            Event_Log::record( 'document-upload', 'error', __( 'Upload failed: WordPress could not create the attachment record for the uploaded file.', 'kitgenix-document-manager' ), 'db_insert_failed' );
            return new \WP_Error( 'db_insert_failed', __( 'The file was uploaded but could not be saved to the media library. Please try again.', 'kitgenix-document-manager' ) );
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
     * @return int|\WP_Error New attachment ID on success, WP_Error on failure.
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers must verify nonces and capabilities before invoking this helper.
    public static function replace_file( int $document_id, array $file, bool $keep_history, bool $delete_old_when_not_kept, string $version_label = '', string $version_note = '' ): int|\WP_Error {
        $old_current = self::get_current_attachment_id( $document_id );
        $new_id = self::upload_and_attach( $document_id, $file );
        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        if ( $version_label !== '' || $version_note !== '' ) {
            kitgenix_document_manager_set_version_meta( $document_id, $new_id, $version_label, $version_note );
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
