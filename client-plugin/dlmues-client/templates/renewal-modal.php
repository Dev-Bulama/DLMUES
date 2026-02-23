<?php
/**
 * Renewal Modal Template for DLMUES Client.
 *
 * Displays a full-screen modal overlay on admin pages
 * when the license is expired past grace period.
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
?>

<div id="dlmues-enforcement-modal" style="
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.85);
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
">
    <div style="
        background: #fff;
        max-width: 500px;
        width: 90%;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    ">
        <div style="background: #dc3232; color: #fff; padding: 25px 30px; text-align: center;">
            <div style="font-size: 48px; margin-bottom: 10px;">&#9888;</div>
            <h2 style="margin: 0; font-size: 22px; font-weight: 600;">
                <?php esc_html_e( 'License Expired', 'dlmues-client' ); ?>
            </h2>
        </div>

        <div style="padding: 30px;">
            <p style="font-size: 15px; line-height: 1.6; color: #333; margin-top: 0;">
                <?php
                printf(
                    /* translators: %s: product name */
                    esc_html__( 'Your license for %s has expired. Please renew your license to continue using this product and accessing admin features.', 'dlmues-client' ),
                    '<strong>' . esc_html( $product_name ) . '</strong>'
                );
                ?>
            </p>

            <?php if ( ! empty( $license_data['expires_at'] ) ) : ?>
                <p style="font-size: 13px; color: #666; background: #f8f8f8; padding: 10px 15px; border-radius: 4px;">
                    <strong><?php esc_html_e( 'Expired:', 'dlmues-client' ); ?></strong>
                    <?php echo esc_html( $license_data['expires_at'] ); ?>
                </p>
            <?php endif; ?>

            <div style="margin-top: 25px; text-align: center;">
                <a href="<?php echo esc_url( $settings_url ); ?>" style="
                    display: inline-block;
                    padding: 12px 30px;
                    background: #0073aa;
                    color: #fff;
                    text-decoration: none;
                    border-radius: 4px;
                    font-size: 15px;
                    font-weight: 600;
                    margin-bottom: 10px;
                ">
                    <?php esc_html_e( 'Renew License', 'dlmues-client' ); ?>
                </a>

                <p style="font-size: 12px; color: #999; margin-top: 15px;">
                    <?php esc_html_e( 'You can still access the license settings page to manage your license.', 'dlmues-client' ); ?>
                </p>
            </div>
        </div>
    </div>
</div>
