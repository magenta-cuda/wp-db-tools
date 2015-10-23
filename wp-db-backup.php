<?php

/*
Module Name: WordPress Database Developer Tools: Backup
Plugin URI: https://wpdbdt.wordpress.com/
Description: Easy Backup for Testing
Version: 1.0
Author: Magenta Cuda
Author URI: https://profiles.wordpress.org/magenta-cuda/
License: GPL2
*/

/*  Copyright 2013  Magenta Cuda

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
Project X: WordPress Database Developer Tools: Backup

Quick backup of individual MySQL tables by duplication in the same database.
Useful for testing when you know only some tables will be changed so you don't have to save and restore the entire database.
Most useful for repeated testing, i.e. backup table(s), test, restore table(s), test, restore table(s), ... test, restore table(s), delete backup.
 */
 
namespace mc_x_wp_db_tools {

define( 'MC_BACKUP_PAGE_NAME', 'easy_backup_for_testing' );

define( 'MC_BACKUP', 'mc_backup' );

define( 'MC_ORIG_SUFFIX', '_orig' );   # N.B. no existing table must have a name ending with this suffix

define( 'MC_SUCCESS', 'STATUS:SUCCESS' );
define( 'MC_FAILURE', 'STATUS:FAILURE' );

define( 'MC_COLS', 4 );

$options = get_option( 'mc-x-wp-db-tools', [ 'hello' => 'hi' ] );

$wp_db_diff_included = NULL;
if ( file_exists( __DIR__ . '/wp-db-diff.php' ) && !empty( $options[ 'diff' ] ) ) {
    $wp_db_diff_included = include_once( __DIR__ . '/wp-db-diff.php' );
}

function ddt_get_backup_tables( ) {
    global $wpdb;
    $tables = $wpdb->get_col( "show tables" );
    # extract only table names with the backup suffix and remove the backup suffix
    $suffix_len = strlen( MC_ORIG_SUFFIX );
    $tables = array_filter( array_map( function( $table ) use ( $suffix_len ) {
        if ( substr_compare( $table, MC_ORIG_SUFFIX, -$suffix_len, $suffix_len ) === 0 ) {
            return substr( $table, 0, -$suffix_len );
        } else {
            return FALSE;
        }
    }, $tables ) );
    error_log( '##### ddt_get_backup_tables():return=' . print_r( $tables, true ) );
    return $tables;
}
    
add_action( 'admin_menu', function( ) {
    
    add_menu_page( 'Easy Backup for Testing', 'Easy Backup for Testing', 'export', MC_BACKUP_PAGE_NAME, function( ) {
        global $wpdb;
        # get names of all tables in database
        $tables = $wpdb->get_col( "show tables" );
        error_log( '##### add_menu_page():callback():$tables=' . print_r( $tables, true ) );
        # remove names of backup tables
        $suffix_len = strlen( MC_ORIG_SUFFIX );
        $tables = array_merge( array_filter( $tables, function( $table ) use ( $suffix_len ) {
            return substr_compare( $table, MC_ORIG_SUFFIX, -$suffix_len, $suffix_len ) !== 0;
        } ) );
        error_log( '##### add_menu_page():callback():$tables=' . print_r( $tables, true ) );
        $tables_orig = ddt_get_backup_tables( );
?>
<div style="padding:10px 20px;">
    <form id="mc_tables">
    <fieldset id="mc_table_fields" style="border:2px solid black;padding:10px;"<?php echo $tables_orig ? ' disabled' : ''; ?>>
        <legend>WordPress Tables for Backup</legend>
        <table class="mc_table_table">
<?php
        # create a HTML input element embedded in a HTML td element for each database table
        $mc_backup = MC_BACKUP;
        $columns = MC_COLS;
        $max_len = 0;
        foreach ( $tables as $i => $table ) {
            if ( ( $len = strlen( $table ) ) > $max_len ) {
                $max_len = $len;
            }
        }
        error_log( '$max_len=' . $max_len );
        if ( $max_len > 80 ) {
            $columns = 1;
        } else if ( $max_len > 50 ) {
            $columns = 2;
        } else if ( $max_len > 40 ) {
            $columns = 3;
        }
        foreach ( $tables as $i => $table ) {
            if ( $i % $columns === 0 ) {
                echo '<tr>';
            }
            # create HTML input element with name = database table name and value = $mc_backup and text = database table name
            # if table is already backed up set the checked attribute
            $checked = in_array( $table, $tables_orig) ? 'checked' : '';
            echo <<<EOD
            <td class="mc_table_td">
                <input type="checkbox" name="$table" id="$table" class="mc_table_checkbox" value="$mc_backup"$checked>
                <label for="$table">$table</label>
            </td>
EOD;
            if ( $i % $columns === $columns - 1 ) {
                echo '</tr>';
            }
        }
        if ( $i % $columns !== $columns - 1 ) {
            echo '</tr>';
        }
        # this form invokes the AJAX action wp_ajax_mc_backup_tables
?>
        </table>
        <input type="hidden" name="action" value="mc_backup_tables">
    </fieldset>
<?php
        if ( file_exists( __DIR__ . '/wp-db-diff.php' ) ) {
?>
    <fieldset id="mc_enable_diff" style="border:2px solid black;padding:10px;">
        <legend>Options</legend>
        <input type="checkbox" name="mc_enable_diff" id="mc_enable_diff" value="enabled">
        <label for="mc_enable_diff">Enable Diff</label>
    </fieldset>
<?php
        }
?>
    </form>
    <div style="padding:20px;">
        <button id="mc_backup"  class="mc-wpdbdt-btn" type="button"<?php if (  $tables_orig ) { echo ' disabled'; } ?>>Backup Tables</button>
        <button id="mc_restore" class="mc-wpdbdt-btn" type="button"<?php if ( !$tables_orig ) { echo 'disabled';  } ?>>Restore Tables</button>
        <button id="mc_delete"  class="mc-wpdbdt-btn" type="button"<?php if ( !$tables_orig ) { echo 'disabled';  } ?>>Delete Backup</button>
    </div>
    <div style="border:2px solid black;margin:20px;padding:20px;">
        <h3>Log</h3>
        <pre id="mc_status"></pre>
    </div>
</div>
<?php
    } );   # add_menu_page( 'Easy Backup for Testing', 'Easy Backup for Testing', 'export', MC_BACKUP_PAGE_NAME, function( ) {
        
    add_action( 'admin_enqueue_scripts', function( $hook ) {
        error_log( '$hook=' . $hook );
        if ( strpos( $hook, MC_BACKUP_PAGE_NAME ) !== FALSE ) {
            wp_enqueue_style(  'wp-db-tools', plugin_dir_url( __FILE__ ) . 'wp-db-tools.css' );
            wp_enqueue_script( 'wp-db-tools', plugin_dir_url( __FILE__ ) . 'wp-db-tools.js', [ 'jquery' ] );
        }
    } );
    
} );   # add_action( 'admin_menu', function() {

if ( defined( 'DOING_AJAX' ) ) {
    
    # ddt_wpdb_query() is a wrapper for $wpdb->query() for logging SQL commands and results
    
    function ddt_wpdb_query( $sql, &$messages ) {
        global $wpdb;
        $result = $wpdb->query( $sql );
        $messages[ ] = ( $result === FALSE ? 'Error: ' : '' ) . "\"$sql\" => ". ( $result === FALSE ? 'FAILED' : $result );
        return $result;
    }

    function ddt_format_messages( $messages, $tag ) {
        return array_map( function( $message ) use ( $tag ) {
            if ( substr_compare( $message, $tag, 0, strlen( $tag ) ) === 0 ) {
                return $message;
            } else {
                return "\t" . $message;
            }
        }, $messages );
    }
    
    # mc_backup_tables() is invoked as a 'wp_ajax_mc_backup_tables' action
    
    add_action( 'wp_ajax_mc_backup_tables', function( ) use ( $options, $wp_db_diff_included ) {
        $action = 'backup tables';
        error_log( '##### mc_backup_tables():$_POST=' . print_r( $_POST, true ) );
        
        if ( !empty( $_POST[ 'mc_enable_diff' ] ) ) {
            $options[ 'diff' ] = 'enabled';
            if ( !$wp_db_diff_included ) {
                $wp_db_diff_included = include_once( __DIR__ . '/wp-db-diff.php' );
            }
        } else {
            $options[ 'diff' ] = NULL;
        }
        update_option( 'mc-x-wp-db-tools', $options );
        
        $messages = array();
        # extract only table names from HTTP query parameters
        $tables = array_keys( array_filter( $_POST, function( $value ) {
            return $value === MC_BACKUP;
        } ) );
        error_log( '##### mc_backup_tables():$tables=' . print_r( $tables, true ) );
        $messages[ ] = $action . ': ' . implode( ', ', $tables );
        $status = MC_SUCCESS;
        foreach ( $tables as $table ) {
            # rename original table to use as backup
            if ( ddt_wpdb_query( "ALTER TABLE $table RENAME TO $table" . MC_ORIG_SUFFIX,      $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
            # create new table with original name and schema
            if ( ddt_wpdb_query( "CREATE TABLE $table LIKE $table" . MC_ORIG_SUFFIX,          $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
            # copy backup into new table
            if ( ddt_wpdb_query( "INSERT INTO $table SELECT * FROM $table" . MC_ORIG_SUFFIX,  $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
        }
        $messages[ ] = $action . ': ' . $status;
        $messages = ddt_format_messages( $messages, $action );
        echo implode( "\n", $messages ) . "\n";
        if ( !empty( $wp_db_diff_included ) ) {
            ddt_wp_db_diff_start_session( );
        }
        die;
    } );   # add_action( 'wp_ajax_mc_backup_tables', function( ) use ( &$options ) {

    # mc_restore_tables() is invoked as a 'wp_ajax_mc_restore_tables' action
    
    add_action( 'wp_ajax_mc_restore_tables', function( ) use ( $wp_db_diff_included ) {
        $action = 'restore tables';
        # get names of tables that have a backup copy
        $tables = ddt_get_backup_tables();
        $messages = array();
        $messages[ ] = $action . ': ' . implode( ', ', $tables );
        $status = MC_SUCCESS;
        # restore all tables that have a backup copy
        foreach ( $tables as $table ) {
            # drop the table to be restored
            if ( ddt_wpdb_query( "DROP TABLE $table",                                   $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
            # create a new empty table with the database schema of the corresponding backup table
            if ( ddt_wpdb_query( "CREATE TABLE $table LIKE $table" . MC_ORIG_SUFFIX,    $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
            # copy the rows from the corresponding backup table into the newly created table
            if ( ddt_wpdb_query( "INSERT $table SELECT * FROM $table" . MC_ORIG_SUFFIX, $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
        }
        $messages[ ] = $action . ': ' . $status;
        $messages = ddt_format_messages( $messages, $action );
        echo implode( "\n", $messages ) . "\n";
        if ( !empty( $wp_db_diff_included ) ) {
            ddt_wp_db_diff_end_session( );
            ddt_wp_db_diff_start_session( );
        }
        die;
    } );   # add_action( 'wp_ajax_mc_restore_tables', function( ) {

    # mc_delete_backup() is invoked as a 'wp_ajax_mc_delete_backup' action
    
    add_action( 'wp_ajax_mc_delete_backup', function( ) use ( $wp_db_diff_included ) {
        $action = 'delete tables';
        $tables = ddt_get_backup_tables( );
        $messages = array();
        if ( $tables ) {
            $messages[] = $action . ': ' . implode(  MC_ORIG_SUFFIX . ', ', $tables ) . MC_ORIG_SUFFIX;
        } else {
            $messages[] = $action . ': ';
        }
        $status = MC_SUCCESS;
        foreach ( $tables as $table ) {
            # drop the backup table
            if ( ddt_wpdb_query( "DROP TABLE $table" . MC_ORIG_SUFFIX, $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
        }
        $messages[ ] = $action . ': ' . $status;
        $messages = ddt_format_messages( $messages, $action );
        echo implode( "\n", $messages ) . "\n";
        if ( !empty( $wp_db_diff_included ) ) {
            ddt_wp_db_diff_end_session( );
        }
        die;
    } );   # add_action( 'wp_ajax_mc_delete_backup', function( ) {
    
}   #if ( defined( 'DOING_AJAX' ) ) {

}   # namespace mc_x_wp_db_tools {

?>
