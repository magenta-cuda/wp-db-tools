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

Diff works by spying on database operations using the filter 'query'. This will intercept all database operations done through the Wordpress API,
e.g. $wpdb->get_results, $wpdb->query, ... update_post_meta (which will call $wpdb->update ), ... Of course, it will not spy on database operations
done directly through the PHP MySQL API, e.g. mysqli_query, mysql_query, ... Further, it cannot spy on database operations that occur before the
filter is installed. The filter is installed when the plugin is loaded which is probably early enough for most uses but will not spy on database
operations done on the load of plugins loaded before this plugin. Some of the more exotic MySQL commands are not currently handled. These will 
generate an error message like this "ERROR:ddt_post_query():unknown MySQL operation: ..." using the error_log function.
 */
 
namespace ddt_x_wp_db_tools {

const DDT_DIFF_CHANGES_TABLE = 'ddt_x_diff_tool_changes_1113';
const DDT_DIFF_PAGE_NAME     = 'ddt_diff_tool';

function ddt_get_diff_changes_table( ) {
    return DDT_DIFF_CHANGES_TABLE;
}

function ddt_wp_db_diff_start_session( ) {
    global $wpdb;
    $wpdb->query( 'CREATE TABLE ' . ddt_get_diff_changes_table( ) .
                  ' ( cid INT NOT NULL AUTO_INCREMENT, table_name VARCHAR( 255 ) NOT NULL, operation VARCHAR( 31 ) NOT NULL, row_ids VARCHAR( 255 ) NOT NULL, PRIMARY KEY( cid ) )' );  
}   # function ddt_wp_db_diff_start_session( ) {


function ddt_wp_db_diff_end_session( ) {
    global $wpdb;
    $wpdb->query( 'DROP TABLE ' . ddt_get_diff_changes_table( ) );
}   # function ddt_wp_db_diff_end_session( ) {

function ddt_wp_db_diff_prettify( $content ) {
    if ( is_serialized( $content ) ) {
        return json_encode( unserialize( $content ), JSON_HEX_TAG | JSON_FORCE_OBJECT );
    }
    return htmlspecialchars( $content );
}   # function ddt_wp_db_diff_prettify( $content ) {

function ddt_wp_db_diff_get_next_mysql_token( $buffer, &$position ) {
    $length = strlen( $buffer );
    $first_start = NULL;
    $first_quote = NULL;
    while ( $position < $length && ctype_space( substr( $buffer, $position, 1 ) ) ) {
        ++$position;
    }
    if ( $position === $length ) {
        return FALSE;
    }
at_start_of_token:
    $char = substr( $buffer, $position, 1 );
    if ( ctype_digit( $char ) ) {
        $quote = '';
        $start = $position++;
        while ( $position < $length && ctype_digit( substr( $buffer, $position, 1 ) ) ) {
            ++$position;
        }
        $end = $position;
    } else if ( $char === '\'' || $char === '"' ) {
        $quote = $char;
        $start = ++$position;
        while ( TRUE ) {
            if ( ( $i = strpos( $buffer, $quote, $position ) ) === FALSE ) {
                $position = $length;
                return FALSE;
            }
            if ( $i + 1 < $length && substr_compare( $buffer, $quote, $i + 1, 1 ) === 0 ) {
                $position = $i + 2;
            } else if ( substr_compare( $buffer, '\\', $i - 1, 1 ) === 0 ) {
                $position = $i + 1;
            } else {
                $end = $i;
                $position = $end + 1;
                break;
            }
        }
    }
    while ( $position < $length && ctype_space( substr( $buffer, $position, 1 ) ) ) {
        ++$position;
    }
    if ( $position < $length && ( substr_compare( $buffer, '\'', $position, 1 ) === 0 || substr_compare( $buffer, '"', $position, 1 ) === 0 ) ) {
        # this is a concatenation
        if ( !$first_start ) {
            $first_start = $start;
            $first_quote = $quote;
        }
        goto at_start_of_token;   # the first goto I have used in 3 years of PHP programming
    }
    if ( $position < $length && substr_compare( $buffer, ',', $position, 1 ) === 0 ) {
        ++$position;
    }
    if ( !$first_start ) {
        $first_start = $start;
        $first_quote = $quote;
    }
    return $first_quote . substr( $buffer, $first_start, $end - $first_start ) . $quote;
}   # function ddt_wp_db_diff_get_next_mysql_token( $buffer, &$position ) {

function ddt_id_for_table( $i = NULL ) {
    static $id_for_table = NULL;
    if ( $i !== NULL ) {
        $id_for_table = $i;
    }
    return $id_for_table;
}   # function ddt_id_for_table( $i = NULL ) {

function ddt_wp_db_diff_init( ) {
    global $wpdb;

    $options = ddt_get_options( );

    $backed_up_tables = ddt_backed_up_tables( );

    $id_for_table = [ ];
    $tables = $wpdb->get_col( 'show tables' );
    foreach ( $tables as $table ) {
        if ( !in_array( $table, $backed_up_tables ) ) {
            continue;
        }
        $results= $wpdb->get_results( 'show columns from ' . $table );
        foreach( $results as $result ) {
            if ( $result->Key === 'PRI' ) {
                $id_for_table[ $table ] = $result->Field;
            }
        }
    }
    ddt_id_for_table( $id_for_table );

    function ddt_post_query( ) {
        global $wpdb;

        $options          = ddt_get_options( );
        $backed_up_tables = ddt_backed_up_tables( );
        $id_for_table     = ddt_id_for_table( );

        static $regex_of_tables_orig = NULL;
        if ( !$regex_of_tables_orig ) {
            $regex_of_tables_orig = '#(\s|`)(' . implode( '|', $backed_up_tables ) . ')\1#';
        }
 
        static $doing_my_query = FALSE;
        if ( $doing_my_query ) {
            # prevent infinite recursion
            return;
        }

        $suffix     = $options[ 'ddt_x-orig_suffix' ];
        $last_query = $wpdb->last_query;
        if ( $last_query && preg_match( $regex_of_tables_orig, $last_query ) === 1 ) {
            if ( preg_match( '#^\s*(insert|replace)\s*(low_priority\s*|delayed\s*|high_priority\s*)*(into\s*)?(\s|`)(\w+)\4.+#i', $last_query, $matches ) ) {
                # INSERT or REPLACE operation
                $table     = $matches[ 5 ];
                if ( !in_array( $table, $backed_up_tables ) ) {
                    return;
                }
                $operation = 'INSERT';
                if ( $wpdb->use_mysqli ) {
                    $results = mysqli_insert_id( $wpdb->dbh );
                } else {
                    $results = mysql_insert_id( $wpdb->dbh );
                }
                if ( !$results ) {
                    # the primary key must have been specified so ...
                    if ( preg_match ( '#\(\s*((`?\w+`?,\s*)*(`?\w+`?))\s*\)\s*values?\s*\(\s*(.+)\s*\)\s*(on|$)#i', $last_query, $matches ) ) {
                        # parse column names
                        preg_match_all( '#\w+#', $matches[ 1 ], $fields );
                        # find the position of the primary key
                        if ( ( $index = array_search( $id_for_table[ $table ], $fields[ 0 ] ) ) !== FALSE ) {
                            $values = $matches[ 4 ];
                            $position = 0;
                            for ( $i = 0; $i <= $index; $i++ ) {
                                # parse column values until the corresponding position of the primary key
                                $value = ddt_wp_db_diff_get_next_mysql_token( $values, $position );
                            }
                            if ( preg_match( '#^(\'|")(.*)\1$#', $value, $matches ) ) {
                                $results = $matches[ 2 ];
                            } else {
                                $results = $value;
                            }
                        }   
                    } else if ( preg_match( '#' . $id_for_table[ $table ] . '\s*(\'|")(\.+?)\1#', $last_query, $matches ) ) {
                        $results = $matches[ 2 ];
                    }
                    if ( !$results ) {
                        error_log( 'ERROR:ddt_post_query():INSERT id not known: ' . $last_query );
                    }
                }
            } else if ( preg_match( '#^\s*update\s*(low_priority\s*)?(\s|`)(\w+)\2.+\swhere\s(.+)$#i', $last_query, $matches ) ) {
                # UPDATE operation
                $table = $matches[ 3 ];
                if ( !in_array( $table, $backed_up_tables ) ) {
                    return;
                }
                $operation      = 'UPDATE';
                $where          = $matches[ 4 ];
                $id             = $id_for_table[ $table ];
                $doing_my_query = TRUE;
                $results        = $wpdb->get_col( "SELECT $id FROM {$table}{$suffix} WHERE $where" );
                $doing_my_query = FALSE;
                if ( !$results ) {
                    # this can occur when the update changes the value of a field in the where clause
                    #error_log( 'WARNING:ddt_post_query():UPDATE id not known: ' . $last_query );
                }
            } else if ( preg_match( '#^\s*delete\s+(low_priority\s+|quick\s+)*from\s*(\s|`)(\w+)\2\s*where\s(.*)$#i', $last_query, $matches ) ) {
                # DELETE operation
                $table = $matches[ 3 ];
                if ( !in_array( $table, $backed_up_tables ) ) {
                    return;
                }
                $operation      = 'DELETE';
                $where          = $matches[ 4 ];
                $id             = $id_for_table[ $table ];
                $doing_my_query = TRUE;
                $results = $wpdb->get_col( "SELECT $id FROM {$table}{$suffix} WHERE $where" );
                $doing_my_query = FALSE;
                if ( !$results ) {
                    # this is a delete of a row that was inserted in this session
                    $results = -1;
                }
            } else if ( preg_match( '/^\s*select\s/i', $last_query ) ) {
                # SELECT operation is ignored
            } else if ( preg_match( '/^\s*show\s/i', $last_query ) ) {
                # SHOW operation is ignored
            } else if ( preg_match( '/^\s*create\s/i', $last_query ) ) {
                # CREATE operation is ignored
            } else if ( preg_match( '/^\s*drop\s/i', $last_query ) ) {
                # DROP operation is ignored
            } else {
                # This case should not happen
                error_log( 'ERROR:ddt_post_query():unknown MySQL operation: ' . $last_query );
            }
            if ( !empty( $table ) && !empty( $results ) && $results !== -1 ) {
                # omit deletes of rows inserted in this session since the row id is not known
                # and anyway the net result with respect to the session is that the insert did not occur
                $doing_my_query = TRUE;
                $wpdb->insert( ddt_get_diff_changes_table( ), [ 'table_name' => $table, 'operation' => $operation, 'row_ids' => maybe_serialize( $results ) ], [ '%s', '%s' ] );
                $doing_my_query = FALSE;
            }
        }   # if ( $last_query && preg_match( $regex_of_tables_orig, $last_query ) === 1 ) {
    }   # function ddt_post_query( ) {

    add_filter( 'query', function( $query ) {
        ddt_post_query( );
        return $query;
    } );

    register_shutdown_function( function( ) {
        ddt_post_query( );
    } );
    
    if ( !defined( 'DOING_AJAX' ) ) {
        
        add_action( 'admin_menu', function( ) {
            add_submenu_page( DDT_BACKUP_PAGE_NAME, 'Backup Tool', 'Backup Tool', 'export', DDT_BACKUP_PAGE_NAME, '\ddt_x_wp_db_tools\ddt_add_main_menu' );
            # export?
            add_submenu_page( DDT_BACKUP_PAGE_NAME, 'Diff Tool', 'Diff Tool', 'export', DDT_DIFF_PAGE_NAME, function( ) {
                global $wpdb;
                
                $options      = ddt_get_options( );
                $id_for_table = ddt_id_for_table( );
?>
<h2>Database Diff Tool</h2>
<button type="button" id="ddt_x-reload_diff" class="ddt_x-button">Refresh</button>
<?php
                if ( !$wpdb->get_col( 'SHOW TABLES LIKE \'' . ddt_get_diff_changes_table( ) . '\'' ) ) {
?>
<div class="ddt_x-error_message">
There is no diff session active. You must enable the diff option of the &quot;Backup Tool&quot; to use the &quot;Diff Tool&quot;.
</div>
<?php
                    return;
                }
                
                $results = $wpdb->get_results( 'SELECT table_name, operation, row_ids FROM ' . ddt_get_diff_changes_table( ) );
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
                echo '<input type="text" id="ddt_x-table_width" placeholder="e.g. 2000px or 150%" disabled>';
                echo '<label for="ddt_x-table_cell_size">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Table Cell Max Characters: </label>';
                echo '<input type="text" id="ddt_x-table_cell_size" placeholder="truncate content to this" disabled>';
                echo '<label for="ddt_x-table_sort_order">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Sort Order: </label>';
                echo '<input type="text" id="ddt_x-table_sort_order" placeholder="primary key, secondary keys, ..." readonly>';
                echo '<input type="hidden" id="ddt_x-nonce" value="' . wp_create_nonce( 'ddt_x-from_diff' ) . '">';
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
            } );  # add_submenu_page( DDT_BACKUP_PAGE_NAME, 'Diff Tool', 'Diff Tool', 'export', DDT_DIFF_PAGE_NAME, function( ) {
        } );   # add_action( 'admin_menu', function( ) {

        add_action( 'admin_enqueue_scripts', function( $hook ) {
            if ( strpos( $hook, DDT_DIFF_PAGE_NAME ) !== FALSE ) {
                wp_enqueue_style(  'wp-db-tools',        plugin_dir_url( __FILE__ ) . 'wp-db-tools.css'                         );
                wp_enqueue_script( 'wp-db-diff',         plugin_dir_url( __FILE__ ) . 'wp-db-diff.js',             [ 'jquery' ] );
                wp_enqueue_script( 'jquery.tablesorter', plugin_dir_url( __FILE__ ) . 'jquery.tablesorter.min.js', [ 'jquery' ] );
            }
        } );

    }   # if ( !defined( 'DOING_AJAX' ) ) {
   
    if ( defined( 'DOING_AJAX' ) ) {

        add_action( 'wp_ajax_ddt_x-diff_view_changes', function( ) {
            global $wpdb;

            $options      = ddt_get_options( );
            $id_for_table = ddt_id_for_table( );

            if ( !wp_verify_nonce( $_REQUEST[ 'ddt_x-nonce' ], 'ddt_x-from_diff' ) ) {
                wp_nonce_ays( '' );
            }
            $suffix     = $options[ 'ddt_x-orig_suffix' ];
            $table      = $_POST[ 'table' ];
            $table_id   = $id_for_table[ $table ];
            $operation  = explode( ',', $_POST[ 'operation' ] );
            # replace pretty header labels for operation with the operation tag
            $operation  = str_replace( 'Inserts', 'INSERT', $operation );
            $operation  = str_replace( 'Updates', 'UPDATE', $operation );
            $operation  = str_replace( 'Deletes', 'DELETE', $operation );
            # remove any invalid operation tags
            $operation  = array_filter( $operation, function( $v ) {
                return in_array( $v, [ 'INSERT', 'UPDATE', 'DELETE' ] );
            } );
            $width      = !empty( $options[ 'ddt_x-table_width'      ][ $table ] ) ? $options[ 'ddt_x-table_width'      ][ $table ] : '';
            $cell_size  = !empty( $options[ 'ddt_x-table_cell_size'  ][ $table ] ) ? $options[ 'ddt_x-table_cell_size'  ][ $table ] : '';
            $sort_order = !empty( $options[ 'ddt_x-table_sort_order' ][ $table ] ) ? $options[ 'ddt_x-table_sort_order' ][ $table ] : '';
            $results    = $wpdb->get_results( $wpdb->prepare( 'SELECT operation, row_ids FROM ' . ddt_get_diff_changes_table( )
                              . ' WHERE table_name = %s AND operation IN ( ' . implode( ', ', array_slice( [ '%s', '%s', '%s' ], 0, count( $operation ) ) )
                              . ' ) ORDER BY operation', array_merge( [ $table ], $operation ) ) );
?>
<div class="ddt_x-info_message">
Table cells with content ending in &quot;...&quot; have been truncated. You can view the original content by clicking on the cell.
The columns are sortable and sorting may bring related rows closer together where they may be easier to compare.
You can do a multi-column sort by pressing the shift-key when clicking on the secondary columns.
</div>
<script type="text/javascript">
    var ems_xii_diff_options = { width: "<?php echo $width; ?>", cell_size: "<?php echo $cell_size; ?>", sort_order: "<?php echo $sort_order; ?>" };
</script>
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
            $columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table );
            $columns = array_filter( $columns, function( $v ) use ( $table_id ) {
                return $v !== $table_id;
            } );
            array_unshift( $columns, $table_id );
            $columns_imploded = implode( ', ', $columns );
            if (  $ids[ 'INSERT' ] ) {
                $inserts   = $wpdb->get_results( 'SELECT ' . $columns_imploded . ' FROM ' . $table           
                                                    . ' WHERE ' . $table_id . ' IN ( ' . implode( ', ', $ids[ 'INSERT' ] ) . ' )', OBJECT_K );
            }
            if ( $ids[ 'UPDATE' ] ) {
                $updates   = $wpdb->get_results( 'SELECT ' . $columns_imploded . ' FROM ' . $table           
                                                    . ' WHERE ' . $table_id . ' IN ( ' . implode( ', ', $ids[ 'UPDATE' ] ) . ' )', OBJECT_K );
                $originals = $wpdb->get_results( 'SELECT ' . $columns_imploded . ' FROM ' . $table . $suffix
                                                    . ' WHERE ' . $table_id . ' IN ( ' . implode( ', ', $ids[ 'UPDATE' ] ) . ' )', OBJECT_K );
            }
            if ( $ids[ 'DELETE' ] ) {
                $deletes   = $wpdb->get_results( 'SELECT ' . $columns_imploded . ' FROM ' . $table . $suffix
                                                    . ' WHERE ' . $table_id . ' IN ( ' . implode( ', ', $ids[ 'DELETE' ] ) . ' )', OBJECT_K );
            }
            echo '<div id="ddt_x-table_changes_container"><table class="ddt_x-table_changes mc_table_changes tablesorter"><thead><tr><th>Row Status</th>';
            foreach ( $columns as $column ) {
                echo '<th>' . $column . '</th>';
            }
            echo "</tr></thead>\n<tbody>";
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
                        # this can occur on an insert that gets deleted in the same session
                        #error_log( "WARNING:action:wp_ajax_ddt_x-diff_view_changes:maybe bad INSERT id \"$id\" for table \"$table\"." );
                        continue;
                    }
                    echo '<tr class="ddt_x-changes_updated">';
                    echo '<td>INSERTED</td>';
                    $insert = $inserts[ $id ];
                    foreach ( $columns as $column ) {
                        echo '<td class="ddt_x-field_changed">' . ddt_wp_db_diff_prettify( $insert->$column ) . '</td>';
                    }
                    echo "</tr>\n";
                } else if ( $operation === 'UPDATE' ) {
                    if ( !array_key_exists( $id, $originals ) || !array_key_exists( $id, $updates ) ) {
                        # this can occur on a row that was inserted and updated or a row that was updated and deleted
                        #error_log( "WARNING:action:wp_ajax_ddt_x-diff_view_changes:maybe UPDATE id \"$id\" for table \"$table\"." );
                        continue;
                    }
                    echo '<tr class="ddt_x-changes_original">';
                    echo '<td>ORIGINAL</td>';
                    $original = $originals[ $id ];
                    foreach ( $columns as $column ) {
                        $td_class = strcmp( $originals[ $id ]->$column, $updates[ $id ]->$column ) ? ' class="ddt_x-field_changed"' : '';
                        echo '<td' . $td_class . '>' . ddt_wp_db_diff_prettify( $original->$column ) . '</td>';
                    }
                    echo '</tr>';
                    echo '<tr class="ddt_x-changes_updated">';
                    echo '<td>UPDATED</td>';
                    $update = $updates[ $id ];
                    foreach ( $columns as $column ) {
                        $td_class = strcmp( $originals[ $id ]->$column, $updates[ $id ]->$column ) ? ' class="ddt_x-field_changed"' : '';
                        echo '<td' . $td_class . '>' . ddt_wp_db_diff_prettify( $update->$column ) . '</td>';
                    }
                    echo "</tr>\n";
                } else if ( $operation === 'DELETE' ) {
                    if ( !array_key_exists( $id, $deletes ) ) {
                        # this can occur on an inserted row
                        #error_log( "WARNING:action:wp_ajax_ddt_x-diff_view_changes:maybe bad DELETE id \"$id\" for table \"$table\"." );
                        continue;
                    }
                    echo '<tr class="ddt_x-changes_original">';
                    echo '<td>DELETED</td>';
                    $delete = $deletes[ $id ];
                    foreach ( $columns as $column ) {
                        echo '<td class="ddt_x-field_changed">' . ddt_wp_db_diff_prettify( $delete->$column ) . '</td>';
                    }
                    echo "</tr>\n";
                }
            }   # while ( TRUE ) {
            echo '</tbody></table></div>';
?>
<div class="ddt_x-fine-print">
<h3>Technical Details</h3>
Diff works by spying on database operations using the WordPress filter 'query'. This will intercept all database operations done through the Wordpress API,
e.g. $wpdb->get_results(), ... update_post_meta() (which will eventually call $wpdb->update() ), ... It will not spy on database operations
done directly through the PHP MySQL API, e.g. mysqli_query(), mysql_query(), ... Further, it cannot spy on database operations that occur before the
filter is installed. The filter is installed when the plugin is loaded which is probably early enough for most uses but will not spy on database
operations done on the load of plugins loaded before this plugin. Some of the more exotic MySQL commands are not currently handled. These will 
generate an error message like this "ERROR:ddt_post_query():unknown MySQL operation: ..." using the error_log function.
</div>
<?php
            die;
        } );   # add_action( 'wp_ajax_ddt_x-diff_view_changes', function( ) {

        add_action( 'wp_ajax_ddt_x-update_diff_options', function( ) {
            $options = ddt_get_options( );

            if ( !wp_verify_nonce( $_REQUEST[ 'ddt_x-nonce' ], 'ddt_x-from_diff' ) ) {
                wp_nonce_ays( '' );
            }
            foreach( [ 'ddt_x-table_width', 'ddt_x-table_cell_size', 'ddt_x-table_sort_order' ] as $option ) {
                if ( !empty( $_POST[ $option ] ) && !empty( $_POST[ $option] ) ) {
                    $options[ $option ][ $_POST[ 'ddt_x-table' ] ] = $_POST[ $option ];
                }
            }
            \update_option( 'ddt-x-wp_db_tools', $options );
            ddt_get_options( $options );
        } );   # add_action( 'wp_ajax_ddt_x-update_diff_options', function( ) {
        
    }   # if ( defined( 'DOING_AJAX' ) ) {

}   # function ddt_wp_db_diff_init( ) {

ddt_wp_db_diff_init( );

}   # namespace ddt_x_wp_db_tools {

?>
