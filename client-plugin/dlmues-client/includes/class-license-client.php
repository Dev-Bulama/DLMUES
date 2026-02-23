<?php
/**
 * License Client handler for DLMUES.
 *
 * Manages license activation, validation, deactivation,
 * and all communication with the license server.
 *
 * @package DLMUES_Client
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_License_Client
 *
 * Core license management for the client side.
 */
class DLMUES_License_Client {

    /**
     * Option prefix for all stored license data.
     *
     * @var string
     */
    private $prefix;

    /**
     * Transient key for cached license status.
     *
     * @var string
     */
    private $cache_key = 'dlmues_license_status_cache';

    /**
     * Cache duration in seconds (1 hour).
     *
     * @var int
     */
    private $cache_duration = HOUR_IN_SECONDS;

    /**
     * API request timeout in seconds.
     *
     * @var int
     */
    private $api_timeout = 30;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->prefix = defined( 'DLMUES_CLIENT_OPTION_PREFIX' )
            ? DLMUES_CLIENT_OPTION_PREFIX
            : 'dlmues_client_';

        // Register cron hook.
        add_action( 'dlmues_license_check', array( $this, 'cron_check_license' ) );
    }

    /**
     * Activate a license key with the server.
     *
     * Sends activation request to the server, stores response data in wp_options.
     *
     * @param string $license_key The license key to activate.
     * @param string $server_url  The license server URL.
     * @return true|WP_Error True on success or WP_Error on failure.
     */
    public function activate_license( $license_key, $server_url ) {
        $license_key = sanitize_text_field( $license_key );
        $server_url  = esc_url_raw( trailingslashit( $server_url ) );

        if ( empty( $license_key ) ) {
            return new WP_Error( 'missing_key', __( 'License key is required.', 'dlmues-client' ) );
        }

        if ( empty( $server_url ) ) {
            return new WP_Error( 'missing_server', __( 'Server URL is required.', 'dlmues-client' ) );
        }

        // Store server URL first so api_request can use it.
        update_option( $this->prefix . 'server_url', $server_url );

        $domain = $this->get_current_domain();

        $response = $this->api_request( 'license/activate', 'POST', array(
            'license_key' => $license_key,
            'domain'      => $domain,
            'site_url'    => home_url(),
            'site_name'   => get_bloginfo( 'name' ),
        ), $server_url );

        if ( is_wp_error( $response ) ) {
            // Clear stored server URL on failure.
            delete_option( $this->prefix . 'server_url' );
            return $response;
        }

        // Store all license data from server response.
        update_option( $this->prefix . 'license_key', $license_key );
        update_option( $this->prefix . 'server_url', $server_url );
        update_option( $this->prefix . 'domain', $domain );
        update_option( $this->prefix . 'status', isset( $response['status'] ) ? sanitize_text_field( $response['status'] ) : 'active' );
        update_option( $this->prefix . 'expires_at', isset( $response['expires_at'] ) ? sanitize_text_field( $response['expires_at'] ) : '' );
        update_option( $this->prefix . 'subscription_type', isset( $response['subscription_type'] ) ? sanitize_text_field( $response['subscription_type'] ) : '' );
        update_option( $this->prefix . 'enforcement_mode', isset( $response['enforcement_mode'] ) ? sanitize_text_field( $response['enforcement_mode'] ) : 'restrict_admin' );
        update_option( $this->prefix . 'grace_period_days', isset( $response['grace_period_days'] ) ? absint( $response['grace_period_days'] ) : 7 );
        update_option( $this->prefix . 'product_slug', isset( $response['product_slug'] ) ? sanitize_text_field( $response['product_slug'] ) : '' );
        update_option( $this->prefix . 'product_name', isset( $response['product_name'] ) ? sanitize_text_field( $response['product_name'] ) : '' );
        update_option( $this->prefix . 'client_email', isset( $response['client_email'] ) ? sanitize_email( $response['client_email'] ) : '' );
        update_option( $this->prefix . 'currency', isset( $response['currency'] ) ? sanitize_text_field( $response['currency'] ) : 'USD' );
        update_option( $this->prefix . 'price', isset( $response['price'] ) ? floatval( $response['price'] ) : 0 );
        update_option( $this->prefix . 'last_validated', current_time( 'mysql' ) );

        // Store API token if server provides one.
        if ( ! empty( $response['api_token'] ) ) {
            update_option( $this->prefix . 'api_token', sanitize_text_field( $response['api_token'] ) );
        }

        // Store renewal URL if provided.
        if ( ! empty( $response['renewal_url'] ) ) {
            update_option( $this->prefix . 'renewal_url', esc_url_raw( $response['renewal_url'] ) );
        }

        // Store managed products list if provided.
        if ( ! empty( $response['managed_products'] ) && is_array( $response['managed_products'] ) ) {
            update_option( $this->prefix . 'managed_products', array_map( 'sanitize_text_field', $response['managed_products'] ) );
        }

        // Cache the valid status.
        set_transient( $this->cache_key, 'valid', $this->cache_duration );

        // Schedule periodic checks.
        $this->schedule_checks();

        return true;
    }

    /**
     * Validate the current license with the server.
     *
     * Contacts the server to verify license status and updates local data.
     * Caches the result in a transient for 1 hour.
     *
     * @return array|WP_Error License status data or WP_Error on failure.
     */
    public function validate_license() {
        $license_key = get_option( $this->prefix . 'license_key', '' );
        $server_url  = get_option( $this->prefix . 'server_url', '' );

        if ( empty( $license_key ) || empty( $server_url ) ) {
            return new WP_Error( 'no_license', __( 'No license configured.', 'dlmues-client' ) );
        }

        $domain = $this->get_current_domain();

        $response = $this->api_request( 'license/validate', 'POST', array(
            'license_key' => $license_key,
            'domain'      => $domain,
        ) );

        if ( is_wp_error( $response ) ) {
            // On connection error, keep existing status but mark as stale.
            // Do not immediately expire the license due to network issues.
            return $response;
        }

        // Update stored license data from server.
        if ( isset( $response['status'] ) ) {
            update_option( $this->prefix . 'status', sanitize_text_field( $response['status'] ) );
        }
        if ( isset( $response['expires_at'] ) ) {
            update_option( $this->prefix . 'expires_at', sanitize_text_field( $response['expires_at'] ) );
        }
        if ( isset( $response['subscription_type'] ) ) {
            update_option( $this->prefix . 'subscription_type', sanitize_text_field( $response['subscription_type'] ) );
        }
        if ( isset( $response['enforcement_mode'] ) ) {
            update_option( $this->prefix . 'enforcement_mode', sanitize_text_field( $response['enforcement_mode'] ) );
        }
        if ( isset( $response['grace_period_days'] ) ) {
            update_option( $this->prefix . 'grace_period_days', absint( $response['grace_period_days'] ) );
        }
        if ( isset( $response['price'] ) ) {
            update_option( $this->prefix . 'price', floatval( $response['price'] ) );
        }
        if ( isset( $response['currency'] ) ) {
            update_option( $this->prefix . 'currency', sanitize_text_field( $response['currency'] ) );
        }
        if ( isset( $response['renewal_url'] ) ) {
            update_option( $this->prefix . 'renewal_url', esc_url_raw( $response['renewal_url'] ) );
        }
        if ( isset( $response['managed_products'] ) && is_array( $response['managed_products'] ) ) {
            update_option( $this->prefix . 'managed_products', array_map( 'sanitize_text_field', $response['managed_products'] ) );
        }

        update_option( $this->prefix . 'last_validated', current_time( 'mysql' ) );

        // Cache the status.
        $cache_value = ( 'active' === $response['status'] ) ? 'valid' : $response['status'];
        set_transient( $this->cache_key, $cache_value, $this->cache_duration );

        return $response;
    }

    /**
     * Get the current license status.
     *
     * Returns cached status if available, otherwise fetches fresh from server.
     *
     * @return string The license status (active, expired, grace_period, suspended, inactive).
     */
    public function get_license_status() {
        $cached = get_transient( $this->cache_key );

        if ( false !== $cached ) {
            if ( 'valid' === $cached ) {
                return 'active';
            }
            return $cached;
        }

        // No cache. Check stored status.
        $status = get_option( $this->prefix . 'status', 'inactive' );

        // If we have a license key, try to validate.
        $license_key = get_option( $this->prefix . 'license_key', '' );
        if ( ! empty( $license_key ) ) {
            $result = $this->validate_license();
            if ( ! is_wp_error( $result ) && isset( $result['status'] ) ) {
                return sanitize_text_field( $result['status'] );
            }
        }

        return $status;
    }

    /**
     * Get all stored license data.
     *
     * @return array Associative array of all license data.
     */
    public function get_license_data() {
        return array(
            'license_key'       => get_option( $this->prefix . 'license_key', '' ),
            'server_url'        => get_option( $this->prefix . 'server_url', '' ),
            'api_token'         => get_option( $this->prefix . 'api_token', '' ),
            'domain'            => get_option( $this->prefix . 'domain', '' ),
            'status'            => get_option( $this->prefix . 'status', 'inactive' ),
            'expires_at'        => get_option( $this->prefix . 'expires_at', '' ),
            'subscription_type' => get_option( $this->prefix . 'subscription_type', '' ),
            'enforcement_mode'  => get_option( $this->prefix . 'enforcement_mode', 'restrict_admin' ),
            'grace_period_days' => get_option( $this->prefix . 'grace_period_days', 7 ),
            'last_validated'    => get_option( $this->prefix . 'last_validated', '' ),
            'product_slug'      => get_option( $this->prefix . 'product_slug', '' ),
            'product_name'      => get_option( $this->prefix . 'product_name', '' ),
            'client_email'      => get_option( $this->prefix . 'client_email', '' ),
            'currency'          => get_option( $this->prefix . 'currency', 'USD' ),
            'price'             => get_option( $this->prefix . 'price', 0 ),
            'renewal_url'       => get_option( $this->prefix . 'renewal_url', '' ),
            'managed_products'  => get_option( $this->prefix . 'managed_products', array() ),
            'time_remaining'    => $this->get_time_remaining(),
            'is_valid'          => $this->is_license_valid(),
            'is_grace_period'   => $this->is_in_grace_period(),
            'is_expired'        => $this->is_expired(),
        );
    }

    /**
     * Deactivate the license.
     *
     * Notifies the server of deactivation and clears all local license data.
     *
     * @return true|WP_Error True on success or WP_Error on failure.
     */
    public function deactivate_license() {
        $license_key = get_option( $this->prefix . 'license_key', '' );
        $server_url  = get_option( $this->prefix . 'server_url', '' );

        if ( ! empty( $license_key ) && ! empty( $server_url ) ) {
            // Notify server of deactivation. Non-blocking; we clear local data regardless.
            $this->api_request( 'license/deactivate', 'POST', array(
                'license_key' => $license_key,
                'domain'      => $this->get_current_domain(),
            ) );
        }

        // Clear all local license data.
        $options = array(
            'license_key',
            'server_url',
            'api_token',
            'domain',
            'status',
            'expires_at',
            'subscription_type',
            'enforcement_mode',
            'grace_period_days',
            'last_validated',
            'product_slug',
            'product_name',
            'client_email',
            'currency',
            'price',
            'renewal_url',
            'managed_products',
        );

        foreach ( $options as $option ) {
            delete_option( $this->prefix . $option );
        }

        // Clear transients.
        delete_transient( $this->cache_key );
        delete_transient( 'dlmues_update_check_cache' );

        // Clear scheduled events.
        wp_clear_scheduled_hook( 'dlmues_license_check' );

        return true;
    }

    /**
     * Quick boolean check if the license is currently valid.
     *
     * A license is valid if its status is 'active' and it has not expired.
     *
     * @return bool True if the license is valid.
     */
    public function is_license_valid() {
        $status     = get_option( $this->prefix . 'status', 'inactive' );
        $expires_at = get_option( $this->prefix . 'expires_at', '' );

        if ( 'active' !== $status ) {
            return false;
        }

        if ( empty( $expires_at ) ) {
            // Lifetime license or no expiry set.
            return true;
        }

        $expiry_time = strtotime( $expires_at );

        if ( false === $expiry_time ) {
            return false;
        }

        return time() < $expiry_time;
    }

    /**
     * Check if the license is in grace period.
     *
     * Grace period is the time between expiry and enforcement.
     *
     * @return bool True if in grace period.
     */
    public function is_in_grace_period() {
        $status     = get_option( $this->prefix . 'status', 'inactive' );
        $expires_at = get_option( $this->prefix . 'expires_at', '' );

        // Server may explicitly set grace_period status.
        if ( 'grace_period' === $status ) {
            return true;
        }

        if ( empty( $expires_at ) || 'active' !== $status ) {
            return false;
        }

        $expiry_time      = strtotime( $expires_at );
        $grace_days       = absint( get_option( $this->prefix . 'grace_period_days', 7 ) );
        $grace_end_time   = $expiry_time + ( $grace_days * DAY_IN_SECONDS );
        $current_time     = time();

        return ( $current_time >= $expiry_time && $current_time < $grace_end_time );
    }

    /**
     * Check if the license is fully expired (past grace period).
     *
     * @return bool True if expired past grace period.
     */
    public function is_expired() {
        $status     = get_option( $this->prefix . 'status', 'inactive' );
        $expires_at = get_option( $this->prefix . 'expires_at', '' );

        // Server may explicitly set expired status.
        if ( 'expired' === $status ) {
            return true;
        }

        if ( 'suspended' === $status ) {
            return true;
        }

        if ( empty( $expires_at ) ) {
            return false;
        }

        $expiry_time    = strtotime( $expires_at );

        if ( false === $expiry_time ) {
            return false;
        }

        $grace_days     = absint( get_option( $this->prefix . 'grace_period_days', 7 ) );
        $grace_end_time = $expiry_time + ( $grace_days * DAY_IN_SECONDS );

        return time() >= $grace_end_time;
    }

    /**
     * Build the renewal/payment URL.
     *
     * Uses the server-provided renewal URL if available, otherwise
     * constructs one from server URL.
     *
     * @return string The renewal payment URL.
     */
    public function get_renewal_url() {
        $renewal_url = get_option( $this->prefix . 'renewal_url', '' );

        if ( ! empty( $renewal_url ) ) {
            return $renewal_url;
        }

        $server_url  = get_option( $this->prefix . 'server_url', '' );
        $license_key = get_option( $this->prefix . 'license_key', '' );

        if ( empty( $server_url ) || empty( $license_key ) ) {
            return '';
        }

        return add_query_arg(
            array(
                'action'      => 'renew',
                'license_key' => rawurlencode( $license_key ),
                'domain'      => rawurlencode( $this->get_current_domain() ),
                'return_url'  => rawurlencode( admin_url( 'options-general.php?page=dlmues-license&payment=complete' ) ),
            ),
            trailingslashit( $server_url ) . 'wp-json/dlmues/v1/payment/initialize'
        );
    }

    /**
     * Calculate the remaining license time.
     *
     * @return array Associative array with days, hours, minutes, total_seconds, and human_readable keys.
     */
    public function get_time_remaining() {
        $expires_at = get_option( $this->prefix . 'expires_at', '' );

        if ( empty( $expires_at ) ) {
            return array(
                'days'           => 0,
                'hours'          => 0,
                'minutes'        => 0,
                'total_seconds'  => 0,
                'human_readable' => __( 'No expiry set', 'dlmues-client' ),
                'is_lifetime'    => true,
            );
        }

        $expiry_time = strtotime( $expires_at );

        if ( false === $expiry_time ) {
            return array(
                'days'           => 0,
                'hours'          => 0,
                'minutes'        => 0,
                'total_seconds'  => 0,
                'human_readable' => __( 'Invalid expiry date', 'dlmues-client' ),
                'is_lifetime'    => false,
            );
        }

        $remaining = $expiry_time - time();

        if ( $remaining <= 0 ) {
            $grace_days     = absint( get_option( $this->prefix . 'grace_period_days', 7 ) );
            $grace_end_time = $expiry_time + ( $grace_days * DAY_IN_SECONDS );
            $grace_remaining = $grace_end_time - time();

            if ( $grace_remaining > 0 ) {
                $days    = floor( $grace_remaining / DAY_IN_SECONDS );
                $hours   = floor( ( $grace_remaining % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
                $minutes = floor( ( $grace_remaining % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

                return array(
                    'days'           => $days,
                    'hours'          => $hours,
                    'minutes'        => $minutes,
                    'total_seconds'  => $grace_remaining,
                    /* translators: 1: days count, 2: hours count */
                    'human_readable' => sprintf( __( '%1$d days, %2$d hours (grace period)', 'dlmues-client' ), $days, $hours ),
                    'is_lifetime'    => false,
                );
            }

            return array(
                'days'           => 0,
                'hours'          => 0,
                'minutes'        => 0,
                'total_seconds'  => 0,
                'human_readable' => __( 'Expired', 'dlmues-client' ),
                'is_lifetime'    => false,
            );
        }

        $days    = floor( $remaining / DAY_IN_SECONDS );
        $hours   = floor( ( $remaining % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
        $minutes = floor( ( $remaining % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

        return array(
            'days'           => $days,
            'hours'          => $hours,
            'minutes'        => $minutes,
            'total_seconds'  => $remaining,
            /* translators: 1: days count, 2: hours count */
            'human_readable' => sprintf( __( '%1$d days, %2$d hours', 'dlmues-client' ), $days, $hours ),
            'is_lifetime'    => false,
        );
    }

    /**
     * Schedule WP Cron for periodic license validation.
     *
     * Registers a twice-daily cron event.
     */
    public function schedule_checks() {
        if ( ! wp_next_scheduled( 'dlmues_license_check' ) ) {
            wp_schedule_event( time(), 'twicedaily', 'dlmues_license_check' );
        }
    }

    /**
     * Cron callback for license validation.
     *
     * Called by WP Cron twice daily to validate the license.
     */
    public function cron_check_license() {
        $license_key = get_option( $this->prefix . 'license_key', '' );

        if ( empty( $license_key ) ) {
            return;
        }

        // Clear the cache so validation hits the server.
        delete_transient( $this->cache_key );
        $this->validate_license();
    }

    /**
     * Make an authenticated API request to the license server.
     *
     * @param string      $endpoint The API endpoint (relative path).
     * @param string      $method   HTTP method (GET or POST).
     * @param array       $data     Data to send with the request.
     * @param string|null $base_url Optional base URL override (used during activation).
     * @return array|WP_Error Response data array or WP_Error on failure.
     */
    public function api_request( $endpoint, $method = 'POST', $data = array(), $base_url = null ) {
        if ( null === $base_url ) {
            $base_url = get_option( $this->prefix . 'server_url', '' );
        }

        if ( empty( $base_url ) ) {
            return new WP_Error( 'no_server', __( 'License server URL is not configured.', 'dlmues-client' ) );
        }

        $url = trailingslashit( $base_url ) . 'wp-json/dlmues/v1/' . ltrim( $endpoint, '/' );

        // Always include domain in requests.
        if ( ! isset( $data['domain'] ) ) {
            $data['domain'] = $this->get_current_domain();
        }

        $api_token = get_option( $this->prefix . 'api_token', '' );

        $headers = array(
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
            'X-DLMUES-Token' => $api_token,
            'X-Site-URL'     => home_url(),
        );

        $args = array(
            'timeout'   => $this->api_timeout,
            'headers'   => $headers,
            'sslverify' => true,
        );

        if ( 'POST' === strtoupper( $method ) ) {
            $args['body']   = wp_json_encode( $data );
            $args['method'] = 'POST';
            $response = wp_remote_post( $url, $args );
        } else {
            $url = add_query_arg( $data, $url );
            $args['method'] = 'GET';
            $response = wp_remote_get( $url, $args );
        }

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'connection_error',
                sprintf(
                    /* translators: %s: error message */
                    __( 'Could not connect to license server: %s', 'dlmues-client' ),
                    $response->get_error_message()
                )
            );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $decoded       = json_decode( $response_body, true );

        if ( $response_code < 200 || $response_code >= 300 ) {
            $error_message = __( 'License server returned an error.', 'dlmues-client' );

            if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
                $error_message = sanitize_text_field( $decoded['message'] );
            } elseif ( is_array( $decoded ) && isset( $decoded['data']['message'] ) ) {
                $error_message = sanitize_text_field( $decoded['data']['message'] );
            }

            return new WP_Error(
                'server_error',
                $error_message,
                array( 'status' => $response_code )
            );
        }

        if ( null === $decoded ) {
            return new WP_Error( 'invalid_response', __( 'Invalid response from license server.', 'dlmues-client' ) );
        }

        // Handle REST API response envelope.
        if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
            return $decoded['data'];
        }

        return $decoded;
    }

    /**
     * Get the normalized current domain.
     *
     * @return string The sanitized current domain.
     */
    public function get_current_domain() {
        $home_url = home_url();
        $domain   = wp_parse_url( $home_url, PHP_URL_HOST );

        if ( empty( $domain ) ) {
            $domain = $home_url;
        }

        // Normalize: lowercase, strip www.
        $domain = strtolower( $domain );
        $domain = preg_replace( '/^www\./', '', $domain );

        return sanitize_text_field( $domain );
    }

    /**
     * Get the option prefix.
     *
     * @return string The option prefix.
     */
    public function get_prefix() {
        return $this->prefix;
    }
}
