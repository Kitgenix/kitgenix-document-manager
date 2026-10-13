<?php
/**
 * Plugin Name:       Kitgenix Document Manager
 * Plugin URI:        https://wordpress.org/plugins/kitgenix-document-manager/
 * Author Plugin URI: https://kitgenix.com/plugins/kitgenix-document-manager
 * Documentation URI: https://kitgenix.com/plugins/kitgenix-document-manager/documentation
 * Support URI:       https://wordpress.org/support/plugin/kitgenix-document-manager/
 * Author Support URI: https://kitgenix.com/plugins/kitgenix-document-manager/support
 * Feature Request URI: https://kitgenix.com/plugins/kitgenix-document-manager/feature-request
 * Description:       Manage document downloads with stable links, granular access, lifecycle scheduling, and usage analytics.
 * Version:           2.1.0
 * Requires at least: 6.0
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Author:            Kitgenix
 * Author URI:        https://kitgenix.com/
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       kitgenix-document-manager
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// -----------------------------------------------------------------------------
// Shared Kitgenix admin menu (top-level) helper.
// Each Kitgenix plugin may call this; it is safe to call multiple times.
// -----------------------------------------------------------------------------
if ( ! function_exists( 'kitgenix_get_admin_menu_icon' ) ) {
    function kitgenix_get_admin_menu_icon( string $plugin_file ): string {
        $plugin_dir = dirname( $plugin_file ) . '/';
        $icon_paths = [
            $plugin_dir . 'assets/images/logos/kitgenix-wordpress-admin-menu-favicon.svg',
            $plugin_dir . 'assets/images/logos/kitgenix-black-favicon.svg',
        ];

        foreach ( $icon_paths as $icon_path ) {
            if ( ! is_readable( $icon_path ) ) {
                continue;
            }

            $svg = file_get_contents( $icon_path );
            if ( false !== $svg && '' !== trim( $svg ) ) {
                return 'data:image/svg+xml;base64,' . base64_encode( $svg );
            }
        }

        return 'dashicons-admin-generic';
    }
}

if ( ! function_exists( 'kitgenix_ensure_admin_menu' ) ) {
    function kitgenix_ensure_admin_menu(): void {
        if ( ! is_admin() ) {
            return;
        }

        global $admin_page_hooks;
        $slug = 'kitgenix';

        if ( isset( $admin_page_hooks[ $slug ] ) ) {
            return;
        }

        // Prefer WooCommerce managers when WooCommerce is active, otherwise admins.
        $capability = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
        if ( ! current_user_can( $capability ) && current_user_can( 'manage_options' ) ) {
            $capability = 'manage_options';
        }

        $icon_url = kitgenix_get_admin_menu_icon( __FILE__ );

        add_menu_page(
            __( 'Kitgenix', 'kitgenix-document-manager' ),
            __( 'Kitgenix', 'kitgenix-document-manager' ),
            $capability,
            $slug,
            'kitgenix_render_admin_page',
            $icon_url,
            58
        );
    }
}

add_action( 'admin_menu', 'kitgenix_ensure_admin_menu', 5 );

if ( ! function_exists( 'kitgenix_hub_get_wporg_active_installs' ) ) {
    /**
     * Fetch WP.org active install counts for a set of plugin slugs.
     * Cached to avoid repeated network calls.
     *
     * @param string[] $slugs
     * @return array<string,int> Map of slug => active_installs
     */
    function kitgenix_hub_get_wporg_active_installs( array $slugs ): array {
        if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
            return [];
        }

        $clean_slugs = [];
        foreach ( $slugs as $slug ) {
            $slug = is_string( $slug ) ? $slug : '';
            $slug = function_exists( 'sanitize_key' ) ? sanitize_key( $slug ) : strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $slug ) );
            if ( $slug ) {
                $clean_slugs[] = $slug;
            }
        }

        $clean_slugs = array_values( array_unique( $clean_slugs ) );
        if ( empty( $clean_slugs ) ) {
            return [];
        }

        $cache_key = 'kitgenix_hub_wporg_active_installs_v1';
        $cached    = get_transient( $cache_key );
        $cached    = is_array( $cached ) ? $cached : [];

        $missing = [];
        foreach ( $clean_slugs as $slug ) {
            if ( ! array_key_exists( $slug, $cached ) ) {
                $missing[] = $slug;
            }
        }

        if ( ! empty( $missing ) ) {
            if ( ! function_exists( 'plugins_api' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }

            foreach ( $missing as $slug ) {
                $info = plugins_api(
                    'plugin_information',
                    [
                        'slug'   => $slug,
                        'fields' => [
                            'active_installs'   => true,
                            'short_description' => false,
                            'description'       => false,
                            'sections'          => false,
                            'versions'          => false,
                            'banners'           => false,
                            'rating'            => false,
                            'ratings'           => false,
                            'downloaded'        => false,
                            'last_updated'      => false,
                            'added'             => false,
                            'tags'              => false,
                            'requires'          => false,
                            'requires_php'      => false,
                            'tested'            => false,
                            'homepage'          => false,
                            'donate_link'       => false,
                        ],
                    ]
                );

                if ( function_exists( 'is_wp_error' ) && is_wp_error( $info ) ) {
                    continue;
                }

                if ( is_object( $info ) && isset( $info->active_installs ) ) {
                    $cached[ $slug ] = (int) $info->active_installs;
                }
            }

            $ttl = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
            set_transient( $cache_key, $cached, $ttl );
        }

        $result = [];
        foreach ( $clean_slugs as $slug ) {
            if ( isset( $cached[ $slug ] ) && (int) $cached[ $slug ] > 0 ) {
                $result[ $slug ] = (int) $cached[ $slug ];
            }
        }

        return $result;
    }
}

