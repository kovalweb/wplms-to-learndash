<?php
namespace WPLMS_S1I;

/**
 * Link a LearnDash course to a WooCommerce product with WooCommerce-for-LearnDash meta.
 *
 * Ensures the course access mode is set to closed and synchronizes WooCommerce
 * meta arrays without creating duplicate entries on repeated calls.
 */
function hv_ld_link_course_to_product( int $course_id, int $product_id, ?Logger $logger = null ): bool {
    $course_id  = (int) $course_id;
    $product_id = (int) $product_id;
    if ( $course_id <= 0 || $product_id <= 0 ) {
        return false;
    }

    $changed = false;

    // Basic cross references. Avoid unnecessary writes or duplicate IDs.
    $existing = \get_post_meta( $course_id, 'ld_product_id', true );
    if ( is_array( $existing ) ) {
        if ( ! in_array( $product_id, $existing, true ) ) {
            $existing[] = $product_id;
            \update_post_meta( $course_id, 'ld_product_id', $existing );
            $changed = true;
        }
    } elseif ( (int) $existing !== $product_id ) {
        \update_post_meta( $course_id, 'ld_product_id', $product_id );
        $changed = true;
    }

    if ( \get_post_meta( $course_id, 'ld_course_access_mode', true ) !== 'closed' ) {
        \update_post_meta( $course_id, 'ld_course_access_mode', 'closed' );
        $changed = true;
    }

    $existing = \get_post_meta( $product_id, 'ld_course_id', true );
    if ( is_array( $existing ) ) {
        if ( ! in_array( $course_id, $existing, true ) ) {
            $existing[] = $course_id;
            \update_post_meta( $product_id, 'ld_course_id', $existing );
            $changed = true;
        }
    } elseif ( (int) $existing !== $course_id ) {
        \update_post_meta( $product_id, 'ld_course_id', $course_id );
        $changed = true;
    }

    // WooCommerce for LearnDash meta keys require arrays keyed by course ID.
    $meta_map = [
        '_ld_price_type'         => 'paynow',
        '_ld_price_billing_time' => '',
        '_ld_price_billing_unit' => '',
    ];

    foreach ( $meta_map as $meta_key => $default ) {
        $meta = \get_post_meta( $product_id, $meta_key, true );
        if ( ! is_array( $meta ) ) {
            $meta = [];
        }
        if ( ! array_key_exists( $course_id, $meta ) || $meta[ $course_id ] !== $default ) {
            $meta[ $course_id ] = $default;
            \update_post_meta( $product_id, $meta_key, $meta );
            $changed = true;
        }
    }

    if ( $logger ) {
        $logger->write( 'course_product_link', [ 'course_id' => $course_id, 'product_id' => $product_id, 'changed' => $changed ] );
    }

    return $changed;
}

/**
 * Retrieve the WooCommerce product linked to a LearnDash course.
 */
function hv_get_linked_product_id_for_course( int $course_id ): ?int {
    $course_id = (int) $course_id;
    if ( $course_id <= 0 ) {
        return null;
    }
    $pid = (int) \get_post_meta( $course_id, 'ld_product_id', true );
    return $pid > 0 ? $pid : null;
}

/**
 * Synchronize LearnDash course button URL based on linked WooCommerce product.
 *
 * Sets a reliable add-to-cart or product permalink URL for sellable courses and
 * clears the button URL otherwise. Always keeps the course access mode set to
 * "closed" for WooCommerce-managed access. Idempotent.
 */
