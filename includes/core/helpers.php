<?php
namespace KitgenixDocumentManager\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults for KITGENIX_DOCUMENT_MANAGER_OPTION_NAME.
 */
function kitgenix_document_manager_default_settings(): array {
    return [
        'allowed_extensions'       => [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt' ],
        'default_visibility'       => 'public', // public|private
        'serving_mode'             => 'attachment', // attachment|inline
        'keep_version_history'     => true,
        'delete_old_versions'      => false,
        'private_behavior'         => 'login', // login|403
        'delete_data_on_uninstall' => false,

        // Frontend (document card shortcode) defaults.
        'card_button_label'        => 'View',
        'card_button_style'        => 'outline', // outline|solid
        'card_open_new_tab'        => false,
        'card_show_file_type'      => true,
        'card_show_file_size'      => true,
        'card_show_description'    => false,

        'card_image_size'          => 96, // px
        'card_meta_alignment'      => 'justify', // justify|left|center|right
        'card_button_alignment'    => 'center', // justify|left|center|right

        // Button colors (hex; blank = use theme defaults).
        'card_button_text_color'        => '',
        'card_button_bg_color'          => '',
        'card_button_border_color'      => '',
        'card_button_hover_text_color'  => '',
        'card_button_hover_bg_color'    => '',
        'card_button_hover_border_color'=> '',
    ];
}

/**
 * DB schema/data-shape version for idempotent upgrade routines. Bump this
 * whenever an upgrade routine needs to run again for existing installs.
 */
function kitgenix_document_manager_db_version(): string {
    return '2.0.0';
}

function kitgenix_document_manager_get_settings(): array {
    $defaults = kitgenix_document_manager_default_settings();
    $opt_name = defined( 'KITGENIX_DOCUMENT_MANAGER_OPTION_NAME' ) ? (string) KITGENIX_DOCUMENT_MANAGER_OPTION_NAME : '';
    $stored   = $opt_name ? get_option( $opt_name, [] ) : [];
    $stored   = is_array( $stored ) ? $stored : [];

    $settings = array_merge( $defaults, $stored );

    $settings['allowed_extensions'] = kitgenix_document_manager_normalize_extensions( $settings['allowed_extensions'] ?? [] );
    $settings['default_visibility'] = ( $settings['default_visibility'] === 'private' ) ? 'private' : 'public';
    $settings['serving_mode']       = ( $settings['serving_mode'] === 'inline' ) ? 'inline' : 'attachment';
    $settings['private_behavior']   = ( $settings['private_behavior'] === '403' ) ? '403' : 'login';

    $settings['keep_version_history'] = (bool) $settings['keep_version_history'];
    $settings['delete_old_versions']  = (bool) $settings['delete_old_versions'];
    $settings['delete_data_on_uninstall'] = (bool) $settings['delete_data_on_uninstall'];

    $settings['card_button_label']   = sanitize_text_field( (string) ( $settings['card_button_label'] ?? 'View' ) );
    $settings['card_button_style']   = (string) ( $settings['card_button_style'] ?? 'outline' );
    $settings['card_button_style']   = ( $settings['card_button_style'] === 'solid' ) ? 'solid' : 'outline';
    $settings['card_open_new_tab']   = (bool) ( $settings['card_open_new_tab'] ?? false );
    $settings['card_show_file_type'] = (bool) ( $settings['card_show_file_type'] ?? true );
    $settings['card_show_file_size'] = (bool) ( $settings['card_show_file_size'] ?? true );
    $settings['card_show_description'] = (bool) ( $settings['card_show_description'] ?? false );

    $settings['card_image_size'] = absint( $settings['card_image_size'] ?? 96 );
    if ( $settings['card_image_size'] < 32 || $settings['card_image_size'] > 256 ) {
        $settings['card_image_size'] = 96;
    }

    $settings['card_meta_alignment'] = sanitize_key( (string) ( $settings['card_meta_alignment'] ?? 'justify' ) );
    if ( ! in_array( $settings['card_meta_alignment'], [ 'justify', 'left', 'center', 'right' ], true ) ) {
        $settings['card_meta_alignment'] = 'justify';
    }

    $settings['card_button_alignment'] = sanitize_key( (string) ( $settings['card_button_alignment'] ?? 'center' ) );
    if ( ! in_array( $settings['card_button_alignment'], [ 'justify', 'left', 'center', 'right' ], true ) ) {
        $settings['card_button_alignment'] = 'center';
    }

    $hex_fields = [
        'card_button_text_color',
        'card_button_bg_color',
        'card_button_border_color',
        'card_button_hover_text_color',
        'card_button_hover_bg_color',
        'card_button_hover_border_color',
    ];
    foreach ( $hex_fields as $key ) {
        $raw = (string) ( $settings[ $key ] ?? '' );
        $raw = trim( $raw );
        if ( $raw === '' ) {
            $settings[ $key ] = '';
            continue;
        }
        if ( function_exists( 'sanitize_hex_color' ) ) {
            $clean = sanitize_hex_color( $raw );
            $settings[ $key ] = $clean ? (string) $clean : '';
        } else {
            $settings[ $key ] = '';
        }
    }

    return $settings;
}

function kitgenix_document_manager_normalize_extensions( $exts ): array {
    if ( is_string( $exts ) ) {
        $exts = preg_split( '/\s*,\s*/', trim( $exts ), -1, PREG_SPLIT_NO_EMPTY );
    }
    $exts = is_array( $exts ) ? $exts : [];

    $clean = [];
    foreach ( $exts as $ext ) {
        $ext = strtolower( trim( (string) $ext ) );
        $ext = ltrim( $ext, '.' );
        $ext = preg_replace( '/[^a-z0-9]/', '', $ext );
        if ( $ext === '' ) {
            continue;
        }
        $clean[] = $ext;
    }

    $clean = array_values( array_unique( $clean ) );
    sort( $clean );

    return $clean;
}

function kitgenix_document_manager_normalize_visibility( string $visibility ): string {
    $visibility = sanitize_key( $visibility );

    if ( in_array( $visibility, [ 'public', 'private', 'restricted' ], true ) ) {
        return $visibility;
    }

    return 'public';
}

function kitgenix_document_manager_sanitize_user_ids( $user_ids ): array {
    if ( is_string( $user_ids ) ) {
        $user_ids = preg_split( '/\s*,\s*/', trim( $user_ids ), -1, PREG_SPLIT_NO_EMPTY );
    }

    $user_ids = is_array( $user_ids ) ? $user_ids : [];
    $clean    = [];

    foreach ( $user_ids as $user_id ) {
        $user_id = absint( $user_id );
        if ( $user_id > 0 ) {
            $clean[] = $user_id;
        }
    }

    return array_values( array_unique( $clean ) );
}

function kitgenix_document_manager_sanitize_roles( $roles ): array {
    if ( is_string( $roles ) ) {
        $roles = preg_split( '/\s*,\s*/', trim( $roles ), -1, PREG_SPLIT_NO_EMPTY );
    }

    $roles = is_array( $roles ) ? $roles : [];
    $clean = [];

    $wp_roles      = function_exists( 'wp_roles' ) ? wp_roles() : null;
    $allowed_roles = ( $wp_roles instanceof \WP_Roles ) ? array_keys( $wp_roles->roles ) : [];

    foreach ( $roles as $role ) {
        $role = sanitize_key( (string) $role );
        if ( $role === '' ) {
            continue;
        }
        if ( ! empty( $allowed_roles ) && ! in_array( $role, $allowed_roles, true ) ) {
            continue;
        }
        $clean[] = $role;
    }

    return array_values( array_unique( $clean ) );
}

function kitgenix_document_manager_local_datetime_to_gmt( string $datetime ): string {
    $datetime = trim( str_replace( 'T', ' ', $datetime ) );
    if ( $datetime === '' ) {
        return '';
    }

    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $datetime ) ) {
        return '';
    }

    try {
        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
        $date     = new \DateTimeImmutable( $datetime, $timezone );
    } catch ( \Exception $exception ) {
        return '';
    }

    return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
}

