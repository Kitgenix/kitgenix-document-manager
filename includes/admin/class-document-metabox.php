<?php
namespace KitgenixDocumentManager\Admin;

defined( 'ABSPATH' ) || exit;

use KitgenixDocumentManager\Core\Capabilities;
use KitgenixDocumentManager\Core\CPT;
use KitgenixDocumentManager\Core\Uploader;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_allowed_roles;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_allowed_user_ids;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_required_capability;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_schedule;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_schedule_status;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_share_expires_gmt;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_share_max_uses;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_share_use_count;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_version_meta;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_version_uploaded_by;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_visibility;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_signed_share_url;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_gmt_to_local_datetime_input;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_is_versioning_enabled;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_settings;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_local_datetime_to_gmt;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_normalize_visibility;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_sanitize_roles;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_sanitize_user_ids;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_stable_url;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_versions;

final class Document_Metabox {

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_metaboxes' ] );
        add_action( 'save_post', [ __CLASS__, 'save_meta' ] );

        add_action( 'admin_post_kitgenix_document_manager_upload_initial', [ __CLASS__, 'handle_upload_initial' ] );
        add_action( 'admin_post_kitgenix_document_manager_replace_file', [ __CLASS__, 'handle_replace_file' ] );

        add_action( 'admin_notices', [ __CLASS__, 'maybe_notice' ] );
    }

    public static function add_metaboxes(): void {
        add_meta_box(
            'kitgenix-document-manager-file',
            __( 'Document File', 'kitgenix-document-manager' ),
            [ __CLASS__, 'render_metabox' ],
            CPT::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'kitgenix-document-manager-visibility',
            __( 'Visibility', 'kitgenix-document-manager' ),
            [ __CLASS__, 'render_visibility_metabox' ],
            CPT::POST_TYPE,
            'side',
            'high'
        );
    }

    public static function render_visibility_metabox( \WP_Post $post ): void {
        if ( ! current_user_can( Capabilities::CAPABILITY ) ) {
            return;
        }

        wp_nonce_field( 'kitgenix_document_manager_save_meta', 'kitgenix_document_manager_meta_nonce' );

        $vis = kitgenix_document_manager_get_document_visibility( (int) $post->ID );
        $versioning_enabled = kitgenix_document_manager_is_versioning_enabled( (int) $post->ID );
        $allowed_roles = kitgenix_document_manager_get_document_allowed_roles( (int) $post->ID );
        $required_capability = kitgenix_document_manager_get_document_required_capability( (int) $post->ID );
        $allowed_user_ids = implode( ', ', kitgenix_document_manager_get_document_allowed_user_ids( (int) $post->ID ) );
        $share_expires_gmt = kitgenix_document_manager_get_document_share_expires_gmt( (int) $post->ID );
        $share_expires_input = $share_expires_gmt !== '' ? kitgenix_document_manager_gmt_to_local_datetime_input( $share_expires_gmt ) : '';
        $share_url = kitgenix_document_manager_get_document_signed_share_url( (int) $post->ID );
        $schedule = kitgenix_document_manager_get_document_schedule( (int) $post->ID );
        $schedule_status = kitgenix_document_manager_get_document_schedule_status( (int) $post->ID );
        $role_choices = function_exists( 'wp_roles' ) ? wp_roles() : null;

        echo '<div class="kitgenix-metabox">';

        echo '<p class="kitgenix-field-row"><label for="kitgenix_dm_visibility"><strong>' . esc_html__( 'Visibility', 'kitgenix-document-manager' ) . '</strong></label></p>';
        echo '<div class="kitgenix-field-row"><select class="widefat" name="kitgenix_dm_visibility" id="kitgenix_dm_visibility">'
            . '<option value="public" ' . selected( $vis, 'public', false ) . '>' . esc_html__( 'Public', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="private" ' . selected( $vis, 'private', false ) . '>' . esc_html__( 'Private', 'kitgenix-document-manager' ) . '</option>'
            . '<option value="restricted" ' . selected( $vis, 'restricted', false ) . '>' . esc_html__( 'Restricted', 'kitgenix-document-manager' ) . '</option>'
            . '</select></div>';

        echo '<p class="kitgenix-field-row"><label for="kitgenix_dm_access_roles"><strong>' . esc_html__( 'Allowed roles', 'kitgenix-document-manager' ) . '</strong></label></p>';
        echo '<div class="kitgenix-field-row"><select class="widefat" name="kitgenix_dm_access_roles[]" id="kitgenix_dm_access_roles" multiple="multiple" size="5">';
        if ( $role_choices instanceof \WP_Roles ) {
            foreach ( $role_choices->roles as $role_key => $role_data ) {
                $role_key = sanitize_key( (string) $role_key );
                $label = isset( $role_data['name'] ) ? translate_user_role( (string) $role_data['name'] ) : $role_key;
                echo '<option value="' . esc_attr( $role_key ) . '"' . selected( in_array( $role_key, $allowed_roles, true ), true, false ) . '>' . esc_html( $label ) . '</option>';
            }
        }
        echo '</select></div>';

        echo '<p class="kitgenix-field-row"><label for="kitgenix_dm_access_capability"><strong>' . esc_html__( 'Required capability', 'kitgenix-document-manager' ) . '</strong></label></p>';
        echo '<div class="kitgenix-field-row"><input type="text" class="widefat" name="kitgenix_dm_access_capability" id="kitgenix_dm_access_capability" value="' . esc_attr( $required_capability ) . '" placeholder="manage_options" /></div>';

        echo '<p class="kitgenix-field-row"><label for="kitgenix_dm_access_user_ids"><strong>' . esc_html__( 'Allowed user IDs', 'kitgenix-document-manager' ) . '</strong></label></p>';
        echo '<div class="kitgenix-field-row"><input type="text" class="widefat" name="kitgenix_dm_access_user_ids" id="kitgenix_dm_access_user_ids" value="' . esc_attr( $allowed_user_ids ) . '" placeholder="12, 48, 91" /></div>';

        echo '<p class="kitgenix-field-row"><label for="kitgenix_dm_share_expires"><strong>' . esc_html__( 'Signed share link expiry', 'kitgenix-document-manager' ) . '</strong></label></p>';
        echo '<div class="kitgenix-field-row"><input type="datetime-local" class="widefat" name="kitgenix_dm_share_expires" id="kitgenix_dm_share_expires" value="' . esc_attr( $share_expires_input ) . '" /></div>';

        $share_max_uses  = kitgenix_document_manager_get_document_share_max_uses( (int) $post->ID );
        $share_use_count = kitgenix_document_manager_get_document_share_use_count( (int) $post->ID );
        echo '<p class="kitgenix-field-row"><label for="kitgenix_dm_share_max_uses"><strong>' . esc_html__( 'Maximum uses (0 = unlimited)', 'kitgenix-document-manager' ) . '</strong></label></p>';
        echo '<div class="kitgenix-field-row"><input type="number" min="0" step="1" class="widefat" name="kitgenix_dm_share_max_uses" id="kitgenix_dm_share_max_uses" value="' . esc_attr( (string) $share_max_uses ) . '" /></div>';
        echo '<p class="description">' . esc_html(
            sprintf(
                /* translators: %s: number of times the current signed link has been used */
                __( 'Used so far: %s.', 'kitgenix-document-manager' ),
                number_format_i18n( $share_use_count )
            )
        ) . '</p>';

        echo '<p class="kitgenix-field-row"><label for="kitgenix_dm_available_from"><strong>' . esc_html__( 'Available from', 'kitgenix-document-manager' ) . '</strong></label></p>';
        echo '<div class="kitgenix-field-row"><input type="datetime-local" class="widefat" name="kitgenix_dm_available_from" id="kitgenix_dm_available_from" value="' . esc_attr( $schedule['available_from_gmt'] !== '' ? kitgenix_document_manager_gmt_to_local_datetime_input( $schedule['available_from_gmt'] ) : '' ) . '" /></div>';

        echo '<p class="kitgenix-field-row"><label for="kitgenix_dm_archive_at"><strong>' . esc_html__( 'Archive at', 'kitgenix-document-manager' ) . '</strong></label></p>';
        echo '<div class="kitgenix-field-row"><input type="datetime-local" class="widefat" name="kitgenix_dm_archive_at" id="kitgenix_dm_archive_at" value="' . esc_attr( $schedule['archive_at_gmt'] !== '' ? kitgenix_document_manager_gmt_to_local_datetime_input( $schedule['archive_at_gmt'] ) : '' ) . '" /></div>';

        echo '<p class="kitgenix-field-row"><label for="kitgenix_dm_expires_at"><strong>' . esc_html__( 'Expire at', 'kitgenix-document-manager' ) . '</strong></label></p>';
        echo '<div class="kitgenix-field-row"><input type="datetime-local" class="widefat" name="kitgenix_dm_expires_at" id="kitgenix_dm_expires_at" value="' . esc_attr( $schedule['expires_at_gmt'] !== '' ? kitgenix_document_manager_gmt_to_local_datetime_input( $schedule['expires_at_gmt'] ) : '' ) . '" /></div>';

        echo '<p class="kitgenix-field-row"><label>'
            . '<input type="checkbox" name="kitgenix_dm_versioning_enabled" value="1" ' . checked( $versioning_enabled, true, false ) . ' /> '
            . esc_html__( 'Keep version history', 'kitgenix-document-manager' )
            . '</label></p>';

        if ( $share_url !== '' ) {
            $revoke_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=kitgenix_document_manager_share_revoke&document_id=' . (int) $post->ID ),
                'kitgenix_document_manager_share_revoke',
                'nonce'
            );
            $regenerate_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=kitgenix_document_manager_share_regenerate&document_id=' . (int) $post->ID ),
                'kitgenix_document_manager_share_regenerate',
                'nonce'
            );

            echo '<p class="kitgenix-field-row"><strong>' . esc_html__( 'Signed share URL', 'kitgenix-document-manager' ) . '</strong></p>';
            echo '<div class="kitgenix-document-manager-meta-row kitgenix-field-row">'
                . '<input type="text" class="widefat code" readonly="readonly" value="' . esc_attr( $share_url ) . '" />'
                . '</div>';
            echo '<p>'
                . '<button type="button" class="button" data-kitgenix-copy="' . esc_attr( $share_url ) . '" data-default-label="' . esc_attr__( 'Copy share link', 'kitgenix-document-manager' ) . '" data-copied-label="' . esc_attr__( 'Copied', 'kitgenix-document-manager' ) . '">' . esc_html__( 'Copy share link', 'kitgenix-document-manager' ) . '</button> '
                . '<a class="button" href="' . esc_url( $revoke_url ) . '" onclick="return window.confirm(\'' . esc_js( __( 'Revoke the current share link?', 'kitgenix-document-manager' ) ) . '\');">' . esc_html__( 'Revoke', 'kitgenix-document-manager' ) . '</a> '
                . '<a class="button" href="' . esc_url( $regenerate_url ) . '" onclick="return window.confirm(\'' . esc_js( __( 'Regenerate the signing secret? All previously issued links stop working immediately.', 'kitgenix-document-manager' ) ) . '\');">' . esc_html__( 'Regenerate', 'kitgenix-document-manager' ) . '</a>'
                . '</p>';
        }

        echo '<p class="description">' . esc_html__( 'Private documents require login. Restricted documents can also be limited by role, capability, user ID, share link, and schedule.', 'kitgenix-document-manager' ) . '</p>';
        echo '<p class="description">'
            . sprintf(
                /* translators: %s: current schedule status label. */
                esc_html__( 'Current schedule status: %s', 'kitgenix-document-manager' ),
                esc_html( (string) ( $schedule_status['label'] ?? __( 'Active', 'kitgenix-document-manager' ) ) )
            )
            . '</p>';

        echo '</div>';
    }

    public static function render_metabox( \WP_Post $post ): void {
        if ( ! current_user_can( Capabilities::CAPABILITY ) ) {
            echo esc_html__( 'You do not have permission to manage documents.', 'kitgenix-document-manager' );
            return;
        }

        $post_id = (int) $post->ID;
        $slug = (string) $post->post_name;
        $stable = $slug ? kitgenix_document_manager_stable_url( $slug ) : '';

        $current_id = (int) get_post_meta( $post_id, '_kitgenix_document_manager_current_attachment_id', true );
        $versions = kitgenix_document_manager_get_versions( $post_id );

        echo '<div class="kitgenix-metabox">';

        if ( $stable ) {
            echo '<div class="kitgenix-document-manager-meta-row kitgenix-field-row">'
                . '<strong>' . esc_html__( 'Stable Link:', 'kitgenix-document-manager' ) . '</strong> '
                . '<a href="' . esc_url( $stable ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $stable ) . '</a>'
                . ' <button type="button" class="kitgenix-document-manager-copy" data-kitgenix-copy="' . esc_attr( $stable ) . '" data-default-label="' . esc_attr__( 'Copy', 'kitgenix-document-manager' ) . '" data-copied-label="' . esc_attr__( 'Copied', 'kitgenix-document-manager' ) . '">' . esc_html__( 'Copy', 'kitgenix-document-manager' ) . '</button>'
                . '</div>';
        }

        if ( $current_id > 0 ) {
            $path = get_attached_file( $current_id );
            $path = is_string( $path ) ? $path : '';
            $name = $path ? wp_basename( $path ) : '';

            $bytes = ( $path && file_exists( $path ) ) ? @filesize( $path ) : false;
            $size_str = ( $bytes !== false ) ? size_format( (int) $bytes, 2 ) : '';

            $a = get_post( $current_id );
            $date = ( $a instanceof \WP_Post ) ? get_post_time( get_option( 'date_format' ), false, $a, true ) : '';

            echo '<p><strong>' . esc_html__( 'Current file', 'kitgenix-document-manager' ) . '</strong></p>';
            echo '<div class="kitgenix-document-manager-meta-row kitgenix-field-row">'
                . '<code>' . esc_html( $name ? $name : (string) $current_id ) . '</code>'
                . ( $date ? '<span class="description">' . esc_html( $date ) . '</span>' : '' )
                . ( $size_str ? '<span class="description">' . esc_html( $size_str ) . '</span>' : '' )
                . '</div>';

            $download = wp_get_attachment_url( $current_id );
            if ( $download ) {
                echo '<p><a class="button" href="' . esc_url( $download ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Download Current File', 'kitgenix-document-manager' ) . '</a></p>';
            }
        } else {
            echo '<p class="description">' . esc_html__( 'No file uploaded yet. Upload an initial file below.', 'kitgenix-document-manager' ) . '</p>';
        }

        $focus = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['kitgenix_dm_focus'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $focus = sanitize_key( wp_unslash( $_GET['kitgenix_dm_focus'] ) );
        }
        if ( $focus === 'file' ) {
            if ( function_exists( 'wp_add_inline_script' ) ) {
                static $kitgenix_dm_focus_inline_added = false;
                if ( ! $kitgenix_dm_focus_inline_added ) {
                    $kitgenix_dm_focus_inline_added = true;
                    wp_add_inline_script(
                        'kitgenix-document-manager-admin',
                        'try{document.getElementById("kitgenix-document-manager-file").scrollIntoView({behavior:"smooth",block:"start"});}catch(e){}',
                        'after'
                    );
                }
            }
        }

        $action = ( $current_id > 0 ) ? 'kitgenix_document_manager_replace_file' : 'kitgenix_document_manager_upload_initial';
        $label  = ( $current_id > 0 ) ? __( 'Replace File', 'kitgenix-document-manager' ) : __( 'Upload File', 'kitgenix-document-manager' );

        echo '<hr />';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
        echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '" />';

        $nonce_action = ( $current_id > 0 ) ? 'kitgenix_document_manager_replace_file' : 'kitgenix_document_manager_upload_initial';
        wp_nonce_field( $nonce_action, 'kitgenix_document_manager_file_nonce' );

        echo '<p><input type="file" name="kitgenix_document_manager_file" required="required" /></p>';
        echo '<p><label for="kitgenix_dm_version_label">' . esc_html__( 'Version label (optional)', 'kitgenix-document-manager' ) . '</label><br />'
            . '<input type="text" class="widefat" id="kitgenix_dm_version_label" name="version_label" placeholder="' . esc_attr__( 'e.g. 2024 policy update', 'kitgenix-document-manager' ) . '" /></p>';
        echo '<p><button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></p>';
        echo '</form>';

        if ( $versions && count( $versions ) > 1 ) {
            echo '<p><strong>' . esc_html__( 'Version history', 'kitgenix-document-manager' ) . '</strong></p>';
            echo '<ul>';
            foreach ( array_reverse( $versions ) as $vid ) {
                $att = get_post( (int) $vid );
                if ( ! ( $att instanceof \WP_Post ) ) {
                    continue;
                }
                $p = get_attached_file( (int) $vid );
                $p = is_string( $p ) ? $p : '';
                $n = $p ? wp_basename( $p ) : (string) $vid;
                $d = get_post_time( get_option( 'date_format' ), false, $att, true );
                $version_meta = kitgenix_document_manager_get_version_meta( $post_id, (int) $vid );
                $uploaded_by  = kitgenix_document_manager_get_version_uploaded_by( (int) $vid );
                $current_flag = ( $current_id > 0 && (int) $vid === $current_id ) ? ' – ' . esc_html__( 'current', 'kitgenix-document-manager' ) : '';

                echo '<li>' . esc_html( $n ) . esc_html( $current_flag )
                    . ( $version_meta['label'] !== '' ? ' – <strong>' . esc_html( $version_meta['label'] ) . '</strong>' : '' )
                    . ' <span class="description">(' . esc_html( (string) $d )
                    . ( $uploaded_by !== '' ? ', ' . esc_html( $uploaded_by ) : '' )
                    . ')</span>'
                    . ( $version_meta['note'] !== '' ? '<br /><span class="description">' . esc_html( $version_meta['note'] ) . '</span>' : '' )
                    . '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';
    }

    public static function save_meta( int $post_id ): void {
        if ( get_post_type( $post_id ) !== CPT::POST_TYPE ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( Capabilities::CAPABILITY ) ) {
            return;
        }

        if ( ! self::verify_post_nonce( 'kitgenix_document_manager_meta_nonce', 'kitgenix_document_manager_save_meta' ) ) {
            return;
        }

        $vis = kitgenix_document_manager_normalize_visibility( self::get_post_key( 'kitgenix_dm_visibility' ) );
        update_post_meta( $post_id, '_kitgenix_document_manager_visibility', $vis );

        $enabled = self::post_flag_is_enabled( 'kitgenix_dm_versioning_enabled' );
        update_post_meta( $post_id, '_kitgenix_document_manager_versioning_enabled', $enabled ? 1 : 0 );

        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified above via self::verify_post_nonce(); each input is sanitized below.
        $access_roles = isset( $_POST['kitgenix_dm_access_roles'] ) ? kitgenix_document_manager_sanitize_roles( wp_unslash( $_POST['kitgenix_dm_access_roles'] ) ) : [];
        $access_capability = isset( $_POST['kitgenix_dm_access_capability'] ) ? sanitize_key( wp_unslash( (string) $_POST['kitgenix_dm_access_capability'] ) ) : '';
        $access_user_ids = isset( $_POST['kitgenix_dm_access_user_ids'] ) ? kitgenix_document_manager_sanitize_user_ids( wp_unslash( (string) $_POST['kitgenix_dm_access_user_ids'] ) ) : [];
        $share_expires = isset( $_POST['kitgenix_dm_share_expires'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['kitgenix_dm_share_expires'] ) ) : '';
        $share_max_uses = isset( $_POST['kitgenix_dm_share_max_uses'] ) ? absint( wp_unslash( $_POST['kitgenix_dm_share_max_uses'] ) ) : 0;
        $available_from = isset( $_POST['kitgenix_dm_available_from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['kitgenix_dm_available_from'] ) ) : '';
        $archive_at = isset( $_POST['kitgenix_dm_archive_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['kitgenix_dm_archive_at'] ) ) : '';
        $expires_at = isset( $_POST['kitgenix_dm_expires_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['kitgenix_dm_expires_at'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $share_expires_gmt = $share_expires !== '' ? kitgenix_document_manager_local_datetime_to_gmt( $share_expires ) : '';
        $available_from_gmt = $available_from !== '' ? kitgenix_document_manager_local_datetime_to_gmt( $available_from ) : '';
        $archive_at_gmt = $archive_at !== '' ? kitgenix_document_manager_local_datetime_to_gmt( $archive_at ) : '';
        $expires_at_gmt = $expires_at !== '' ? kitgenix_document_manager_local_datetime_to_gmt( $expires_at ) : '';

        if ( ! empty( $access_roles ) ) {
            update_post_meta( $post_id, '_kitgenix_document_manager_access_roles', $access_roles );
        } else {
            delete_post_meta( $post_id, '_kitgenix_document_manager_access_roles' );
        }

        if ( $access_capability !== '' ) {
            update_post_meta( $post_id, '_kitgenix_document_manager_access_capability', $access_capability );
        } else {
            delete_post_meta( $post_id, '_kitgenix_document_manager_access_capability' );
        }

        if ( ! empty( $access_user_ids ) ) {
            update_post_meta( $post_id, '_kitgenix_document_manager_access_user_ids', $access_user_ids );
        } else {
            delete_post_meta( $post_id, '_kitgenix_document_manager_access_user_ids' );
        }

        if ( $share_expires_gmt !== '' ) {
            update_post_meta( $post_id, '_kitgenix_document_manager_share_expires_gmt', $share_expires_gmt );
        } else {
            delete_post_meta( $post_id, '_kitgenix_document_manager_share_expires_gmt' );
        }

        if ( $share_max_uses > 0 ) {
            update_post_meta( $post_id, '_kitgenix_document_manager_share_max_uses', $share_max_uses );
        } else {
            delete_post_meta( $post_id, '_kitgenix_document_manager_share_max_uses' );
        }

        if ( $available_from_gmt !== '' ) {
            update_post_meta( $post_id, '_kitgenix_document_manager_available_from_gmt', $available_from_gmt );
        } else {
            delete_post_meta( $post_id, '_kitgenix_document_manager_available_from_gmt' );
        }

        if ( $archive_at_gmt !== '' ) {
            update_post_meta( $post_id, '_kitgenix_document_manager_archive_at_gmt', $archive_at_gmt );
        } else {
            delete_post_meta( $post_id, '_kitgenix_document_manager_archive_at_gmt' );
        }

        if ( $expires_at_gmt !== '' ) {
            update_post_meta( $post_id, '_kitgenix_document_manager_expires_at_gmt', $expires_at_gmt );
        } else {
            delete_post_meta( $post_id, '_kitgenix_document_manager_expires_at_gmt' );
        }
    }

    public static function handle_upload_initial(): void {
        if ( ! current_user_can( Capabilities::CAPABILITY ) ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'kitgenix-document-manager' ) );
        }

        if ( ! self::verify_post_nonce( 'kitgenix_document_manager_file_nonce', 'kitgenix_document_manager_upload_initial' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'kitgenix-document-manager' ) );
        }

        $post_id = self::get_post_absint( 'post_id' );
        if ( $post_id <= 0 || get_post_type( $post_id ) !== CPT::POST_TYPE ) {
            wp_die( esc_html__( 'Invalid document.', 'kitgenix-document-manager' ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --
        // Files are validated and processed by Uploader::upload_and_attach which uses WP APIs.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --
        // Incoming files are validated by the Uploader class which uses WP APIs.
        if ( ! \KitgenixDocumentManager\Core\Uploader::has_incoming_file() ) {
            wp_die( esc_html__( 'No file uploaded.', 'kitgenix-document-manager' ) );
        }

        $attach_id = Uploader::upload_and_attach( $post_id, \KitgenixDocumentManager\Core\Uploader::get_incoming_file() );
        if ( is_wp_error( $attach_id ) ) {
            self::redirect_with_notice( $post_id, self::notice_key_for_upload_error( $attach_id, 'upload' ) );
        }

        Uploader::set_current_attachment( $post_id, $attach_id );
        update_post_meta( $post_id, '_kitgenix_document_manager_versions', [ $attach_id ] );

        self::redirect_with_notice( $post_id, 'uploaded' );
    }

    public static function handle_replace_file(): void {
        if ( ! current_user_can( Capabilities::CAPABILITY ) ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'kitgenix-document-manager' ) );
        }

        if ( ! self::verify_post_nonce( 'kitgenix_document_manager_file_nonce', 'kitgenix_document_manager_replace_file' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'kitgenix-document-manager' ) );
        }

        $post_id = self::get_post_absint( 'post_id' );
        if ( $post_id <= 0 || get_post_type( $post_id ) !== CPT::POST_TYPE ) {
            wp_die( esc_html__( 'Invalid document.', 'kitgenix-document-manager' ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --
        // Files are validated and processed by Uploader::replace_file which uses WP APIs.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --
        // Incoming files are validated by the Uploader class which uses WP APIs.
        if ( ! \KitgenixDocumentManager\Core\Uploader::has_incoming_file() ) {
            wp_die( esc_html__( 'No file uploaded.', 'kitgenix-document-manager' ) );
        }

        $keep_history = kitgenix_document_manager_is_versioning_enabled( $post_id );
        $settings = kitgenix_document_manager_get_settings();
        $delete_old = ! empty( $settings['delete_old_versions'] );
        $version_label = isset( $_POST['version_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['version_label'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via self::verify_post_nonce().

        $new_id = Uploader::replace_file( $post_id, \KitgenixDocumentManager\Core\Uploader::get_incoming_file(), $keep_history, $delete_old, $version_label );
        if ( is_wp_error( $new_id ) ) {
            self::redirect_with_notice( $post_id, self::notice_key_for_upload_error( $new_id, 'replace' ) );
        }

        self::redirect_with_notice( $post_id, 'replaced' );
    }

    private static function verify_post_nonce( string $field, string $action ): bool {
        $nonce = self::get_post_nonce( $field );
        if ( '' === $nonce ) {
            return false;
        }

        return (bool) wp_verify_nonce( $nonce, $action );
    }

    private static function get_post_nonce( string $field ): string {
        if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- helper is only used for immediate nonce verification.
            return '';
        }

        return sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- helper is only used for immediate nonce verification.
    }

    private static function get_post_absint( string $field ): int {
        if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify nonce before reading request values.
            return 0;
        }

        return absint( wp_unslash( (string) $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify nonce before reading request values.
    }

    private static function get_post_key( string $field ): string {
        if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify nonce before reading request values.
            return '';
        }

        return sanitize_key( wp_unslash( (string) $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify nonce before reading request values.
    }

    private static function post_flag_is_enabled( string $field ): bool {
        if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify nonce before reading request values.
            return false;
        }

        return '1' === sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify nonce before reading request values.
    }

    /**
     * Turn an upload/replace WP_Error code into a specific notice key, falling
     * back to the original generic '{action}_failed' key for unmapped codes.
     */
    private static function notice_key_for_upload_error( \WP_Error $error, string $action ): string {
        $known_codes = [
            'no_capability',
            'no_tmp_file',
            'dir_not_writable',
            'upload_handler_error',
            'missing_filepath',
            'disallowed_extension',
            'db_insert_failed',
        ];

        $code = $error->get_error_code();
        if ( in_array( $code, $known_codes, true ) ) {
            return $action . '_failed_' . $code;
        }

        return $action . '_failed';
    }

    private static function redirect_with_notice( int $post_id, string $notice ): void {
        $url = get_edit_post_link( $post_id, 'raw' );
        if ( ! $url ) {
            $url = admin_url( 'edit.php?post_type=' . rawurlencode( CPT::POST_TYPE ) );
        }
        $url = add_query_arg( [ 'kitgenix_dm_notice' => sanitize_key( $notice ) ], $url );
        wp_safe_redirect( $url );
        exit;
    }

    public static function maybe_notice(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== CPT::POST_TYPE ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $n = isset( $_GET['kitgenix_dm_notice'] ) ? sanitize_key( wp_unslash( $_GET['kitgenix_dm_notice'] ) ) : '';
        if ( $n === '' ) {
            return;
        }

        $map = [
            'uploaded'      => [ 'success', __( 'File uploaded.', 'kitgenix-document-manager' ) ],
            'replaced'      => [ 'success', __( 'File replaced. Stable link unchanged.', 'kitgenix-document-manager' ) ],
            'upload_failed' => [ 'error', __( 'Upload failed. Please check file type and try again.', 'kitgenix-document-manager' ) ],
            'replace_failed'=> [ 'error', __( 'Replace failed. Please check file type and try again.', 'kitgenix-document-manager' ) ],

            'upload_failed_no_capability'        => [ 'error', __( 'Upload failed: you do not have permission to upload documents.', 'kitgenix-document-manager' ) ],
            'upload_failed_no_tmp_file'           => [ 'error', __( 'Upload failed: no file was received by the server. Please try again.', 'kitgenix-document-manager' ) ],
            'upload_failed_dir_not_writable'      => [ 'error', __( 'Upload failed: the uploads folder is not writable. Contact your host or administrator.', 'kitgenix-document-manager' ) ],
            'upload_failed_upload_handler_error'  => [ 'error', __( 'Upload failed: WordPress could not process the file. Check the file and try again.', 'kitgenix-document-manager' ) ],
            'upload_failed_missing_filepath'      => [ 'error', __( 'Upload failed: the file could not be found after processing. Please try again.', 'kitgenix-document-manager' ) ],
            'upload_failed_disallowed_extension'  => [ 'error', __( 'Upload failed: this file type is not allowed. Check Settings → Allowed file types.', 'kitgenix-document-manager' ) ],
            'upload_failed_db_insert_failed'      => [ 'error', __( 'Upload failed: the file could not be saved to the media library. Please try again.', 'kitgenix-document-manager' ) ],

            'replace_failed_no_capability'        => [ 'error', __( 'Replace failed: you do not have permission to upload documents.', 'kitgenix-document-manager' ) ],
            'replace_failed_no_tmp_file'          => [ 'error', __( 'Replace failed: no file was received by the server. Please try again.', 'kitgenix-document-manager' ) ],
            'replace_failed_dir_not_writable'     => [ 'error', __( 'Replace failed: the uploads folder is not writable. Contact your host or administrator.', 'kitgenix-document-manager' ) ],
            'replace_failed_upload_handler_error' => [ 'error', __( 'Replace failed: WordPress could not process the file. Check the file and try again.', 'kitgenix-document-manager' ) ],
            'replace_failed_missing_filepath'     => [ 'error', __( 'Replace failed: the file could not be found after processing. Please try again.', 'kitgenix-document-manager' ) ],
            'replace_failed_disallowed_extension' => [ 'error', __( 'Replace failed: this file type is not allowed. Check Settings → Allowed file types.', 'kitgenix-document-manager' ) ],
            'replace_failed_db_insert_failed'     => [ 'error', __( 'Replace failed: the file could not be saved to the media library. Please try again.', 'kitgenix-document-manager' ) ],
        ];
        if ( ! isset( $map[ $n ] ) ) {
            return;
        }

        [ $type, $msg ] = $map[ $n ];
        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
    }
}
