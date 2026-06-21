<?php
namespace KitgenixDocumentManager\Core;

defined( 'ABSPATH' ) || exit;

final class CPT {

    // NOTE: WordPress stores post_type in a VARCHAR(20). Keep this <= 20 chars.
    public const POST_TYPE = 'kitgenix_dm_doc';

    // Hierarchical taxonomy for grouping documents.
    public const TAXONOMY_CATEGORY = 'kitgenix_dm_cat';

    // Legacy (too-long) slug from early development builds.
    public const LEGACY_POST_TYPE = 'kitgenix_document_manager_document';

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );
    }

    public static function register_post_type(): void {
        $labels = [
            'name'               => __( 'Kitgenix Docs', 'kitgenix-document-manager' ),
            'singular_name'      => __( 'Kitgenix Doc', 'kitgenix-document-manager' ),
            'menu_name'          => __( 'Kitgenix Docs', 'kitgenix-document-manager' ),
            'name_admin_bar'     => __( 'Kitgenix Doc', 'kitgenix-document-manager' ),
            'add_new'            => __( 'Add New', 'kitgenix-document-manager' ),
            'add_new_item'       => __( 'Add New Document', 'kitgenix-document-manager' ),
            'new_item'           => __( 'New Document', 'kitgenix-document-manager' ),
            'edit_item'          => __( 'Edit Document', 'kitgenix-document-manager' ),
            'view_item'          => __( 'View Document', 'kitgenix-document-manager' ),
            'all_items'          => __( 'Kitgenix Docs', 'kitgenix-document-manager' ),
            'search_items'       => __( 'Search Documents', 'kitgenix-document-manager' ),
            'not_found'          => __( 'No documents found.', 'kitgenix-document-manager' ),
            'not_found_in_trash' => __( 'No documents found in Trash.', 'kitgenix-document-manager' ),
        ];

        $caps = [
            'edit_post'              => Capabilities::CAPABILITY,
            'read_post'              => Capabilities::CAPABILITY,
            'delete_post'            => Capabilities::CAPABILITY,
            'edit_posts'             => Capabilities::CAPABILITY,
            'edit_others_posts'      => Capabilities::CAPABILITY,
            'publish_posts'          => Capabilities::CAPABILITY,
            'read_private_posts'     => Capabilities::CAPABILITY,
            'delete_posts'           => Capabilities::CAPABILITY,
            'delete_private_posts'   => Capabilities::CAPABILITY,
            'delete_published_posts' => Capabilities::CAPABILITY,
            'delete_others_posts'    => Capabilities::CAPABILITY,
            'edit_private_posts'     => Capabilities::CAPABILITY,
            'edit_published_posts'   => Capabilities::CAPABILITY,
            'create_posts'           => Capabilities::CAPABILITY,
        ];

        register_post_type(
            self::POST_TYPE,
            [
                'labels'              => $labels,
                'public'              => false,
                'publicly_queryable'  => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'show_in_admin_bar'   => false,
                'show_in_nav_menus'   => false,
                'show_in_rest'        => false,
                'has_archive'         => false,
                'rewrite'             => false,
                'supports'            => [ 'title' ],
                'capability_type'     => [ 'kitgenix_document', 'kitgenix_documents' ],
                'capabilities'        => $caps,
                'map_meta_cap'        => true,
                'menu_icon'           => 'dashicons-media-document',
            ]
        );

        // Categories taxonomy (managed via the plugin UI).
        $tax_labels = [
            'name'              => __( 'Document Categories', 'kitgenix-document-manager' ),
            'singular_name'     => __( 'Document Category', 'kitgenix-document-manager' ),
            'search_items'      => __( 'Search Categories', 'kitgenix-document-manager' ),
            'all_items'         => __( 'All Categories', 'kitgenix-document-manager' ),
            'parent_item'       => __( 'Parent Category', 'kitgenix-document-manager' ),
            'parent_item_colon' => __( 'Parent Category:', 'kitgenix-document-manager' ),
            'edit_item'         => __( 'Edit Category', 'kitgenix-document-manager' ),
            'update_item'       => __( 'Update Category', 'kitgenix-document-manager' ),
            'add_new_item'      => __( 'Add New Category', 'kitgenix-document-manager' ),
            'new_item_name'     => __( 'New Category Name', 'kitgenix-document-manager' ),
            'menu_name'         => __( 'Categories', 'kitgenix-document-manager' ),
        ];

        register_taxonomy(
            self::TAXONOMY_CATEGORY,
            [ self::POST_TYPE ],
            [
                'hierarchical'      => true,
                'labels'            => $tax_labels,
                'public'            => false,
                'show_ui'           => false,
                'show_admin_column' => false,
                'show_in_nav_menus' => false,
                'show_tagcloud'     => false,
                'show_in_rest'      => false,
                'rewrite'           => false,
                'query_var'         => false,
                'capabilities'      => [
                    'manage_terms' => Capabilities::CAPABILITY,
                    'edit_terms'   => Capabilities::CAPABILITY,
                    'delete_terms' => Capabilities::CAPABILITY,
                    'assign_terms' => Capabilities::CAPABILITY,
                ],
            ]
        );
    }

    /**
     * Best-effort migration for any legacy posts (if they exist).
     * Safe to call multiple times.
     */
    public static function migrate_legacy_posts(): void {
        // Best-effort migration for legacy posts. Use WP APIs rather than
        // direct $wpdb queries to satisfy static analysis and ensure hooks
        // and caches behave correctly. We paginate to avoid memory spikes.
        $per_page = 100;
        $paged    = 1;

        do {
            $query = new \WP_Query(
                [
                    'post_type'      => self::LEGACY_POST_TYPE,
                    'posts_per_page' => $per_page,
                    'paged'          => $paged,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ]
            );

            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $post_id ) {
                wp_update_post(
                    [
                        'ID'        => (int) $post_id,
                        'post_type' => self::POST_TYPE,
                    ]
                );
            }

            $paged++;
            wp_reset_postdata();
        } while ( count( $query->posts ) === $per_page );
    }
}
