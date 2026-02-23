<?php
/**
 * Paystack payment integration for DLMUES License Server.
 *
 * Handles payment initialization, verification, webhook processing,
 * reference generation, and basic currency conversion.
 *
 * @package DLMUES_Server
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Paystack
 */
class DLMUES_Paystack {

    /**
     * Paystack API base URL.
     *
     * @var string
     */
    private $api_base = 'https://api.paystack.co';

    /**
     * Supported currencies and their conversion rates to USD.
     *
     * @var array
     */
    private $currency_rates = array(
        'USD' => 1.00,
        'NGN' => 1550.00,
        'GBP' => 0.79,
        'EUR' => 0.92,
        'GHS' => 15.50,
        'ZAR' => 18.50,
        'KES' => 155.00,
    );

    /**
     * Get the Paystack secret key from options.
     *
     * The key is stored encrypted. This method decrypts it for use.
     *
     * @return string The secret key.
     */
    private function get_secret_key() {
        $test_mode = get_option( 'dlmues_paystack_test_mode', 1 );

        if ( $test_mode ) {
            $encrypted = get_option( 'dlmues_paystack_test_secret_key', '' );
        } else {
            $encrypted = get_option( 'dlmues_paystack_secret_key', '' );
        }

        if ( empty( $encrypted ) ) {
            return '';
        }

        // Attempt to decrypt.
        $security  = new DLMUES_Security();
        $decrypted = $security->decrypt_license_key( $encrypted );

        // If decryption fails, return raw value (for backward compatibility / initial setup).
        return ! empty( $decrypted ) ? $decrypted : $encrypted;
    }

    /**
     * Get the Paystack public key.
     *
     * @return string The public key.
     */
    public function get_public_key() {
        $test_mode = get_option( 'dlmues_paystack_test_mode', 1 );

        if ( $test_mode ) {
            return get_option( 'dlmues_paystack_test_public_key', '' );
        }

        return get_option( 'dlmues_paystack_public_key', '' );
    }

    /**
     * Store the Paystack secret key encrypted.
     *
     * @param string $key       The raw secret key.
     * @param bool   $test_mode Whether this is a test key.
     */
    public function save_secret_key( $key, $test_mode = false ) {
        $security  = new DLMUES_Security();
        $encrypted = $security->encrypt_license_key( $key );

        $option_name = $test_mode ? 'dlmues_paystack_test_secret_key' : 'dlmues_paystack_secret_key';
        update_option( $option_name, $encrypted );
    }

