<?php
namespace KitgenixDocumentManager\Core;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

    public const CAPABILITY = 'manage_kitgenix_document_manager_documents';

    /**
     * Document Manager admin access.
     *
     * Keep in sync with other Kitgenix plugins which generally use manage_options.
     */
    public static function user_can_manage(): bool {
        if ( function_exists( 'current_user_can' ) && current_user_can( self::CAPABILITY ) ) {
            return true;
        }

        // Allow standard WP admins.
        if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Allow WooCommerce managers if WooCommerce is active.
        if ( class_exists( 'WooCommerce' ) && function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ) ) {
            return true;
        }

        return false;
    }

    public static function init(): void {
        // Nothing required at runtime; capabilities are assigned on activation.
    }

    public static function add_caps(): void {
        if ( ! function_exists( 'get_role' ) ) {
            return;
        }

        $role = get_role( 'administrator' );
        if ( $role && method_exists( $role, 'add_cap' ) ) {
            $role->add_cap( self::CAPABILITY );
        }

        // If WooCommerce is installed, Shop Managers commonly need access too.
        $shop_manager = get_role( 'shop_manager' );
        if ( $shop_manager && method_exists( $shop_manager, 'add_cap' ) ) {
            $shop_manager->add_cap( self::CAPABILITY );
        }
    }
}
