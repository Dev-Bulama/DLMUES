<?php
/**
 * License Enforcement handler for DLMUES.
 *
 * Enforces license restrictions when the license is expired past
 * the grace period. Supports multiple enforcement modes:
 * restrict_admin, lock_frontend, and maintenance.
 *
 * @package DLMUES_Client
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Enforcement
 *
 * Handles license enforcement when expired past grace period.
 */
class DLMUES_Enforcement {

    /**
     * License client instance.
     *
     * @var DLMUES_License_Client
     */
    private $license_client;

    /**
     * Bypass parameter name for emergency access.
     *
     * @var string
     */
    private $bypass_param = 'dlmues_bypass';

    /**
     * Bypass token stored in options.
     *
     * @var string
     */
    private $bypass_token_option = 'dlmues_client_bypass_token';

    /**
     * Constructor.
     *
     * @param DLMUES_License_Client $license_client The license client instance.
     */
    public function __construct( DLMUES_License_Client $license_client ) {
        $this->license_client = $license_client;

        // Generate bypass token if not exists.
        if ( empty( get_option( $this->bypass_token_option, '' ) ) ) {
            update_option( $this->bypass_token_option, wp_generate_password( 32, false ) );
        }
    }

    /**
     * Main enforcement method.
     *
     * Called on admin_init and template_redirect to apply enforcement.
     * Determines the enforcement mode and applies appropriate restrictions.
     */
    public function enforce() {
        // Check if bypass is active.
        if ( $this->is_bypass_active() ) {
            return;
        }

        // Always allow access to the DLMUES settings page.
        if ( $this->is_dlmues_settings_page() ) {
            return;
        }

        // Show grace period notice if in grace period.
        if ( $this->license_client->is_in_grace_period() ) {
            $this->show_grace_period_notice();
            $this->show_expiry_warning();
            return;
        }

        // Show expiry warning if approaching expiry (within 7 days).
        if ( $this->license_client->is_license_valid() ) {
            $time_remaining = $this->license_client->get_time_remaining();
            if ( ! $time_remaining['is_lifetime'] && $time_remaining['days'] <= 7 && $time_remaining['total_seconds'] > 0 ) {
                $this->show_expiry_warning();
            }
            return;
        }

        // Only enforce if expired past grace period.
        if ( ! $this->license_client->is_expired() ) {
            return;
        }

        $mode = $this->get_enforcement_mode();

        switch ( $mode ) {
            case 'restrict_admin':
                $this->enforce_restrict_admin();
                break;

            case 'lock_frontend':
                $this->enforce_lock_frontend();
                break;

            case 'maintenance':
                $this->enforce_maintenance_mode();
                break;

            default:
                $this->enforce_restrict_admin();
                break;
        }
    }

    /**
     * Enforce admin restriction mode.
     *
     * Shows a modal overlay on all admin pages.
     * Only allows access to the DLMUES settings page.
     * Redirects other admin pages to the renewal page.
     */
    public function enforce_restrict_admin() {
        if ( ! is_admin() ) {
            return;
        }

        // Allow AJAX requests for payment/license operations.
        if ( wp_doing_ajax() ) {
            $allowed_actions = array(
                'dlmues_activate_license',
                'dlmues_deactivate_license',
                'dlmues_refresh_status',
                'dlmues_initiate_payment',
                'dlmues_verify_payment',
                'dlmues_get_plans',
            );
            $current_action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
            if ( in_array( $current_action, $allowed_actions, true ) ) {
                return;
            }
        }

        // Allow the DLMUES settings page.
        if ( $this->is_dlmues_settings_page() ) {
            // Add an admin notice on the settings page.
            add_action( 'admin_notices', array( $this, 'render_expired_admin_notice' ) );
            return;
        }

        // Add the enforcement modal overlay via admin_footer.
        add_action( 'admin_footer', array( $this, 'render_enforcement_modal' ) );
        add_action( 'admin_notices', array( $this, 'render_expired_admin_notice' ) );
    }

    /**
     * Enforce frontend lockout mode.
     *
     * Replaces the entire frontend with a "Site Under Maintenance" page.
     * Only allows wp-admin access.
     */
    public function enforce_lock_frontend() {
        if ( is_admin() ) {
            // Show modal on admin pages.
            if ( ! $this->is_dlmues_settings_page() ) {
                add_action( 'admin_footer', array( $this, 'render_enforcement_modal' ) );
            }
            add_action( 'admin_notices', array( $this, 'render_expired_admin_notice' ) );
            return;
        }

        // Block frontend access.
        add_action( 'template_redirect', array( $this, 'render_maintenance_page' ), 1 );
    }

    /**
     * Enforce full maintenance mode.
     *
     * Shows maintenance page on frontend and renewal modal on admin.
     */
    public function enforce_maintenance_mode() {
        if ( is_admin() ) {
            if ( ! $this->is_dlmues_settings_page() ) {
                add_action( 'admin_footer', array( $this, 'render_enforcement_modal' ) );
            }
            add_action( 'admin_notices', array( $this, 'render_expired_admin_notice' ) );
            return;
        }

        // Block frontend access.
        add_action( 'template_redirect', array( $this, 'render_maintenance_page' ), 1 );
    }

    /**
     * Get the current enforcement mode.
     *
     * @return string The enforcement mode (restrict_admin, lock_frontend, maintenance).
     */
    public function get_enforcement_mode() {
        $mode = get_option( $this->license_client->get_prefix() . 'enforcement_mode', 'restrict_admin' );

        $valid_modes = array( 'restrict_admin', 'lock_frontend', 'maintenance' );

        if ( ! in_array( $mode, $valid_modes, true ) ) {
            return 'restrict_admin';
        }

        return $mode;
    }

