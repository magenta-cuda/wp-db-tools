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

define( 'MC_DIFF_CHANGES_TABLE', 'ddt_x_diff_tool_changes_1113' );
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
    
    $tables_orig = ddt_get_backup_tables( $options[ 'ddt_x-orig_suffix' ] );
    
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
        $suffix     = $options[ 'ddt_x-orig_suffix' ];
        $last_query = $wpdb->last_query;
        #error_log( 'ddt_post_query():$wpdb->last_query=' . $last_query );
       
        if ( $last_query && preg_match( $regex_or_tables_orig, $last_query ) === 1 ) {
            
            error_log( 'ddt_post_query():$wpdb->last_query=' . $last_query );
            
            if ( preg_match( '#^\s*(insert|replace)\s+(low_priority\s+|delayed\s+|high_priority\s+)*(into\s*)?(\s|`)(\w+)\4.+#i', $last_query, $matches ) ) {
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
            } else if ( preg_match( '#^\s*update\s*(low_priority\s*)?(\s|`)(\w+)\2.+\swhere\s(.+)$#i', $last_query, $matches ) ) {
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
            } else if ( preg_match( '#^\s*delete\s+(low_priority\s+|quick\s+)*from\s*(\s|`)(\w+)\2\s*where\s(.*)$#i', $last_query, $matches ) ) {
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
                if ( !$results ) {
                    $results = -1;
                }
            } else if ( preg_match( '/^\s*select\s/i', $last_query ) ) {
            } else if ( preg_match( '/^\s*show\s/i', $last_query ) ) {
            } else {
                error_log( 'ddt_post_query():unmatched: ' . $last_query );
            }
            if ( !empty( $table ) && !empty( $results ) && $results !== -1 ) {
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
    
    if ( !defined( 'DOING_AJAX' ) ) {
        
        add_action( 'admin_menu', function( ) use ( $ddt_add_main_menu, $options, $id_for_table ) {
            
            add_submenu_page( MC_BACKUP_PAGE_NAME, 'Backup Tool', 'Backup Tool', 'export', MC_BACKUP_PAGE_NAME, $ddt_add_main_menu );
            # export?
            add_submenu_page( MC_BACKUP_PAGE_NAME, 'Diff Tool',     'Diff Tool', 'export', MC_DIFF_PAGE_NAME,   function( ) use ( $options, $id_for_table ) {
                global $wpdb;
?>
<h2>Database Diff Tool</h2>
<?php
                if ( !$wpdb->get_col( 'SHOW TABLES LIKE \'' . MC_DIFF_CHANGES_TABLE . '\'' ) ) {
?>
<div class="ddt_x-error_message">
There is no diff session active. You must enable the diff option of the &quot;Backup Tool&quot; to use the &quot;Diff Tool&quot;.
</div>
<?php
                    return;
                }
                
                $results = $wpdb->get_results( 'SELECT table_name, operation, row_ids FROM ' . MC_DIFF_CHANGES_TABLE );
                if ( !$results ) {
?>
<div class="ddt_x-info_message">
No database operations have been done on the selected tables.
</div>
<?php
                    return;
                }
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
                    $table_id = $id_for_table[ $table_name ];
                    $inserts  = array_unique( $table[ 'INSERT' ] );
                    $deletes  = array_unique( $table[ 'DELETE' ] );
                    if ( $inserts ) {
                        # get the deleted inserts which are not yet included in $deletes
                        $existing_inserts = $wpdb->get_col( "SELECT $table_id FROM $table_name WHERE $table_id IN ( " . implode( ', ', $inserts ) . ' )' );
                        if ( count( $existing_inserts ) < count( $inserts ) ) {
                            $deletes = array_unique( array_merge( $deletes, array_diff( $inserts, $existing_inserts ) ) );
                        }
                    }
                    $updates     = array_unique( $table[ 'UPDATE' ] );
                    $inserts_all = $inserts;
                    $inserts     = array_diff( $inserts, $deletes );
                    $updates     = array_diff( $updates, $inserts, $deletes );
                    $deletes     = array_diff( $deletes, $inserts_all );
                    $inserts     = count( $inserts );
                    $updates     = count( $updates );
                    $deletes     = count( $deletes );
                    echo "<tr><td>$table_name<input type=\"checkbox\"></td><td>$inserts<input type=\"checkbox\"></td><td>$updates<input type=\"checkbox\"></td><td>$deletes<input type=\"checkbox\"></td></tr>";
                }
                echo '</tbody></table>';
                echo '<div id="ddt_x-diff_controls">';
                echo '<button id="ddt_x-diff_view_changes" class="mc-wpdbdt-btn" type="button" disabled>View Selected</button>';
                echo '<label for="ddt_x-table_width">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Table Width: </label>';
                echo '<input type="text" id="ddt_x-table_width" placeholder="e.g. 2000px or 150%" value="' . $options[ 'ddt_x-table_width' ] . '">';
                echo '<label for="ddt_x-table_cell_size">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Table Cell Max Characters: </label>';
                echo '<input type="text" id="ddt_x-table_cell_size" placeholder="truncate content to this" value="' . $options[ 'ddt_x-table_cell_size' ] . '">';
                echo '</div>';
                echo '<div id="mc_changes_view"></div>';
?>
<div id="ddt_x-popup-margin">
</div>
<div id="ddt_x-detail_popup">
    <button id="ddt_x-close_detail_popup">X</button>
    <div style="clear:both;">
        <div id="ddt_x-detail_content" class="ddt_x-detail_content"></div>
        <div id="ddt_x-detail_content_other" class="ddt_x-detail_content_other ddt_x-detail_content"></div>
    </div>
</div>
<?php
            } );
        } );
    
        add_action( 'admin_enqueue_scripts', function( $hook ) {
            if ( strpos( $hook, MC_DIFF_PAGE_NAME ) !== FALSE ) {
                wp_enqueue_style(  'wp-db-tools', plugin_dir_url( __FILE__ ) . 'wp-db-tools.css' );
                wp_enqueue_script( 'wp-db-tools', plugin_dir_url( __FILE__ ) . 'wp-db-tools.js', [ 'jquery' ] );
            }
        } );

    }   # if ( !defined( 'DOING_AJAX' ) ) {
   
    if ( defined( 'DOING_AJAX' ) ) {

        add_action( 'wp_ajax_ddt_x-diff_view_changes', function( ) use ( $options, $id_for_table ) {
            global $wpdb;
            error_log( '$_POST=' . print_r( $_POST, true ) );
            $suffix    = $options[ 'ddt_x-orig_suffix' ];
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
?>
<div class="ddt_x-info_message">
Table cells with content ending in &quot;...&quot; have been truncated. You can view the original content by clicking on the cell.
</div>
<?php
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
            $inserts   = $wpdb->get_results( 'SELECT ' . $columns_imploded . ' FROM ' . $table           
                                                . ' WHERE ' . $table_id . ' IN ( ' . implode( ', ', $ids[ 'INSERT' ] ) . ' )', OBJECT_K );
            $updates   = $wpdb->get_results( 'SELECT ' . $columns_imploded . ' FROM ' . $table           
                                                . ' WHERE ' . $table_id . ' IN ( ' . implode( ', ', $ids[ 'UPDATE' ] ) . ' )', OBJECT_K );
            $originals = $wpdb->get_results( 'SELECT ' . $columns_imploded . ' FROM ' . $table . $suffix
                                                . ' WHERE ' . $table_id . ' IN ( ' . implode( ', ', $ids[ 'UPDATE' ] ) . ' )', OBJECT_K );
            $deletes   = $wpdb->get_results( 'SELECT ' . $columns_imploded . ' FROM ' . $table . $suffix
                                                . ' WHERE ' . $table_id . ' IN ( ' . implode( ', ', $ids[ 'DELETE' ] ) . ' )', OBJECT_K );
            echo '<table class="ddt_x-table_changes mc_table_changes">';
            foreach ( $columns as $column ) {
                echo '<th>' . $column . '</th>';
            }
            $insert_ids = $ids[ 'INSERT' ];
            $update_ids = $ids[ 'UPDATE' ];
            $delete_ids = $ids[ 'DELETE' ];
            while ( TRUE ) {
                $insert_id = current( $insert_ids );
                $update_id = current( $update_ids );
                $delete_id = current( $delete_ids );
                if ( $insert_id === FALSE && $update_id === FALSE && $delete_id === FALSE ) {
                    break;
                }
                if ( $insert_id !== FALSE && ( $update_id === FALSE || $insert_id < $update_id ) && ( $delete_id === FALSE || $insert_id < $delete_id ) ) {
                    $operation = 'INSERT';
                    $id = $insert_id;
                    next( $insert_ids );
                } else if ( $update_id !== FALSE && ( $insert_id === FALSE || $update_id < $insert_id ) && ( $delete_id === FALSE || $update_id < $delete_id ) ) {
                    $operation  = 'UPDATE';
                    $id = $update_id;
                    next( $update_ids );
                } else {
                    $operation = 'DELETE';
                    $id = $delete_id;
                    next( $delete_ids );
                }
                if ( $operation === 'INSERT' ) {
                    if ( !array_key_exists( $id, $inserts) ) {
                        error_log( "ERROR:action:wp_ajax_ddt_x-diff_view_changes:bad INSERT id \"$id\" for table \"$table\"." );
                        continue;
                    }
                    echo '<tr class="ddt_x-changes_updated">';
                    foreach ( $columns as $column ) {
                        echo '<td class="ddt_x-field_changed">' . $inserts[ $id ]->$column . '</td>';
                    }
                    echo '</tr>';
                } else if ( $operation === 'UPDATE' ) {
                    if ( !array_key_exists( $id, $originals ) ) {
                        error_log( "ERROR:action:wp_ajax_ddt_x-diff_view_changes:bad UPDATE id \"$id\" for table \"$table\"." );
                        continue;
                    }
                    echo '<tr class="ddt_x-changes_original">';
                    foreach ( $columns as $column ) {
                        $td_class = strcmp( $originals[ $id ]->$column, $updates[ $id ]->$column ) ? ' class="ddt_x-field_changed"' : '';
                        echo '<td' . $td_class . '>' . $originals[ $id ]->$column . '</td>';
                    }
                    echo '</tr>';
                    echo '<tr class="ddt_x-changes_updated">';
                    foreach ( $columns as $column ) {
                        $td_class = strcmp( $originals[ $id ]->$column, $updates[ $id ]->$column ) ? ' class="ddt_x-field_changed"' : '';
                        echo '<td' . $td_class . '>' . $updates[ $id ]->$column . '</td>';
                    }
                    echo '</tr>';
                } else if ( $operation === 'DELETE' ) {
                    if ( !array_key_exists( $id, $deletes ) ) {
                        error_log( "ERROR:action:wp_ajax_ddt_x-diff_view_changes:bad DELETE id \"$id\" for table \"$table\"." );
                        continue;
                    }
                    echo '<tr class="ddt_x-changes_original">';
                    foreach ( $columns as $column ) {
                        echo '<td class="ddt_x-field_changed">' . $deletes[ $id ]->$column . '</td>';
                    }
                    echo '</tr>';
                }
            }   # while ( TRUE ) {
            echo '</table>';
            die;
        } );   # add_action( 'wp_ajax_ddt_x-diff_view_changes', function( ) use ( $options, $id_for_table ) {
           
        add_action( 'wp_ajax_ddt_x-update_diff_options', function( ) use ( $options ) {
            foreach( [ 'ddt_x-table_width', 'ddt_x-table_cell_size' ] as $option ) {
                if ( !empty( $_POST[ $option ] ) ) {
                    $options[ $option ] = $_POST[ $option ];
                }
            }
            update_option( 'ddt-x-wp_db_tools', $options ); 
        } );   # add_action( 'wp_ajax_ddt_x-update_diff_options', function( ) use ( $options ) {
        
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
