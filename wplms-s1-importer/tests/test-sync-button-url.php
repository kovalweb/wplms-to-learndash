<?php
// Stubs for WordPress and WooCommerce functions.
$GLOBALS['post_meta'] = [];

function update_post_meta( $id, $key, $val ) { $GLOBALS['post_meta'][ $id ][ $key ] = $val; return true; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['post_meta'][ $id ][ $key ] ?? ''; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['post_meta'][ $id ][ $key ] ); }
function learndash_update_setting( $id, $key, $val ) { update_post_meta( $id, $key, $val ); }
function get_permalink( $id ) { return "http://example.com/product/$id"; }

class Dummy_Product {
    private $id; private $status; private $price; private $type;
    public function __construct( $id, $status, $price, $type ) {
        $this->id = $id; $this->status = $status; $this->price = $price; $this->type = $type;
    }
    public function get_status() { return $this->status; }
    public function get_price() { return $this->price; }
    public function is_type( $t ) { return $this->type === $t; }
    public function add_to_cart_url() { return "http://shop/?add-to-cart=" . $this->id; }
}
$GLOBALS['products'] = [];
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? null; }

require __DIR__ . '/../includes/linking.php';

use function WPLMS_S1I\hv_ld_sync_button_url;

// Sellable simple product.
$GLOBALS['products'][10] = new Dummy_Product( 10, 'publish', '15', 'simple' );
hv_ld_sync_button_url( 1, 10 );
if ( ( $GLOBALS['post_meta'][1]['custom_button_url'] ?? '' ) !== 'http://shop/?add-to-cart=10' ) {
    echo "failed set"; exit( 1 );
}
if ( ( $GLOBALS['post_meta'][1]['ld_course_access_mode'] ?? '' ) !== 'closed' ) {
    echo "access not closed"; exit( 1 );
}

// Unsellable draft product clears URL.
$GLOBALS['products'][10] = new Dummy_Product( 10, 'draft', '15', 'simple' );
hv_ld_sync_button_url( 1, 10 );
if ( isset( $GLOBALS['post_meta'][1]['custom_button_url'] ) && $GLOBALS['post_meta'][1]['custom_button_url'] !== '' ) {
    echo "failed clear"; exit( 1 );
}

echo "All tests passed\n";

