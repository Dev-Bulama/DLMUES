<?php
/**
 * Client Admin interface for DLMUES.
 *
 * Registers the settings page under Settings > DLMUES License,
 * and renders the license management UI.
 *
 * @package DLMUES_Client
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Client_Admin
 */
class DLMUES_Client_Admin {

    /**
     * License client instance.
     *
     * @var DLMUES_License_Client
     */
    private $license_client;

    /**
     * Payment handler instance.
     *
     * @var DLMUES_Payment_Handler
     */
    private $payment_handler;

    /**
     * Site health reporter instance.
     *
     * @var DLMUES_Site_Health_Reporter
     */
    private $health_reporter;

    /**
     * Payment history instance.
     *
     * @var DLMUES_Payment_History|null
     */
    private $payment_history;

    /**
     * Constructor.
     *
     * @param DLMUES_License_Client      $license_client  The license client instance.
     * @param DLMUES_Payment_Handler     $payment_handler The payment handler instance.
     * @param DLMUES_Site_Health_Reporter $health_reporter The health reporter instance.
     */
    public function __construct( DLMUES_License_Client $license_client, DLMUES_Payment_Handler $payment_handler, DLMUES_Site_Health_Reporter $health_reporter ) {
        $this->license_client  = $license_client;
        $this->payment_handler = $payment_handler;
        $this->health_reporter = $health_reporter;
        $this->payment_history = class_exists( 'DLMUES_Payment_History' ) ? new DLMUES_Payment_History() : null;

        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
    }

