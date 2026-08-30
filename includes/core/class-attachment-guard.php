<?php
namespace KitgenixDocumentManager\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents a document's underlying attachment from being reachable through
 * WordPress-core surfaces (REST API media endpoints, attachment permalinks)
 * when the document itself is not publicly accessible.
 *
 * Background: uploading/attaching a file via wp_insert_attachment() makes it
 * a normal Media Library attachment. Because the parent document CPT post is
 * published whenever it is "live", WordPress core treats the attachment as
 * publicly readable by default – regardless of this plugin's own visibility,
 * access-rule, or lifecycle checks. Left alone, that means a Private or
 * Restricted (or scheduled/archived/expired) document's file could still be
 * discovered and fetched directly via `/wp-json/wp/v2/media/{id}` or the
 * attachment's own permalink, completely bypassing the stable-link access
 * control in Endpoint. This class closes that gap without moving any files,
 * changing any URLs, or altering stored data.
 */
final class Attachment_Guard {

    public static function init(): void {
        add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'block_single_attachment_rest_request' ], 10, 3 );
        add_filter( 'rest_attachment_query', [ __CLASS__, 'exclude_inaccessible_attachments_from_query' ], 10, 2 );
        add_filter( 'rest_prepare_attachment', [ __CLASS__, 'strip_sensitive_attachment_fields' ], 10, 2 );
        add_action( 'template_redirect', [ __CLASS__, 'block_attachment_permalink' ], 1 );
    }

    /**
     * Short-circuit `GET /wp/v2/media/{id}` (and similar single-item routes)
     * before WordPress builds a response, so a denied request never reaches
     * the normal item-preparation code path.
     *
     * @param mixed            $response
     * @param array            $handler
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public static function block_single_attachment_rest_request( $response, $handler, $request ) {
        if ( is_wp_error( $response ) || null !== $response ) {
            return $response;
        }

        if ( ! is_object( $request ) || ! method_exists( $request, 'get_method' ) ) {
            return $response;
        }

        if ( 'GET' !== strtoupper( (string) $request->get_method() ) ) {
            return $response;
        }

        $route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
        if ( ! preg_match( '#/wp/v2/media/(\d+)(?:/[a-z\-]+)?$#', $route, $matches ) ) {
            return $response;
        }

        $attachment_id = (int) $matches[1];
        if ( $attachment_id <= 0 || ! self::request_denied_for_attachment( $attachment_id ) ) {
            return $response;
        }

        return new \WP_Error(
            'kitgenix_document_manager_forbidden',
            __( 'Sorry, you are not allowed to access this file.', 'kitgenix-document-manager' ),
            [ 'status' => 403 ]
        );
    }

    /**
     * Exclude attachments tied to a document the current viewer cannot
     * access from `/wp/v2/media` collection queries.
     *
     * @param array             $args
     * @param \WP_REST_Request  $request
     * @return array
     */
    public static function exclude_inaccessible_attachments_from_query( array $args, $request ): array {
        $blocked_parents = self::get_inaccessible_document_ids_for_viewer();
        if ( empty( $blocked_parents ) ) {
            return $args;
        }

        $existing = isset( $args['post_parent__not_in'] ) && is_array( $args['post_parent__not_in'] ) ? $args['post_parent__not_in'] : [];
        $args['post_parent__not_in'] = array_values( array_unique( array_merge( $existing, $blocked_parents ) ) );

        return $args;
    }

    /**
     * Defense-in-depth: strip file-revealing fields from any attachment
     * response that still slips through (e.g. embedded in another
     * controller's response) for a document the viewer cannot access.
     *
     * @param \WP_REST_Response $response
     * @param \WP_Post          $attachment
     * @return \WP_REST_Response
     */
    public static function strip_sensitive_attachment_fields( $response, $attachment ) {
        if ( ! ( $response instanceof \WP_REST_Response ) || ! ( $attachment instanceof \WP_Post ) ) {
            return $response;
        }

        if ( ! self::request_denied_for_attachment( (int) $attachment->ID ) ) {
            return $response;
        }

        $data = $response->get_data();
        if ( ! is_array( $data ) ) {
            return $response;
        }

        unset( $data['source_url'], $data['media_details'], $data['caption'], $data['description'] );
        if ( isset( $data['guid'] ) && is_array( $data['guid'] ) ) {
            $data['guid']['rendered'] = '';
        }
        foreach ( [ '_links' ] as $key ) {
            unset( $data[ $key ] );
        }

        $response->set_data( $data );

        return $response;
    }

    /**
     * Prevent the classic attachment permalink (`?attachment_id=` or its
     * pretty-permalink form) from exposing a protected document's file.
     */
    public static function block_attachment_permalink(): void {
        if ( ! function_exists( 'is_attachment' ) || ! is_attachment() ) {
            return;
        }

        $attachment_id = (int) get_queried_object_id();
        if ( $attachment_id <= 0 || ! self::request_denied_for_attachment( $attachment_id ) ) {
            return;
        }

        status_header( 404 );
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        include get_query_template( '404' );
        exit;
    }

    /**
     * @return bool True when this attachment belongs to a document the
     *              current request/viewer is not allowed to access.
     */
    private static function request_denied_for_attachment( int $attachment_id ): bool {
        $document_id = kitgenix_document_manager_attachment_document_id( $attachment_id );
        if ( $document_id <= 0 ) {
            return false;
        }

        $access = kitgenix_document_manager_evaluate_document_access(
            $document_id,
            [ 'surface' => 'listing' ]
        );

        return empty( $access['allowed'] );
    }

    /**
     * Bounded, request-scoped (never cached across users) lookup of document
     * IDs the current viewer cannot access, limited to documents that carry
     * any non-public/schedule signal so the query stays small.
     *
     * @return int[]
     */
    private static function get_inaccessible_document_ids_for_viewer(): array {
        static $cache = null;
        if ( is_array( $cache ) ) {
            return $cache;
        }

        $settings = kitgenix_document_manager_get_settings();
        $query_args = [
            'post_type'        => CPT::POST_TYPE,
            'post_status'      => 'publish',
            'fields'           => 'ids',
            'posts_per_page'   => 2000,
            'no_found_rows'    => true,
            'cache_results'    => true,
            'suppress_filters' => false,
        ];

        // A document with no explicit per-document visibility meta falls back
        // to this site-wide default (see kitgenix_document_manager_get_document_visibility()).
        // If that default is not "public", a plain `!=` meta comparison would
        // miss documents relying on the default (SQL NULL doesn't match `!=`),
        // so widen to every published document rather than risk under-matching.
        if ( ( $settings['default_visibility'] ?? 'public' ) === 'public' ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- No taxonomy alternative exists for these per-document access-control meta keys; result is cached per-request (see static $cache above) and bounded to 2000 posts.
            $query_args['meta_query'] = [
                'relation' => 'OR',
                [ 'key' => '_kitgenix_document_manager_visibility', 'value' => 'public', 'compare' => '!=' ],
                [ 'key' => '_kitgenix_document_manager_access_roles', 'compare' => 'EXISTS' ],
                [ 'key' => '_kitgenix_document_manager_access_capability', 'compare' => 'EXISTS' ],
                [ 'key' => '_kitgenix_document_manager_access_user_ids', 'compare' => 'EXISTS' ],
                [ 'key' => '_kitgenix_document_manager_available_from_gmt', 'compare' => 'EXISTS' ],
                [ 'key' => '_kitgenix_document_manager_archive_at_gmt', 'compare' => 'EXISTS' ],
                [ 'key' => '_kitgenix_document_manager_expires_at_gmt', 'compare' => 'EXISTS' ],
            ];
        }

        $candidate_ids = get_posts( $query_args );

        $blocked = [];
        if ( is_array( $candidate_ids ) ) {
            foreach ( $candidate_ids as $id ) {
                $id = (int) $id;
                $access = kitgenix_document_manager_evaluate_document_access( $id, [ 'surface' => 'listing' ] );
                if ( empty( $access['allowed'] ) ) {
                    $blocked[] = $id;
                }
            }
        }

        $cache = $blocked;

        return $cache;
    }
}
