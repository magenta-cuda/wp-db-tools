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

define( 'MC_SUCCESS', 'STATUS:SUCCESS' );
define( 'MC_FAILURE', 'STATUS:FAILURE' );

define( 'MC_COLS', 4 );

$options = get_option( 'mc-x-wp-db-tools', [ 'version' => '1.0', 'orig_suffix' => '_orig' ] );

# N.B. no existing table must have a name ending with suffix $options[ 'orig_suffix' ]'

$wp_db_diff_included = NULL;
if ( file_exists( __DIR__ . '/wp-db-diff.php' ) && !empty( $options[ 'diff' ] ) ) {
    $wp_db_diff_included = include_once( __DIR__ . '/wp-db-diff.php' );
}

# The argument $orig_tables must be set to an array for ddt_get_backup_tables() to return the original table names

function ddt_get_backup_tables( $suffix, &$orig_tables = NULL ) {
    global $wpdb;
    # extract only table names with the backup suffix and remove the backup suffix
    $tables        = $wpdb->get_col( "show tables" );
    $suffix_len    = strlen( $suffix );
    if ( is_array( $orig_tables ) ) {
        $orig_tables = [ ];
    }
    $backup_tables = array_filter( array_map( function( $table ) use ( $suffix, $suffix_len, &$orig_tables ) {
        if ( substr_compare( $table, $suffix, -$suffix_len, $suffix_len ) === 0 ) {
            return substr( $table, 0, -$suffix_len );
        } else {
            if ( is_array( $orig_tables ) ) {
                # this is not a backup table so it is an original table
                $orig_tables[ ] = $table;
            }
            return FALSE;
        }
    }, $tables ) );
    error_log( '##### ddt_get_backup_tables():return=' . print_r( $tables, true ) );
    return $backup_tables;
}

# ddt_check_backup_suffix() verifies that no existing table already has the backup suffix

function ddt_check_backup_suffix( &$bad_table, $backup_tables = NULL, $orig_tables = NULL, $backup_suffix = NULL ) {
    if ( $backup_tables === NULL || $orig_tables === NULL ) {
        $orig_tables   = [ ];
        $backup_tables = ddt_get_backup_tables( $backup_suffix, $orig_tables );
    }
    $bad_table = NULL;
    foreach ( $backup_tables as $table ) {
        if ( !in_array( $table, $orig_tables ) ) {
            $bad_table = $table;
            return FALSE;
        }
    }
    return TRUE;
}
    
