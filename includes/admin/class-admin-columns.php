<?php
namespace KitgenixDocumentManager\Admin;

defined( 'ABSPATH' ) || exit;

use KitgenixDocumentManager\Core\CPT;
use KitgenixDocumentManager\Core\Uploader;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_schedule_status;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_signed_share_url;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_stable_url;
use function KitgenixDocumentManager\Core\kitgenix_document_manager_get_document_visibility;

final class Admin_Columns {

    public static function init(): void {
        add_filter( 'manage_' . CPT::POST_TYPE . '_posts_columns', [ __CLASS__, 'columns' ] );
        add_action( 'manage_' . CPT::POST_TYPE . '_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
        add_filter( 'post_row_actions', [ __CLASS__, 'row_actions' ], 10, 2 );
    }

    public static function columns( array $columns ): array {
        $new = [];
        foreach ( $columns as $k => $label ) {
            $new[ $k ] = $label;
            if ( $k === 'title' ) {
                $new['kitgenix_dm_stable']   = __( 'Stable Link', 'kitgenix-document-manager' );
                $new['kitgenix_dm_version']  = __( 'Current Version', 'kitgenix-document-manager' );
                $new['kitgenix_dm_file']     = __( 'File', 'kitgenix-document-manager' );
                $new['kitgenix_dm_vis']      = __( 'Visibility', 'kitgenix-document-manager' );
                $new['kitgenix_dm_actions']  = __( 'Actions', 'kitgenix-document-manager' );
            }
        }

        return $new;
    }

    public static function render_column( string $column, int $post_id ): void {
        if ( get_post_type( $post_id ) !== CPT::POST_TYPE ) {
            return;
        }

        if ( $column === 'kitgenix_dm_stable' ) {
            $slug = get_post_field( 'post_name', $post_id );
            $slug = is_string( $slug ) ? $slug : '';
            if ( $slug === '' ) {
                echo '—';
                return;
            }
            $url = kitgenix_document_manager_stable_url( $slug );

            echo '<span class="kitgenix-document-manager-stable-link">'
                . '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>'
                . ' <button type="button" class="kitgenix-document-manager-copy" data-kitgenix-copy="' . esc_attr( $url ) . '" data-default-label="' . esc_attr__( 'Copy', 'kitgenix-document-manager' ) . '" data-copied-label="' . esc_attr__( 'Copied', 'kitgenix-document-manager' ) . '">' . esc_html__( 'Copy', 'kitgenix-document-manager' ) . '</button>'
                . '</span>';
            return;
        }

        if ( $column === 'kitgenix_dm_version' ) {
            $aid = (int) get_post_meta( $post_id, '_kitgenix_document_manager_current_attachment_id', true );
            if ( $aid <= 0 ) {
                echo '—';
                return;
            }
            $a = get_post( $aid );
            if ( ! ( $a instanceof \WP_Post ) ) {
                echo '—';
                return;
            }

            $date = get_post_time( get_option( 'date_format' ), false, $a, true );
            echo esc_html( (string) $date );
            return;
        }

        if ( $column === 'kitgenix_dm_file' ) {
            $aid = (int) get_post_meta( $post_id, '_kitgenix_document_manager_current_attachment_id', true );
            if ( $aid <= 0 ) {
                echo '—';
                return;
            }

            $path = get_attached_file( $aid );
            $path = is_string( $path ) ? $path : '';
            $name = $path ? wp_basename( $path ) : '';

            $type = $name ? wp_check_filetype( $name ) : [];
            $ext = isset( $type['ext'] ) ? strtoupper( (string) $type['ext'] ) : '';

            $size_str = '';
            if ( $path && file_exists( $path ) ) {
                $bytes = @filesize( $path );
                if ( $bytes !== false ) {
                    $size_str = size_format( (int) $bytes, 2 );
                }
            }

            $out = [];
            if ( $ext ) {
                $out[] = $ext;
            }
            if ( $size_str ) {
                $out[] = $size_str;
            }

            echo esc_html( $name ? $name : '—' );
            if ( $out ) {
                echo '<div class="description">' . esc_html( implode( ' • ', $out ) ) . '</div>';
            }
            return;
        }

        if ( $column === 'kitgenix_dm_vis' ) {
            $vis = kitgenix_document_manager_get_document_visibility( $post_id );
            $label = $vis === 'private' ? __( 'Private', 'kitgenix-document-manager' ) : ( $vis === 'restricted' ? __( 'Restricted', 'kitgenix-document-manager' ) : __( 'Public', 'kitgenix-document-manager' ) );
            $schedule_status = kitgenix_document_manager_get_document_schedule_status( $post_id );
            if ( ( $schedule_status['status'] ?? 'active' ) !== 'active' && ! empty( $schedule_status['label'] ) ) {
                $label .= ' · ' . (string) $schedule_status['label'];
            }

            echo esc_html( $label );
            return;
        }

        if ( $column === 'kitgenix_dm_actions' ) {
            $edit = get_edit_post_link( $post_id );
            $slug = get_post_field( 'post_name', $post_id );
            $slug = is_string( $slug ) ? $slug : '';
            $url  = $slug ? kitgenix_document_manager_stable_url( $slug ) : '';

            $replace = $edit ? add_query_arg( [ 'kitgenix_dm_focus' => 'file' ], $edit ) : '';

            $links = [];
            if ( $edit ) {
                $links[] = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'kitgenix-document-manager' ) . '</a>';
            }
            if ( $replace ) {
                $links[] = '<a href="' . esc_url( $replace ) . '">' . esc_html__( 'Replace File', 'kitgenix-document-manager' ) . '</a>';
            }
            if ( $url ) {
                $links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View Link', 'kitgenix-document-manager' ) . '</a>';
            }

            $share_url = kitgenix_document_manager_get_document_signed_share_url( $post_id );
            if ( $share_url ) {
                $links[] = '<a href="' . esc_url( $share_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Share Link', 'kitgenix-document-manager' ) . '</a>';
            }

            echo wp_kses_post( implode( ' | ', $links ) );
            return;
        }
    }

    public static function row_actions( array $actions, \WP_Post $post ): array {
        if ( $post->post_type !== CPT::POST_TYPE ) {
            return $actions;
        }

        $edit = get_edit_post_link( $post->ID );
        if ( $edit ) {
            $actions['kitgenix_dm_replace'] = '<a href="' . esc_url( add_query_arg( [ 'kitgenix_dm_focus' => 'file' ], $edit ) ) . '">' . esc_html__( 'Replace File', 'kitgenix-document-manager' ) . '</a>';
        }

        return $actions;
    }
}