if ( ! function_exists( 'kitgenix_hub_get_wporg_ratings' ) ) {
    /**
     * Fetch WP.org ratings (percentage) for a set of plugin slugs.
     *
     * @param array<int,string> $slugs Plugin slugs.
     * @return array<string,int> Map of slug => rating percentage (0-100)
     */
    function kitgenix_hub_get_wporg_ratings( array $slugs ): array {
        $slugs = array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
        if ( empty( $slugs ) ) {
            return [];
        }

        $cache_key = 'kitgenix_hub_wporg_ratings_v1';
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            $missing = array_diff( $slugs, array_keys( $cached ) );
            if ( empty( $missing ) ) {
                return $cached;
            }
        } else {
            $cached = [];
            $missing = $slugs;
        }

        if ( ! function_exists( 'plugins_api' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }

        foreach ( $missing as $slug ) {
            $info = plugins_api(
                'plugin_information',
                [
                    'slug'   => $slug,
                    'fields' => [
                        'rating' => true,
                    ],
                ]
            );

            if ( is_object( $info ) && isset( $info->rating ) ) {
                $cached[ $slug ] = (int) $info->rating;
            }
        }

        set_transient( $cache_key, $cached, DAY_IN_SECONDS );

        return $cached;
    }
}

if ( ! function_exists( 'kitgenix_hub_get_wporg_media' ) ) {
    /**
     * Fetch WP.org banner or icon artwork for a set of plugin slugs.
     *
     * @param array<int,string> $slugs Plugin slugs.
     * @return array<string,array{url:string,type:string}> Map of slug => media payload.
     */
    function kitgenix_hub_get_wporg_media( array $slugs ): array {
        if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
            return [];
        }

        $slugs = array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
        if ( empty( $slugs ) ) {
            return [];
        }

        $cache_key = 'kitgenix_hub_wporg_media_v1';
        $cached    = get_transient( $cache_key );
        $cached    = is_array( $cached ) ? $cached : [];
        $missing   = array_diff( $slugs, array_keys( $cached ) );

        if ( ! empty( $missing ) ) {
            if ( ! function_exists( 'plugins_api' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }

            foreach ( $missing as $slug ) {
                $info = plugins_api(
                    'plugin_information',
                    [
                        'slug'   => $slug,
                        'fields' => [
                            'icons'             => true,
                            'banners'           => true,
                            'active_installs'   => false,
                            'rating'            => false,
                            'ratings'           => false,
                            'short_description' => false,
                            'description'       => false,
                            'sections'          => false,
                            'versions'          => false,
                            'downloaded'        => false,
                            'last_updated'      => false,
                            'added'             => false,
                            'tags'              => false,
                            'requires'          => false,
                            'requires_php'      => false,
                            'tested'            => false,
                            'homepage'          => false,
                            'donate_link'       => false,
                        ],
                    ]
                );

                if ( function_exists( 'is_wp_error' ) && is_wp_error( $info ) ) {
                    continue;
                }

                $media_url  = '';
                $media_type = '';

                if ( is_object( $info ) && isset( $info->banners ) ) {
                    $banners = is_object( $info->banners ) ? get_object_vars( $info->banners ) : ( is_array( $info->banners ) ? $info->banners : [] );
                    foreach ( [ 'high', 'low' ] as $key ) {
                        if ( ! empty( $banners[ $key ] ) && is_string( $banners[ $key ] ) ) {
                            $media_url  = $banners[ $key ];
                            $media_type = 'banner';
                            break;
                        }
                    }
                }

                if ( '' === $media_url && is_object( $info ) && isset( $info->icons ) ) {
                    $icons = is_object( $info->icons ) ? get_object_vars( $info->icons ) : ( is_array( $info->icons ) ? $info->icons : [] );
                    foreach ( [ 'svg', '2x', '1x', 'default' ] as $key ) {
                        if ( ! empty( $icons[ $key ] ) && is_string( $icons[ $key ] ) ) {
                            $media_url  = $icons[ $key ];
                            $media_type = 'icon';
                            break;
                        }
                    }
                }

                $cached[ $slug ] = $media_url ? [
                    'url'  => $media_url,
                    'type' => $media_type,
                ] : [];
            }

            $ttl = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
            set_transient( $cache_key, $cached, $ttl );
        }

        $result = [];
        foreach ( $slugs as $slug ) {
            if ( ! empty( $cached[ $slug ]['url'] ) ) {
                $result[ $slug ] = [
                    'url'  => (string) $cached[ $slug ]['url'],
                    'type' => ! empty( $cached[ $slug ]['type'] ) ? (string) $cached[ $slug ]['type'] : 'icon',
                ];
            }
        }

        return $result;
    }
}