    /**
     * Show grace period admin notice.
     */
    public function show_grace_period_notice() {
        add_action( 'admin_notices', array( $this, 'render_grace_period_notice' ) );
    }

    /**
     * Show expiry warning when approaching expiry.
     */
    public function show_expiry_warning() {
        add_action( 'admin_notices', array( $this, 'render_expiry_warning_notice' ) );
    }

    /**
     * Render grace period admin notice.
     */
    public function render_grace_period_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $time_remaining = $this->license_client->get_time_remaining();
        $settings_url   = admin_url( 'options-general.php?page=dlmues-license' );
        $product_name   = get_option( $this->license_client->get_prefix() . 'product_name', '' );

        if ( empty( $product_name ) ) {
            $product_name = __( 'your licensed product', 'dlmues-client' );
        }

        ?>
        <div class="notice notice-warning dlmues-grace-notice" style="border-left-color: #ffba00; padding: 12px;">
            <p>
                <strong><?php esc_html_e( 'License Grace Period Active', 'dlmues-client' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: product name, 2: time remaining */
                    esc_html__( 'Your license for %1$s has expired and is now in the grace period. You have %2$s remaining before enforcement begins. Please renew your license to avoid service disruption.', 'dlmues-client' ),
                    '<strong>' . esc_html( $product_name ) . '</strong>',
                    '<strong>' . esc_html( $time_remaining['human_readable'] ) . '</strong>'
                );
                ?>
            </p>
            <p>
                <a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Renew License', 'dlmues-client' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Render expiry warning admin notice.
     */
    public function render_expiry_warning_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $time_remaining = $this->license_client->get_time_remaining();
        $settings_url   = admin_url( 'options-general.php?page=dlmues-license' );

        ?>
        <div class="notice notice-info dlmues-expiry-warning" style="border-left-color: #0073aa;">
            <p>
                <strong><?php esc_html_e( 'License Expiring Soon', 'dlmues-client' ); ?></strong> &mdash;
                <?php
                printf(
                    /* translators: %s: time remaining */
                    esc_html__( 'Your license expires in %s. Renew now to ensure uninterrupted service.', 'dlmues-client' ),
                    '<strong>' . esc_html( $time_remaining['human_readable'] ) . '</strong>'
                );
                ?>
                <a href="<?php echo esc_url( $settings_url ); ?>">
                    <?php esc_html_e( 'Renew License', 'dlmues-client' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Render expired license admin notice.
     */
    public function render_expired_admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings_url = admin_url( 'options-general.php?page=dlmues-license' );
        $product_name = get_option( $this->license_client->get_prefix() . 'product_name', '' );

        if ( empty( $product_name ) ) {
            $product_name = __( 'your licensed product', 'dlmues-client' );
        }

        ?>
        <div class="notice notice-error dlmues-expired-notice" style="border-left-color: #dc3232; padding: 12px;">
            <p>
                <strong><?php esc_html_e( 'License Expired', 'dlmues-client' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %s: product name */
                    esc_html__( 'Your license for %s has expired and enforcement is active. Some features may be restricted until you renew.', 'dlmues-client' ),
                    '<strong>' . esc_html( $product_name ) . '</strong>'
                );
                ?>
            </p>
            <p>
                <a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Renew License Now', 'dlmues-client' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Render enforcement modal overlay on admin pages.
     *
     * This modal cannot be dismissed and covers the entire admin page.
     */
    public function render_enforcement_modal() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $license_data   = $this->license_client->get_license_data();
        $settings_url   = admin_url( 'options-general.php?page=dlmues-license' );
        $product_name   = ! empty( $license_data['product_name'] ) ? $license_data['product_name'] : __( 'Licensed Product', 'dlmues-client' );

        include DLMUES_CLIENT_PATH . 'templates/renewal-modal.php';
    }

    /**
     * Render the maintenance page for frontend lockout.
     *
     * Outputs a standalone HTML page and terminates execution.
     */
    public function render_maintenance_page() {
        // Check bypass.
        if ( $this->is_bypass_active() ) {
            return;
        }

        $license_data = $this->license_client->get_license_data();

        // Set proper HTTP status code.
        status_header( 503 );
        header( 'Retry-After: 3600' );
        header( 'Content-Type: text/html; charset=utf-8' );

        include DLMUES_CLIENT_PATH . 'templates/maintenance-page.php';
        exit;
    }

    /**
     * Check if the current page is the DLMUES settings page.
     *
     * @return bool True if on DLMUES settings page.
     */
    private function is_dlmues_settings_page() {
        if ( ! is_admin() ) {
            return false;
        }

        $current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

        return ( 'dlmues-license' === $current_page );
    }

    /**
     * Check if the bypass parameter is present and valid.
     *
     * Allows emergency access to the site with a special URL parameter.
     *
     * @return bool True if bypass is active.
     */
    private function is_bypass_active() {
        if ( ! isset( $_GET[ $this->bypass_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return false;
        }

        $provided_token = sanitize_text_field( wp_unslash( $_GET[ $this->bypass_param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $stored_token   = get_option( $this->bypass_token_option, '' );

        if ( empty( $stored_token ) ) {
            return false;
        }

        if ( hash_equals( $stored_token, $provided_token ) ) {
            // Set a session cookie so bypass persists for the session.
            if ( ! isset( $_COOKIE['dlmues_bypass_session'] ) ) {
                setcookie( 'dlmues_bypass_session', $provided_token, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
            }
            return true;
        }

        // Check session cookie.
        if ( isset( $_COOKIE['dlmues_bypass_session'] ) ) {
            $cookie_token = sanitize_text_field( wp_unslash( $_COOKIE['dlmues_bypass_session'] ) );
            return hash_equals( $stored_token, $cookie_token );
        }

        return false;
    }
}
