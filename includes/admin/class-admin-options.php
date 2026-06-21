<?php
namespace KitgenixDocumentManager\Admin;

defined( 'ABSPATH' ) || exit;

use KitgenixDocumentManager\Core\Capabilities;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_default_settings;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_normalize_visibility;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_normalize_extensions;

final class Admin_Options {

    public static function init(): void {
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    public static function register_settings(): void {
        $opt_name = defined( 'KITGENIX_DOCUMENT_MANAGER_OPTION_NAME' ) ? (string) KITGENIX_DOCUMENT_MANAGER_OPTION_NAME : 'kitgenix_document_manager_settings';

        register_setting(
            'kitgenix_document_manager_settings_group',
            $opt_name,
            [
                'type'              => 'array',
                'description'       => __( 'Kitgenix Document Manager Settings', 'kitgenix-document-manager' ),
                'sanitize_callback' => [ __CLASS__, 'sanitize' ],
                'show_in_rest'      => false,
                'default'           => kitgenix_document_manager_default_settings(),
            ]
        );
    }

    public static function sanitize( $settings ): array {
        if ( ! Capabilities::user_can_manage() ) {
            $opt_name = defined( 'KITGENIX_DOCUMENT_MANAGER_OPTION_NAME' ) ? (string) KITGENIX_DOCUMENT_MANAGER_OPTION_NAME : 'kitgenix_document_manager_settings';
            return (array) get_option( $opt_name, kitgenix_document_manager_default_settings() );
        }

        // WordPress core already validates the Settings API nonce for this option group
        // (via settings_fields + options.php). Avoid requiring an extra custom nonce,
        // as it can cause saves to appear to "revert" on some setups.

        $settings = is_array( $settings ) ? $settings : [];

        $opt_name = defined( 'KITGENIX_DOCUMENT_MANAGER_OPTION_NAME' ) ? (string) KITGENIX_DOCUMENT_MANAGER_OPTION_NAME : 'kitgenix_document_manager_settings';
        $defaults = kitgenix_document_manager_default_settings();
        $existing = (array) get_option( $opt_name, $defaults );
        $existing = is_array( $existing ) ? $existing : [];

        // Start from existing values (merged with defaults) so saving a subset of fields
        // from one UI tab doesn't unintentionally reset other settings.
        $clean = array_merge( $defaults, $existing );

        if ( array_key_exists( 'allowed_extensions', $settings ) ) {
            $clean['allowed_extensions'] = kitgenix_document_manager_normalize_extensions( $settings['allowed_extensions'] ?? [] );
            if ( empty( $clean['allowed_extensions'] ) ) {
                $clean['allowed_extensions'] = $defaults['allowed_extensions'];
            }
        }

        if ( array_key_exists( 'default_visibility', $settings ) ) {
            $clean['default_visibility'] = kitgenix_document_manager_normalize_visibility( (string) ( $settings['default_visibility'] ?? 'public' ) );
        }

        if ( array_key_exists( 'serving_mode', $settings ) ) {
            $mode = (string) ( $settings['serving_mode'] ?? 'attachment' );
            $clean['serving_mode'] = ( $mode === 'inline' ) ? 'inline' : 'attachment';
        }

        if ( array_key_exists( 'keep_version_history', $settings ) ) {
            $clean['keep_version_history'] = ! empty( $settings['keep_version_history'] );
        }
        if ( array_key_exists( 'delete_old_versions', $settings ) ) {
            $clean['delete_old_versions'] = ! empty( $settings['delete_old_versions'] );
        }

        if ( array_key_exists( 'private_behavior', $settings ) ) {
            $pb = (string) ( $settings['private_behavior'] ?? 'login' );
            $clean['private_behavior'] = ( $pb === '403' ) ? '403' : 'login';
        }

        if ( array_key_exists( 'delete_data_on_uninstall', $settings ) ) {
            $clean['delete_data_on_uninstall'] = ! empty( $settings['delete_data_on_uninstall'] );
        }

        if ( array_key_exists( 'card_button_label', $settings ) ) {
            $clean['card_button_label'] = sanitize_text_field( (string) ( $settings['card_button_label'] ?? $clean['card_button_label'] ?? 'View' ) );
            if ( $clean['card_button_label'] === '' ) {
                $clean['card_button_label'] = 'View';
            }
        }

        if ( array_key_exists( 'card_button_style_source', $settings ) ) {
            $source = (string) ( $settings['card_button_style_source'] ?? 'custom' );
            $clean['card_button_style_source'] = ( $source === 'theme' ) ? 'theme' : 'custom';
        }

        if ( array_key_exists( 'card_button_style', $settings ) ) {
            $style = (string) ( $settings['card_button_style'] ?? 'outline' );
            $clean['card_button_style'] = ( $style === 'solid' ) ? 'solid' : 'outline';
        }

        if ( array_key_exists( 'card_open_new_tab', $settings ) ) {
            $clean['card_open_new_tab'] = ! empty( $settings['card_open_new_tab'] );
        }
        if ( array_key_exists( 'card_show_file_type', $settings ) ) {
            $clean['card_show_file_type'] = ! empty( $settings['card_show_file_type'] );
        }
        if ( array_key_exists( 'card_show_file_size', $settings ) ) {
            $clean['card_show_file_size'] = ! empty( $settings['card_show_file_size'] );
        }

        if ( array_key_exists( 'card_show_description', $settings ) ) {
            $clean['card_show_description'] = ! empty( $settings['card_show_description'] );
        }

        if ( array_key_exists( 'card_image_size', $settings ) ) {
            $size = absint( $settings['card_image_size'] ?? 96 );
            if ( $size < 32 || $size > 256 ) {
                $size = 96;
            }
            $clean['card_image_size'] = $size;
        }

        if ( array_key_exists( 'card_meta_alignment', $settings ) ) {
            $align = sanitize_key( (string) ( $settings['card_meta_alignment'] ?? 'justify' ) );
            $clean['card_meta_alignment'] = in_array( $align, [ 'justify', 'left', 'center', 'right' ], true ) ? $align : 'justify';
        }

        if ( array_key_exists( 'card_button_alignment', $settings ) ) {
            $align = sanitize_key( (string) ( $settings['card_button_alignment'] ?? 'center' ) );
            $clean['card_button_alignment'] = in_array( $align, [ 'justify', 'left', 'center', 'right' ], true ) ? $align : 'center';
        }

        if ( array_key_exists( 'card_button_full_width', $settings ) ) {
            $clean['card_button_full_width'] = ! empty( $settings['card_button_full_width'] );
        }

        $hex_keys = [
            'card_button_text_color',
            'card_button_bg_color',
            'card_button_border_color',
            'card_button_hover_text_color',
            'card_button_hover_bg_color',
            'card_button_hover_border_color',
        ];
        foreach ( $hex_keys as $key ) {
            if ( ! array_key_exists( $key, $settings ) ) {
                continue;
            }
            $raw = trim( (string) ( $settings[ $key ] ?? '' ) );
            if ( $raw === '' ) {
                $clean[ $key ] = '';
                continue;
            }
            if ( function_exists( 'sanitize_hex_color' ) ) {
                $val = sanitize_hex_color( $raw );
                $clean[ $key ] = $val ? (string) $val : '';
            } else {
                $clean[ $key ] = '';
            }
        }

        return $clean;
    }
}
