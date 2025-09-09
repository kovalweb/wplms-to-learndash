<?php
// Simple in-memory simulation of WordPress post meta functions.
$GLOBALS['post_meta'] = [];

function update_post_meta( $post_id, $meta_key, $value ) {
    $GLOBALS['post_meta'][ $post_id ][ $meta_key ] = $value;
    return true;
}

function get_post_meta( $post_id, $meta_key, $single = true ) {
    if ( isset( $GLOBALS['post_meta'][ $post_id ][ $meta_key ] ) ) {
        return $GLOBALS['post_meta'][ $post_id ][ $meta_key ];
    }
    return $single ? '' : [];
}

require __DIR__ . '/../includes/linking.php';

use function WPLMS_S1I\hv_ld_link_course_to_product;
use function WPLMS_S1I\hv_get_linked_product_id_for_course;

// Link twice to ensure idempotency.
hv_ld_link_course_to_product( 1, 100 );
hv_ld_link_course_to_product( 1, 100 );

// Verify product meta arrays contain a single entry for course ID.
$expected = [ 1 => 'paynow' ];
$price_types = $GLOBALS['post_meta'][100]['_ld_price_type'] ?? [];
if ( $price_types !== $expected ) {
    echo "Duplicate _ld_price_type entries\n";
    exit( 1 );
}
$billing_times = $GLOBALS['post_meta'][100]['_ld_price_billing_time'] ?? [];
if ( $billing_times !== [ 1 => '' ] ) {
    echo "Duplicate _ld_price_billing_time entries\n";
    exit( 1 );
}
$billing_units = $GLOBALS['post_meta'][100]['_ld_price_billing_unit'] ?? [];
if ( $billing_units !== [ 1 => '' ] ) {
    echo "Duplicate _ld_price_billing_unit entries\n";
    exit( 1 );
}

// Verify accessor.
$link = hv_get_linked_product_id_for_course( 1 );
if ( 100 !== $link ) {
    echo "get_linked_product_id failed\n";
    exit( 1 );
}

echo "All tests passed\n";
