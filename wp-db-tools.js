jQuery( function( ) {
    jQuery( "form#mc_tables" ).submit( function( ) {
        // TODO: e.preventDefault() vs return false;
        //e.preventDefault();
        return false;
    } );
    jQuery( "button.mc-wpdbdt-btn#mc_backup" ).click( function( e ) {
        var button = this;
        jQuery( "#mc_status" ).html( "sending..." );
        // data will contain the names and values of checked HTML input elements of the form with action = 'mc_backup_tables'
        var data = jQuery( "form#mc_tables" ).serialize();
        console.log( data );
        // invoke the AJAX action wp_ajax_mc_backup_tables
        jQuery.post( ajaxurl, data, function ( response ) {
            jQuery( "#mc_status" ).html( response );
            if ( response.indexOf( "<?php echo MC_SUCCESS; ?>" ) ) {
                button.disabled = true;
                jQuery( "#mc_restore"      ).prop( "disabled", false );
                jQuery( "#mc_delete"       ).prop( "disabled", false );
                jQuery( "#mc_table_fields" ).prop( "disabled", true  );
            }
        } );
    } );
    
    jQuery( "button.mc-wpdbdt-btn#mc_restore" ).click( function( e ) {
        jQuery( "#mc_status" ).html( "sending..." );
        jQuery.post( ajaxurl, { action: "mc_restore_tables" }, function ( response ) {
            jQuery( "#mc_status" ).html( response );
        } );
    } );
    
    jQuery( "button.mc-wpdbdt-btn#mc_delete" ).click( function( e ) {
        var button = this;
        jQuery( "#mc_status" ).html( "sending..." );
        jQuery.post( ajaxurl, { action: "mc_delete_backup" }, function ( response ) {
            jQuery( "#mc_status" ).html( response );
            if ( response.indexOf( "<?php echo MC_SUCCESS; ?>" ) ) {
                button.disabled = true;
                jQuery( "#mc_backup"       ).prop( "disabled", false );
                jQuery( "#mc_restore"      ).prop( "disabled", true  );
                jQuery( "#mc_table_fields" ).prop( "disabled", false );
            }
        } );
    } );
    
    jQuery( "input#mc_backup_suffix" ).change( function( e ) {
        var table_pane = jQuery( "fieldset#mc_table_fields" );
        var error_pane = jQuery( "div#mc_db_tools_error_pane" );
        error_pane.text( "checking backup suffix..." ).show( );
        var suffix = this.value;
        jQuery.post( ajaxurl, { action: "mc_check_backup_suffix", backup_suffix: suffix }, function( response ) {
            console.log( "response=", response );
            var result = JSON.parse( response );
            console.log( "result=", result );
            error_pane.text( result.backup_suffix_ok ? suffix + " accepted as the backup suffix. reloading..."
                : "Existing table " + result.bad_table + " already has suffix " + suffix + ", please try another suffix." );
            if ( result.backup_suffix_ok ) {
                window.setTimeout( function( ) {
                    location.reload( true );
                }, 2000 );
            } else {
                table_pane.hide( );
            }
        } );
        e.stopImmediatePropagation( );
        e.stopPropagation( );
        e.preventDefault( );
        return false;
    } );
    
} );