function kitgenix_document_manager_gmt_to_local_datetime_input( string $datetime_gmt ): string {
    $datetime_gmt = trim( $datetime_gmt );
    if ( $datetime_gmt === '' ) {
        return '';
    }

    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $datetime_gmt ) ) {
        return '';
    }

    try {
        $date = new \DateTimeImmutable( $datetime_gmt, new \DateTimeZone( 'UTC' ) );
    } catch ( \Exception $exception ) {
        return '';
    }

    $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );

    return $date->setTimezone( $timezone )->format( 'Y-m-d\TH:i' );
}

function kitgenix_document_manager_get_document_allowed_roles( int $post_id ): array {
    return kitgenix_document_manager_sanitize_roles( get_post_meta( $post_id, '_kitgenix_document_manager_access_roles', true ) );
}

function kitgenix_document_manager_get_document_required_capability( int $post_id ): string {
    return sanitize_key( (string) get_post_meta( $post_id, '_kitgenix_document_manager_access_capability', true ) );
}

function kitgenix_document_manager_get_document_allowed_user_ids( int $post_id ): array {
    return kitgenix_document_manager_sanitize_user_ids( get_post_meta( $post_id, '_kitgenix_document_manager_access_user_ids', true ) );
}

function kitgenix_document_manager_get_document_share_expires_gmt( int $post_id ): string {
    $value = trim( (string) get_post_meta( $post_id, '_kitgenix_document_manager_share_expires_gmt', true ) );
    if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
        return $value;
    }

    return '';
}

function kitgenix_document_manager_get_document_schedule( int $post_id ): array {
    $schedule = [
        'available_from_gmt' => trim( (string) get_post_meta( $post_id, '_kitgenix_document_manager_available_from_gmt', true ) ),
        'archive_at_gmt'     => trim( (string) get_post_meta( $post_id, '_kitgenix_document_manager_archive_at_gmt', true ) ),
        'expires_at_gmt'     => trim( (string) get_post_meta( $post_id, '_kitgenix_document_manager_expires_at_gmt', true ) ),
    ];

    foreach ( $schedule as $key => $value ) {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
            $schedule[ $key ] = '';
        }
    }

    return $schedule;
}

