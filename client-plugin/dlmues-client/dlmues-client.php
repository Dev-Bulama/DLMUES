<?php
/**
 * Plugin Name: DLMUES License Client
 * Plugin URI: https://dlmues.com/client
 * Description: Distributed License Management & Update Enforcement System - Client Plugin. Manages license activation, update delivery, and enforcement for licensed WordPress products.
 * Version: 1.0.0
 * Author: DLMUES
 * Author URI: https://dlmues.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dlmues-client
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin constants.
 */
define( 'DLMUES_CLIENT_VERSION', '1.0.0' );
define( 'DLMUES_CLIENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'DLMUES_CLIENT_URL', plugin_dir_url( __FILE__ ) );
define( 'DLMUES_CLIENT_BASENAME', plugin_basename( __FILE__ ) );
define( 'DLMUES_CLIENT_OPTION_PREFIX', 'dlmues_client_' );

/**
 * Include required files.
 */
require_once DLMUES_CLIENT_PATH . 'includes/class-license-client.php';
require_once DLMUES_CLIENT_PATH . 'includes/class-update-interceptor.php';
require_once DLMUES_CLIENT_PATH . 'includes/class-enforcement.php';
require_once DLMUES_CLIENT_PATH . 'includes/class-payment-handler.php';
require_once DLMUES_CLIENT_PATH . 'includes/class-site-health-reporter.php';
require_once DLMUES_CLIENT_PATH . 'includes/class-client-admin.php';

/**
 * Main plugin class.
 */
final class DLMUES_Client_Plugin {

    /**
     * Singleton instance.
     *
     * @var DLMUES_Client_Plugin|null
     */
    private static $instance = null;

    /**
     * License client instance.
     *
     * @var DLMUES_License_Client
     */
    public $license_client;

    /**
     * Update interceptor instance.
     *
     * @var DLMUES_Update_Interceptor
     */
    public $update_interceptor;

    /**
     * Enforcement instance.
     *
     * @var DLMUES_Enforcement
     */
    public $enforcement;

    /**
     * Payment handler instance.
     *
     * @var DLMUES_Payment_Handler
     */
    public $payment_handler;

    /**
     * Site health reporter instance.
     *
     * @var DLMUES_Site_Health_Reporter
     */
    public $health_reporter;

    /**
     * Client admin instance.
     *
     * @var DLMUES_Client_Admin
     */
    public $client_admin;

    /**
     * Get singleton instance.
     *
     * @return DLMUES_Client_Plugin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        $this->init_components();
        $this->register_hooks();
    }

    /**
     * Initialize plugin components.
     */
    private function init_components() {
        $this->license_client    = new DLMUES_License_Client();
        $this->update_interceptor = new DLMUES_Update_Interceptor( $this->license_client );
        $this->enforcement       = new DLMUES_Enforcement( $this->license_client );
        $this->payment_handler   = new DLMUES_Payment_Handler( $this->license_client );
        $this->health_reporter   = new DLMUES_Site_Health_Reporter( $this->license_client );
        $this->client_admin      = new DLMUES_Client_Admin( $this->license_client, $this->payment_handler, $this->health_reporter );
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks() {
        // Activation and deactivation.
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // Initialize license checking on admin_init.
        add_action( 'admin_init', array( $this, 'admin_init_license_check' ) );

        // Enqueue scripts and styles.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        // Register AJAX handlers.
        add_action( 'wp_ajax_dlmues_activate_license', array( $this, 'ajax_activate_license' ) );
        add_action( 'wp_ajax_dlmues_deactivate_license', array( $this, 'ajax_deactivate_license' ) );
        add_action( 'wp_ajax_dlmues_refresh_status', array( $this, 'ajax_refresh_status' ) );
        add_action( 'wp_ajax_dlmues_initiate_payment', array( $this, 'ajax_initiate_payment' ) );
        add_action( 'wp_ajax_dlmues_verify_payment', array( $this, 'ajax_verify_payment' ) );
        add_action( 'wp_ajax_dlmues_get_plans', array( $this, 'ajax_get_plans' ) );

        // Track visitor counts for health reporting.
        add_action( 'template_redirect', array( $this->health_reporter, 'track_visitor' ) );

        // Plugin action links.
        add_filter( 'plugin_action_links_' . DLMUES_CLIENT_BASENAME, array( $this, 'add_action_links' ) );
    }

    /**
     * Plugin activation.
     */
    public function activate() {
        // Set default options.
        add_option( DLMUES_CLIENT_OPTION_PREFIX . 'license_key', '' );
        add_option( DLMUES_CLIENT_OPTION_PREFIX . 'server_url', '' );
        add_option( DLMUES_CLIENT_OPTION_PREFIX . 'api_token', '' );
        add_option( DLMUES_CLIENT_OPTION_PREFIX . 'domain', home_url() );
        add_option( DLMUES_CLIENT_OPTION_PREFIX . 'status', 'inactive' );
        add_option( DLMUES_CLIENT_OPTION_PREFIX . 'expires_at', '' );
        add_option( DLMUES_CLIENT_OPTION_PREFIX . 'subscription_type', '' );
        add_option( DLMUES_CLIENT_OPTION_PREFIX . 'enforcement_mode', 'restrict_admin' );
        add_option( DLMUES_CLIENT_OPTION_PREFIX . 'grace_period_days', 7 );
        add_option( DLMUES_CLIENT_OPTION_PREFIX . 'last_validated', '' );
        add_option( DLMUES_CLIENT_OPTION_PREFIX . 'visitor_counts', array() );

        // Schedule cron events.
        $this->license_client->schedule_checks();
        $this->health_reporter->schedule_reporting();

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate() {
        // Clear scheduled events.
        wp_clear_scheduled_hook( 'dlmues_license_check' );
        wp_clear_scheduled_hook( 'dlmues_health_report' );

        // Clear transients.
        delete_transient( 'dlmues_license_status_cache' );
        delete_transient( 'dlmues_update_check_cache' );
    }

    /**
     * License check on admin_init.
     */
    public function admin_init_license_check() {
        $license_key = get_option( DLMUES_CLIENT_OPTION_PREFIX . 'license_key' );

        if ( empty( $license_key ) ) {
            return;
        }

        // Validate license if not recently validated.
        $last_validated = get_option( DLMUES_CLIENT_OPTION_PREFIX . 'last_validated' );
        $cache_duration = HOUR_IN_SECONDS;

        if ( empty( $last_validated ) || ( time() - strtotime( $last_validated ) ) > $cache_duration ) {
            $this->license_client->validate_license();
        }

        // Run enforcement if needed.
        $this->enforcement->enforce();
    }

    /**
     * Enqueue admin scripts and styles.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueue_admin_assets( $hook_suffix ) {
        // Always enqueue enforcement assets if license is expired.
        if ( $this->license_client->is_expired() || $this->license_client->is_in_grace_period() ) {
            wp_enqueue_style(
                'dlmues-enforcement-style',
                DLMUES_CLIENT_URL . 'assets/css/enforcement-style.css',
                array(),
                DLMUES_CLIENT_VERSION
            );
            wp_enqueue_script(
                'dlmues-enforcement-script',
                DLMUES_CLIENT_URL . 'assets/js/enforcement.js',
                array( 'jquery' ),
                DLMUES_CLIENT_VERSION,
                true
            );
            wp_localize_script( 'dlmues-enforcement-script', 'dlmuesEnforcement', array(
                'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( 'dlmues_enforcement_nonce' ),
                'settingsUrl'     => admin_url( 'options-general.php?page=dlmues-license' ),
                'isExpired'       => $this->license_client->is_expired(),
                'isGracePeriod'   => $this->license_client->is_in_grace_period(),
                'timeRemaining'   => $this->license_client->get_time_remaining(),
                'renewalUrl'      => $this->license_client->get_renewal_url(),
                'enforcementMode' => $this->enforcement->get_enforcement_mode(),
                'licenseData'     => $this->license_client->get_license_data(),
            ) );
        }

        // Only load full admin assets on our settings page.
        if ( 'settings_page_dlmues-license' !== $hook_suffix && 'index.php' !== $hook_suffix ) {
            return;
        }

        wp_enqueue_style(
            'dlmues-client-admin-style',
            DLMUES_CLIENT_URL . 'admin/css/client-admin-style.css',
            array(),
            DLMUES_CLIENT_VERSION
        );

        wp_enqueue_style(
            'dlmues-payment-modal-style',
            DLMUES_CLIENT_URL . 'assets/css/payment-modal.css',
            array(),
            DLMUES_CLIENT_VERSION
        );

        wp_enqueue_script(
            'dlmues-client-admin-script',
            DLMUES_CLIENT_URL . 'admin/js/client-admin-script.js',
            array( 'jquery' ),
            DLMUES_CLIENT_VERSION,
            true
        );

        wp_enqueue_script(
            'dlmues-payment-modal-script',
            DLMUES_CLIENT_URL . 'assets/js/payment-modal.js',
            array( 'jquery' ),
            DLMUES_CLIENT_VERSION,
            true
        );

        wp_localize_script( 'dlmues-client-admin-script', 'dlmuesClient', array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'dlmues_client_nonce' ),
            'settingsUrl'   => admin_url( 'options-general.php?page=dlmues-license' ),
            'licenseData'   => $this->license_client->get_license_data(),
            'isValid'       => $this->license_client->is_license_valid(),
            'isGracePeriod' => $this->license_client->is_in_grace_period(),
            'isExpired'     => $this->license_client->is_expired(),
            'i18n'          => array(
                'activating'    => __( 'Activating license...', 'dlmues-client' ),
                'deactivating'  => __( 'Deactivating license...', 'dlmues-client' ),
                'refreshing'    => __( 'Refreshing status...', 'dlmues-client' ),
                'error'         => __( 'An error occurred. Please try again.', 'dlmues-client' ),
                'confirmDeactivate' => __( 'Are you sure you want to deactivate this license?', 'dlmues-client' ),
            ),
        ) );

        wp_localize_script( 'dlmues-payment-modal-script', 'dlmuesPayment', array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'dlmues_payment_nonce' ),
            'licenseData' => $this->license_client->get_license_data(),
            'returnUrl'   => admin_url( 'options-general.php?page=dlmues-license&payment=complete' ),
        ) );
    }

    /**
     * Enqueue frontend assets for enforcement.
     */
    public function enqueue_frontend_assets() {
        if ( ! $this->license_client->is_expired() ) {
            return;
        }

        $enforcement_mode = $this->enforcement->get_enforcement_mode();

        if ( 'lock_frontend' === $enforcement_mode || 'maintenance' === $enforcement_mode ) {
            wp_enqueue_style(
                'dlmues-enforcement-style',
                DLMUES_CLIENT_URL . 'assets/css/enforcement-style.css',
                array(),
                DLMUES_CLIENT_VERSION
            );
        }
    }

    /**
     * AJAX: Activate license.
     */
    public function ajax_activate_license() {
        check_ajax_referer( 'dlmues_client_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'dlmues-client' ) ) );
        }

        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
        $server_url  = isset( $_POST['server_url'] ) ? esc_url_raw( wp_unslash( $_POST['server_url'] ) ) : '';

        if ( empty( $license_key ) || empty( $server_url ) ) {
            wp_send_json_error( array( 'message' => __( 'License key and server URL are required.', 'dlmues-client' ) ) );
        }

        $result = $this->license_client->activate_license( $license_key, $server_url );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message'     => __( 'License activated successfully.', 'dlmues-client' ),
            'licenseData' => $this->license_client->get_license_data(),
        ) );
    }

    /**
     * AJAX: Deactivate license.
     */
    public function ajax_deactivate_license() {
        check_ajax_referer( 'dlmues_client_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'dlmues-client' ) ) );
        }

        $result = $this->license_client->deactivate_license();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'License deactivated successfully.', 'dlmues-client' ),
        ) );
    }

    /**
     * AJAX: Refresh license status.
     */
    public function ajax_refresh_status() {
        check_ajax_referer( 'dlmues_client_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'dlmues-client' ) ) );
        }

        // Force fresh validation.
        delete_transient( 'dlmues_license_status_cache' );
        $this->license_client->validate_license();

        wp_send_json_success( array(
            'message'     => __( 'License status refreshed.', 'dlmues-client' ),
            'licenseData' => $this->license_client->get_license_data(),
            'isValid'     => $this->license_client->is_license_valid(),
        ) );
    }

    /**
     * AJAX: Initiate payment.
     */
    public function ajax_initiate_payment() {
        check_ajax_referer( 'dlmues_payment_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'dlmues-client' ) ) );
        }

        $plan = isset( $_POST['plan'] ) ? sanitize_text_field( wp_unslash( $_POST['plan'] ) ) : '';

        $result = $this->payment_handler->initiate_payment( $plan );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Verify payment.
     */
    public function ajax_verify_payment() {
        check_ajax_referer( 'dlmues_payment_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'dlmues-client' ) ) );
        }

        $reference = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';

        if ( empty( $reference ) ) {
            wp_send_json_error( array( 'message' => __( 'Payment reference is required.', 'dlmues-client' ) ) );
        }

        $result = $this->payment_handler->verify_payment( $reference );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Refresh license after successful payment.
        delete_transient( 'dlmues_license_status_cache' );
        $this->license_client->validate_license();

        wp_send_json_success( array(
            'message'     => __( 'Payment verified. License renewed successfully.', 'dlmues-client' ),
            'licenseData' => $this->license_client->get_license_data(),
        ) );
    }

    /**
     * AJAX: Get available plans.
     */
    public function ajax_get_plans() {
        check_ajax_referer( 'dlmues_payment_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'dlmues-client' ) ) );
        }

        $plans = $this->payment_handler->get_available_plans();

        if ( is_wp_error( $plans ) ) {
            wp_send_json_error( array( 'message' => $plans->get_error_message() ) );
        }

        wp_send_json_success( array( 'plans' => $plans ) );
    }

    /**
     * Add plugin action links.
     *
     * @param array $links Existing action links.
     * @return array Modified action links.
     */
    public function add_action_links( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'options-general.php?page=dlmues-license' ) ),
            esc_html__( 'Settings', 'dlmues-client' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }
}

/**
 * Initialize the plugin.
 *
 * @return DLMUES_Client_Plugin
 */
function dlmues_client() {
    return DLMUES_Client_Plugin::get_instance();
}

// Initialize on plugins_loaded for proper hook timing.
add_action( 'plugins_loaded', 'dlmues_client' );
