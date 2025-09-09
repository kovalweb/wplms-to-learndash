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
