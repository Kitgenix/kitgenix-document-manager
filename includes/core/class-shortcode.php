<?php
namespace KitgenixDocumentManager\Core;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

    public static function init(): void {
        add_shortcode( 'kitgenix_document_manager_link', [ __CLASS__, 'render' ] );
        add_shortcode( 'kitgenix_document_manager_document', [ __CLASS__, 'render_document' ] );
    }

    /**
     * Shortcode: [kitgenix_document_manager_link slug="my-doc" label="Download"]
     */
    public static function render( $atts ): string {
        $atts = shortcode_atts(
            [
                'slug'  => '',
                'label' => '',
            ],
            (array) $atts,
            'kitgenix_document_manager_link'
        );

        $slug = sanitize_title_with_dashes( (string) ( $atts['slug'] ?? '' ) );
        if ( $slug === '' ) {
            return '';
        }

        $post = get_page_by_path( $slug, OBJECT, CPT::POST_TYPE );
        if ( ! ( $post instanceof \WP_Post ) || $post->post_status !== 'publish' ) {
            return '';
        }

        $document_id   = (int) $post->ID;
        $attachment_id = (int) get_post_meta( $document_id, '_kitgenix_document_manager_current_attachment_id', true );
        if ( $attachment_id <= 0 ) {
            return '';
        }

        $access = kitgenix_document_manager_evaluate_document_access(
            $document_id,
            [
                'surface' => 'listing',
            ]
        );

        if ( empty( $access['allowed'] ) ) {
            return '';
        }

        $url = kitgenix_document_manager_stable_url( $slug );
        $label = (string) ( $atts['label'] ?? '' );
        if ( $label === '' ) {
            $label = get_the_title( $post );
        }

        return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }

    /**
     * Shortcode: [kitgenix_document_manager_document slug="my-doc" button_label="View"]
     * Shortcode (multiple): [kitgenix_document_manager_document slugs="doc-a,doc-b,doc-c" button_label="View"]
     *
     * Renders a small preview image with the document title below,
     * plus file type, file size, and a button linking to the stable URL.
     */
    public static function render_document( $atts ): string {
        $atts = shortcode_atts(
            [
                'slug'         => '',
                'slugs'        => '',
                'button_label' => '',
                'button_style_source' => '',
                'button_style' => '',
                'button_full_width' => '',
                'image_size'   => '',
                'meta_align'   => '',
                'button_align' => '',
                'new_tab'      => '',
                'show_type'    => '',
                'show_size'    => '',
                'show_description' => '',
            ],
            (array) $atts,
            'kitgenix_document_manager_document'
        );

        $settings = function_exists( __NAMESPACE__ . '\\kitgenix_document_manager_get_settings' )
            ? kitgenix_document_manager_get_settings()
            : [];

        $button_label = (string) ( $atts['button_label'] ?? '' );
        if ( $button_label === '' ) {
            $button_label = sanitize_text_field( (string) ( $settings['card_button_label'] ?? '' ) );
            if ( $button_label === '' ) {
                $button_label = __( 'View', 'kitgenix-document-manager' );
            }
        }

        $button_style_source = (string) ( $settings['card_button_style_source'] ?? 'custom' );
        $button_style_source = ( $button_style_source === 'theme' ) ? 'theme' : 'custom';

        $button_style_source_raw = sanitize_key( (string) ( $atts['button_style_source'] ?? '' ) );
        if ( $button_style_source_raw === 'theme' || $button_style_source_raw === 'custom' ) {
            $button_style_source = $button_style_source_raw;
        }

        $open_new_tab = (bool) ( $settings['card_open_new_tab'] ?? false );
        $show_type    = (bool) ( $settings['card_show_file_type'] ?? true );
        $show_size    = (bool) ( $settings['card_show_file_size'] ?? true );
        $show_description = (bool) ( $settings['card_show_description'] ?? false );
        $button_full_width = (bool) ( $settings['card_button_full_width'] ?? false );

        $image_size = absint( $settings['card_image_size'] ?? 96 );
        if ( $image_size < 32 || $image_size > 256 ) {
            $image_size = 96;
        }

        $meta_align = sanitize_key( (string) ( $settings['card_meta_alignment'] ?? 'justify' ) );
        if ( ! in_array( $meta_align, [ 'justify', 'left', 'center', 'right' ], true ) ) {
            $meta_align = 'justify';
        }

        $button_align = sanitize_key( (string) ( $settings['card_button_alignment'] ?? 'center' ) );
        if ( ! in_array( $button_align, [ 'justify', 'left', 'center', 'right' ], true ) ) {
            $button_align = 'center';
        }

        $image_size_raw = trim( (string) ( $atts['image_size'] ?? '' ) );
        if ( $image_size_raw !== '' ) {
            $override = absint( $image_size_raw );
            if ( $override >= 32 && $override <= 256 ) {
                $image_size = $override;
            }
        }

        $meta_align_raw = sanitize_key( (string) ( $atts['meta_align'] ?? '' ) );
        if ( $meta_align_raw !== '' && in_array( $meta_align_raw, [ 'justify', 'left', 'center', 'right' ], true ) ) {
            $meta_align = $meta_align_raw;
        }

        $button_align_raw = sanitize_key( (string) ( $atts['button_align'] ?? '' ) );
        if ( $button_align_raw !== '' && in_array( $button_align_raw, [ 'justify', 'left', 'center', 'right' ], true ) ) {
            $button_align = $button_align_raw;
        }

        $button_full_width_raw = strtolower( trim( (string) ( $atts['button_full_width'] ?? '' ) ) );
        if ( $button_full_width_raw !== '' ) {
            $button_full_width = in_array( $button_full_width_raw, [ '1', 'true', 'yes', 'y', 'on' ], true );
        }

        if ( $button_full_width ) {
            $button_align = 'justify';
        }

        $card_classes = 'kgx-dm-card kgx-dm-card--meta-' . $meta_align . ' kgx-dm-card--button-' . $button_align;
        if ( $button_full_width ) {
            $card_classes .= ' kgx-dm-card--button-full-width';
        }

        $style_vars = [];
        $style_vars[] = '--kgx-dm-thumb-size:' . $image_size . 'px';

        if ( $button_style_source !== 'theme' ) {
            $map = [
                'card_button_text_color'        => '--kgx-dm-btn-text',
                'card_button_bg_color'          => '--kgx-dm-btn-bg',
                'card_button_border_color'      => '--kgx-dm-btn-border',
                'card_button_hover_text_color'  => '--kgx-dm-btn-hover-text',
                'card_button_hover_bg_color'    => '--kgx-dm-btn-hover-bg',
                'card_button_hover_border_color'=> '--kgx-dm-btn-hover-border',
            ];
            foreach ( $map as $setting_key => $css_var ) {
                $val = trim( (string) ( $settings[ $setting_key ] ?? '' ) );
                if ( $val !== '' ) {
                    $style_vars[] = $css_var . ':' . $val;
                }
            }
        }

        $card_style_attr = '';
        if ( ! empty( $style_vars ) ) {
            $card_style_attr = ' style="' . esc_attr( implode( ';', $style_vars ) . ';' ) . '"';
        }

        $button_style = (string) ( $settings['card_button_style'] ?? 'outline' );
        $button_style = in_array( $button_style, [ 'outline', 'solid', 'theme' ], true ) ? $button_style : 'outline';
        if ( $button_style_source === 'theme' ) {
            $button_style = 'theme';
        }

        $button_style_raw = strtolower( trim( (string) ( $atts['button_style'] ?? '' ) ) );
        if ( $button_style_raw !== '' ) {
            $button_style = in_array( $button_style_raw, [ 'outline', 'solid', 'theme' ], true ) ? $button_style_raw : 'outline';
            if ( $button_style_raw === 'theme' ) {
                $button_style_source = 'theme';
            }
        }

        $new_tab_raw = strtolower( trim( (string) ( $atts['new_tab'] ?? '' ) ) );
        if ( $new_tab_raw !== '' ) {
            $open_new_tab = in_array( $new_tab_raw, [ '1', 'true', 'yes', 'y', 'on' ], true );
        }

        $show_type_raw = strtolower( trim( (string) ( $atts['show_type'] ?? '' ) ) );
        if ( $show_type_raw !== '' ) {
            $show_type = in_array( $show_type_raw, [ '1', 'true', 'yes', 'y', 'on' ], true );
        }

        $show_size_raw = strtolower( trim( (string) ( $atts['show_size'] ?? '' ) ) );
        if ( $show_size_raw !== '' ) {
            $show_size = in_array( $show_size_raw, [ '1', 'true', 'yes', 'y', 'on' ], true );
        }

        $show_desc_raw = strtolower( trim( (string) ( $atts['show_description'] ?? '' ) ) );
        if ( $show_desc_raw !== '' ) {
            $show_description = in_array( $show_desc_raw, [ '1', 'true', 'yes', 'y', 'on' ], true );
        }

        $slugs = self::parse_shortcode_slugs( $atts );

        if ( empty( $slugs ) ) {
            return '';
        }

        self::ensure_public_styles();

        $cards = '';
        foreach ( $slugs as $slug ) {
            $post = get_page_by_path( $slug, OBJECT, CPT::POST_TYPE );
            if ( ! ( $post instanceof \WP_Post ) || $post->post_status !== 'publish' ) {
                continue;
            }

            $card = self::render_document_card( $post, $slug, $button_label, $button_style_source, $button_style, $open_new_tab, $show_type, $show_size, $show_description, $card_classes, $card_style_attr, $button_full_width );
            if ( $card !== '' ) {
                $cards .= $card;
            }
        }

        if ( $cards === '' ) {
            return '';
        }

        if ( count( $slugs ) > 1 ) {
            return '<div class="kgx-dm-cards">' . $cards . '</div>';
        }

        return $cards;
    }

    private static function parse_shortcode_slugs( array $atts ): array {
        $raw_values = [];

        foreach ( [ 'slugs', 'slug' ] as $key ) {
            $raw = trim( (string) ( $atts[ $key ] ?? '' ) );
            if ( $raw !== '' ) {
                $raw_values[] = $raw;
            }
        }

        if ( empty( $raw_values ) ) {
            return [];
        }

        $slugs = [];
        foreach ( $raw_values as $raw ) {
            $parts = preg_split( '/[\s,|;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
            $parts = is_array( $parts ) ? $parts : [];
            foreach ( $parts as $part ) {
                $slug = sanitize_title_with_dashes( (string) $part );
                if ( $slug !== '' ) {
                    $slugs[] = $slug;
                }
            }
        }

        return array_values( array_unique( $slugs ) );
    }

    private static function ensure_public_styles(): void {
        if ( ! function_exists( 'wp_register_style' ) || ! function_exists( 'wp_enqueue_style' ) ) {
            return;
        }

        if ( function_exists( 'wp_style_is' ) && ! wp_style_is( 'kitgenix-document-manager-public', 'registered' ) ) {
            $ver = defined( 'KITGENIX_DOCUMENT_MANAGER_VERSION' ) ? (string) KITGENIX_DOCUMENT_MANAGER_VERSION : '1.1.1';
            $css_file = defined( 'KITGENIX_DOCUMENT_MANAGER_DIR' ) ? (string) KITGENIX_DOCUMENT_MANAGER_DIR . 'assets/css/public.css' : '';
            $css_ver  = ( $css_file && file_exists( $css_file ) ) ? (string) filemtime( $css_file ) : $ver;

            wp_register_style(
                'kitgenix-document-manager-public',
                defined( 'KITGENIX_DOCUMENT_MANAGER_FILE' ) ? plugins_url( 'assets/css/public.css', KITGENIX_DOCUMENT_MANAGER_FILE ) : '',
                [],
                $css_ver
            );
        }

        wp_enqueue_style( 'kitgenix-document-manager-public' );
    }

    private static function render_document_card( \WP_Post $post, string $slug, string $button_label, string $button_style_source, string $button_style, bool $open_new_tab, bool $show_type, bool $show_size, bool $show_description, string $card_classes, string $card_style_attr, bool $button_full_width ): string {
        $document_id = (int) $post->ID;
        $access = kitgenix_document_manager_evaluate_document_access(
            $document_id,
            [
                'surface' => 'listing',
            ]
        );

        if ( empty( $access['allowed'] ) ) {
            return '';
        }

        $attachment_id = (int) get_post_meta( $document_id, '_kitgenix_document_manager_current_attachment_id', true );

        $stable_url = kitgenix_document_manager_stable_url( $slug );
        $title      = get_the_title( $post );

        $description = '';
        if ( $show_description ) {
            $description = (string) get_post_meta( $document_id, '_kitgenix_document_manager_description', true );
            $description = trim( preg_replace( '/\s+/', ' ', $description ) );
        }

        $type_label = '';
        $size_label = '';

        if ( $attachment_id > 0 ) {
            $filepath = get_attached_file( $attachment_id );
            $filepath = is_string( $filepath ) ? $filepath : '';

            if ( $filepath !== '' ) {
                $ext = strtolower( (string) pathinfo( $filepath, PATHINFO_EXTENSION ) );
                if ( $ext !== '' ) {
                    $type_label = strtoupper( $ext );
                }

                if ( file_exists( $filepath ) && is_readable( $filepath ) ) {
                    $size = @filesize( $filepath );
                    if ( $size !== false && (int) $size >= 0 && function_exists( 'size_format' ) ) {
                        $size_label = size_format( (int) $size );
                    }
                }
            }
        }

        if ( $type_label === '' ) {
            $mime = (string) get_post_mime_type( $attachment_id );
            if ( $mime !== '' ) {
                $type_label = $mime;
            }
        }

        $preview_html = '';
        if ( function_exists( 'wp_get_attachment_image' ) ) {
            $preview_html = (string) wp_get_attachment_image(
                $attachment_id,
                'thumbnail',
                false,
                [
                    'class' => 'kgx-dm-card__img',
                    'alt'   => esc_attr( $title ),
                    'loading' => 'lazy',
                ]
            );
        }

        if ( $preview_html === '' && function_exists( 'wp_mime_type_icon' ) ) {
            $icon = wp_mime_type_icon( $attachment_id );
            $icon = is_string( $icon ) ? $icon : '';
            if ( $icon !== '' ) {
                $preview_html = '<img class="kgx-dm-card__img kgx-dm-card__img--icon" src="' . esc_url( $icon ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" />';
            }
        }

        if ( $preview_html === '' ) {
            $preview_html = '<div class="kgx-dm-card__img kgx-dm-card__img--placeholder" aria-hidden="true"></div>';
        }

        $link_attrs = '';
        if ( $open_new_tab ) {
            $link_attrs = ' target="_blank" rel="noopener noreferrer"';
        }

        $button_class = 'kgx-dm-card__button';
        if ( $button_style_source === 'theme' ) {
            $button_class = 'kgx-dm-card__button--theme wp-block-button__link wp-element-button button';
        } elseif ( $button_style === 'solid' ) {
            $button_class .= ' kgx-dm-card__button--solid';
        }

        if ( $button_full_width ) {
            $button_class .= ' kgx-dm-card__button--full-width';
        }

        $meta_parts = '';
        if ( $show_type && $type_label !== '' ) {
            $meta_parts .= '<div class="kgx-dm-card__meta"><span class="kgx-dm-card__meta-label">' . esc_html__( 'File Type', 'kitgenix-document-manager' ) . '</span><span class="kgx-dm-card__meta-value">' . esc_html( $type_label ) . '</span></div>';
        }
        if ( $show_size && $size_label !== '' ) {
            $meta_parts .= '<div class="kgx-dm-card__meta"><span class="kgx-dm-card__meta-label">' . esc_html__( 'File Size', 'kitgenix-document-manager' ) . '</span><span class="kgx-dm-card__meta-value">' . esc_html( $size_label ) . '</span></div>';
        }

        return ''
            . '<div class="' . esc_attr( $card_classes ) . '" data-kgx-dm-slug="' . esc_attr( $slug ) . '"' . $card_style_attr . '>'
            . '  <a class="kgx-dm-card__preview" href="' . esc_url( $stable_url ) . '"' . $link_attrs . '>' . $preview_html . '</a>'
            . '  <div class="kgx-dm-card__title">' . esc_html( $title ) . '</div>'
            . ( $description !== '' ? '  <div class="kgx-dm-card__desc">' . esc_html( $description ) . '</div>' : '' )
            . '  <div class="kgx-dm-card__meta-grid">' . $meta_parts . '</div>'
            . '  <div class="kgx-dm-card__actions">'
            . '    <a class="' . esc_attr( $button_class ) . '" href="' . esc_url( $stable_url ) . '"' . $link_attrs . '>' . esc_html( $button_label ) . '</a>'
            . '  </div>'
            . '</div>';
    }
}
