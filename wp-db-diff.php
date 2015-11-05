<?php

/*
Module Name: WordPress Database Developer Tools: Diff
Plugin URI: https://wpdbdt.wordpress.com/
Description: Easy Backup for Testing
Version: 1.0
Author: Magenta Cuda
Author URI: https://profiles.wordpress.org/magenta-cuda/
License: GPL2
*/

/*  Copyright 2015  Magenta Cuda

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

/*
Project X: WordPress Database Developer Tools: Diff

 */
 
namespace mc_x_wp_db_tools {

define( 'MC_DIFF_CHANGES_TABLE', 'mc_diff_modified' );
define( 'MC_DIFF_PAGE_NAME', 'ddt_diff_tool' );

function ddt_wp_db_diff_start_session( ) {
    global $wpdb;
    $wpdb->query( 'CREATE TABLE ' . MC_DIFF_CHANGES_TABLE .
                  ' ( cid INT NOT NULL, table_name VARCHAR( 255 ) NOT NULL, row_ids VARCHAR( 255 ) NOT NULL, PRIMARY KEY( cid ) )' );  
};

function ddt_wp_db_diff_end_session( ) {
    global $wpdb;
    $wpdb->query( 'DROP TABLE ' . MC_DIFF_CHANGES_TABLE );
};

function ddt_wp_db_diff_init( $options, $ddt_add_main_menu ) {
    global $wpdb;
    
    $tables_orig = ddt_get_backup_tables( $options[ 'orig_suffix' ] );
    
    $id_for_table = [ ];
    
    $tables = $wpdb->get_col( 'show tables' );
    foreach ( $tables as $table ) {
        if ( !in_array( $table, $tables_orig ) ) {
            continue;
        }
        $results= $wpdb->get_results( 'show columns from ' . $table );
        foreach( $results as $result ) {
            if ( $result->Key === 'PRI' ) {
                $id_for_table[ $table ] = $result->Field;
            }
        }
    }
    error_log( '$id_for_table=' . print_r( $id_for_table, true ) );
    
    function ddt_post_query( $tables_orig, $id_for_table ) {
        global $wpdb;
        
        static $regex_or_tables_orig = NULL;
        if ( !$regex_or_tables_orig ) {
            $regex_or_tables_orig = '#(\s|`)(' . implode( '|', $tables_orig ) . ')\1#';
            error_log( 'ddt_post_query():$regex_or_tables_orig=' . $regex_or_tables_orig );
        }
        
        static $doing_my_query = FALSE;
        
        if ( $doing_my_query ) {
            return;
        }
        
        $last_query = $wpdb->last_query;
        #error_log( 'ddt_post_query():$wpdb->last_query=' . $last_query );
       
        if ( $last_query && preg_match( $regex_or_tables_orig, $last_query ) === 1 ) {
            
            error_log( 'ddt_post_query():$wpdb->last_query=' . $last_query );
            
            if ( preg_match( '#^\s*(insert|replace)\s+(low_priority\s+|delayed\s+|high_priority\s+)*(into\s+)?.+#i', $last_query, $matches ) ) {
                error_log( 'insert:$matches=' . print_r( $matches, true ) );
                $table = $matches[ 4 ];
                if ( !in_array( $table, $tables_orig ) ) {
                    return;
                }
                if ( $wpdb->use_mysqli ) {
                    $result = mysqli_insert_id( $wpdb->dbh );
                } else {
                    $result = mysql_insert_id( $wpdb->dbh );
                }
            } else if ( preg_match( '#^\s*update\s+(low_priority\s+)?(\s|`)(\w+)\2.+\swhere\s(.+)$#i', $last_query, $matches ) ) {
                error_log( 'update:$matches=' . print_r( $matches, true ) );
                $table = $matches[ 3 ];
                if ( !in_array( $table, $tables_orig ) ) {
                    return;
                }
                $id    = $id_for_table[ $table ];
                $where = $matches[ 4 ];
                $doing_my_query = TRUE;
                $results = $wpdb->get_col( "SELECT $id FROM $table WHERE $where" );
                $doing_my_query = FALSE;
                error_log( 'update:$results=' . print_r( $results, true ) );
            } else if ( preg_match( '/^\s*delete\s+(low_priority\s+|quick\s+)*from\s+(\w+)\s+where\s(.*)$/i', $last_query, $matches ) ) {
                error_log( '$delete:matches=' . print_r( $matches, true ) );
                $table = $matches[ 2 ];
                if ( !in_array( $table, $tables_orig ) ) {
                    return;
                }
                $id    = $id_for_table[ $table ];
                $where = $matches[ 3 ];
                $doing_my_query = TRUE;
                $results = $wpdb->get_col( "SELECT $id FROM $table WHERE $where" );
                $doing_my_query = FALSE;
            } else if ( preg_match( '/^\s*select\s/i', $last_query ) ) {
            } else {
                error_log( 'ddt_post_query():unmatched: ' . $last_query );
            }
            if ( !empty( $table ) ) {
                $wpdb->insert( MC_DIFF_CHANGES_TABLE, [ 'table_name' => $table, 'row_ids' => maybe_serialize( $results ) ], [ '%s', '%s' ] );
            }
        }
    };

    add_filter( 'query', function( $query ) use ( $tables_orig, $id_for_table ) {
        ddt_post_query( $tables_orig, $id_for_table );
        return $query;
    } );

    register_shutdown_function( function( $tables_orig, $id_for_table ) {
        error_log( 'shutdown:' );
        ddt_post_query( $tables_orig, $id_for_table );
    }, $tables_orig, $id_for_table );
    
    add_action( 'admin_menu', function( ) use ( $ddt_add_main_menu ) {
        
        add_submenu_page( MC_BACKUP_PAGE_NAME, 'Backup Tool', 'Backup Tools', 'export', MC_BACKUP_PAGE_NAME, $ddt_add_main_menu );
        # export?
        add_submenu_page( MC_BACKUP_PAGE_NAME, 'Diff Tool',    'Diff Tools:', 'export', MC_DIFF_PAGE_NAME,   function( ) {
?>
<h2>Database Developer's Tools: Diff Tool</h2>
<?php
        } );
    } );
    
};   # function ddt_wp_db_diff_init( $options ) {

ddt_wp_db_diff_init( $options, $ddt_add_main_menu );

}   # namespace mc_x_wp_db_tools {

namespace {

if ( function_exists( 'ddt_get_backup_tables' ) ) {
    error_log( 'ddt_get_backup_tables exists' );
} else {
    error_log( 'ddt_get_backup_tables does not exists' );
}    

//error_log( '$options=' . print_r( $options, true ) );

}   # namespace {

?>
