/**
 * DLMUES Client Plugin - Enforcement JavaScript
 *
 * Handles the enforcement overlay, grace period countdown bar,
 * and expiry warnings on admin pages.
 *
 * @package DLMUES_Client
 * @since   1.0.0
 */

/* global dlmuesEnforcement, jQuery */
( function ( $ ) {
    'use strict';

    var DLMUES_Enforcement = {

        countdownInterval: null,

        /**
         * Initialize enforcement UI.
         */
        init: function () {
            if ( dlmuesEnforcement.isGracePeriod ) {
                this.showGraceBar();
            }

            if ( dlmuesEnforcement.isExpired ) {
                this.enhanceEnforcementModal();
            }
        },

        /**
         * Show the grace period warning bar at the top of admin.
         */
        showGraceBar: function () {
            if ( $( '#dlmues-grace-bar' ).length ) {
                return;
            }

            var timeRemaining = dlmuesEnforcement.timeRemaining;
            var renewUrl      = dlmuesEnforcement.renewalUrl || dlmuesEnforcement.settingsUrl;
            var humanReadable = timeRemaining.human_readable || '';

            var $bar = $( '<div id="dlmues-grace-bar">'
                + '<span class="dlmues-grace-text">'
                + '<strong>Grace Period Active</strong> &mdash; '
                + 'Your license has expired. <span id="dlmues-grace-countdown">' + humanReadable + '</span> remaining before restrictions apply.'
                + '</span>'
                + '<span class="dlmues-grace-actions">'
                + '<a href="' + renewUrl + '" class="dlmues-grace-renew">Renew Now</a>'
                + '<button type="button" class="dlmues-grace-dismiss" title="Dismiss">&times;</button>'
                + '</span>'
                + '</div>'
            );

            $( 'body' ).addClass( 'dlmues-grace-active' ).prepend( $bar );

            // Start countdown.
            if ( timeRemaining.total_seconds > 0 ) {
                this.startCountdown( timeRemaining.total_seconds );
            }

            // Dismiss button.
            $bar.on( 'click', '.dlmues-grace-dismiss', function () {
                $bar.slideUp( 200, function () {
                    $( 'body' ).removeClass( 'dlmues-grace-active' );
                } );
            } );
        },

        /**
         * Start a live countdown timer.
         *
         * @param {number} totalSeconds Seconds remaining.
         */
        startCountdown: function ( totalSeconds ) {
            var remaining = totalSeconds;

            function updateDisplay() {
                if ( remaining <= 0 ) {
                    $( '#dlmues-grace-countdown' ).html( '<strong>Expired</strong>' );
                    clearInterval( DLMUES_Enforcement.countdownInterval );
                    // Reload the page to trigger enforcement.
                    setTimeout( function () { location.reload(); }, 2000 );
                    return;
                }

                var days    = Math.floor( remaining / 86400 );
                var hours   = Math.floor( ( remaining % 86400 ) / 3600 );
                var minutes = Math.floor( ( remaining % 3600 ) / 60 );
                var secs    = remaining % 60;

                var parts = [];
                if ( days > 0 ) {
                    parts.push( days + 'd' );
                }
                if ( hours > 0 || days > 0 ) {
                    parts.push( hours + 'h' );
                }
                parts.push( minutes + 'm' );
                parts.push( secs + 's' );

                $( '#dlmues-grace-countdown' ).text( parts.join( ' ' ) );
                remaining--;
            }

            updateDisplay();
            this.countdownInterval = setInterval( updateDisplay, 1000 );
        },

        /**
         * Enhance the enforcement modal with more information.
         */
        enhanceEnforcementModal: function () {
            var $modal = $( '#dlmues-enforcement-modal' );

            if ( ! $modal.length ) {
                return;
            }

            // Replace the simple modal with an enhanced version.
            var license    = dlmuesEnforcement.licenseData || {};
            var renewUrl   = dlmuesEnforcement.renewalUrl || dlmuesEnforcement.settingsUrl;
            var settingsUrl = dlmuesEnforcement.settingsUrl;

            var html = '<div class="dlmues-enforcement-card">'
                + '<div class="dlmues-enforcement-header">'
                + '<span class="dlmues-icon">&#9888;</span>'
                + '<h2>License Expired</h2>'
                + '</div>'
                + '<div class="dlmues-enforcement-body">'
                + '<p>Your license for <strong>' + ( license.product_name || license.product_slug || 'this product' ) + '</strong> has expired and the grace period has ended. Please renew to restore full access.</p>';

            // Info table.
            html += '<div class="dlmues-enforcement-info"><table>';

            if ( license.subscription_type ) {
                html += '<tr><td>Plan</td><td>' + DLMUES_Enforcement.capitalize( license.subscription_type ) + '</td></tr>';
            }
            if ( license.expires_at ) {
                html += '<tr><td>Expired</td><td>' + license.expires_at + '</td></tr>';
            }
            if ( license.price && license.currency ) {
                html += '<tr><td>Renewal Amount</td><td>' + license.currency + ' ' + parseFloat( license.price ).toFixed( 2 ) + '</td></tr>';
            }
            if ( license.server_url ) {
                html += '<tr><td>License Server</td><td>' + license.server_url.replace( /^https?:\/\//, '' ) + '</td></tr>';
            }

            html += '</table></div>';

            // Action buttons.
            html += '<div class="dlmues-enforcement-actions">'
                + '<a href="' + settingsUrl + '" class="dlmues-enforce-renew-btn">Renew License</a>'
                + '</div>';

            html += '<p class="dlmues-enforcement-hint">You can still access the license settings page to manage your license.</p>';

            html += '</div></div>';

            $modal.html( html );
        },

        /**
         * Capitalize a string.
         *
         * @param {string} str The string.
         * @return {string} Capitalized string.
         */
        capitalize: function ( str ) {
            if ( ! str ) {
                return '';
            }
            return str.charAt( 0 ).toUpperCase() + str.slice( 1 );
        },
    };

    $( document ).ready( function () {
        DLMUES_Enforcement.init();
    } );

} )( jQuery );
