<?php
/**
 * Deactivation Guard for DLMUES Client.
 *
 * When the license server sets allow_deactivation = false for this license,
 * this class:
 *   1. Removes the "Deactivate" action link from the Plugins page.
 *   2. Intercepts direct URL-based deactivation attempts and blocks them.
 *
 * The allow_deactivation flag is synced from the server on every license
 * validation (hourly cron or manual refresh).
 *
 * @package DLMUES_Client
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Deactivation_Guard
 */
class DLMUES_Deactivation_Guard {

    /**
     * Option prefix.
     *
     * @var string
     */
    private $prefix;

    /**
     * Constructor — register hooks.
     */
    public function __construct() {
        $this->prefix = defined( 'DLMUES_CLIENT_OPTION_PREFIX' ) ? DLMUES_CLIENT_OPTION_PREFIX : 'dlmues_client_';

        // Remove "Deactivate" link on the Plugins screen.
        add_filter(
            'plugin_action_links_' . DLMUES_CLIENT_BASENAME,
            array( $this, 'filter_plugin_action_links' )
        );

        // Block URL-based deactivation on admin_init (runs before the page renders).
        add_action( 'admin_init', array( $this, 'block_deactivation_request' ), 1 );
    }

    /**
     * Check whether deactivation is currently prevented for this client.
     *
     * allow_deactivation = 1 → client CAN deactivate (default/normal).
     * allow_deactivation = 0 → client CANNOT deactivate (guard active).
     *
     * @return bool True if deactivation is prevented.
     */
    private function is_deactivation_prevented() {
        return ! (bool) get_option( $this->prefix . 'allow_deactivation', 1 );
    }

    /**
     * Remove the "Deactivate" link from the Plugins list when guard is active.
     *
     * @param array $actions Plugin action links.
     * @return array Filtered action links.
     */
    public function filter_plugin_action_links( $actions ) {
        if ( $this->is_deactivation_prevented() ) {
            unset( $actions['deactivate'] );
        }
        return $actions;
    }

    /**
     * Block direct deactivation via URL (plugins.php?action=deactivate&plugin=...).
     *
     * Fires on admin_init before WordPress processes the deactivation request.
     */
    public function block_deactivation_request() {
        if ( ! $this->is_deactivation_prevented() ) {
            return;
        }

        // Only intercept deactivation action.
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
        if ( 'deactivate' !== $action ) {
            return;
        }

        // Only block our own plugin slug.
        $plugin = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) : '';
        if ( $plugin !== DLMUES_CLIENT_BASENAME ) {
            return;
        }

        wp_die(
            esc_html__(
                'Deactivation of the DLMUES License Client plugin has been disabled by your license administrator. Please contact support if you believe this is an error.',
                'dlmues-client'
            ),
            esc_html__( 'Plugin Deactivation Blocked', 'dlmues-client' ),
            array(
                'response'  => 403,
                'back_link' => true,
            )
        );
    }
}