function kitgenix_document_manager_get_document_schedule_status( int $post_id, ?int $timestamp = null ): array {
    $schedule  = kitgenix_document_manager_get_document_schedule( $post_id );
    $timestamp = is_int( $timestamp ) ? $timestamp : time();

    $available_from_ts = $schedule['available_from_gmt'] !== '' ? strtotime( $schedule['available_from_gmt'] . ' GMT' ) : false;
    $archive_at_ts     = $schedule['archive_at_gmt'] !== '' ? strtotime( $schedule['archive_at_gmt'] . ' GMT' ) : false;
    $expires_at_ts     = $schedule['expires_at_gmt'] !== '' ? strtotime( $schedule['expires_at_gmt'] . ' GMT' ) : false;

    $status      = 'active';
    $label       = __( 'Active', 'kitgenix-document-manager' );
    $message     = '';
    $http_status = 200;

    if ( $available_from_ts && $timestamp < $available_from_ts ) {
        $status      = 'scheduled';
        $label       = __( 'Scheduled', 'kitgenix-document-manager' );
        $message     = __( 'This document is scheduled and is not live yet.', 'kitgenix-document-manager' );
        $http_status = 404;
    } elseif ( $expires_at_ts && $timestamp >= $expires_at_ts ) {
        $status      = 'expired';
        $label       = __( 'Expired', 'kitgenix-document-manager' );
        $message     = __( 'This document has expired and is no longer available.', 'kitgenix-document-manager' );
        $http_status = 410;
    } elseif ( $archive_at_ts && $timestamp >= $archive_at_ts ) {
        $status      = 'archived';
        $label       = __( 'Archived', 'kitgenix-document-manager' );
        $message     = __( 'This document has been archived and is no longer publicly available.', 'kitgenix-document-manager' );
        $http_status = 410;
    }

    return [
        'status'             => $status,
        'label'              => $label,
        'message'            => $message,
        'http_status'        => $http_status,
        'is_active'          => $status === 'active',
        'available_from_gmt' => $schedule['available_from_gmt'],
        'archive_at_gmt'     => $schedule['archive_at_gmt'],
        'expires_at_gmt'     => $schedule['expires_at_gmt'],
    ];
}

/**
 * Unified lifecycle state for the Documents dashboard, combining draft
 * status, missing files, schedule status, and access/visibility into the
 * single set of states requested for the lifecycle dashboard: Draft,
 * Unavailable, Scheduled, Live, Restricted, Archived, Expired.
 *
 * @return array{state: string, label: string}
 */
function kitgenix_document_manager_get_document_lifecycle_state( int $post_id ): array {
    $post = get_post( $post_id );

    if ( ! ( $post instanceof \WP_Post ) ) {
        return [ 'state' => 'unavailable', 'label' => __( 'Unavailable', 'kitgenix-document-manager' ) ];
    }

    if ( $post->post_status !== 'publish' ) {
        return [ 'state' => 'draft', 'label' => __( 'Draft', 'kitgenix-document-manager' ) ];
    }

    $attachment_id = (int) get_post_meta( $post_id, '_kitgenix_document_manager_current_attachment_id', true );
    if ( $attachment_id <= 0 ) {
        return [ 'state' => 'unavailable', 'label' => __( 'Unavailable', 'kitgenix-document-manager' ) ];
    }

    $schedule_status = kitgenix_document_manager_get_document_schedule_status( $post_id );
    $status = (string) ( $schedule_status['status'] ?? 'active' );
    if ( 'scheduled' === $status ) {
        return [ 'state' => 'scheduled', 'label' => __( 'Scheduled', 'kitgenix-document-manager' ) ];
    }
    if ( 'archived' === $status ) {
        return [ 'state' => 'archived', 'label' => __( 'Archived', 'kitgenix-document-manager' ) ];
    }
    if ( 'expired' === $status ) {
        return [ 'state' => 'expired', 'label' => __( 'Expired', 'kitgenix-document-manager' ) ];
    }

    $visibility = kitgenix_document_manager_get_document_visibility( $post_id );
    $has_granular_rules = ! empty( kitgenix_document_manager_get_document_allowed_roles( $post_id ) )
        || ! empty( kitgenix_document_manager_get_document_allowed_user_ids( $post_id ) )
        || kitgenix_document_manager_get_document_required_capability( $post_id ) !== '';

    if ( 'private' === $visibility ) {
        return [ 'state' => 'restricted', 'label' => __( 'Private', 'kitgenix-document-manager' ) ];
    }
    if ( 'restricted' === $visibility || $has_granular_rules ) {
        return [ 'state' => 'restricted', 'label' => __( 'Restricted', 'kitgenix-document-manager' ) ];
    }

    return [ 'state' => 'live', 'label' => __( 'Live', 'kitgenix-document-manager' ) ];
}

function kitgenix_document_manager_get_document_share_secret( int $post_id ): string {
    return sanitize_text_field( (string) get_post_meta( $post_id, '_kitgenix_document_manager_share_secret', true ) );
}

function kitgenix_document_manager_ensure_document_share_secret( int $post_id ): string {
    $secret = kitgenix_document_manager_get_document_share_secret( $post_id );
    if ( $secret !== '' ) {
        return $secret;
    }

    $secret = wp_generate_password( 40, false, false );
    update_post_meta( $post_id, '_kitgenix_document_manager_share_secret', $secret );

    return $secret;
}

/**
 * Optional maximum number of times a signed share link may be used.
 * 0 (default) means unlimited.
 */
function kitgenix_document_manager_get_document_share_max_uses( int $post_id ): int {
    return max( 0, absint( get_post_meta( $post_id, '_kitgenix_document_manager_share_max_uses', true ) ) );
}

function kitgenix_document_manager_get_document_share_use_count( int $post_id ): int {
    return max( 0, absint( get_post_meta( $post_id, '_kitgenix_document_manager_share_use_count', true ) ) );
}

