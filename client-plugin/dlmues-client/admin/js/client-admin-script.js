/**
 * DLMUES Client Plugin - Admin JavaScript
 *
 * Handles license activation, deactivation, status refresh,
 * plan selection, and coupon application on the settings page.
 *
 * @package DLMUES_Client
 * @since   1.0.0
 */

/* global dlmuesClient, jQuery */
( function ( $ ) {
    'use strict';

    var DLMUES_ClientAdmin = {

        /**
         * Initialize all client admin functionality.
         */
        init: function () {
            this.bindActivation();
            this.bindDeactivation();
            this.bindRefresh();
            this.bindPlanSelection();
            this.bindCoupon();
            this.checkPaymentReturn();
        },

        /**
         * Bind the license activation form.
         */
        bindActivation: function () {
            $( '#dlmues-activate-btn' ).on( 'click', function ( e ) {
                e.preventDefault();

                var $btn = $( this );

                var licenseKey = $( '#dlmues-license-key' ).val();

                if ( ! licenseKey ) {
                    DLMUES_ClientAdmin.showNotice( dlmuesClient.i18n.error, 'error' );
                    return;
                }

                $btn.prop( 'disabled', true ).html( '<span class="dlmues-spinner"></span> ' + dlmuesClient.i18n.activating );

                $.post( dlmuesClient.ajaxUrl, {
                    action: 'dlmues_activate_license',
                    nonce: dlmuesClient.nonce,
                    license_key: licenseKey,
                }, function ( response ) {
                    if ( response.success ) {
                        DLMUES_ClientAdmin.showNotice( response.data.message, 'success' );
                        setTimeout( function () { location.reload(); }, 1000 );
                    } else {
                        DLMUES_ClientAdmin.showNotice( response.data.message || dlmuesClient.i18n.error, 'error' );
                    }
                } ).fail( function () {
                    DLMUES_ClientAdmin.showNotice( dlmuesClient.i18n.error, 'error' );
                } ).always( function () {
                    $btn.prop( 'disabled', false ).text( 'Activate License' );
                } );
            } );
        },

        /**
         * Bind the deactivate button.
         */
        bindDeactivation: function () {
            $( '#dlmues-deactivate-btn' ).on( 'click', function ( e ) {
                e.preventDefault();

                if ( ! confirm( dlmuesClient.i18n.confirmDeactivate ) ) {
                    return;
                }

                var $btn = $( this );
                $btn.prop( 'disabled', true ).html( '<span class="dlmues-spinner"></span> ' + dlmuesClient.i18n.deactivating );

                $.post( dlmuesClient.ajaxUrl, {
                    action: 'dlmues_deactivate_license',
                    nonce: dlmuesClient.nonce,
                }, function ( response ) {
                    if ( response.success ) {
                        DLMUES_ClientAdmin.showNotice( response.data.message, 'success' );
                        setTimeout( function () { location.reload(); }, 1000 );
                    } else {
                        DLMUES_ClientAdmin.showNotice( response.data.message || dlmuesClient.i18n.error, 'error' );
                    }
                } ).fail( function () {
                    DLMUES_ClientAdmin.showNotice( dlmuesClient.i18n.error, 'error' );
                } ).always( function () {
                    $btn.prop( 'disabled', false ).text( 'Deactivate' );
                } );
            } );
        },

        /**
         * Bind the refresh status button.
         */
        bindRefresh: function () {
            $( '#dlmues-refresh-btn' ).on( 'click', function ( e ) {
                e.preventDefault();

                var $btn = $( this );
                $btn.prop( 'disabled', true ).html( '<span class="dlmues-spinner"></span> ' + dlmuesClient.i18n.refreshing );

                $.post( dlmuesClient.ajaxUrl, {
                    action: 'dlmues_refresh_status',
                    nonce: dlmuesClient.nonce,
                }, function ( response ) {
                    if ( response.success ) {
                        DLMUES_ClientAdmin.showNotice( response.data.message, 'success' );
                        setTimeout( function () { location.reload(); }, 800 );
                    } else {
                        DLMUES_ClientAdmin.showNotice( response.data.message || dlmuesClient.i18n.error, 'error' );
                    }
                } ).fail( function () {
                    DLMUES_ClientAdmin.showNotice( dlmuesClient.i18n.error, 'error' );
                } ).always( function () {
                    $btn.prop( 'disabled', false ).text( 'Refresh Status' );
                } );
            } );
        },

        /**
         * Bind plan selection cards.
         */
        bindPlanSelection: function () {
            $( '.dlmues-plan-option' ).on( 'click', function () {
                $( '.dlmues-plan-option' ).removeClass( 'selected' );
                $( this ).addClass( 'selected' );
                $( '#dlmues-selected-plan' ).val( $( this ).data( 'plan' ) );
            } );

            // Pre-select current plan.
            var currentPlan = $( '#dlmues-selected-plan' ).val();
            if ( currentPlan ) {
                $( '.dlmues-plan-option[data-plan="' + currentPlan + '"]' ).addClass( 'selected' );
            }
        },

        /**
         * Bind coupon code application.
         */
        bindCoupon: function () {
            $( '#dlmues-apply-coupon' ).on( 'click', function ( e ) {
                e.preventDefault();

                var $btn  = $( this );
                var code  = $( '#dlmues-coupon-code' ).val().trim();
                var $result = $( '#dlmues-coupon-result' );

                if ( ! code ) {
                    $result.text( 'Enter a coupon code.' ).attr( 'class', 'dlmues-coupon-result error' );
                    return;
                }

                $btn.prop( 'disabled', true ).text( 'Applying...' );
                $result.text( '' ).attr( 'class', 'dlmues-coupon-result' );

                $.post( dlmuesClient.ajaxUrl, {
                    action: 'dlmues_apply_coupon',
                    nonce: dlmuesClient.nonce,
                    coupon_code: code,
                }, function ( response ) {
                    if ( response.success ) {
                        $result.text( response.data.message ).attr( 'class', 'dlmues-coupon-result success' );
                        if ( response.data.new_price !== undefined ) {
                            $( '#dlmues-renewal-amount' ).text( response.data.new_price );
                        }
                    } else {
                        $result.text( response.data.message || dlmuesClient.i18n.error ).attr( 'class', 'dlmues-coupon-result error' );
                    }
                } ).fail( function () {
                    $result.text( dlmuesClient.i18n.error ).attr( 'class', 'dlmues-coupon-result error' );
                } ).always( function () {
                    $btn.prop( 'disabled', false ).text( 'Apply' );
                } );
            } );
        },

        /**
         * Check if returning from a payment.
         */
        checkPaymentReturn: function () {
            var urlParams = new URLSearchParams( window.location.search );

            if ( urlParams.get( 'payment' ) === 'complete' ) {
                var reference = urlParams.get( 'reference' ) || urlParams.get( 'trxref' );

                if ( reference ) {
                    DLMUES_ClientAdmin.showNotice( 'Verifying payment...', 'info' );

                    $.post( dlmuesClient.ajaxUrl, {
                        action: 'dlmues_verify_payment',
                        nonce: dlmuesClient.nonce,
                        reference: reference,
                    }, function ( response ) {
                        if ( response.success ) {
                            DLMUES_ClientAdmin.showNotice( response.data.message, 'success' );
                            setTimeout( function () {
                                // Remove query params and reload.
                                window.location.href = dlmuesClient.settingsUrl;
                            }, 2000 );
                        } else {
                            DLMUES_ClientAdmin.showNotice( response.data.message || 'Payment verification failed.', 'error' );
                        }
                    } ).fail( function () {
                        DLMUES_ClientAdmin.showNotice( 'Payment verification failed. Please contact support.', 'error' );
                    } );
                }
            }
        },

        /**
         * Show an inline notice.
         *
         * @param {string} message The message.
         * @param {string} type    Notice type: success, error, warning, info.
         */
        showNotice: function ( message, type ) {
            type = type || 'info';

            // Remove existing notices.
            $( '.dlmues-admin-notice-dynamic' ).remove();

            var $notice = $( '<div class="notice notice-' + type + ' is-dismissible dlmues-admin-notice-dynamic"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button></div>' );

            $( '.dlmues-settings-wrap h1' ).first().after( $notice );

            $notice.on( 'click', '.notice-dismiss', function () {
                $notice.fadeOut( function () { $( this ).remove(); } );
            } );

            // Auto dismiss after 5 seconds for success.
            if ( 'success' === type ) {
                setTimeout( function () {
                    $notice.fadeOut( function () { $( this ).remove(); } );
                }, 5000 );
            }
        },
    };

    $( document ).ready( function () {
        DLMUES_ClientAdmin.init();
    } );

} )( jQuery );
