<?php
/**
 * Payment Handler for DLMUES Client.
 *
 * Handles payment initiation and verification through the license server,
 * which proxies to Paystack.
 *
 * @package DLMUES_Client
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Payment_Handler
 */
class DLMUES_Payment_Handler {

    /**
     * License client instance.
     *
     * @var DLMUES_License_Client
     */
    private $license_client;

    /**
     * Constructor.
     *
     * @param DLMUES_License_Client $license_client The license client instance.
     */
    public function __construct( DLMUES_License_Client $license_client ) {
        $this->license_client = $license_client;
    }

    /**
     * Initiate a payment through the license server.
     *
     * @param string $plan        The plan to purchase (monthly, bimonthly, quarterly, yearly).
     * @param string $coupon_code Optional coupon code to apply.
     * @return array|WP_Error Payment initialization data or WP_Error.
     */
    public function initiate_payment( $plan, $coupon_code = '' ) {
        $license_key = get_option( $this->license_client->get_prefix() . 'license_key', '' );

        if ( empty( $license_key ) ) {
            return new WP_Error( 'no_license', __( 'No license key configured.', 'dlmues-client' ) );
        }

        $valid_plans = array( 'monthly', 'bimonthly', 'quarterly', 'yearly' );
        if ( ! in_array( $plan, $valid_plans, true ) ) {
            return new WP_Error( 'invalid_plan', __( 'Invalid subscription plan.', 'dlmues-client' ) );
        }

        $return_url = admin_url( 'options-general.php?page=dlmues-license&payment=complete' );

        $payload = array(
            'license_key' => $license_key,
            'plan'        => $plan,
            'return_url'  => $return_url,
        );

        if ( ! empty( $coupon_code ) ) {
            $payload['coupon_code'] = sanitize_text_field( $coupon_code );
        }

        $response = $this->license_client->api_request( 'payment/initialize', 'POST', $payload );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['authorization_url'] ) && empty( $response['access_code'] ) ) {
            return new WP_Error( 'no_auth_url', __( 'Failed to get payment URL from server.', 'dlmues-client' ) );
        }

        return array(
            'authorization_url' => isset( $response['authorization_url'] ) ? esc_url_raw( $response['authorization_url'] ) : '',
            'reference'         => sanitize_text_field( $response['reference'] ),
            'access_code'       => isset( $response['access_code'] ) ? sanitize_text_field( $response['access_code'] ) : '',
        );
    }

    /**
     * Apply a coupon code via the license server.
     *
     * @param string $coupon_code The coupon code to apply.
     * @param string $plan        The plan to apply the coupon to.
     * @return array|WP_Error Discount data or WP_Error.
     */
    public function apply_coupon( $coupon_code, $plan = '' ) {
        $license_key = get_option( $this->license_client->get_prefix() . 'license_key', '' );

        if ( empty( $license_key ) ) {
            return new WP_Error( 'no_license', __( 'No license key configured.', 'dlmues-client' ) );
        }

        $response = $this->license_client->api_request( 'coupon/apply', 'POST', array(
            'license_key' => $license_key,
            'coupon_code' => sanitize_text_field( $coupon_code ),
            'plan'        => sanitize_text_field( $plan ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Verify a payment through the license server.
     *
     * @param string $reference The payment reference to verify.
     * @return array|WP_Error Verification result or WP_Error.
     */
    public function verify_payment( $reference ) {
        if ( empty( $reference ) ) {
            return new WP_Error( 'missing_reference', __( 'Payment reference is required.', 'dlmues-client' ) );
        }

        $response = $this->license_client->api_request( 'payment/verify', 'POST', array(
            'reference' => sanitize_text_field( $reference ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['verified'] ) ) {
            return new WP_Error( 'not_verified', __( 'Payment could not be verified.', 'dlmues-client' ) );
        }

        return $response;
    }

    /**
     * Get available plans from the license server.
     *
     * @return array|WP_Error Plans data or WP_Error.
     */
    public function get_available_plans() {
        $license_key = get_option( $this->license_client->get_prefix() . 'license_key', '' );

        $response = $this->license_client->api_request( 'payment/plans', 'POST', array(
            'license_key' => $license_key,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( isset( $response['plans'] ) ) {
            return $response['plans'];
        }

        // Fallback default plans.
        return array(
            'monthly'   => array(
                'label'    => __( 'Monthly', 'dlmues-client' ),
                'price'    => 9.99,
                'currency' => 'USD',
                'interval' => '1 month',
            ),
            'bimonthly' => array(
                'label'    => __( 'Bi-Monthly', 'dlmues-client' ),
                'price'    => 17.99,
                'currency' => 'USD',
                'interval' => '2 months',
            ),
            'quarterly' => array(
                'label'    => __( 'Quarterly', 'dlmues-client' ),
                'price'    => 24.99,
                'currency' => 'USD',
                'interval' => '3 months',
            ),
            'yearly'    => array(
                'label'    => __( 'Yearly', 'dlmues-client' ),
                'price'    => 89.99,
                'currency' => 'USD',
                'interval' => '1 year',
            ),
        );
    }
}
