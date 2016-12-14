<?php

/*
Plugin Name: Database Debugging Tools for Developers
Plugin URI:  https://wpdbdt.wordpress.com/
Description: Database tools for WordPress developers
Version:     2.2
Author:      Magenta Cuda
Author URI:  https://profiles.wordpress.org/magenta-cuda/
License:     GPL2
*/

/*  
    Copyright 2013  Magenta Cuda

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace ddt_x_wp_db_tools {

function ddt_plugin_basename( ) {
    return plugin_basename( __FILE__ );
}

function ddt_wp_db_tools_init( ) {
    # The check for version is in its own file since if the file contains PHP 5.4 code an ugly fatal error will be triggered instead

    list( $major, $minor ) = sscanf( phpversion(), '%D.%D' );
    $tested_major = 5;
    $tested_minor = 4;
    if ( !( $major > $tested_major || ( $major == $tested_major && $minor >= $tested_minor ) ) ) {
        add_action( 'admin_notices', function () use ( $major, $minor, $tested_major, $tested_minor ) {
            echo <<<EOD
    <div style="padding:10px 20px;border:2px solid red;margin:50px 20px;font-weight:bold;">
        &quot;WordPress Database Developer Tools&quot; will not work with PHP version $major.$minor;
        Please uninstall it or upgrade your PHP version to $tested_major.$tested_minor or later.
    </div>
EOD;
        } );
        return;
    }

    # ok to start loading PHP 5.4 code
    # load from inside a function to hide variables from the global scope
    
    require_once( __DIR__  . '/wp-db-backup.php' );
    if ( ddt_in_diff_session( ) ) {
        ddt_wp_db_diff_included( include_once( __DIR__ . '/wp-db-diff.php' ) );
    }
}

}   # namespace ddt_x_wp_db_tools {

namespace {
    
ddt_x_wp_db_tools\ddt_wp_db_tools_init( );

}   # namespace {

?>