/**
 * Best-effort atomic increment of the share-link use counter. Uses a direct
 * SQL increment rather than read-then-write to reduce (not eliminate) the
 * race window under concurrent downloads of the same signed link.
 */
function kitgenix_document_manager_increment_document_share_use_count( int $post_id ): int {
    global $wpdb;

    if ( $post_id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
        return 0;
    }

    $meta_key = '_kitgenix_document_manager_share_use_count';
    $existing = get_post_meta( $post_id, $meta_key, true );

    if ( '' === $existing ) {
        add_post_meta( $post_id, $meta_key, 1, true );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic numeric increment; no user input involved.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
                $post_id,
                $meta_key
            )
        );
        clean_post_cache( $post_id );
    }

    return kitgenix_document_manager_get_document_share_use_count( $post_id );
}

/**
 * Revoke the currently issued signed share link (any previously shared URL
 * stops working immediately) without disabling future sharing – an admin
 * can set a new expiry afterwards to issue a fresh link.
 */
function kitgenix_document_manager_revoke_document_share_link( int $post_id ): void {
    delete_post_meta( $post_id, '_kitgenix_document_manager_share_expires_gmt' );
    delete_post_meta( $post_id, '_kitgenix_document_manager_share_use_count' );
}

/**
 * Rotate the per-document signing secret. Every link issued before this
 * call becomes invalid immediately, even ones whose expiry has not passed,
 * because the signature is derived from the secret.
 */
function kitgenix_document_manager_regenerate_document_share_secret( int $post_id ): string {
    delete_post_meta( $post_id, '_kitgenix_document_manager_share_secret' );
    delete_post_meta( $post_id, '_kitgenix_document_manager_share_use_count' );

    return kitgenix_document_manager_ensure_document_share_secret( $post_id );
}

function kitgenix_document_manager_build_document_share_signature( int $post_id, int $expires_timestamp, bool $ensure_secret = false ): string {
    if ( $post_id <= 0 || $expires_timestamp <= 0 ) {
        return '';
    }

    $secret = $ensure_secret ? kitgenix_document_manager_ensure_document_share_secret( $post_id ) : kitgenix_document_manager_get_document_share_secret( $post_id );
    if ( $secret === '' ) {
        return '';
    }

    $attachment_id = (int) get_post_meta( $post_id, '_kitgenix_document_manager_current_attachment_id', true );
    $slug          = sanitize_title_with_dashes( (string) get_post_field( 'post_name', $post_id ) );
    $payload       = implode( '|', [ (string) $post_id, (string) $attachment_id, $slug, (string) $expires_timestamp ] );

    return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . '|' . $secret );
}

function kitgenix_document_manager_get_document_signed_share_url( int $post_id ): string {
    $slug = sanitize_title_with_dashes( (string) get_post_field( 'post_name', $post_id ) );
    if ( $post_id <= 0 || $slug === '' ) {
        return '';
    }

    $expires_gmt = kitgenix_document_manager_get_document_share_expires_gmt( $post_id );
    if ( $expires_gmt === '' ) {
        return '';
    }

    $expires_timestamp = strtotime( $expires_gmt . ' GMT' );
    if ( ! is_int( $expires_timestamp ) && ! is_float( $expires_timestamp ) ) {
        return '';
    }

    $expires_timestamp = (int) $expires_timestamp;
    if ( $expires_timestamp <= time() ) {
        return '';
    }

    $signature = kitgenix_document_manager_build_document_share_signature( $post_id, $expires_timestamp, true );
    if ( $signature === '' ) {
        return '';
    }

    $url = add_query_arg(
        [
            'kitgenix_dm_share'   => '1',
            'kitgenix_dm_expires' => (string) $expires_timestamp,
            'kitgenix_dm_sig'     => $signature,
        ],
        kitgenix_document_manager_stable_url( $slug )
    );

    /**
     * Fires/filters whenever a signed share URL is generated for a document.
     *
     * @param string $url               The generated signed share URL.
     * @param int    $post_id           Document post ID.
     * @param int    $expires_timestamp Expiry as a Unix timestamp (GMT).
     */
    return (string) apply_filters( 'kitgenix_document_manager_share_link_url', $url, $post_id, $expires_timestamp );
}

/**
 * Validate the share-link parameters on the current request against a document,
 * distinguishing *why* a link was rejected so callers can log or act on it.
 *
 * Codes: 'link_missing_flag' (no share params present at all – the normal case
 * for ordinary visits), 'link_expired' (the link's own expiry has passed),
 * 'secret_rotated' (the link predates the document's current share settings,
 * e.g. a fresh link was issued since), 'signature_mismatch' (signature does not
 * match – the URL was altered, corrupted, or fabricated).
 *
 * @return array{valid: bool, code: string} $code is '' when $valid is true.
 */
