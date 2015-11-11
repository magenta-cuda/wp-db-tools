<?php
# test data for ddt_post_query() for uncommon MySQL operations

add_action( 'admin_init', function( ) {
    global $wpdb;
    # test case for INSERT INTO ... SET ...
    $wpdb->query( "INSERT INTO $wpdb->postmeta SET meta_id = 88888888, meta_key = 'ddt_x-insert_set_test', meta_value = 'ddt_x-test', post_id = 88888888" );
    $wpdb->query( "DELETE FROM wp_postmeta WHERE meta_id = 88888888" );
} );
?>