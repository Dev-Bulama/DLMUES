<?php
/**
 * Renewal Modal Template for DLMUES Client.
 *
 * Displays a full-screen modal overlay on admin pages
 * when the license is expired past grace period. Shows
 * renewal plan, expiry date, server domain, renewal amount,
 * and time remaining.
 *
 * @package DLMUES_Client
 * @since   1.0.0
 *
 * Variables available:
 * @var string $settings_url   URL to the DLMUES settings page.
 * @var string $product_name   The product name.
 * @var array  $license_data   All license data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$server_url        = isset( $license_data['server_url'] ) ? preg_replace( '#^https?://#', '', rtrim( $license_data['server_url'], '/' ) ) : '';
$subscription_type = isset( $license_data['subscription_type'] ) ? ucfirst( $license_data['subscription_type'] ) : '';
$currency          = isset( $license_data['currency'] ) ? $license_data['currency'] : 'USD';
$price             = isset( $license_data['price'] ) ? number_format( (float) $license_data['price'], 2 ) : '0.00';
$expires_at        = isset( $license_data['expires_at'] ) ? $license_data['expires_at'] : '';
$custom_amount     = isset( $license_data['custom_renewal_amount'] ) ? floatval( $license_data['custom_renewal_amount'] ) : 0;
$display_amount    = $custom_amount > 0 ? number_format( $custom_amount, 2 ) : $price;
?>

<div id="dlmues-enforcement-modal">
    <div class="dlmues-enforcement-card">
        <div class="dlmues-enforcement-header">
            <span class="dlmues-icon">&#9888;</span>
            <h2><?php esc_html_e( 'License Expired', 'dlmues-client' ); ?></h2>
        </div>

        <div class="dlmues-enforcement-body">
            <p>
                <?php
                printf(
                    /* translators: %s: product name */
                    esc_html__( 'Your license for %s has expired. Please renew your license to continue using this product and accessing admin features.', 'dlmues-client' ),
                    '<strong>' . esc_html( $product_name ) . '</strong>'
                );
                ?>
            </p>

            <div class="dlmues-enforcement-info">
                <table>
                    <?php if ( ! empty( $subscription_type ) ) : ?>
                        <tr>
                            <td><?php esc_html_e( 'Renewal Plan', 'dlmues-client' ); ?></td>
                            <td><?php echo esc_html( $subscription_type ); ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if ( ! empty( $expires_at ) ) : ?>
                        <tr>
                            <td><?php esc_html_e( 'Expiry Date', 'dlmues-client' ); ?></td>
                            <td><?php echo esc_html( $expires_at ); ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if ( ! empty( $server_url ) ) : ?>
                        <tr>
                            <td><?php esc_html_e( 'License Server', 'dlmues-client' ); ?></td>
                            <td><?php echo esc_html( $server_url ); ?></td>
                        </tr>
                    <?php endif; ?>

                    <tr>
                        <td><?php esc_html_e( 'Renewal Amount', 'dlmues-client' ); ?></td>
                        <td><strong><?php echo esc_html( $currency . ' ' . $display_amount ); ?></strong></td>
                    </tr>
                </table>
            </div>

            <div class="dlmues-enforcement-actions">
                <button type="button" id="dlmues-open-payment" class="dlmues-enforce-renew-btn">
                    <?php esc_html_e( 'Renew License Now', 'dlmues-client' ); ?>
                </button>
                <a href="<?php echo esc_url( $settings_url ); ?>" class="button button-small" style="margin-left:10px;">
                    <?php esc_html_e( 'Go to Settings', 'dlmues-client' ); ?>
                </a>
            </div>
            <div id="dlmues-payment-overlay" class="dlmues-payment-overlay"></div>

            <p class="dlmues-enforcement-hint">
                <?php esc_html_e( 'You can still access the license settings page to manage your license.', 'dlmues-client' ); ?>
            </p>
        </div>
    </div>
</div>