if ( ! function_exists( 'kitgenix_render_admin_page' ) ) {
    function kitgenix_render_admin_page(): void {
        $allowed = current_user_can( 'manage_options' ) || ( class_exists( 'WooCommerce' ) && current_user_can( 'manage_woocommerce' ) );
        if ( ! $allowed ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'kitgenix-document-manager' ) );
        }

        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins_data = function_exists( 'get_plugins' ) ? get_plugins() : [];

        $plugins = [
            [
                'id'       => 'documents',
                'name'     => __( 'Document Manager', 'kitgenix-document-manager' ),
                'slug'     => 'kitgenix-document-manager',
                'file'     => 'kitgenix-document-manager/kitgenix-document-manager.php',
                'page'     => 'kitgenix-document-manager',
                'requires' => __( 'Manage document downloads with stable links, version history, and private file access.', 'kitgenix-document-manager' ),
            ],
            [
                'id'       => 'turnstile',
                'name'     => __( 'CAPTCHA for Cloudflare Turnstile', 'kitgenix-document-manager' ),
                'slug'     => 'kitgenix-captcha-for-cloudflare-turnstile',
                'file'     => 'kitgenix-captcha-for-cloudflare-turnstile/kitgenix-captcha-for-cloudflare-turnstile.php',
                'page'     => 'kitgenix-captcha-for-cloudflare-turnstile',
                'requires' => __( 'Add Cloudflare Turnstile CAPTCHA to WordPress, WooCommerce, Elementor, and popular form plugins.', 'kitgenix-document-manager' ),
            ],
            [
                'id'       => 'custom_tabs',
                'name'     => __( 'Custom Tabs for WooCommerce', 'kitgenix-document-manager' ),
                'slug'     => 'kitgenix-custom-tabs-for-woocommerce',
                'file'     => 'kitgenix-custom-tabs-for-woocommerce/kitgenix-custom-tabs-for-woocommerce.php',
                'page'     => 'kitgenix-custom-tabs-for-woocommerce',
                'requires' => __( 'Add custom WooCommerce product tabs with per-product content, global tabs, and lightweight controls.', 'kitgenix-document-manager' ),
            ],
            [
                'id'       => 'tracking',
                'name'     => __( 'Order Tracking for WooCommerce', 'kitgenix-document-manager' ),
                'slug'     => 'kitgenix-order-tracking-for-woocommerce',
                'file'     => 'kitgenix-order-tracking-for-woocommerce/kitgenix-order-tracking-for-woocommerce.php',
                'page'     => 'kitgenix-order-tracking-for-woocommerce-analytics',
                'requires' => __( 'Add WooCommerce order tracking, multi-shipment support, email tracking links, and a public customer tracking page.', 'kitgenix-document-manager' ),
            ],
            [
                'id'       => 'pdf',
                'name'     => __( 'PDF Invoicing for WooCommerce', 'kitgenix-document-manager' ),
                'slug'     => 'kitgenix-pdf-invoicing-for-woocommerce',
                'file'     => 'kitgenix-pdf-invoicing-for-woocommerce/kitgenix-pdf-invoicing-for-woocommerce.php',
                'page'     => 'kitgenix-pdf-invoicing-settings',
                'requires' => __( 'Generate WooCommerce PDF invoices, receipts, packing slips, and credit notes with secure downloads and configurable email attachments.', 'kitgenix-document-manager' ),
            ],
            [
                'id'       => 'stock',
                'name'     => __( 'Stock Sync for WooCommerce', 'kitgenix-document-manager' ),
                'slug'     => 'kitgenix-stock-sync-for-woocommerce',
                'file'     => 'kitgenix-stock-sync-for-woocommerce/kitgenix-stock-sync-for-woocommerce.php',
                'page'     => 'kitgenix-stock-sync-for-woocommerce',
                'requires' => __( 'Sync WooCommerce stock between stores with secure master-child inventory updates and signed REST requests.', 'kitgenix-document-manager' ),
            ],
            [
                'id'       => 'multistore',
                'name'     => __( 'MultiStore for WooCommerce', 'kitgenix-document-manager' ),
                'slug'     => 'kitgenix-multistore-sync-for-woocommerce',
                'file'     => 'kitgenix-multistore-sync-for-woocommerce/kitgenix-multistore-sync-for-woocommerce.php',
                'page'     => 'kitgenix-multistore-sync-for-woocommerce',
                'requires' => __( 'Sync WooCommerce products, prices, media, and metadata between multiple stores with a secure master-child architecture.', 'kitgenix-document-manager' ),
            ],
            [
                'id'       => 'image_optimizer',
                'name'     => __( 'Image Optimizer', 'kitgenix-document-manager' ),
                'slug'     => 'kitgenix-image-optimizer',
                'file'     => 'kitgenix-image-optimizer/kitgenix-image-optimizer.php',
                'page'     => 'kitgenix-image-optimizer',
                'requires' => __( 'Optimize, compress, and resize images in your WordPress media library with automatic on-upload processing and bulk optimization tools.', 'kitgenix-document-manager' ),
            ],
            [
                'id'       => 'affiliate',
                'name'     => __( 'Affiliate Link Manager', 'kitgenix-document-manager' ),
                'slug'     => 'kitgenix-affiliate-link-manager',
                'file'     => 'kitgenix-affiliate-link-manager/kitgenix-affiliate-link-manager.php',
                'page'     => 'kitgenix-affiliate-link-manager',
                'requires' => __( 'Manage affiliate short links, branded redirects, and click tracking from one WordPress dashboard.', 'kitgenix-document-manager' ),
            ],
            [
                'id'       => 'database_cleaner',
                'name'     => __( 'Database Cleaner', 'kitgenix-document-manager' ),
                'slug'     => 'kitgenix-database-cleaner',
                'file'     => 'kitgenix-database-cleaner/kitgenix-database-cleaner.php',
                'page'     => 'kitgenix-database-cleaner',
                'requires' => __( 'Scan and clean your WordPress database safely, with plugin/theme attribution for every item found and a built-in recycle bin so nothing is ever deleted without a way back.', 'kitgenix-document-manager' ),
            ],
        ];

        $slugs = [];
        foreach ( $plugins as $plugin ) {
            if ( ! empty( $plugin['slug'] ) ) {
                $slugs[] = (string) $plugin['slug'];
            }
        }
        $wporg_active_installs = kitgenix_hub_get_wporg_active_installs( $slugs );
        $wporg_ratings        = kitgenix_hub_get_wporg_ratings( $slugs );
        $wporg_media          = kitgenix_hub_get_wporg_media( $slugs );
        $logo_url             = plugins_url( 'assets/images/logos/kitgenix-primary-favicon.svg', __FILE__ );

        echo '<div class="wrap kitgenix-admin-app plugin-install-php">'
            . '<div class="kitgenix-hub-header">'
            . '<div class="kitgenix-hub-brand">'
            . '<span class="kitgenix-topbar-brand">'
            . '<img class="kitgenix-hub-logo" src="' . esc_url( $logo_url ) . '" alt="' . esc_attr__( 'Kitgenix', 'kitgenix-document-manager' ) . '" width="30" height="30" />'
            . '</span>'
            . '<span class="kitgenix-topbar-divider" aria-hidden="true"></span>'
            . '<div class="kitgenix-hub-brand-copy">'
            . '<h1 class="kitgenix-hub-title">' . esc_html__( 'Kitgenix', 'kitgenix-document-manager' ) . '</h1>'
            . '<p class="kitgenix-hub-description">' . esc_html__( 'Install, activate, open, and review Kitgenix plugins.', 'kitgenix-document-manager' ) . '</p>'
            . '</div>'
            . '</div>'
            . '<div class="kitgenix-hub-social-links">'
            . '<a href="https://kitgenix.com" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="' . esc_url( plugins_url( 'assets/images/social-media/globe-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Website</span></a>'
            . '<a href="https://www.facebook.com/groups/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook Community" title="Facebook Community"><img src="' . esc_url( plugins_url( 'assets/images/social-media/facebook-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook Community</span></a>'
            . '<a href="https://www.facebook.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><img src="' . esc_url( plugins_url( 'assets/images/social-media/facebook-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook</span></a>'
            . '<a href="https://www.instagram.com/kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram"><img src="' . esc_url( plugins_url( 'assets/images/social-media/instagram-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Instagram</span></a>'
            . '<a href="https://www.youtube.com/@Kitgenix" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube"><img src="' . esc_url( plugins_url( 'assets/images/social-media/youtube-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">YouTube</span></a>'
            . '<a href="https://www.reddit.com/r/Kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Reddit" title="Reddit"><img src="' . esc_url( plugins_url( 'assets/images/social-media/reddit-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Reddit</span></a>'
            . '<a href="https://www.linkedin.com/company/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><img src="' . esc_url( plugins_url( 'assets/images/social-media/linkedin-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">LinkedIn</span></a>'
            . '<a href="https://x.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="X" title="X"><img src="' . esc_url( plugins_url( 'assets/images/social-media/x-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">X</span></a>'
            . '<a href="https://www.tiktok.com/@kitgenix" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok"><img src="' . esc_url( plugins_url( 'assets/images/social-media/tiktok-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">TikTok</span></a>'
            . '<a href="https://github.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="GitHub"><img src="' . esc_url( plugins_url( 'assets/images/social-media/github-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">GitHub</span></a>'
            . '</div>'
            . '</div>'
            . '<div class="kitgenix-hub-wrap">'
            . '<div class="kitgenix-hub">'
            . '<div class="kitgenix-hub-grid">';
        foreach ( $plugins as $p ) {
            $id        = (string) $p['id'];
            $file      = (string) $p['file'];
            $installed = isset( $plugins_data[ $file ] );
            $active    = false;
            if ( $installed && function_exists( 'is_plugin_active' ) ) {
                $active = is_plugin_active( $file ) || ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $file ) );
            }

            $version_badge = '';
            if ( $installed && ! empty( $plugins_data[ $file ]['Version'] ) ) {
                $version_badge = '<span class="kitgenix-badge muted">v' . esc_html( (string) $plugins_data[ $file ]['Version'] ) . '</span>';
            }

            $installs_badge = '';
            $slug = ! empty( $p['slug'] ) ? (string) $p['slug'] : '';
            if ( $slug && ! empty( $wporg_active_installs[ $slug ] ) ) {
                $count = (int) $wporg_active_installs[ $slug ];
                $count_text = function_exists( 'number_format_i18n' ) ? number_format_i18n( $count ) : (string) $count;
                /* translators: %s is the number of active installs and may include a thousands separator, e.g. "1,234". The "+" suffix is literal. */
                $installs_badge = '<span class="kitgenix-badge muted">' . esc_html( sprintf( __( '%s+ installs', 'kitgenix-document-manager' ), $count_text ) ) . '</span>';
            }

            $rating_badge = '';
            if ( $slug && isset( $wporg_ratings[ $slug ] ) && (int) $wporg_ratings[ $slug ] > 0 ) {
                $rating_percent = (int) $wporg_ratings[ $slug ];
                $stars = ( $rating_percent / 100 ) * 5;
                $stars_text = function_exists( 'number_format_i18n' ) ? number_format_i18n( $stars, 1 ) : number_format( $stars, 1 );
                /* translators: %s is the average rating out of 5 with one decimal place, e.g. "4.5". The star symbol (★) precedes the number. */
                $rating_badge = '<span class="kitgenix-badge muted">' . esc_html( sprintf( __( '★ %s/5', 'kitgenix-document-manager' ), $stars_text ) ) . '</span>';
            }

            $status_badge = '';
            if ( ! $installed ) {
                $status_badge = '<span class="kitgenix-badge muted">' . esc_html__( 'Not installed', 'kitgenix-document-manager' ) . '</span>';
            } elseif ( $active ) {
                $status_badge = '<span class="kitgenix-badge ok">' . esc_html__( 'Active', 'kitgenix-document-manager' ) . '</span>';
            } else {
                $status_badge = '<span class="kitgenix-badge warn">' . esc_html__( 'Installed (Inactive)', 'kitgenix-document-manager' ) . '</span>';
            }

            $card_media = '';
            if ( $slug && ! empty( $wporg_media[ $slug ]['url'] ) ) {
                $media_type = ( ! empty( $wporg_media[ $slug ]['type'] ) && 'banner' === (string) $wporg_media[ $slug ]['type'] ) ? 'banner' : 'icon';
                $card_media = '<div class="kitgenix-card-media kitgenix-card-media-' . esc_attr( $media_type ) . '"><img class="kitgenix-card-media-image" src="' . esc_url( (string) $wporg_media[ $slug ]['url'] ) . '" alt="" loading="lazy" /></div>';
            }

            $actions = '';
            if ( ! $installed ) {
                if ( current_user_can( 'install_plugins' ) ) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    $install_url = wp_nonce_url(
                        admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( (string) $p['slug'] ) ),
                        'install-plugin_' . (string) $p['slug']
                    );
                } else {
                    $install_url = admin_url( 'plugin-install.php?s=' . rawurlencode( 'kitgenix' ) . '&tab=search&type=term' );
                }
                $actions .= '<a class="button button-primary" href="' . esc_url( $install_url ) . '">' . esc_html__( 'Install', 'kitgenix-document-manager' ) . '</a>';
            } elseif ( ! $active ) {
                if ( current_user_can( 'activate_plugins' ) ) {
                    $activate_url = wp_nonce_url(
                        admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) . '&plugin_status=all&paged=1&s=' ),
                        'activate-plugin_' . $file
                    );
                    $actions .= '<a class="button button-primary" href="' . esc_url( $activate_url ) . '">' . esc_html__( 'Activate', 'kitgenix-document-manager' ) . '</a>';
                } else {
                    $actions .= '<span class="description">' . esc_html__( 'You do not have permission to activate plugins.', 'kitgenix-document-manager' ) . '</span>';
                }
            } else {
                $open = (string) ( $p['page'] ?? '' );
                $open_url = '';
                if ( $open ) {
                    if ( str_starts_with( $open, 'edit.php' ) || str_starts_with( $open, 'post-new.php' ) ) {
                        $open_url = admin_url( ltrim( $open, '/' ) );
                    } else {
                        $open_url = admin_url( 'admin.php?page=' . rawurlencode( $open ) );
                    }
                }
                if ( $open_url ) {
                    $actions .= '<a class="button button-primary" href="' . esc_url( $open_url ) . '">' . esc_html__( 'Open', 'kitgenix-document-manager' ) . '</a>';
                }
            }

            $info_url = admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( (string) $p['slug'] ) . '&TB_iframe=true&width=600&height=550' );
            $actions .= ' <a class="button button-secondary thickbox open-plugin-details-modal" href="' . esc_url( $info_url ) . '">' . esc_html__( 'Details', 'kitgenix-document-manager' ) . '</a>';
            if ( $slug ) {
                $review_url  = 'https://wordpress.org/support/plugin/' . rawurlencode( $slug ) . '/reviews/#new-post';
                $support_url = 'https://wordpress.org/support/plugin/' . rawurlencode( $slug ) . '/';
                $actions    .= ' <a class="button button-secondary" href="' . esc_url( $review_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Review', 'kitgenix-document-manager' ) . '</a>';
                $actions    .= ' <a class="button button-secondary" href="' . esc_url( $support_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support Forum', 'kitgenix-document-manager' ) . '</a>';
            }

            $allowed_html = [
                'a'    => [ 'class' => true, 'href' => true, 'target' => true, 'rel' => true ],
                'span' => [ 'class' => true ],
            ];
            $card_media_allowed_html = [
                'div' => [ 'class' => true ],
                'img' => [ 'class' => true, 'src' => true, 'alt' => true, 'loading' => true ],
            ];

            echo '<div class="kitgenix-card" data-kitgenix-plugin="' . esc_attr( sanitize_key( $id ) ) . '">'
                . wp_kses( $card_media, $card_media_allowed_html )
                . '<div class="kitgenix-card-body">'
                . '<div class="kitgenix-card-badges">' . wp_kses( trim( $status_badge . ' ' . $version_badge . ' ' . $rating_badge . ' ' . $installs_badge ), $allowed_html ) . '</div>'
                . '<p class="kitgenix-card-title">' . esc_html( (string) $p['name'] ) . '</p>'
                . '<p class="kitgenix-card-desc">' . esc_html( (string) $p['requires'] ) . '</p>'
                . '</div>'
                . '<div class="kitgenix-card-actions">' . wp_kses( $actions, $allowed_html ) . '</div>'
                . '</div>';
        }

        echo '</div>'; // .kitgenix-hub-grid

        echo '<div class="kitgenix-hub-feature-banner">'
            . '<div class="kitgenix-hub-feature-banner-copy">'
            . '<h2>' . esc_html__( 'Have an idea for a new feature?', 'kitgenix-document-manager' ) . '</h2>'
            . '<p>' . esc_html__( 'Browse the roadmap, vote on ideas, and submit feature requests for any Kitgenix plugin.', 'kitgenix-document-manager' ) . '</p>'
            . '</div>'
            . '<a class="button button-primary" href="' . esc_url( 'https://roadmap.kitgenix.com' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Request a Feature', 'kitgenix-document-manager' ) . '</a>'
            . '</div>';

        echo '</div></div></div>'; // .kitgenix-hub, .kitgenix-hub-wrap, .wrap
    }
}

if ( ! function_exists( 'kitgenix_document_manager_register_admin_ui_style' ) ) {
    function kitgenix_document_manager_register_admin_ui_style(): void {
        if ( ! is_admin() ) {
            return;
        }

        if ( function_exists( 'wp_style_is' ) && wp_style_is( 'kitgenix-document-manager-admin-ui', 'registered' ) ) {
            return;
        }

        $ver      = defined( 'KITGENIX_DOCUMENT_MANAGER_VERSION' ) ? (string) KITGENIX_DOCUMENT_MANAGER_VERSION : '2.0.0';
        $css_file = plugin_dir_path( __FILE__ ) . 'assets/css/kitgenix-admin-ui.css';
        $css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : $ver;

        wp_register_style( 'kitgenix-document-manager-admin-ui', plugins_url( 'assets/css/kitgenix-admin-ui.css', __FILE__ ), [], $css_ver );
    }
}
add_action( 'admin_enqueue_scripts', 'kitgenix_document_manager_register_admin_ui_style', 5 );

add_action( 'admin_enqueue_scripts', function ( string $hook_suffix ): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( 'kitgenix' !== $page && 'toplevel_page_kitgenix' !== $hook_suffix ) {
        return;
    }

    add_thickbox();
    wp_enqueue_style( 'plugin-install' );

    if ( function_exists( 'wp_style_is' ) && ( wp_style_is( 'kitgenix-hub', 'enqueued' ) || wp_style_is( 'kitgenix-hub', 'registered' ) ) ) {
        return;
    }

    $ver = defined( 'KITGENIX_DOCUMENT_MANAGER_VERSION' ) ? (string) KITGENIX_DOCUMENT_MANAGER_VERSION : '2.0.0';
    wp_register_style( 'kitgenix-hub', plugins_url( 'assets/css/kitgenix-hub.css', __FILE__ ), [], $ver );
    wp_enqueue_style( 'kitgenix-hub' );

    wp_register_style( 'kitgenix-document-manager-admin-ui', plugins_url( 'assets/css/kitgenix-admin-ui.css', __FILE__ ), [], $ver );
    wp_enqueue_style( 'kitgenix-document-manager-admin-ui' );
} );

// -----------------------------------------------------------------------------
// Constants (guarded)
// -----------------------------------------------------------------------------
if ( ! defined( 'KITGENIX_DOCUMENT_MANAGER_VERSION' ) ) {
    define( 'KITGENIX_DOCUMENT_MANAGER_VERSION', '2.1.0' );
}
if ( ! defined( 'KITGENIX_DOCUMENT_MANAGER_FILE' ) ) {
    define( 'KITGENIX_DOCUMENT_MANAGER_FILE', __FILE__ );
}
if ( ! defined( 'KITGENIX_DOCUMENT_MANAGER_DIR' ) ) {
    define( 'KITGENIX_DOCUMENT_MANAGER_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'KITGENIX_DOCUMENT_MANAGER_URL' ) ) {
    define( 'KITGENIX_DOCUMENT_MANAGER_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR' ) ) {
    define( 'KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR', KITGENIX_DOCUMENT_MANAGER_DIR . 'includes/' );
}
if ( ! defined( 'KITGENIX_DOCUMENT_MANAGER_ASSETS_URL' ) ) {
    define( 'KITGENIX_DOCUMENT_MANAGER_ASSETS_URL', KITGENIX_DOCUMENT_MANAGER_URL . 'assets/' ); // trailing slash
}
if ( ! defined( 'KITGENIX_DOCUMENT_MANAGER_TEXT_DOMAIN' ) ) {
    define( 'KITGENIX_DOCUMENT_MANAGER_TEXT_DOMAIN', 'kitgenix-document-manager' );
}
if ( ! defined( 'KITGENIX_DOCUMENT_MANAGER_OPTION_NAME' ) ) {
    define( 'KITGENIX_DOCUMENT_MANAGER_OPTION_NAME', 'kitgenix_document_manager_settings' );
}

// -----------------------------------------------------------------------------
// Requires
// -----------------------------------------------------------------------------
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'core/helpers.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'core/class-event-log.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'core/class-capabilities.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'core/class-cpt.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'core/class-uploader.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'core/class-endpoint.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'core/class-attachment-guard.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'core/class-lifecycle.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'core/class-script-handler.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'core/class-shortcode.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'core/class-rest.php';

require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'admin/class-admin-menu.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'admin/class-admin-options.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'admin/class-admin-columns.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'admin/class-document-metabox.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'admin/class-settings-ui.php';
require_once KITGENIX_DOCUMENT_MANAGER_INCLUDES_DIR . 'admin/class-site-health.php';

// -----------------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------------
add_action( 'plugins_loaded', static function (): void {
    // Translations are loaded automatically for plugins hosted on WordPress.org;
    // explicit load_plugin_textdomain() is discouraged and not required.

    \KitgenixDocumentManager\Core\Capabilities::init();
    \KitgenixDocumentManager\Core\CPT::init();
    \KitgenixDocumentManager\Core\Endpoint::init();
    \KitgenixDocumentManager\Core\Attachment_Guard::init();
    \KitgenixDocumentManager\Core\Lifecycle::init();
    \KitgenixDocumentManager\Core\Event_Log::init();
    \KitgenixDocumentManager\Core\Script_Handler::init();
    \KitgenixDocumentManager\Core\Shortcode::init();
    \KitgenixDocumentManager\Core\Rest::init();

    kitgenix_document_manager_maybe_upgrade();

    if ( is_admin() ) {
        // Ensure caps exist even after plugin updates.
        \KitgenixDocumentManager\Core\Capabilities::add_caps();
        \KitgenixDocumentManager\Admin\Admin_Options::init();
        \KitgenixDocumentManager\Admin\Settings_UI::init();
        \KitgenixDocumentManager\Admin\Site_Health::init();
    }
} );

// -----------------------------------------------------------------------------
// Idempotent upgrade routine (1.x -> 2.0.0 and beyond)
// -----------------------------------------------------------------------------
if ( ! function_exists( 'kitgenix_document_manager_maybe_upgrade' ) ) {
    /**
     * Runs on every request (cheap: a single option read) and only performs
     * work when the stored data-shape version is behind the code's version.
     * Safe to run repeatedly; never deletes documents, versions, or files.
     */
    function kitgenix_document_manager_maybe_upgrade(): void {
        $option_key = 'kitgenix_document_manager_db_version';
        $current    = (string) get_option( $option_key, '' );
        $target     = \KitgenixDocumentManager\Core\kitgenix_document_manager_db_version();

        if ( $current === $target ) {
            return;
        }

        // 1.x installs never wrote this option – treat empty as "pre-2.0.0".
        \KitgenixDocumentManager\Core\CPT::migrate_legacy_posts();
        \KitgenixDocumentManager\Core\kitgenix_document_manager_ensure_upload_dir();
        \KitgenixDocumentManager\Core\Lifecycle::schedule();
        \KitgenixDocumentManager\Core\Event_Log::schedule();

        update_option( $option_key, $target, false );
    }
}

// -----------------------------------------------------------------------------
// Activation / Deactivation
// -----------------------------------------------------------------------------
register_activation_hook( __FILE__, static function (): void {
    \KitgenixDocumentManager\Core\CPT::register_post_type();
    \KitgenixDocumentManager\Core\CPT::migrate_legacy_posts();
    \KitgenixDocumentManager\Core\Endpoint::add_rewrite_rules();
    \KitgenixDocumentManager\Core\Capabilities::add_caps();
    \KitgenixDocumentManager\Core\kitgenix_document_manager_ensure_upload_dir();
    \KitgenixDocumentManager\Core\Lifecycle::schedule();
    \KitgenixDocumentManager\Core\Event_Log::schedule();

    update_option( 'kitgenix_document_manager_db_version', \KitgenixDocumentManager\Core\kitgenix_document_manager_db_version(), false );

    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, static function (): void {
    \KitgenixDocumentManager\Core\Lifecycle::unschedule();
    \KitgenixDocumentManager\Core\Event_Log::unschedule();
    flush_rewrite_rules( false );
} );
