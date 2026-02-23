<?php
/**
 * Maintenance Page Template for DLMUES Client.
 *
 * Displayed on the frontend when enforcement mode is
 * 'lock_frontend' or 'maintenance' and the license is expired.
 *
 * @package DLMUES_Client
 * @since   1.0.0
 *
 * Variables available:
 * @var array $license_data All license data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$site_name = get_bloginfo( 'name' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html( $site_name ); ?> - <?php esc_html_e( 'Under Maintenance', 'dlmues-client' ); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;
            background: #f0f2f5;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .maintenance-container {
            max-width: 580px;
            width: 100%;
            text-align: center;
        }
        .maintenance-icon {
            width: 80px;
            height: 80px;
            background: #0073aa;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 30px;
        }
        .maintenance-icon svg {
            width: 40px;
            height: 40px;
            fill: #fff;
        }
        h1 {
            font-size: 28px;
            font-weight: 600;
            color: #1d2327;
            margin-bottom: 15px;
        }
        .maintenance-message {
            font-size: 16px;
            line-height: 1.7;
            color: #50575e;
            margin-bottom: 30px;
        }
        .site-name {
            display: inline-block;
            padding: 8px 20px;
            background: #fff;
            border-radius: 4px;
            font-size: 14px;
            color: #0073aa;
            font-weight: 600;
            border: 1px solid #ddd;
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="maintenance-icon">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z"/>
            </svg>
        </div>

        <h1><?php esc_html_e( 'We\'ll Be Back Soon', 'dlmues-client' ); ?></h1>

        <p class="maintenance-message">
            <?php esc_html_e( 'This site is currently undergoing scheduled maintenance. We apologize for the inconvenience and will be back online shortly.', 'dlmues-client' ); ?>
        </p>

        <div class="site-name">
            <?php echo esc_html( $site_name ); ?>
        </div>
    </div>
</body>
</html>
