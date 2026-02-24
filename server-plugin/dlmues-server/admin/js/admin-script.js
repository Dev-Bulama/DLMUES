/**
 * DLMUES Server Plugin - Admin JavaScript
 *
 * Handles all admin-side interactivity: inline editing, AJAX operations,
 * client management, bulk import, coupon management, and invoice viewing.
 *
 * @package DLMUES_Server
 * @since   1.0.0
 */

/* global dlmuesAdmin, jQuery, Chart */
( function ( $ ) {
    'use strict';

    var DLMUES_Admin = {

        /**
         * Initialize all admin functionality.
         */
        init: function () {
            this.initDataTables();
            this.initInlineEdit();
            this.initBulkImport();
            this.initCouponForm();
            this.initInvoiceViewer();
            this.initVisitorCountInjection();
            this.initPaystackTest();
            this.initLicenseActions();
        },

        /**
         * Initialize DataTables for enhanced table features.
         */
        initDataTables: function () {
            if ( $.fn.DataTable && $( '#dlmues-licenses-table' ).length ) {
                $( '#dlmues-licenses-table' ).DataTable( {
                    order: [ [ 6, 'asc' ] ],
                    pageLength: 25,
                    responsive: true,
                    language: {
                        search: dlmuesAdmin.strings.search || 'Search:',
                    },
                } );
            }

            if ( $.fn.DataTable && $( '#dlmues-clients-table' ).length ) {
                $( '#dlmues-clients-table' ).DataTable( {
                    order: [ [ 5, 'asc' ] ],
                    pageLength: 25,
                    responsive: true,
                    columnDefs: [
                        { orderable: false, targets: [ -1 ] },
                    ],
                } );
            }
        },

        /**
         * Initialize inline editing for client rows.
         */
        initInlineEdit: function () {
            // Open inline edit.
            $( document ).on( 'click', '.dlmues-edit-btn', function ( e ) {
                e.preventDefault();
                var $btn = $( this );
                var licenseKey = $btn.data( 'license-key' );
                var $editRow = $( '#dlmues-edit-' + licenseKey.replace( /[^a-zA-Z0-9]/g, '-' ) );

                // Close any open edit rows.
                $( '.dlmues-inline-edit-row' ).not( $editRow ).hide();

                $editRow.toggle();
            } );

            // Cancel inline edit.
            $( document ).on( 'click', '.dlmues-cancel-edit', function ( e ) {
                e.preventDefault();
                $( this ).closest( '.dlmues-inline-edit-row' ).hide();
            } );

            // Save inline edit.
            $( document ).on( 'click', '.dlmues-save-edit', function ( e ) {
                e.preventDefault();
                var $btn = $( this );
                var $row = $btn.closest( '.dlmues-inline-edit-row' );
                var licenseKey = $btn.data( 'license-key' );

                var data = {
                    action: 'dlmues_update_license',
                    nonce: dlmuesAdmin.nonce,
                    license_key: licenseKey,
                    subscription_type: $row.find( '[name="subscription_type"]' ).val(),
                    price: $row.find( '[name="price"]' ).val(),
                    currency: $row.find( '[name="currency"]' ).val(),
                    grace_period_days: $row.find( '[name="grace_period_days"]' ).val(),
                    enforcement_mode: $row.find( '[name="enforcement_mode"]' ).val(),
                    notes: $row.find( '[name="notes"]' ).val(),
                };

                $btn.prop( 'disabled', true ).text( 'Saving...' );

                $.post( dlmuesAdmin.ajaxUrl, data, function ( response ) {
                    if ( response.success ) {
                        DLMUES_Admin.showNotice( response.data.message, 'success' );
                        $row.hide();
                        setTimeout( function () { location.reload(); }, 800 );
                    } else {
                        DLMUES_Admin.showNotice( response.data.message || dlmuesAdmin.strings.error, 'error' );
                    }
                } ).fail( function () {
                    DLMUES_Admin.showNotice( dlmuesAdmin.strings.error, 'error' );
                } ).always( function () {
                    $btn.prop( 'disabled', false ).text( 'Save Changes' );
                } );
            } );
        },

        /**
         * Initialize bulk client import.
         */
        initBulkImport: function () {
            var $form = $( '#dlmues-bulk-import-form' );
            if ( ! $form.length ) {
                return;
            }

            $form.on( 'submit', function ( e ) {
                e.preventDefault();

                var fileInput = $( '#dlmues-import-file' )[ 0 ];
                if ( ! fileInput.files.length ) {
                    DLMUES_Admin.showNotice( 'Please select a CSV file.', 'error' );
                    return;
                }

                var formData = new FormData();
                formData.append( 'action', 'dlmues_bulk_import' );
                formData.append( 'nonce', dlmuesAdmin.nonce );
                formData.append( 'csv_file', fileInput.files[ 0 ] );
                formData.append( 'default_plan', $( '#dlmues-import-default-plan' ).val() );
                formData.append( 'default_currency', $( '#dlmues-import-default-currency' ).val() );
                formData.append( 'send_welcome_email', $( '#dlmues-import-send-email' ).is( ':checked' ) ? 1 : 0 );

                var $btn = $form.find( 'button[type="submit"]' );
                $btn.prop( 'disabled', true ).html( '<span class="dlmues-loading"></span> Importing...' );

                $( '#dlmues-import-results' ).empty();

                $.ajax( {
                    url: dlmuesAdmin.ajaxUrl,
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function ( response ) {
                        if ( response.success ) {
                            DLMUES_Admin.showNotice( response.data.summary, 'success' );
                            DLMUES_Admin.renderImportResults( response.data.results );
                        } else {
                            DLMUES_Admin.showNotice( response.data.message || dlmuesAdmin.strings.error, 'error' );
                        }
                    },
                    error: function () {
                        DLMUES_Admin.showNotice( dlmuesAdmin.strings.error, 'error' );
                    },
                    complete: function () {
                        $btn.prop( 'disabled', false ).text( 'Import CSV' );
                    },
                } );
            } );
        },

        /**
         * Render bulk import results.
         *
         * @param {Array} results Array of import result objects.
         */
        renderImportResults: function ( results ) {
            if ( ! results || ! results.length ) {
                return;
            }

            var $container = $( '#dlmues-import-results' );
            var html = '<div class="dlmues-import-results"><strong>Import Results:</strong>';

            $.each( results, function ( i, row ) {
                var cssClass = row.success ? 'success' : 'error';
                var icon = row.success ? '&#10003;' : '&#10007;';
                html += '<div class="dlmues-import-row ' + cssClass + '">' + icon + ' Row ' + ( i + 1 ) + ': ' + row.email + ' &mdash; ' + row.message + '</div>';
            } );

            html += '</div>';
            $container.html( html );
        },

        /**
         * Initialize coupon creation form.
         */
        initCouponForm: function () {
            var $form = $( '#dlmues-create-coupon-form' );
            if ( ! $form.length ) {
                return;
            }

            $form.on( 'submit', function ( e ) {
                e.preventDefault();

                var $btn = $form.find( 'button[type="submit"]' );
                $btn.prop( 'disabled', true ).text( 'Creating...' );

                $.post( dlmuesAdmin.ajaxUrl, {
                    action: 'dlmues_create_coupon',
                    nonce: dlmuesAdmin.nonce,
                    code: $( '#coupon-code' ).val(),
                    discount_type: $( '#coupon-type' ).val(),
                    discount_value: $( '#coupon-value' ).val(),
                    max_uses: $( '#coupon-max-uses' ).val(),
                    valid_from: $( '#coupon-valid-from' ).val(),
                    valid_until: $( '#coupon-valid-until' ).val(),
                }, function ( response ) {
                    if ( response.success ) {
                        DLMUES_Admin.showNotice( response.data.message, 'success' );
                        setTimeout( function () { location.reload(); }, 800 );
                    } else {
                        DLMUES_Admin.showNotice( response.data.message || dlmuesAdmin.strings.error, 'error' );
                    }
                } ).fail( function () {
                    DLMUES_Admin.showNotice( dlmuesAdmin.strings.error, 'error' );
                } ).always( function () {
                    $btn.prop( 'disabled', false ).text( 'Create Coupon' );
                } );
            } );

            // Delete coupon.
            $( document ).on( 'click', '.dlmues-delete-coupon', function ( e ) {
                e.preventDefault();
                if ( ! confirm( dlmuesAdmin.strings.confirmDelete ) ) {
                    return;
                }

                var $btn = $( this );
                var couponId = $btn.data( 'id' );

                $.post( dlmuesAdmin.ajaxUrl, {
                    action: 'dlmues_delete_coupon',
                    nonce: dlmuesAdmin.nonce,
                    coupon_id: couponId,
                }, function ( response ) {
                    if ( response.success ) {
                        $btn.closest( 'tr' ).fadeOut( function () { $( this ).remove(); } );
                    } else {
                        DLMUES_Admin.showNotice( response.data.message || dlmuesAdmin.strings.error, 'error' );
                    }
                } );
            } );
        },

        /**
         * Initialize invoice viewer popup.
         */
        initInvoiceViewer: function () {
            $( document ).on( 'click', '.dlmues-view-invoice', function ( e ) {
                e.preventDefault();
                var invoiceId = $( this ).data( 'id' );

                $( '#dlmues-invoice-popup-overlay' ).fadeIn( 150 );
                $( '#dlmues-invoice-popup' ).fadeIn( 150 );
                $( '#dlmues-invoice-content' ).html( '<p style="text-align:center;padding:30px;"><span class="dlmues-loading"></span> Loading invoice...</p>' );

                $.post( dlmuesAdmin.ajaxUrl, {
                    action: 'dlmues_get_invoice',
                    nonce: dlmuesAdmin.nonce,
                    invoice_id: invoiceId,
                }, function ( response ) {
                    if ( response.success ) {
                        $( '#dlmues-invoice-content' ).html( response.data.html );
                    } else {
                        $( '#dlmues-invoice-content' ).html( '<p style="color:red;">' + ( response.data.message || dlmuesAdmin.strings.error ) + '</p>' );
                    }
                } );
            } );

            // Close invoice popup.
            $( document ).on( 'click', '#dlmues-invoice-popup-overlay, #dlmues-invoice-popup-close', function () {
                $( '#dlmues-invoice-popup-overlay' ).fadeOut( 150 );
                $( '#dlmues-invoice-popup' ).fadeOut( 150 );
            } );

            // Print invoice.
            $( document ).on( 'click', '#dlmues-print-invoice', function () {
                var content = $( '#dlmues-invoice-content' ).html();
                var win = window.open( '', '_blank' );
                win.document.write( '<html><head><title>Invoice</title></head><body>' + content + '<script>window.onload=function(){window.print();}<\/script></body></html>' );
                win.document.close();
            } );
        },

        /**
         * Initialize visitor count injection.
         */
        initVisitorCountInjection: function () {
            $( document ).on( 'click', '.dlmues-inject-visitors', function ( e ) {
                e.preventDefault();
                var $btn = $( this );
                var licenseKey = $btn.data( 'license-key' );
                var $row = $btn.closest( 'tr' );
                var $input = $row.find( '.dlmues-visitor-inject-input' );

                var count = $input.val();
                if ( count === '' || isNaN( count ) || parseInt( count, 10 ) < 0 ) {
                    DLMUES_Admin.showNotice( 'Please enter a valid visitor count.', 'error' );
                    return;
                }

                $btn.prop( 'disabled', true );

                $.post( dlmuesAdmin.ajaxUrl, {
                    action: 'dlmues_inject_visitor_count',
                    nonce: dlmuesAdmin.nonce,
                    license_key: licenseKey,
                    count: parseInt( count, 10 ),
                }, function ( response ) {
                    if ( response.success ) {
                        DLMUES_Admin.showNotice( response.data.message, 'success' );
                        $row.find( '.dlmues-visitor-display' ).text( response.data.display_count );
                        $input.val( '' );
                    } else {
                        DLMUES_Admin.showNotice( response.data.message || dlmuesAdmin.strings.error, 'error' );
                    }
                } ).fail( function () {
                    DLMUES_Admin.showNotice( dlmuesAdmin.strings.error, 'error' );
                } ).always( function () {
                    $btn.prop( 'disabled', false );
                } );
            } );
        },

        /**
         * Initialize site health popup.
         */
        initSiteHealthPopup: function () {
            $( document ).on( 'click', '.dlmues-view-health', function ( e ) {
                e.preventDefault();
                var licenseKey = $( this ).data( 'license-key' );

                $( '#dlmues-popup-overlay' ).fadeIn( 150 );
                $( '#dlmues-site-health-popup' ).fadeIn( 150 ).find( '.dlmues-popup-body' )
                    .html( '<p style="text-align:center;padding:30px;"><span class="dlmues-loading"></span> Loading...</p>' );

                $.post( dlmuesAdmin.ajaxUrl, {
                    action: 'dlmues_get_site_health',
                    nonce: dlmuesAdmin.nonce,
                    license_key: licenseKey,
                }, function ( response ) {
                    if ( response.success ) {
                        $( '#dlmues-site-health-popup .dlmues-popup-body' ).html( response.data.html );
                    } else {
                        $( '#dlmues-site-health-popup .dlmues-popup-body' ).html( '<p style="color:red;">Could not load health data.</p>' );
                    }
                } );
            } );

            // Close popup.
            $( document ).on( 'click', '#dlmues-popup-overlay, .dlmues-popup-close', function () {
                $( '#dlmues-popup-overlay' ).fadeOut( 150 );
                $( '.dlmues-site-health-popup' ).fadeOut( 150 );
                $( '#dlmues-invoice-popup' ).fadeOut( 150 );
                $( '#dlmues-invoice-popup-overlay' ).fadeOut( 150 );
            } );
        },

        /**
         * Initialize Paystack connection test.
         */
        initPaystackTest: function () {
            $( '#dlmues-test-paystack' ).on( 'click', function ( e ) {
                e.preventDefault();
                var $btn = $( this );
                $btn.prop( 'disabled', true ).text( 'Testing...' );

                $.post( dlmuesAdmin.ajaxUrl, {
                    action: 'dlmues_test_paystack',
                    nonce: dlmuesAdmin.nonce,
                }, function ( response ) {
                    if ( response.success ) {
                        DLMUES_Admin.showNotice( dlmuesAdmin.strings.testSuccess, 'success' );
                    } else {
                        DLMUES_Admin.showNotice( dlmuesAdmin.strings.testFail + ' ' + ( response.data.message || '' ), 'error' );
                    }
                } ).fail( function () {
                    DLMUES_Admin.showNotice( dlmuesAdmin.strings.testFail, 'error' );
                } ).always( function () {
                    $btn.prop( 'disabled', false ).text( 'Test Connection' );
                } );
            } );
        },

        /**
         * Initialize license-specific AJAX actions.
         */
        initLicenseActions: function () {
            // Quick suspend via AJAX (from clients page action buttons).
            $( document ).on( 'click', '.dlmues-quick-suspend', function ( e ) {
                e.preventDefault();
                if ( ! confirm( dlmuesAdmin.strings.confirmSuspend ) ) {
                    return;
                }

                var $btn = $( this );
                var licenseKey = $btn.data( 'license-key' );

                $.post( dlmuesAdmin.ajaxUrl, {
                    action: 'dlmues_quick_suspend',
                    nonce: dlmuesAdmin.nonce,
                    license_key: licenseKey,
                }, function ( response ) {
                    if ( response.success ) {
                        DLMUES_Admin.showNotice( response.data.message, 'success' );
                        $btn.closest( 'tr' ).find( '.dlmues-status-cell' )
                            .html( '<span class="dlmues-badge dlmues-badge-suspended">Suspended</span>' );
                        $btn.replaceWith( '<button class="button button-small dlmues-quick-reactivate" data-license-key="' + licenseKey + '">Reactivate</button>' );
                    } else {
                        DLMUES_Admin.showNotice( response.data.message || dlmuesAdmin.strings.error, 'error' );
                    }
                } );
            } );

            // Quick reactivate.
            $( document ).on( 'click', '.dlmues-quick-reactivate', function ( e ) {
                e.preventDefault();
                var $btn = $( this );
                var licenseKey = $btn.data( 'license-key' );

                $.post( dlmuesAdmin.ajaxUrl, {
                    action: 'dlmues_quick_reactivate',
                    nonce: dlmuesAdmin.nonce,
                    license_key: licenseKey,
                }, function ( response ) {
                    if ( response.success ) {
                        DLMUES_Admin.showNotice( response.data.message, 'success' );
                        $btn.closest( 'tr' ).find( '.dlmues-status-cell' )
                            .html( '<span class="dlmues-badge dlmues-badge-active">Active</span>' );
                        $btn.replaceWith( '<button class="button button-small dlmues-quick-suspend" data-license-key="' + licenseKey + '">Suspend</button>' );
                    } else {
                        DLMUES_Admin.showNotice( response.data.message || dlmuesAdmin.strings.error, 'error' );
                    }
                } );
            } );

            // Send renewal reminder.
            $( document ).on( 'click', '.dlmues-send-reminder', function ( e ) {
                e.preventDefault();
                var $btn = $( this );
                var licenseKey = $btn.data( 'license-key' );

                $btn.prop( 'disabled', true ).text( 'Sending...' );

                $.post( dlmuesAdmin.ajaxUrl, {
                    action: 'dlmues_send_reminder',
                    nonce: dlmuesAdmin.nonce,
                    license_key: licenseKey,
                }, function ( response ) {
                    if ( response.success ) {
                        DLMUES_Admin.showNotice( response.data.message, 'success' );
                    } else {
                        DLMUES_Admin.showNotice( response.data.message || dlmuesAdmin.strings.error, 'error' );
                    }
                } ).always( function () {
                    $btn.prop( 'disabled', false ).text( 'Send Reminder' );
                } );
            } );

            // Create trial license.
            var $trialForm = $( '#dlmues-create-trial-form' );
            if ( $trialForm.length ) {
                $trialForm.on( 'submit', function ( e ) {
                    e.preventDefault();
                    var $btn = $trialForm.find( 'button[type="submit"]' );
                    $btn.prop( 'disabled', true ).text( 'Creating...' );

                    $.post( dlmuesAdmin.ajaxUrl, {
                        action: 'dlmues_create_trial',
                        nonce: dlmuesAdmin.nonce,
                        client_email: $trialForm.find( '[name="trial_email"]' ).val(),
                        product_slug: $trialForm.find( '[name="trial_product"]' ).val(),
                        trial_days: $trialForm.find( '[name="trial_days"]' ).val(),
                    }, function ( response ) {
                        if ( response.success ) {
                            DLMUES_Admin.showNotice( response.data.message, 'success' );
                            setTimeout( function () { location.reload(); }, 800 );
                        } else {
                            DLMUES_Admin.showNotice( response.data.message || dlmuesAdmin.strings.error, 'error' );
                        }
                    } ).always( function () {
                        $btn.prop( 'disabled', false ).text( 'Create Trial' );
                    } );
                } );
            }
        },

        /**
         * Show an admin notice.
         *
         * @param {string} message The message to show.
         * @param {string} type    'success', 'error', 'warning', 'info'.
         */
        showNotice: function ( message, type ) {
            type = type || 'success';
            var $notice = $( '<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button></div>' );

            $( '.dlmues-admin h1' ).after( $notice );

            // Auto dismiss after 4 seconds.
            setTimeout( function () {
                $notice.fadeOut( function () { $( this ).remove(); } );
            }, 4000 );

            // Manual dismiss.
            $notice.on( 'click', '.notice-dismiss', function () {
                $notice.fadeOut( function () { $( this ).remove(); } );
            } );
        },
    };

    $( document ).ready( function () {
        DLMUES_Admin.init();
        DLMUES_Admin.initSiteHealthPopup();
    } );

} )( jQuery );