function hv_ld_sync_button_url(
    int $course_id,
    ?int $product_id,
    ?Logger $logger = null,
    ?array &$stats = null
): bool {
    $course_id  = (int) $course_id;
    $product_id = (int) $product_id;

    if ( $course_id <= 0 ) {
        return false;
    }

    $url          = '';
    $reason       = '';
    $sellable     = false;
    $product_type = '';
    $url_source   = '';

    if ( $product_id > 0 && function_exists( '\\wc_get_product' ) ) {
        $product = \wc_get_product( $product_id );
        if ( $product ) {
            $product_type = $product->get_type();
            if ( $product->get_status() === 'publish' ) {
                $price = $product->get_price();
                if ( '' !== (string) $price && is_numeric( $price ) && (float) $price > 0 ) {
                    $sellable = true;
                    $url        = \add_query_arg( 'add-to-cart', $product_id, \wc_get_cart_url() );
                    $url_source = 'cart';
                    if ( ! $product->is_purchasable() ) {
                        $url        = \get_permalink( $product_id );
                        $url_source = 'product';
                    }
                    $url    = \esc_url_raw( $url );
                    $reason = 'sellable';
                } else {
                    $reason = 'no_price';
                }
            } else {
                $reason = 'not_publish';
            }
        } else {
            $reason = 'no_woo';
        }
    } else {
        $reason = 'no_product';
    }

    $changed = false;
    $current = \get_post_meta( $course_id, 'custom_button_url', true );

    if ( $sellable ) {
        if ( $current !== $url ) {
            if ( function_exists( '\\learndash_update_setting' ) ) {
                \learndash_update_setting( $course_id, 'custom_button_url', $url );
            }
            \update_post_meta( $course_id, 'custom_button_url', $url );
            $changed = true;
        }
        if ( \get_post_meta( $course_id, 'ld_course_access_mode', true ) !== 'closed' ) {
            \update_post_meta( $course_id, 'ld_course_access_mode', 'closed' );
        }
        if ( $changed ) {
            if ( $logger ) {
                $logger->write( 'button_url_set', [
                    'course_id'    => $course_id,
                    'product_id'   => $product_id,
                    'url'          => $url,
                    'reason'       => 'sellable',
                    'button_url_source' => $url_source,
                    'product_type'     => $product_type,
                ] );
            }
            if ( is_array( $stats ) ) {
                $stats['button_url_set_count'] = array_get( $stats, 'button_url_set_count', 0 ) + 1;
                if ( count( $stats['button_url_set_examples'] ) < 5 ) {
                    $stats['button_url_set_examples'][] = [
                        'course_id'    => $course_id,
                        'product_id'   => $product_id,
                        'url'          => $url,
                        'button_url_source' => $url_source,
                        'product_type'     => $product_type,
                    ];
                }
            }
        }
    } else {
        if ( $current !== '' ) {
            if ( function_exists( '\\learndash_update_setting' ) ) {
                \learndash_update_setting( $course_id, 'custom_button_url', '' );
            }
            \delete_post_meta( $course_id, 'custom_button_url' );
            $changed = true;
        }
        if ( $product_id > 0 && \get_post_meta( $course_id, 'ld_course_access_mode', true ) !== 'closed' ) {
            \update_post_meta( $course_id, 'ld_course_access_mode', 'closed' );
        }
        if ( $changed ) {
            if ( $logger ) {
                $logger->write( 'button_url_cleared', [ 'course_id' => $course_id, 'reason' => $reason ] );
            }
            if ( is_array( $stats ) ) {
                $stats['button_url_cleared_count'] = array_get( $stats, 'button_url_cleared_count', 0 ) + 1;
                if ( count( $stats['button_url_cleared_examples'] ) < 5 ) {
                    $stats['button_url_cleared_examples'][] = [ 'course_id' => $course_id, 'reason' => $reason ];
                }
            }
        }
    }

    return $changed;
}

/**
 * Sets LearnDash course certificate id (version-aware), idempotent.
 */
function hv_ld_attach_certificate_to_course( int $course_id, int $certificate_id ): bool {
    $course_id      = (int) $course_id;
    $certificate_id = (int) $certificate_id;
    if ( $course_id <= 0 || $certificate_id <= 0 ) {
        return false;
    }

    $current = 0;
    if ( function_exists( '\\learndash_get_setting' ) ) {
        $current = (int) \learndash_get_setting( $course_id, 'certificate' );
        if ( ! $current ) {
            $current = (int) \learndash_get_setting( $course_id, 'course_certificate' );
        }
    } else {
        $current = (int) \get_post_meta( $course_id, 'certificate', true );
        if ( ! $current ) {
            $current = (int) \get_post_meta( $course_id, 'course_certificate', true );
        }
    }

    if ( $current === $certificate_id ) {
        return true;
    }

    if ( function_exists( '\\learndash_update_setting' ) ) {
        \learndash_update_setting( $course_id, 'certificate', $certificate_id );
    }
    \update_post_meta( $course_id, 'certificate', $certificate_id );
    \update_post_meta( $course_id, 'ld_course_certificate', $certificate_id );

    $check = 0;
    if ( function_exists( '\\learndash_get_setting' ) ) {
        $check = (int) \learndash_get_setting( $course_id, 'certificate' );
    } else {
        $check = (int) \get_post_meta( $course_id, 'certificate', true );
    }

    return ( $check === $certificate_id );
}
