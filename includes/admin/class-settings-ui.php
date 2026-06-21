<?php
namespace KitgenixDocumentManager\Admin;

defined( 'ABSPATH' ) || exit;

use KitgenixDocumentManager\Core\Capabilities;
use KitgenixDocumentManager\Core\CPT;
use KitgenixDocumentManager\Core\Event_Log;
use KitgenixDocumentManager\Core\Uploader;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_settings;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_stable_url;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_allowed_roles;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_analytics;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_allowed_user_ids;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_required_capability;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_schedule;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_schedule_status;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_share_expires_gmt;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_signed_share_url;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_visibility;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_gmt_to_local_datetime_input;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_is_versioning_enabled;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_local_datetime_to_gmt;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_versions;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_normalize_visibility;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_sanitize_roles;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_sanitize_user_ids;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_set_versions;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_sum_document_analytics;

final class Settings_UI {

    public const PAGE_SLUG = 'kitgenix-document-manager';

    /** @var string|null */
    private static $page_hook = null;

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 55 );

        add_action( 'admin_notices', [ __CLASS__, 'render_admin_notices' ] );

        add_action( 'admin_post_kitgenix_document_manager_document_save', [ __CLASS__, 'handle_document_save' ] );
        add_action( 'admin_post_kitgenix_document_manager_document_delete', [ __CLASS__, 'handle_document_delete' ] );
        add_action( 'admin_post_kitgenix_document_manager_documents_bulk_action', [ __CLASS__, 'handle_documents_bulk_action' ] );
        add_action( 'admin_post_kitgenix_document_manager_documents_bulk_upload', [ __CLASS__, 'handle_documents_bulk_upload' ] );

        add_action( 'admin_post_kitgenix_document_manager_version_restore', [ __CLASS__, 'handle_version_restore' ] );
        add_action( 'admin_post_kitgenix_document_manager_version_delete', [ __CLASS__, 'handle_version_delete' ] );
        add_action( 'admin_post_kitgenix_document_manager_versions_delete_all', [ __CLASS__, 'handle_versions_delete_all' ] );

        add_action( 'admin_post_kitgenix_document_manager_category_save', [ __CLASS__, 'handle_category_save' ] );
        add_action( 'admin_post_kitgenix_document_manager_category_delete', [ __CLASS__, 'handle_category_delete' ] );

        // Admin AJAX (used by the shortcode builder search).
        add_action( 'wp_ajax_kitgenix_dm_search_docs', [ __CLASS__, 'ajax_search_docs' ] );
        add_action( 'admin_post_kitgenix_document_manager_clear_event_log', [ __CLASS__, 'handle_clear_event_log' ] );
        add_action( 'update_option_kitgenix_document_manager_settings', [ __CLASS__, 'on_settings_saved' ], 10, 3 );
    }

    public static function ajax_search_docs(): void {
        if ( ! Capabilities::user_can_manage() ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
        if ( '' === $nonce ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 400 );
        }
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_dm_search_docs' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 400 );
        }

        $q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        $q = trim( $q );

        if ( $q === '' ) {
            wp_send_json_success( [ 'items' => [] ] );
        }

        $posts = get_posts(
            [
                'post_type'              => CPT::POST_TYPE,
                'post_status'            => 'publish',
                'posts_per_page'         => 20,
                'orderby'                => 'title',
                'order'                  => 'ASC',
                's'                      => $q,
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );

        $items = [];
        foreach ( $posts as $p ) {
            if ( ! ( $p instanceof \WP_Post ) ) {
                continue;
            }
            $slug = (string) $p->post_name;
            if ( $slug === '' ) {
                continue;
            }
            $items[] = [
                'slug'  => $slug,
                'title' => (string) $p->post_title,
            ];
        }

        wp_send_json_success( [ 'items' => $items ] );
    }

    private static function is_settings_screen_now(): bool {
        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && ! empty( self::$page_hook ) && $screen->id === self::$page_hook ) {
                return true;
            }
        }

        // Fallback via `page` query arg.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return $page === self::PAGE_SLUG;
    }

    public static function render_admin_notices(): void {
        if ( ! Capabilities::user_can_manage() ) {
            return;
        }

        if ( ! self::is_settings_screen_now() ) {
            return;
        }

        if ( function_exists( 'settings_errors' ) ) {
            settings_errors();
        }

        // Custom notices for document CRUD.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kitgenix_saved   = isset( $_GET['kitgenix_saved'] ) ? (int) $_GET['kitgenix_saved'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kitgenix_deleted = isset( $_GET['kitgenix_deleted'] ) ? (int) $_GET['kitgenix_deleted'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kitgenix_version_restored = isset( $_GET['kitgenix_version_restored'] ) ? (int) $_GET['kitgenix_version_restored'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kitgenix_version_deleted = isset( $_GET['kitgenix_version_deleted'] ) ? (int) $_GET['kitgenix_version_deleted'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kitgenix_category_saved = isset( $_GET['kitgenix_category_saved'] ) ? (int) $_GET['kitgenix_category_saved'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kitgenix_category_deleted = isset( $_GET['kitgenix_category_deleted'] ) ? (int) $_GET['kitgenix_category_deleted'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kitgenix_bulk_updated = isset( $_GET['kitgenix_bulk_updated'] ) ? (int) $_GET['kitgenix_bulk_updated'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kitgenix_bulk_action = isset( $_GET['kitgenix_bulk_action'] ) ? (string) sanitize_key( wp_unslash( $_GET['kitgenix_bulk_action'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kitgenix_bulk_uploaded = isset( $_GET['kitgenix_bulk_uploaded'] ) ? (int) $_GET['kitgenix_bulk_uploaded'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kitgenix_bulk_failed = isset( $_GET['kitgenix_bulk_failed'] ) ? (int) $_GET['kitgenix_bulk_failed'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kitgenix_error   = isset( $_GET['kitgenix_error'] ) ? (string) sanitize_text_field( wp_unslash( $_GET['kitgenix_error'] ) ) : '';

        if ( $kitgenix_saved ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Document saved.', 'kitgenix-document-manager' )
                . '</p></div>';
        }
        if ( $kitgenix_deleted ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Document deleted (moved to Trash).', 'kitgenix-document-manager' )
                . '</p></div>';
        }
        if ( $kitgenix_version_restored ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Version restored.', 'kitgenix-document-manager' )
                . '</p></div>';
        }
        if ( $kitgenix_version_deleted ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Version deleted.', 'kitgenix-document-manager' )
                . '</p></div>';
        }
        if ( $kitgenix_category_saved ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Category saved.', 'kitgenix-document-manager' )
                . '</p></div>';
        }
        if ( $kitgenix_category_deleted ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Category deleted.', 'kitgenix-document-manager' )
                . '</p></div>';
        }
        if ( $kitgenix_bulk_updated > 0 ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html( self::get_bulk_action_notice( $kitgenix_bulk_action, $kitgenix_bulk_updated ) )
                . '</p></div>';
        }
        if ( $kitgenix_bulk_uploaded > 0 ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(
                    sprintf(
                        /* translators: %s is the number of documents created via batch upload */
                        _n( '%s document created from batch upload.', '%s documents created from batch upload.', $kitgenix_bulk_uploaded, 'kitgenix-document-manager' ),
                        number_format_i18n( $kitgenix_bulk_uploaded )
                    )
                )
                . '</p></div>';
        }
        if ( $kitgenix_bulk_failed > 0 ) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . esc_html(
                    sprintf(
                        /* translators: %s is the number of files that failed during batch upload */
                        _n( '%s file could not be imported. Check the allowed file types and try again.', '%s files could not be imported. Check the allowed file types and try again.', $kitgenix_bulk_failed, 'kitgenix-document-manager' ),
                        number_format_i18n( $kitgenix_bulk_failed )
                    )
                )
                . '</p></div>';
        }
        if ( $kitgenix_error !== '' ) {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html( $kitgenix_error )
                . '</p></div>';
        }
    }

    public static function register_menu(): void {
        if ( function_exists( '\\kitgenix_ensure_admin_menu' ) ) {
            \kitgenix_ensure_admin_menu();
        }

        if ( ! Capabilities::user_can_manage() ) {
            return;
        }

        self::$page_hook = add_submenu_page(
            'kitgenix',
            __( 'Kitgenix Document Manager', 'kitgenix-document-manager' ),
            __( 'Document Manager', 'kitgenix-document-manager' ),
            'manage_options',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function is_settings_screen( string $hook_suffix ): bool {
        if ( ! empty( self::$page_hook ) && $hook_suffix === self::$page_hook ) {
            return true;
        }

        // Fallback via `page` query arg.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return $page === self::PAGE_SLUG;
    }

    public static function render_page(): void {
        if ( ! Capabilities::user_can_manage() ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'kitgenix-document-manager' ) );
        }

        $action = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['kitgenix_action'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $action = sanitize_key( wp_unslash( $_GET['kitgenix_action'] ) );
        }

        $edit_id = 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['document_id'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $edit_id = (int) $_GET['document_id'];
        }

        $edit_post = null;
        if ( $action === 'edit' && $edit_id ) {
            $p = get_post( $edit_id );
            if ( $p instanceof \WP_Post && $p->post_type === CPT::POST_TYPE ) {
                $edit_post = $p;
            }
        }

        $settings = kitgenix_document_manager_get_settings();
        $ver = defined( 'KITGENIX_DOCUMENT_MANAGER_VERSION' ) ? (string) KITGENIX_DOCUMENT_MANAGER_VERSION : '';
        $logo = defined( 'KITGENIX_DOCUMENT_MANAGER_FILE' ) ? plugins_url( 'assets/images/logos/kitgenix-favicon-purple.svg', KITGENIX_DOCUMENT_MANAGER_FILE ) : '';
        $social_base = defined( 'KITGENIX_DOCUMENT_MANAGER_FILE' ) ? plugins_url( 'assets/images/social-media/', KITGENIX_DOCUMENT_MANAGER_FILE ) : '';
        $social_base = defined( 'KITGENIX_DOCUMENT_MANAGER_FILE' ) ? plugins_url( 'assets/images/social-media/', KITGENIX_DOCUMENT_MANAGER_FILE ) : '';

        // Support legacy query arg navigation.
        $default_tab = 'documents';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['tab'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $maybe_tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
            if ( in_array( $maybe_tab, [ 'documents', 'categories', 'versions', 'settings', 'frontend', 'support', 'log' ], true ) ) {
                $default_tab = $maybe_tab;
            }
        }
        if ( $action === 'edit' && $edit_post instanceof \WP_Post ) {
            $default_tab = 'documents';
        }

        echo '<div class="wrap kitgenix-admin-app kitgenix-document-manager-use-top-tabs" id="kitgenix-document-manager-admin-app" data-kitgenix-tabs data-kitgenix-default-tab="' . esc_attr( $default_tab ) . '">';

        echo '<div class="kitgenix-document-manager-settings-intro kitgenix-settings-header">'
            . '<div class="kitgenix-settings-header-row">'
            . '<div class="kitgenix-settings-header-main">'
            . '<div class="kitgenix-settings-brand">'
            . ( $logo ? '<img class="kitgenix-settings-logo" src="' . esc_url( $logo ) . '" alt="' . esc_attr__( 'Kitgenix', 'kitgenix-document-manager' ) . '" />' : '' )
            . '<h1 class="kitgenix-document-manager-admin-title">' . esc_html__( 'Kitgenix Document Manager', 'kitgenix-document-manager' ) . '</h1>'
            . '</div>'
            . '<p>' . esc_html__( 'Create stable document links in one place. Replace a file without changing its URL.', 'kitgenix-document-manager' ) . '</p>'
            . ( $ver ? '<div class="kitgenix-settings-meta"><span class="kitgenix-settings-version" aria-label="Plugin version">v' . esc_html( $ver ) . '</span></div>' : '' )
            . '</div>'
            . '<div class="kitgenix-settings-header-actions">'
            . '<div class="kitgenix-intro-links kitgenix-document-manager-intro-links">'
            . '<a href="' . esc_url( 'https://kitgenix.com/plugins/kitgenix-document-manager/documentation/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'kitgenix-document-manager' ) . '</a>'
            . '<a href="' . esc_url( 'https://wordpress.org/support/plugin/kitgenix-document-manager/reviews/#new-post' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Review Plugin', 'kitgenix-document-manager' ) . '</a>'
            . '<a href="' . esc_url( 'https://wordpress.org/support/plugin/kitgenix-document-manager/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support Request', 'kitgenix-document-manager' ) . '</a>'
            . '<a href="' . esc_url( 'https://buymeacoffee.com/kitgenix' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support Kitgenix', 'kitgenix-document-manager' ) . '</a>'
            . '</div>'
            . ( $social_base ? '<div class="kitgenix-social-links kitgenix-social-links--icons">'
                . '<a href="https://kitgenix.com" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="' . esc_url( plugins_url( 'assets/images/social-media/globe-solid.svg', KITGENIX_DOCUMENT_MANAGER_FILE ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Website</span></a>'
                . '<a href="https://www.facebook.com/groups/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook Community" title="Facebook Community"><img src="' . esc_url( plugins_url( 'assets/images/social-media/facebook-solid.svg', KITGENIX_DOCUMENT_MANAGER_FILE ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook Community</span></a>'
                . '<a href="https://www.facebook.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><img src="' . esc_url( plugins_url( 'assets/images/social-media/facebook-solid.svg', KITGENIX_DOCUMENT_MANAGER_FILE ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook</span></a>'
                . '<a href="https://www.instagram.com/kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram"><img src="' . esc_url( plugins_url( 'assets/images/social-media/instagram-solid.svg', KITGENIX_DOCUMENT_MANAGER_FILE ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Instagram</span></a>'
                . '<a href="https://www.youtube.com/@Kitgenix" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube"><img src="' . esc_url( plugins_url( 'assets/images/social-media/youtube-solid.svg', KITGENIX_DOCUMENT_MANAGER_FILE ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">YouTube</span></a>'
                . '<a href="https://www.reddit.com/r/Kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Reddit" title="Reddit"><img src="' . esc_url( plugins_url( 'assets/images/social-media/reddit-solid.svg', KITGENIX_DOCUMENT_MANAGER_FILE ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Reddit</span></a>'
                . '<a href="https://www.linkedin.com/company/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><img src="' . esc_url( plugins_url( 'assets/images/social-media/linkedin-solid.svg', KITGENIX_DOCUMENT_MANAGER_FILE ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">LinkedIn</span></a>'
                . '<a href="https://x.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="X" title="X"><img src="' . esc_url( plugins_url( 'assets/images/social-media/x-solid.svg', KITGENIX_DOCUMENT_MANAGER_FILE ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">X</span></a>'
                . '<a href="https://www.tiktok.com/@kitgenix" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok"><img src="' . esc_url( plugins_url( 'assets/images/social-media/tiktok-solid.svg', KITGENIX_DOCUMENT_MANAGER_FILE ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">TikTok</span></a>'
                . '<a href="https://github.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="GitHub"><img src="' . esc_url( plugins_url( 'assets/images/social-media/github-solid.svg', KITGENIX_DOCUMENT_MANAGER_FILE ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">GitHub</span></a>'
                . '</div>' : '' )
            . '</div>'
            . '</div>'
            . '</div>';

        $base_tab_url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) );

        echo '<h2 class="nav-tab-wrapper kitgenix-nav-tabs" aria-label="Settings navigation">'
            . '<a class="nav-tab ' . ( $default_tab === 'documents' ? 'nav-tab-active' : '' ) . ' kitgenix-tab-trigger" href="' . esc_url( $base_tab_url . '&tab=documents#kitgenix-tab-documents' ) . '" data-kitgenix-tab="documents">' . esc_html__( 'Documents', 'kitgenix-document-manager' ) . '</a>'
            . '<a class="nav-tab ' . ( $default_tab === 'categories' ? 'nav-tab-active' : '' ) . ' kitgenix-tab-trigger" href="' . esc_url( $base_tab_url . '&tab=categories#kitgenix-tab-categories' ) . '" data-kitgenix-tab="categories">' . esc_html__( 'Categories', 'kitgenix-document-manager' ) . '</a>'
            . '<a class="nav-tab ' . ( $default_tab === 'versions' ? 'nav-tab-active' : '' ) . ' kitgenix-tab-trigger" href="' . esc_url( $base_tab_url . '&tab=versions#kitgenix-tab-versions' ) . '" data-kitgenix-tab="versions">' . esc_html__( 'Versions', 'kitgenix-document-manager' ) . '</a>'
            . '<a class="nav-tab ' . ( $default_tab === 'settings' ? 'nav-tab-active' : '' ) . ' kitgenix-tab-trigger" href="' . esc_url( $base_tab_url . '&tab=settings#kitgenix-tab-settings' ) . '" data-kitgenix-tab="settings">' . esc_html__( 'Settings', 'kitgenix-document-manager' ) . '</a>'
            . '<a class="nav-tab ' . ( $default_tab === 'frontend' ? 'nav-tab-active' : '' ) . ' kitgenix-tab-trigger" href="' . esc_url( $base_tab_url . '&tab=frontend#kitgenix-tab-frontend' ) . '" data-kitgenix-tab="frontend">' . esc_html__( 'Frontend', 'kitgenix-document-manager' ) . '</a>'
            . '<a class="nav-tab ' . ( $default_tab === 'support' ? 'nav-tab-active' : '' ) . ' kitgenix-tab-trigger" href="' . esc_url( $base_tab_url . '&tab=support#kitgenix-tab-support' ) . '" data-kitgenix-tab="support">' . esc_html__( 'Support', 'kitgenix-document-manager' ) . '</a>'
            . '<a class="nav-tab ' . ( $default_tab === 'log' ? 'nav-tab-active' : '' ) . ' kitgenix-tab-trigger" href="' . esc_url( $base_tab_url . '&tab=log#kitgenix-tab-log' ) . '" data-kitgenix-tab="log">' . esc_html__( 'Log', 'kitgenix-document-manager' ) . '</a>'
            . '</h2>';

        echo '<div class="kitgenix-settings-layout"><div class="kitgenix-settings-content" id="kitgenix-settings-content" tabindex="-1">';

        echo '<div id="kitgenix-document-manager-tab-documents" data-kitgenix-tab-panel="documents"' . ( $default_tab !== 'documents' ? ' hidden="hidden" style="display:none;"' : '' ) . '>';
        self::render_documents_tab( $settings, $edit_post );
        echo '</div>';

        echo '<div id="kitgenix-document-manager-tab-categories" data-kitgenix-tab-panel="categories"' . ( $default_tab !== 'categories' ? ' hidden="hidden" style="display:none;"' : '' ) . '>';
        self::render_categories_tab();
        echo '</div>';

        echo '<div id="kitgenix-document-manager-tab-versions" data-kitgenix-tab-panel="versions"' . ( $default_tab !== 'versions' ? ' hidden="hidden" style="display:none;"' : '' ) . '>';
        self::render_versions_tab();
        echo '</div>';

        echo '<div id="kitgenix-document-manager-tab-settings" data-kitgenix-tab-panel="settings"' . ( $default_tab !== 'settings' ? ' hidden="hidden" style="display:none;"' : '' ) . '>';
        self::render_settings_tab( $settings );
        echo '</div>';

        echo '<div id="kitgenix-document-manager-tab-frontend" data-kitgenix-tab-panel="frontend"' . ( $default_tab !== 'frontend' ? ' hidden="hidden" style="display:none;"' : '' ) . '>';
        self::render_frontend_tab( $settings );
        echo '</div>';

        echo '<div id="kitgenix-document-manager-tab-support" data-kitgenix-tab-panel="support"' . ( $default_tab !== 'support' ? ' hidden="hidden" style="display:none;"' : '' ) . '>';
        self::render_support_tab( $settings );
        echo '</div>';

        echo '<div id="kitgenix-document-manager-tab-log" data-kitgenix-tab-panel="log"' . ( $default_tab !== 'log' ? ' hidden="hidden" style="display:none;"' : '' ) . '>';
        self::render_log_tab();
        echo '</div>';

        echo '</div>';
        self::render_sidebar();
        echo '</div>';

        echo '</div>';
    }

    private static function render_sidebar(): void {
        $social_base = plugins_url( 'assets/images/social-media/', KITGENIX_DOCUMENT_MANAGER_FILE );

        echo '<aside class="kitgenix-settings-sidebar" aria-label="' . esc_attr__( 'Help and links', 'kitgenix-document-manager' ) . '">';

        echo '<div class="kitgenix-sidebar-card">'
            . '<h2>' . esc_html__( 'Need Help?', 'kitgenix-document-manager' ) . '</h2>'
            . '<p>' . esc_html__( 'Open the documentation for setup guidance or send us a support request if you need help configuring the plugin.', 'kitgenix-document-manager' ) . '</p>'
            . '<div class="kitgenix-sidebar-actions">'
            . '<a class="button button-secondary" href="' . esc_url( 'https://kitgenix.com/plugins/kitgenix-document-manager/documentation/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'kitgenix-document-manager' ) . '</a>'
            . '<a class="button button-primary" href="' . esc_url( 'https://kitgenix.com/plugins/kitgenix-document-manager/support' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Request Support', 'kitgenix-document-manager' ) . '</a>'
            . '</div>'
            . '</div>';

        echo '<div class="kitgenix-sidebar-card">'
            . '<h2>' . esc_html__( 'Visit Our Official Facebook Group', 'kitgenix-document-manager' ) . '</h2>'
            . '<p>' . esc_html__( 'Join the Kitgenix community to ask questions, share feedback, and keep up with product updates.', 'kitgenix-document-manager' ) . '</p>'
            . '<div class="kitgenix-sidebar-actions">'
            . '<a class="button button-secondary" href="' . esc_url( 'https://www.facebook.com/groups/kitgenix' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Join Group', 'kitgenix-document-manager' ) . '</a>'
            . '</div>'
            . '</div>';

        echo '<div class="kitgenix-sidebar-card">'
            . '<h2>' . esc_html__( 'Follow Us', 'kitgenix-document-manager' ) . '</h2>'
            . '<p>' . esc_html__( 'Keep up with new releases, tutorials, and product news across our channels.', 'kitgenix-document-manager' ) . '</p>'
            . '<div class="kitgenix-sidebar-social-grid">'
            . '<a class="kitgenix-sidebar-social-link" href="https://kitgenix.com" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="' . esc_url( $social_base . 'globe-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
            . '<a class="kitgenix-sidebar-social-link" href="https://www.facebook.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><img src="' . esc_url( $social_base . 'facebook-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
            . '<a class="kitgenix-sidebar-social-link" href="https://www.instagram.com/kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram"><img src="' . esc_url( $social_base . 'instagram-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
            . '<a class="kitgenix-sidebar-social-link" href="https://www.youtube.com/@Kitgenix" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube"><img src="' . esc_url( $social_base . 'youtube-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
            . '<a class="kitgenix-sidebar-social-link" href="https://www.reddit.com/r/Kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Reddit" title="Reddit"><img src="' . esc_url( $social_base . 'reddit-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
            . '<a class="kitgenix-sidebar-social-link" href="https://www.linkedin.com/company/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><img src="' . esc_url( $social_base . 'linkedin-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
            . '<a class="kitgenix-sidebar-social-link" href="https://x.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="X" title="X"><img src="' . esc_url( $social_base . 'x-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
            . '<a class="kitgenix-sidebar-social-link" href="https://www.tiktok.com/@kitgenix" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok"><img src="' . esc_url( $social_base . 'tiktok-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
            . '<a class="kitgenix-sidebar-social-link" href="https://github.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="GitHub"><img src="' . esc_url( $social_base . 'github-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
            . '</div>'
            . '</div>';

        echo '</aside>';
    }

    private static function render_support_tab( array $settings ): void {
        $doc_count = 0;
        $counts    = wp_count_posts( CPT::POST_TYPE );
        if ( is_object( $counts ) && isset( $counts->publish ) ) {
            $doc_count = (int) $counts->publish;
        }

        $private_count    = 0;
        $restricted_count = 0;
        $versioned_count  = 0;
        $scheduled_count  = 0;
        $archived_count   = 0;
        $expired_count    = 0;
        $views_30         = 0;
        $downloads_30     = 0;
        $signed_30        = 0;
        $ids              = get_posts(
            [
                'post_type'        => CPT::POST_TYPE,
                'post_status'      => 'publish',
                'fields'           => 'ids',
                'numberposts'      => -1,
                'no_found_rows'    => true,
                'cache_results'    => false,
                'suppress_filters' => false,
            ]
        );
        if ( is_array( $ids ) ) {
            foreach ( $ids as $id ) {
                $id = (int) $id;
                $visibility = kitgenix_document_manager_get_document_visibility( $id );
                if ( $visibility === 'private' ) {
                    $private_count++;
                } elseif ( $visibility === 'restricted' ) {
                    $restricted_count++;
                }
                if ( kitgenix_document_manager_is_versioning_enabled( $id ) ) {
                    $versioned_count++;
                }

                $schedule_status = kitgenix_document_manager_get_document_schedule_status( $id );
                if ( ( $schedule_status['status'] ?? 'active' ) === 'scheduled' ) {
                    $scheduled_count++;
                } elseif ( ( $schedule_status['status'] ?? 'active' ) === 'archived' ) {
                    $archived_count++;
                } elseif ( ( $schedule_status['status'] ?? 'active' ) === 'expired' ) {
                    $expired_count++;
                }

                $analytics = kitgenix_document_manager_get_document_analytics( $id );
                $recent    = kitgenix_document_manager_sum_document_analytics( $analytics, 30 );
                $views_30 += absint( $recent['views'] ?? 0 );
                $downloads_30 += absint( $recent['downloads'] ?? 0 );
                $signed_30 += absint( $recent['signed_views'] ?? 0 ) + absint( $recent['signed_downloads'] ?? 0 );
            }
        }

        $allowed_extensions = isset( $settings['allowed_extensions'] ) && is_array( $settings['allowed_extensions'] )
            ? implode( ', ', $settings['allowed_extensions'] )
            : '';
        $serving_mode      = (string) ( $settings['serving_mode'] ?? 'attachment' );
        $private_behavior  = (string) ( $settings['private_behavior'] ?? 'login' );

        $donate_once_url     = 'https://buymeacoffee.com/kitgenix';
        $monthly_support_url = 'https://buymeacoffee.com/kitgenix/membership';
        $plugin_page_url     = 'https://kitgenix.com/plugins/kitgenix-document-manager/';
        $review_url          = 'https://wordpress.org/support/plugin/kitgenix-document-manager/reviews/#new-post';
        $support_request_url = 'https://kitgenix.com/plugins/kitgenix-document-manager/support';
        $copy_onclick        = "if(window.navigator&&navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(" . wp_json_encode( $plugin_page_url ) . ");}else{window.prompt(" . wp_json_encode( __( 'Copy plugin link:', 'kitgenix-document-manager' ) ) . ", " . wp_json_encode( $plugin_page_url ) . ");}return false;";
        $monthly_options     = [
            [ 'label' => __( 'Kitgenix Supporter (£4/month)', 'kitgenix-document-manager' ), 'url' => 'https://buymeacoffee.com/kitgenix/membership' ],
            [ 'label' => __( 'Kitgenix Plus (£8/month)', 'kitgenix-document-manager' ), 'url' => 'https://buymeacoffee.com/kitgenix/membership' ],
            [ 'label' => __( 'Kitgenix Pro Supporter (£19/month)', 'kitgenix-document-manager' ), 'url' => 'https://buymeacoffee.com/kitgenix/membership' ],
            [ 'label' => __( 'Kitgenix Agency (£37/month)', 'kitgenix-document-manager' ), 'url' => 'https://buymeacoffee.com/kitgenix/membership' ],
            [ 'label' => __( 'Kitgenix Partner (£75/month)', 'kitgenix-document-manager' ), 'url' => 'https://buymeacoffee.com/kitgenix/membership' ],
            [ 'label' => __( 'Kitgenix YouTube Sponsor (£730/month)', 'kitgenix-document-manager' ), 'url' => 'https://buymeacoffee.com/kitgenix/membership' ],
        ];

        $impact_cards = [
            [
                'label' => __( 'Documents managed', 'kitgenix-document-manager' ),
                'value' => number_format_i18n( $doc_count ),
                'meta'  => __( 'Published documents currently maintained in the library.', 'kitgenix-document-manager' ),
            ],
            [
                'label' => __( 'Protected documents', 'kitgenix-document-manager' ),
                'value' => number_format_i18n( $private_count + $restricted_count ),
                'meta'  => __( 'Documents requiring login, signed links, or granular access rules.', 'kitgenix-document-manager' ),
            ],
            [
                'label' => __( 'Version history enabled', 'kitgenix-document-manager' ),
                'value' => number_format_i18n( $versioned_count ),
                'meta'  => __( 'Documents keeping previous uploads for safer updates.', 'kitgenix-document-manager' ),
            ],
            [
                'label' => __( 'Views in last 30 days', 'kitgenix-document-manager' ),
                'value' => number_format_i18n( $views_30 ),
                'meta'  => __( 'Successful inline document opens recorded by the stable link endpoint.', 'kitgenix-document-manager' ),
            ],
            [
                'label' => __( 'Downloads in last 30 days', 'kitgenix-document-manager' ),
                'value' => number_format_i18n( $downloads_30 ),
                'meta'  => __( 'Successful attachment-style downloads recorded by the stable link endpoint.', 'kitgenix-document-manager' ),
            ],
            [
                'label' => __( 'Signed-share activity', 'kitgenix-document-manager' ),
                'value' => number_format_i18n( $signed_30 ),
                'meta'  => __( 'Views and downloads served through signed share URLs in the last 30 days.', 'kitgenix-document-manager' ),
            ],
            [
                'label' => __( 'Lifecycle rules active', 'kitgenix-document-manager' ),
                'value' => number_format_i18n( $scheduled_count + $archived_count + $expired_count ),
                'meta'  => __( 'Documents currently governed by go-live, archive, or expiry dates.', 'kitgenix-document-manager' ),
            ],
            [
                'label' => __( 'Serving mode', 'kitgenix-document-manager' ),
                'value' => $serving_mode === 'inline' ? __( 'Inline', 'kitgenix-document-manager' ) : __( 'Download', 'kitgenix-document-manager' ),
                'meta'  => __( 'How browsers currently receive protected files.', 'kitgenix-document-manager' ),
            ],
            [
                'label' => __( 'Private behavior', 'kitgenix-document-manager' ),
                'value' => $private_behavior === '403' ? __( '403', 'kitgenix-document-manager' ) : __( 'Login', 'kitgenix-document-manager' ),
                'meta'  => __( 'What visitors see when protected files require access.', 'kitgenix-document-manager' ),
            ],
        ];
        $meaning_points = [
            __( 'Your site already has a live document library being managed centrally inside WordPress.', 'kitgenix-document-manager' ),
            __( 'Protected documents show the plugin is actively handling logged-in, role-based, and signed-share access.', 'kitgenix-document-manager' ),
            __( 'Lifecycle and analytics metrics show when files are live and how often people are actually using them.', 'kitgenix-document-manager' ),
            __( 'Versioning and delivery settings define how safely files are served and maintained over time.', 'kitgenix-document-manager' ),
        ];
        $support_points = [
            __( 'Compatibility updates for new WordPress / WooCommerce releases', 'kitgenix-document-manager' ),
            __( 'Bug fixes, edge-case testing, and better multi-site and access-control coverage', 'kitgenix-document-manager' ),
            __( 'Security hardening and ongoing performance improvements', 'kitgenix-document-manager' ),
            __( 'Documentation upgrades and faster, clearer support responses', 'kitgenix-document-manager' ),
        ];
        $trust_points = [
            __( 'No paid features locked behind donations', 'kitgenix-document-manager' ),
            __( 'No tracking or invasive upsells', 'kitgenix-document-manager' ),
            __( 'Support is always optional, and genuinely appreciated.', 'kitgenix-document-manager' ),
        ];
        ?>
        <div class="kitgenix-card kitgenix-document-manager-support-page kitgenix-support-page">
            <div class="kitgenix-support-shell">
                <section class="kitgenix-support-hero">
                    <div class="kitgenix-support-hero__copy">
                        <span class="kitgenix-support-eyebrow"><?php echo esc_html__( 'Help keep Kitgenix independent', 'kitgenix-document-manager' ); ?></span>
                        <h2 class="kitgenix-support-heading"><?php echo esc_html__( 'Support Kitgenix', 'kitgenix-document-manager' ); ?></h2>
                        <p class="description kitgenix-support-intro"><?php echo esc_html__( 'We try to keep Kitgenix plugins lightweight, privacy-friendly, and free for everyone. If Document Manager saves you admin time or helps prevent scattered document workflows, please consider supporting Kitgenix. Your support directly funds ongoing development, testing, and maintenance so we can keep features open and updates frequent.', 'kitgenix-document-manager' ); ?></p>
                    </div>
                    <div class="kitgenix-support-hero__aside">
                        <p class="kitgenix-support-kicker"><?php echo esc_html__( 'Support this plugin', 'kitgenix-document-manager' ); ?></p>
                        <div class="kitgenix-support-actions">
                            <a class="button button-primary" href="<?php echo esc_url( $donate_once_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Buy Me a Coffee', 'kitgenix-document-manager' ); ?></a>
                            <a class="button button-secondary" href="<?php echo esc_url( $monthly_support_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Become a member', 'kitgenix-document-manager' ); ?></a>
                        </div>
                        <p class="kitgenix-support-note"><?php echo esc_html__( 'Via Buy Me a Coffee. Cancel anytime.', 'kitgenix-document-manager' ); ?></p>
                    </div>
                </section>

                <section class="kitgenix-support-section kitgenix-support-section--feature">
                    <div class="kitgenix-support-section__header">
                        <h3 class="kitgenix-support-subheading"><?php echo esc_html__( 'Your site impact', 'kitgenix-document-manager' ); ?></h3>
                        <p class="description"><?php echo esc_html__( 'These stats show how Document Manager is currently working on your site:', 'kitgenix-document-manager' ); ?></p>
                    </div>
                    <div class="kitgenix-support-metric-grid">
                        <?php foreach ( $impact_cards as $impact_card ) : ?>
                            <div class="kitgenix-support-stat">
                                <span class="kitgenix-support-stat__label"><?php echo esc_html( $impact_card['label'] ); ?></span>
                                <strong class="kitgenix-support-stat__value"><?php echo esc_html( $impact_card['value'] ); ?></strong>
                                <span class="kitgenix-support-stat__meta"><?php echo esc_html( $impact_card['meta'] ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ( $allowed_extensions ) : ?>
                        <p class="kitgenix-support-footnote"><?php echo esc_html__( 'Allowed file extensions:', 'kitgenix-document-manager' ); ?> <strong><?php echo esc_html( $allowed_extensions ); ?></strong></p>
                    <?php endif; ?>
                </section>

                <div class="kitgenix-support-grid">
                    <section class="kitgenix-support-section">
                        <h3 class="kitgenix-support-subheading"><?php echo esc_html__( 'Support options', 'kitgenix-document-manager' ); ?></h3>
                        <p class="description"><?php echo esc_html__( 'Buy Me a Coffee: A quick way to say thanks and help fund the next round of improvements.', 'kitgenix-document-manager' ); ?></p>
                        <p class="description"><?php echo esc_html__( 'A membership helps keep development consistent if Document Manager is part of your day-to-day workflow.', 'kitgenix-document-manager' ); ?></p>
                        <div class="kitgenix-support-chip-list">
                            <?php foreach ( $monthly_options as $monthly_option ) : ?>
                                <?php
                                $monthly_label = (string) $monthly_option['label'];
                                $monthly_name  = $monthly_label;
                                $monthly_price = '';
                                if ( preg_match( '/^(.*)\(([^)]+)\)$/u', $monthly_label, $monthly_parts ) ) {
                                    $monthly_name  = trim( $monthly_parts[1] );
                                    $monthly_price = trim( $monthly_parts[2] );
                                }
                                ?>
                                <a class="kitgenix-support-chip" href="<?php echo esc_url( $monthly_option['url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                    <span class="kitgenix-support-chip__name"><?php echo esc_html( $monthly_name ); ?></span>
                                    <?php if ( '' !== $monthly_price ) : ?>
                                        <span class="kitgenix-support-chip__price"><?php echo esc_html( $monthly_price ); ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="kitgenix-support-section">
                        <h3 class="kitgenix-support-subheading"><?php echo esc_html__( 'What this means', 'kitgenix-document-manager' ); ?></h3>
                        <ul class="kitgenix-support-list">
                            <?php foreach ( $meaning_points as $meaning_point ) : ?>
                                <li><?php echo esc_html( $meaning_point ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>

                    <section class="kitgenix-support-section kitgenix-support-section--soft">
                        <h3 class="kitgenix-support-subheading"><?php echo esc_html__( 'What your support helps with', 'kitgenix-document-manager' ); ?></h3>
                        <ul class="kitgenix-support-list">
                            <?php foreach ( $support_points as $support_point ) : ?>
                                <li><?php echo esc_html( $support_point ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>

                    <section class="kitgenix-support-section">
                        <h3 class="kitgenix-support-subheading"><?php echo esc_html__( 'Not in a position to donate?', 'kitgenix-document-manager' ); ?></h3>
                        <p class="description"><?php echo esc_html__( 'No worries - you can still massively help:', 'kitgenix-document-manager' ); ?></p>
                        <p class="description"><?php echo esc_html__( 'Reviews help others discover the plugin and keep the project sustainable. Sharing the plugin with teams that manage public and private files, and sending strong issue reports, both help improve the product faster.', 'kitgenix-document-manager' ); ?></p>
                        <div class="kitgenix-support-actions">
                            <a class="button button-secondary" href="<?php echo esc_url( $review_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Leave a WordPress.org review', 'kitgenix-document-manager' ); ?></a>
                            <button type="button" class="button button-secondary" onclick="<?php echo esc_attr( $copy_onclick ); ?>"><?php echo esc_html__( 'Copy plugin link', 'kitgenix-document-manager' ); ?></button>
                            <a class="button button-secondary" href="<?php echo esc_url( $support_request_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open support / feature request', 'kitgenix-document-manager' ); ?></a>
                        </div>
                    </section>

                    <section class="kitgenix-support-section kitgenix-support-section--full">
                        <h3 class="kitgenix-support-subheading"><?php echo esc_html__( 'A small note on trust & privacy', 'kitgenix-document-manager' ); ?></h3>
                        <ul class="kitgenix-support-list">
                            <?php foreach ( $trust_points as $trust_point ) : ?>
                                <li><?php echo esc_html( $trust_point ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="kitgenix-support-footer-note"><?php echo esc_html__( 'Thank you for supporting Kitgenix.', 'kitgenix-document-manager' ); ?></p>
                    </section>
                </div>
            </div>
        </div>
        <?php
    }

    private static function get_document_role_choices(): array {
        $wp_roles = function_exists( 'wp_roles' ) ? wp_roles() : null;
        if ( ! ( $wp_roles instanceof \WP_Roles ) ) {
            return [];
        }

        $choices = [];
        foreach ( $wp_roles->roles as $role_key => $role_data ) {
            $role_key = sanitize_key( (string) $role_key );
            if ( $role_key === '' ) {
                continue;
            }

            $label = isset( $role_data['name'] ) ? translate_user_role( (string) $role_data['name'] ) : $role_key;
            $choices[ $role_key ] = $label;
        }

        return $choices;
    }

    private static function format_document_visibility_label( string $visibility ): string {
        switch ( $visibility ) {
            case 'private':
                return __( 'Private', 'kitgenix-document-manager' );

            case 'restricted':
                return __( 'Restricted', 'kitgenix-document-manager' );

            default:
                return __( 'Public', 'kitgenix-document-manager' );
        }
    }

    private static function get_document_access_state( int $post_id = 0 ): array {
        $state = [
            'roles'                => [],
            'capability'           => '',
            'user_ids'             => '',
            'share_expires_input'  => '',
            'share_url'            => '',
            'share_note'           => __( 'Set an expiry time to generate a signed share URL that bypasses login until it expires.', 'kitgenix-document-manager' ),
            'available_from_input' => '',
            'archive_at_input'     => '',
            'expires_at_input'     => '',
            'schedule_label'       => __( 'Active', 'kitgenix-document-manager' ),
            'schedule_status'      => 'active',
        ];

        if ( $post_id <= 0 ) {
            return $state;
        }

        $state['roles']      = kitgenix_document_manager_get_document_allowed_roles( $post_id );
        $state['capability'] = kitgenix_document_manager_get_document_required_capability( $post_id );
        $state['user_ids']   = implode( ', ', kitgenix_document_manager_get_document_allowed_user_ids( $post_id ) );

        $share_expires_gmt = kitgenix_document_manager_get_document_share_expires_gmt( $post_id );
        if ( $share_expires_gmt !== '' ) {
            $state['share_expires_input'] = kitgenix_document_manager_gmt_to_local_datetime_input( $share_expires_gmt );
            $state['share_url']           = kitgenix_document_manager_get_document_signed_share_url( $post_id );

            $share_ts = strtotime( $share_expires_gmt . ' GMT' );
            if ( $share_ts && $share_ts <= time() ) {
                $state['share_note'] = __( 'This signed share link has expired. Update the expiry to generate a fresh URL.', 'kitgenix-document-manager' );
            } elseif ( $state['share_url'] !== '' ) {
                $state['share_note'] = __( 'Anyone with this signed URL can access the current file until the expiry time.', 'kitgenix-document-manager' );
            }
        }

        $schedule = kitgenix_document_manager_get_document_schedule( $post_id );
        if ( $schedule['available_from_gmt'] !== '' ) {
            $state['available_from_input'] = kitgenix_document_manager_gmt_to_local_datetime_input( $schedule['available_from_gmt'] );
        }
        if ( $schedule['archive_at_gmt'] !== '' ) {
            $state['archive_at_input'] = kitgenix_document_manager_gmt_to_local_datetime_input( $schedule['archive_at_gmt'] );
        }
        if ( $schedule['expires_at_gmt'] !== '' ) {
            $state['expires_at_input'] = kitgenix_document_manager_gmt_to_local_datetime_input( $schedule['expires_at_gmt'] );
        }

        $schedule_status = kitgenix_document_manager_get_document_schedule_status( $post_id );
        $state['schedule_label']  = (string) ( $schedule_status['label'] ?? __( 'Active', 'kitgenix-document-manager' ) );
        $state['schedule_status'] = (string) ( $schedule_status['status'] ?? 'active' );

        return $state;
    }

    private static function render_document_access_fields( array $state, string $prefix ): string {
        $html         = '';
        $roles        = isset( $state['roles'] ) && is_array( $state['roles'] ) ? $state['roles'] : [];
        $capability   = (string) ( $state['capability'] ?? '' );
        $user_ids     = (string) ( $state['user_ids'] ?? '' );
        $role_choices = self::get_document_role_choices();

        $html .= '<div class="kitgenix-field">'
            . '<label for="' . esc_attr( $prefix . 'access_roles' ) . '">' . esc_html__( 'Allowed roles (optional)', 'kitgenix-document-manager' ) . '</label>'
            . '<select id="' . esc_attr( $prefix . 'access_roles' ) . '" name="access_roles[]" multiple="multiple" size="5">';

        foreach ( $role_choices as $role_key => $role_label ) {
            $html .= '<option value="' . esc_attr( $role_key ) . '"' . selected( in_array( $role_key, $roles, true ), true, false ) . '>' . esc_html( $role_label ) . '</option>';
        }

        $html .= '</select>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Leave empty to use the base visibility rule. Add roles to require one of them for access.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        $html .= '<div class="kitgenix-field">'
            . '<label for="' . esc_attr( $prefix . 'access_capability' ) . '">' . esc_html__( 'Required capability (optional)', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="text" id="' . esc_attr( $prefix . 'access_capability' ) . '" name="access_capability" value="' . esc_attr( $capability ) . '" class="regular-text" placeholder="manage_options" />'
            . '<div class="kitgenix-muted">' . esc_html__( 'Optional WordPress capability. Users with this capability can access the document even if they do not match a listed role.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        $html .= '<div class="kitgenix-field">'
            . '<label for="' . esc_attr( $prefix . 'access_user_ids' ) . '">' . esc_html__( 'Allowed user IDs (optional)', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="text" id="' . esc_attr( $prefix . 'access_user_ids' ) . '" name="access_user_ids" value="' . esc_attr( $user_ids ) . '" class="regular-text" placeholder="12, 48, 91" />'
            . '<div class="kitgenix-muted">' . esc_html__( 'Comma-separated WordPress user IDs for one-off access grants.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        return $html;
    }

    private static function render_document_share_fields( array $state, string $prefix ): string {
        $share_expires = (string) ( $state['share_expires_input'] ?? '' );
        $share_url     = (string) ( $state['share_url'] ?? '' );
        $share_note    = (string) ( $state['share_note'] ?? '' );

        $html = '<div class="kitgenix-field">'
            . '<label for="' . esc_attr( $prefix . 'share_expires_at' ) . '">' . esc_html__( 'Signed share link expiry', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="datetime-local" id="' . esc_attr( $prefix . 'share_expires_at' ) . '" name="share_expires_at" value="' . esc_attr( $share_expires ) . '" class="regular-text" />'
            . '<div class="kitgenix-muted">' . esc_html__( 'Anyone with the generated link can access the current file until this time.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        $html .= '<div class="kitgenix-field">'
            . '<label for="' . esc_attr( $prefix . 'share_url' ) . '">' . esc_html__( 'Signed share URL', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="text" id="' . esc_attr( $prefix . 'share_url' ) . '" value="' . esc_attr( $share_url ) . '" class="regular-text code" readonly="readonly" />'
            . '<div style="margin-top:6px;">'
            . '<button type="button" id="' . esc_attr( $prefix . 'share_url_copy' ) . '" class="button" data-default-label="' . esc_attr__( 'Copy share link', 'kitgenix-document-manager' ) . '" data-copied-label="' . esc_attr__( 'Copied', 'kitgenix-document-manager' ) . '" data-kitgenix-copy="' . esc_attr( $share_url ) . '">' . esc_html__( 'Copy share link', 'kitgenix-document-manager' ) . '</button>'
            . ' <a class="button" id="' . esc_attr( $prefix . 'share_url_open' ) . '" href="' . esc_url( $share_url ) . '" target="_blank" rel="noopener noreferrer"' . ( $share_url === '' ? ' style="display:none;"' : '' ) . '>' . esc_html__( 'Open share link', 'kitgenix-document-manager' ) . '</a>';

        $html .= '</div>';
        if ( $share_note !== '' ) {
            $html .= '<div class="kitgenix-muted">' . esc_html( $share_note ) . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private static function render_document_schedule_fields( array $state, string $prefix ): string {
        $available_from = (string) ( $state['available_from_input'] ?? '' );
        $archive_at     = (string) ( $state['archive_at_input'] ?? '' );
        $expires_at     = (string) ( $state['expires_at_input'] ?? '' );
        $schedule_label = (string) ( $state['schedule_label'] ?? __( 'Active', 'kitgenix-document-manager' ) );

        $html = '<div class="kitgenix-field">'
            . '<label for="' . esc_attr( $prefix . 'available_from' ) . '">' . esc_html__( 'Available from', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="datetime-local" id="' . esc_attr( $prefix . 'available_from' ) . '" name="available_from" value="' . esc_attr( $available_from ) . '" class="regular-text" />'
            . '<div class="kitgenix-muted">' . esc_html__( 'Leave empty to make the document available immediately.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        $html .= '<div class="kitgenix-field">'
            . '<label for="' . esc_attr( $prefix . 'archive_at' ) . '">' . esc_html__( 'Archive at', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="datetime-local" id="' . esc_attr( $prefix . 'archive_at' ) . '" name="archive_at" value="' . esc_attr( $archive_at ) . '" class="regular-text" />'
            . '<div class="kitgenix-muted">' . esc_html__( 'Archived documents stay in WordPress but stop appearing on the frontend.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        $html .= '<div class="kitgenix-field">'
            . '<label for="' . esc_attr( $prefix . 'expires_at' ) . '">' . esc_html__( 'Expire at', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="datetime-local" id="' . esc_attr( $prefix . 'expires_at' ) . '" name="expires_at" value="' . esc_attr( $expires_at ) . '" class="regular-text" />'
            . '<div class="kitgenix-muted">' . esc_html__( 'Expired documents return a gone response and stop serving through the stable link.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        $html .= '<div class="kitgenix-field">'
            . '<div class="kitgenix-muted">' . esc_html__( 'Current schedule status:', 'kitgenix-document-manager' ) . ' <span id="' . esc_attr( $prefix . 'schedule_status_label' ) . '">' . esc_html( $schedule_label ) . '</span></div>'
            . '</div>';

        return $html;
    }

    private static function get_document_analytics_state( int $post_id = 0 ): array {
        $state = [
            'views'             => 0,
            'downloads'         => 0,
            'recent_views'      => 0,
            'recent_downloads'  => 0,
            'signed_views'      => 0,
            'signed_downloads'  => 0,
            'last_access_label' => __( 'No activity yet', 'kitgenix-document-manager' ),
        ];

        if ( $post_id <= 0 ) {
            return $state;
        }

        $analytics = kitgenix_document_manager_get_document_analytics( $post_id );
        $recent    = kitgenix_document_manager_sum_document_analytics( $analytics, 30 );

        $state['views']            = absint( $analytics['views'] ?? 0 );
        $state['downloads']        = absint( $analytics['downloads'] ?? 0 );
        $state['recent_views']     = absint( $recent['views'] ?? 0 );
        $state['recent_downloads'] = absint( $recent['downloads'] ?? 0 );
        $state['signed_views']     = absint( $analytics['signed_views'] ?? 0 );
        $state['signed_downloads'] = absint( $analytics['signed_downloads'] ?? 0 );

        $last_access_gmt = (string) ( $analytics['last_access_gmt'] ?? '' );
        if ( $last_access_gmt !== '' ) {
            $format = trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );
            $state['last_access_label'] = get_date_from_gmt( $last_access_gmt, $format ?: 'Y-m-d H:i' );
        }

        return $state;
    }

    private static function render_document_analytics_fields( array $state, string $prefix ): string {
        return '<div class="kitgenix-field">'
            . '<label>' . esc_html__( 'Analytics', 'kitgenix-document-manager' ) . '</label>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Total views:', 'kitgenix-document-manager' ) . ' <span id="' . esc_attr( $prefix . 'analytics_views' ) . '">' . esc_html( number_format_i18n( absint( $state['views'] ?? 0 ) ) ) . '</span></div>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Total downloads:', 'kitgenix-document-manager' ) . ' <span id="' . esc_attr( $prefix . 'analytics_downloads' ) . '">' . esc_html( number_format_i18n( absint( $state['downloads'] ?? 0 ) ) ) . '</span></div>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Last 30 days:', 'kitgenix-document-manager' ) . ' <span id="' . esc_attr( $prefix . 'analytics_recent_views' ) . '">' . esc_html( number_format_i18n( absint( $state['recent_views'] ?? 0 ) ) ) . '</span> ' . esc_html__( 'views', 'kitgenix-document-manager' ) . ' / <span id="' . esc_attr( $prefix . 'analytics_recent_downloads' ) . '">' . esc_html( number_format_i18n( absint( $state['recent_downloads'] ?? 0 ) ) ) . '</span> ' . esc_html__( 'downloads', 'kitgenix-document-manager' ) . '</div>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Signed-share access:', 'kitgenix-document-manager' ) . ' <span id="' . esc_attr( $prefix . 'analytics_signed_views' ) . '">' . esc_html( number_format_i18n( absint( $state['signed_views'] ?? 0 ) ) ) . '</span> ' . esc_html__( 'views', 'kitgenix-document-manager' ) . ' / <span id="' . esc_attr( $prefix . 'analytics_signed_downloads' ) . '">' . esc_html( number_format_i18n( absint( $state['signed_downloads'] ?? 0 ) ) ) . '</span> ' . esc_html__( 'downloads', 'kitgenix-document-manager' ) . '</div>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Last access:', 'kitgenix-document-manager' ) . ' <span id="' . esc_attr( $prefix . 'analytics_last_access' ) . '">' . esc_html( (string) ( $state['last_access_label'] ?? __( 'No activity yet', 'kitgenix-document-manager' ) ) ) . '</span></div>'
            . '</div>';
    }

    private static function render_documents_tab( array $settings, $edit_post ): void {
        $is_edit = ( $edit_post instanceof \WP_Post );
        $edit_id = $is_edit ? (int) $edit_post->ID : 0;

        $title = $is_edit ? (string) $edit_post->post_title : '';
        $slug  = $is_edit ? (string) $edit_post->post_name : '';

        $vis = $is_edit ? kitgenix_document_manager_get_document_visibility( $edit_id ) : kitgenix_document_manager_normalize_visibility( (string) ( $settings['default_visibility'] ?? 'public' ) );
        $versioning_enabled = $is_edit ? kitgenix_document_manager_is_versioning_enabled( $edit_id ) : ( ! empty( $settings['keep_version_history'] ) );
        $access_state = self::get_document_access_state( $edit_id );
        $analytics_state = self::get_document_analytics_state( $edit_id );

        $description = '';
        if ( $is_edit ) {
            $description = (string) get_post_meta( $edit_id, '_kitgenix_document_manager_description', true );
        }

        $current_cat_id = 0;
        if ( $is_edit ) {
            $term_ids = wp_get_object_terms( $edit_id, CPT::TAXONOMY_CATEGORY, [ 'fields' => 'ids' ] );
            if ( is_array( $term_ids ) && ! empty( $term_ids[0] ) ) {
                $current_cat_id = (int) $term_ids[0];
            }
        }

        echo '<div class="kitgenix-grid kitgenix-grid--2">';
        echo '<div class="kitgenix-documents-sidebar">';

        // Add/edit form.
        echo '<div class="kitgenix-card">'
            . '<h3>' . ( $is_edit ? esc_html__( 'Edit Document', 'kitgenix-document-manager' ) : esc_html__( 'Add Document', 'kitgenix-document-manager' ) ) . '</h3>'
            . '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
            . '<input type="hidden" name="action" value="kitgenix_document_manager_document_save" />'
            . '<input type="hidden" name="post_id" value="' . esc_attr( (string) $edit_id ) . '" />';

        wp_nonce_field( 'kitgenix_document_manager_document_save', 'kitgenix_document_manager_document_nonce' );

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_title">' . esc_html__( 'Name', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="text" id="kitgenix_dm_title" name="title" value="' . esc_attr( $title ) . '" class="regular-text" required />'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_slug">' . esc_html__( 'Slug', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="text" id="kitgenix_dm_slug" name="slug" value="' . esc_attr( $slug ) . '" class="regular-text" placeholder="' . esc_attr__( 'Auto-generated from name', 'kitgenix-document-manager' ) . '" />'
            . '<div class="kitgenix-muted">' . esc_html__( 'Leave blank to auto-generate. You can change it later.', 'kitgenix-document-manager' ) . '<br />'
            . esc_html( home_url( '/kitgenix-document-manager/' ) ) . '<span class="kitgenix-code">{slug}</span></div>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_description">' . esc_html__( 'Description (optional)', 'kitgenix-document-manager' ) . '</label>'
            . '<textarea id="kitgenix_dm_description" name="description" rows="3" class="large-text">' . esc_textarea( $description ) . '</textarea>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Shown on the frontend document card when enabled in the Frontend tab.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_visibility">' . esc_html__( 'Visibility', 'kitgenix-document-manager' ) . '</label>'
            . '<select id="kitgenix_dm_visibility" name="visibility">'
            . '<option value="public"' . selected( $vis, 'public', false ) . '>' . esc_html__( 'Public', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="private"' . selected( $vis, 'private', false ) . '>' . esc_html__( 'Private', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="restricted"' . selected( $vis, 'restricted', false ) . '>' . esc_html__( 'Restricted', 'kitgenix-document-manager' ) . '</option>'
            . '</select>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Public is open, Private requires login, and Restricted applies the grant rules below.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        echo self::render_document_access_fields( $access_state, 'kitgenix_dm_' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped HTML form fields.
        echo self::render_document_share_fields( $access_state, 'kitgenix_dm_' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped HTML form fields.
        echo self::render_document_schedule_fields( $access_state, 'kitgenix_dm_' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped HTML form fields.

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_category">' . esc_html__( 'Category', 'kitgenix-document-manager' ) . '</label>';

        wp_dropdown_categories(
            [
                'taxonomy'         => CPT::TAXONOMY_CATEGORY,
                'hide_empty'       => false,
                'show_option_none' => __( '— No category —', 'kitgenix-document-manager' ),
                'option_none_value'=> '0',
                'name'             => 'category_id',
                'id'               => 'kitgenix_dm_category',
                'selected'         => $current_cat_id,
                'class'            => '',
            ]
        );

        echo '<div class="kitgenix-muted">' . esc_html__( 'Use categories to group documents (e.g. Policies, Guides, Forms).', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label>'
            . '<input type="checkbox" name="versioning_enabled" value="1" ' . checked( $versioning_enabled, true, false ) . ' /> '
            . esc_html__( 'Keep version history', 'kitgenix-document-manager' )
            . '</label>'
            . '<div class="kitgenix-muted">' . esc_html__( 'When enabled, replacements keep previous versions in the history.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        $require_file = '';
        $require_attr = $require_file ? ' ' . esc_attr( trim( $require_file ) ) : '';
        $file_label = $is_edit ? __( 'Replace file (optional)', 'kitgenix-document-manager' ) : __( 'Upload file', 'kitgenix-document-manager' );
        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_file">' . esc_html( $file_label ) . '</label>'
            . '<input type="file" id="kitgenix_dm_file" name="kitgenix_document_manager_file"' . esc_attr( $require_attr ) . ' />'
            . '<input type="hidden" id="kitgenix_dm_selected_attachment" name="selected_attachment_id" value="0" />'
            . '<div style="margin-top:6px;">'
            . '<button type="button" class="button kitgenix-document-manager-media-select" data-kitgenix-media-target="add">' . esc_html__( 'Select from Media Library', 'kitgenix-document-manager' ) . '</button>'
            . ' <span class="kitgenix-muted" id="kitgenix-document-manager-media-selected-add"></span>'
            . '</div>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Allowed types are controlled in Settings.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        if ( $is_edit ) {
            echo self::render_document_analytics_fields( $analytics_state, 'kitgenix_dm_' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped HTML form fields.
        }

        submit_button( $is_edit ? __( 'Update Document', 'kitgenix-document-manager' ) : __( 'Add Document', 'kitgenix-document-manager' ) );

        if ( $is_edit ) {
            $cancel_url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) . '#kitgenix-document-manager-tab-documents' );
            echo '<a class="button" href="' . esc_url( $cancel_url ) . '">' . esc_html__( 'Cancel', 'kitgenix-document-manager' ) . '</a>';
        }

        echo '</form></div>';

        echo '<div class="kitgenix-card kitgenix-document-manager-bulk-upload-card">'
            . '<h3>' . esc_html__( 'Batch Upload', 'kitgenix-document-manager' ) . '</h3>'
            . '<p class="kitgenix-muted">' . esc_html__( 'Drop multiple files to create one document per file with shared visibility, category, and versioning defaults.', 'kitgenix-document-manager' ) . '</p>'
            . '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="kitgenix-document-manager-bulk-upload-form" data-kitgenix-bulk-upload-form="1">'
            . '<input type="hidden" name="action" value="kitgenix_document_manager_documents_bulk_upload" />';

        wp_nonce_field( 'kitgenix_document_manager_documents_bulk_upload', 'kitgenix_document_manager_bulk_upload_nonce' );

        echo '<div class="kitgenix-document-manager-dropzone" data-kitgenix-bulk-dropzone="1" tabindex="0" role="button" aria-controls="kitgenix_dm_bulk_files">'
            . '<strong>' . esc_html__( 'Drop files here', 'kitgenix-document-manager' ) . '</strong>'
            . '<span class="kitgenix-muted">' . esc_html__( 'or click to browse for multiple files at once.', 'kitgenix-document-manager' ) . '</span>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_bulk_files">' . esc_html__( 'Files', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="file" id="kitgenix_dm_bulk_files" name="kitgenix_document_manager_bulk_files[]" multiple data-kitgenix-bulk-files-input="1" />'
            . '<div class="kitgenix-muted">' . esc_html__( 'Each uploaded file becomes a new document and uses its filename as the starting document name.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        echo '<div class="kitgenix-document-manager-selected-files" id="kitgenix-document-manager-selected-files" data-kitgenix-bulk-files-list="1"></div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_bulk_visibility">' . esc_html__( 'Default visibility', 'kitgenix-document-manager' ) . '</label>'
            . '<select id="kitgenix_dm_bulk_visibility" name="bulk_upload_visibility">'
            . '<option value="public"' . selected( (string) ( $settings['default_visibility'] ?? 'public' ), 'public', false ) . '>' . esc_html__( 'Public', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="private"' . selected( (string) ( $settings['default_visibility'] ?? 'public' ), 'private', false ) . '>' . esc_html__( 'Private', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="restricted"' . selected( (string) ( $settings['default_visibility'] ?? 'public' ), 'restricted', false ) . '>' . esc_html__( 'Restricted', 'kitgenix-document-manager' ) . '</option>'
            . '</select>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_bulk_category">' . esc_html__( 'Default category', 'kitgenix-document-manager' ) . '</label>';

        wp_dropdown_categories(
            [
                'taxonomy'          => CPT::TAXONOMY_CATEGORY,
                'hide_empty'        => false,
                'show_option_none'  => __( '— No category —', 'kitgenix-document-manager' ),
                'option_none_value' => '0',
                'name'              => 'bulk_upload_category_id',
                'id'                => 'kitgenix_dm_bulk_category',
                'selected'          => 0,
            ]
        );

        echo '</div>';

        echo '<div class="kitgenix-field">'
            . '<label>'
            . '<input type="checkbox" name="bulk_upload_versioning_enabled" value="1" ' . checked( ! empty( $settings['keep_version_history'] ), true, false ) . ' /> '
            . esc_html__( 'Keep version history for uploaded documents', 'kitgenix-document-manager' )
            . '</label>'
            . '</div>';

        submit_button( __( 'Create Documents from Files', 'kitgenix-document-manager' ) );

        echo '</form></div>';
        echo '</div>';

        // Table.
        echo '<div class="kitgenix-card">'
            . '<div class="kitgenix-card-header kitgenix-card-header--docs">'
            . '<div class="kitgenix-card-header__title">'
            . '<h3 class="kitgenix-card-title">' . esc_html__( 'All Documents', 'kitgenix-document-manager' ) . '</h3>'
            . '<p class="kitgenix-card-subtitle">' . esc_html__( 'Search, copy stable links, and replace files.', 'kitgenix-document-manager' ) . '</p>'
            . '</div>'
            . '<div class="kitgenix-links-toolbar" role="search">'
            . '<label class="screen-reader-text" for="kitgenix-document-manager-docs-search">' . esc_html__( 'Search documents', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="search" id="kitgenix-document-manager-docs-search" class="regular-text" placeholder="' . esc_attr__( 'Search documents…', 'kitgenix-document-manager' ) . '" autocomplete="off" />'
            . '<button type="button" class="button" id="kitgenix-document-manager-docs-clear" style="display:none;">' . esc_html__( 'Clear', 'kitgenix-document-manager' ) . '</button>'
            . '<span class="kitgenix-muted kitgenix-links-count" id="kitgenix-document-manager-docs-count" aria-live="polite"></span>'
            . '</div>'
            . '</div>';

        $docs = get_posts(
            [
                'post_type'        => CPT::POST_TYPE,
                'post_status'      => 'publish',
                'numberposts'      => 200,
                'orderby'          => 'date',
                'order'            => 'DESC',
                'no_found_rows'    => true,
                'cache_results'    => true,
                'suppress_filters' => false,
            ]
        );

        if ( empty( $docs ) ) {
            echo '<p class="kitgenix-muted">' . esc_html__( 'No documents yet. Add your first one on the left.', 'kitgenix-document-manager' ) . '</p>';
            echo '</div></div>';
            return;
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="kitgenix-document-manager-bulk-actions-form" data-kitgenix-bulk-actions-form="1">'
            . '<input type="hidden" name="action" value="kitgenix_document_manager_documents_bulk_action" />';

        wp_nonce_field( 'kitgenix_document_manager_documents_bulk_action', 'kitgenix_document_manager_bulk_action_nonce' );

        echo '<div class="kitgenix-document-manager-bulk-toolbar">'
            . '<div class="kitgenix-document-manager-bulk-toolbar__controls">'
            . '<label class="screen-reader-text" for="kitgenix-document-manager-bulk-action">' . esc_html__( 'Bulk action', 'kitgenix-document-manager' ) . '</label>'
            . '<select id="kitgenix-document-manager-bulk-action" name="bulk_action" data-kitgenix-bulk-action-select="1">'
            . '<option value="">' . esc_html__( 'Bulk actions', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="visibility_public">' . esc_html__( 'Set visibility to Public', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="visibility_private">' . esc_html__( 'Set visibility to Private', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="visibility_restricted">' . esc_html__( 'Set visibility to Restricted', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="assign_category">' . esc_html__( 'Assign category', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="clear_category">' . esc_html__( 'Clear category', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="delete">' . esc_html__( 'Move to Trash', 'kitgenix-document-manager' ) . '</option>'
            . '</select>';

        wp_dropdown_categories(
            [
                'taxonomy'          => CPT::TAXONOMY_CATEGORY,
                'hide_empty'        => false,
                'show_option_none'  => __( 'Choose category', 'kitgenix-document-manager' ),
                'option_none_value' => '0',
                'name'              => 'bulk_category_id',
                'id'                => 'kitgenix-document-manager-bulk-category',
                'selected'          => 0,
            ]
        );

        echo '<button type="submit" class="button" data-kitgenix-bulk-submit="1" disabled="disabled">' . esc_html__( 'Apply', 'kitgenix-document-manager' ) . '</button>'
            . '</div>'
            . '<div class="kitgenix-document-manager-bulk-toolbar__status">'
            . '<span class="kitgenix-muted" id="kitgenix-document-manager-selection-count">' . esc_html__( 'No documents selected.', 'kitgenix-document-manager' ) . '</span>'
            . '</div>'
            . '</div>';

        echo '<div class="kitgenix-table-wrap">'
            . '<table class="kitgenix-table kitgenix-links-table kitgenix-document-manager-table" id="kitgenix-document-manager-docs-table">'
            . '<thead><tr>'
            . '<th class="kitgenix-col-select"><label class="screen-reader-text" for="kitgenix-document-manager-select-all">' . esc_html__( 'Select all documents', 'kitgenix-document-manager' ) . '</label><input type="checkbox" id="kitgenix-document-manager-select-all" data-kitgenix-select-all="1" /></th>'
            . '<th class="kitgenix-col-name">' . esc_html__( 'Name', 'kitgenix-document-manager' ) . '</th>'
            . '<th class="kitgenix-col-category">' . esc_html__( 'Category', 'kitgenix-document-manager' ) . '</th>'
            . '<th class="kitgenix-col-visibility">' . esc_html__( 'Visibility', 'kitgenix-document-manager' ) . '</th>'
            . '<th class="kitgenix-col-actions">' . esc_html__( 'Actions', 'kitgenix-document-manager' ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( $docs as $doc ) {
            if ( ! ( $doc instanceof \WP_Post ) ) {
                continue;
            }

            $row_title = (string) $doc->post_title;
            $row_slug  = (string) $doc->post_name;
            $row_desc  = (string) get_post_meta( (int) $doc->ID, '_kitgenix_document_manager_description', true );
            $stable    = $row_slug ? kitgenix_document_manager_stable_url( $row_slug ) : '';
            $visibility = kitgenix_document_manager_get_document_visibility( (int) $doc->ID );
            $row_access_state = self::get_document_access_state( (int) $doc->ID );
            $row_analytics_state = self::get_document_analytics_state( (int) $doc->ID );
            $current_id = (int) get_post_meta( (int) $doc->ID, '_kitgenix_document_manager_current_attachment_id', true );

            $cat_name = '—';
            $cat_id = 0;
            $term_ids = wp_get_object_terms( (int) $doc->ID, CPT::TAXONOMY_CATEGORY, [ 'fields' => 'ids' ] );
            if ( is_array( $term_ids ) && ! empty( $term_ids[0] ) ) {
                $cat_id = (int) $term_ids[0];
                $t = get_term( $cat_id, CPT::TAXONOMY_CATEGORY );
                if ( $t && ! is_wp_error( $t ) && isset( $t->name ) ) {
                    $cat_name = (string) $t->name;
                }
            }

            $file_label2 = '—';
            $file_size_display = '—';
            $file_replaced_display = '—';
            if ( $current_id > 0 ) {
                $path = get_attached_file( $current_id );
                $path = is_string( $path ) ? $path : '';
                $file_label2 = $path ? wp_basename( $path ) : (string) $current_id;

                if ( $path && file_exists( $path ) ) {
                    $bytes = @filesize( $path );
                    if ( is_int( $bytes ) && $bytes > 0 ) {
                        $file_size_display = size_format( $bytes );
                    }
                }

                $ts = (int) get_post_modified_time( 'U', true, $current_id );
                if ( $ts > 0 ) {
                    $fmt = trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );
                    $file_replaced_display = date_i18n( $fmt, $ts );
                }
            }

            $row_versioning_enabled = kitgenix_document_manager_is_versioning_enabled( (int) $doc->ID );
            $versions_payload = [];
            if ( $row_versioning_enabled ) {
                $versions = kitgenix_document_manager_get_versions( (int) $doc->ID );
                foreach ( $versions as $vid ) {
                    $vid = (int) $vid;
                    if ( $vid <= 0 ) {
                        continue;
                    }

                    $att = get_post( $vid );
                    if ( ! ( $att instanceof \WP_Post ) || $att->post_type !== 'attachment' ) {
                        continue;
                    }

                    $v_path = get_attached_file( $vid );
                    $v_path = is_string( $v_path ) ? $v_path : '';
                    $v_name = $v_path ? wp_basename( $v_path ) : (string) $vid;
                    $v_size = '';
                    if ( $v_path && file_exists( $v_path ) ) {
                        $v_bytes = @filesize( $v_path );
                        if ( is_int( $v_bytes ) && $v_bytes > 0 ) {
                            $v_size = size_format( $v_bytes );
                        }
                    }

                    $v_ts = (int) get_post_modified_time( 'U', true, $vid );
                    $v_replaced = '';
                    if ( $v_ts > 0 ) {
                        $fmt = trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );
                        $v_replaced = date_i18n( $fmt, $v_ts );
                    }

                    $is_current = ( $current_id > 0 && $vid === $current_id );

                    $restore_url = '';
                    $delete_version_url = '';
                    if ( ! $is_current ) {
                        $restore_url = admin_url( 'admin-post.php?action=kitgenix_document_manager_version_restore&document_id=' . (int) $doc->ID . '&attachment_id=' . $vid );
                        $restore_url = wp_nonce_url( $restore_url, 'kitgenix_document_manager_version_restore', 'nonce' );

                        $delete_version_url = admin_url( 'admin-post.php?action=kitgenix_document_manager_version_delete&document_id=' . (int) $doc->ID . '&attachment_id=' . $vid );
                        $delete_version_url = wp_nonce_url( $delete_version_url, 'kitgenix_document_manager_version_delete', 'nonce' );
                    }

                    $versions_payload[] = [
                        'id'          => $vid,
                        'fileName'    => $v_name,
                        'size'        => $v_size,
                        'replacedAt'  => $v_replaced,
                        'timestamp'   => $v_ts,
                        'isCurrent'   => $is_current,
                        'restoreUrl'  => $restore_url,
                        'deleteUrl'   => $delete_version_url,
                    ];
                }
            }

            $edit_url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) . '&kitgenix_action=edit&document_id=' . (int) $doc->ID . '#kitgenix-document-manager-tab-documents' );

            $delete_url = admin_url( 'admin-post.php?action=kitgenix_document_manager_document_delete&document_id=' . (int) $doc->ID );
            $delete_url = wp_nonce_url( $delete_url, 'kitgenix_document_manager_document_delete', 'nonce' );

            $delete_all_url = admin_url( 'admin-post.php?action=kitgenix_document_manager_versions_delete_all&document_id=' . (int) $doc->ID );
            $delete_all_url = wp_nonce_url( $delete_all_url, 'kitgenix_document_manager_versions_delete_all', 'nonce' );

            $shortcode = $row_slug ? '[kitgenix_document_manager_document slug="' . $row_slug . '"]' : '';
            $visibility_label = self::format_document_visibility_label( $visibility );
            if ( ( $row_access_state['schedule_status'] ?? 'active' ) !== 'active' ) {
                $visibility_label .= ' · ' . (string) ( $row_access_state['schedule_label'] ?? '' );
            }

            $search_blob = strtolower( $row_title . ' ' . $cat_name . ' ' . $row_slug . ' ' . $stable . ' ' . $file_label2 . ' ' . $file_size_display . ' ' . $file_replaced_display . ' ' . $visibility . ' ' . $visibility_label . ' ' . implode( ' ', $row_access_state['roles'] ?? [] ) . ' ' . (string) ( $row_access_state['capability'] ?? '' ) );

            echo '<tr data-kitgenix-search="' . esc_attr( $search_blob ) . '">'
                . '<td class="kitgenix-col-select"><input type="checkbox" name="document_ids[]" value="' . esc_attr( (string) (int) $doc->ID ) . '" data-kitgenix-document-checkbox="1" /></td>'
                . '<td class="kitgenix-col-name"><strong>' . esc_html( $row_title ) . '</strong></td>'
                . '<td class="kitgenix-col-category">' . esc_html( $cat_name ) . '</td>'
                . '<td class="kitgenix-col-visibility">' . esc_html( $visibility_label ) . '</td>'
                . '<td class="kitgenix-actions kitgenix-col-actions">'
                . ( $stable ? '<a class="kitgenix-document-manager-open" href="' . esc_url( $stable ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open link', 'kitgenix-document-manager' ) . '</a>' : '' )
                . ( $stable ? '<a href="#" data-default-label="' . esc_attr__( 'Copy link', 'kitgenix-document-manager' ) . '" data-copied-label="' . esc_attr__( 'Copied', 'kitgenix-document-manager' ) . '" data-kitgenix-copy="' . esc_attr( $stable ) . '">' . esc_html__( 'Copy link', 'kitgenix-document-manager' ) . '</a>' : '' )
                . ( ! empty( $row_access_state['share_url'] ) ? '<a href="#" data-default-label="' . esc_attr__( 'Copy share link', 'kitgenix-document-manager' ) . '" data-copied-label="' . esc_attr__( 'Copied', 'kitgenix-document-manager' ) . '" data-kitgenix-copy="' . esc_attr( (string) $row_access_state['share_url'] ) . '">' . esc_html__( 'Copy share link', 'kitgenix-document-manager' ) . '</a>' : '' )
                . ( $shortcode ? '<a href="#" data-default-label="' . esc_attr__( 'Copy shortcode', 'kitgenix-document-manager' ) . '" data-copied-label="' . esc_attr__( 'Copied', 'kitgenix-document-manager' ) . '" data-kitgenix-copy="' . esc_attr( $shortcode ) . '">' . esc_html__( 'Copy shortcode', 'kitgenix-document-manager' ) . '</a>' : '' )
                . '<a class="kitgenix-document-manager-edit" href="' . esc_url( $edit_url ) . '"'
                . ' data-kitgenix-edit="1"'
                . ' data-document-id="' . esc_attr( (string) (int) $doc->ID ) . '"'
                . ' data-title="' . esc_attr( $row_title ) . '"'
                . ' data-slug="' . esc_attr( $row_slug ) . '"'
                . ' data-description="' . esc_attr( $row_desc ) . '"'
                . ' data-visibility="' . esc_attr( $visibility ) . '"'
                . ' data-access-roles="' . esc_attr( implode( ',', $row_access_state['roles'] ?? [] ) ) . '"'
                . ' data-access-capability="' . esc_attr( (string) ( $row_access_state['capability'] ?? '' ) ) . '"'
                . ' data-access-user-ids="' . esc_attr( (string) ( $row_access_state['user_ids'] ?? '' ) ) . '"'
                . ' data-share-expires="' . esc_attr( (string) ( $row_access_state['share_expires_input'] ?? '' ) ) . '"'
                . ' data-share-url="' . esc_attr( (string) ( $row_access_state['share_url'] ?? '' ) ) . '"'
                . ' data-available-from="' . esc_attr( (string) ( $row_access_state['available_from_input'] ?? '' ) ) . '"'
                . ' data-archive-at="' . esc_attr( (string) ( $row_access_state['archive_at_input'] ?? '' ) ) . '"'
                . ' data-expires-at="' . esc_attr( (string) ( $row_access_state['expires_at_input'] ?? '' ) ) . '"'
                . ' data-schedule-label="' . esc_attr( (string) ( $row_access_state['schedule_label'] ?? '' ) ) . '"'
                . ' data-analytics-views="' . esc_attr( (string) ( $row_analytics_state['views'] ?? 0 ) ) . '"'
                . ' data-analytics-downloads="' . esc_attr( (string) ( $row_analytics_state['downloads'] ?? 0 ) ) . '"'
                . ' data-analytics-recent-views="' . esc_attr( (string) ( $row_analytics_state['recent_views'] ?? 0 ) ) . '"'
                . ' data-analytics-recent-downloads="' . esc_attr( (string) ( $row_analytics_state['recent_downloads'] ?? 0 ) ) . '"'
                . ' data-analytics-signed-views="' . esc_attr( (string) ( $row_analytics_state['signed_views'] ?? 0 ) ) . '"'
                . ' data-analytics-signed-downloads="' . esc_attr( (string) ( $row_analytics_state['signed_downloads'] ?? 0 ) ) . '"'
                . ' data-analytics-last-access="' . esc_attr( (string) ( $row_analytics_state['last_access_label'] ?? '' ) ) . '"'
                . ' data-versioning="' . esc_attr( $row_versioning_enabled ? '1' : '0' ) . '"'
                . ' data-category-id="' . esc_attr( (string) $cat_id ) . '"'
                . ( $row_versioning_enabled ? ' data-versions="' . esc_attr( wp_json_encode( $versions_payload ) ) . '"' : '' )
                . ' data-delete-url="' . esc_attr( $delete_url ) . '"'
                . ' data-delete-all-url="' . esc_attr( $delete_all_url ) . '"'
                . '>' . esc_html__( 'Edit', 'kitgenix-document-manager' ) . '</a>'
                . '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Move this document to the Trash?', 'kitgenix-document-manager' ) ) . '\');">' . esc_html__( 'Delete', 'kitgenix-document-manager' ) . '</a>'
                . '</td>'
                . '</tr>';
        }

        echo '</tbody></table></div>';
        echo '</form>';

        echo '<p class="kitgenix-muted kitgenix-links-no-results" id="kitgenix-document-manager-docs-no-results" hidden>'
            . esc_html__( 'No documents match your search.', 'kitgenix-document-manager' )
            . '</p>';

        $modal_access_state = self::get_document_access_state( 0 );
        $modal_analytics_state = self::get_document_analytics_state( 0 );

        // Modal editor.
        echo '<div class="kitgenix-modal" id="kitgenix-document-manager-edit-modal" aria-hidden="true" role="dialog" aria-modal="true">'
            . '<div class="kitgenix-modal__backdrop" data-kitgenix-modal-close="1"></div>'
            . '<div class="kitgenix-modal__dialog" role="document">'
            . '<div class="kitgenix-modal__header">'
            . '<h2 class="kitgenix-modal__title">' . esc_html__( 'Edit Document', 'kitgenix-document-manager' ) . '</h2>'
            . '<button type="button" class="button kitgenix-modal__close" data-kitgenix-modal-close="1">' . esc_html__( 'Close', 'kitgenix-document-manager' ) . '</button>'
            . '</div>'
            . '<div class="kitgenix-modal__body">'
            . '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="kitgenix-document-manager-edit-form">'
            . '<input type="hidden" name="action" value="kitgenix_document_manager_document_save" />'
            . '<input type="hidden" name="post_id" value="" />';

        wp_nonce_field( 'kitgenix_document_manager_document_save', 'kitgenix_document_manager_document_nonce' );

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_modal_title">' . esc_html__( 'Name', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="text" id="kitgenix_dm_modal_title" name="title" value="" class="regular-text" required />'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_modal_slug">' . esc_html__( 'Slug', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="text" id="kitgenix_dm_modal_slug" name="slug" value="" class="regular-text" placeholder="' . esc_attr__( 'Auto-generated from name', 'kitgenix-document-manager' ) . '" />'
            . '<div class="kitgenix-muted">' . esc_html__( 'Leave blank to auto-generate. You can change it later.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_modal_description">' . esc_html__( 'Description (optional)', 'kitgenix-document-manager' ) . '</label>'
            . '<textarea id="kitgenix_dm_modal_description" name="description" rows="3" class="large-text"></textarea>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Shown on the frontend document card when enabled in the Frontend tab.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_modal_visibility">' . esc_html__( 'Visibility', 'kitgenix-document-manager' ) . '</label>'
            . '<select id="kitgenix_dm_modal_visibility" name="visibility">'
            . '<option value="public">' . esc_html__( 'Public', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="private">' . esc_html__( 'Private', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="restricted">' . esc_html__( 'Restricted', 'kitgenix-document-manager' ) . '</option>'
            . '</select>'
            . '</div>';

        echo self::render_document_access_fields( $modal_access_state, 'kitgenix_dm_modal_' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped HTML form fields.
        echo self::render_document_share_fields( $modal_access_state, 'kitgenix_dm_modal_' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped HTML form fields.
        echo self::render_document_schedule_fields( $modal_access_state, 'kitgenix_dm_modal_' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped HTML form fields.

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_modal_category">' . esc_html__( 'Category', 'kitgenix-document-manager' ) . '</label>';

        wp_dropdown_categories(
            [
                'taxonomy'         => CPT::TAXONOMY_CATEGORY,
                'hide_empty'       => false,
                'show_option_none' => __( '— No category —', 'kitgenix-document-manager' ),
                'option_none_value'=> '0',
                'name'             => 'category_id',
                'id'               => 'kitgenix_dm_modal_category',
                'selected'         => 0,
                'class'            => '',
            ]
        );

        echo '</div>';

        echo '<div class="kitgenix-field">'
            . '<label>'
            . '<input type="checkbox" name="versioning_enabled" value="1" /> '
            . esc_html__( 'Keep version history', 'kitgenix-document-manager' )
            . '</label>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_modal_file">' . esc_html__( 'Replace file (optional)', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="file" id="kitgenix_dm_modal_file" name="kitgenix_document_manager_file" />'
            . '<input type="hidden" id="kitgenix_dm_modal_selected_attachment" name="selected_attachment_id" value="0" />'
            . '<div style="margin-top:6px;">'
            . '<button type="button" class="button kitgenix-document-manager-media-select" data-kitgenix-media-target="modal">' . esc_html__( 'Select from Media Library', 'kitgenix-document-manager' ) . '</button>'
            . ' <span class="kitgenix-muted" id="kitgenix-document-manager-media-selected-modal"></span>'
            . '</div>'
            . '</div>';

        echo '<div class="kitgenix-field" id="kitgenix-document-manager-versions-panel" hidden>'
            . '<label>' . esc_html__( 'Versions', 'kitgenix-document-manager' ) . '</label>'
            . '<div class="kitgenix-muted" id="kitgenix-document-manager-versions-help">' . esc_html__( 'When version history is enabled, previous files are listed here. You can restore or delete older versions.', 'kitgenix-document-manager' ) . '</div>'
            . '<div style="margin-top:6px;">'
            . '<a href="#" class="button button-small button-link-delete" id="kitgenix-document-manager-delete-all-versions" style="display:none;">' . esc_html__( 'Delete all old versions', 'kitgenix-document-manager' ) . '</a>'
            . '</div>'
            . '<div id="kitgenix-document-manager-versions-list"></div>'
            . '</div>';

        echo self::render_document_analytics_fields( $modal_analytics_state, 'kitgenix_dm_modal_' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped HTML form fields.

        echo '<div class="kitgenix-modal__actions">';
        echo '<a href="#" class="button kitgenix-modal__cancel" data-kitgenix-modal-close="1">' . esc_html__( 'Cancel', 'kitgenix-document-manager' ) . '</a>';
        echo ' <a href="#" class="button button-link-delete kitgenix-modal__delete" id="kitgenix-document-manager-modal-delete">' . esc_html__( 'Delete', 'kitgenix-document-manager' ) . '</a>';
        echo ' ';
        submit_button( __( 'Save Changes', 'kitgenix-document-manager' ), 'primary', 'submit', false );
        echo '</div>';

        echo '</form>'
            . '</div>'
            . '</div>'
            . '</div>';

        echo '</div></div>';
    }

    private static function render_settings_tab( array $settings ): void {
        $opt_name = defined( 'KITGENIX_DOCUMENT_MANAGER_OPTION_NAME' ) ? (string) KITGENIX_DOCUMENT_MANAGER_OPTION_NAME : 'kitgenix_document_manager_settings';

        $allowed = isset( $settings['allowed_extensions'] ) && is_array( $settings['allowed_extensions'] ) ? implode( ', ', $settings['allowed_extensions'] ) : '';
        $vis = (string) ( $settings['default_visibility'] ?? 'public' );
        $mode = (string) ( $settings['serving_mode'] ?? 'attachment' );
        $pb = (string) ( $settings['private_behavior'] ?? 'login' );

        echo '<div class="kitgenix-card">';
        echo '<form method="post" action="options.php">';

        settings_fields( 'kitgenix_document_manager_settings_group' );

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_allowed">' . esc_html__( 'Allowed file extensions', 'kitgenix-document-manager' ) . '</label>'
            . '<input name="' . esc_attr( $opt_name ) . '[allowed_extensions]" id="kitgenix_dm_allowed" type="text" class="regular-text" value="' . esc_attr( $allowed ) . '" />'
            . '<div class="kitgenix-muted">' . esc_html__( 'Comma-separated list (e.g. pdf, docx, xlsx).', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_default_visibility">' . esc_html__( 'Default visibility', 'kitgenix-document-manager' ) . '</label>'
            . '<select id="kitgenix_dm_default_visibility" name="' . esc_attr( $opt_name ) . '[default_visibility]">'
            . '<option value="public" ' . selected( $vis, 'public', false ) . '>' . esc_html__( 'Public', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="private" ' . selected( $vis, 'private', false ) . '>' . esc_html__( 'Private', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="restricted" ' . selected( $vis, 'restricted', false ) . '>' . esc_html__( 'Restricted', 'kitgenix-document-manager' ) . '</option>'
            . '</select>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_serving_mode">' . esc_html__( 'Serving mode', 'kitgenix-document-manager' ) . '</label>'
            . '<select id="kitgenix_dm_serving_mode" name="' . esc_attr( $opt_name ) . '[serving_mode]">'
            . '<option value="attachment" ' . selected( $mode, 'attachment', false ) . '>' . esc_html__( 'Download (attachment)', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="inline" ' . selected( $mode, 'inline', false ) . '>' . esc_html__( 'Inline (browser)', 'kitgenix-document-manager' ) . '</option>'
            . '</select>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label>' . esc_html__( 'Versioning defaults', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="hidden" name="' . esc_attr( $opt_name ) . '[keep_version_history]" value="0" />'
            . '<label><input type="checkbox" name="' . esc_attr( $opt_name ) . '[keep_version_history]" value="1" ' . checked( ! empty( $settings['keep_version_history'] ), true, false ) . ' /> ' . esc_html__( 'Keep version history by default', 'kitgenix-document-manager' ) . '</label><br />'
            . '<input type="hidden" name="' . esc_attr( $opt_name ) . '[delete_old_versions]" value="0" />'
            . '<label><input type="checkbox" name="' . esc_attr( $opt_name ) . '[delete_old_versions]" value="1" ' . checked( ! empty( $settings['delete_old_versions'] ), true, false ) . ' /> ' . esc_html__( 'Delete old versions when history is disabled', 'kitgenix-document-manager' ) . '</label>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_private_behavior">' . esc_html__( 'Private document behavior', 'kitgenix-document-manager' ) . '</label>'
            . '<select id="kitgenix_dm_private_behavior" name="' . esc_attr( $opt_name ) . '[private_behavior]">'
            . '<option value="login" ' . selected( $pb, 'login', false ) . '>' . esc_html__( 'Redirect to login', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="403" ' . selected( $pb, '403', false ) . '>' . esc_html__( 'Return 403 Forbidden', 'kitgenix-document-manager' ) . '</option>'
            . '</select>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label>' . esc_html__( 'Uninstall', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="hidden" name="' . esc_attr( $opt_name ) . '[delete_data_on_uninstall]" value="0" />'
            . '<label><input type="checkbox" name="' . esc_attr( $opt_name ) . '[delete_data_on_uninstall]" value="1" ' . checked( ! empty( $settings['delete_data_on_uninstall'] ), true, false ) . ' /> ' . esc_html__( 'Delete documents and attachments on uninstall', 'kitgenix-document-manager' ) . '</label>'
            . '</div>';

        submit_button( __( 'Save Settings', 'kitgenix-document-manager' ) );

        echo '</form>';
        echo '</div>';
    }

    private static function render_frontend_tab( array $settings ): void {
        $opt_name = defined( 'KITGENIX_DOCUMENT_MANAGER_OPTION_NAME' ) ? (string) KITGENIX_DOCUMENT_MANAGER_OPTION_NAME : 'kitgenix_document_manager_settings';

        $button_label = sanitize_text_field( (string) ( $settings['card_button_label'] ?? 'View' ) );
        if ( $button_label === '' ) {
            $button_label = 'View';
        }

        echo '<div class="kitgenix-card">';
        echo '<h2>' . esc_html__( 'Frontend Card', 'kitgenix-document-manager' ) . '</h2>';
        echo '<p class="kitgenix-muted">' . esc_html__( 'These settings control the default output of the document card shortcode.', 'kitgenix-document-manager' ) . '</p>';
        echo '<p class="kitgenix-muted"><span class="kitgenix-code">[kitgenix_document_manager_document slug="my-doc"]</span></p>';

        echo '<form method="post" action="options.php">';

        settings_fields( 'kitgenix_document_manager_settings_group' );

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_card_button_label">' . esc_html__( 'Button label', 'kitgenix-document-manager' ) . '</label>'
            . '<input name="' . esc_attr( $opt_name ) . '[card_button_label]" id="kitgenix_dm_card_button_label" type="text" class="regular-text" value="' . esc_attr( $button_label ) . '" />'
            . '<div class="kitgenix-muted">' . esc_html__( 'Default label for the “View” button on the card. Can be overridden per shortcode using button_label="...".', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        $button_style_source = (string) ( $settings['card_button_style_source'] ?? 'custom' );
        $button_style_source = ( $button_style_source === 'theme' ) ? 'theme' : 'custom';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_card_button_style_source">' . esc_html__( 'Button style source', 'kitgenix-document-manager' ) . '</label>'
            . '<select name="' . esc_attr( $opt_name ) . '[card_button_style_source]" id="kitgenix_dm_card_button_style_source">'
            . '<option value="custom" ' . selected( $button_style_source, 'custom', false ) . '>' . esc_html__( 'Custom button styling', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="theme" ' . selected( $button_style_source, 'theme', false ) . '>' . esc_html__( 'Sync from theme', 'kitgenix-document-manager' ) . '</option>'
            . '</select>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Use your theme button styles instead of the plugin’s custom outline/solid appearance.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        $button_style = (string) ( $settings['card_button_style'] ?? 'outline' );
        $button_style = in_array( $button_style, [ 'outline', 'solid', 'theme' ], true ) ? $button_style : 'outline';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_card_button_style">' . esc_html__( 'Button style', 'kitgenix-document-manager' ) . '</label>'
            . '<select name="' . esc_attr( $opt_name ) . '[card_button_style]" id="kitgenix_dm_card_button_style">'
            . '<option value="outline" ' . selected( $button_style, 'outline', false ) . '>' . esc_html__( 'Outline', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="solid" ' . selected( $button_style, 'solid', false ) . '>' . esc_html__( 'Solid', 'kitgenix-document-manager' ) . '</option>'
            . '</select>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Controls the default styling of the card button.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label>' . esc_html__( 'Link behavior', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="hidden" name="' . esc_attr( $opt_name ) . '[card_open_new_tab]" value="0" />'
            . '<label><input type="checkbox" name="' . esc_attr( $opt_name ) . '[card_open_new_tab]" value="1" ' . checked( ! empty( $settings['card_open_new_tab'] ), true, false ) . ' /> ' . esc_html__( 'Open “View” link in a new tab', 'kitgenix-document-manager' ) . '</label>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label>' . esc_html__( 'Card fields', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="hidden" name="' . esc_attr( $opt_name ) . '[card_show_file_type]" value="0" />'
            . '<label><input type="checkbox" name="' . esc_attr( $opt_name ) . '[card_show_file_type]" value="1" ' . checked( ! empty( $settings['card_show_file_type'] ), true, false ) . ' /> ' . esc_html__( 'Show file type', 'kitgenix-document-manager' ) . '</label><br />'
            . '<input type="hidden" name="' . esc_attr( $opt_name ) . '[card_show_file_size]" value="0" />'
            . '<label><input type="checkbox" name="' . esc_attr( $opt_name ) . '[card_show_file_size]" value="1" ' . checked( ! empty( $settings['card_show_file_size'] ), true, false ) . ' /> ' . esc_html__( 'Show file size', 'kitgenix-document-manager' ) . '</label><br />'
            . '<input type="hidden" name="' . esc_attr( $opt_name ) . '[card_show_description]" value="0" />'
            . '<label><input type="checkbox" name="' . esc_attr( $opt_name ) . '[card_show_description]" value="1" ' . checked( ! empty( $settings['card_show_description'] ), true, false ) . ' /> ' . esc_html__( 'Show description (if set on the document)', 'kitgenix-document-manager' ) . '</label>'
            . '</div>';

        $image_size = absint( $settings['card_image_size'] ?? 96 );
        if ( $image_size < 32 || $image_size > 256 ) {
            $image_size = 96;
        }

        $meta_align = sanitize_key( (string) ( $settings['card_meta_alignment'] ?? 'justify' ) );
        if ( ! in_array( $meta_align, [ 'justify', 'left', 'center', 'right' ], true ) ) {
            $meta_align = 'justify';
        }

        $button_align = sanitize_key( (string) ( $settings['card_button_alignment'] ?? 'center' ) );
        if ( ! in_array( $button_align, [ 'justify', 'left', 'center', 'right' ], true ) ) {
            $button_align = 'center';
        }

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_card_image_size">' . esc_html__( 'Preview image size (px)', 'kitgenix-document-manager' ) . '</label>'
            . '<input name="' . esc_attr( $opt_name ) . '[card_image_size]" id="kitgenix_dm_card_image_size" type="number" min="32" max="256" step="1" value="' . esc_attr( (string) $image_size ) . '" />'
            . '<div class="kitgenix-muted">' . esc_html__( 'Controls the displayed size of the thumbnail/icon on the card (CSS sizing).', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_card_meta_alignment">' . esc_html__( 'File type/size alignment', 'kitgenix-document-manager' ) . '</label>'
            . '<select name="' . esc_attr( $opt_name ) . '[card_meta_alignment]" id="kitgenix_dm_card_meta_alignment">'
            . '<option value="justify" ' . selected( $meta_align, 'justify', false ) . '>' . esc_html__( 'Justify (label left, value right)', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="left" ' . selected( $meta_align, 'left', false ) . '>' . esc_html__( 'Left', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="center" ' . selected( $meta_align, 'center', false ) . '>' . esc_html__( 'Center', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="right" ' . selected( $meta_align, 'right', false ) . '>' . esc_html__( 'Right', 'kitgenix-document-manager' ) . '</option>'
            . '</select>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_card_button_alignment">' . esc_html__( 'Button alignment', 'kitgenix-document-manager' ) . '</label>'
            . '<select name="' . esc_attr( $opt_name ) . '[card_button_alignment]" id="kitgenix_dm_card_button_alignment">'
            . '<option value="left" ' . selected( $button_align, 'left', false ) . '>' . esc_html__( 'Left', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="center" ' . selected( $button_align, 'center', false ) . '>' . esc_html__( 'Center', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="right" ' . selected( $button_align, 'right', false ) . '>' . esc_html__( 'Right', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="justify" ' . selected( $button_align, 'justify', false ) . '>' . esc_html__( 'Justify (full width)', 'kitgenix-document-manager' ) . '</option>'
            . '</select>'
            . '<div style="margin-top:8px;">'
            . '<input type="hidden" name="' . esc_attr( $opt_name ) . '[card_button_full_width]" value="0" />'
            . '<label><input type="checkbox" name="' . esc_attr( $opt_name ) . '[card_button_full_width]" value="1" ' . checked( ! empty( $settings['card_button_full_width'] ), true, false ) . ' /> ' . esc_html__( 'Make the button full width', 'kitgenix-document-manager' ) . '</label>'
            . '</div>'
            . '</div>';

        $btn_text   = sanitize_text_field( (string) ( $settings['card_button_text_color'] ?? '' ) );
        $btn_bg     = sanitize_text_field( (string) ( $settings['card_button_bg_color'] ?? '' ) );
        $btn_border = sanitize_text_field( (string) ( $settings['card_button_border_color'] ?? '' ) );
        $btn_h_text = sanitize_text_field( (string) ( $settings['card_button_hover_text_color'] ?? '' ) );
        $btn_h_bg   = sanitize_text_field( (string) ( $settings['card_button_hover_bg_color'] ?? '' ) );
        $btn_h_brd  = sanitize_text_field( (string) ( $settings['card_button_hover_border_color'] ?? '' ) );

        echo '<div class="kitgenix-field">'
            . '<label>' . esc_html__( 'Button colors (optional)', 'kitgenix-document-manager' ) . '</label>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Enter hex colors like #ffffff. Leave blank to use theme defaults. Useful if solid buttons are hard to read.', 'kitgenix-document-manager' ) . '</div>'
            . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:520px;margin-top:8px;">'
            . '<div><label for="kitgenix_dm_btn_text" class="kitgenix-muted">' . esc_html__( 'Text', 'kitgenix-document-manager' ) . '</label><input id="kitgenix_dm_btn_text" name="' . esc_attr( $opt_name ) . '[card_button_text_color]" type="text" class="regular-text" placeholder="#ffffff" value="' . esc_attr( $btn_text ) . '" /></div>'
            . '<div><label for="kitgenix_dm_btn_bg" class="kitgenix-muted">' . esc_html__( 'Background', 'kitgenix-document-manager' ) . '</label><input id="kitgenix_dm_btn_bg" name="' . esc_attr( $opt_name ) . '[card_button_bg_color]" type="text" class="regular-text" placeholder="#4f2a9a" value="' . esc_attr( $btn_bg ) . '" /></div>'
            . '<div><label for="kitgenix_dm_btn_border" class="kitgenix-muted">' . esc_html__( 'Border', 'kitgenix-document-manager' ) . '</label><input id="kitgenix_dm_btn_border" name="' . esc_attr( $opt_name ) . '[card_button_border_color]" type="text" class="regular-text" placeholder="#4f2a9a" value="' . esc_attr( $btn_border ) . '" /></div>'
            . '<div></div>'
            . '<div><label for="kitgenix_dm_btn_h_text" class="kitgenix-muted">' . esc_html__( 'Hover text', 'kitgenix-document-manager' ) . '</label><input id="kitgenix_dm_btn_h_text" name="' . esc_attr( $opt_name ) . '[card_button_hover_text_color]" type="text" class="regular-text" placeholder="#ffffff" value="' . esc_attr( $btn_h_text ) . '" /></div>'
            . '<div><label for="kitgenix_dm_btn_h_bg" class="kitgenix-muted">' . esc_html__( 'Hover background', 'kitgenix-document-manager' ) . '</label><input id="kitgenix_dm_btn_h_bg" name="' . esc_attr( $opt_name ) . '[card_button_hover_bg_color]" type="text" class="regular-text" placeholder="#6d3dc6" value="' . esc_attr( $btn_h_bg ) . '" /></div>'
            . '<div><label for="kitgenix_dm_btn_h_border" class="kitgenix-muted">' . esc_html__( 'Hover border', 'kitgenix-document-manager' ) . '</label><input id="kitgenix_dm_btn_h_border" name="' . esc_attr( $opt_name ) . '[card_button_hover_border_color]" type="text" class="regular-text" placeholder="#6d3dc6" value="' . esc_attr( $btn_h_brd ) . '" /></div>'
            . '<div></div>'
            . '</div>'
            . '</div>';

        submit_button( __( 'Save Frontend Settings', 'kitgenix-document-manager' ) );

        echo '</form>';

        // Shortcode builder (single or multiple documents).
        // Uses admin-ajax search so it scales to thousands of documents.
        $default_shortcode = '[kitgenix_document_manager_document slug="my-doc"]';

        echo '<hr style="margin:18px 0;" />';
        echo '<h3>' . esc_html__( 'Shortcode Builder', 'kitgenix-document-manager' ) . '</h3>';
        echo '<p class="kitgenix-muted">' . esc_html__( 'Generate a shortcode for a single document or multiple documents inline.', 'kitgenix-document-manager' ) . '</p>';

        echo '<div id="kitgenix-dm-shortcode-builder" class="kitgenix-field">'
            . '<label style="display:block;margin-bottom:6px;">' . esc_html__( 'Mode', 'kitgenix-document-manager' ) . '</label>'
            . '<label style="margin-right:12px;"><input type="radio" name="kitgenix_dm_sc_mode" value="single" checked /> ' . esc_html__( 'Single document', 'kitgenix-document-manager' ) . '</label>'
            . '<label><input type="radio" name="kitgenix_dm_sc_mode" value="multiple" /> ' . esc_html__( 'Multiple documents', 'kitgenix-document-manager' ) . '</label>'
            . '</div>';

        echo '<div class="kitgenix-field" data-kitgenix-dm-sc-panel="single">'
            . '<label for="kitgenix_dm_sc_search_single">' . esc_html__( 'Search', 'kitgenix-document-manager' ) . '</label>'
            . '<input id="kitgenix_dm_sc_search_single" type="text" class="regular-text" style="min-width:320px;max-width:520px;" placeholder="' . esc_attr__( 'Type to search documents...', 'kitgenix-document-manager' ) . '" autocomplete="off" />'
            . '<div id="kitgenix_dm_sc_results_single" style="padding:8px;max-height:220px;overflow:auto;max-width:520px;margin-top:8px;">'
            . '<div class="kitgenix-muted">' . esc_html__( 'Start typing to search.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>'
            . '<input type="hidden" id="kitgenix_dm_sc_single_slug" value="" />'
            . '</div>';

        echo '<div class="kitgenix-field" data-kitgenix-dm-sc-panel="multiple" style="display:none;">'
            . '<label for="kitgenix_dm_sc_search_multiple">' . esc_html__( 'Search', 'kitgenix-document-manager' ) . '</label>'
            . '<input id="kitgenix_dm_sc_search_multiple" type="text" class="regular-text" style="min-width:320px;max-width:520px;" placeholder="' . esc_attr__( 'Type to search documents...', 'kitgenix-document-manager' ) . '" autocomplete="off" />'
            . '<div id="kitgenix_dm_sc_results_multiple" style="padding:8px;max-height:220px;overflow:auto;max-width:520px;margin-top:8px;">'
            . '<div class="kitgenix-muted">' . esc_html__( 'Start typing to search and tick the documents you want.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_sc_output">' . esc_html__( 'Shortcode', 'kitgenix-document-manager' ) . '</label>'
            . '<input id="kitgenix_dm_sc_output" type="text" class="regular-text" style="min-width:520px;max-width:820px;" readonly value="' . esc_attr( $default_shortcode ) . '" />'
            . ' <a href="#" class="button" data-default-label="' . esc_attr__( 'Copy shortcode', 'kitgenix-document-manager' ) . '" data-copied-label="' . esc_attr__( 'Copied', 'kitgenix-document-manager' ) . '" data-kitgenix-copy="' . esc_attr( $default_shortcode ) . '">' . esc_html__( 'Copy shortcode', 'kitgenix-document-manager' ) . '</a>'
            . '<div class="kitgenix-muted">' . esc_html__( 'Tip: You can also override behaviour per shortcode using attributes like slugs, image_size, and button_label.', 'kitgenix-document-manager' ) . '</div>'
            . '</div>';

        echo '</div>';
    }

    public static function handle_document_save(): void {
        if ( ! Capabilities::user_can_manage() ) {
            wp_die( esc_html__( 'Forbidden', 'kitgenix-document-manager' ) );
        }

        $nonce = isset( $_POST['kitgenix_document_manager_document_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['kitgenix_document_manager_document_nonce'] ) )
            : '';
        if ( '' === $nonce ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_document_manager_document_save' ) ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $title   = isset( $_POST['title'] ) ? (string) sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $slug    = isset( $_POST['slug'] ) ? (string) sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
        $vis     = isset( $_POST['visibility'] ) ? kitgenix_document_manager_normalize_visibility( (string) wp_unslash( $_POST['visibility'] ) ) : 'public'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized via helper.
        $versioning = ! empty( $_POST['versioning_enabled'] );

        $access_roles = isset( $_POST['access_roles'] ) ? kitgenix_document_manager_sanitize_roles( wp_unslash( $_POST['access_roles'] ) ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via helper.
        $access_capability = isset( $_POST['access_capability'] ) ? sanitize_key( (string) wp_unslash( $_POST['access_capability'] ) ) : '';
        $access_user_ids = isset( $_POST['access_user_ids'] ) ? kitgenix_document_manager_sanitize_user_ids( wp_unslash( (string) $_POST['access_user_ids'] ) ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via helper.

        $share_expires_raw = isset( $_POST['share_expires_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['share_expires_at'] ) ) : '';
        $available_from_raw = isset( $_POST['available_from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['available_from'] ) ) : '';
        $archive_at_raw = isset( $_POST['archive_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['archive_at'] ) ) : '';
        $expires_at_raw = isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['expires_at'] ) ) : '';

        $share_expires_gmt = $share_expires_raw !== '' ? kitgenix_document_manager_local_datetime_to_gmt( $share_expires_raw ) : '';
        $available_from_gmt = $available_from_raw !== '' ? kitgenix_document_manager_local_datetime_to_gmt( $available_from_raw ) : '';
        $archive_at_gmt = $archive_at_raw !== '' ? kitgenix_document_manager_local_datetime_to_gmt( $archive_at_raw ) : '';
        $expires_at_gmt = $expires_at_raw !== '' ? kitgenix_document_manager_local_datetime_to_gmt( $expires_at_raw ) : '';

        if ( $share_expires_raw !== '' && $share_expires_gmt === '' ) {
            self::redirect_with_error( __( 'Signed share link expiry is invalid.', 'kitgenix-document-manager' ) );
        }
        if ( $available_from_raw !== '' && $available_from_gmt === '' ) {
            self::redirect_with_error( __( 'Available from date is invalid.', 'kitgenix-document-manager' ) );
        }
        if ( $archive_at_raw !== '' && $archive_at_gmt === '' ) {
            self::redirect_with_error( __( 'Archive date is invalid.', 'kitgenix-document-manager' ) );
        }
        if ( $expires_at_raw !== '' && $expires_at_gmt === '' ) {
            self::redirect_with_error( __( 'Expiry date is invalid.', 'kitgenix-document-manager' ) );
        }

        $available_ts = $available_from_gmt !== '' ? strtotime( $available_from_gmt . ' GMT' ) : false;
        $archive_ts   = $archive_at_gmt !== '' ? strtotime( $archive_at_gmt . ' GMT' ) : false;
        $expires_ts   = $expires_at_gmt !== '' ? strtotime( $expires_at_gmt . ' GMT' ) : false;

        if ( $available_ts && $archive_ts && $archive_ts <= $available_ts ) {
            self::redirect_with_error( __( 'Archive date must be later than the availability date.', 'kitgenix-document-manager' ) );
        }
        if ( $available_ts && $expires_ts && $expires_ts <= $available_ts ) {
            self::redirect_with_error( __( 'Expiry date must be later than the availability date.', 'kitgenix-document-manager' ) );
        }

        $category_id = isset( $_POST['category_id'] ) ? (int) $_POST['category_id'] : 0;
        if ( $category_id < 0 ) {
            $category_id = 0;
        }

        $selected_attachment_id = isset( $_POST['selected_attachment_id'] ) ? (int) $_POST['selected_attachment_id'] : 0;

        $description = isset( $_POST['description'] )
            ? (string) sanitize_textarea_field( wp_unslash( $_POST['description'] ) )
            : '';

        if ( $title === '' ) {
            self::redirect_with_error( __( 'Name is required.', 'kitgenix-document-manager' ) );
        }

        // Prevent a common MySQL failure on older databases (utf8, not utf8mb4):
        // inserting 4-byte characters (e.g. emoji) into post_title can trigger
        // "Could not insert post into the database.".
        if ( self::string_has_4byte_chars( $title ) && ! self::db_supports_4byte_for_posts_title() ) {
            self::redirect_with_error(
                __( 'Your document name contains emoji or special characters that your site database cannot store. Remove those characters from the name, or update your database to use utf8mb4.', 'kitgenix-document-manager' )
            );
        }

        $is_update = false;
        if ( $post_id > 0 ) {
            $p = get_post( $post_id );
            if ( ! ( $p instanceof \WP_Post ) || $p->post_type !== CPT::POST_TYPE ) {
                self::redirect_with_error( __( 'Invalid document.', 'kitgenix-document-manager' ) );
            }
            $is_update = true;
        }

        $postarr = [
            'post_type'   => CPT::POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => $title,
        ];
        if ( $slug !== '' ) {
            $postarr['post_name'] = $slug;
        }
        // WordPress expects slashed post data.
        $postarr = wp_slash( $postarr );

        if ( $is_update ) {
            $postarr['ID'] = $post_id;
            $result = wp_update_post( $postarr, true );
        } else {
            $result = wp_insert_post( $postarr, true );
        }

        if ( $result instanceof \WP_Error ) {
            self::redirect_with_error( self::format_insert_error( $result ) );
        }

        if ( ! is_int( $result ) && ! is_numeric( $result ) ) {
            self::redirect_with_error( __( 'Save failed. Please try again.', 'kitgenix-document-manager' ) );
        }

        $doc_id = (int) $result;
        if ( $doc_id <= 0 ) {
            self::redirect_with_error( self::format_insert_error( null ) );
        }

        update_post_meta( $doc_id, '_kitgenix_document_manager_visibility', $vis );
        update_post_meta( $doc_id, '_kitgenix_document_manager_versioning_enabled', $versioning ? 1 : 0 );
        update_post_meta( $doc_id, '_kitgenix_document_manager_description', $description );

        if ( ! empty( $access_roles ) ) {
            update_post_meta( $doc_id, '_kitgenix_document_manager_access_roles', $access_roles );
        } else {
            delete_post_meta( $doc_id, '_kitgenix_document_manager_access_roles' );
        }

        if ( $access_capability !== '' ) {
            update_post_meta( $doc_id, '_kitgenix_document_manager_access_capability', $access_capability );
        } else {
            delete_post_meta( $doc_id, '_kitgenix_document_manager_access_capability' );
        }

        if ( ! empty( $access_user_ids ) ) {
            update_post_meta( $doc_id, '_kitgenix_document_manager_access_user_ids', $access_user_ids );
        } else {
            delete_post_meta( $doc_id, '_kitgenix_document_manager_access_user_ids' );
        }

        if ( $share_expires_gmt !== '' ) {
            update_post_meta( $doc_id, '_kitgenix_document_manager_share_expires_gmt', $share_expires_gmt );
        } else {
            delete_post_meta( $doc_id, '_kitgenix_document_manager_share_expires_gmt' );
        }

        if ( $available_from_gmt !== '' ) {
            update_post_meta( $doc_id, '_kitgenix_document_manager_available_from_gmt', $available_from_gmt );
        } else {
            delete_post_meta( $doc_id, '_kitgenix_document_manager_available_from_gmt' );
        }

        if ( $archive_at_gmt !== '' ) {
            update_post_meta( $doc_id, '_kitgenix_document_manager_archive_at_gmt', $archive_at_gmt );
        } else {
            delete_post_meta( $doc_id, '_kitgenix_document_manager_archive_at_gmt' );
        }

        if ( $expires_at_gmt !== '' ) {
            update_post_meta( $doc_id, '_kitgenix_document_manager_expires_at_gmt', $expires_at_gmt );
        } else {
            delete_post_meta( $doc_id, '_kitgenix_document_manager_expires_at_gmt' );
        }

        // Category assignment.
        if ( $category_id > 0 ) {
            wp_set_object_terms( $doc_id, [ $category_id ], CPT::TAXONOMY_CATEGORY, false );
        } else {
            wp_set_object_terms( $doc_id, [], CPT::TAXONOMY_CATEGORY, false );
        }

        // Optional file upload.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --
        // Incoming files are validated by the Uploader class which uses WP APIs.
        // Use Uploader helper to avoid direct $_FILES access in this file.
        $has_file = \KitgenixDocumentManager\Core\Uploader::has_incoming_file();

        if ( $has_file ) {
            $current_id = (int) get_post_meta( $doc_id, '_kitgenix_document_manager_current_attachment_id', true );
            if ( $current_id > 0 ) {
                $delete_old = ! empty( kitgenix_document_manager_get_settings()['delete_old_versions'] );
                $new_id = Uploader::replace_file( $doc_id, \KitgenixDocumentManager\Core\Uploader::get_incoming_file(), $versioning, $delete_old );
                if ( $new_id <= 0 ) {
                    self::redirect_with_error( __( 'Upload failed. Please check file type and try again.', 'kitgenix-document-manager' ) );
                }
            } else {
                $attach_id = Uploader::upload_and_attach( $doc_id, \KitgenixDocumentManager\Core\Uploader::get_incoming_file() );
                if ( $attach_id <= 0 ) {
                    self::redirect_with_error( __( 'Upload failed. Please check file type and try again.', 'kitgenix-document-manager' ) );
                }
                Uploader::set_current_attachment( $doc_id, $attach_id );
                update_post_meta( $doc_id, '_kitgenix_document_manager_versions', [ $attach_id ] );
            }
        } elseif ( $selected_attachment_id > 0 ) {
            $att = get_post( $selected_attachment_id );
            if ( ! ( $att instanceof \WP_Post ) || $att->post_type !== 'attachment' ) {
                self::redirect_with_error( __( 'Invalid media selection.', 'kitgenix-document-manager' ) );
            }

            $path = get_attached_file( $selected_attachment_id );
            $path = is_string( $path ) ? $path : '';
            if ( $path === '' || ! file_exists( $path ) || ! is_readable( $path ) ) {
                self::redirect_with_error( __( 'Selected media file is missing or unreadable.', 'kitgenix-document-manager' ) );
            }

            // Validate against allowed extensions.
            $settings2 = kitgenix_document_manager_get_settings();
            $allowed_exts = isset( $settings2['allowed_extensions'] ) && is_array( $settings2['allowed_extensions'] ) ? $settings2['allowed_extensions'] : [];
            $ft = wp_check_filetype( wp_basename( $path ) );
            $ext = isset( $ft['ext'] ) ? strtolower( (string) $ft['ext'] ) : '';
            if ( $ext === '' || ( ! empty( $allowed_exts ) && ! in_array( $ext, $allowed_exts, true ) ) ) {
                self::redirect_with_error( __( 'Selected media type is not allowed by the plugin settings.', 'kitgenix-document-manager' ) );
            }

            // Make the attachment a child of the document (used for version validation).
            if ( (int) $att->post_parent !== $doc_id ) {
                wp_update_post( wp_slash( [ 'ID' => $selected_attachment_id, 'post_parent' => $doc_id ] ) );
            }

            $current_id = (int) get_post_meta( $doc_id, '_kitgenix_document_manager_current_attachment_id', true );
            $versions = kitgenix_document_manager_get_versions( $doc_id );

            if ( $versioning ) {
                if ( $current_id > 0 && ! in_array( $current_id, $versions, true ) ) {
                    $versions[] = $current_id;
                }
                if ( ! in_array( $selected_attachment_id, $versions, true ) ) {
                    $versions[] = $selected_attachment_id;
                }
                kitgenix_document_manager_set_versions( $doc_id, $versions );
            } else {
                $delete_old = ! empty( kitgenix_document_manager_get_settings()['delete_old_versions'] );
                if ( $delete_old ) {
                    foreach ( $versions as $vid ) {
                        if ( $vid > 0 && $vid !== $selected_attachment_id ) {
                            $v = get_post( $vid );
                            if ( $v instanceof \WP_Post && $v->post_type === 'attachment' && (int) $v->post_parent === $doc_id ) {
                                wp_delete_attachment( $vid, true );
                            }
                        }
                    }
                    if ( $current_id > 0 && $current_id !== $selected_attachment_id ) {
                        $v = get_post( $current_id );
                        if ( $v instanceof \WP_Post && $v->post_type === 'attachment' && (int) $v->post_parent === $doc_id ) {
                            wp_delete_attachment( $current_id, true );
                        }
                    }
                }
                kitgenix_document_manager_set_versions( $doc_id, [ $selected_attachment_id ] );
            }

            Uploader::set_current_attachment( $doc_id, $selected_attachment_id );
        } elseif ( ! $is_update ) {
            self::redirect_with_error( __( 'Please upload a file or select one from the Media Library.', 'kitgenix-document-manager' ) );
        }

        $url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) );
        $url = add_query_arg( [ 'kitgenix_saved' => 1 ], $url );
        $url .= '#kitgenix-document-manager-tab-documents';
        wp_safe_redirect( $url );
        exit;
    }

    public static function handle_document_delete(): void {
        if ( ! Capabilities::user_can_manage() ) {
            wp_die( esc_html__( 'Forbidden', 'kitgenix-document-manager' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
        if ( '' === $nonce ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_document_manager_document_delete' ) ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $doc_id = isset( $_GET['document_id'] ) ? (int) $_GET['document_id'] : 0;
        if ( $doc_id > 0 ) {
            $p = get_post( $doc_id );
            if ( $p instanceof \WP_Post && $p->post_type === CPT::POST_TYPE ) {
                wp_trash_post( $doc_id );
            }
        }

        $url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) );
        $url = add_query_arg( [ 'kitgenix_deleted' => 1 ], $url );
        $url .= '#kitgenix-document-manager-tab-documents';
        wp_safe_redirect( $url );
        exit;
    }

    public static function handle_documents_bulk_action(): void {
        if ( ! Capabilities::user_can_manage() ) {
            wp_die( esc_html__( 'Forbidden', 'kitgenix-document-manager' ) );
        }

        $nonce = isset( $_POST['kitgenix_document_manager_bulk_action_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['kitgenix_document_manager_bulk_action_nonce'] ) )
            : '';
        if ( '' === $nonce ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_document_manager_documents_bulk_action' ) ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }

        $bulk_action = isset( $_POST['bulk_action'] ) ? (string) sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
        $document_ids = self::get_selected_document_ids();

        if ( empty( $document_ids ) ) {
            self::redirect_with_error( __( 'Select at least one document first.', 'kitgenix-document-manager' ) );
        }

        if ( ! in_array( $bulk_action, [ 'visibility_public', 'visibility_private', 'visibility_restricted', 'assign_category', 'clear_category', 'delete' ], true ) ) {
            self::redirect_with_error( __( 'Choose a valid bulk action.', 'kitgenix-document-manager' ) );
        }

        $category_id = isset( $_POST['bulk_category_id'] ) ? (int) $_POST['bulk_category_id'] : 0;
        if ( $category_id < 0 ) {
            $category_id = 0;
        }

        if ( 'assign_category' === $bulk_action ) {
            if ( $category_id <= 0 ) {
                self::redirect_with_error( __( 'Choose a category for the bulk category update.', 'kitgenix-document-manager' ) );
            }

            $term = get_term( $category_id, CPT::TAXONOMY_CATEGORY );
            if ( ! ( $term instanceof \WP_Term ) ) {
                self::redirect_with_error( __( 'Invalid category selected.', 'kitgenix-document-manager' ) );
            }
        }

        $updated = 0;

        foreach ( $document_ids as $document_id ) {
            $post = get_post( $document_id );
            if ( ! ( $post instanceof \WP_Post ) || $post->post_type !== CPT::POST_TYPE ) {
                continue;
            }

            switch ( $bulk_action ) {
                case 'visibility_public':
                    update_post_meta( $document_id, '_kitgenix_document_manager_visibility', 'public' );
                    ++$updated;
                    break;

                case 'visibility_private':
                    update_post_meta( $document_id, '_kitgenix_document_manager_visibility', 'private' );
                    ++$updated;
                    break;

                case 'visibility_restricted':
                    update_post_meta( $document_id, '_kitgenix_document_manager_visibility', 'restricted' );
                    ++$updated;
                    break;

                case 'assign_category':
                    $result = wp_set_object_terms( $document_id, [ $category_id ], CPT::TAXONOMY_CATEGORY, false );
                    if ( ! is_wp_error( $result ) ) {
                        ++$updated;
                    }
                    break;

                case 'clear_category':
                    $result = wp_set_object_terms( $document_id, [], CPT::TAXONOMY_CATEGORY, false );
                    if ( ! is_wp_error( $result ) ) {
                        ++$updated;
                    }
                    break;

                case 'delete':
                    if ( wp_trash_post( $document_id ) ) {
                        ++$updated;
                    }
                    break;
            }
        }

        if ( $updated <= 0 ) {
            self::redirect_with_error( __( 'No documents were updated.', 'kitgenix-document-manager' ) );
        }

        $url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) );
        $url = add_query_arg(
            [
                'kitgenix_bulk_updated' => $updated,
                'kitgenix_bulk_action'  => $bulk_action,
            ],
            $url
        );
        $url .= '#kitgenix-document-manager-tab-documents';
        wp_safe_redirect( $url );
        exit;
    }

    public static function handle_documents_bulk_upload(): void {
        if ( ! Capabilities::user_can_manage() ) {
            wp_die( esc_html__( 'Forbidden', 'kitgenix-document-manager' ) );
        }

        $nonce = isset( $_POST['kitgenix_document_manager_bulk_upload_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['kitgenix_document_manager_bulk_upload_nonce'] ) )
            : '';
        if ( '' === $nonce ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_document_manager_documents_bulk_upload' ) ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }

        $files = Uploader::get_incoming_files( 'kitgenix_document_manager_bulk_files' );
        if ( empty( $files ) ) {
            self::redirect_with_error( __( 'Add at least one file to the batch upload.', 'kitgenix-document-manager' ) );
        }

        $visibility = isset( $_POST['bulk_upload_visibility'] ) ? kitgenix_document_manager_normalize_visibility( (string) wp_unslash( $_POST['bulk_upload_visibility'] ) ) : 'public'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized via helper.
        $versioning = ! empty( $_POST['bulk_upload_versioning_enabled'] );

        $category_id = isset( $_POST['bulk_upload_category_id'] ) ? (int) $_POST['bulk_upload_category_id'] : 0;
        if ( $category_id < 0 ) {
            $category_id = 0;
        }

        if ( $category_id > 0 ) {
            $term = get_term( $category_id, CPT::TAXONOMY_CATEGORY );
            if ( ! ( $term instanceof \WP_Term ) ) {
                self::redirect_with_error( __( 'Invalid category selected for the batch upload.', 'kitgenix-document-manager' ) );
            }
        }

        $created = 0;
        $failed  = 0;

        foreach ( $files as $index => $file ) {
            $file_name = isset( $file['name'] ) ? (string) $file['name'] : '';
            $title = self::build_document_title_from_filename( $file_name, $index + 1 );

            if ( self::string_has_4byte_chars( $title ) && ! self::db_supports_4byte_for_posts_title() ) {
                ++$failed;
                continue;
            }

            $result = wp_insert_post(
                wp_slash(
                    [
                        'post_type'   => CPT::POST_TYPE,
                        'post_status' => 'publish',
                        'post_title'  => $title,
                    ]
                ),
                true
            );

            if ( $result instanceof \WP_Error || ( ! is_int( $result ) && ! is_numeric( $result ) ) ) {
                ++$failed;
                continue;
            }

            $document_id = (int) $result;
            if ( $document_id <= 0 ) {
                ++$failed;
                continue;
            }

            update_post_meta( $document_id, '_kitgenix_document_manager_visibility', $visibility );
            update_post_meta( $document_id, '_kitgenix_document_manager_versioning_enabled', $versioning ? 1 : 0 );
            update_post_meta( $document_id, '_kitgenix_document_manager_description', '' );

            if ( $category_id > 0 ) {
                wp_set_object_terms( $document_id, [ $category_id ], CPT::TAXONOMY_CATEGORY, false );
            } else {
                wp_set_object_terms( $document_id, [], CPT::TAXONOMY_CATEGORY, false );
            }

            $attachment_id = Uploader::upload_and_attach( $document_id, $file );
            if ( $attachment_id <= 0 ) {
                wp_delete_post( $document_id, true );
                ++$failed;
                continue;
            }

            Uploader::set_current_attachment( $document_id, $attachment_id );
            kitgenix_document_manager_set_versions( $document_id, [ $attachment_id ] );
            ++$created;
        }

        if ( $created <= 0 ) {
            self::redirect_with_error( __( 'Batch upload failed. Check the allowed file types and try again.', 'kitgenix-document-manager' ) );
        }

        $url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) );
        $url = add_query_arg(
            [
                'kitgenix_bulk_uploaded' => $created,
                'kitgenix_bulk_failed'   => $failed,
            ],
            $url
        );
        $url .= '#kitgenix-document-manager-tab-documents';
        wp_safe_redirect( $url );
        exit;
    }

    public static function handle_version_restore(): void {
        if ( ! Capabilities::user_can_manage() ) {
            wp_die( esc_html__( 'Forbidden', 'kitgenix-document-manager' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
        if ( '' === $nonce ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_document_manager_version_restore' ) ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $doc_id = isset( $_GET['document_id'] ) ? (int) $_GET['document_id'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $attachment_id = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : 0;
        if ( $doc_id <= 0 || $attachment_id <= 0 ) {
            self::redirect_with_error( __( 'Invalid request.', 'kitgenix-document-manager' ) );
        }

        $p = get_post( $doc_id );
        if ( ! ( $p instanceof \WP_Post ) || $p->post_type !== CPT::POST_TYPE ) {
            self::redirect_with_error( __( 'Invalid document.', 'kitgenix-document-manager' ) );
        }

        if ( ! kitgenix_document_manager_is_versioning_enabled( $doc_id ) ) {
            self::redirect_with_error( __( 'Version history is not enabled for this document.', 'kitgenix-document-manager' ) );
        }

        $att = get_post( $attachment_id );
        if ( ! ( $att instanceof \WP_Post ) || $att->post_type !== 'attachment' || (int) $att->post_parent !== $doc_id ) {
            self::redirect_with_error( __( 'Invalid version.', 'kitgenix-document-manager' ) );
        }

        $versions = kitgenix_document_manager_get_versions( $doc_id );
        if ( ! in_array( $attachment_id, $versions, true ) ) {
            self::redirect_with_error( __( 'Invalid version.', 'kitgenix-document-manager' ) );
        }

        // Set current, keep versions list intact.
        Uploader::set_current_attachment( $doc_id, $attachment_id );
        if ( ! in_array( $attachment_id, $versions, true ) ) {
            $versions[] = $attachment_id;
            kitgenix_document_manager_set_versions( $doc_id, $versions );
        }

        $url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) );
        $url = add_query_arg( [ 'kitgenix_version_restored' => 1 ], $url );
        $url .= '#kitgenix-document-manager-tab-documents';
        wp_safe_redirect( $url );
        exit;
    }

    public static function handle_version_delete(): void {
        if ( ! Capabilities::user_can_manage() ) {
            wp_die( esc_html__( 'Forbidden', 'kitgenix-document-manager' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
        if ( '' === $nonce ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_document_manager_version_delete' ) ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $doc_id = isset( $_GET['document_id'] ) ? (int) $_GET['document_id'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $attachment_id = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : 0;
        if ( $doc_id <= 0 || $attachment_id <= 0 ) {
            self::redirect_with_error( __( 'Invalid request.', 'kitgenix-document-manager' ) );
        }

        $p = get_post( $doc_id );
        if ( ! ( $p instanceof \WP_Post ) || $p->post_type !== CPT::POST_TYPE ) {
            self::redirect_with_error( __( 'Invalid document.', 'kitgenix-document-manager' ) );
        }

        if ( ! kitgenix_document_manager_is_versioning_enabled( $doc_id ) ) {
            self::redirect_with_error( __( 'Version history is not enabled for this document.', 'kitgenix-document-manager' ) );
        }

        $current_id = (int) get_post_meta( $doc_id, '_kitgenix_document_manager_current_attachment_id', true );
        if ( $current_id > 0 && $attachment_id === $current_id ) {
            self::redirect_with_error( __( 'You cannot delete the current version.', 'kitgenix-document-manager' ) );
        }

        $att = get_post( $attachment_id );
        if ( ! ( $att instanceof \WP_Post ) || $att->post_type !== 'attachment' || (int) $att->post_parent !== $doc_id ) {
            self::redirect_with_error( __( 'Invalid version.', 'kitgenix-document-manager' ) );
        }

        $versions = kitgenix_document_manager_get_versions( $doc_id );
        if ( ! in_array( $attachment_id, $versions, true ) ) {
            self::redirect_with_error( __( 'Invalid version.', 'kitgenix-document-manager' ) );
        }

        $versions = array_values( array_filter( $versions, static function ( int $id ) use ( $attachment_id ): bool {
            return $id > 0 && $id !== $attachment_id;
        } ) );
        kitgenix_document_manager_set_versions( $doc_id, $versions );

        wp_delete_attachment( $attachment_id, true );

        $url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) );
        $url = add_query_arg( [ 'kitgenix_version_deleted' => 1 ], $url );
        $url .= '#kitgenix-document-manager-tab-documents';
        wp_safe_redirect( $url );
        exit;
    }

    public static function handle_versions_delete_all(): void {
        if ( ! Capabilities::user_can_manage() ) {
            wp_die( esc_html__( 'Forbidden', 'kitgenix-document-manager' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
        if ( '' === $nonce ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_document_manager_versions_delete_all' ) ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $doc_id = isset( $_GET['document_id'] ) ? (int) $_GET['document_id'] : 0;
        if ( $doc_id <= 0 ) {
            self::redirect_with_error( __( 'Invalid document.', 'kitgenix-document-manager' ) );
        }

        $p = get_post( $doc_id );
        if ( ! ( $p instanceof \WP_Post ) || $p->post_type !== CPT::POST_TYPE ) {
            self::redirect_with_error( __( 'Invalid document.', 'kitgenix-document-manager' ) );
        }

        $current_id = (int) get_post_meta( $doc_id, '_kitgenix_document_manager_current_attachment_id', true );
        $versions = kitgenix_document_manager_get_versions( $doc_id );

        foreach ( $versions as $vid ) {
            $vid = (int) $vid;
            if ( $vid <= 0 || ( $current_id > 0 && $vid === $current_id ) ) {
                continue;
            }
            $att = get_post( $vid );
            if ( $att instanceof \WP_Post && $att->post_type === 'attachment' && (int) $att->post_parent === $doc_id ) {
                wp_delete_attachment( $vid, true );
            }
        }

        if ( $current_id > 0 ) {
            kitgenix_document_manager_set_versions( $doc_id, [ $current_id ] );
        } else {
            kitgenix_document_manager_set_versions( $doc_id, [] );
        }

        $url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) );
        $url = add_query_arg( [ 'kitgenix_saved' => 1 ], $url );
        $url .= '#kitgenix-document-manager-tab-versions';
        wp_safe_redirect( $url );
        exit;
    }

    private static function render_versions_tab(): void {
        echo '<div class="kitgenix-card">'
            . '<h3>' . esc_html__( 'Versions', 'kitgenix-document-manager' ) . '</h3>'
            . '<p class="kitgenix-muted">' . esc_html__( 'Manage version history for documents that have older files saved.', 'kitgenix-document-manager' ) . '</p>';

        $docs = get_posts(
            [
                'post_type'        => CPT::POST_TYPE,
                'post_status'      => 'publish',
                'numberposts'      => 200,
                'orderby'          => 'date',
                'order'            => 'DESC',
                'no_found_rows'    => true,
                'cache_results'    => true,
                'suppress_filters' => false,
            ]
        );

        $rows = [];
        foreach ( $docs as $doc ) {
            if ( ! ( $doc instanceof \WP_Post ) ) {
                continue;
            }
            $versions = kitgenix_document_manager_get_versions( (int) $doc->ID );
            if ( count( $versions ) <= 1 ) {
                continue;
            }
            $rows[] = $doc;
        }

        if ( empty( $rows ) ) {
            echo '<p class="kitgenix-muted">' . esc_html__( 'No documents currently have older versions saved.', 'kitgenix-document-manager' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<div class="kitgenix-table-wrap">'
            . '<table class="kitgenix-table kitgenix-links-table">'
            . '<thead><tr>'
            . '<th>' . esc_html__( 'Document', 'kitgenix-document-manager' ) . '</th>'
            . '<th>' . esc_html__( 'Versions saved', 'kitgenix-document-manager' ) . '</th>'
            . '<th class="kitgenix-col-actions">' . esc_html__( 'Actions', 'kitgenix-document-manager' ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( $rows as $doc ) {
            $versions = kitgenix_document_manager_get_versions( (int) $doc->ID );
            $count = count( $versions );

            $delete_all_url = admin_url( 'admin-post.php?action=kitgenix_document_manager_versions_delete_all&document_id=' . (int) $doc->ID );
            $delete_all_url = wp_nonce_url( $delete_all_url, 'kitgenix_document_manager_versions_delete_all', 'nonce' );

            $edit_url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) . '&kitgenix_action=edit&document_id=' . (int) $doc->ID . '#kitgenix-document-manager-tab-documents' );

            echo '<tr>'
                . '<td><strong>' . esc_html( (string) $doc->post_title ) . '</strong></td>'
                . '<td>' . esc_html( number_format_i18n( $count ) ) . '</td>'
                . '<td class="kitgenix-actions kitgenix-col-actions">'
                . '<a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'kitgenix-document-manager' ) . '</a>'
                . ' <a class="button button-small button-link-delete" href="' . esc_url( $delete_all_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete all old versions for this document? This cannot be undone.', 'kitgenix-document-manager' ) ) . '\');">' . esc_html__( 'Delete all old versions', 'kitgenix-document-manager' ) . '</a>'
                . '</td>'
                . '</tr>';
        }

        echo '</tbody></table></div>';
        echo '</div>';
    }

    private static function render_categories_tab(): void {
        $cat_action = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['cat_action'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $cat_action = sanitize_key( wp_unslash( $_GET['cat_action'] ) );
        }

        $term_id = 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['term_id'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $term_id = (int) $_GET['term_id'];
        }

        $is_edit = ( $cat_action === 'edit' && $term_id > 0 );
        $term = null;
        if ( $is_edit ) {
            $t = get_term( $term_id, CPT::TAXONOMY_CATEGORY );
            if ( $t && ! is_wp_error( $t ) ) {
                $term = $t;
            } else {
                $is_edit = false;
            }
        }

        $name      = $is_edit && $term ? (string) $term->name : '';
        $slug      = $is_edit && $term ? (string) $term->slug : '';
        $parent_id = $is_edit && $term ? (int) $term->parent : 0;

        echo '<div class="kitgenix-grid kitgenix-grid--2">';

        echo '<div class="kitgenix-card">'
            . '<h3>' . ( $is_edit ? esc_html__( 'Edit Category', 'kitgenix-document-manager' ) : esc_html__( 'Add Category', 'kitgenix-document-manager' ) ) . '</h3>'
            . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
            . '<input type="hidden" name="action" value="kitgenix_document_manager_category_save" />'
            . '<input type="hidden" name="term_id" value="' . esc_attr( (string) $term_id ) . '" />';

        wp_nonce_field( 'kitgenix_document_manager_category_save', 'kitgenix_document_manager_category_nonce' );

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_cat_name">' . esc_html__( 'Name', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="text" id="kitgenix_dm_cat_name" name="name" value="' . esc_attr( $name ) . '" class="regular-text" required />'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_cat_slug">' . esc_html__( 'Slug (optional)', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="text" id="kitgenix_dm_cat_slug" name="slug" value="' . esc_attr( $slug ) . '" class="regular-text" placeholder="' . esc_attr__( 'Auto-generated from name', 'kitgenix-document-manager' ) . '" />'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_cat_parent">' . esc_html__( 'Parent category (optional)', 'kitgenix-document-manager' ) . '</label>';

        wp_dropdown_categories(
            [
                'taxonomy'          => CPT::TAXONOMY_CATEGORY,
                'hide_empty'        => false,
                'show_option_none'  => __( '— No parent —', 'kitgenix-document-manager' ),
                'option_none_value' => '0',
                'name'              => 'parent_id',
                'id'                => 'kitgenix_dm_cat_parent',
                'selected'          => $parent_id,
            ]
        );

        echo '</div>';

        submit_button( $is_edit ? __( 'Update Category', 'kitgenix-document-manager' ) : __( 'Add Category', 'kitgenix-document-manager' ) );

        if ( $is_edit ) {
            $cancel_url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) . '&tab=categories#kitgenix-document-manager-tab-categories' );
            echo '<a class="button" href="' . esc_url( $cancel_url ) . '">' . esc_html__( 'Cancel', 'kitgenix-document-manager' ) . '</a>';
        }

        echo '</form>'
            . '<p class="kitgenix-muted">' . esc_html__( 'Categories are only used inside this plugin to organize documents.', 'kitgenix-document-manager' ) . '</p>'
            . '</div>';

        $terms = get_terms(
            [
                'taxonomy'   => CPT::TAXONOMY_CATEGORY,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]
        );

        echo '<div class="kitgenix-card">'
            . '<div class="kitgenix-card-header">'
            . '<div class="kitgenix-card-header__title">'
            . '<h3 class="kitgenix-card-title">' . esc_html__( 'All Categories', 'kitgenix-document-manager' ) . '</h3>'
            . '<p class="kitgenix-card-subtitle">' . esc_html__( 'Add, edit, or delete categories used for organizing documents.', 'kitgenix-document-manager' ) . '</p>'
            . '</div>'
            . '</div>';

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            echo '<p class="kitgenix-muted">' . esc_html__( 'No categories yet.', 'kitgenix-document-manager' ) . '</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        echo '<div class="kitgenix-table-wrap">'
            . '<table class="kitgenix-table kitgenix-links-table kitgenix-document-manager-table">'
            . '<thead><tr>'
            . '<th>' . esc_html__( 'Name', 'kitgenix-document-manager' ) . '</th>'
            . '<th>' . esc_html__( 'Parent', 'kitgenix-document-manager' ) . '</th>'
            . '<th>' . esc_html__( 'Documents', 'kitgenix-document-manager' ) . '</th>'
            . '<th class="kitgenix-col-actions">' . esc_html__( 'Actions', 'kitgenix-document-manager' ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( $terms as $t ) {
            if ( ! ( $t instanceof \WP_Term ) ) {
                continue;
            }

            $parent_name = '—';
            if ( ! empty( $t->parent ) ) {
                $pt = get_term( (int) $t->parent, CPT::TAXONOMY_CATEGORY );
                if ( $pt && ! is_wp_error( $pt ) && isset( $pt->name ) ) {
                    $parent_name = (string) $pt->name;
                }
            }

            $edit_url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) . '&tab=categories&cat_action=edit&term_id=' . (int) $t->term_id . '#kitgenix-document-manager-tab-categories' );
            $del_url = admin_url( 'admin-post.php?action=kitgenix_document_manager_category_delete&term_id=' . (int) $t->term_id );
            $del_url = wp_nonce_url( $del_url, 'kitgenix_document_manager_category_delete', 'nonce' );

            echo '<tr>'
                . '<td><strong>' . esc_html( (string) $t->name ) . '</strong></td>'
                . '<td>' . esc_html( $parent_name ) . '</td>'
                . '<td>' . esc_html( number_format_i18n( (int) $t->count ) ) . '</td>'
                . '<td class="kitgenix-actions kitgenix-col-actions">'
                . '<a class="button button-small" href="' . esc_url( $edit_url ) . '"'
                . ' data-kitgenix-cat-edit="1"'
                . ' data-term-id="' . esc_attr( (string) (int) $t->term_id ) . '"'
                . ' data-name="' . esc_attr( (string) $t->name ) . '"'
                . ' data-slug="' . esc_attr( (string) $t->slug ) . '"'
                . ' data-parent-id="' . esc_attr( (string) (int) $t->parent ) . '"'
                . '>' . esc_html__( 'Edit', 'kitgenix-document-manager' ) . '</a>'
                . ' <a class="button button-small button-link-delete" href="' . esc_url( $del_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this category? Documents will be left uncategorized.', 'kitgenix-document-manager' ) ) . '\');">' . esc_html__( 'Delete', 'kitgenix-document-manager' ) . '</a>'
                . '</td>'
                . '</tr>';
        }

        echo '</tbody></table></div>';
        echo '</div>';

        // Modal editor.
        echo '<div class="kitgenix-modal" id="kitgenix-document-manager-category-modal" aria-hidden="true" role="dialog" aria-modal="true">'
            . '<div class="kitgenix-modal__backdrop" data-kitgenix-modal-close="1"></div>'
            . '<div class="kitgenix-modal__dialog" role="document">'
            . '<div class="kitgenix-modal__header">'
            . '<h2 class="kitgenix-modal__title">' . esc_html__( 'Edit Category', 'kitgenix-document-manager' ) . '</h2>'
            . '<button type="button" class="button kitgenix-modal__close" data-kitgenix-modal-close="1">' . esc_html__( 'Close', 'kitgenix-document-manager' ) . '</button>'
            . '</div>'
            . '<div class="kitgenix-modal__body">'
            . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="kitgenix-document-manager-category-form">'
            . '<input type="hidden" name="action" value="kitgenix_document_manager_category_save" />'
            . '<input type="hidden" name="term_id" value="" />';

        wp_nonce_field( 'kitgenix_document_manager_category_save', 'kitgenix_document_manager_category_nonce' );

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_cat_modal_name">' . esc_html__( 'Name', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="text" id="kitgenix_dm_cat_modal_name" name="name" value="" class="regular-text" required />'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_cat_modal_slug">' . esc_html__( 'Slug (optional)', 'kitgenix-document-manager' ) . '</label>'
            . '<input type="text" id="kitgenix_dm_cat_modal_slug" name="slug" value="" class="regular-text" placeholder="' . esc_attr__( 'Auto-generated from name', 'kitgenix-document-manager' ) . '" />'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_dm_cat_modal_parent">' . esc_html__( 'Parent category (optional)', 'kitgenix-document-manager' ) . '</label>';

        wp_dropdown_categories(
            [
                'taxonomy'          => CPT::TAXONOMY_CATEGORY,
                'hide_empty'        => false,
                'show_option_none'  => __( '— No parent —', 'kitgenix-document-manager' ),
                'option_none_value' => '0',
                'name'              => 'parent_id',
                'id'                => 'kitgenix_dm_cat_modal_parent',
                'selected'          => 0,
            ]
        );

        echo '</div>';

        echo '<div class="kitgenix-modal__actions">';
        echo '<a href="#" class="button kitgenix-modal__cancel" data-kitgenix-modal-close="1">' . esc_html__( 'Cancel', 'kitgenix-document-manager' ) . '</a>';
        echo ' ';
        submit_button( __( 'Save Changes', 'kitgenix-document-manager' ), 'primary', 'submit', false );
        echo '</div>';

        echo '</form>'
            . '</div>'
            . '</div>'
            . '</div>';

        echo '</div>';
    }

    public static function handle_category_save(): void {
        if ( ! Capabilities::user_can_manage() ) {
            wp_die( esc_html__( 'Forbidden', 'kitgenix-document-manager' ) );
        }

        $nonce = isset( $_POST['kitgenix_document_manager_category_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['kitgenix_document_manager_category_nonce'] ) ) : '';
        if ( '' === $nonce ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_document_manager_category_save' ) ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }

        $term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
        $name = isset( $_POST['name'] ) ? (string) sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $slug = isset( $_POST['slug'] ) ? (string) sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
        $parent_id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;

        if ( $term_id > 0 && $parent_id === $term_id ) {
            $parent_id = 0;
        }

        if ( $name === '' ) {
            self::redirect_with_error( __( 'Category name is required.', 'kitgenix-document-manager' ), 'categories' );
        }

        $args = [
            'parent' => max( 0, $parent_id ),
        ];
        if ( $slug !== '' ) {
            $args['slug'] = $slug;
        }

        if ( $term_id > 0 ) {
            $res = wp_update_term( $term_id, CPT::TAXONOMY_CATEGORY, array_merge( [ 'name' => $name ], $args ) );
        } else {
            $res = wp_insert_term( $name, CPT::TAXONOMY_CATEGORY, $args );
        }

        if ( is_wp_error( $res ) ) {
            self::redirect_with_error( $res->get_error_message(), 'categories' );
        }

        $url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) );
        $url = add_query_arg( [ 'tab' => 'categories', 'kitgenix_category_saved' => 1 ], $url );
        $url .= '#kitgenix-document-manager-tab-categories';
        wp_safe_redirect( $url );
        exit;
    }

    public static function handle_category_delete(): void {
        if ( ! Capabilities::user_can_manage() ) {
            wp_die( esc_html__( 'Forbidden', 'kitgenix-document-manager' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
        if ( '' === $nonce ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_document_manager_category_delete' ) ) {
            wp_die( esc_html__( 'Invalid nonce', 'kitgenix-document-manager' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $term_id = isset( $_GET['term_id'] ) ? (int) $_GET['term_id'] : 0;
        if ( $term_id <= 0 ) {
            self::redirect_with_error( __( 'Invalid category.', 'kitgenix-document-manager' ), 'categories' );
        }

        $t = get_term( $term_id, CPT::TAXONOMY_CATEGORY );
        if ( ! $t || is_wp_error( $t ) ) {
            self::redirect_with_error( __( 'Invalid category.', 'kitgenix-document-manager' ), 'categories' );
        }

        $res = wp_delete_term( $term_id, CPT::TAXONOMY_CATEGORY );
        if ( is_wp_error( $res ) ) {
            self::redirect_with_error( $res->get_error_message(), 'categories' );
        }

        $url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) );
        $url = add_query_arg( [ 'tab' => 'categories', 'kitgenix_category_deleted' => 1 ], $url );
        $url .= '#kitgenix-document-manager-tab-categories';
        wp_safe_redirect( $url );
        exit;
    }

    private static function redirect_with_error( string $message, string $tab = 'documents' ): void {
        $url = admin_url( 'admin.php?page=' . rawurlencode( self::PAGE_SLUG ) );
        $url = add_query_arg( [ 'tab' => $tab, 'kitgenix_error' => $message ], $url );
        $url .= '#kitgenix-document-manager-tab-' . rawurlencode( $tab );
        wp_safe_redirect( $url );
        exit;
    }

    private static function get_selected_document_ids(): array {
        $raw_document_ids = isset( $_POST['document_ids'] ) ? wp_unslash( $_POST['document_ids'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Called from nonce-verified bulk action handlers; values are normalized to int below.
        if ( ! is_array( $raw_document_ids ) ) {
            return [];
        }

        $document_ids = array_map( 'intval', $raw_document_ids );
        $document_ids = array_values(
            array_unique(
                array_filter(
                    $document_ids,
                    static function ( int $document_id ): bool {
                        return $document_id > 0;
                    }
                )
            )
        );

        return $document_ids;
    }

    private static function get_bulk_action_notice( string $bulk_action, int $count ): string {
        $count_label = number_format_i18n( $count );

        switch ( $bulk_action ) {
            case 'visibility_public':
                return sprintf(
                    /* translators: %s is the number of documents updated */
                    _n( '%s document is now public.', '%s documents are now public.', $count, 'kitgenix-document-manager' ),
                    $count_label
                );

            case 'visibility_private':
                return sprintf(
                    /* translators: %s is the number of documents updated */
                    _n( '%s document is now private.', '%s documents are now private.', $count, 'kitgenix-document-manager' ),
                    $count_label
                );

            case 'assign_category':
                return sprintf(
                    /* translators: %s is the number of documents updated */
                    _n( '%s document moved to the selected category.', '%s documents moved to the selected category.', $count, 'kitgenix-document-manager' ),
                    $count_label
                );

            case 'clear_category':
                return sprintf(
                    /* translators: %s is the number of documents updated */
                    _n( '%s document category cleared.', '%s document categories cleared.', $count, 'kitgenix-document-manager' ),
                    $count_label
                );

            case 'delete':
                return sprintf(
                    /* translators: %s is the number of documents updated */
                    _n( '%s document moved to Trash.', '%s documents moved to Trash.', $count, 'kitgenix-document-manager' ),
                    $count_label
                );
        }

        return sprintf(
            /* translators: %s is the number of documents updated */
            _n( '%s document updated.', '%s documents updated.', $count, 'kitgenix-document-manager' ),
            $count_label
        );
    }

    private static function build_document_title_from_filename( string $file_name, int $index ): string {
        $base_name = wp_basename( $file_name );
        $extension = pathinfo( $base_name, PATHINFO_EXTENSION );

        if ( $extension !== '' ) {
            $base_name = substr( $base_name, 0, -1 * ( strlen( $extension ) + 1 ) );
        }

        $title = str_replace( [ '-', '_' ], ' ', $base_name );
        $title = preg_replace( '/\s+/', ' ', $title );
        $title = is_string( $title ) ? trim( $title ) : '';

        if ( $title === '' ) {
            $title = sprintf(
                /* translators: %d is the position of the file in the batch upload */
                __( 'Document %d', 'kitgenix-document-manager' ),
                $index
            );
        }

        return $title;
    }

    private static function string_has_4byte_chars( string $text ): bool {
        // Detect non-BMP characters (includes most emoji).
        return (bool) preg_match( '/[\x{10000}-\x{10FFFF}]/u', $text );
    }

    private static function db_supports_4byte_for_posts_title(): bool {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return true;
        }

        if ( isset( $wpdb->charset ) && is_string( $wpdb->charset ) && strtolower( $wpdb->charset ) === 'utf8mb4' ) {
            return true;
        }

        if ( method_exists( $wpdb, 'get_col_charset' ) && isset( $wpdb->posts ) ) {
            $col_charset = $wpdb->get_col_charset( $wpdb->posts, 'post_title' );
            if ( is_string( $col_charset ) && strtolower( $col_charset ) === 'utf8mb4' ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a useful admin-facing message for insert/update failures.
     *
     * @param \WP_Error|null $error
     */
    private static function format_insert_error( $error ): string {
        $message = $error instanceof \WP_Error ? (string) $error->get_error_message() : __( 'Could not save the document.', 'kitgenix-document-manager' );

        global $wpdb;
        $wpdb_error = '';
        if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->last_error ) && is_string( $wpdb->last_error ) ) {
            $wpdb_error = trim( $wpdb->last_error );
        }

        // Provide a friendlier message for a very common DB charset problem.
        if ( $wpdb_error && preg_match( '/Incorrect string value|character set|collation/i', $wpdb_error ) ) {
            return __( 'Save failed due to a database character set limitation (often caused by emoji/special characters). Try removing emoji/special characters from the name, or update the database to utf8mb4.', 'kitgenix-document-manager' );
        }

        // Only show raw DB errors when debugging (or for site admins).
        $can_see_debug = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || current_user_can( 'manage_options' );
        if ( $can_see_debug && $wpdb_error ) {
            $message .= ' ' . sprintf(
                /* translators: %s is the database error message */
                __( '(Database error: %s)', 'kitgenix-document-manager' ),
                $wpdb_error
            );
        }

        return $message;
    }

    private static function render_log_tab(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['kitgenix_log_cleared'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Event log cleared.', 'kitgenix-document-manager' ) . '</p></div>';
        }

        $clear_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=kitgenix_document_manager_clear_event_log' ),
            'kitgenix_document_manager_clear_event_log'
        );

        echo '<div class="kitgenix-settings-section">';
        echo '<h2>' . esc_html__( 'Activity Log', 'kitgenix-document-manager' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'A record of recent plugin events. Entries show the timestamp, context, outcome, and a plain-English note. IP addresses and sensitive data are never stored here.', 'kitgenix-document-manager' ) . '</p>';
        echo '<textarea class="large-text code" rows="20" readonly>' . esc_textarea( Event_Log::get_log_text() ) . '</textarea>';
        echo '<p>';
        echo '<a href="' . esc_url( $clear_url ) . '" class="button button-secondary" onclick="return confirm(\'' . esc_js( __( 'Clear all log entries?', 'kitgenix-document-manager' ) ) . '\')">' . esc_html__( 'Clear log', 'kitgenix-document-manager' ) . '</a>';
        echo '</p>';
        echo '</div>';
    }

    public static function handle_clear_event_log(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'kitgenix-document-manager' ), 403 );
        }
        check_admin_referer( 'kitgenix_document_manager_clear_event_log' );
        Event_Log::clear();
        wp_safe_redirect( admin_url( 'admin.php?page=kitgenix-document-manager&tab=log&kitgenix_log_cleared=1' ) );
        exit;
    }

    public static function on_settings_saved( mixed $old_value, mixed $new_value, string $option ): void {
        Event_Log::record( 'settings-saved', 'success', __( 'Plugin settings were saved via the admin settings page.', 'kitgenix-document-manager' ) );
    }
}
