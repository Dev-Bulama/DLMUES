<?php
/**
 * Update Interceptor for DLMUES.
 *
 * Intercepts WordPress default update system to manage updates
 * for licensed plugins and themes through the DLMUES server.
 *
 * @package DLMUES_Client
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Update_Interceptor
 *
 * Hooks into WordPress transient system to inject custom updates
 * and block unauthorized updates for managed products.
 */
class DLMUES_Update_Interceptor {

    /**
     * License client instance.
     *
     * @var DLMUES_License_Client
     */
    private $license_client;

    /**
     * Transient key for update check cache.
     *
     * @var string
     */
    private $update_cache_key = 'dlmues_update_check_cache';

    /**
     * Update cache duration in seconds (6 hours).
     *
     * @var int
     */
    private $update_cache_duration = 21600;

    /**
     * Constructor.
     *
     * @param DLMUES_License_Client $license_client The license client instance.
     */
    public function __construct( DLMUES_License_Client $license_client ) {
        $this->license_client = $license_client;
        $this->init_hooks();
    }

    /**
     * Register all WordPress hooks for update interception.
     */
    private function init_hooks() {
        // Intercept plugin update transient when it is being set.
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'intercept_plugin_updates' ), 10, 1 );

        // Intercept theme update transient when it is being set.
        add_filter( 'pre_set_site_transient_update_themes', array( $this, 'intercept_theme_updates' ), 10, 1 );

        // Modify plugin information for the details popup.
        add_filter( 'plugins_api', array( $this, 'modify_plugin_info' ), 20, 3 );

        // Filter the update transient when it is being read.
        add_filter( 'site_transient_update_plugins', array( $this, 'filter_plugin_transient' ), 10, 1 );
        add_filter( 'site_transient_update_themes', array( $this, 'filter_theme_transient' ), 10, 1 );
    }

    /**
     * Intercept plugin updates during transient set.
     *
     * Checks the DLMUES server for updates and injects them into the transient.
     *
     * @param object $transient The update_plugins transient data.
     * @return object Modified transient data.
     */
    public function intercept_plugin_updates( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) ) {
            return $transient;
        }

        // Avoid recursion.
        if ( did_action( 'dlmues_checking_plugin_updates' ) ) {
            return $transient;
        }
        do_action( 'dlmues_checking_plugin_updates' );

        $managed_products = $this->get_managed_products();

        if ( empty( $managed_products ) ) {
            return $transient;
        }

        foreach ( $managed_products as $product_slug ) {
            $update_data = $this->check_for_updates( $product_slug, 'plugin' );

            if ( $update_data && ! is_wp_error( $update_data ) ) {
                $transient = $this->inject_update( $transient, $update_data, 'plugin' );
            }
        }

        return $transient;
    }

    /**
     * Intercept theme updates during transient set.
     *
     * @param object $transient The update_themes transient data.
     * @return object Modified transient data.
     */
    public function intercept_theme_updates( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) ) {
            return $transient;
        }

        if ( did_action( 'dlmues_checking_theme_updates' ) ) {
            return $transient;
        }
        do_action( 'dlmues_checking_theme_updates' );

        $managed_products = $this->get_managed_products();

        if ( empty( $managed_products ) ) {
            return $transient;
        }

        foreach ( $managed_products as $product_slug ) {
            $update_data = $this->check_for_updates( $product_slug, 'theme' );

            if ( $update_data && ! is_wp_error( $update_data ) ) {
                $transient = $this->inject_update( $transient, $update_data, 'theme' );
            }
        }

        return $transient;
    }

    /**
     * Filter plugin transient when read to block unauthorized updates.
     *
     * @param object $transient The update_plugins transient data.
     * @return object Modified transient data.
     */
    public function filter_plugin_transient( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) ) {
            return $transient;
        }

        return $this->block_unauthorized_updates( $transient, 'plugin' );
    }

    /**
     * Filter theme transient when read to block unauthorized updates.
     *
     * @param object $transient The update_themes transient data.
     * @return object Modified transient data.
     */
    public function filter_theme_transient( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) ) {
            return $transient;
        }

        return $this->block_unauthorized_updates( $transient, 'theme' );
    }

    /**
     * Check the server for available updates for a product.
     *
     * Queries the server's /update/check endpoint with product information.
     *
     * @param string $product_slug   The product slug to check.
     * @param string $product_type   The product type (plugin or theme).
     * @return array|false|WP_Error Update data array, false if no update, or WP_Error.
     */
    public function check_for_updates( $product_slug = '', $product_type = 'plugin' ) {
        if ( empty( $product_slug ) ) {
            $product_slug = get_option( $this->license_client->get_prefix() . 'product_slug', '' );
        }

        if ( empty( $product_slug ) ) {
            return false;
        }

        // Check cache first.
        $cache_key  = $this->update_cache_key . '_' . md5( $product_slug . $product_type );
        $cached     = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $current_version = $this->get_current_version( $product_slug, $product_type );
        $license_key     = get_option( $this->license_client->get_prefix() . 'license_key', '' );

        $response = $this->license_client->api_request( 'update/check', 'POST', array(
            'product_slug'    => $product_slug,
            'product_type'    => $product_type,
            'current_version' => $current_version,
            'license_key'     => $license_key,
            'wp_version'      => get_bloginfo( 'version' ),
            'php_version'     => phpversion(),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // No update available.
        if ( empty( $response['new_version'] ) || version_compare( $current_version, $response['new_version'], '>=' ) ) {
            set_transient( $cache_key, false, $this->update_cache_duration );
            return false;
        }

        $update_data = array(
            'slug'         => isset( $response['slug'] ) ? sanitize_text_field( $response['slug'] ) : $product_slug,
            'new_version'  => sanitize_text_field( $response['new_version'] ),
            'package'      => isset( $response['package'] ) ? esc_url_raw( $response['package'] ) : '',
            'url'          => isset( $response['url'] ) ? esc_url_raw( $response['url'] ) : '',
            'tested'       => isset( $response['tested'] ) ? sanitize_text_field( $response['tested'] ) : '',
            'requires'     => isset( $response['requires'] ) ? sanitize_text_field( $response['requires'] ) : '',
            'requires_php' => isset( $response['requires_php'] ) ? sanitize_text_field( $response['requires_php'] ) : '',
            'product_type' => $product_type,
            'plugin_file'  => isset( $response['plugin_file'] ) ? sanitize_text_field( $response['plugin_file'] ) : '',
            'icons'        => isset( $response['icons'] ) && is_array( $response['icons'] ) ? array_map( 'esc_url_raw', $response['icons'] ) : array(),
            'banners'      => isset( $response['banners'] ) && is_array( $response['banners'] ) ? array_map( 'esc_url_raw', $response['banners'] ) : array(),
            'sections'     => isset( $response['sections'] ) && is_array( $response['sections'] ) ? $response['sections'] : array(),
        );

        // Cache the result.
        set_transient( $cache_key, $update_data, $this->update_cache_duration );

        return $update_data;
    }

    /**
     * Inject update information into a WordPress update transient.
     *
     * @param object $transient   The update transient object.
     * @param array  $update_data The update data to inject.
     * @param string $type        The product type (plugin or theme).
     * @return object The modified transient.
     */
    public function inject_update( $transient, $update_data, $type = 'plugin' ) {
        if ( empty( $update_data ) || ! is_array( $update_data ) ) {
            return $transient;
        }

        if ( ! isset( $transient->response ) ) {
            $transient->response = array();
        }

        if ( 'plugin' === $type ) {
            $plugin_file = $this->get_plugin_file( $update_data['slug'] );

            if ( empty( $plugin_file ) && ! empty( $update_data['plugin_file'] ) ) {
                $plugin_file = $update_data['plugin_file'];
            }

            if ( empty( $plugin_file ) ) {
                return $transient;
            }

            $plugin_update = (object) array(
                'slug'         => $update_data['slug'],
                'plugin'       => $plugin_file,
                'new_version'  => $update_data['new_version'],
                'package'      => $update_data['package'],
                'url'          => $update_data['url'],
                'tested'       => $update_data['tested'],
                'requires'     => $update_data['requires'],
                'requires_php' => $update_data['requires_php'],
                'icons'        => $update_data['icons'],
                'banners'      => $update_data['banners'],
            );

            $transient->response[ $plugin_file ] = $plugin_update;

        } elseif ( 'theme' === $type ) {
            $theme_slug = $update_data['slug'];

            $theme_update = array(
                'theme'        => $theme_slug,
                'new_version'  => $update_data['new_version'],
                'package'      => $update_data['package'],
                'url'          => $update_data['url'],
                'requires'     => $update_data['requires'],
                'requires_php' => $update_data['requires_php'],
            );

            $transient->response[ $theme_slug ] = $theme_update;
        }

        return $transient;
    }

    /**
     * Override the plugins_api for managed plugins.
     *
     * Provides custom plugin information for the update details popup.
     *
     * @param false|object|array $result The result object or array. Default false.
     * @param string             $action The type of information being requested.
     * @param object             $args   Plugin API arguments.
     * @return false|object The modified result or false to use default.
     */
    public function modify_plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) ) {
            return $result;
        }

        $managed_products = $this->get_managed_products();
        $product_slug     = get_option( $this->license_client->get_prefix() . 'product_slug', '' );

        // Check if this plugin is managed by DLMUES.
        if ( ! in_array( $args->slug, $managed_products, true ) && $args->slug !== $product_slug ) {
            return $result;
        }

        // Fetch plugin information from server.
        $response = $this->license_client->api_request( 'update/info', 'POST', array(
            'product_slug' => $args->slug,
            'license_key'  => get_option( $this->license_client->get_prefix() . 'license_key', '' ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $result;
        }

        $plugin_info = (object) array(
            'name'            => isset( $response['name'] ) ? sanitize_text_field( $response['name'] ) : $args->slug,
            'slug'            => $args->slug,
            'version'         => isset( $response['version'] ) ? sanitize_text_field( $response['version'] ) : '',
            'author'          => isset( $response['author'] ) ? wp_kses_post( $response['author'] ) : '',
            'author_profile'  => isset( $response['author_profile'] ) ? esc_url_raw( $response['author_profile'] ) : '',
            'homepage'        => isset( $response['homepage'] ) ? esc_url_raw( $response['homepage'] ) : '',
            'requires'        => isset( $response['requires'] ) ? sanitize_text_field( $response['requires'] ) : '',
            'tested'          => isset( $response['tested'] ) ? sanitize_text_field( $response['tested'] ) : '',
            'requires_php'    => isset( $response['requires_php'] ) ? sanitize_text_field( $response['requires_php'] ) : '',
            'last_updated'    => isset( $response['last_updated'] ) ? sanitize_text_field( $response['last_updated'] ) : '',
            'download_link'   => isset( $response['download_link'] ) ? esc_url_raw( $response['download_link'] ) : '',
            'trunk'           => isset( $response['download_link'] ) ? esc_url_raw( $response['download_link'] ) : '',
            'sections'        => array(),
            'banners'         => array(),
            'icons'           => array(),
        );

        // Process sections with proper sanitization.
        if ( isset( $response['sections'] ) && is_array( $response['sections'] ) ) {
            foreach ( $response['sections'] as $section_key => $section_content ) {
                $plugin_info->sections[ sanitize_key( $section_key ) ] = wp_kses_post( $section_content );
            }
        }

        if ( isset( $response['banners'] ) && is_array( $response['banners'] ) ) {
            $plugin_info->banners = array_map( 'esc_url_raw', $response['banners'] );
        }

        if ( isset( $response['icons'] ) && is_array( $response['icons'] ) ) {
            $plugin_info->icons = array_map( 'esc_url_raw', $response['icons'] );
        }

        return $plugin_info;
    }

    /**
     * Block unauthorized updates if license is expired.
     *
     * Removes updates from the transient for managed products when
     * the license is not valid, and adds an admin notice.
     *
     * @param object $transient The update transient object.
     * @param string $type      Product type (plugin or theme).
     * @return object The modified transient.
     */
    public function block_unauthorized_updates( $transient, $type = 'plugin' ) {
        if ( ! is_object( $transient ) || empty( $transient->response ) ) {
            return $transient;
        }

        // Only block if license is expired (not valid and not in grace period).
        if ( $this->license_client->is_license_valid() ) {
            return $transient;
        }

        // During grace period, allow updates but show a notice.
        if ( $this->license_client->is_in_grace_period() ) {
            $this->add_grace_period_update_notice();
            return $transient;
        }

        // License is expired; block updates for managed products.
        $managed_products = $this->get_managed_products();
        $product_slug     = get_option( $this->license_client->get_prefix() . 'product_slug', '' );

        if ( ! empty( $product_slug ) && ! in_array( $product_slug, $managed_products, true ) ) {
            $managed_products[] = $product_slug;
        }

        if ( 'plugin' === $type ) {
            foreach ( $transient->response as $plugin_file => $update_info ) {
                $slug = '';
                if ( is_object( $update_info ) && isset( $update_info->slug ) ) {
                    $slug = $update_info->slug;
                } elseif ( is_array( $update_info ) && isset( $update_info['slug'] ) ) {
                    $slug = $update_info['slug'];
                }

                if ( in_array( $slug, $managed_products, true ) ) {
                    // Remove the download package URL to prevent update.
                    if ( is_object( $update_info ) ) {
                        $update_info->package = '';
                        $transient->response[ $plugin_file ] = $update_info;
                    } elseif ( is_array( $update_info ) ) {
                        $update_info['package'] = '';
                        $transient->response[ $plugin_file ] = $update_info;
                    }
                }
            }
        } elseif ( 'theme' === $type ) {
            foreach ( $transient->response as $theme_slug => $update_info ) {
                if ( in_array( $theme_slug, $managed_products, true ) ) {
                    if ( is_array( $update_info ) ) {
                        $update_info['package'] = '';
                        $transient->response[ $theme_slug ] = $update_info;
                    }
                }
            }
        }

        $this->add_expired_update_notice();

        return $transient;
    }

    /**
     * Get the list of products managed by DLMUES.
     *
     * @return array List of product slugs.
     */
    public function get_managed_products() {
        $products = get_option( $this->license_client->get_prefix() . 'managed_products', array() );

        if ( ! is_array( $products ) ) {
            $products = array();
        }

        // Always include the primary product slug.
        $primary = get_option( $this->license_client->get_prefix() . 'product_slug', '' );
        if ( ! empty( $primary ) && ! in_array( $primary, $products, true ) ) {
            $products[] = $primary;
        }

        return $products;
    }

    /**
     * Get the current installed version of a product.
     *
     * @param string $product_slug The product slug.
     * @param string $product_type The product type (plugin or theme).
     * @return string The current version or '0.0.0' if not found.
     */
    private function get_current_version( $product_slug, $product_type = 'plugin' ) {
        if ( 'plugin' === $product_type ) {
            $plugin_file = $this->get_plugin_file( $product_slug );

            if ( empty( $plugin_file ) ) {
                return '0.0.0';
            }

            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

            if ( ! file_exists( $plugin_path ) ) {
                return '0.0.0';
            }

            $plugin_data = get_plugin_data( $plugin_path, false, false );

            return isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '0.0.0';

        } elseif ( 'theme' === $product_type ) {
            $theme = wp_get_theme( $product_slug );

            if ( $theme->exists() ) {
                return $theme->get( 'Version' );
            }
        }

        return '0.0.0';
    }

    /**
     * Get the plugin file path (relative to plugins directory) from a slug.
     *
     * @param string $slug The plugin slug.
     * @return string The plugin file path or empty string if not found.
     */
    private function get_plugin_file( $slug ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();

        // First, check direct match: slug/slug.php.
        $direct_match = $slug . '/' . $slug . '.php';
        if ( isset( $all_plugins[ $direct_match ] ) ) {
            return $direct_match;
        }

        // Search through all plugins for a matching text domain or slug in the path.
        foreach ( $all_plugins as $file => $data ) {
            $plugin_slug = dirname( $file );

            if ( '.' === $plugin_slug ) {
                $plugin_slug = basename( $file, '.php' );
            }

            if ( $plugin_slug === $slug ) {
                return $file;
            }

            if ( isset( $data['TextDomain'] ) && $data['TextDomain'] === $slug ) {
                return $file;
            }
        }

        return '';
    }

    /**
     * Add admin notice about grace period for updates.
     */
    private function add_grace_period_update_notice() {
        if ( ! has_action( 'admin_notices', array( $this, 'render_grace_period_update_notice' ) ) ) {
            add_action( 'admin_notices', array( $this, 'render_grace_period_update_notice' ) );
        }
    }

    /**
     * Render grace period update notice.
     */
    public function render_grace_period_update_notice() {
        $screen = get_current_screen();
        if ( ! $screen || ( 'update-core' !== $screen->id && 'plugins' !== $screen->id ) ) {
            return;
        }

        $time_remaining = $this->license_client->get_time_remaining();
        $renewal_url    = $this->license_client->get_renewal_url();
        $settings_url   = admin_url( 'options-general.php?page=dlmues-license' );

        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a> | <a href="%s">%s</a></p></div>',
            sprintf(
                /* translators: %s: human-readable time remaining */
                esc_html__( 'Your license is in the grace period. Updates will be blocked after the grace period ends. Time remaining: %s.', 'dlmues-client' ),
                esc_html( $time_remaining['human_readable'] )
            ),
            esc_url( $settings_url ),
            esc_html__( 'Renew License', 'dlmues-client' ),
            esc_url( $settings_url ),
            esc_html__( 'License Settings', 'dlmues-client' )
        );
    }

    /**
     * Add admin notice about expired license blocking updates.
     */
    private function add_expired_update_notice() {
        if ( ! has_action( 'admin_notices', array( $this, 'render_expired_update_notice' ) ) ) {
            add_action( 'admin_notices', array( $this, 'render_expired_update_notice' ) );
        }
    }

    /**
     * Render expired license update notice.
     */
    public function render_expired_update_notice() {
        $screen = get_current_screen();
        if ( ! $screen || ( 'update-core' !== $screen->id && 'plugins' !== $screen->id ) ) {
            return;
        }

        $settings_url = admin_url( 'options-general.php?page=dlmues-license' );

        printf(
            '<div class="notice notice-error"><p>%s <a href="%s" class="button button-primary">%s</a></p></div>',
            esc_html__( 'Your license has expired. Updates for licensed products are blocked until you renew.', 'dlmues-client' ),
            esc_url( $settings_url ),
            esc_html__( 'Renew Now', 'dlmues-client' )
        );
    }
}