    /**
     * Initialize a Paystack transaction.
     *
     * @param string $email        Customer email address.
     * @param float  $amount       Amount to charge (in major currency units).
     * @param string $currency     Currency code (e.g., 'NGN', 'USD', 'GHS').
     * @param string $reference    Unique payment reference.
     * @param string $callback_url URL to redirect after payment.
     * @param array  $metadata     Additional metadata to attach.
     * @return array|WP_Error Transaction initialization data or WP_Error.
     */
    public function initialize_payment( $email, $amount, $currency, $reference, $callback_url, $metadata = array() ) {
        $secret_key = $this->get_secret_key();

        if ( empty( $secret_key ) ) {
            return new WP_Error( 'paystack_not_configured', __( 'Paystack API keys are not configured.', 'dlmues-server' ), array( 'status' => 500 ) );
        }

        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', __( 'A valid email address is required.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        if ( $amount <= 0 ) {
            return new WP_Error( 'invalid_amount', __( 'Amount must be greater than zero.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        // Paystack expects amount in the smallest currency unit (kobo, cents, etc.).
        $amount_in_subunits = intval( round( $amount * 100 ) );

        $body = array(
            'email'        => sanitize_email( $email ),
            'amount'       => $amount_in_subunits,
            'currency'     => strtoupper( sanitize_text_field( $currency ) ),
            'reference'    => sanitize_text_field( $reference ),
            'callback_url' => esc_url_raw( $callback_url ),
            'metadata'     => $metadata,
        );

        $response = wp_remote_post(
            $this->api_base . '/transaction/initialize',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type'  => 'application/json',
                    'Cache-Control' => 'no-cache',
                ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'paystack_request_failed',
                sprintf( __( 'Paystack API request failed: %s', 'dlmues-server' ), $response->get_error_message() ),
                array( 'status' => 502 )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body_raw, true );

        if ( 200 !== $status_code || empty( $data['status'] ) || true !== $data['status'] ) {
            $message = isset( $data['message'] ) ? $data['message'] : 'Unknown error from Paystack.';
            return new WP_Error( 'paystack_init_failed', $message, array( 'status' => $status_code ) );
        }

        return array(
            'authorization_url' => $data['data']['authorization_url'],
            'access_code'       => $data['data']['access_code'],
            'reference'         => $data['data']['reference'],
        );
    }

    /**
     * Verify a Paystack transaction.
     *
     * @param string $reference The payment reference to verify.
     * @return array|WP_Error Verification result or WP_Error.
     */
    public function verify_payment( $reference ) {
        $secret_key = $this->get_secret_key();

        if ( empty( $secret_key ) ) {
            return new WP_Error( 'paystack_not_configured', __( 'Paystack API keys are not configured.', 'dlmues-server' ), array( 'status' => 500 ) );
        }

        if ( empty( $reference ) ) {
            return new WP_Error( 'missing_reference', __( 'Payment reference is required.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        $response = wp_remote_get(
            $this->api_base . '/transaction/verify/' . urlencode( sanitize_text_field( $reference ) ),
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Cache-Control' => 'no-cache',
                ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'paystack_request_failed',
                sprintf( __( 'Paystack verification failed: %s', 'dlmues-server' ), $response->get_error_message() ),
                array( 'status' => 502 )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body_raw, true );

        if ( 200 !== $status_code || empty( $data['status'] ) || true !== $data['status'] ) {
            $message = isset( $data['message'] ) ? $data['message'] : 'Verification failed.';
            return new WP_Error( 'paystack_verify_failed', $message, array( 'status' => $status_code ) );
        }

        $transaction = $data['data'];

        return array(
            'status'    => $transaction['status'],
            'reference' => $transaction['reference'],
            'amount'    => $transaction['amount'] / 100, // Convert from subunits.
            'currency'  => $transaction['currency'],
            'email'     => $transaction['customer']['email'],
            'paid_at'   => $transaction['paid_at'],
            'channel'   => $transaction['channel'],
            'metadata'  => isset( $transaction['metadata'] ) ? $transaction['metadata'] : array(),
            'raw'       => $transaction,
        );
    }

    /**
     * Handle a Paystack webhook event.
     *
     * Verifies the webhook signature and processes the event.
     *
     * @param string $payload The raw webhook payload (JSON string).
     * @return array|WP_Error Processing result or WP_Error.
     */
    public function handle_webhook( $payload ) {
        $secret_key = $this->get_secret_key();

        if ( empty( $secret_key ) ) {
            return new WP_Error( 'paystack_not_configured', __( 'Paystack API keys are not configured.', 'dlmues-server' ), array( 'status' => 500 ) );
        }

        // Verify webhook signature.
        $signature = isset( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ) )
            : '';

        if ( empty( $signature ) ) {
            return new WP_Error( 'missing_signature', __( 'Webhook signature is missing.', 'dlmues-server' ), array( 'status' => 401 ) );
        }

        $expected_signature = hash_hmac( 'sha512', $payload, $secret_key );

        if ( ! hash_equals( $expected_signature, $signature ) ) {
            return new WP_Error( 'invalid_signature', __( 'Webhook signature verification failed.', 'dlmues-server' ), array( 'status' => 401 ) );
        }

        $event = json_decode( $payload, true );

        if ( ! $event || ! isset( $event['event'] ) ) {
            return new WP_Error( 'invalid_payload', __( 'Invalid webhook payload.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        $event_type = sanitize_text_field( $event['event'] );

        switch ( $event_type ) {
            case 'charge.success':
                return $this->process_charge_success( $event['data'] );

            case 'subscription.create':
                return $this->process_subscription_create( $event['data'] );

            case 'subscription.disable':
                return $this->process_subscription_disable( $event['data'] );

            case 'invoice.payment_failed':
                return $this->process_payment_failed( $event['data'] );

            default:
                return array(
                    'processed' => false,
                    'event'     => $event_type,
                    'message'   => 'Event type not handled.',
                );
        }
    }

    /**
     * Process a successful charge event.
     *
     * @param array $data The event data.
     * @return array Processing result.
     */
    private function process_charge_success( $data ) {
        global $wpdb;

        $reference = sanitize_text_field( $data['reference'] );
        $amount    = floatval( $data['amount'] ) / 100;
        $currency  = sanitize_text_field( $data['currency'] );
        $email     = sanitize_email( $data['customer']['email'] );
        $metadata  = isset( $data['metadata'] ) ? $data['metadata'] : array();

        $payments_table = $wpdb->prefix . 'dlmues_payments';

        // Check if we already processed this reference.
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$payments_table} WHERE payment_reference = %s AND status = 'success'",
                $reference
            )
        );

        if ( $existing ) {
            return array(
                'processed' => true,
                'message'   => 'Payment already processed.',
                'payment_id' => $existing,
            );
        }

        // Find the pending payment by reference.
        $payment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$payments_table} WHERE payment_reference = %s",
                $reference
            ),
            ARRAY_A
        );

        if ( ! $payment ) {
            // Create a new payment record if one does not exist.
            $license_id   = 0;
            $plan_duration = '';

            if ( isset( $metadata['license_id'] ) ) {
                $license_id = absint( $metadata['license_id'] );
            }
            if ( isset( $metadata['plan_duration'] ) ) {
                $plan_duration = sanitize_text_field( $metadata['plan_duration'] );
            }

            $wpdb->insert(
                $payments_table,
                array(
                    'license_id'        => $license_id,
                    'amount'            => $amount,
                    'currency'          => $currency,
                    'payment_reference' => $reference,
                    'payment_provider'  => 'paystack',
                    'status'            => 'success',
                    'plan_duration'     => $plan_duration,
                    'created_at'        => current_time( 'mysql' ),
                ),
                array( '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s' )
            );

            $payment_id = $wpdb->insert_id;
        } else {
            // Update existing payment.
            $wpdb->update(
                $payments_table,
                array( 'status' => 'success' ),
                array( 'id' => $payment['id'] ),
                array( '%s' ),
                array( '%d' )
            );

            $payment_id    = $payment['id'];
            $license_id    = $payment['license_id'];
            $plan_duration = $payment['plan_duration'];
        }

        // Renew the associated license.
        if ( ! empty( $license_id ) ) {
            $license_engine = new DLMUES_License_Engine();
            $license        = $license_engine->get_license_by_id( $license_id );

            if ( $license ) {
                $duration = ! empty( $plan_duration ) ? $plan_duration : $license['subscription_type'];
                $license_engine->renew_license( $license['license_key'], $duration );

                // Send payment confirmation.
                $notification_manager = new DLMUES_Notification_Manager();
                $notification_manager->send_payment_confirmation(
                    $license['license_key'],
                    array(
                        'amount'    => $amount,
                        'currency'  => $currency,
                        'reference' => $reference,
                        'plan'      => $duration,
                    )
                );

                // Generate invoice.
                $invoice_manager = new DLMUES_Invoice_Manager();
                $invoice_manager->generate_invoice( $payment_id );
            }
        }

        return array(
            'processed'  => true,
            'payment_id' => $payment_id,
            'reference'  => $reference,
            'amount'     => $amount,
            'message'    => 'Payment processed successfully.',
        );
    }

    /**
     * Process subscription creation event.
     *
     * @param array $data The event data.
     * @return array Processing result.
     */
    private function process_subscription_create( $data ) {
        return array(
            'processed' => true,
            'event'     => 'subscription.create',
            'message'   => 'Subscription created.',
        );
    }

    /**
     * Process subscription disable event.
     *
     * @param array $data The event data.
     * @return array Processing result.
     */
    private function process_subscription_disable( $data ) {
        $metadata = isset( $data['metadata'] ) ? $data['metadata'] : array();

        if ( isset( $metadata['license_key'] ) ) {
            $license_engine = new DLMUES_License_Engine();
            $license_engine->suspend_license( sanitize_text_field( $metadata['license_key'] ) );
        }

        return array(
            'processed' => true,
            'event'     => 'subscription.disable',
            'message'   => 'Subscription disabled, license suspended.',
        );
    }

    /**
     * Process failed payment event.
     *
     * @param array $data The event data.
     * @return array Processing result.
     */
    private function process_payment_failed( $data ) {
        $metadata = isset( $data['metadata'] ) ? $data['metadata'] : array();

        if ( isset( $metadata['license_key'] ) ) {
            $notification_manager = new DLMUES_Notification_Manager();
            $notification_manager->send_grace_period_warning( sanitize_text_field( $metadata['license_key'] ) );
        }

        return array(
            'processed' => true,
            'event'     => 'invoice.payment_failed',
            'message'   => 'Payment failure processed.',
        );
    }

    /**
     * Generate a unique payment reference.
     *
     * Format: DLMUES-{timestamp}-{random}
     *
     * @return string The payment reference.
     */
    public function generate_reference() {
        return 'DLMUES-' . time() . '-' . strtoupper( wp_generate_password( 8, false ) );
    }

    /**
     * Get the list of supported currencies.
     *
     * @return array Associative array of currency code => name.
     */
    public function get_supported_currencies() {
        return array(
            'USD' => 'US Dollar',
            'NGN' => 'Nigerian Naira',
            'GBP' => 'British Pound',
            'EUR' => 'Euro',
            'GHS' => 'Ghanaian Cedi',
            'ZAR' => 'South African Rand',
            'KES' => 'Kenyan Shilling',
        );
    }

    /**
     * Convert an amount between supported currencies.
     *
     * Uses static conversion rates. For production, consider integrating
     * a live exchange rate API.
     *
     * @param float  $amount The amount to convert.
     * @param string $from   Source currency code.
     * @param string $to     Target currency code.
     * @return float|WP_Error The converted amount or WP_Error.
     */
    public function convert_currency( $amount, $from, $to ) {
        $from = strtoupper( $from );
        $to   = strtoupper( $to );

        if ( ! isset( $this->currency_rates[ $from ] ) ) {
            return new WP_Error( 'unsupported_currency', sprintf( __( 'Currency %s is not supported.', 'dlmues-server' ), $from ) );
        }

        if ( ! isset( $this->currency_rates[ $to ] ) ) {
            return new WP_Error( 'unsupported_currency', sprintf( __( 'Currency %s is not supported.', 'dlmues-server' ), $to ) );
        }

        if ( $from === $to ) {
            return round( $amount, 2 );
        }

        // Convert to USD first, then to target.
        $amount_in_usd = $amount / $this->currency_rates[ $from ];
        $converted     = $amount_in_usd * $this->currency_rates[ $to ];

        return round( $converted, 2 );
    }

    /**
     * Test the Paystack API connection.
     *
     * @return bool|WP_Error True if connection is successful, WP_Error otherwise.
     */
    public function test_connection() {
        $secret_key = $this->get_secret_key();

        if ( empty( $secret_key ) ) {
            return new WP_Error( 'paystack_not_configured', __( 'Paystack API keys are not configured.', 'dlmues-server' ) );
        }

        $response = wp_remote_get(
            $this->api_base . '/balance',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'connection_failed', $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $status_code ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $msg  = isset( $body['message'] ) ? $body['message'] : 'API returned status ' . $status_code;
            return new WP_Error( 'api_error', $msg );
        }

        return true;
    }

    /**
     * Record a payment in the database.
     *
     * @param array $data {
     *     Payment data.
     *
     *     @type int    $license_id        License ID.
     *     @type float  $amount            Payment amount.
     *     @type string $currency          Currency code.
     *     @type string $payment_reference Payment reference.
     *     @type string $status            Payment status.
     *     @type string $plan_duration     Plan duration.
     * }
     * @return int|WP_Error The payment ID or WP_Error.
     */
    public function record_payment( $data ) {
        global $wpdb;

        $table = $wpdb->prefix . 'dlmues_payments';

        $result = $wpdb->insert(
            $table,
            array(
                'license_id'        => absint( $data['license_id'] ),
                'amount'            => floatval( $data['amount'] ),
                'currency'          => sanitize_text_field( $data['currency'] ),
                'payment_reference' => sanitize_text_field( $data['payment_reference'] ),
                'payment_provider'  => 'paystack',
                'status'            => sanitize_text_field( $data['status'] ),
                'plan_duration'     => sanitize_text_field( $data['plan_duration'] ),
                'created_at'        => current_time( 'mysql' ),
            ),
            array( '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'payment_record_failed', __( 'Failed to record payment.', 'dlmues-server' ) );
        }

        return $wpdb->insert_id;
    }

    /**
     * Get all payments with optional filtering.
     *
     * @param array $args Query arguments.
     * @return array Array with 'items' and 'total'.
     */
    public function get_payments( $args = array() ) {
        global $wpdb;

        $table = $wpdb->prefix . 'dlmues_payments';
        $licenses_table = $wpdb->prefix . 'dlmues_licenses';

        $defaults = array(
            'per_page' => 50,
            'page'     => 1,
            'status'   => '',
            'search'   => '',
        );

        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';
        $values = array();

        if ( ! empty( $args['status'] ) ) {
            $where .= ' AND p.status = %s';
            $values[] = sanitize_text_field( $args['status'] );
        }

        if ( ! empty( $args['search'] ) ) {
            $search = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where .= ' AND (p.payment_reference LIKE %s OR l.client_email LIKE %s OR l.license_key LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }

        $offset   = ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] );
        $per_page = absint( $args['per_page'] );

        $count_query = "SELECT COUNT(*) FROM {$table} p LEFT JOIN {$licenses_table} l ON p.license_id = l.id WHERE {$where}";
        if ( ! empty( $values ) ) {
            $count_query = $wpdb->prepare( $count_query, $values );
        }
        $total = absint( $wpdb->get_var( $count_query ) );

        $query = "SELECT p.*, l.license_key, l.client_email
                  FROM {$table} p
                  LEFT JOIN {$licenses_table} l ON p.license_id = l.id
                  WHERE {$where}
                  ORDER BY p.created_at DESC
                  LIMIT %d OFFSET %d";

        $query_values = array_merge( $values, array( $per_page, $offset ) );

        $items = $wpdb->get_results(
            $wpdb->prepare( $query, $query_values ),
            ARRAY_A
        );

        return array(
            'items' => $items ? $items : array(),
            'total' => $total,
        );
    }
}
