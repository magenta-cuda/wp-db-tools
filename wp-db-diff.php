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
                  ' ( cid INT NOT NULL AUTO_INCREMENT, table_name VARCHAR( 255 ) NOT NULL, operation VARCHAR( 31 ) NOT NULL, row_ids VARCHAR( 255 ) NOT NULL, PRIMARY KEY( cid ) )' );  
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
    
    $ddt_post_query = function ( $tables_orig, $id_for_table ) use ( $options ) {
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
        $suffix     = $options[ 'orig_suffix' ];
        $last_query = $wpdb->last_query;
        #error_log( 'ddt_post_query():$wpdb->last_query=' . $last_query );
       
        if ( $last_query && preg_match( $regex_or_tables_orig, $last_query ) === 1 ) {
            
            error_log( 'ddt_post_query():$wpdb->last_query=' . $last_query );
            
            if ( preg_match( '#^\s*(insert|replace)\s+(low_priority\s+|delayed\s+|high_priority\s+)*(into\s+)?(`)?(\w+)\4.+#i', $last_query, $matches ) ) {
                error_log( 'insert:$matches=' . print_r( $matches, true ) );
                $table     = $matches[ 5 ];
                if ( !in_array( $table, $tables_orig ) ) {
                    return;
                }
                $operation = 'INSERT';
                if ( $wpdb->use_mysqli ) {
                    $results = mysqli_insert_id( $wpdb->dbh );
                } else {
                    $results = mysql_insert_id( $wpdb->dbh );
                }
            } else if ( preg_match( '#^\s*update\s+(low_priority\s+)?(`)(\w+)\2.+\swhere\s(.+)$#i', $last_query, $matches ) ) {
                error_log( 'update:$matches=' . print_r( $matches, true ) );
                $table = $matches[ 3 ];
                if ( !in_array( $table, $tables_orig ) ) {
                    return;
                }
                $operation      = 'UPDATE';
                $where          = $matches[ 4 ];
                $id             = $id_for_table[ $table ];
                $doing_my_query = TRUE;
                $results        = $wpdb->get_col( "SELECT $id FROM $table WHERE $where" );
                $doing_my_query = FALSE;
                error_log( 'update:$results=' . print_r( $results, true ) );
            } else if ( preg_match( '/^\s*delete\s+(low_priority\s+|quick\s+)*from\s+(`)?(\w+)\2\s+where\s(.*)$/i', $last_query, $matches ) ) {
                error_log( '$delete:matches=' . print_r( $matches, true ) );
                $table = $matches[ 3 ];
                if ( !in_array( $table, $tables_orig ) ) {
                    return;
                }
                $operation      = 'DELETE';
                $where          = $matches[ 4 ];
                $id             = $id_for_table[ $table ];
                $doing_my_query = TRUE;
                $results = $wpdb->get_col( "SELECT $id FROM {$table}{$suffix} WHERE $where" );
                $doing_my_query = FALSE;
            } else if ( preg_match( '/^\s*select\s/i', $last_query ) ) {
            } else {
                error_log( 'ddt_post_query():unmatched: ' . $last_query );
            }
            if ( !empty( $table ) ) {
                $doing_my_query = TRUE;
                $wpdb->insert( MC_DIFF_CHANGES_TABLE, [ 'table_name' => $table, 'operation' => $operation, 'row_ids' => maybe_serialize( $results ) ], [ '%s', '%s' ] );
                $doing_my_query = FALSE;
            }
        }   # if ( $last_query && preg_match( $regex_or_tables_orig, $last_query ) === 1 ) {
    };

    add_filter( 'query', function( $query ) use ( $ddt_post_query, $tables_orig, $id_for_table ) {
        $ddt_post_query( $tables_orig, $id_for_table );
        return $query;
    } );

    register_shutdown_function( function( $ddt_post_query, $tables_orig, $id_for_table ) {
        error_log( 'shutdown:' );
        $ddt_post_query( $tables_orig, $id_for_table );
    }, $ddt_post_query, $tables_orig, $id_for_table );
    
    add_action( 'admin_menu', function( ) use ( $ddt_add_main_menu ) {
        
        add_submenu_page( MC_BACKUP_PAGE_NAME, 'Backup Tool', 'Backup Tool', 'export', MC_BACKUP_PAGE_NAME, $ddt_add_main_menu );
        # export?
        add_submenu_page( MC_BACKUP_PAGE_NAME, 'Diff Tool',     'Diff Tool', 'export', MC_DIFF_PAGE_NAME,   function( ) {
            global $wpdb;
?>
<h2>Database Diff Tool</h2>
<?php
            if ( !$wpdb->get_col( 'SHOW TABLES LIKE \'' . MC_DIFF_CHANGES_TABLE . '\'' ) ) {
?>
<div style="border:2px solid red;padding:10px 25px;margin:20px;">
There is no diff session active. You must enable the diff option of the &quot;Backup Tool&quot; to use the &quot;Diff Tool&quot;.
</div>
<?php
                return;
            }
            
            $results = $wpdb->get_results( 'SELECT table_name, operation, row_ids FROM ' . MC_DIFF_CHANGES_TABLE );
            $tables = [ ];
            foreach ( $results as $result ) {
                error_log( '$result=' . print_r( $result, true ) );
                $table_name = $result->table_name;
                $operation  = $result->operation;
                $row_ids    = $result->row_ids;
                if ( is_serialized( $row_ids ) ) {
                    $row_ids = unserialize( $row_ids );
                } else {
                    $row_ids = [ $row_ids ];
                }
                if ( !array_key_exists( $table_name, $tables ) ) {
                    $tables[ $table_name ] = [ ];
                    $tables[ $table_name ][ 'INSERT' ] = [ ];
                    $tables[ $table_name ][ 'UPDATE' ] = [ ];
                    $tables[ $table_name ][ 'DELETE' ] = [ ];
                }
                $ids =& $tables[ $table_name ][ $operation ];
                $ids = array_merge( $ids, $row_ids );
            }
            error_log( '$tables=' . print_r( $tables, true ) );
            echo '<table id="ddt_x-op_counts"><thead><tr><th>Table</th><th>Inserts</th><th>Updates</th><th>Deletes</th></tr></thead><tbody>'; 
            foreach ( $tables as $table_name => $table ) {
                $inserts = count( array_unique( $table[ 'INSERT' ] ) );
                $updates = count( array_unique( $table[ 'UPDATE' ] ) );
                $deletes = count( array_unique( $table[ 'DELETE' ] ) );
                echo "<tr><td>$table_name<input type=\"checkbox\"></td><td>$inserts<input type=\"checkbox\"></td><td>$updates<input type=\"checkbox\"></td><td>$deletes<input type=\"checkbox\"></td></tr>";
            }
            echo '</tbody></table>';
            echo '<button id="mc_view_changes" class="mc-wpdbdt-btn" type="button" disabled>View Selected</button>';
            echo '<label for="ddt_x-table_width">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Table Width:</label>';
            echo '<input type="text" id="ddt_x-table_width" placeholder="e.g. 2000px or 150%" value="100%">';
            echo '<label for="ddt_x-table_cell_size">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Table Cell Max Characters:</label>';
            echo '<input type="text" id="ddt_x-table_cell_size" placeholder="truncate cell contents to this number of characters" value="100">';
            echo '<div id="mc_changes_view"></div>';
        } );
    } );
    
    add_action( 'admin_enqueue_scripts', function( $hook ) {
        if ( strpos( $hook, MC_DIFF_PAGE_NAME ) !== FALSE ) {
            wp_enqueue_style(  'wp-db-tools', plugin_dir_url( __FILE__ ) . 'wp-db-tools.css' );
            wp_enqueue_script( 'wp-db-tools', plugin_dir_url( __FILE__ ) . 'wp-db-tools.js', [ 'jquery' ] );
        }
    } );
    
    if ( defined( 'DOING_AJAX' ) ) {

        add_action( 'wp_ajax_mc_view_changes', function( ) use ( $options, $id_for_table ) {
            global $wpdb;
            error_log( '$_POST=' . print_r( $_POST, true ) );
            $suffix    = $options[ 'orig_suffix' ];
            $table     = $_POST[ 'table' ];
            $table_id  = $id_for_table[ $table ];
            $operation = explode( ',', $_POST[ 'operation' ] );
            # replace pretty header labels for operation with the operation tag
            $operation = str_replace( 'Inserts', 'INSERT', $operation );
            $operation = str_replace( 'Updates', 'UPDATE', $operation );
            $operation = str_replace( 'Deletes', 'DELETE', $operation );
            # remove any invalid operation tags
            $operation = array_filter( $operation, function( $v ) {
                return in_array( $v, [ 'INSERT', 'UPDATE', 'DELETE' ] );
            } );
            $sql       = $wpdb->prepare( 'SELECT operation, row_ids FROM ' . MC_DIFF_CHANGES_TABLE
                                             . ' WHERE table_name = %s AND operation IN ( '
                                             . implode( ', ', array_slice( [ '%s', '%s', '%s' ], 0, count( $operation ) ) )
                                             . ' ) ORDER BY operation', array_merge( [ $table ], $operation ) );
            error_log( '$sql=' . $sql );
            $results   = $wpdb->get_results( $sql );    
            error_log( '$results=' . print_r( $results, true ) );
            $ids             = [ ];
            $ids[ 'INSERT' ] = [ ];
            $ids[ 'UPDATE' ] = [ ];
            $ids[ 'DELETE' ] = [ ];
            foreach ( $results as $result ) {
                $row_ids = $result->row_ids;
                if ( is_serialized( $row_ids ) ) {
                    $row_ids = unserialize( $row_ids );
                } else {
                    $row_ids = [ $row_ids ];
                }
                $idsr =& $ids[ $result->operation ];
                $idsr = array_merge( $idsr, $row_ids );
            }
            $ids[ 'INSERT' ] = array_unique( $ids[ 'INSERT' ] );
            $ids[ 'UPDATE' ] = array_unique( $ids[ 'UPDATE' ] );
            $ids[ 'DELETE' ] = array_unique( $ids[ 'DELETE' ] );
            sort( $ids[ 'INSERT' ] );
            sort( $ids[ 'UPDATE' ] );
            sort( $ids[ 'DELETE' ] );
            # remove updates to inserted and deleted rows as the net effect for the session is an insert or delete
            $ids[ 'UPDATE' ] = array_diff( $ids[ 'UPDATE' ], $ids[ 'INSERT' ] );
            $ids[ 'UPDATE' ] = array_diff( $ids[ 'UPDATE' ], $ids[ 'DELETE' ] );
            # remove deleted rows from inserted rows as the net effect for the session is the insert/delete did not occur
            $ids[ 'INSERT' ] = array_diff( $ids[ 'INSERT' ], $ids[ 'DELETE' ] );
            # remove inserted rows from deleted rows as the net effect for the session is the insert/delete did not occur
            $ids[ 'DELETE' ] = array_diff( $ids[ 'DELETE' ], $ids[ 'INSERT' ] );
            error_log( '$ids=' . print_r( $ids, true ) );
            $columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table );
            $columns = array_filter( $columns, function( $v ) use ( $table_id ) {
                return $v !== $table_id;
            } );
            array_unshift( $columns, $table_id );
            error_log( '$columns=' . print_r( $columns, true ) );
            $columns_imploded = implode( ', ', $columns );
            if ( $ids[ 'INSERT' ] ) {
                echo '<table class="ddt_x-table_changes mc_table_changes">';
                foreach ( $columns as $column ) {
                    echo '<th>' . $column . '</th>';
                }
                $inserts   = $wpdb->get_results( 'SELECT ' . $columns_imploded . ' FROM ' . $table           
                                                    . ' WHERE ' . $table_id . ' IN ( ' . implode( ', ', $ids[ 'INSERT' ] ) . ' )', OBJECT_K );
                foreach ( $ids[ 'INSERT' ] as $id ) {
                    if ( !array_key_exists( $id, $inserts) ) {
                        error_log( "ERROR:action:wp_ajax_mc_view_changes:bad INSERT id \"$id\" for table \"$table\"." );
                        continue;
                    }
                    echo '<tr class="ddt_x-changes-updated">';
                    foreach ( $columns as $column ) {
                        echo '<td class="ddt_x-field_changed">' . $inserts[ $id ]->$column . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</table>';
            }
            if ( $ids[ 'UPDATE' ] ) {
                echo '<table class="ddt_x-table_changes mc_table_changes">';
                foreach ( $columns as $column ) {
                    echo '<th>' . $column . '</th>';
                }
                $updates   = $wpdb->get_results( 'SELECT ' . $columns_imploded . ' FROM ' . $table           
                                                    . ' WHERE ' . $table_id . ' IN ( ' . implode( ', ', $ids[ 'UPDATE' ] ) . ' )', OBJECT_K );
                $originals = $wpdb->get_results( 'SELECT ' . $columns_imploded . ' FROM ' . $table . $suffix
                                                    . ' WHERE ' . $table_id . ' IN ( ' . implode( ', ', $ids[ 'UPDATE' ] ) . ' )', OBJECT_K );
                foreach ( $ids[ 'UPDATE' ] as $id ) {
                    if ( !array_key_exists( $id, $originals ) ) {
                        error_log( "ERROR:action:wp_ajax_mc_view_changes:bad UPDATE id \"$id\" for table \"$table\"." );
                        continue;
                    }
                    echo '<tr class="ddt_x-changes-original">';
                    foreach ( $columns as $column ) {
                        $td_class = strcmp( $originals[ $id ]->$column, $updates[ $id ]->$column ) ? ' class="ddt_x-field_changed"' : '';
                        echo '<td' . $td_class . '>' . $originals[ $id ]->$column . '</td>';
                    }
                    echo '</tr>';
                    echo '<tr class="ddt_x-changes-updated">';
                    foreach ( $columns as $column ) {
                        $td_class = strcmp( $originals[ $id ]->$column, $updates[ $id ]->$column ) ? ' class="ddt_x-field_changed"' : '';
                        echo '<td' . $td_class . '>' . $updates[ $id ]->$column . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</table>';
            }
            if ( $ids[ 'DELETE' ] ) {
                echo '<table class="ddt_x-table_changes mc_table_changes">';
                foreach ( $columns as $column ) {
                    echo '<th>' . $column . '</th>';
                }
                $deletes   = $wpdb->get_results( 'SELECT ' . $columns_imploded . ' FROM ' . $table . $suffix
                                                    . ' WHERE ' . $table_id . ' IN ( ' . implode( ', ', $ids[ 'DELETE' ] ) . ' )', OBJECT_K );
                foreach ( $ids[ 'DELETE' ] as $id ) {
                    if ( !array_key_exists( $id, $deletes ) ) {
                        error_log( "ERROR:action:wp_ajax_mc_view_changes:bad DELETE id \"$id\" for table \"$table\"." );
                        continue;
                    }
                    echo '<tr class="ddt_x-changes-original">';
                    foreach ( $columns as $column ) {
                        echo '<td class="ddt_x-field_changed">' . $deletes[ $id ]->$column . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</table>';
            }
            die;
       } );

    }   # if ( defined( 'DOING_AJAX' ) ) {

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
