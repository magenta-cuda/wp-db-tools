jQuery( document ).ready( function( ) {
  
    var workingText = "working...\n";

    // convenience buttons to select or clear all checkboxes
    
    jQuery( "button#ddt_x-select_all_btn" ).click( function( e ) {
        jQuery( "input.ddt_x-table_checkbox" ).prop( "checked", true );
        jQuery( "div#ddt_x-main_buttons button#mc_backup" ).prop( "disabled", false );
        e.preventDefault( );
    } );

    jQuery( "button#ddt_x-clear_all_btn" ).click( function( e ) {
        jQuery( "input.ddt_x-table_checkbox" ).prop( "checked", false );
        jQuery( "div#ddt_x-main_buttons button#mc_backup" ).prop( "disabled", true );
        e.preventDefault( );
    } );
    
    // enable backup button only if a table is selected
    
    jQuery( "input.ddt_x-table_checkbox[type='checkbox']" ).change( function( e ) {
        jQuery( "div#ddt_x-main_buttons button#mc_backup" ).prop( "disabled", jQuery( "input.ddt_x-table_checkbox:checked" ).length === 0 );
    } );

    // submit form only on the click of backup button, i.e. ignore CR on form elements
    jQuery( "form#ddt_x-tables" ).submit( function( e ) {
        console.log( "submit:", e );
        // TODO: e.preventDefault() vs return false;
        //e.preventDefault();
        return false;
    } );

    // Create backup
    
    function backupTables( data ) {
        var log = jQuery( "#mc_status" );
        var text = log.text( );
        log.text( text + workingText );
        // invoke the AJAX action wp_ajax_mc_backup_tables
        jQuery.post( ajaxurl, data, function ( response ) {
            console.log( response );
            console.log( response.data.messages );
            var text = log.text( );
            response.data.messages.forEach( function( message ) {
                text += message + "\n";
            } );
            log.text( text );
            if ( text.indexOf( ddt_xPhpData.DDT_SUCCESS ) !== -1 ) {
                jQuery( "div#ddt_x-main_buttons button#ddt_x-restore"               ).prop( "disabled", false );
                jQuery( "div#ddt_x-main_buttons button#ddt_x-delete"                ).prop( "disabled", false );
                jQuery( "div#ddt_x-main_buttons button#ddt_x-diff_tool"             ).prop( "disabled", false );
                jQuery( "fieldset#ddt_x-table_fields"                               ).prop( "disabled", true  );
                jQuery( "fieldset#mc_db_tools_options input#ddt_x-backup_suffix"    ).prop( "disabled", true  );
                jQuery( "fieldset#mc_db_tools_options input#ddt_x-enable_diff"      ).prop( "disabled", true  );
            } else {
                backupTables( response.data.tables_to_do );
            }
        } );
    }
    jQuery( "button.ddt_x-button#mc_backup" ).click( function( e ) {
        var button = this;
        button.disabled = true;
        jQuery( "#mc_status" ).text( "" );
        // data will contain the names and values of checked HTML input elements of the form with action = 'mc_backup_tables'
        var data = jQuery( "form#ddt_x-tables" ).serialize( );
        backupTables( data );
        e.preventDefault( );
    } );
    
    // Restore from backup
    
    function restoreTables( data ) {
        var log = jQuery( "#mc_status" );
        var text = log.text( );
        log.text( text + workingText );
        // invoke the AJAX action wp_ajax_mc_restore_tables
        jQuery.post( ajaxurl, data, function ( response ) {
            console.log( response );
            console.log( response.data.messages );
            var text = log.text( );
            response.data.messages.forEach( function( message ) {
                text += message + "\n";
            } );
            log.text( text );
            if ( text.indexOf( ddt_xPhpData.DDT_SUCCESS ) !== -1 ) {
            } else {
                restoreTables( response.data.tables_not_to_do );
            }
        } );
    }
    
    jQuery( "button.ddt_x-button#ddt_x-restore" ).click( function( e ) {
        jQuery( "#mc_status" ).text( "" );
        restoreTables( { action: "mc_restore_tables", 'ddt_x-nonce': jQuery( "input#ddt_x-nonce" ).val( ) } );
    } );
    
    // Delete backup
    
    jQuery( "button.ddt_x-button#ddt_x-delete" ).click( function( e ) {
        var button = this;
        var nonce  = jQuery( "input#ddt_x-nonce" ).val( );
        jQuery( "#mc_status" ).text( workingText );
        jQuery.post( ajaxurl, { action: "mc_delete_backup", 'ddt_x-nonce': nonce }, function ( response ) {
            jQuery( "#mc_status" ).text( response );
            if ( response.indexOf( ddt_xPhpData.DDT_SUCCESS ) !== -1 ) {
                button.disabled = true;
                jQuery( "div#ddt_x-main_buttons button#mc_backup"                   ).prop( "disabled", false );
                jQuery( "div#ddt_x-main_buttons button#ddt_x-restore"               ).prop( "disabled", true  );
                jQuery( "div#ddt_x-main_buttons button#ddt_x-diff_tool"             ).prop( "disabled", true  );
                jQuery( "fieldset#ddt_x-table_fields"                               ).prop( "disabled", false );
                jQuery( "fieldset#mc_db_tools_options input#ddt_x-backup_suffix"    ).prop( "disabled", false );
                jQuery( "fieldset#mc_db_tools_options input#ddt_x-enable_diff"      ).prop( "disabled", false );
            }
        } );
    } );
    
    jQuery( "button.ddt_x-button#ddt_x-diff_tool" ).click( function( e ) {
        var href = window.location.href;
        href = href.substr( 0, href.indexOf( "?page=" ) + 6 ) + "ddt_diff_tool";
        window.open( href, "_blank" );
    } );
    
    // Verify backup suffix on change and CR keydown events
    
    jQuery( "input#ddt_x-backup_suffix" ).change( function( e ) {
        var table_pane   = jQuery( "fieldset#ddt_x-table_fields" );
        var error_pane   = jQuery( "div#mc_db_tools_error_pane" );
        var main_buttons = jQuery( "div#ddt_x-main_buttons" );
        var nonce        = jQuery( "input#ddt_x-nonce" ).val( );
        error_pane.text( "checking backup suffix..." ).show( );
        var suffix = this.value;
        jQuery.post( ajaxurl, { action: "mc_check_backup_suffix", backup_suffix: suffix, 'ddt_x-nonce': nonce }, function( response ) {
            var result = JSON.parse( response );
            error_pane.text( result.backup_suffix_ok ? "\"" + suffix + "\" accepted as the backup suffix.  Reloading ..."
                : "Existing table \"" + result.bad_table + "\" already has suffix \"" + suffix + "\", Please try another suffix." );
            if ( result.backup_suffix_ok ) {
                // need to reload since valid table names may have changed
                window.setTimeout( function( ) {
                    location.reload( true );
                }, 1000 );
            } else {
                // invalid suffix so hide main backup panes
                table_pane.hide( );
                main_buttons.hide( );
            }
        } );
        return false;
    } ).keydown( function( e ) {
        // intercept CR since it triggers submit on form elements
        if ( e.keyCode === 13 ) {
            // force change event on CR
            jQuery(this).change( );
            return false;
        }
    } );

    // Also verify backup suffix on click of the Verify button
    
    jQuery( "button#mc_suffix_verify" ).click( function( e ) {
        jQuery( "input#ddt_x-backup_suffix" ).change( );
    } );
} );
