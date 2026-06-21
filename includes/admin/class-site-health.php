<?php
namespace KitgenixDocumentManager\Admin;

defined( 'ABSPATH' ) || exit;

use function KitgenixDocumentManager\Core\kitgenix_document_manager_upload_dir;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_ensure_upload_dir;

final class Site_Health {

    public static function init(): void {
        add_filter( 'site_status_tests', [ __CLASS__, 'register_tests' ] );
    }

    public static function register_tests( array $tests ): array {
        $tests['direct']['kitgenix_document_manager_uploads'] = [
            'label' => __( 'Kitgenix Document Manager uploads directory', 'kitgenix-document-manager' ),
            'test'  => [ __CLASS__, 'test_uploads_dir' ],
        ];

        return $tests;
    }

    public static function test_uploads_dir(): array {
        $info = kitgenix_document_manager_upload_dir();
        $dir = (string) ( $info['dir'] ?? '' );

        // Use WP helper when available; if not, attempt to use WP_Filesystem
        // before falling back to is_writable(). We suppress the phpcs warning
        // for the fallback as it is guarded.
        $ok_writable = false;
        if ( function_exists( 'wp_is_writable' ) ) {
            $ok_writable = wp_is_writable( $dir );
        } else {
            if ( ! function_exists( 'WP_Filesystem' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            global $wp_filesystem;
            if ( empty( $wp_filesystem ) ) {
                WP_Filesystem();
            }
            if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'is_writable' ) ) {
                $ok_writable = (bool) $wp_filesystem->is_writable( $dir );
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- guarded fallback.
                $ok_writable = is_writable( $dir );
            }
        }

        $ok = kitgenix_document_manager_ensure_upload_dir() && $dir && is_dir( $dir ) && $ok_writable;

        return [
            'label'       => $ok ? __( 'Uploads directory is writable', 'kitgenix-document-manager' ) : __( 'Uploads directory is not writable', 'kitgenix-document-manager' ),
            'status'      => $ok ? 'good' : 'critical',
            'badge'       => [
                'label' => __( 'Kitgenix Document Manager', 'kitgenix-document-manager' ),
                'color' => $ok ? 'green' : 'red',
            ],
            'description' => $dir
                ? sprintf( '<p>%s</p>', esc_html( $dir ) )
                : sprintf( '<p>%s</p>', esc_html__( 'Could not resolve uploads directory.', 'kitgenix-document-manager' ) ),
            'actions'     => '',
            'test'        => 'kitgenix_document_manager_uploads',
        ];
    }
}