function kitgenix_document_manager_validate_share_link_request( int $post_id ): array {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- front-end signed access links are URL based.
    $share_flag = isset( $_GET['kitgenix_dm_share'] ) ? sanitize_key( wp_unslash( (string) $_GET['kitgenix_dm_share'] ) ) : '';
    if ( $share_flag !== '1' ) {
        return [ 'valid' => false, 'code' => 'link_missing_flag' ];
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- front-end signed access links are URL based.
    $expires_timestamp = isset( $_GET['kitgenix_dm_expires'] ) ? absint( wp_unslash( (string) $_GET['kitgenix_dm_expires'] ) ) : 0;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- front-end signed access links are URL based.
    $signature = isset( $_GET['kitgenix_dm_sig'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['kitgenix_dm_sig'] ) ) : '';

    if ( $expires_timestamp <= 0 || $signature === '' || $expires_timestamp <= time() ) {
        return [ 'valid' => false, 'code' => 'link_expired' ];
    }

    $current_expiry = kitgenix_document_manager_get_document_share_expires_gmt( $post_id );
    $current_ts     = $current_expiry !== '' ? strtotime( $current_expiry . ' GMT' ) : false;
    if ( ! is_int( $current_ts ) && ! is_float( $current_ts ) ) {
        // No share expiry currently stored for this document – most often
        // because sharing was turned off or a new link was issued since.
        return [ 'valid' => false, 'code' => 'secret_rotated' ];
    }

    $current_ts = (int) $current_ts;
    if ( $current_ts !== $expires_timestamp ) {
        return [ 'valid' => false, 'code' => 'secret_rotated' ];
    }

    $expected_signature = kitgenix_document_manager_build_document_share_signature( $post_id, $expires_timestamp, false );
    if ( $expected_signature === '' || ! hash_equals( $expected_signature, $signature ) ) {
        return [ 'valid' => false, 'code' => 'signature_mismatch' ];
    }

    $max_uses = kitgenix_document_manager_get_document_share_max_uses( $post_id );
    if ( $max_uses > 0 && kitgenix_document_manager_get_document_share_use_count( $post_id ) >= $max_uses ) {
        return [ 'valid' => false, 'code' => 'link_use_limit_reached' ];
    }

    return [ 'valid' => true, 'code' => '' ];
}

function kitgenix_document_manager_request_has_valid_share_link( int $post_id ): bool {
    return kitgenix_document_manager_validate_share_link_request( $post_id )['valid'];
}

function kitgenix_document_manager_evaluate_document_access( int $post_id, array $args = [] ): array {
    $surface = isset( $args['surface'] ) ? sanitize_key( (string) $args['surface'] ) : 'delivery';
    if ( ! in_array( $surface, [ 'delivery', 'listing' ], true ) ) {
        $surface = 'delivery';
    }

    $schedule_status = kitgenix_document_manager_get_document_schedule_status( $post_id );
    if ( $schedule_status['status'] !== 'active' ) {
        return [
            'allowed'           => false,
            'reason'            => (string) $schedule_status['status'],
            'http_status'       => (int) $schedule_status['http_status'],
            'visibility'        => kitgenix_document_manager_get_document_visibility( $post_id ),
            'schedule'          => $schedule_status,
            'granted_by'        => '',
            'is_signed_request' => false,
            'requires_login'    => false,
        ];
    }

    $visibility          = kitgenix_document_manager_get_document_visibility( $post_id );
    $allowed_roles       = kitgenix_document_manager_get_document_allowed_roles( $post_id );
    $required_capability = kitgenix_document_manager_get_document_required_capability( $post_id );
    $allowed_user_ids    = kitgenix_document_manager_get_document_allowed_user_ids( $post_id );
    $has_granular_rules  = ! empty( $allowed_roles ) || ! empty( $allowed_user_ids ) || $required_capability !== '';
    $is_signed_request   = kitgenix_document_manager_request_has_valid_share_link( $post_id );

    if ( current_user_can( Capabilities::CAPABILITY ) ) {
        return [
            'allowed'           => true,
            'reason'            => 'allowed',
            'http_status'       => 200,
            'visibility'        => $visibility,
            'schedule'          => $schedule_status,
            'granted_by'        => 'manage',
            'is_signed_request' => $is_signed_request,
            'requires_login'    => false,
        ];
    }

    if ( $is_signed_request ) {
        return [
            'allowed'           => true,
            'reason'            => 'allowed',
            'http_status'       => 200,
            'visibility'        => $visibility,
            'schedule'          => $schedule_status,
            'granted_by'        => 'signed_link',
            'is_signed_request' => true,
            'requires_login'    => false,
        ];
    }

    if ( $visibility === 'public' && ! $has_granular_rules ) {
        return [
            'allowed'           => true,
            'reason'            => 'allowed',
            'http_status'       => 200,
            'visibility'        => $visibility,
            'schedule'          => $schedule_status,
            'granted_by'        => 'public',
            'is_signed_request' => false,
            'requires_login'    => false,
        ];
    }

    $settings      = kitgenix_document_manager_get_settings();
    $login_mode    = (string) ( $settings['private_behavior'] ?? 'login' );
    $requires_login = $login_mode !== '403';

    if ( ! is_user_logged_in() ) {
        return [
            'allowed'           => false,
            'reason'            => $requires_login ? 'login_required' : 'forbidden',
            'http_status'       => $requires_login ? 401 : 403,
            'visibility'        => $visibility,
            'schedule'          => $schedule_status,
            'granted_by'        => '',
            'is_signed_request' => false,
            'requires_login'    => $requires_login,
        ];
    }

    $current_user = wp_get_current_user();
    $current_id   = get_current_user_id();
    $granted_by   = '';
    $allowed      = false;

    if ( ! empty( $allowed_user_ids ) && in_array( $current_id, $allowed_user_ids, true ) ) {
        $allowed    = true;
        $granted_by = 'user';
    }

    if ( ! $allowed && $required_capability !== '' && current_user_can( $required_capability ) ) {
        $allowed    = true;
        $granted_by = 'capability';
    }

    if ( ! $allowed && ! empty( $allowed_roles ) ) {
        $user_roles = is_array( $current_user->roles ) ? array_map( 'sanitize_key', $current_user->roles ) : [];
        if ( array_intersect( $allowed_roles, $user_roles ) ) {
            $allowed    = true;
            $granted_by = 'role';
        }
    }

    if ( ! $allowed ) {
        $filtered = apply_filters(
            'kitgenix_document_manager_user_has_document_access',
            null,
            $post_id,
            [
                'visibility'          => $visibility,
                'allowed_roles'       => $allowed_roles,
                'required_capability' => $required_capability,
                'allowed_user_ids'    => $allowed_user_ids,
                'surface'             => $surface,
                'schedule'            => $schedule_status,
            ]
        );

        if ( is_bool( $filtered ) ) {
            $allowed    = $filtered;
            $granted_by = $filtered ? 'filter' : $granted_by;
        }
    }

    if ( ! $allowed && ! $has_granular_rules && $visibility !== 'public' ) {
        $allowed    = true;
        $granted_by = 'login';
    }

    if ( $allowed ) {
        return [
            'allowed'           => true,
            'reason'            => 'allowed',
            'http_status'       => 200,
            'visibility'        => $visibility,
            'schedule'          => $schedule_status,
            'granted_by'        => $granted_by,
            'is_signed_request' => false,
            'requires_login'    => false,
        ];
    }

    return [
        'allowed'           => false,
        'reason'            => 'forbidden',
        'http_status'       => 403,
        'visibility'        => $visibility,
        'schedule'          => $schedule_status,
        'granted_by'        => '',
        'is_signed_request' => false,
        'requires_login'    => false,
    ];
}

function kitgenix_document_manager_get_document_analytics( int $post_id ): array {
    $analytics = get_post_meta( $post_id, '_kitgenix_document_manager_analytics', true );
    $analytics = is_array( $analytics ) ? $analytics : [];

    $defaults = [
        'views'           => 0,
        'downloads'       => 0,
        'signed_views'    => 0,
        'signed_downloads'=> 0,
        'last_access_gmt' => '',
        'last_access_type'=> '',
        'daily'           => [],
    ];

    $analytics = array_merge( $defaults, $analytics );

    $analytics['views']            = absint( $analytics['views'] );
    $analytics['downloads']        = absint( $analytics['downloads'] );
    $analytics['signed_views']     = absint( $analytics['signed_views'] );
    $analytics['signed_downloads'] = absint( $analytics['signed_downloads'] );
    $analytics['last_access_gmt']  = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $analytics['last_access_gmt'] ) ? (string) $analytics['last_access_gmt'] : '';
    $analytics['last_access_type'] = sanitize_key( (string) $analytics['last_access_type'] );
    $analytics['daily']            = is_array( $analytics['daily'] ) ? $analytics['daily'] : [];

    $clean_daily = [];
    foreach ( $analytics['daily'] as $day => $bucket ) {
        $day = (string) $day;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || ! is_array( $bucket ) ) {
            continue;
        }

        $clean_daily[ $day ] = [
            'views'            => absint( $bucket['views'] ?? 0 ),
            'downloads'        => absint( $bucket['downloads'] ?? 0 ),
            'signed_views'     => absint( $bucket['signed_views'] ?? 0 ),
            'signed_downloads' => absint( $bucket['signed_downloads'] ?? 0 ),
        ];
    }

    ksort( $clean_daily );
    $analytics['daily'] = $clean_daily;

    return $analytics;
}

