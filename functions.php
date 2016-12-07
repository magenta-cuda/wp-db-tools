<?php
# test data for ddt_post_query() for uncommon MySQL operations

add_action( 'admin_init', function( ) {
    global $wpdb;
    # test case for INSERT INTO ... SET ...
    # don't use 88888888 but the next available id as MySQL will remember the id even if you delete the row and auto generate ids from the remembered id
    #$wpdb->query( "INSERT INTO $wpdb->postmeta SET meta_id = 88888888, meta_key = 'ddt_x-insert_set_test', meta_value = 'ddt_x-test', post_id = 88888888" );
    #$wpdb->query( "DELETE FROM wp_postmeta WHERE meta_id = 88888888" );
    # test cases for SELECT ... FROM ... JOIN ...
    $wpdb->query( <<<EOD
SELECT $wpdb->terms.name,$wpdb->term_taxonomy.taxonomy,$wpdb->term_relationships.object_id
    FROM $wpdb->terms,$wpdb->term_taxonomy,$wpdb->term_relationships
    WHERE $wpdb->terms.term_id = $wpdb->term_taxonomy.term_id AND $wpdb->term_taxonomy.term_taxonomy_id = $wpdb->term_relationships.term_taxonomy_id AND $wpdb->term_relationships.object_id = 177
EOD
    );
    $wpdb->query( <<<EOD
SELECT t.name, x.taxonomy, r.object_id
    FROM $wpdb->terms t, $wpdb->term_taxonomy x, $wpdb->term_relationships r
    WHERE t.term_id = x.term_id AND x.term_taxonomy_id = r.term_taxonomy_id AND r.object_id = 177
EOD
    );
    $wpdb->query( <<<EOD
SELECT t.name, x.taxonomy, r.object_id
    FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS x INNER JOIN $wpdb->term_relationships AS r
    ON t.term_id = x.term_id AND x.term_taxonomy_id = r.term_taxonomy_id
    WHERE r.object_id = 177
EOD
    );
} );
?>
