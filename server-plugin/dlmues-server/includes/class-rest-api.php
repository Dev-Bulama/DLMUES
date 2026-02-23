<?php
/**
 * REST API handler for DLMUES License Server.
 *
 * Registers and handles all REST API endpoints for license operations,
 * update checks, payment processing, and site health reporting.
 *
 * @package DLMUES_Server
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_REST_API
 */
class DLMUES_REST_API {

    /**
     * REST namespace.
     *
     * @var string
     */
    private $namespace = 'dlmues/v1';

    /**
     * Register all REST API routes.
     */
    public function register_routes() {
        // License endpoints.
        register_rest_route( $this->namespace, '/license/activate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'activate_license' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( $this->namespace, '/license/validate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'validate_license' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( $this->namespace, '/license/deactivate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'deactivate_license' ),
            'permission_callback' => '__return_true',
        ) );

        // Update endpoints.
        register_rest_route( $this->namespace, '/update/check', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'check_update' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( $this->namespace, '/update/info', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'get_update_info' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( $this->namespace, '/update/download', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'download_update' ),
            'permission_callback' => '__return_true',
        ) );

        // Payment endpoints.
        register_rest_route( $this->namespace, '/payment/initialize', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'initialize_payment' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( $this->namespace, '/payment/verify', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'verify_payment' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( $this->namespace, '/payment/plans', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'get_plans' ),
            'permission_callback' => '__return_true',
        ) );

        // Webhook endpoint.
        register_rest_route( $this->namespace, '/webhook/paystack', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_paystack_webhook' ),
            'permission_callback' => '__return_true',
        ) );