function kitgenix_document_manager_sum_document_analytics( array $analytics, int $days = 30 ): array {
    $days = max( 1, $days );
    $cutoff = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) );
    $totals = [
        'views'            => 0,
        'downloads'        => 0,
        'signed_views'     => 0,
        'signed_downloads' => 0,
    ];

    $daily = isset( $analytics['daily'] ) && is_array( $analytics['daily'] ) ? $analytics['daily'] : [];
    foreach ( $daily as $day => $bucket ) {
        if ( $day < $cutoff || ! is_array( $bucket ) ) {
            continue;
        }

        $totals['views']            += absint( $bucket['views'] ?? 0 );
        $totals['downloads']        += absint( $bucket['downloads'] ?? 0 );
        $totals['signed_views']     += absint( $bucket['signed_views'] ?? 0 );
        $totals['signed_downloads'] += absint( $bucket['signed_downloads'] ?? 0 );
    }

    return $totals;
}

function kitgenix_document_manager_record_document_analytics_event( int $post_id, string $event_type, array $args = [] ): void {
    $event_type = sanitize_key( $event_type );
    if ( ! in_array( $event_type, [ 'view', 'download' ], true ) ) {
        return;
    }

    $analytics      = kitgenix_document_manager_get_document_analytics( $post_id );
    $is_signed      = ! empty( $args['signed'] );
    $analytics_key  = $event_type === 'view' ? 'views' : 'downloads';
    $signed_key     = $event_type === 'view' ? 'signed_views' : 'signed_downloads';
    $day_key        = gmdate( 'Y-m-d' );
    $last_access_gmt = gmdate( 'Y-m-d H:i:s' );

    $analytics[ $analytics_key ] = absint( $analytics[ $analytics_key ] ?? 0 ) + 1;
    if ( $is_signed ) {
        $analytics[ $signed_key ] = absint( $analytics[ $signed_key ] ?? 0 ) + 1;
    }

    if ( ! isset( $analytics['daily'][ $day_key ] ) || ! is_array( $analytics['daily'][ $day_key ] ) ) {
        $analytics['daily'][ $day_key ] = [
            'views'            => 0,
            'downloads'        => 0,
            'signed_views'     => 0,
            'signed_downloads' => 0,
        ];
    }

    $analytics['daily'][ $day_key ][ $analytics_key ] = absint( $analytics['daily'][ $day_key ][ $analytics_key ] ?? 0 ) + 1;
    if ( $is_signed ) {
        $analytics['daily'][ $day_key ][ $signed_key ] = absint( $analytics['daily'][ $day_key ][ $signed_key ] ?? 0 ) + 1;
    }

    $analytics['last_access_gmt']  = $last_access_gmt;
    $analytics['last_access_type'] = $is_signed ? 'signed_' . $event_type : $event_type;

    $pruned_daily = [];
    $cutoff       = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
    foreach ( $analytics['daily'] as $day => $bucket ) {
        if ( $day < $cutoff ) {
            continue;
        }
        $pruned_daily[ $day ] = $bucket;
    }
    ksort( $pruned_daily );
    $analytics['daily'] = $pruned_daily;

    update_post_meta( $post_id, '_kitgenix_document_manager_analytics', $analytics );
}

