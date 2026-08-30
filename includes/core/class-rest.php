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
            return self::response_no_store( [ 'code' => 'not_found' ], 404 );
        }

        $document_id = (int) $post->ID;
        $attachment_id = (int) get_post_meta( $document_id, '_kitgenix_document_manager_current_attachment_id', true );
        if ( $attachment_id <= 0 ) {
            return self::response_no_store( [ 'code' => 'not_found' ], 404 );
        }

        $access = kitgenix_document_manager_evaluate_document_access(
            $document_id,
            [
                'surface' => 'listing',
            ]
        );

        if ( empty( $access['allowed'] ) ) {
            return self::response_no_store(
                [
                    'code'   => (string) ( $access['reason'] ?? 'forbidden' ),
                    'status' => (string) ( $access['schedule']['status'] ?? '' ),
                ],
                (int) ( $access['http_status'] ?? 403 )
            );
        }

        $visibility = (string) ( $access['visibility'] ?? kitgenix_document_manager_get_document_visibility( $document_id ) );
        $lifecycle  = kitgenix_document_manager_get_document_lifecycle_state( $document_id );

        $data = [
            'slug'            => $slug,
            'title'           => get_the_title( $post ),
            'updated'         => get_post_modified_time( 'c', true, $post ),
            'visibility'      => $visibility,
            'schedule_status' => (string) ( $access['schedule']['status'] ?? 'active' ),
            'lifecycle_state' => (string) ( $lifecycle['state'] ?? '' ),
            'stable_url'      => kitgenix_document_manager_stable_url( $slug ),
        ];

        /**
         * Filters the REST metadata payload for `GET /kitgenix-document-manager/v1/doc/{slug}`.
         * Runs only after the built-in access check has already allowed the request.
         *
         * @param array            $data    Response payload.
         * @param \WP_Post         $post    Document post.
         * @param array            $access  Access-decision array.
         * @param \WP_REST_Request $request The current request.
         */
        $data = apply_filters( 'kitgenix_document_manager_rest_document_response', $data, $post, $access, $request );

        $response = new \WP_REST_Response( $data, 200 );

        // Public documents may be safely cached by intermediaries; anything
        // reached only via a signed link or granular access rule must not be.
        if ( $visibility !== 'public' || ! empty( $access['is_signed_request'] ) ) {
            $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
        }

        return $response;
    }

    private static function response_no_store( array $data, int $status ): \WP_REST_Response {
        $response = new \WP_REST_Response( $data, $status );
        $response->header( 'Cache-Control', 'private, no-store, max-age=0' );

        return $response;
    }
}
