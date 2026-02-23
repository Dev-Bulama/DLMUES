<?php
/**
 * Notification Manager for DLMUES License Server.
 *
 * Handles email notifications for license expiry warnings,
 * grace period alerts, payment confirmations, and renewal reminders.
 *
 * @package DLMUES_Server
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Notification_Manager
 */
class DLMUES_Notification_Manager {

    /**
     * Email from name.
     *
     * @var string
     */
    private $from_name;

    /**
     * Email from address.
     *
     * @var string
     */
    private $from_email;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->from_name  = get_option( 'dlmues_email_from_name', get_bloginfo( 'name' ) );
        $this->from_email = get_option( 'dlmues_email_from_address', get_option( 'admin_email' ) );

        add_filter( 'wp_mail_from', array( $this, 'set_mail_from' ) );
        add_filter( 'wp_mail_from_name', array( $this, 'set_mail_from_name' ) );
    }

    /**
     * Set the from email address for DLMUES emails.
     *
     * @param string $email Default from email.
     * @return string Modified from email.
     */
    public function set_mail_from( $email ) {
        if ( ! empty( $this->from_email ) ) {
            return $this->from_email;
        }
        return $email;
    }

    /**
     * Set the from name for DLMUES emails.
     *
     * @param string $name Default from name.
     * @return string Modified from name.
     */
    public function set_mail_from_name( $name ) {
        if ( ! empty( $this->from_name ) ) {
            return $this->from_name;
        }
        return $name;
    }

    /**
     * Schedule notification cron events.
     */
    public function schedule_notifications() {
        if ( ! wp_next_scheduled( 'dlmues_check_expiring_licenses' ) ) {
            wp_schedule_event( time(), 'daily', 'dlmues_check_expiring_licenses' );
        }
    }

    /**
     * Process the notification queue.
     *
     * Checks for licenses expiring soon and sends appropriate notifications.
     */
    public function process_notification_queue() {
        $this->send_expiry_warnings();
        $this->send_grace_period_warnings_batch();
    }

    /**
     * Send expiry warning emails to licenses expiring within 7 days.
     */
    private function send_expiry_warnings() {
        $license_engine = new DLMUES_License_Engine();
        $expiring       = $license_engine->get_expiring_licenses( 7 );

        foreach ( $expiring as $license ) {
            // Skip if no email.
            if ( empty( $license['client_email'] ) ) {
                continue;
            }

            // Check if we already sent a warning for this expiry period.
            $sent_key = 'dlmues_expiry_warned_' . md5( $license['license_key'] . $license['expires_at'] );
            if ( get_transient( $sent_key ) ) {
                continue;
            }

            $this->send_expiry_warning( $license['license_key'] );

            // Mark as sent for 6 days.
            set_transient( $sent_key, 1, 6 * DAY_IN_SECONDS );
        }
    }

    /**
     * Send grace period warnings for licenses in grace period.
     */
    private function send_grace_period_warnings_batch() {
        $license_engine = new DLMUES_License_Engine();
        $expired        = $license_engine->get_licenses_by_status( 'expired' );

        foreach ( $expired as $license ) {
            if ( empty( $license['client_email'] ) ) {
                continue;
            }

            $sent_key = 'dlmues_grace_warned_' . md5( $license['license_key'] );
            if ( get_transient( $sent_key ) ) {
                continue;
            }

            $this->send_grace_period_warning( $license['license_key'] );
            set_transient( $sent_key, 1, 3 * DAY_IN_SECONDS );
        }
    }

    /**
     * Send an expiry warning email.
     *
     * @param string $license_key The license key.
     * @return bool True if sent successfully.
     */
    public function send_expiry_warning( $license_key ) {
        $license_engine = new DLMUES_License_Engine();
        $license        = $license_engine->get_license( $license_key );

        if ( ! $license || empty( $license['client_email'] ) ) {
            return false;
        }

        $days_remaining = max( 0, ceil( ( strtotime( $license['expires_at'] ) - time() ) / DAY_IN_SECONDS ) );
        $renewal_url    = $this->get_renewal_url( $license );

        $subject = sprintf(
            /* translators: 1: product name or slug, 2: days remaining */
            __( 'Your license for %1$s expires in %2$d days', 'dlmues-server' ),
            $this->get_product_name( $license ),
            $days_remaining
        );

        $message = $this->build_email_body( 'expiry_warning', array(
            'license'        => $license,
            'days_remaining' => $days_remaining,
            'renewal_url'    => $renewal_url,
            'product_name'   => $this->get_product_name( $license ),
        ) );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        return wp_mail( $license['client_email'], $subject, $message, $headers );
    }

    /**
     * Send a grace period warning email.
     *
     * @param string $license_key The license key.
     * @return bool True if sent successfully.
     */
    public function send_grace_period_warning( $license_key ) {
        $license_engine = new DLMUES_License_Engine();
        $license        = $license_engine->get_license( $license_key );

        if ( ! $license || empty( $license['client_email'] ) ) {
            return false;
        }

        $expiry_time    = strtotime( $license['expires_at'] );
        $grace_end      = $expiry_time + ( absint( $license['grace_period_days'] ) * DAY_IN_SECONDS );
        $days_remaining = max( 0, ceil( ( $grace_end - time() ) / DAY_IN_SECONDS ) );
        $renewal_url    = $this->get_renewal_url( $license );

        $subject = sprintf(
            /* translators: %s: product name */
            __( 'Urgent: License for %s - Grace Period Active', 'dlmues-server' ),
            $this->get_product_name( $license )
        );

        $message = $this->build_email_body( 'grace_period', array(
            'license'        => $license,
            'days_remaining' => $days_remaining,
            'renewal_url'    => $renewal_url,
            'product_name'   => $this->get_product_name( $license ),
        ) );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        return wp_mail( $license['client_email'], $subject, $message, $headers );
    }

    /**
     * Send a payment confirmation email.
     *
     * @param string $license_key  The license key.
     * @param array  $payment_data Payment details.
     * @return bool True if sent successfully.
     */
    public function send_payment_confirmation( $license_key, $payment_data ) {
        $license_engine = new DLMUES_License_Engine();
        $license        = $license_engine->get_license( $license_key );

        if ( ! $license || empty( $license['client_email'] ) ) {
            return false;
        }

        $subject = sprintf(
            /* translators: %s: product name */
            __( 'Payment Confirmed - %s License Renewed', 'dlmues-server' ),
            $this->get_product_name( $license )
        );

        $message = $this->build_email_body( 'payment_confirmation', array(
            'license'      => $license,
            'payment_data' => $payment_data,
            'product_name' => $this->get_product_name( $license ),
        ) );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        return wp_mail( $license['client_email'], $subject, $message, $headers );
    }

    /**
     * Build an HTML email body from a template type.
     *
     * @param string $template Template type.
     * @param array  $data     Template data.
     * @return string The HTML email body.
     */
    private function build_email_body( $template, $data ) {
        $site_name = get_bloginfo( 'name' );
        $license   = $data['license'];
        $security  = new DLMUES_Security();
        $masked_key = $security->mask_license_key( $license['license_key'] );

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0073aa; color: #fff; padding: 20px; text-align: center; border-radius: 4px 4px 0 0; }
                .content { background: #fff; padding: 30px; border: 1px solid #ddd; }
                .footer { background: #f7f7f7; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 4px 4px; }
                .btn { display: inline-block; padding: 12px 24px; background: #0073aa; color: #fff; text-decoration: none; border-radius: 4px; margin: 10px 0; }
                .btn-urgent { background: #dc3232; }
                .info-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                .info-table td { padding: 8px; border-bottom: 1px solid #eee; }
                .info-table td:first-child { font-weight: bold; width: 40%; }
            </style>
        </head>
        <body>
        <div class="container">
            <div class="header">
                <h2><?php echo esc_html( $site_name ); ?></h2>
            </div>
            <div class="content">
        <?php

        switch ( $template ) {
            case 'expiry_warning':
                ?>
                <h3><?php esc_html_e( 'License Expiring Soon', 'dlmues-server' ); ?></h3>
                <p>
                    <?php
                    printf(
                        /* translators: 1: product name, 2: days remaining */
                        esc_html__( 'Your license for %1$s will expire in %2$d day(s). Please renew to maintain access to updates and support.', 'dlmues-server' ),
                        '<strong>' . esc_html( $data['product_name'] ) . '</strong>',
                        absint( $data['days_remaining'] )
                    );
                    ?>
                </p>
                <table class="info-table">
                    <tr><td><?php esc_html_e( 'License Key', 'dlmues-server' ); ?></td><td><?php echo esc_html( $masked_key ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'Expires', 'dlmues-server' ); ?></td><td><?php echo esc_html( $license['expires_at'] ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'Plan', 'dlmues-server' ); ?></td><td><?php echo esc_html( ucfirst( $license['subscription_type'] ) ); ?></td></tr>
                </table>
                <?php if ( ! empty( $data['renewal_url'] ) ) : ?>
                    <p><a href="<?php echo esc_url( $data['renewal_url'] ); ?>" class="btn"><?php esc_html_e( 'Renew Now', 'dlmues-server' ); ?></a></p>
                <?php endif; ?>
                <?php
                break;

            case 'grace_period':
                ?>
                <h3><?php esc_html_e( 'Grace Period Active - Action Required', 'dlmues-server' ); ?></h3>
                <p>
                    <?php
                    printf(
                        /* translators: 1: product name, 2: days remaining */
                        esc_html__( 'Your license for %1$s has expired. You are now in the grace period with %2$d day(s) remaining before enforcement begins.', 'dlmues-server' ),
                        '<strong>' . esc_html( $data['product_name'] ) . '</strong>',
                        absint( $data['days_remaining'] )
                    );
                    ?>
                </p>
                <table class="info-table">
                    <tr><td><?php esc_html_e( 'License Key', 'dlmues-server' ); ?></td><td><?php echo esc_html( $masked_key ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'Grace Period Remaining', 'dlmues-server' ); ?></td><td><?php echo absint( $data['days_remaining'] ); ?> <?php esc_html_e( 'days', 'dlmues-server' ); ?></td></tr>
                </table>
                <?php if ( ! empty( $data['renewal_url'] ) ) : ?>
                    <p><a href="<?php echo esc_url( $data['renewal_url'] ); ?>" class="btn btn-urgent"><?php esc_html_e( 'Renew Immediately', 'dlmues-server' ); ?></a></p>
                <?php endif; ?>
                <?php
                break;

            case 'payment_confirmation':
                $payment = $data['payment_data'];
                ?>
                <h3><?php esc_html_e( 'Payment Confirmed', 'dlmues-server' ); ?></h3>
                <p>
                    <?php
                    printf(
                        /* translators: %s: product name */
                        esc_html__( 'Your payment has been received and your license for %s has been renewed.', 'dlmues-server' ),
                        '<strong>' . esc_html( $data['product_name'] ) . '</strong>'
                    );
                    ?>
                </p>
                <table class="info-table">
                    <tr><td><?php esc_html_e( 'Amount', 'dlmues-server' ); ?></td><td><?php echo esc_html( $payment['currency'] . ' ' . number_format( $payment['amount'], 2 ) ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'Reference', 'dlmues-server' ); ?></td><td><?php echo esc_html( $payment['reference'] ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'Plan', 'dlmues-server' ); ?></td><td><?php echo esc_html( ucfirst( $payment['plan'] ) ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'New Expiry', 'dlmues-server' ); ?></td><td><?php echo esc_html( $license['expires_at'] ); ?></td></tr>
                </table>
                <?php
                break;
        }

        ?>
            </div>
            <div class="footer">
                <p><?php echo esc_html( $site_name ); ?> &mdash; <?php esc_html_e( 'License Management System', 'dlmues-server' ); ?></p>
            </div>
        </div>
        </body>
        </html>
        <?php

        return ob_get_clean();
    }

    /**
     * Get the product display name for a license.
     *
     * @param array $license The license data.
     * @return string The product name.
     */
    private function get_product_name( $license ) {
        if ( ! empty( $license['product_slug'] ) ) {
            global $wpdb;
            $name = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT product_name FROM {$wpdb->prefix}dlmues_products WHERE product_slug = %s",
                    $license['product_slug']
                )
            );
            if ( $name ) {
                return $name;
            }
        }
        return $license['product_slug'] ? $license['product_slug'] : __( 'Licensed Product', 'dlmues-server' );
    }

    /**
     * Build a renewal URL for a license.
     *
     * @param array $license The license data.
     * @return string The renewal URL.
     */
    private function get_renewal_url( $license ) {
        return add_query_arg(
            array(
                'action'      => 'renew',
                'license_key' => rawurlencode( $license['license_key'] ),
            ),
            rest_url( 'dlmues/v1/payment/initialize' )
        );
    }
}
