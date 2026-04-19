<?php
/**
 * Auto-Login handler for DLMUES Client.
 *
 * Handles one-click WP-Admin login initiated from the server plugin dashboard.
 *
 * Flow:
 *   1. Admin clicks "Login to WP-Admin" on the server dashboard.
 *   2. Server generates a 60-second, single-use token and stores its SHA-256
 *      hash in the dlmues_api_tokens table with token_type = 'login'.
 *   3. Server opens <client-url>?dlmues_auto_login=<raw_token>&dlmues_key=<license_key>
 *      in a new tab.
 *   4. This class intercepts the request on WordPress 'init', calls the server's
 *      /login/validate-token endpoint to verify and consume the token.
 *   5. On success, logs in the first administrator user and redirects to /wp-admin/.
 *   6. On failure, terminates with an "Unauthorized login attempt" message.
 *
 * Security properties:
 *   - Token expires after 60 seconds (enforced by server).
 *   - Token is single-use — deleted immediately after validation.
 *   - Raw token is never stored on the server; only its SHA-256 hash is kept.
 *   - Origin (client home_url) is sent to the server for logging purposes.
 *   - SSL verification is enabled for server communication.
 *
 * @package DLMUES_Client
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Auto_Login
 */
class DLMUES_Auto_Login {

    /**
     * Option prefix.
     *
     * @var string
     */
    private $prefix;

    /**
     * Constructor — hook into 'init' with priority 1 to run before most plugins.
     */
    public function __construct() {
        $this->prefix = defined( 'DLMUES_CLIENT_OPTION_PREFIX' ) ? DLMUES_CLIENT_OPTION_PREFIX : 'dlmues_client_';
        add_action( 'init', array( $this, 'handle_auto_login' ), 1 );
    }

    /**
     * Detect auto-login parameters and process the token.
     */
    public function handle_auto_login() {
        // Only act when both GET parameters are present.
        if ( empty( $_GET['dlmues_auto_login'] ) || empty( $_GET['dlmues_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $raw_token   = sanitize_text_field( wp_unslash( $_GET['dlmues_auto_login'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $license_key = sanitize_text_field( wp_unslash( $_GET['dlmues_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( empty( $raw_token ) || empty( $license_key ) ) {
            $this->deny();
        }

        // Retrieve the server URL stored during license activation.
        $server_url = get_option( $this->prefix . 'server_url', '' );
        if ( empty( $server_url ) ) {
            $server_url = defined( 'DLMUES_CLIENT_SERVER_URL' ) ? DLMUES_CLIENT_SERVER_URL : '';
        }

        if ( empty( $server_url ) ) {
            $this->deny();
        }

        $validate_url = trailingslashit( $server_url ) . 'wp-json/dlmues/v1/login/validate-token';

        // Call the server to validate and consume the token.
        $response = wp_remote_post(
            $validate_url,
            array(
                'timeout'   => 15,
                'sslverify' => true,
                'headers'   => array( 'Content-Type' => 'application/json' ),
                'body'      => wp_json_encode(
                    array(
                        'token'       => $raw_token,
                        'license_key' => $license_key,
                        'origin'      => home_url(),
                    )
                ),
            )
        );

        // Network / TLS failure.
        if ( is_wp_error( $response ) ) {
            $this->deny();
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== (int) $http_code || empty( $body['data']['valid'] ) ) {
            $this->deny();
        }

        // Find the first administrator on this site (lowest user ID).
        $admins = get_users(
            array(
                'role'    => 'administrator',
                'number'  => 1,
                'orderby' => 'ID',
                'order'   => 'ASC',
            )
        );

        if ( empty( $admins ) ) {
            wp_die(
                esc_html__( 'Auto-login failed: no administrator user found on this site.', 'dlmues-client' ),
                esc_html__( 'Login Error', 'dlmues-client' ),
                array( 'response' => 500, 'back_link' => false )
            );
        }

        $user = $admins[0];

        // Establish the WordPress auth session.
        wp_clear_auth_cookie();
        wp_set_current_user( $user->ID, $user->user_login );
        wp_set_auth_cookie( $user->ID, false, is_ssl() );

        do_action( 'wp_login', $user->user_login, $user );

        // Redirect to WP-Admin.
        wp_safe_redirect( admin_url() );
        exit;
    }

    /**
     * Terminate the request with an unauthorized error.
     *
     * @return never
     */
    private function deny() {
        wp_die(
            esc_html__( 'Unauthorized login attempt.', 'dlmues-client' ),
            esc_html__( 'Access Denied', 'dlmues-client' ),
            array( 'response' => 403, 'back_link' => false )
        );
    }
}
