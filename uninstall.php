<?php
/**
 * Uninstall Kitgenix Document Manager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$kitgenix_dm_opt_name = defined( 'KITGENIX_DOCUMENT_MANAGER_OPTION_NAME' ) ? (string) KITGENIX_DOCUMENT_MANAGER_OPTION_NAME : 'kitgenix_document_manager_settings';
$kitgenix_dm_settings = get_option( $kitgenix_dm_opt_name, [] );
$kitgenix_dm_settings = is_array( $kitgenix_dm_settings ) ? $kitgenix_dm_settings : [];

$kitgenix_dm_delete_data = ! empty( $kitgenix_dm_settings['delete_data_on_uninstall'] );

delete_option( $kitgenix_dm_opt_name );
delete_option( 'kitgenix_document_manager_event_log' );

delete_transient( 'kitgenix_document_manager_site_health' );

if ( ! $kitgenix_dm_delete_data ) {
    return;
}

$post_type = 'kitgenix_dm_doc';

$posts = get_posts(
    [
        'post_type'      => $post_type,
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
    ]
);

if ( is_array( $posts ) ) {
    foreach ( $posts as $kitgenix_dm_pid ) {
        $kitgenix_dm_pid_int = (int) $kitgenix_dm_pid;
        if ( $kitgenix_dm_pid_int <= 0 ) {
            continue;
        }

        $kitgenix_dm_current = (int) get_post_meta( $kitgenix_dm_pid_int, '_kitgenix_document_manager_current_attachment_id', true );
        if ( $kitgenix_dm_current > 0 ) {
            wp_delete_attachment( $kitgenix_dm_current, true );
        }

        $kitgenix_dm_versions = get_post_meta( $kitgenix_dm_pid_int, '_kitgenix_document_manager_versions', true );
        $kitgenix_dm_versions = is_array( $kitgenix_dm_versions ) ? $kitgenix_dm_versions : [];
        foreach ( $kitgenix_dm_versions as $kitgenix_dm_vid ) {
            $kitgenix_dm_vid_int = (int) $kitgenix_dm_vid;
            if ( $kitgenix_dm_vid_int > 0 ) {
                wp_delete_attachment( $kitgenix_dm_vid_int, true );
            }
        }

        wp_delete_post( $kitgenix_dm_pid_int, true );
    }
}
