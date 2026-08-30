<?php
namespace KitgenixDocumentManager\Core;

defined( 'ABSPATH' ) || exit;

use KitgenixDocumentManager\Admin\Settings_UI;

final class Script_Handler {

    public static function init(): void {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_public' ] );
    }

    public static function enqueue_admin( string $hook_suffix ): void {
        if ( ! is_admin() ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        $is_settings = Settings_UI::is_settings_screen( $hook_suffix );
        $is_document_editor = (
            $screen &&
            isset( $screen->post_type, $screen->base ) &&
            $screen->post_type === CPT::POST_TYPE &&
            in_array( (string) $screen->base, [ 'post', 'post-new' ], true )
        );

        if ( ! $is_settings && ! $is_document_editor ) {
            return;
        }

        if ( ! Capabilities::user_can_manage() ) {
            return;
        }

        // Needed for selecting files from the Media Library (wp.media).
        if ( $is_settings && function_exists( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }

        $ver = defined( 'KITGENIX_DOCUMENT_MANAGER_VERSION' ) ? (string) KITGENIX_DOCUMENT_MANAGER_VERSION : '1.1.1';

        $css_file = defined( 'KITGENIX_DOCUMENT_MANAGER_DIR' ) ? (string) KITGENIX_DOCUMENT_MANAGER_DIR . 'assets/css/admin.css' : '';
        $js_file  = defined( 'KITGENIX_DOCUMENT_MANAGER_DIR' ) ? (string) KITGENIX_DOCUMENT_MANAGER_DIR . 'assets/js/admin.js' : '';
        $css_ver  = ( $css_file && file_exists( $css_file ) ) ? (string) filemtime( $css_file ) : $ver;
        $js_ver   = ( $js_file && file_exists( $js_file ) ) ? (string) filemtime( $js_file ) : $ver;

        $ui_js_file  = defined( 'KITGENIX_DOCUMENT_MANAGER_DIR' ) ? (string) KITGENIX_DOCUMENT_MANAGER_DIR . 'assets/js/kitgenix-admin-tabs.js' : '';
        $ui_js_ver   = ( $ui_js_file && file_exists( $ui_js_file ) ) ? (string) filemtime( $ui_js_file ) : $ver;

        if ( $is_settings ) {
            wp_enqueue_style(
                'kitgenix-document-manager-admin',
                defined( 'KITGENIX_DOCUMENT_MANAGER_FILE' ) ? plugins_url( 'assets/css/admin.css', KITGENIX_DOCUMENT_MANAGER_FILE ) : '',
                [ 'kitgenix-document-manager-admin-ui' ],
                $css_ver
            );

            wp_enqueue_script(
                'kitgenix-admin-tabs',
                defined( 'KITGENIX_DOCUMENT_MANAGER_FILE' ) ? plugins_url( 'assets/js/kitgenix-admin-tabs.js', KITGENIX_DOCUMENT_MANAGER_FILE ) : '',
                [],
                $ui_js_ver,
                true
            );
        }

        wp_enqueue_style( 'kitgenix-document-manager-admin-ui' );

        // Shared admin JS provides copy-to-clipboard and modal wiring; safe on editor screens.
        wp_enqueue_script(
            'kitgenix-document-manager-admin',
            defined( 'KITGENIX_DOCUMENT_MANAGER_FILE' ) ? plugins_url( 'assets/js/admin.js', KITGENIX_DOCUMENT_MANAGER_FILE ) : '',
            [],
            $js_ver,
            true
        );

        wp_localize_script(
            'kitgenix-document-manager-admin',
            'kitgenixDMAdmin',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'kitgenix_dm_search_docs' ),
            ]
        );
    }

    public static function enqueue_public(): void {
        if ( is_admin() ) {
            return;
        }

        $ver = defined( 'KITGENIX_DOCUMENT_MANAGER_VERSION' ) ? (string) KITGENIX_DOCUMENT_MANAGER_VERSION : '1.1.1';

        $css_file = defined( 'KITGENIX_DOCUMENT_MANAGER_DIR' ) ? (string) KITGENIX_DOCUMENT_MANAGER_DIR . 'assets/css/public.css' : '';
        $css_ver  = ( $css_file && file_exists( $css_file ) ) ? (string) filemtime( $css_file ) : $ver;

        wp_register_style(
            'kitgenix-document-manager-public',
            defined( 'KITGENIX_DOCUMENT_MANAGER_FILE' ) ? plugins_url( 'assets/css/public.css', KITGENIX_DOCUMENT_MANAGER_FILE ) : '',
            [],
            $css_ver
        );

        // Load only when the shortcode is present in the main post content.
        if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'has_shortcode' ) ) {
            global $post;
            if ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'kitgenix_document_manager_document' ) ) {
                wp_enqueue_style( 'kitgenix-document-manager-public' );
            }
        }
    }
}
