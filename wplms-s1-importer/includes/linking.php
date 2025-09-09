<?php
namespace WPLMS_S1I;

/**
 * Link a LearnDash course to a WooCommerce product with WooCommerce-for-LearnDash meta.
 *
 * Ensures the course access mode is set to closed and synchronizes WooCommerce
 * meta arrays without creating duplicate entries on repeated calls.
 */
function hv_ld_link_course_to_product( int $course_id, int $product_id ): bool {
    $course_id  = (int) $course_id;
    $product_id = (int) $product_id;
    if ( $course_id <= 0 || $product_id <= 0 ) {
        return false;
    }

    // Basic cross references.
    \update_post_meta( $course_id, 'ld_product_id', $product_id );
    \update_post_meta( $course_id, 'ld_course_access_mode', 'closed' );
    \update_post_meta( $product_id, 'ld_course_id', $course_id );

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
        $meta[ $course_id ] = $default;
        \update_post_meta( $product_id, $meta_key, $meta );
    }

    return true;
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
