<?php
/**
 * Site Health Reporter for DLMUES Client.
 *
 * Collects and reports site health data to the license server,
 * including WordPress version, PHP version, active plugins/theme,
 * and visitor statistics.
 *
 * @package DLMUES_Client
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Site_Health_Reporter
 */
class DLMUES_Site_Health_Reporter {

    /**
     * License client instance.
     *
     * @var DLMUES_License_Client
     */
    private $license_client;

    /**
     * Constructor.
     *
     * @param DLMUES_License_Client $license_client The license client instance.
     */
    public function __construct( DLMUES_License_Client $license_client ) {
        $this->license_client = $license_client;

        // Register cron hook.
        add_action( 'dlmues_health_report', array( $this, 'send_health_report' ) );
    }

    /**
     * Schedule the health report cron event.
     */
    public function schedule_reporting() {
        if ( ! wp_next_scheduled( 'dlmues_health_report' ) ) {
            wp_schedule_event( time(), 'daily', 'dlmues_health_report' );
        }
    }

    /**
     * Track a visitor on the frontend.
     *
     * Called on template_redirect to count unique daily visitors.
     */
    public function track_visitor() {
        // Skip admin pages, AJAX, and cron.
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        // Skip bots.
        if ( $this->is_bot() ) {
            return;
        }

        $today = gmdate( 'Y-m-d' );
        $counts = get_option( $this->license_client->get_prefix() . 'visitor_counts', array() );

        if ( ! is_array( $counts ) ) {
            $counts = array();
        }

        // Increment today's count.
        if ( ! isset( $counts[ $today ] ) ) {
            $counts[ $today ] = 0;
        }
        $counts[ $today ]++;

        // Keep only last 30 days.
        $cutoff = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        foreach ( $counts as $date => $count ) {
            if ( $date < $cutoff ) {
                unset( $counts[ $date ] );
            }
        }

        update_option( $this->license_client->get_prefix() . 'visitor_counts', $counts, false );
    }

    /**
     * Send a health report to the license server.
     *
     * @return array|WP_Error Server response or WP_Error.
     */
    public function send_health_report() {
        $license_key = get_option( $this->license_client->get_prefix() . 'license_key', '' );

        if ( empty( $license_key ) ) {
            return new WP_Error( 'no_license', __( 'No license configured.', 'dlmues-client' ) );
        }

        $report_data = $this->collect_health_data();

        $response = $this->license_client->api_request( 'health/report', 'POST', array_merge(
            $report_data,
            array( 'license_key' => $license_key )
        ) );

        return $response;
    }

    /**
     * Collect site health data.
     *
     * @return array The health data.
     */
    public function collect_health_data() {
        $active_theme = wp_get_theme();
        $plugins      = $this->get_active_plugins_list();
        $visitor_count = $this->get_total_visitor_count();

        return array(
            'wp_version'      => get_bloginfo( 'version' ),
            'php_version'     => phpversion(),
            'active_theme'    => $active_theme->get( 'Name' ) . ' ' . $active_theme->get( 'Version' ),
            'plugin_list'     => $plugins,
            'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
            'site_url'        => home_url(),
            'visitor_count'   => $visitor_count,
            'all_themes'      => $this->get_all_themes_list(),
        );
    }

    /**
     * Get a list of all installed themes with their details.
     *
     * @return array List of themes with name, version, and active status.
     */
    private function get_all_themes_list() {
        $all_themes   = wp_get_themes();
        $active_theme = wp_get_theme();
        $theme_list   = array();

        foreach ( $all_themes as $theme_slug => $theme ) {
            $theme_list[] = array(
                'name'    => $theme->get( 'Name' ),
                'version' => $theme->get( 'Version' ),
                'active'  => ( $theme->get( 'Name' ) === $active_theme->get( 'Name' ) ),
            );
        }

        return $theme_list;
    }

    /**
     * Get a list of active plugins with versions.
     *
     * @return array List of active plugin names and versions.
     */
    private function get_active_plugins_list() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );
        $plugin_list    = array();

        foreach ( $active_plugins as $plugin_file ) {
            if ( isset( $all_plugins[ $plugin_file ] ) ) {
                $plugin_list[] = array(
                    'name'    => $all_plugins[ $plugin_file ]['Name'],
                    'version' => $all_plugins[ $plugin_file ]['Version'],
                );
            }
        }

        return $plugin_list;
    }

    /**
     * Get the total visitor count over the last 30 days.
     *
     * @return int Total visitor count.
     */
    public function get_total_visitor_count() {
        $counts = get_option( $this->license_client->get_prefix() . 'visitor_counts', array() );

        if ( ! is_array( $counts ) ) {
            return 0;
        }

        return array_sum( $counts );
    }

    /**
     * Get the visitor count for today.
     *
     * @return int Today's visitor count.
     */
    public function get_today_visitor_count() {
        $counts = get_option( $this->license_client->get_prefix() . 'visitor_counts', array() );
        $today  = gmdate( 'Y-m-d' );

        return isset( $counts[ $today ] ) ? absint( $counts[ $today ] ) : 0;
    }

    /**
     * Get the visitor counts for the last N days (for charting).
     *
     * @param int $days Number of days.
     * @return array Associative array of date => count.
     */
    public function get_visitor_history( $days = 30 ) {
        $counts  = get_option( $this->license_client->get_prefix() . 'visitor_counts', array() );
        $history = array();

        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $date = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
            $history[ $date ] = isset( $counts[ $date ] ) ? absint( $counts[ $date ] ) : 0;
        }

        return $history;
    }

    /**
     * Check if the current request is from a bot.
     *
     * @return bool True if likely a bot.
     */
    private function is_bot() {
        if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
            return true;
        }

        $user_agent = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) );

        $bot_patterns = array(
            'bot', 'crawl', 'spider', 'slurp', 'mediapartners',
            'facebookexternalhit', 'twitterbot', 'linkedinbot',
            'pingdom', 'uptimerobot', 'monitoring',
        );

        foreach ( $bot_patterns as $pattern ) {
            if ( strpos( $user_agent, $pattern ) !== false ) {
                return true;
            }
        }

        return false;
    }
}