        // Site health endpoint.
        register_rest_route( $this->namespace, '/health/report', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'receive_health_report' ),
            'permission_callback' => '__return_true',
        ) );

        // Trial endpoint.
        register_rest_route( $this->namespace, '/license/trial', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_trial' ),
            'permission_callback' => '__return_true',
        ) );

        // Coupon endpoint.
        register_rest_route( $this->namespace, '/coupon/apply', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'apply_coupon' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Activate a license.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function activate_license( $request ) {
        $security = new DLMUES_Security();
        $ip       = $security->get_client_ip();

        if ( ! $security->rate_limit( $ip, 'license/activate' ) ) {
            return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'dlmues-server' ), array( 'status' => 429 ) );
        }

        $params      = $request->get_json_params();
        $license_key = isset( $params['license_key'] ) ? sanitize_text_field( $params['license_key'] ) : '';
        $domain      = isset( $params['domain'] ) ? sanitize_text_field( $params['domain'] ) : '';

        if ( empty( $license_key ) || empty( $domain ) ) {
            return new WP_Error( 'missing_params', __( 'License key and domain are required.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        $license_engine = new DLMUES_License_Engine();
        $result = $license_engine->activate_license( $license_key, $domain );

        if ( is_wp_error( $result ) ) {
            $security->log_failed_auth( $ip, 'license/activate', $result->get_error_message() );
            $status = $result->get_error_data() && isset( $result->get_error_data()['status'] ) ? $result->get_error_data()['status'] : 400;
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
        }

        // Get full license data for the response.
        $license = $license_engine->get_license( $license_key );

        // Generate a new API token for this activation.
        $api_token = $security->generate_api_token();
        $security->store_api_token( $api_token, $license['id'] );

        $product_name = '';
        if ( ! empty( $license['product_slug'] ) ) {
            global $wpdb;
            $product_name = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT product_name FROM {$wpdb->prefix}dlmues_products WHERE product_slug = %s",
                    $license['product_slug']
                )
            );
        }

        return new WP_REST_Response( array(
            'data' => array(
                'status'            => $result['status'],
                'expires_at'        => $license['expires_at'],
                'subscription_type' => $license['subscription_type'],
                'enforcement_mode'  => $license['enforcement_mode'],
                'grace_period_days' => absint( $license['grace_period_days'] ),
                'product_slug'      => $license['product_slug'],
                'product_name'      => $product_name ? $product_name : $license['product_slug'],
                'client_email'      => $license['client_email'],
                'currency'          => $license['currency'],
                'price'             => floatval( $license['price'] ),
                'api_token'         => $api_token,
            ),
        ), 200 );
    }

    /**
     * Validate a license.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function validate_license( $request ) {
        $security = new DLMUES_Security();
        $ip       = $security->get_client_ip();

        if ( ! $security->rate_limit( $ip, 'license/validate' ) ) {
            return new WP_Error( 'rate_limited', __( 'Too many requests.', 'dlmues-server' ), array( 'status' => 429 ) );
        }

        $params      = $request->get_json_params();
        $license_key = isset( $params['license_key'] ) ? sanitize_text_field( $params['license_key'] ) : '';
        $domain      = isset( $params['domain'] ) ? sanitize_text_field( $params['domain'] ) : '';

        if ( empty( $license_key ) ) {
            return new WP_Error( 'missing_key', __( 'License key is required.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        $license_engine = new DLMUES_License_Engine();
        $result = $license_engine->validate_license( $license_key, $domain );

        if ( is_wp_error( $result ) ) {
            $status = $result->get_error_data() && isset( $result->get_error_data()['status'] ) ? $result->get_error_data()['status'] : 400;
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
        }

        // Get license for additional data.
        $license = $license_engine->get_license( $license_key );

        $response_data = array(
            'status'            => $result['status'],
            'expires_at'        => $license['expires_at'],
            'subscription_type' => $license['subscription_type'],
            'enforcement_mode'  => $license['enforcement_mode'],
            'grace_period_days' => absint( $license['grace_period_days'] ),
            'price'             => floatval( $license['price'] ),
            'currency'          => $license['currency'],
        );

        if ( isset( $result['grace_days_remaining'] ) ) {
            $response_data['grace_days_remaining'] = $result['grace_days_remaining'];
        }

        if ( isset( $result['days_until_expiry'] ) ) {
            $response_data['days_until_expiry'] = $result['days_until_expiry'];
        }

        return new WP_REST_Response( array( 'data' => $response_data ), 200 );
    }

    /**
     * Deactivate a license.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function deactivate_license( $request ) {
        $security = new DLMUES_Security();
        $ip       = $security->get_client_ip();

        if ( ! $security->rate_limit( $ip, 'license/deactivate' ) ) {
            return new WP_Error( 'rate_limited', __( 'Too many requests.', 'dlmues-server' ), array( 'status' => 429 ) );
        }

        $params      = $request->get_json_params();
        $license_key = isset( $params['license_key'] ) ? sanitize_text_field( $params['license_key'] ) : '';

        if ( empty( $license_key ) ) {
            return new WP_Error( 'missing_key', __( 'License key is required.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        // Verify API token.
        $token = $request->get_header( 'X-DLMUES-Token' );
        if ( ! empty( $token ) ) {
            $token_record = $security->validate_api_token( $token );
            if ( ! $token_record ) {
                $security->log_failed_auth( $ip, 'license/deactivate', 'Invalid API token' );
                return new WP_Error( 'invalid_token', __( 'Invalid API token.', 'dlmues-server' ), array( 'status' => 401 ) );
            }
        }

        $license_engine = new DLMUES_License_Engine();
        $result = $license_engine->deactivate_license( $license_key );

        if ( is_wp_error( $result ) ) {
            $status = $result->get_error_data() && isset( $result->get_error_data()['status'] ) ? $result->get_error_data()['status'] : 400;
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
        }

        // Revoke tokens for this license.
        $license = $license_engine->get_license( $license_key );
        if ( $license ) {
            $security->revoke_tokens_for_license( $license['id'] );
        }

        return new WP_REST_Response( array( 'data' => $result ), 200 );
    }

    /**
     * Check for available updates.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function check_update( $request ) {
        $params        = $request->get_json_params();
        $product_slug  = isset( $params['product_slug'] ) ? sanitize_text_field( $params['product_slug'] ) : '';
        $current_ver   = isset( $params['current_version'] ) ? sanitize_text_field( $params['current_version'] ) : '0.0.0';
        $license_key   = isset( $params['license_key'] ) ? sanitize_text_field( $params['license_key'] ) : '';

        if ( empty( $product_slug ) ) {
            return new WP_Error( 'missing_slug', __( 'Product slug is required.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        $update_manager = new DLMUES_Update_Manager();
        $result = $update_manager->check_for_update( $product_slug, $current_ver, $license_key );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( false === $result ) {
            return new WP_REST_Response( array( 'data' => array( 'update_available' => false ) ), 200 );
        }

        return new WP_REST_Response( array( 'data' => $result ), 200 );
    }

    /**
     * Get update information for plugin details popup.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_update_info( $request ) {
        $params       = $request->get_json_params();
        $product_slug = isset( $params['product_slug'] ) ? sanitize_text_field( $params['product_slug'] ) : '';

        if ( empty( $product_slug ) ) {
            return new WP_Error( 'missing_slug', __( 'Product slug is required.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        $update_manager = new DLMUES_Update_Manager();
        $info = $update_manager->get_product_info( $product_slug );

        if ( is_wp_error( $info ) ) {
            return $info;
        }

        return new WP_REST_Response( $info, 200 );
    }

    /**
     * Handle update package download.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function download_update( $request ) {
        $product_slug = $request->get_param( 'product_slug' );
        $license_key  = $request->get_param( 'license_key' );
        $token        = $request->get_param( 'token' );

        if ( empty( $product_slug ) || empty( $license_key ) ) {
            return new WP_Error( 'missing_params', __( 'Product slug and license key are required.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        // Validate license.
        $license_engine = new DLMUES_License_Engine();
        $license = $license_engine->get_license( $license_key );

        if ( ! $license ) {
            return new WP_Error( 'invalid_license', __( 'Invalid license key.', 'dlmues-server' ), array( 'status' => 403 ) );
        }

        if ( ! in_array( $license['status'], array( 'active', 'trial' ), true ) ) {
            return new WP_Error( 'license_inactive', __( 'License is not active.', 'dlmues-server' ), array( 'status' => 403 ) );
        }

        $update_manager = new DLMUES_Update_Manager();
        $file_path = $update_manager->get_package_path( $product_slug );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'no_package', __( 'Update package not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        // Serve the file.
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        readfile( $file_path );
        exit;
    }

    /**
     * Initialize a payment.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function initialize_payment( $request ) {
        $params      = $request->get_json_params();
        $license_key = isset( $params['license_key'] ) ? sanitize_text_field( $params['license_key'] ) : '';
        $plan        = isset( $params['plan'] ) ? sanitize_text_field( $params['plan'] ) : '';
        $return_url  = isset( $params['return_url'] ) ? esc_url_raw( $params['return_url'] ) : '';

        if ( empty( $license_key ) ) {
            return new WP_Error( 'missing_key', __( 'License key is required.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        $license_engine = new DLMUES_License_Engine();
        $license = $license_engine->get_license( $license_key );

        if ( ! $license ) {
            return new WP_Error( 'invalid_license', __( 'License key not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        $paystack = new DLMUES_Paystack();

        // Determine amount based on plan.
        $plan_prices = array(
            'monthly'   => floatval( get_option( 'dlmues_pricing_monthly', 9.99 ) ),
            'bimonthly' => floatval( get_option( 'dlmues_pricing_bimonthly', 17.99 ) ),
            'quarterly' => floatval( get_option( 'dlmues_pricing_quarterly', 24.99 ) ),
            'yearly'    => floatval( get_option( 'dlmues_pricing_yearly', 89.99 ) ),
        );

        $amount   = isset( $plan_prices[ $plan ] ) ? $plan_prices[ $plan ] : $plan_prices['monthly'];
        $currency = $license['currency'] ? $license['currency'] : get_option( 'dlmues_currency', 'USD' );

        // Convert price if needed.
        if ( 'USD' !== $currency ) {
            $converted = $paystack->convert_currency( $amount, 'USD', $currency );
            if ( ! is_wp_error( $converted ) ) {
                $amount = $converted;
            }
        }

        $reference    = $paystack->generate_reference();
        $callback_url = ! empty( $return_url ) ? $return_url : home_url();

        $metadata = array(
            'license_id'    => $license['id'],
            'license_key'   => $license_key,
            'plan_duration' => $plan,
            'product_slug'  => $license['product_slug'],
        );

        $result = $paystack->initialize_payment(
            $license['client_email'],
            $amount,
            $currency,
            $reference,
            $callback_url,
            $metadata
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Record pending payment.
        $paystack->record_payment( array(
            'license_id'        => $license['id'],
            'amount'            => $amount,
            'currency'          => $currency,
            'payment_reference' => $reference,
            'status'            => 'pending',
            'plan_duration'     => $plan,
        ) );

        return new WP_REST_Response( array(
            'data' => array(
                'authorization_url' => $result['authorization_url'],
                'reference'         => $result['reference'],
                'access_code'       => $result['access_code'],
            ),
        ), 200 );
    }

    /**
     * Verify a payment.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function verify_payment( $request ) {
        $params    = $request->get_json_params();
        $reference = isset( $params['reference'] ) ? sanitize_text_field( $params['reference'] ) : '';

        if ( empty( $reference ) ) {
            return new WP_Error( 'missing_reference', __( 'Payment reference is required.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        $paystack = new DLMUES_Paystack();
        $result = $paystack->verify_payment( $reference );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( 'success' !== $result['status'] ) {
            return new WP_Error( 'payment_failed', __( 'Payment was not successful.', 'dlmues-server' ), array( 'status' => 402 ) );
        }

        // Find the pending payment record and process it.
        global $wpdb;
        $payment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dlmues_payments WHERE payment_reference = %s",
                $reference
            ),
            ARRAY_A
        );

        if ( $payment && 'success' !== $payment['status'] ) {
            $wpdb->update(
                $wpdb->prefix . 'dlmues_payments',
                array( 'status' => 'success' ),
                array( 'id' => $payment['id'] ),
                array( '%s' ),
                array( '%d' )
            );

            // Renew the license.
            if ( ! empty( $payment['license_id'] ) ) {
                $license_engine = new DLMUES_License_Engine();
                $license = $license_engine->get_license_by_id( $payment['license_id'] );

                if ( $license ) {
                    $duration = ! empty( $payment['plan_duration'] ) ? $payment['plan_duration'] : $license['subscription_type'];
                    $license_engine->renew_license( $license['license_key'], $duration );

                    // Send confirmation and generate invoice.
                    $notification_manager = new DLMUES_Notification_Manager();
                    $notification_manager->send_payment_confirmation(
                        $license['license_key'],
                        array(
                            'amount'    => $result['amount'],
                            'currency'  => $result['currency'],
                            'reference' => $reference,
                            'plan'      => $duration,
                        )
                    );

                    $invoice_manager = new DLMUES_Invoice_Manager();
                    $invoice_manager->generate_invoice( $payment['id'] );
                }
            }
        }

        return new WP_REST_Response( array(
            'data' => array(
                'verified'  => true,
                'reference' => $reference,
                'amount'    => $result['amount'],
                'currency'  => $result['currency'],
            ),
        ), 200 );
    }

    /**
     * Get available plans and pricing.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response
     */
    public function get_plans( $request ) {
        $params      = $request->get_json_params();
        $license_key = isset( $params['license_key'] ) ? sanitize_text_field( $params['license_key'] ) : '';
        $currency    = 'USD';

        if ( ! empty( $license_key ) ) {
            $license_engine = new DLMUES_License_Engine();
            $license = $license_engine->get_license( $license_key );
            if ( $license && ! empty( $license['currency'] ) ) {
                $currency = $license['currency'];
            }
        }

        $plans = array(
            'monthly'   => array(
                'label'    => __( 'Monthly', 'dlmues-server' ),
                'price'    => floatval( get_option( 'dlmues_pricing_monthly', 9.99 ) ),
                'currency' => 'USD',
                'interval' => '1 month',
            ),
            'bimonthly' => array(
                'label'    => __( 'Bi-Monthly', 'dlmues-server' ),
                'price'    => floatval( get_option( 'dlmues_pricing_bimonthly', 17.99 ) ),
                'currency' => 'USD',
                'interval' => '2 months',
            ),
            'quarterly' => array(
                'label'    => __( 'Quarterly', 'dlmues-server' ),
                'price'    => floatval( get_option( 'dlmues_pricing_quarterly', 24.99 ) ),
                'currency' => 'USD',
                'interval' => '3 months',
            ),
            'yearly'    => array(
                'label'    => __( 'Yearly', 'dlmues-server' ),
                'price'    => floatval( get_option( 'dlmues_pricing_yearly', 89.99 ) ),
                'currency' => 'USD',
                'interval' => '1 year',
            ),
        );

        // Convert prices if currency differs.
        if ( 'USD' !== $currency ) {
            $paystack = new DLMUES_Paystack();
            foreach ( $plans as $key => &$plan ) {
                $converted = $paystack->convert_currency( $plan['price'], 'USD', $currency );
                if ( ! is_wp_error( $converted ) ) {
                    $plan['price']    = $converted;
                    $plan['currency'] = $currency;
                }
            }
            unset( $plan );
        }

        return new WP_REST_Response( array( 'data' => array( 'plans' => $plans ) ), 200 );
    }

    /**
     * Handle Paystack webhook.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_paystack_webhook( $request ) {
        $payload = $request->get_body();

        $paystack = new DLMUES_Paystack();
        $result = $paystack->handle_webhook( $payload );

        if ( is_wp_error( $result ) ) {
            $status = $result->get_error_data() && isset( $result->get_error_data()['status'] ) ? $result->get_error_data()['status'] : 400;
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * Receive a site health report from a client.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function receive_health_report( $request ) {
        $params      = $request->get_json_params();
        $license_key = isset( $params['license_key'] ) ? sanitize_text_field( $params['license_key'] ) : '';

        if ( empty( $license_key ) ) {
            return new WP_Error( 'missing_key', __( 'License key is required.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        // Verify API token.
        $security = new DLMUES_Security();
        $token    = $request->get_header( 'X-DLMUES-Token' );

        if ( ! empty( $token ) ) {
            $token_record = $security->validate_api_token( $token );
            if ( ! $token_record ) {
                return new WP_Error( 'invalid_token', __( 'Invalid API token.', 'dlmues-server' ), array( 'status' => 401 ) );
            }
        }

        $license_engine = new DLMUES_License_Engine();
        $license = $license_engine->get_license( $license_key );

        if ( ! $license ) {
            return new WP_Error( 'invalid_license', __( 'License key not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dlmues_site_health';

        $health_data = array(
            'license_id'      => $license['id'],
            'wp_version'      => isset( $params['wp_version'] ) ? sanitize_text_field( $params['wp_version'] ) : '',
            'active_theme'    => isset( $params['active_theme'] ) ? sanitize_text_field( $params['active_theme'] ) : '',
            'plugin_list'     => isset( $params['plugin_list'] ) ? wp_json_encode( $params['plugin_list'] ) : '',
            'php_version'     => isset( $params['php_version'] ) ? sanitize_text_field( $params['php_version'] ) : '',
            'server_software' => isset( $params['server_software'] ) ? sanitize_text_field( $params['server_software'] ) : '',
            'last_reported'   => current_time( 'mysql' ),
            'site_url'        => isset( $params['site_url'] ) ? esc_url_raw( $params['site_url'] ) : '',
        );

        // Update or insert.
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE license_id = %d",
                $license['id']
            )
        );

        if ( $existing ) {
            $wpdb->update( $table, $health_data, array( 'id' => $existing ), null, array( '%d' ) );
        } else {
            $wpdb->insert( $table, $health_data );
        }

        // Update visitor count on the license if provided.
        if ( isset( $params['visitor_count'] ) ) {
            $wpdb->update(
                $wpdb->prefix . 'dlmues_licenses',
                array( 'visitor_count' => absint( $params['visitor_count'] ) ),
                array( 'id' => $license['id'] ),
                array( '%d' ),
                array( '%d' )
            );
        }

        return new WP_REST_Response( array( 'data' => array( 'received' => true ) ), 200 );
    }

    /**
     * Create a trial license.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function create_trial( $request ) {
        $security = new DLMUES_Security();
        $ip       = $security->get_client_ip();

        if ( ! $security->rate_limit( $ip, 'license/trial' ) ) {
            return new WP_Error( 'rate_limited', __( 'Too many requests.', 'dlmues-server' ), array( 'status' => 429 ) );
        }

        $params = $request->get_json_params();

        $license_engine = new DLMUES_License_Engine();
        $result = $license_engine->create_trial( $params );

        if ( is_wp_error( $result ) ) {
            $status = $result->get_error_data() && isset( $result->get_error_data()['status'] ) ? $result->get_error_data()['status'] : 400;
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
        }

        return new WP_REST_Response( array( 'data' => $result ), 201 );
    }

    /**
     * Apply a coupon code.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function apply_coupon( $request ) {
        $params      = $request->get_json_params();
        $license_key = isset( $params['license_key'] ) ? sanitize_text_field( $params['license_key'] ) : '';
        $coupon_code = isset( $params['coupon_code'] ) ? sanitize_text_field( $params['coupon_code'] ) : '';

        if ( empty( $license_key ) || empty( $coupon_code ) ) {
            return new WP_Error( 'missing_params', __( 'License key and coupon code are required.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        $license_engine = new DLMUES_License_Engine();
        $result = $license_engine->apply_coupon( $license_key, $coupon_code );

        if ( is_wp_error( $result ) ) {
            $status = $result->get_error_data() && isset( $result->get_error_data()['status'] ) ? $result->get_error_data()['status'] : 400;
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
        }

        return new WP_REST_Response( array( 'data' => $result ), 200 );
    }
}
