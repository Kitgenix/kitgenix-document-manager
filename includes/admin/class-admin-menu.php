<?php
namespace KitgenixDocumentManager\Admin;

defined( 'ABSPATH' ) || exit;

use KitgenixDocumentManager\Core\Capabilities;

final class Admin_Menu {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 60 );
    }

    public static function register_menu(): void {
        if ( function_exists( '\\kitgenix_ensure_admin_menu' ) ) {
            \kitgenix_ensure_admin_menu();
        }
    }
}
