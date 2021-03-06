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
 
namespace ddt_x_wp_db_tools {

const DDT_BACKUP_PAGE_NAME   = 'ddt_backup_tool';
const DDT_BACKUP             = 'mc_backup';
const DDT_LOG_READ           = 'mc_log_read';
const DDT_RESTORED           = 'ddt_restored';
const DDT_SUCCESS            = 'STATUS:SUCCESS';
const DDT_FAILURE            = 'STATUS:FAILURE';
const DDT_COLS               = 4;
const DDT_DIFF_CHANGES_TABLE = 'ddt_x_diff_tool_changes_1113';
const DDT_STATUS_TABLE       = 'ddt_x_status_1113';

function ddt_get_options( $o = NULL ) {
    global $wpdb;
    static $options = NULL;

    if ( $o !== NULL ) {
        $options = $o;
    } else if ( $options === NULL ) {
        $options = \get_option( 'ddt_x-wp_db_tools', [
            'ddt_x-version'              => '2.2',
            'ddt_x-orig_suffix'          => '_ddt_x_1113',
            'ddt_x-tables_per_increment' => '4',
            'ddt_x-suffix_verified'      => FALSE,
            'ddt_x-enable_diff'          => 'enabled',
            'ddt_x-table_width'          => [ ],
            'ddt_x-table_cell_size'      => [ ],
            'ddt_x-table_sort_order'     => [ $wpdb->postmeta => '2(post_id), 3(meta_key)', $wpdb->options => '2(option_name)' ],
            'ddt_x-tables_to_log_read'   => [ ]
        ] );
        # versions prior to 2.2 will not have saved values for 'ddt_x-tables_per_increment' and 'ddt_x-tables_to_log_read' so ...
        if ( empty( $options[ 'ddt_x-tables_per_increment' ] ) ) {
            $options[ 'ddt_x-tables_per_increment' ] = '4';
        }
        if ( empty( $options[ 'ddt_x-tables_to_log_read' ] ) ) {
            $options[ 'ddt_x-tables_to_log_read' ] = [ ];
        }
    }

    return $options;
}   # function ddt_get_options( $o = NULL ) {

# N.B. no existing table must have a name ending with suffix $options[ 'ddt_x-orig_suffix' ]'

# The argument $orig_tables must be set to an array for ddt_get_backup_tables() to return the original table names

function ddt_get_backup_tables( &$orig_tables = NULL, $suffix = NULL ) {
    global $wpdb;
    if ( $suffix === NULL ) {
        $suffix = ddt_get_options( )[ 'ddt_x-orig_suffix' ];
    }
    # extract only table names with the backup suffix and remove the backup suffix
    $tables     = $wpdb->get_col( "show tables" );
    $suffix_len = strlen( $suffix );
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
    return $backup_tables;
}   # function ddt_get_backup_tables( &$orig_tables = NULL ) {

function ddt_backed_up_tables( ) {
    static $backed_up_tables = NULL;
    if ( $backed_up_tables === NULL ) {
        $backed_up_tables = ddt_get_backup_tables( );
    }
    return $backed_up_tables;
}   # function ddt_backed_up_tables( ) {

# ddt_check_backup_suffix() verifies that no existing table already has the backup suffix

function ddt_check_backup_suffix( &$bad_table, $backup_tables = NULL, $orig_tables = NULL, $suffix = NULL ) {
    if ( $backup_tables === NULL || $orig_tables === NULL ) {
        $orig_tables   = [ ];
        $backup_tables = ddt_get_backup_tables( $orig_tables, $suffix );
    }
    $bad_table = NULL;
    foreach ( $backup_tables as $table ) {
        if ( !in_array( $table, $orig_tables ) ) {
            $bad_table = $table;
            return FALSE;
        }
    }
    return TRUE;
}   # function ddt_check_backup_suffix( &$bad_table, $backup_tables = NULL, $orig_tables = NULL ) {

function ddt_emit_backup_page( ) {
    global $wpdb;
    $options = ddt_get_options( );
?>
<h2>Database Backup Tool <span id="ddt_x-status-active" class="ddt_x-status-heading" style="display:<?php echo ddt_in_backup_session( ) ? 'inline' : 'none'; ?>";">
    (Status: a session is active)</span></h2>
<?php
    # verify that a previous backup/restore operation has completed normally
    ddt_check_status( );
    # get names of all tables in database
    $tables     = $wpdb->get_col( "show tables" );
    # remove names of backup tables
    $suffix     = $options[ 'ddt_x-orig_suffix' ];
    $suffix_len = strlen( $suffix );
    $tables     = array_merge( array_filter( $tables, function( $table ) use ( $suffix, $suffix_len ) {
        if ( $table === ddt_get_diff_changes_table( ) || $table === ddt_get_status_table( ) ) {
            return FALSE;
        }
        return substr_compare( $table, $suffix, -$suffix_len, $suffix_len ) !== 0;
    } ) );
    $orig_tables        = [ ];
    $backup_tables      = ddt_get_backup_tables( $orig_tables );
    $tables_to_log_read = $options[ 'ddt_x-tables_to_log_read' ];
    $backup_suffix_ok   = ddt_check_backup_suffix( $bad_table, $backup_tables, $orig_tables );
?>
<div class="ddt_x-container">
    <form id="ddt_x-tables">
    <fieldset id="ddt_x-table_fields" class="mc_db_tools_pane"
        <?php echo $backup_tables ? ' disabled' : ''; echo $backup_suffix_ok ? '' : ' style="display:none;"'; ?>>
        <legend>WordPress Tables for Backup</legend>
        <p>The left checkbox selects a table for backup. The right checkbox additionally enables a table for logging SELECT operations by the diff tool.
        As WordPress does a massive number of SELECT operations enable tables for logging SELECT operations sparingly and use only in short sessions.</p>
        <table class="ddt_x-table_table">
<?php
    # create a HTML input element embedded in a HTML td element for each database table
    $mc_backup   = DDT_BACKUP;
    $mc_log_read = DDT_LOG_READ;
    $columns     = DDT_COLS;
    # guess how many columns will fit into the page
    $max_len = 0;
    foreach ( $tables as $i => $table ) {
        if ( ( $len = strlen( $table ) ) > $max_len ) {
            $max_len = $len;
        }
    }
    if ( $max_len > 60 ) {
        $columns = 1;
    } else if ( $max_len > 40 ) {
        $columns = 2;
    } else if ( $max_len > 30 ) {
        $columns = 3;
    } else {
        $columns = 4;
    }
    $table_selected = FALSE;
    foreach ( $tables as $i => $table ) {
        if ( $i % $columns === 0 ) {
            echo '<tr>';
        }
        # create HTML input element with name = database table name and value = $mc_backup and text = database table name
        # if table is already backed up set the checked attribute
        $checked          = in_array( $table, $backup_tables      ) ? ' checked' : '';
        $checked_log_read = in_array( $table, $tables_to_log_read ) ? ' checked' : '';
        $table_selected |= $checked;
        echo <<<EOD
            <td class="mc_table_td">
                <input type="checkbox" name="$table" id="$table" class="ddt_x-table_checkbox ddt_x-table_backup" value="$mc_backup"$checked>
                <input type="checkbox" name="{$table}-log_read" id="{$table}-log_read" class="ddt_x-table_checkbox ddt_x-table_log_read" value="$mc_log_read"$checked_log_read>
                <label for="$table">$table</label>
            </td>
EOD;
        if ( $i % $columns === $columns - 1 ) {
            echo '</tr>';
        }
    }   # foreach ( $tables as $i => $table ) {
    if ( $i % $columns !== $columns - 1 ) {
        echo '</tr>';
    }
    # this form invokes the AJAX action wp_ajax_mc_backup_tables
?>
        </table>
        <div id="ddt_x-selection_buttons">
            <button type="button" id="ddt_x-select_all_btn" class="ddt_x-button">Select All</button>
            <button type="button" id="ddt_x-clear_all_btn" class="ddt_x-button">Clear All</button>
        </div>
        <input type="hidden" name="action" value="mc_backup_tables">
    </fieldset>
    <fieldset id="ddt_x-important_messages" class="mc_db_tools_pane">
        <legend>Important</legend>
<p>It is very important that you select all the tables that may be changed.
Otherwise when you restore the tables you may be left with an inconsistent database.
You should always have a real backup just in case you inadvertantly omit a required table.
If you are not sure about which tables will be changed you should select all tables.
Although not efficient this is always safe.</p>
<p>If you have selected the wp_usermeta table do not log out without ending a database debugging session.
Otherwise your session key will change and when you try and restore the tables the old session key will
also be restored and invalidate your current session.</p>

    </fieldset>
    <fieldset id="mc_db_tools_options" class="mc_db_tools_pane">
        <legend>Options</legend>
        <div class="ddt_x-option_box">
            <div class="ddt_x-option_comment">
The backup tables will be named by concatenating the original table name with the backup suffix. It is important that no existing table have a name ending in this suffix.
            </div>
            <label for="ddt_x-backup_suffix">Backup Suffix: </label>
            <input type="text" name="ddt_x-backup_suffix" id="ddt_x-backup_suffix" class="<?php echo $options[ 'ddt_x-suffix_verified' ] ? 'ddt_x-verified' : 'ddt_x-unverified' ?>"
                value="<?php echo $options[ 'ddt_x-orig_suffix' ]; ?>" size="20"<?php if ( $backup_tables ) { echo ' disabled'; } ?>>
            <button type="button" id="ddt_x-suffix_verify" class="ddt_x-button"<?php if ( $backup_tables ) { echo ' disabled'; } ?>>Verify</button>
        </div>
        <div id="mc_db_tools_error_pane"<?php echo $backup_suffix_ok ? ' style="display:none;"' : ''; ?>>
        The backup suffix &quot;<?php echo $options[ 'ddt_x-orig_suffix' ]; ?>&quot; conflicts with the existing table &quot;
        <?php echo "{$bad_table}{$options['ddt_x-orig_suffix']}"; ?>&quot;. Please use another suffix.
        </div>
        <div class="ddt_x-option_box">
            <div class="ddt_x-option_comment">
The backup/restore operation will be done incrementally. The number below is the number of tables that will be done per increment.
            </div>
            <label for="ddt_x-tables_per_increment">Tables per Increment: </label>
            <input type="number" name="ddt_x-tables_per_increment" id="ddt_x-tables_per_increment"
                value="<?php echo $options[ 'ddt_x-tables_per_increment' ]; ?>" size="4"<?php if ( $backup_tables ) { echo ' disabled'; } ?>>
        </div>
<?php
    if ( file_exists( __DIR__ . '/wp-db-diff.php' ) ) {
?>
        <div class="ddt_x-option_box">
            <div class="ddt_x-option_comment">
To monitor the backed up tables for changes you must enable the Diff Tool.
            </div>
            <label for="ddt_x-enable_diff">Enable Diff Tool: </label>
            <input type="checkbox" name="ddt_x-enable_diff" id="ddt_x-enable_diff" value="enabled"
                <?php if ( !empty($options[ 'ddt_x-enable_diff' ] ) ) { echo ' checked'; } ?><?php if ( $backup_tables ) { echo ' disabled'; } ?>>
        </div>
        <input type="hidden" name="ddt_x-nonce" id="ddt_x-nonce" value="<?php echo wp_create_nonce( 'ddt_x-from_backup' ); ?>">
<?php
    }   # if ( file_exists( __DIR__ . '/wp-db-diff.php' ) ) {
?>
    </fieldset>
    </form>
<?php
    if ( $backup_suffix_ok ) {
?>
    <div id="ddt_x-main_buttons">
        <button id="ddt_x-backup"    class="ddt_x-button" type="button" <?php if ( $backup_tables || !$table_selected ) { echo ' disabled'; } ?>>Backup Tables</button>
        <button id="ddt_x-restore"   class="ddt_x-button" type="button" <?php if ( !$backup_tables                    ) { echo ' disabled'; } ?>>Restore Tables</button>
        <button id="ddt_x-delete"    class="ddt_x-button" type="button" <?php if ( !$backup_tables                    ) { echo ' disabled'; } ?>>Delete Backup</button>
        <button id="ddt_x-diff_tool" class="ddt_x-button" type="button"
            <?php if ( !$backup_tables || empty($options[ 'ddt_x-enable_diff' ] ) ) { echo ' disabled'; } ?>>Open Diff Tool</button>
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
}   # function ddt_emit_backup_page( ) {

# ddt_check_status() will show the recovery pane if a backup/restore operation has failed.
# if $check_only is TRUE ddt_check_status() returns FALSE if a backup/restore operation has failed.

function ddt_check_status( $check_only = FALSE ) {
    global $wpdb;
    if ( !($request = ddt_get_status( 'request' ) ) ) {
        return TRUE;
    }
    $suffix = ddt_get_options( )[ 'ddt_x-orig_suffix' ];
    if ( $request[ 'action' ] === 'mc_backup_tables' ) {
        $tables_to_do     = ddt_get_status( 'tables to do'     );
        $backup_completed = ddt_get_status( 'backup completed' );
        if ( $backup_completed != $tables_to_do ) {
            if ( $check_only ) {
                return FALSE;
            }
            $backup_started        = ddt_get_status( 'backup started' );
            $started_not_completed = array_diff( $backup_started, $backup_completed );
            $request               = array_diff_key( $request, array_flip( $backup_completed ) );
            $not_completed         = implode( ', ', array_diff( $tables_to_do, $backup_completed ) );
            foreach ( $started_not_completed as $table ) {
                if ( $wpdb->get_col( "SHOW TABLES LIKE '{$table}{$suffix}'" ) ) {
                    if ( $wpdb->get_col( "SHOW TABLES LIKE '$table'" ) ) {
                        $wpdb->query( "DROP TABLE $table" );
                    }
                    $wpdb->query( "ALTER TABLE {$table}{$suffix} RENAME TO $table" );
                }
                $backup_started = array_diff( $backup_started, [ $table ] );
            }
            ddt_set_status( 'backup started', $backup_started );
?>
<div class="ddt_x-container">
    <form id="ddt_x-tables">
    <fieldset id="ddt_x-failure-backup" class="mc_db_tools_pane">
        <legend>Backup Failure</legend>
<p>The previous backup operation has not completed. Tables <?php echo $not_completed; ?> have not been backed up. Please click the &quot;Restart Backup&quot; button.</p>
        <button id="ddt_x-restart-backup" class="ddt_x-button" type="button" data-request="<?php echo http_build_query( $request ); ?>">Restart Backup</button>
    </fieldset>
    </form>
</div>
<?php
        }   # if ( $backup_completed != $tables_to_do ) {
    } else if ( $request[ 'action' ] === 'mc_restore_tables' ) {
        $backed_up_tables  = ddt_backed_up_tables( );
        $restore_completed = ddt_get_status( 'restore completed' );
        if ( $tables_to_restore = implode( ', ', array_diff( $backed_up_tables, $restore_completed ) ) ) {
            if ( $check_only ) {
                return FALSE;
            }
            $restore_started = ddt_get_status( 'restore started' );
            $unreported = [ ];
            foreach ( $restore_completed as $table ) {
                # add restored but not recorded tables to $request
                if ( empty( $request[ $table ] ) ) {
                    $request[ $table ] = DDT_RESTORED;
                    $unreported[ ]     = $table;
                }
            }
            # handle any partially restored tables
            foreach ( array_diff( $restore_started, $restore_completed ) as $table ) {
                # back out a started but not complete the restore
                if ( $wpdb->get_col( "SHOW TABLES LIKE '{$table}{$suffix}'" ) ) {
                    if ( $wpdb->get_col( "SHOW TABLES LIKE '$table'" ) ) {
                        $wpdb->query( "DROP TABLE $table" );
                    }
                }
                $restore_started = array_diff( $restore_started, [ $table ] );
            }
            ddt_set_status( 'restore started', $restore_started );
?>
<div class="ddt_x-container">
    <form id="ddt_x-tables">
    <fieldset id="ddt_x-failure-restore" class="mc_db_tools_pane">
        <legend>Restore Failure</legend>
<p>The previous restore operation has not completed. Tables <?php echo $tables_to_restore; ?> have not been restored. Please click the &quot;Restart Restore&quot; button.</p>
        <button id="ddt_x-restart-restore" class="ddt_x-button" type="button" data-request="<?php echo http_build_query( $request ); ?>">Restart Restore</button>
    </fieldset>
    </form>
</div>
<?php
        }   # if ( $tables_to_restore = array_diff( $backed_up_tables, $restore_completed ) ) {
    }
    return TRUE;
}   # function ddt_check_status( ) {


if ( is_admin( ) ) {

    # verify that last operation of the Backup Tool is fully done.

    add_action( 'admin_notices', function( ) {
        if ( ddt_check_status( TRUE ) ) {
            return;
        }
?>
<div class="notice notice-error">
    <p>The last operation of the Database Backup Tool is incomplete. Please go to <a href="<?php echo( admin_url( 'admin.php?page=ddt_backup_tool' ) ); ?>">Database Backup Tool</a>
        to fix this.</p>
</div>
<?php
    } );

    # add link to documentation

    add_filter( 'plugin_row_meta', function( $links, $file ) {
        if ( $file === ddt_plugin_basename( ) ) {
            return array_merge( $links, [ 'docs' => '<a href="https://wpdbdt.wordpress.com/" target="_blank">View documentation</a>' ] );
        }
        return (array) $links;
    }, 10, 2 );

    add_action( 'admin_menu', function( ) {
        add_menu_page( 'Database Developer\'s Tools', 'Database Developer\'s Tools', 'export', DDT_BACKUP_PAGE_NAME, '\ddt_x_wp_db_tools\ddt_emit_backup_page' );
    } );   # add_action( 'admin_menu', function( ) {

    add_action( 'admin_enqueue_scripts', function( $hook ) {
        if ( strpos( $hook, DDT_BACKUP_PAGE_NAME ) !== FALSE ) {
            wp_enqueue_style(  'wp-db-tools',  plugin_dir_url( __FILE__ ) . 'wp-db-tools.css' );
            wp_enqueue_script( 'wp-db-backup', plugin_dir_url( __FILE__ ) . 'wp-db-backup.js', [ 'jquery' ] );
            wp_localize_script( 'wp-db-backup', 'ddt_xPhpData', [ 'DDT_SUCCESS' => DDT_SUCCESS ] );
        }
    } );
}

function ddt_get_diff_changes_table( ) {
    return DDT_DIFF_CHANGES_TABLE;
}

function ddt_get_status_table( ) {
    return DDT_STATUS_TABLE;
}

function ddt_in_backup_session( ) {
    global $wpdb;
    return !!$wpdb->get_col( 'SHOW TABLES LIKE "%' . ddt_get_options( )[ 'ddt_x-orig_suffix' ] . '"' );
}
    
function ddt_in_diff_session( ) {
    global $wpdb;
    return !!$wpdb->get_col( 'SHOW TABLES LIKE "' . ddt_get_diff_changes_table( ) . '"' );
}

function ddt_wp_db_diff_included( $i = NULL ) {
    static $wp_db_diff_included = NULL;
    if ( $i !== NULL ) {
        $wp_db_diff_included = $i;
    }
    return $wp_db_diff_included;
}   # function ddt_wp_db_diff_included( $i = NULL ) {

function ddt_get_status( $name ) {
    global $wpdb;
    if ( $wpdb->get_col( 'SHOW TABLES LIKE "' . DDT_STATUS_TABLE . '"' ) ) {
        $col = $wpdb->get_col( 'SELECT option_value FROM ' . DDT_STATUS_TABLE . ' WHERE option_name = "' . $name . '"' );
        return $col ? maybe_unserialize( $col[ 0 ] ) : [ ];
    } else {
        return [ ];
    }
}

function ddt_set_status( $name, $status ) {
    global $wpdb;
    if ( !$wpdb->get_col( 'SHOW TABLES LIKE "' . DDT_STATUS_TABLE . '"' ) ) {
        $wpdb->query( 'CREATE TABLE ' . DDT_STATUS_TABLE . " LIKE $wpdb->options" );
        $wpdb->query( 'ALTER TABLE ' . DDT_STATUS_TABLE . ' DROP COLUMN autoload' );
    }
    if ( $id = $wpdb->get_col( 'SELECT option_id FROM ' . DDT_STATUS_TABLE . ' WHERE option_name = "' . $name . '"' ) ) {
        $wpdb->update( DDT_STATUS_TABLE, [ 'option_value' => maybe_serialize( $status ) ], [ 'option_id' => $id[ 0 ] ], [ '%s' ], [ '%d' ] );
    } else {
        $wpdb->insert( DDT_STATUS_TABLE, [ 'option_name' => $name, 'option_value' => maybe_serialize( $status ) ], [ '%s', '%s' ] );
    }
}

if ( defined( 'DOING_AJAX' ) ) {

    # AJAX Helper Functions

    # ddt_wpdb_query() is a wrapper for $wpdb->query() for logging SQL commands and results

    function ddt_wpdb_query( $sql, &$messages ) {
        global $wpdb;
        $result = $wpdb->query( $sql );
        $messages[ ] = ( $result === FALSE ? 'Error: ' : '' ) . "\"$sql\" => ". ( $result === FALSE ? 'FAILED' : $result );
        return $result;
    }   # function ddt_wpdb_query( $sql, &$messages ) {

    function ddt_format_messages( $messages, $tag ) {
        return array_map( function( $message ) use ( $tag ) {
            if ( substr_compare( $message, $tag, 0, strlen( $tag ) ) === 0 ) {
                return $message;
            } else {
                return "\t" . $message;
            }
        }, $messages );
    }   # function ddt_format_messages( $messages, $tag ) {

    # AJAX Handlers

    # mc_backup_tables() is invoked as a 'wp_ajax_mc_backup_tables' action
    
    add_action( 'wp_ajax_mc_backup_tables', function( ) {
        if ( !\wp_verify_nonce( $_REQUEST[ 'ddt_x-nonce' ], 'ddt_x-from_backup' ) ) {
            \wp_nonce_ays( '' );
        }

        ddt_set_status( 'request', $_REQUEST );
        $options      = ddt_get_options( );
        $action       = 'backup tables';
        $messages     = [ ];
        # extract only table names from HTTP query parameters
        $tables       = array_keys( array_filter( $_REQUEST, function( $value ) {
            return $value === DDT_BACKUP;
        } ) );
        if ( !ddt_get_status( 'tables to do' ) ) {
            ddt_set_status( 'tables to do', $tables );
        }
        $tables_log_read = array_map( function( $table ) {
            return substr( $table, 0, -9 );
        }, array_keys( array_filter( $_REQUEST, function( $value ) {
            return $value === DDT_LOG_READ;
        } ) ) );
        if ( $tables_log_read != $options[ 'ddt_x-tables_to_log_read' ] ) {
            $options[ 'ddt_x-tables_to_log_read' ] = $tables_log_read;
            \update_option( 'ddt_x-wp_db_tools', $options );
        }
        $suffix       = $options[ 'ddt_x-orig_suffix' ];
        $delta        = $_REQUEST[ 'ddt_x-tables_per_increment' ];
        if ( $delta != $options[ 'ddt_x-tables_per_increment' ] ) {
            $options[ 'ddt_x-tables_per_increment' ] = $delta;
            \update_option( 'ddt_x-wp_db_tools', $options );
        }
        #$messages[ ]  = $action . ': ' . implode( ', ', $tables );
        $tables_to_do = $_REQUEST;
        $status       = DDT_SUCCESS;
        foreach ( $tables as $table ) {
            $started = ddt_get_status( 'backup started' );
            $started[ ] = $table;
            ddt_set_status( 'backup started', $started );
            # rename original table to use as backup
            if ( ddt_wpdb_query( "ALTER TABLE $table RENAME TO {$table}{$suffix}", $messages ) === FALSE ) {
                $status = DDT_FAILURE;
                break;
            }
            # create new table with original name and schema
            if ( ddt_wpdb_query( "CREATE TABLE $table LIKE {$table}{$suffix}", $messages ) === FALSE ) {
                $status = DDT_FAILURE;
                break;
            }
            # copy backup into new table
            if ( ddt_wpdb_query( "INSERT INTO $table SELECT * FROM {$table}{$suffix}", $messages ) === FALSE ) {
                $status = DDT_FAILURE;
                break;
            }
            unset( $tables_to_do[ $table ] );
            $completed = ddt_get_status( 'backup completed' );
            $completed[ ] = $table;
            ddt_set_status( 'backup completed', $completed );
            if ( ( count( $_REQUEST ) - count( $tables_to_do ) ) >= $delta ) {
                break;
            }
        }
        if ( !in_array( DDT_BACKUP, $tables_to_do ) || $status === DDT_FAILURE ) {
            $messages[ ] = $action . ': ' . $status;
            if ( $status === DDT_SUCCESS ) {
                if ( !empty( $_REQUEST[ 'ddt_x-enable_diff' ] ) && $_REQUEST[ 'ddt_x-enable_diff' ] === 'enabled' && file_exists( __DIR__ . '/wp-db-diff.php' ) ) {
                    # start a diff session
                    $options[ 'ddt_x-enable_diff' ] = 'enabled';
                    \update_option( 'ddt_x-wp_db_tools', $options );
                    ddt_get_options( $options );
                    ddt_wp_db_diff_included( include_once( __DIR__ . '/wp-db-diff.php' ) );
                    ddt_wp_db_diff_start_session( );
                } else {
                    $options[ 'ddt_x-enable_diff' ] = NULL;
                    \update_option( 'ddt_x-wp_db_tools', $options );
                    ddt_get_options( $options );
                }
            }
        }
        $messages    = ddt_format_messages( $messages, $action );
        $data = [ 'messages' => $messages, 'tables_to_do' => $tables_to_do ];
        if ( $status === DDT_SUCCESS ) {
            wp_send_json_success( $data );
        } else {
            wp_send_json_error( $data );
        }
    } );   # add_action( 'wp_ajax_mc_backup_tables', function( ) {

    # mc_restore_tables() is invoked as a 'wp_ajax_mc_restore_tables' action

    add_action( 'wp_ajax_mc_restore_tables', function( ) {
        if ( !wp_verify_nonce( $_REQUEST[ 'ddt_x-nonce' ], 'ddt_x-from_backup' ) ) {
            wp_nonce_ays( '' );
        }

        if ( ddt_in_diff_session( ) ) {
            ddt_wp_db_diff_end_session( );
        }

        ddt_set_status( 'request', $_REQUEST );
        $options          = ddt_get_options( );
        $action           = 'restore tables';
        # get names of tables that have a backup copy
        $tables           = ddt_backed_up_tables( );
        $suffix           = $options[ 'ddt_x-orig_suffix' ];
        $delta            = $options[ 'ddt_x-tables_per_increment' ];
        $messages         = [ ];
        #$messages[ ]      = $action . ': ' . implode( ', ', $tables );
        $tables_not_to_do = $_REQUEST;
        $status           = DDT_SUCCESS;
        # restore all tables that have a backup copy
        if ( !count( array_filter( $_REQUEST, function( $value ) {
            return $value === DDT_RESTORED;
        } ) ) ) {
            # This is the initial restore request so remove any earlier restore status results - restore requests may be done multiple times on a given backup
            ddt_set_status( 'restore started',   [ ] );
            ddt_set_status( 'restore completed', [ ] );
        }
        foreach ( $tables as $table ) {
            if ( !empty( $_REQUEST[ $table ] ) && $_REQUEST[ $table ] === DDT_RESTORED ) {
                continue;
            }
            $started    = ddt_get_status( 'restore started' );
            $started[ ] = $table;
            ddt_set_status( 'restore started', $started );
            # drop the table to be restored
            if ( ddt_wpdb_query( "DROP TABLE $table", $messages ) === FALSE ) {
                # this is not a critical error so we can ignore it
                #$status = DDT_FAILURE;
                #break;
            }
            # create a new empty table with the database schema of the corresponding backup table
            if ( ddt_wpdb_query( "CREATE TABLE $table LIKE {$table}{$suffix}", $messages ) === FALSE ) {
                $status = DDT_FAILURE;
                break;
            }
            # copy the rows from the corresponding backup table into the newly created table
            if ( ddt_wpdb_query( "INSERT $table SELECT * FROM {$table}{$suffix}", $messages ) === FALSE ) {
                $status = DDT_FAILURE;
                break;
            }
            $tables_not_to_do[ $table ] = DDT_RESTORED;
            $completed    = ddt_get_status( 'restore completed' );
            $completed[ ] = $table;
            ddt_set_status( 'restore completed', $completed );
            if ( ( count( $tables_not_to_do ) - count( $_REQUEST ) ) >= $delta ) {
                break;
            }
        }
        if ( !array_diff( $tables, array_keys( $tables_not_to_do, DDT_RESTORED, TRUE ) ) || $status === DDT_FAILURE ) {
            $messages[ ] = $action . ': ' . $status;
            if ( $status === DDT_SUCCESS && !empty( $options[ 'ddt_x-enable_diff' ] ) && file_exists( __DIR__ . '/wp-db-diff.php' ) ) {
                # start a diff session
                ddt_wp_db_diff_included( include_once( __DIR__ . '/wp-db-diff.php' ) );
                ddt_wp_db_diff_start_session( );
            }
        }
        $messages    = ddt_format_messages( $messages, $action );
        $data = [ 'messages' => $messages, 'tables_not_to_do' => $tables_not_to_do ];
        if ( $status === DDT_SUCCESS ) {
            wp_send_json_success( $data );
        } else {
            wp_send_json_error( $data );
        }
    } );   # add_action( 'wp_ajax_mc_restore_tables', function( ) {

    # mc_delete_backup() is invoked as a 'wp_ajax_mc_delete_backup' action

    add_action( 'wp_ajax_mc_delete_backup', function( ) {
        global $wpdb;
        if ( !wp_verify_nonce( $_REQUEST[ 'ddt_x-nonce' ], 'ddt_x-from_backup' ) ) {
            wp_nonce_ays( '' );
        }

        if ( ddt_in_diff_session( ) ) {
            ddt_wp_db_diff_end_session( );
        }
        
        $action   = 'delete tables';
        $suffix   = ddt_get_options( )[ 'ddt_x-orig_suffix' ];
        $tables   = ddt_backed_up_tables( );
        $messages = [ ];
        #if ( $tables ) {
        #    $messages[ ] = $action . ': ' . implode(  $suffix . ', ', $tables ) . $suffix;
        #} else {
        #    $messages[ ] = $action . ': ';
        #}
        $status = DDT_SUCCESS;
        foreach ( $tables as $table ) {
            # drop the backup table
            if ( ddt_wpdb_query( "DROP TABLE {$table}{$suffix}", $messages ) === FALSE ) {
                $status = DDT_FAILURE;
                break;
            }
        }
        if ( $wpdb->get_col( 'SHOW TABLES LIKE "' . DDT_STATUS_TABLE . '"' ) ) {
            $wpdb->query( 'DROP TABLE ' . DDT_STATUS_TABLE );
        }
        $messages[ ] = $action . ': ' . $status;
        $messages    = ddt_format_messages( $messages, $action );
        echo implode( "\n", $messages ) . "\n";
        exit( );
    } );   # add_action( 'wp_ajax_mc_delete_backup', function( ) {
        
    add_action( 'wp_ajax_mc_check_backup_suffix', function( ) {
        $options = ddt_get_options( );

        if ( !wp_verify_nonce( $_REQUEST[ 'ddt_x-nonce' ], 'ddt_x-from_backup' ) ) {
            wp_nonce_ays( '' );
        }

        $suffix = $_POST[ 'backup_suffix' ];
        if ( $backup_suffix_ok = ddt_check_backup_suffix( $bad_table, NULL, NULL, $suffix ) ) {
            $options[ 'ddt_x-orig_suffix' ]     = $suffix;
            $options[ 'ddt_x-suffix_verified' ] = TRUE;
            \update_option( 'ddt_x-wp_db_tools', $options );
            ddt_get_options( $options );
        }

        $result = json_encode( [ 'backup_suffix_ok' => $backup_suffix_ok, 'bad_table' => ( $bad_table ? $bad_table . $suffix : NULL ) ] );
        echo $result;
        exit( );
    } );   # add_action( 'wp_ajax_check_backup_suffix', function( ) {
   
}   #if ( defined( 'DOING_AJAX' ) ) {

}   # namespace ddt_x_wp_db_tools {

?>
