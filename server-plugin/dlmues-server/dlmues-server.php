<?php
/**
 * Plugin Name: DLMUES License Server
 * Plugin URI:  https://dlmues.com
 * Description: Distributed License Management & Update Enforcement System - Server Component. Manages software licenses, payments, updates, and enforcement for WordPress plugins and themes.
 * Version:     1.0.0
 * Author:      License Authority
 * Author URI:  https://dlmues.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dlmues-server
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
define( 'DLMUES_SERVER_VERSION', '1.0.0' );
define( 'DLMUES_SERVER_PATH', plugin_dir_path( __FILE__ ) );
define( 'DLMUES_SERVER_URL', plugin_dir_url( __FILE__ ) );
define( 'DLMUES_SERVER_DB_VERSION', '1.2.0' );
define( 'DLMUES_SERVER_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Include required files.
 */
require_once DLMUES_SERVER_PATH . 'includes/class-database.php';
require_once DLMUES_SERVER_PATH . 'includes/class-security.php';
require_once DLMUES_SERVER_PATH . 'includes/class-license-engine.php';
require_once DLMUES_SERVER_PATH . 'includes/class-paystack.php';
require_once DLMUES_SERVER_PATH . 'includes/class-update-manager.php';
require_once DLMUES_SERVER_PATH . 'includes/class-notification-manager.php';
require_once DLMUES_SERVER_PATH . 'includes/class-invoice-manager.php';
require_once DLMUES_SERVER_PATH . 'includes/class-rest-api.php';
require_once DLMUES_SERVER_PATH . 'includes/class-admin-dashboard.php';

/**
 * Main plugin class.
 */
final class DLMUES_Server {

    /**
     * Single instance.
     *
     * @var DLMUES_Server|null
     */
    private static $instance = null;

    /**
     * Database handler.
     *
     * @var DLMUES_Database
     */
    public $database;

    /**
     * Security handler.
     *
     * @var DLMUES_Security
     */
    public $security;

    /**
     * License engine.
     *
     * @var DLMUES_License_Engine
     */
    public $license_engine;

    /**
     * Paystack handler.
     *
     * @var DLMUES_Paystack
     */
    public $paystack;

    /**
     * Update manager.
     *
     * @var DLMUES_Update_Manager
     */
    public $update_manager;

    /**
     * Notification manager.
     *
     * @var DLMUES_Notification_Manager
     */
    public $notification_manager;

    /**
     * Invoice manager.
     *
     * @var DLMUES_Invoice_Manager
     */
    public $invoice_manager;

    /**
     * REST API handler.
     *
     * @var DLMUES_REST_API
     */
    public $rest_api;

    /**
     * Admin dashboard.
     *
     * @var DLMUES_Admin_Dashboard
     */
    public $admin_dashboard;

    /**
     * Get singleton instance.
     *
     * @return DLMUES_Server
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_components();
        $this->init_hooks();
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Prevent unserialization.
     */
    public function __wakeup() {
        throw new \Exception( 'Cannot unserialize singleton.' );
    }

    /**
     * Initialize plugin components.
     */
    private function init_components() {
        $this->database             = new DLMUES_Database();
        $this->security             = new DLMUES_Security();
        $this->license_engine       = new DLMUES_License_Engine();
        $this->paystack             = new DLMUES_Paystack();
        $this->update_manager       = new DLMUES_Update_Manager();
        $this->notification_manager = new DLMUES_Notification_Manager();
        $this->invoice_manager      = new DLMUES_Invoice_Manager();
        $this->rest_api             = new DLMUES_REST_API();
        $this->admin_dashboard      = new DLMUES_Admin_Dashboard();
    }

    /**
     * Register hooks.
     */
    private function init_hooks() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'rest_api_init', array( $this->rest_api, 'register_routes' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_menu', array( $this->admin_dashboard, 'register_menus' ) );
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_shortcode( 'dlmues_checkout', array( $this, 'render_checkout_shortcode' ) );

        // Cron hooks for notification processing.
        add_action( 'dlmues_check_expiring_licenses', array( $this->notification_manager, 'process_notification_queue' ) );
        add_action( 'dlmues_daily_maintenance', array( $this, 'daily_maintenance' ) );
    }

