<?php
namespace KitgenixDocumentManager\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Watches scheduled documents (go-live, archive, expiry) and fires a
 * developer hook the first time a document's effective lifecycle state
 * changes, so integrations can react to a document going live/archived/
 * expired without polling. Runs on an hourly cron so transitions are caught
 * even when nobody visits the stable link at the moment they occur.
 */
final class Lifecycle {

    public const CRON_HOOK = 'kitgenix_document_manager_lifecycle_check';

    /** Bound the amount of work a single cron run can do. */
    private const MAX_DOCUMENTS_PER_RUN = 500;

    public static function init(): void {
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_check' ] );
    }

    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
        }
    }

    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function run_check(): void {
        $ids = get_posts(
            [
                'post_type'        => CPT::POST_TYPE,
                'post_status'      => 'publish',
                'fields'           => 'ids',
                'posts_per_page'   => self::MAX_DOCUMENTS_PER_RUN,
                'no_found_rows'    => true,
                'cache_results'    => false,
                'suppress_filters' => false,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- No taxonomy alternative exists for these lifecycle-scheduling meta keys; runs on an hourly cron (not a page view) and bounded to MAX_DOCUMENTS_PER_RUN (500) posts.
                'meta_query'       => [
                    'relation' => 'OR',
                    [ 'key' => '_kitgenix_document_manager_available_from_gmt', 'compare' => 'EXISTS' ],
                    [ 'key' => '_kitgenix_document_manager_archive_at_gmt', 'compare' => 'EXISTS' ],
                    [ 'key' => '_kitgenix_document_manager_expires_at_gmt', 'compare' => 'EXISTS' ],
                ],
            ]
        );

        if ( ! is_array( $ids ) ) {
            return;
        }

        foreach ( $ids as $document_id ) {
            self::maybe_record_transition( (int) $document_id );
        }
    }

    public static function maybe_record_transition( int $document_id ): void {
        if ( $document_id <= 0 ) {
            return;
        }

        $state = kitgenix_document_manager_get_document_lifecycle_state( $document_id );
        $new_state = (string) ( $state['state'] ?? '' );
        if ( $new_state === '' ) {
            return;
        }

        $meta_key  = '_kitgenix_document_manager_last_lifecycle_state';
        $old_state = (string) get_post_meta( $document_id, $meta_key, true );

        if ( $old_state === $new_state ) {
            return;
        }

        update_post_meta( $document_id, $meta_key, $new_state );

        if ( $old_state === '' ) {
            // First observation – nothing to compare against, don't fire noise on install/migration.
            return;
        }

        /**
         * Fires when a document's effective lifecycle state changes
         * (e.g. scheduled -> live, live -> archived, live -> expired).
         *
         * @param int    $document_id Document post ID.
         * @param string $old_state   Previous state slug.
         * @param string $new_state   New state slug.
         */
        do_action( 'kitgenix_document_manager_lifecycle_status_changed', $document_id, $old_state, $new_state );

        Event_Log::record(
            'lifecycle-transition',
            'success',
            sprintf(
                /* translators: 1: previous lifecycle state, 2: new lifecycle state */
                __( 'A document moved from "%1$s" to "%2$s".', 'kitgenix-document-manager' ),
                $old_state,
                $new_state
            )
        );
    }
}