    /**
     * Register the settings page under Settings menu.
     */
    public function register_settings_page() {
        add_options_page(
            __( 'DLMUES License', 'dlmues-client' ),
            __( 'DLMUES License', 'dlmues-client' ),
            'manage_options',
            'dlmues-license',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Render the license settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'dlmues-client' ) );
        }

        $license_data = $this->license_client->get_license_data();
        $has_license  = ! empty( $license_data['license_key'] );
        $is_valid     = $this->license_client->is_license_valid();
        $is_grace     = $this->license_client->is_in_grace_period();
        $is_expired   = $this->license_client->is_expired();

        // Handle payment return.
        $payment_status = '';
        if ( isset( $_GET['payment'] ) && 'complete' === $_GET['payment'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $reference = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( ! empty( $reference ) ) {
                $payment_status = 'verifying';
            } else {
                $payment_status = 'returned';
            }
        }

        ?>
        <div class="wrap dlmues-client-admin">
            <h1><?php esc_html_e( 'DLMUES License Management', 'dlmues-client' ); ?></h1>

            <?php if ( 'verifying' === $payment_status ) :
                $pay_reference = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $pay_trxref    = isset( $_GET['trxref'] ) ? sanitize_text_field( wp_unslash( $_GET['trxref'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $pay_ref_final = ! empty( $pay_reference ) ? $pay_reference : $pay_trxref;
                ?>
                <div id="dlmues-payment-verification-notice" class="notice notice-info" style="padding: 12px 16px;">
                    <p id="dlmues-verify-status-msg" style="margin: 0 0 8px;">
                        <span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span>
                        <?php esc_html_e( 'Verifying payment\xe2\x80\xa6 Please wait.', 'dlmues-client' ); ?>
                    </p>
                    <button type="button" id="dlmues-retry-verify-btn" class="button"
                            data-reference="<?php echo esc_attr( $pay_ref_final ); ?>"
                            style="display:none;">
                        <?php esc_html_e( 'Retry Verification', 'dlmues-client' ); ?>
                    </button>
                </div>
            <?php elseif ( 'returned' === $payment_status ) : ?>
                <div class="notice notice-success">
                    <p><?php esc_html_e( 'Payment process completed. Your license status will update shortly.', 'dlmues-client' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( ! $has_license ) : ?>
                <?php $this->render_activation_form(); ?>
            <?php else : ?>
                <?php $this->render_license_status( $license_data, $is_valid, $is_grace, $is_expired ); ?>

                <?php if ( $is_expired || $is_grace ) : ?>
                    <?php $this->render_renewal_section( $license_data ); ?>
                <?php endif; ?>

                <?php $this->render_site_health_section(); ?>

                <?php $this->render_payment_history_section(); ?>

                <?php if ( ! empty( $license_data['allow_deactivation'] ) ) : ?>
                    <?php $this->render_deactivation_section(); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <style>
            .dlmues-client-admin .dlmues-card {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 20px;
                margin: 15px 0;
            }
            .dlmues-client-admin .dlmues-card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .dlmues-client-admin .dlmues-status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 3px;
                font-weight: bold;
                font-size: 13px;
                text-transform: uppercase;
            }
            .dlmues-client-admin .dlmues-status-active { background: #d4edda; color: #155724; }
            .dlmues-client-admin .dlmues-status-grace { background: #fff3cd; color: #856404; }
            .dlmues-client-admin .dlmues-status-expired { background: #f8d7da; color: #721c24; }
            .dlmues-client-admin .dlmues-status-inactive { background: #e2e3e5; color: #383d41; }
            .dlmues-client-admin .dlmues-info-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
                margin: 15px 0;
            }
            .dlmues-client-admin .dlmues-info-item {
                padding: 10px;
                background: #f8f9fa;
                border-radius: 4px;
            }
            .dlmues-client-admin .dlmues-info-item label {
                display: block;
                font-size: 11px;
                text-transform: uppercase;
                color: #666;
                margin-bottom: 3px;
            }
            .dlmues-client-admin .dlmues-info-item span {
                font-size: 14px;
                font-weight: 600;
            }
        </style>
        <?php
    }

    /**
     * Render the license activation form.
     */
    private function render_activation_form() {
        ?>
        <div class="dlmues-card">
            <h2><?php esc_html_e( 'Activate License', 'dlmues-client' ); ?></h2>
            <p><?php esc_html_e( 'Enter your license key to activate your license.', 'dlmues-client' ); ?></p>

            <div id="dlmues-activation-form">
                <table class="form-table">
                    <tr>
                        <th><label for="dlmues-license-key"><?php esc_html_e( 'License Key', 'dlmues-client' ); ?></label></th>
                        <td>
                            <input type="text" id="dlmues-license-key" class="regular-text" placeholder="DLMUES-XXXX-XXXX-XXXX-XXXX">
                            <p class="description"><?php esc_html_e( 'Your license key provided at purchase.', 'dlmues-client' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" id="dlmues-activate-btn" class="button button-primary">
                        <?php esc_html_e( 'Activate License', 'dlmues-client' ); ?>
                    </button>
                    <span id="dlmues-activation-spinner" class="spinner" style="float: none;"></span>
                </p>
                <div id="dlmues-activation-message"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the license status display.
     *
     * @param array $license_data The license data.
     * @param bool  $is_valid     Whether the license is valid.
     * @param bool  $is_grace     Whether in grace period.
     * @param bool  $is_expired   Whether expired past grace.
     */
    private function render_license_status( $license_data, $is_valid, $is_grace, $is_expired ) {
        $status_class = 'dlmues-status-inactive';
        $status_label = __( 'Inactive', 'dlmues-client' );

        if ( $is_valid ) {
            $status_class = 'dlmues-status-active';
            $status_label = __( 'Active', 'dlmues-client' );
        } elseif ( $is_grace ) {
            $status_class = 'dlmues-status-grace';
            $status_label = __( 'Grace Period', 'dlmues-client' );
        } elseif ( $is_expired ) {
            $status_class = 'dlmues-status-expired';
            $status_label = __( 'Expired', 'dlmues-client' );
        }

        $time_remaining = $license_data['time_remaining'];
        ?>
        <div class="dlmues-card">
            <h2>
                <?php esc_html_e( 'License Status', 'dlmues-client' ); ?>
                <span class="dlmues-status-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                <button type="button" id="dlmues-refresh-btn" class="button button-small" style="margin-left: 10px;">
                    <?php esc_html_e( 'Refresh', 'dlmues-client' ); ?>
                </button>
            </h2>

            <div class="dlmues-info-grid">
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'License Key', 'dlmues-client' ); ?></label>
                    <span><code><?php echo esc_html( $license_data['license_key'] ); ?></code></span>
                </div>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'Product', 'dlmues-client' ); ?></label>
                    <span><?php echo esc_html( $license_data['product_name'] ? $license_data['product_name'] : $license_data['product_slug'] ); ?></span>
                </div>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'Plan', 'dlmues-client' ); ?></label>
                    <span><?php echo esc_html( ucfirst( $license_data['subscription_type'] ) ); ?></span>
                </div>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'Expires', 'dlmues-client' ); ?></label>
                    <span><?php echo esc_html( $license_data['expires_at'] ? $license_data['expires_at'] : __( 'Never', 'dlmues-client' ) ); ?></span>
                </div>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'Time Remaining', 'dlmues-client' ); ?></label>
                    <span><?php echo esc_html( $time_remaining['human_readable'] ); ?></span>
                </div>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'Domain', 'dlmues-client' ); ?></label>
                    <span><?php echo esc_html( $license_data['domain'] ); ?></span>
                </div>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'Server', 'dlmues-client' ); ?></label>
                    <span><?php echo esc_html( $license_data['server_url'] ); ?></span>
                </div>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'Last Validated', 'dlmues-client' ); ?></label>
                    <span><?php echo esc_html( $license_data['last_validated'] ); ?></span>
                </div>
                <?php if ( ! empty( $license_data['created_at'] ) ) : ?>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'Client Since', 'dlmues-client' ); ?></label>
                    <span><?php echo esc_html( gmdate( 'M j, Y', strtotime( $license_data['created_at'] ) ) ); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the renewal section with plan selection.
     *
     * @param array $license_data The license data.
     */
    private function render_renewal_section( $license_data ) {
        $custom_amount = ! empty( $license_data['custom_renewal_amount'] ) ? floatval( $license_data['custom_renewal_amount'] ) : 0;
        $currency      = ! empty( $license_data['currency'] ) ? $license_data['currency'] : 'USD';
        ?>
        <div class="dlmues-card" style="border-left: 4px solid #dc3232;">
            <h2><?php esc_html_e( 'Renew Your License', 'dlmues-client' ); ?></h2>
            <p><?php esc_html_e( 'Your license has expired. Select a plan below to renew.', 'dlmues-client' ); ?></p>

            <?php if ( $custom_amount > 0 ) : ?>
                <p><strong><?php esc_html_e( 'Renewal Amount:', 'dlmues-client' ); ?></strong> <?php echo esc_html( $currency . ' ' . number_format( $custom_amount, 2 ) ); ?></p>
            <?php endif; ?>

            <p style="margin-top:20px;">
                <button type="button" id="dlmues-open-payment" class="button button-primary button-hero">
                    <?php esc_html_e( 'Renew License', 'dlmues-client' ); ?>
                </button>
            </p>

            <div id="dlmues-payment-message"></div>
            <div id="dlmues-payment-overlay" class="dlmues-payment-overlay"></div>
        </div>
        <?php
    }

    /**
     * Render the site health section.
     */
    private function render_site_health_section() {
        $health_data    = $this->health_reporter->collect_health_data();
        $visitor_count  = $this->health_reporter->get_total_visitor_count();
        $today_count    = $this->health_reporter->get_today_visitor_count();
        $license_data   = $this->license_client->get_license_data();
        $injected_count = absint( isset( $license_data['injected_visitor_count'] ) ? $license_data['injected_visitor_count'] : 0 );

        ?>
        <div class="dlmues-card">
            <h2><?php esc_html_e( 'Site Health', 'dlmues-client' ); ?></h2>

            <div class="dlmues-info-grid">
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'WordPress Version', 'dlmues-client' ); ?></label>
                    <span><?php echo esc_html( $health_data['wp_version'] ); ?></span>
                </div>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'PHP Version', 'dlmues-client' ); ?></label>
                    <span><?php echo esc_html( $health_data['php_version'] ); ?></span>
                </div>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'Active Theme', 'dlmues-client' ); ?></label>
                    <span><?php echo esc_html( $health_data['active_theme'] ); ?></span>
                </div>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'Active Plugins', 'dlmues-client' ); ?></label>
                    <span><?php echo absint( count( $health_data['plugin_list'] ) ); ?></span>
                </div>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'Visitors (30 days)', 'dlmues-client' ); ?></label>
                    <span><?php echo absint( $visitor_count + $injected_count ); ?>
                    <?php if ( $injected_count > 0 ) : ?>
                        <small style="color:#666;">(<?php echo absint( $visitor_count ); ?> <?php esc_html_e( 'tracked', 'dlmues-client' ); ?> + <?php echo absint( $injected_count ); ?> <?php esc_html_e( 'added', 'dlmues-client' ); ?>)</small>
                    <?php endif; ?>
                    </span>
                </div>
                <div class="dlmues-info-item">
                    <label><?php esc_html_e( 'Visitors (Today)', 'dlmues-client' ); ?></label>
                    <span><?php echo absint( $today_count ); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the payment history section.
     *
     * Displays the wp_dlmues_payment_history table rows if any exist.
     * When no history has been populated yet a friendly placeholder is shown.
     */
    private function render_payment_history_section() {
        if ( null === $this->payment_history ) {
            return;
        }

        $history = $this->payment_history->get_history_for_display();
        ?>
        <div class="dlmues-card">
            <h2><?php esc_html_e( 'Payment History', 'dlmues-client' ); ?></h2>

            <?php if ( empty( $history ) ) : ?>
                <p style="color:#666;">
                    <?php esc_html_e( 'No payment history records found. Your license administrator can populate this from the server dashboard.', 'dlmues-client' ); ?>
                </p>
            <?php else : ?>
                <table class="widefat striped" style="margin-top:10px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Month', 'dlmues-client' ); ?></th>
                            <th><?php esc_html_e( 'Actual Rate', 'dlmues-client' ); ?></th>
                            <th><?php esc_html_e( 'Partner Discount', 'dlmues-client' ); ?></th>
                            <th><?php esc_html_e( 'Final Amount', 'dlmues-client' ); ?></th>
                            <th><?php esc_html_e( 'Billing Type', 'dlmues-client' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'dlmues-client' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $history as $record ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $record['invoice_month'] ); ?></strong></td>
                                <td>$<?php echo esc_html( number_format( (float) $record['actual_rate'], 2 ) ); ?></td>
                                <td style="color:#dc3232;">-$<?php echo esc_html( number_format( (float) $record['partner_discount'], 2 ) ); ?></td>
                                <td><strong>$<?php echo esc_html( number_format( (float) $record['final_amount'], 2 ) ); ?></strong></td>
                                <td><?php echo esc_html( ucfirst( $record['billing_type'] ) ); ?></td>
                                <td>
                                    <span class="dlmues-status-badge dlmues-status-active">
                                        <?php echo esc_html( ucfirst( $record['status'] ) ); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" style="font-size:12px;color:#666;">
                                <?php echo esc_html( sprintf( _n( '%d record', '%d records', count( $history ), 'dlmues-client' ), count( $history ) ) ); ?>
                            </td>
                            <td colspan="4" style="text-align:right;font-size:12px;color:#666;">
                                <?php
                                $total_final = array_sum( array_column( $history, 'final_amount' ) );
                                /* translators: %s: total amount paid */
                                echo esc_html( sprintf( __( 'Total paid: $%s', 'dlmues-client' ), number_format( (float) $total_final, 2 ) ) );
                                ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the deactivation section.
     */
    private function render_deactivation_section() {
        ?>
        <div class="dlmues-card">
            <h2><?php esc_html_e( 'Deactivate License', 'dlmues-client' ); ?></h2>
            <p><?php esc_html_e( 'Deactivating your license will disconnect this site from the license server. You can reactivate later with the same license key.', 'dlmues-client' ); ?></p>
            <p>
                <button type="button" id="dlmues-deactivate-btn" class="button button-secondary">
                    <?php esc_html_e( 'Deactivate License', 'dlmues-client' ); ?>
                </button>
                <span id="dlmues-deactivation-spinner" class="spinner" style="float: none;"></span>
            </p>
        </div>
        <?php
    }
}
