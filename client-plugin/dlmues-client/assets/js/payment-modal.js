/**
 * DLMUES Client Plugin - Payment Modal JavaScript
 *
 * Handles Paystack payment flow: plan selection, coupon application,
 * payment initialization, and verification via the license server.
 *
 * @package DLMUES_Client
 * @since   1.0.0
 */

/* global dlmuesPayment, jQuery, PaystackPop */
( function ( $ ) {
    'use strict';

    var DLMUES_Payment = {

        selectedPlan: null,
        plans: null,
        discountedPrice: null,
        couponCode: null,

        /**
         * Initialize payment modal functionality.
         */
        init: function () {
            this.bindPayButton();
            this.bindModalPlans();
            this.bindModalCoupon();
            this.bindModalClose();
        },

        /**
         * Bind the "Pay Now" / "Renew" button to open the modal.
         */
        bindPayButton: function () {
            $( document ).on( 'click', '#dlmues-open-payment, .dlmues-renew-btn', function ( e ) {
                e.preventDefault();

                var $btn = $( this );
                $btn.prop( 'disabled', true ).html( '<span class="dlmues-spinner"></span> Loading plans...' );

                // Fetch available plans from the server.
                $.post( dlmuesPayment.ajaxUrl, {
                    action: 'dlmues_get_plans',
                    nonce: dlmuesPayment.nonce,
                }, function ( response ) {
                    if ( response.success && response.data.plans ) {
                        DLMUES_Payment.plans = response.data.plans;
                        DLMUES_Payment.renderModal( response.data );
                        $( '.dlmues-payment-overlay' ).addClass( 'active' );
                    } else {
                        alert( response.data.message || 'Could not load plans.' );
                    }
                } ).fail( function () {
                    alert( 'Connection error. Please try again.' );
                } ).always( function () {
                    $btn.prop( 'disabled', false ).text( 'Renew License' );
                } );
            } );
        },

        /**
         * Render the payment modal with plan data.
         *
         * @param {Object} data Server response data including plans and license info.
         */
        renderModal: function ( data ) {
            var $overlay = $( '#dlmues-payment-overlay' );

            // Create overlay if it doesn't exist.
            if ( ! $overlay.length ) {
                $overlay = $( '<div id="dlmues-payment-overlay" class="dlmues-payment-overlay"></div>' );
                $( 'body' ).append( $overlay );
            }

            var plans    = data.plans;
            var license  = dlmuesPayment.licenseData || {};
            var currency = data.currency || license.currency || 'USD';
            var serverDomain = data.server_domain || '';

            // Build plan cards.
            var planHtml = '<div class="dlmues-modal-plans">';
            var firstPlan = null;

            $.each( plans, function ( key, plan ) {
                if ( ! firstPlan ) {
                    firstPlan = key;
                }
                var isCurrentPlan = ( license.subscription_type === key ) ? ' selected' : '';
                if ( isCurrentPlan ) {
                    firstPlan = key;
                }
                planHtml += '<div class="dlmues-modal-plan' + isCurrentPlan + '" data-plan="' + key + '" data-price="' + plan.price + '">';
                planHtml += '<div class="modal-plan-name">' + plan.label + '</div>';
                planHtml += '<div class="modal-plan-price">' + currency + ' ' + parseFloat( plan.price ).toFixed( 2 ) + '</div>';
                planHtml += '</div>';
            } );
            planHtml += '</div>';

            // Set initial selected plan.
            if ( license.subscription_type && plans[ license.subscription_type ] ) {
                DLMUES_Payment.selectedPlan = license.subscription_type;
            } else {
                DLMUES_Payment.selectedPlan = firstPlan;
            }

            var selectedPrice = plans[ DLMUES_Payment.selectedPlan ] ? plans[ DLMUES_Payment.selectedPlan ].price : 0;

            var html = '<div class="dlmues-payment-modal">'
                + '<div class="dlmues-payment-modal-header">'
                + '<h3>Renew License</h3>'
                + '<button type="button" class="dlmues-payment-modal-close">&times;</button>'
                + '</div>'
                + '<div class="dlmues-payment-modal-body">';

            // Summary section.
            html += '<div class="dlmues-payment-summary">'
                + '<table>'
                + '<tr><td>Product</td><td>' + ( license.product_name || license.product_slug || 'License' ) + '</td></tr>'
                + '<tr><td>Current Status</td><td>' + ( license.status || 'Expired' ) + '</td></tr>';

            if ( license.expires_at ) {
                html += '<tr><td>Expired On</td><td>' + license.expires_at + '</td></tr>';
            }
            if ( serverDomain ) {
                html += '<tr><td>License Server</td><td>' + serverDomain + '</td></tr>';
            }

            html += '</table></div>';

            // Plan selection.
            html += '<p style="font-weight:600;margin-bottom:8px;">Select Plan:</p>';
            html += planHtml;

            // Coupon.
            html += '<div class="dlmues-modal-coupon">'
                + '<input type="text" id="dlmues-modal-coupon-code" placeholder="Coupon code (optional)">'
                + '<button type="button" id="dlmues-modal-apply-coupon">Apply</button>'
                + '</div>'
                + '<div class="dlmues-modal-coupon-msg" id="dlmues-modal-coupon-msg"></div>';

            // Total.
            html += '<div class="dlmues-payment-summary" style="margin-top:15px;">'
                + '<table>'
                + '<tr class="dlmues-payment-total">'
                + '<td>Total</td>'
                + '<td id="dlmues-modal-total">' + currency + ' ' + parseFloat( selectedPrice ).toFixed( 2 ) + '</td>'
                + '</tr>'
                + '</table></div>';

            // Pay button.
            html += '<button type="button" class="dlmues-pay-btn" id="dlmues-modal-pay-btn">Pay ' + currency + ' ' + parseFloat( selectedPrice ).toFixed( 2 ) + '</button>';

            html += '</div></div>';

            $overlay.html( html );
        },

        /**
         * Bind plan selection clicks in modal.
         */
        bindModalPlans: function () {
            $( document ).on( 'click', '.dlmues-modal-plan', function () {
                $( '.dlmues-modal-plan' ).removeClass( 'selected' );
                $( this ).addClass( 'selected' );

                DLMUES_Payment.selectedPlan = $( this ).data( 'plan' );
                DLMUES_Payment.discountedPrice = null;
                DLMUES_Payment.couponCode = null;

                var price    = parseFloat( $( this ).data( 'price' ) );
                var currency = ( dlmuesPayment.licenseData && dlmuesPayment.licenseData.currency ) || 'USD';

                $( '#dlmues-modal-total' ).text( currency + ' ' + price.toFixed( 2 ) );
                $( '#dlmues-modal-pay-btn' ).text( 'Pay ' + currency + ' ' + price.toFixed( 2 ) );
                $( '#dlmues-modal-coupon-msg' ).text( '' ).attr( 'class', 'dlmues-modal-coupon-msg' );
                $( '#dlmues-modal-coupon-code' ).val( '' );
            } );
        },

        /**
         * Bind coupon application in modal.
         */
        bindModalCoupon: function () {
            $( document ).on( 'click', '#dlmues-modal-apply-coupon', function () {
                var code = $( '#dlmues-modal-coupon-code' ).val().trim();
                var $msg = $( '#dlmues-modal-coupon-msg' );

                if ( ! code ) {
                    $msg.text( 'Enter a coupon code.' ).attr( 'class', 'dlmues-modal-coupon-msg error' );
                    return;
                }

                $msg.text( 'Applying...' ).attr( 'class', 'dlmues-modal-coupon-msg' );

                $.post( dlmuesPayment.ajaxUrl, {
                    action: 'dlmues_apply_coupon',
                    nonce: dlmuesPayment.nonce,
                    coupon_code: code,
                    plan: DLMUES_Payment.selectedPlan,
                }, function ( response ) {
                    if ( response.success ) {
                        var newPrice = parseFloat( response.data.new_price );
                        var currency = ( dlmuesPayment.licenseData && dlmuesPayment.licenseData.currency ) || 'USD';

                        DLMUES_Payment.discountedPrice = newPrice;
                        DLMUES_Payment.couponCode = code;

                        $( '#dlmues-modal-total' ).text( currency + ' ' + newPrice.toFixed( 2 ) );
                        $( '#dlmues-modal-pay-btn' ).text( 'Pay ' + currency + ' ' + newPrice.toFixed( 2 ) );
                        $msg.text( response.data.message ).attr( 'class', 'dlmues-modal-coupon-msg success' );
                    } else {
                        $msg.text( response.data.message || 'Invalid coupon.' ).attr( 'class', 'dlmues-modal-coupon-msg error' );
                    }
                } ).fail( function () {
                    $msg.text( 'Error applying coupon.' ).attr( 'class', 'dlmues-modal-coupon-msg error' );
                } );
            } );

            // Bind pay button.
            $( document ).on( 'click', '#dlmues-modal-pay-btn', function ( e ) {
                e.preventDefault();
                DLMUES_Payment.initiatePayment();
            } );
        },

        /**
         * Close modal.
         */
        bindModalClose: function () {
            $( document ).on( 'click', '.dlmues-payment-modal-close', function () {
                $( '.dlmues-payment-overlay' ).removeClass( 'active' );
            } );

            // Click outside modal to close.
            $( document ).on( 'click', '.dlmues-payment-overlay', function ( e ) {
                if ( $( e.target ).hasClass( 'dlmues-payment-overlay' ) ) {
                    $( '.dlmues-payment-overlay' ).removeClass( 'active' );
                }
            } );
        },

        /**
         * Initiate Paystack payment.
         */
        initiatePayment: function () {
            var $btn = $( '#dlmues-modal-pay-btn' );
            $btn.prop( 'disabled', true ).html( '<span class="dlmues-spinner"></span> Initializing payment...' );

            $.post( dlmuesPayment.ajaxUrl, {
                action: 'dlmues_initiate_payment',
                nonce: dlmuesPayment.nonce,
                plan: DLMUES_Payment.selectedPlan,
                coupon_code: DLMUES_Payment.couponCode || '',
            }, function ( response ) {
                if ( response.success && response.data.authorization_url ) {
                    // Redirect to Paystack.
                    window.location.href = response.data.authorization_url;
                } else if ( response.success && response.data.access_code ) {
                    // Use inline Paystack popup if access_code returned.
                    DLMUES_Payment.openPaystackInline( response.data );
                } else {
                    alert( response.data.message || 'Payment initialization failed.' );
                    $btn.prop( 'disabled', false ).text( 'Pay Now' );
                }
            } ).fail( function () {
                alert( 'Connection error. Please try again.' );
                $btn.prop( 'disabled', false ).text( 'Pay Now' );
            } );
        },

        /**
         * Open Paystack inline checkout popup.
         *
         * @param {Object} data Response data with access_code, public_key, etc.
         */
        openPaystackInline: function ( data ) {
            if ( typeof PaystackPop === 'undefined' ) {
                // Fallback to redirect.
                if ( data.authorization_url ) {
                    window.location.href = data.authorization_url;
                }
                return;
            }

            var handler = PaystackPop.setup( {
                key: data.public_key,
                email: data.email,
                amount: data.amount,
                currency: data.currency || 'NGN',
                ref: data.reference,
                callback: function ( response ) {
                    // Payment successful – verify.
                    DLMUES_Payment.verifyPayment( response.reference );
                },
                onClose: function () {
                    $( '#dlmues-modal-pay-btn' ).prop( 'disabled', false ).text( 'Pay Now' );
                },
            } );

            handler.openIframe();
        },

        /**
         * Verify payment after completion.
         *
         * @param {string} reference Paystack payment reference.
         */
        verifyPayment: function ( reference ) {
            var $body = $( '.dlmues-payment-modal-body' );

            $body.html(
                '<div class="dlmues-payment-status">'
                + '<div class="dlmues-spinner" style="width:32px;height:32px;border-width:3px;margin:0 auto 15px;"></div>'
                + '<h4>Verifying payment...</h4>'
                + '<p>Please wait while we confirm your payment.</p>'
                + '</div>'
            );

            $.post( dlmuesPayment.ajaxUrl, {
                action: 'dlmues_verify_payment',
                nonce: dlmuesPayment.nonce,
                reference: reference,
            }, function ( response ) {
                if ( response.success ) {
                    $body.html(
                        '<div class="dlmues-payment-status success">'
                        + '<span class="dashicons dashicons-yes-alt"></span>'
                        + '<h4>License Renewed Successfully!</h4>'
                        + '<p>' + ( response.data.message || 'Your license has been renewed.' ) + '</p>'
                        + '<p><button class="button button-primary" onclick="window.location.href=\'' + dlmuesPayment.returnUrl + '\'">Continue</button></p>'
                        + '</div>'
                    );
                } else {
                    $body.html(
                        '<div class="dlmues-payment-status error">'
                        + '<span class="dashicons dashicons-dismiss"></span>'
                        + '<h4>Verification Failed</h4>'
                        + '<p>' + ( response.data.message || 'Could not verify payment.' ) + '</p>'
                        + '<p><button class="button" onclick="window.location.reload()">Try Again</button></p>'
                        + '</div>'
                    );
                }
            } ).fail( function () {
                $body.html(
                    '<div class="dlmues-payment-status error">'
                    + '<span class="dashicons dashicons-dismiss"></span>'
                    + '<h4>Connection Error</h4>'
                    + '<p>Could not verify payment. Please contact support with your reference: ' + reference + '</p>'
                    + '<p><button class="button" onclick="window.location.reload()">Try Again</button></p>'
                    + '</div>'
                );
            } );
        },
    };

    $( document ).ready( function () {
        DLMUES_Payment.init();
    } );

} )( jQuery );