add_action( 'admin_menu', function( ) use ( $options ) {
    
    add_menu_page( 'Database Developer\'s Tools', 'Database Developer\'s Tools', 'export', MC_BACKUP_PAGE_NAME, function( ) use ( $options ) {
        global $wpdb;
?>
<h2>Database Developer's Tools: Backup Tool</h2>
<?php
        # get names of all tables in database
        $tables = $wpdb->get_col( "show tables" );
        error_log( '##### add_menu_page():callback():$tables=' . print_r( $tables, true ) );
        # remove names of backup tables
        $suffix     = $options[ 'orig_suffix' ];
        $suffix_len = strlen( $suffix );
        $tables = array_merge( array_filter( $tables, function( $table ) use ( $suffix, $suffix_len ) {
            return substr_compare( $table, $suffix, -$suffix_len, $suffix_len ) !== 0;
        } ) );
        error_log( '##### add_menu_page():callback():$tables=' . print_r( $tables, true ) );
        $orig_tables = [ ];
        $backup_tables = ddt_get_backup_tables( $options[ 'orig_suffix' ], $orig_tables );
        error_log( '##### add_menu_page():callback():$backup_tables=' . print_r( $backup_tables, true ) );
        error_log( '##### add_menu_page():callback():$orig_tables=' . print_r( $orig_tables, true ) );
        $backup_suffix_ok = ddt_check_backup_suffix( $bad_table, $backup_tables, $orig_tables );
?>
<div class="mc_container">
    <form id="mc_tables">
    <fieldset id="mc_table_fields" class="mc_db_tools_pane"
        <?php echo $backup_tables ? ' disabled' : ''; echo $backup_suffix_ok ? '' : ' style="display:none;"'; ?>>
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
        if ( $max_len > 60 ) {
            $columns = 1;
        } else if ( $max_len > 40 ) {
            $columns = 2;
        } else if ( $max_len > 30 ) {
            $columns = 3;
        } else {
            $columns = 4;
        }
        foreach ( $tables as $i => $table ) {
            if ( $i % $columns === 0 ) {
                echo '<tr>';
            }
            # create HTML input element with name = database table name and value = $mc_backup and text = database table name
            # if table is already backed up set the checked attribute
            $checked = in_array( $table, $backup_tables ) ? 'checked' : '';
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
        }   # foreach ( $tables as $i => $table ) {
        # this form invokes the AJAX action wp_ajax_mc_backup_tables
?>
        </table>
        <input type="hidden" name="action" value="mc_backup_tables">
    </fieldset>
    <div id="mc_db_tools_error_pane" class="mc_db_tools_pane"<?php echo $backup_suffix_ok ? ' style="display:none;"' : ''; ?>>
    The backup suffix &quot;<?php echo $options[ 'orig_suffix' ]; ?>&quot; conflicts with the existing table &quot;
    <?php echo "{$bad_table}{$options['orig_suffix']}"; ?>&quot;. Please use another suffix.
    </div>
    <fieldset id="mc_db_tools_options" class="mc_db_tools_pane">
        <legend>Options</legend>
        <label for="mc_backup_suffix">Backup Suffix</label>
        <input type="text" name="mc_backup_suffix" id="mc_backup_suffix" value="<?php echo $options[ 'orig_suffix' ]; ?>" size="20">
        <button id="mc_suffix_verify" type="button">Verify</button>
<?php
        if ( file_exists( __DIR__ . '/wp-db-diff.php' ) ) {
?>
        <label for="mc_enable_diff">Enable Diff</label>
        <input type="checkbox" name="mc_enable_diff" id="mc_enable_diff" value="enabled">
<?php
        }
?>
    </fieldset>
    </form>
<?php
        if ( $backup_suffix_ok ) {
?>
    <div id="mc_main_buttons">
        <button id="mc_backup"  class="mc-wpdbdt-btn" type="button"<?php if (  $backup_tables ) { echo ' disabled'; } ?>>Backup Tables</button>
        <button id="mc_restore" class="mc-wpdbdt-btn" type="button"<?php if ( !$backup_tables ) { echo 'disabled';  } ?>>Restore Tables</button>
        <button id="mc_delete"  class="mc-wpdbdt-btn" type="button"<?php if ( !$backup_tables ) { echo 'disabled';  } ?>>Delete Backup</button>
    </div>
<?php
        }
?>
    <fieldset id="mc_db_tools_log" class="mc_db_tools_pane">
        <legend>Log</legend>
        <pre id="mc_status"></pre>
    </fieldset>
</div>
<?php
    } );   # add_menu_page( 'Database Developer\'s Tools', 'Database Developer\'s Tools', 'export', MC_BACKUP_PAGE_NAME, function( ) use ( $options ) {
        
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
        
        $messages = [ ];
        # extract only table names from HTTP query parameters
        $tables      = array_keys( array_filter( $_POST, function( $value ) {
            return $value === MC_BACKUP;
        } ) );
        error_log( '##### mc_backup_tables():$tables=' . print_r( $tables, true ) );
        $suffix      = $options[ 'orig_suffix' ];
        $messages[ ] = $action . ': ' . implode( ', ', $tables );
        $status      = MC_SUCCESS;
        foreach ( $tables as $table ) {
            # rename original table to use as backup
            if ( ddt_wpdb_query( "ALTER TABLE $table RENAME TO {$table}{$suffix}", $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
            # create new table with original name and schema
            if ( ddt_wpdb_query( "CREATE TABLE $table LIKE {$table}{$suffix}", $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
            # copy backup into new table
            if ( ddt_wpdb_query( "INSERT INTO $table SELECT * FROM {$table}{$suffix}", $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
        }
        $messages[ ] = $action . ': ' . $status;
        $messages    = ddt_format_messages( $messages, $action );
        echo implode( "\n", $messages ) . "\n";
        if ( !empty( $wp_db_diff_included ) ) {
            ddt_wp_db_diff_start_session( );
        }
        die;
    } );   # add_action( 'wp_ajax_mc_backup_tables', function( ) use ( &$options ) {

    # mc_restore_tables() is invoked as a 'wp_ajax_mc_restore_tables' action
    
    add_action( 'wp_ajax_mc_restore_tables', function( ) use ( $options, $wp_db_diff_included ) {
        $action      = 'restore tables';
        # get names of tables that have a backup copy
        $tables      = ddt_get_backup_tables( $options[ 'orig_suffix' ] );
        $suffix      = $options[ 'orig_suffix' ];
        $messages    = [ ];
        $messages[ ] = $action . ': ' . implode( ', ', $tables );
        $status      = MC_SUCCESS;
        # restore all tables that have a backup copy
        foreach ( $tables as $table ) {
            # drop the table to be restored
            if ( ddt_wpdb_query( "DROP TABLE $table", $messages ) === FALSE ) {
                # this is not a critical error so we can ignore it
                #$status = MC_FAILURE;
                #break;
            }
            # create a new empty table with the database schema of the corresponding backup table
            if ( ddt_wpdb_query( "CREATE TABLE $table LIKE {$table}{$suffix}", $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
            # copy the rows from the corresponding backup table into the newly created table
            if ( ddt_wpdb_query( "INSERT $table SELECT * FROM {$table}{$suffix}", $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
        }
        $messages[ ] = $action . ': ' . $status;
        $messages    = ddt_format_messages( $messages, $action );
        echo implode( "\n", $messages ) . "\n";
        if ( !empty( $wp_db_diff_included ) ) {
            ddt_wp_db_diff_end_session( );
            ddt_wp_db_diff_start_session( );
        }
        die;
    } );   # add_action( 'wp_ajax_mc_restore_tables', function( ) {

    # mc_delete_backup() is invoked as a 'wp_ajax_mc_delete_backup' action
    
    add_action( 'wp_ajax_mc_delete_backup', function( ) use ( $options, $wp_db_diff_included ) {
        $action   = 'delete tables';
        $suffix   = $options[ 'orig_suffix' ];
        $tables   = ddt_get_backup_tables( $options[ 'orig_suffix' ] );
        $messages = [ ];
        if ( $tables ) {
            $messages[ ] = $action . ': ' . implode(  $suffix . ', ', $tables ) . $suffix;
        } else {
            $messages[ ] = $action . ': ';
        }
        $status = MC_SUCCESS;
        foreach ( $tables as $table ) {
            # drop the backup table
            if ( ddt_wpdb_query( "DROP TABLE {$table}{$suffix}", $messages ) === FALSE ) {
                $status = MC_FAILURE;
                break;
            }
        }
        $messages[ ] = $action . ': ' . $status;
        $messages    = ddt_format_messages( $messages, $action );
        echo implode( "\n", $messages ) . "\n";
        if ( !empty( $wp_db_diff_included ) ) {
            ddt_wp_db_diff_end_session( );
        }
        die;
    } );   # add_action( 'wp_ajax_mc_delete_backup', function( ) {
        
    add_action( 'wp_ajax_mc_check_backup_suffix', function( ) use ( $options ) {
        $suffix = $_POST[ 'backup_suffix' ];
        $options[ 'orig_suffix' ] = $suffix;
        update_option( 'mc-x-wp-db-tools', $options );
        $backup_suffix_ok = ddt_check_backup_suffix( $bad_table, NULL, NULL, $suffix );
        $result = json_encode( [ 'backup_suffix_ok' => $backup_suffix_ok, 'bad_table' => $bad_table . $suffix ] );
        error_log( '$result=' . $result );
        echo $result;
        die;
    } );   # add_action( 'wp_ajax_check_backup_suffix', function( ) {
   
}   #if ( defined( 'DOING_AJAX' ) ) {

}   # namespace mc_x_wp_db_tools {

?>
