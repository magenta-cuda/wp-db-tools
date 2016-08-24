<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit();
}

$option_names = [
    'ddt_x-wp_db_tools'
];

foreach ( $option_names as $option_name ) {
    delete_option( $option_name );
}

?>

