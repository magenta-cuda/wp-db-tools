jQuery( function( ) {
        
    function reduceTableCellContents( table ) {
        if ( ! ( table instanceof jQuery ) ) {
            table = jQuery( table );
        }
        var cellContentMaxLength = jQuery( "input#ddt_x-table_cell_size" ).val( );
        table.find( "td" ).each( function( ) {
            var text = this.dataset.origContent ? this.dataset.origContent : this.textContent;
            if ( text.length > cellContentMaxLength ) {
                this.dataset.origContent = text;
                this.textContent = text.substr( 0, cellContentMaxLength ) + " ...";
            }
        } );
    }

    // enable backup button only if a table is selected
    
    jQuery( "input.ddt_x-table_checkbox[type='checkbox']" ).change( function( e ) {
        jQuery( "div#mc_main_buttons button#mc_backup" ).prop( "disabled", jQuery( "input.ddt_x-table_checkbox[type='checkbox']:checked" ).length === 0 );
    } );

    // submit form only on the click of backup button, i.e. ignore CR on form elements
    jQuery( "form#mc_tables" ).submit( function( e ) {
        console.log( "submit:", e );
        // TODO: e.preventDefault() vs return false;
        //e.preventDefault();
        return false;
    } );

    // Create backup
    
    jQuery( "button.mc-wpdbdt-btn#mc_backup" ).click( function( e ) {
        var button = this;
        jQuery( "#mc_status" ).html( "sending..." );
        // data will contain the names and values of checked HTML input elements of the form with action = 'mc_backup_tables'
        var data = jQuery( "form#mc_tables" ).serialize();
        // invoke the AJAX action wp_ajax_mc_backup_tables
        jQuery.post( ajaxurl, data, function ( response ) {
            jQuery( "#mc_status" ).html( response );
            if ( response.indexOf( "<?php echo MC_SUCCESS; ?>" ) ) {
                button.disabled = true;
                jQuery( "div#mc_main_buttons button#mc_restore"                  ).prop( "disabled", false );
                jQuery( "div#mc_main_buttons button#mc_delete"                   ).prop( "disabled", false );
                jQuery( "fieldset#ddt_x-table_fields"                            ).prop( "disabled", true  );
                jQuery( "fieldset#mc_db_tools_options input#ddt_x-backup_suffix" ).prop( "disabled", true  );
                jQuery( "fieldset#mc_db_tools_options input#ddt_x-enable_diff"   ).prop( "disabled", true  );
            }
        } );
    } );
    
    // Restore from backup
    
    jQuery( "button.mc-wpdbdt-btn#mc_restore" ).click( function( e ) {
        jQuery( "#mc_status" ).html( "sending..." );
        jQuery.post( ajaxurl, { action: "mc_restore_tables" }, function ( response ) {
            jQuery( "#mc_status" ).html( response );
        } );
    } );
    
    // Delete backup
    
    jQuery( "button.mc-wpdbdt-btn#mc_delete" ).click( function( e ) {
        var button = this;
        jQuery( "#mc_status" ).html( "sending..." );
        jQuery.post( ajaxurl, { action: "mc_delete_backup" }, function ( response ) {
            jQuery( "#mc_status" ).html( response );
            if ( response.indexOf( "<?php echo MC_SUCCESS; ?>" ) ) {
                button.disabled = true;
                jQuery( "div#mc_main_buttons button#mc_backup"                   ).prop( "disabled", false );
                jQuery( "div#mc_main_buttons button#mc_restore"                  ).prop( "disabled", true  );
                jQuery( "fieldset#ddt_x-table_fields"                            ).prop( "disabled", false );
                jQuery( "fieldset#mc_db_tools_options input#ddt_x-backup_suffix" ).prop( "disabled", false );
                jQuery( "fieldset#mc_db_tools_options input#ddt_x-enable_diff"   ).prop( "disabled", false );
            }
        } );
    } );
    
    // Verify backup suffix on change and CR keydown events
    
    jQuery( "input#ddt_x-backup_suffix" ).change( function( e ) {
        var table_pane   = jQuery( "fieldset#ddt_x-table_fields" );
        var error_pane   = jQuery( "div#mc_db_tools_error_pane" );
        var main_buttons = jQuery( "div#mc_main_buttons" );
        error_pane.text( "checking backup suffix..." ).show( );
        var suffix = this.value;
        jQuery.post( ajaxurl, { action: "mc_check_backup_suffix", backup_suffix: suffix }, function( response ) {
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
    
    // Diff Tool
    
    jQuery( "table#ddt_x-op_counts tbody input[type='checkbox']" ).change( function( e ) {
        var checked  = this.checked;
        var tr       = this.parentNode.parentNode;
        var is_first = this.parentNode === jQuery( tr ).find( "td" ).first( )[ 0 ];

        if ( checked ) {
            jQuery( "table#ddt_x-op_counts tbody tr" ).each( function( ) {
                if ( this !== tr ) {
                    jQuery( this ).find( "> td input[type='checkbox']" ).prop( "checked", false );
                }
            } );
            if ( is_first ) {
                jQuery( tr ).find( "> td input[type='checkbox']" ).prop( "checked", true );
            }
        } else {
            if ( is_first ) {
                jQuery( tr ).find( "> td input[type='checkbox']" ).prop( "checked", false );
            } else {
                jQuery( tr ).find( "> td" ).first( ).find( "input[type='checkbox']" ).prop( "checked", false );
            }
        }
        jQuery( "button#ddt_x-diff_view_changes" ).prop( "disabled", jQuery( "table#ddt_x-op_counts input[type='checkbox']:checked" ).length === 0 );
    } );
    
    jQuery( "button#ddt_x-diff_view_changes" ).click( function( e ) {
        var button    = this;
        var checked   = jQuery( "table#ddt_x-op_counts tbody td input[type='checkbox']:checked" );
        var table     = jQuery( checked[0].parentNode.parentNode ).find( "td" ).first( ).text( );
        var th        = jQuery( "table#ddt_x-op_counts thead th" );
        var operation = "";
        jQuery( checked[0].parentNode.parentNode ).find( "td input[type='checkbox']" ).each( function( i ) {
            if ( this.checked ) {
                operation += jQuery(th[i]).text( ) +" ";
            }
        } );
        if ( !operation ) {
            return;
        }
        operation = operation.trim( ).replace( /\s/g, "," );
        this.disabled = true;
        jQuery.post( ajaxurl, { action: "ddt_x-diff_view_changes", table: table, operation: operation }, function( r ) {
            var table = jQuery( "div#mc_changes_view" ).html( r ).find( "table.ddt_x-table_changes" );
            reduceTableCellContents( table );
            var width = jQuery( "input#ddt_x-table_width" ).val( );
            width = jQuery.isNumeric( width ) ? width + "px" : width;
            table.css( "width", width );
            table.find( "tbody td" ).click( function( e ) {
                var clickedTr = jQuery( this.parentNode );
                var index     = clickedTr.find( "td" ).index( this );
                var updated   = clickedTr.hasClass( "ddt_x-changes_updated" );
                var original  = clickedTr.hasClass( "ddt_x-changes_original" );
                var that      = null;
                if ( updated ) {
                    var prevTr = clickedTr.prev( "tr" );
                    if ( prevTr && prevTr.hasClass( "ddt_x-changes_original" ) ) {
                        that = prevTr.find( "td" )[ index ];
                    }
                } else if ( original ) {
                    var nextTr = clickedTr.next( "tr" );
                    if ( nextTr && nextTr.hasClass( "ddt_x-changes_updated" ) ) {
                        that = nextTr.find( "td" )[ index ];
                    }
                }
                var main = jQuery( "div#ddt_x-detail_content" ).text( this.dataset.origContent ? this.dataset.origContent : this.textContent )
                    .css( "background-color", jQuery( this ).css( "background-color" ) );
                var other = jQuery( "div#ddt_x-detail_content_other" );
                if ( that ) {
                    other.text( that.dataset.origContent ? that.dataset.origContent : that.textContent )
                        .css( "background-color", jQuery( that ).css( "background-color" ) );
                    main.css( "width", "47%" );
                    if ( updated ) {
                        main.css(  { float: "right", margin: "auto 2% auto 1%" } );
                        other.css( { float: "left",  margin: "auto 1% auto 2%" } );
                    } else {
                        main.css(  { float: "left",  margin: "auto 1% auto 2%" } );
                        other.css( { float: "right", margin: "auto 2% auto 1%" } );
                    }
                    other.show( );
                } else {
                    main.css( { width: "96%", float: "none", margin: "auto" } );
                    other.hide( );
                }
                jQuery( "div#ddt_x-detail_popup" ).show( );
                jQuery( "div#ddt_x-popup-margin" ).show( );
            } );
            button.disabled = false;
        } );
    } );
    
    jQuery( "input#ddt_x-table_width" ).change( function( e ) {
        jQuery.post( ajaxurl, { action: "ddt_x-update_diff_options", "ddt_x-table_width": this.value }, function( r ) { } );
        var width = this.value;
        width = jQuery.isNumeric( width ) ? width + "px" : width;
        jQuery( "table.ddt_x-table_changes" ).css( "width", width );
    } );
    
    jQuery( "input#ddt_x-table_cell_size" ).change( function( e ) {
        jQuery.post( ajaxurl, { action: "ddt_x-update_diff_options", "ddt_x-table_cell_size": this.value }, function( r ) { } );
        reduceTableCellContents( jQuery( "div#mc_changes_view" ).find( "table.ddt_x-table_changes" ) );
    } );
    
    jQuery( "div#ddt_x-detail_popup button#ddt_x-close_detail_popup" ).click( function( e ) {
        jQuery( "div#ddt_x-popup-margin" ).hide( );
        jQuery( "div#ddt_x-detail_popup" ).hide( );
    } );

} );