    /**
     * Plugin activation.
     */
    public function activate() {
        $this->database->create_tables();
        $this->notification_manager->schedule_notifications();
        $this->schedule_maintenance();
        $this->create_upload_directory();
        update_option( 'dlmues_server_version', DLMUES_SERVER_VERSION );
        update_option( 'dlmues_server_db_version', DLMUES_SERVER_DB_VERSION );
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate() {
        wp_clear_scheduled_hook( 'dlmues_check_expiring_licenses' );
        wp_clear_scheduled_hook( 'dlmues_daily_maintenance' );
        flush_rewrite_rules();
    }

    /**
     * Load plugin text domain.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'dlmues-server',
            false,
            dirname( DLMUES_SERVER_BASENAME ) . '/languages'
        );
    }

    /**
     * Enqueue admin scripts and styles.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        // Only load on DLMUES admin pages.
        if ( strpos( $hook, 'dlmues' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'dlmues-admin-style',
            DLMUES_SERVER_URL . 'admin/css/admin-style.css',
            array(),
            DLMUES_SERVER_VERSION
        );

        // DataTables CSS.
        wp_enqueue_style(
            'datatables-css',
            'https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css',
            array(),
            '1.13.7'
        );

        // DataTables JS.
        wp_enqueue_script(
            'datatables-js',
            'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
            array( 'jquery' ),
            '1.13.7',
            true
        );

        // Chart.js for analytics.
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            array(),
            '4.4.1',
            true
        );

        wp_enqueue_script(
            'dlmues-admin-script',
            DLMUES_SERVER_URL . 'admin/js/admin-script.js',
            array( 'jquery', 'datatables-js', 'chartjs' ),
            DLMUES_SERVER_VERSION,
            true
        );

        wp_localize_script( 'dlmues-admin-script', 'dlmuesAdmin', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'restUrl'  => rest_url( 'dlmues/v1/' ),
            'nonce'    => wp_create_nonce( 'dlmues_admin_nonce' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
            'strings'  => array(
                'confirmDelete'    => __( 'Are you sure you want to delete this item?', 'dlmues-server' ),
                'confirmSuspend'   => __( 'Are you sure you want to suspend this license?', 'dlmues-server' ),
                'confirmBulk'      => __( 'Are you sure you want to perform this bulk action?', 'dlmues-server' ),
                'saved'            => __( 'Changes saved successfully.', 'dlmues-server' ),
                'error'            => __( 'An error occurred. Please try again.', 'dlmues-server' ),
                'exportComplete'   => __( 'Export complete.', 'dlmues-server' ),
                'testSuccess'      => __( 'Paystack connection successful!', 'dlmues-server' ),
                'testFail'         => __( 'Paystack connection failed.', 'dlmues-server' ),
            ),
        ) );
    }

    /**
     * Schedule daily maintenance cron.
     */
    private function schedule_maintenance() {
        if ( ! wp_next_scheduled( 'dlmues_daily_maintenance' ) ) {
            wp_schedule_event( time(), 'daily', 'dlmues_daily_maintenance' );
        }
    }

    /**
     * Daily maintenance tasks.
     */
    public function daily_maintenance() {
        $this->license_engine->process_expired_licenses();
        $this->cleanup_expired_tokens();
    }

    /**
     * Create the upload directory for update packages.
     */
    private function create_upload_directory() {
        $upload_dir = wp_upload_dir();
        $packages_dir = $upload_dir['basedir'] . '/dlmues-packages';

        if ( ! file_exists( $packages_dir ) ) {
            wp_mkdir_p( $packages_dir );

            // Protect directory with .htaccess.
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            file_put_contents( $packages_dir . '/.htaccess', $htaccess_content );

            // Add an index.php for extra safety.
            file_put_contents( $packages_dir . '/index.php', '<?php // Silence is golden.' );
        }
    }

    /**
     * Render the [dlmues_checkout] shortcode.
     *
     * Outputs a purchase form that calls the /license/new-purchase REST endpoint,
     * then opens the Paystack inline popup. On payment completion the webhook
     * auto-creates and emails the license key to the customer.
     *
     * Attributes:
     *   product_slug  - Which product to license (defaults to dlmues_default_product_slug option).
     *   plan          - Default plan (monthly, quarterly, yearly). Default: monthly.
     *   button_text   - CTA button label.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_checkout_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'product_slug' => get_option( 'dlmues_default_product_slug', '' ),
            'plan'         => 'monthly',
            'button_text'  => __( 'Purchase License', 'dlmues-server' ),
        ), $atts, 'dlmues_checkout' );

        $paystack   = new DLMUES_Paystack();
        $public_key = $paystack->get_public_key();

        // Enqueue Paystack inline JS.
        wp_enqueue_script( 'paystack-inline', 'https://js.paystack.co/v2/inline.js', array(), null, true );

        ob_start();
        ?>
        <div class="dlmues-checkout-form" style="max-width:480px;">
            <p>
                <label for="dlmues-checkout-email"><?php esc_html_e( 'Your Email Address', 'dlmues-server' ); ?></label><br>
                <input type="email" id="dlmues-checkout-email" class="regular-text" style="width:100%;" placeholder="you@example.com" required>
            </p>
            <p>
                <label for="dlmues-checkout-plan"><?php esc_html_e( 'Plan', 'dlmues-server' ); ?></label><br>
                <select id="dlmues-checkout-plan" style="width:100%;">
                    <option value="monthly" <?php selected( $atts['plan'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'dlmues-server' ); ?></option>
                    <option value="quarterly" <?php selected( $atts['plan'], 'quarterly' ); ?>><?php esc_html_e( 'Quarterly', 'dlmues-server' ); ?></option>
                    <option value="yearly" <?php selected( $atts['plan'], 'yearly' ); ?>><?php esc_html_e( 'Yearly', 'dlmues-server' ); ?></option>
                </select>
            </p>
            <p>
                <button type="button" id="dlmues-checkout-btn" class="button button-primary">
                    <?php echo esc_html( $atts['button_text'] ); ?>
                </button>
                <span id="dlmues-checkout-spinner" class="spinner" style="float:none;"></span>
            </p>
            <div id="dlmues-checkout-message"></div>
        </div>
        <script>
        ( function() {
            document.getElementById( 'dlmues-checkout-btn' ).addEventListener( 'click', function() {
                var email = document.getElementById( 'dlmues-checkout-email' ).value.trim();
                var plan  = document.getElementById( 'dlmues-checkout-plan' ).value;
                var msg   = document.getElementById( 'dlmues-checkout-message' );
                var btn   = this;

                if ( ! email ) {
                    msg.innerHTML = '<p style="color:red;"><?php echo esc_js( __( 'Please enter your email address.', 'dlmues-server' ) ); ?></p>';
                    return;
                }

                btn.disabled = true;
                document.getElementById( 'dlmues-checkout-spinner' ).style.display = 'inline-block';
                msg.innerHTML = '';

                fetch( '<?php echo esc_js( rest_url( 'dlmues/v1/license/new-purchase' ) ); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        email: email,
                        plan: plan,
                        product_slug: '<?php echo esc_js( $atts['product_slug'] ); ?>',
                        return_url: window.location.href,
                    } ),
                } )
                .then( function( r ) { return r.json(); } )
                .then( function( res ) {
                    document.getElementById( 'dlmues-checkout-spinner' ).style.display = 'none';
                    btn.disabled = false;

                    if ( res.data && res.data.public_key ) {
                        var d = res.data;
                        var handler = PaystackPop.setup( {
                            key: d.public_key,
                            email: d.email,
                            amount: d.amount,
                            currency: d.currency,
                            ref: d.reference,
                            onSuccess: function( t ) {
                                msg.innerHTML = '<p style="color:green;"><?php echo esc_js( __( 'Payment successful! Your license key will be emailed to you shortly.', 'dlmues-server' ) ); ?></p>';
                            },
                            onCancel: function() {
                                msg.innerHTML = '<p style="color:orange;"><?php echo esc_js( __( 'Payment cancelled.', 'dlmues-server' ) ); ?></p>';
                            },
                        } );
                        handler.openIframe();
                    } else {
                        msg.innerHTML = '<p style="color:red;">' + ( ( res.message || res.code ) || '<?php echo esc_js( __( 'An error occurred. Please try again.', 'dlmues-server' ) ); ?>' ) + '</p>';
                    }
                } )
                .catch( function() {
                    document.getElementById( 'dlmues-checkout-spinner' ).style.display = 'none';
                    btn.disabled = false;
                    msg.innerHTML = '<p style="color:red;"><?php echo esc_js( __( 'An error occurred. Please try again.', 'dlmues-server' ) ); ?></p>';
                } );
            } );
        } )();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Clean up expired API tokens.
     */
    private function cleanup_expired_tokens() {
        global $wpdb;
        $table = $wpdb->prefix . 'dlmues_api_tokens';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s",
                current_time( 'mysql' )
            )
        );
    }
}

/**
 * Return the main plugin instance.
 *
 * @return DLMUES_Server
 */
function dlmues_server() {
    return DLMUES_Server::get_instance();
}

// Initialize the plugin.
dlmues_server();