function kitgenix_document_manager_extension_mime_map(): array {
    return [
        'pdf'  => [ 'application/pdf' ],
        'doc'  => [ 'application/msword' ],
        'docx' => [ 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ],
        'xls'  => [ 'application/vnd.ms-excel' ],
        'xlsx' => [ 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ],
        'ppt'  => [ 'application/vnd.ms-powerpoint' ],
        'pptx' => [ 'application/vnd.openxmlformats-officedocument.presentationml.presentation' ],
        'txt'  => [ 'text/plain' ],
    ];
}

function kitgenix_document_manager_allowed_mimes(): array {
    $settings = kitgenix_document_manager_get_settings();
    $exts     = (array) ( $settings['allowed_extensions'] ?? [] );

    $map  = kitgenix_document_manager_extension_mime_map();
    $mimes = [];
    foreach ( $exts as $ext ) {
        $ext = strtolower( (string) $ext );
        if ( isset( $map[ $ext ] ) ) {
            // wp_handle_upload expects an array of ext => mime.
            $mimes[ $ext ] = is_array( $map[ $ext ] ) ? (string) $map[ $ext ][0] : (string) $map[ $ext ];
        }
    }

    // Fallback: allow unknown extensions to be denied.
    return $mimes;
}

function kitgenix_document_manager_upload_subdir(): string {
    return 'kitgenix-document-manager';
}

function kitgenix_document_manager_upload_dir(): array {
    $u = wp_upload_dir( null, false );
    $base = trailingslashit( (string) ( $u['basedir'] ?? '' ) ) . kitgenix_document_manager_upload_subdir();
    $url  = trailingslashit( (string) ( $u['baseurl'] ?? '' ) ) . kitgenix_document_manager_upload_subdir();

    return [
        'basedir' => (string) $u['basedir'],
        'baseurl' => (string) $u['baseurl'],
        'dir'     => $base,
        'url'     => $url,
    ];
}

function kitgenix_document_manager_ensure_upload_dir(): bool {
    $info = kitgenix_document_manager_upload_dir();
    $dir  = (string) ( $info['dir'] ?? '' );
    if ( $dir === '' ) {
        return false;
    }

    $ok = true;
    if ( ! file_exists( $dir ) ) {
        $ok = wp_mkdir_p( $dir );
    } else {
        $ok = is_dir( $dir );
    }

    if ( $ok ) {
        kitgenix_document_manager_protect_upload_dir();
    }

    return $ok;
}

function kitgenix_document_manager_stable_url( string $slug ): string {
    $slug = sanitize_title_with_dashes( $slug );
    return home_url( trailingslashit( 'kitgenix-document-manager/' . $slug ) );
}

function kitgenix_document_manager_get_document_visibility( int $post_id ): string {
    $vis = kitgenix_document_manager_normalize_visibility( (string) get_post_meta( $post_id, '_kitgenix_document_manager_visibility', true ) );
    if ( in_array( $vis, [ 'public', 'private', 'restricted' ], true ) ) {
        return $vis;
    }

    $settings = kitgenix_document_manager_get_settings();

    return kitgenix_document_manager_normalize_visibility( (string) ( $settings['default_visibility'] ?? 'public' ) );
}

function kitgenix_document_manager_is_versioning_enabled( int $post_id ): bool {
    $raw = get_post_meta( $post_id, '_kitgenix_document_manager_versioning_enabled', true );
    if ( $raw === '' ) {
        $settings = kitgenix_document_manager_get_settings();
        return (bool) ( $settings['keep_version_history'] ?? true );
    }

    return (bool) $raw;
}

function kitgenix_document_manager_get_versions( int $post_id ): array {
    $versions = get_post_meta( $post_id, '_kitgenix_document_manager_versions', true );
    $versions = is_array( $versions ) ? $versions : [];

    $out = [];
    foreach ( $versions as $id ) {
        $out[] = (int) $id;
    }

    $out = array_values( array_filter( $out ) );
    $out = array_values( array_unique( $out ) );

    return $out;
}

