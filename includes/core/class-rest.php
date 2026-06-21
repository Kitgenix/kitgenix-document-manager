<?php
namespace KitgenixDocumentManager\Core;

defined( 'ABSPATH' ) || exit;

final class Rest {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes(): void {
        register_rest_route(
            'kitgenix-document-manager/v1',
            '/doc/(?P<slug>[a-z0-9\-]+)',
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_doc' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'slug' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_title_with_dashes',
                    ],
                ],
            ]
        );
    }

    public static function get_doc( \WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $slug = sanitize_title_with_dashes( $slug );

        $post = get_page_by_path( $slug, OBJECT, CPT::POST_TYPE );
        if ( ! ( $post instanceof \WP_Post ) || $post->post_status !== 'publish' ) {
            return new \WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }

        $document_id = (int) $post->ID;
        $attachment_id = (int) get_post_meta( $document_id, '_kitgenix_document_manager_current_attachment_id', true );
        if ( $attachment_id <= 0 ) {
            return new \WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }

        $access = kitgenix_document_manager_evaluate_document_access(
            $document_id,
            [
                'surface' => 'listing',
            ]
        );

        if ( empty( $access['allowed'] ) ) {
            return new \WP_REST_Response(
                [
                    'code'   => (string) ( $access['reason'] ?? 'forbidden' ),
                    'status' => (string) ( $access['schedule']['status'] ?? '' ),
                ],
                (int) ( $access['http_status'] ?? 403 )
            );
        }

        return new \WP_REST_Response(
            [
                'slug'            => $slug,
                'title'           => get_the_title( $post ),
                'updated'         => get_post_modified_time( 'c', true, $post ),
                'visibility'      => (string) ( $access['visibility'] ?? kitgenix_document_manager_get_document_visibility( $document_id ) ),
                'schedule_status' => (string) ( $access['schedule']['status'] ?? 'active' ),
                'stable_url'      => kitgenix_document_manager_stable_url( $slug ),
            ],
            200
        );
    }
}
