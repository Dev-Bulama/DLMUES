<?php
/**
 * Payment History handler for DLMUES Client.
 *
 * Creates and manages the wp_dlmues_payment_history table and provides
 * a REST endpoint that the server plugin calls to populate dummy history.
 *
 * @package DLMUES_Client
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Payment_History
 */
class DLMUES_Payment_History {

    /**
     * Table suffix (without WP prefix).
     *
     * @var string
     */
    const TABLE_SUFFIX = 'dlmues_payment_history';

    /**
     * REST API namespace.
     *
     * @var string
     */
    const REST_NAMESPACE = 'dlmues-client/v1';

    /**
     * Option prefix.
     *
     * @var string
     */
    private $prefix;

    /**
     * Constructor — register REST routes.
     */
    public function __construct() {
        $this->prefix = defined( 'DLMUES_CLIENT_OPTION_PREFIX' ) ? DLMUES_CLIENT_OPTION_PREFIX : 'dlmues_client_';
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Create the payment history table (called on plugin activation).
     */
    public static function create_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT 0,
            invoice_month varchar(7) NOT NULL,
            actual_rate decimal(10,2) DEFAULT 0.00,
            partner_discount decimal(10,2) DEFAULT 0.00,
            final_amount decimal(10,2) DEFAULT 0.00,
            billing_type varchar(20) DEFAULT 'monthly',
            status varchar(20) DEFAULT 'paid',
            created_at datetime DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY invoice_month (invoice_month)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Register client-side REST API routes.
     */
    public function register_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/payment-history/populate',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_populate_payment_history' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Validate an incoming server-push request using HMAC-SHA256.
     *
     * The server signs requests with: hash_hmac('sha256', license_key.'|'.timestamp, server_push_key)
     * The client verifies using its stored copy of server_push_key.
     *
     * @param WP_REST_Request $request Incoming REST request.
     * @return bool True if the request is authentic.
     */
    private function validate_server_request( $request ) {
        $stored_license_key = get_option( $this->prefix . 'license_key', '' );
        $server_push_key    = get_option( $this->prefix . 'server_push_key', '' );

        if ( empty( $stored_license_key ) || empty( $server_push_key ) ) {
            return false;
        }

        $timestamp = $request->get_header( 'X-DLMUES-Timestamp' );
        $signature = $request->get_header( 'X-DLMUES-Signature' );

        if ( empty( $timestamp ) || empty( $signature ) ) {
            return false;
        }

        // Replay-attack window: reject requests older than 5 minutes.
        if ( abs( time() - (int) $timestamp ) > 300 ) {
            return false;
        }

        $expected = hash_hmac( 'sha256', $stored_license_key . '|' . $timestamp, $server_push_key );

        return hash_equals( $expected, $signature );
    }

    /**
     * REST callback: populate 12 months of dummy payment history.
     *
     * Returns success=true on insertion, success=false if history already exists.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response
     */
    public function rest_populate_payment_history( $request ) {
        if ( ! $this->validate_server_request( $request ) ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Unauthorized request.',
                ),
                401
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // Ensure table exists (safeguard if activation hook was missed).
        self::create_table();

        // Do not insert if records already exist.
        $existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( $existing > 0 ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Payment history already exists for this client.',
                ),
                200
            );
        }

        // Resolve a client administrator user ID.
        $admin_users = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
        $user_id     = ! empty( $admin_users ) ? (int) $admin_users[0]->ID : 0;

        // Insert 12 monthly entries, from 11 months ago to this month.
        $errors = 0;
        for ( $i = 11; $i >= 0; $i-- ) {
            $month  = gmdate( 'Y-m', strtotime( "-{$i} months" ) );
            $result = $wpdb->insert(
                $table,
                array(
                    'user_id'          => $user_id,
                    'invoice_month'    => $month,
                    'actual_rate'      => 250.00,
                    'partner_discount' => 70.00,
                    'final_amount'     => 180.00,
                    'billing_type'     => 'monthly',
                    'status'           => 'paid',
                    'created_at'       => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s' )
            );

            if ( false === $result ) {
                $errors++;
            }
        }

        if ( $errors > 0 ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => "Payment history partially inserted ({$errors} errors). Please check database logs.",
                ),
                200
            );
        }

        return new WP_REST_Response(
            array(
                'success' => true,
                'message' => 'Payment history populated successfully (12 months).',
            ),
            200
        );
    }

    /**
     * Retrieve all payment history rows for display in the admin dashboard.
     *
     * @return array Array of payment history records, newest first.
     */
    public function get_history_for_display() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // Return empty array if table does not exist yet.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return array();
        }

        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY invoice_month DESC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
    }
}