function kitgenix_document_manager_set_versions( int $post_id, array $attachment_ids ): void {
    $clean = [];
    foreach ( $attachment_ids as $id ) {
        $id = (int) $id;
        if ( $id > 0 ) {
            $clean[] = $id;
        }
    }
    $clean = array_values( array_unique( $clean ) );

    update_post_meta( $post_id, '_kitgenix_document_manager_versions', $clean );
}

/**
 * If the given attachment belongs to a Kitgenix Document Manager document
 * (either the current file or a saved version – both are parented to the
 * document post), return that document's post ID. Otherwise 0.
 */
function kitgenix_document_manager_attachment_document_id( int $attachment_id ): int {
    if ( $attachment_id <= 0 ) {
        return 0;
    }

    $attachment = get_post( $attachment_id );
    if ( ! ( $attachment instanceof \WP_Post ) || $attachment->post_type !== 'attachment' ) {
        return 0;
    }

    $parent_id = (int) $attachment->post_parent;
    if ( $parent_id <= 0 ) {
        return 0;
    }

    $parent = get_post( $parent_id );
    if ( ! ( $parent instanceof \WP_Post ) || $parent->post_type !== CPT::POST_TYPE ) {
        return 0;
    }

    return $parent_id;
}

/**
 * Optional per-version label/note, stored separately from the plain
 * attachment-ID version list so existing `_kitgenix_document_manager_versions`
 * data never needs to be migrated or re-shaped.
 *
 * @return array{label: string, note: string}
 */
function kitgenix_document_manager_get_version_meta( int $document_id, int $attachment_id ): array {
    $all = get_post_meta( $document_id, '_kitgenix_document_manager_version_meta', true );
    $all = is_array( $all ) ? $all : [];

    $entry = isset( $all[ $attachment_id ] ) && is_array( $all[ $attachment_id ] ) ? $all[ $attachment_id ] : [];

    return [
        'label' => isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '',
        'note'  => isset( $entry['note'] ) ? sanitize_textarea_field( (string) $entry['note'] ) : '',
    ];
}

function kitgenix_document_manager_set_version_meta( int $document_id, int $attachment_id, string $label, string $note ): void {
    if ( $document_id <= 0 || $attachment_id <= 0 ) {
        return;
    }

    $all = get_post_meta( $document_id, '_kitgenix_document_manager_version_meta', true );
    $all = is_array( $all ) ? $all : [];

    $label = sanitize_text_field( $label );
    $note  = sanitize_textarea_field( $note );

    if ( $label === '' && $note === '' ) {
        unset( $all[ $attachment_id ] );
    } else {
        $all[ $attachment_id ] = [ 'label' => $label, 'note' => $note ];
    }

    update_post_meta( $document_id, '_kitgenix_document_manager_version_meta', $all );
}

/**
 * Display name of the user who uploaded/replaced a given version, derived
 * from the attachment's own post_author (set automatically by WordPress
 * when the file was uploaded) – no separate tracking needed.
 */
function kitgenix_document_manager_get_version_uploaded_by( int $attachment_id ): string {
    $attachment = get_post( $attachment_id );
    if ( ! ( $attachment instanceof \WP_Post ) ) {
        return '';
    }

    $author_id = (int) $attachment->post_author;
    if ( $author_id <= 0 ) {
        return '';
    }

    $user = get_userdata( $author_id );
    if ( ! $user ) {
        return '';
    }

    return (string) $user->display_name;
}

/**
 * Idempotently write server-level access-blocking files (.htaccess for
 * Apache, web.config for IIS, an empty index.php as a directory-listing
 * fallback) into the plugin's dedicated upload subdirectory. Documents are
 * still served correctly because Endpoint streams files via a local
 * filesystem read (readfile()/fread()), not an HTTP request through this
 * directory – these files only stop *direct* web requests to the folder.
 *
 * This does not protect files chosen from elsewhere in the Media Library
 * (those keep their original location by design, to avoid moving files
 * other parts of the site may reference); the REST/attachment-permalink
 * guard (Attachment_Guard) is what protects those.
 */
function kitgenix_document_manager_protect_upload_dir(): void {
    $info = kitgenix_document_manager_upload_dir();
    $dir  = (string) ( $info['dir'] ?? '' );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- one-time directory check; WP_Filesystem is not guaranteed to be initialized this early (e.g. during upload/activation).
    if ( $dir === '' || ! is_dir( $dir ) || ! is_writable( $dir ) ) {
        return;
    }

    $htaccess = rtrim( $dir, '/' ) . '/.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        $contents = "# Kitgenix Document Manager: block direct access, files are served via PHP.\n"
            . "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- one-time, idempotent hardening file; WP_Filesystem is not guaranteed to be initialized this early; failure is non-fatal (best-effort defense-in-depth, see Attachment_Guard for the primary access-control layer).
        @file_put_contents( $htaccess, $contents );
    }

    $webconfig = rtrim( $dir, '/' ) . '/web.config';
    if ( ! file_exists( $webconfig ) ) {
        $contents = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n";
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- one-time, idempotent hardening file; failure is non-fatal.
        @file_put_contents( $webconfig, $contents );
    }

    $index = rtrim( $dir, '/' ) . '/index.php';
    if ( ! file_exists( $index ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- one-time, idempotent hardening file; failure is non-fatal.
        @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
    }
}
