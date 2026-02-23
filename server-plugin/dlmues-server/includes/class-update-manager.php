<?php
/**
 * Update Manager for DLMUES License Server.
 *
 * Manages update packages, version checks, and download distribution
 * for licensed plugins and themes.
 *
 * @package DLMUES_Server
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Update_Manager
 */
class DLMUES_Update_Manager {

    /**
     * Products table name (without prefix).
     *
     * @var string
     */
    private $table = 'dlmues_products';

    /**
     * Check if an update is available for a product.
     *
     * @param string $product_slug    The product slug.
     * @param string $current_version The currently installed version.
     * @param string $license_key     The license key (for access verification).
     * @return array|false|WP_Error Update data, false if no update, or WP_Error.
     */
    public function check_for_update( $product_slug, $current_version, $license_key = '' ) {
        $product = $this->get_product( $product_slug );

        if ( ! $product ) {
            return false;
        }

        // Compare versions.
        if ( version_compare( $current_version, $product['current_version'], '>=' ) ) {
            return false;
        }

        // Build download URL.
        $download_url = $this->get_download_url( $product_slug, $license_key );

        return array(
            'slug'         => $product['product_slug'],
            'new_version'  => $product['current_version'],
            'package'      => $download_url,
            'url'          => home_url(),
            'tested'       => get_option( 'dlmues_tested_wp_version', '' ),
            'requires'     => get_option( 'dlmues_requires_wp_version', '5.8' ),
            'requires_php' => get_option( 'dlmues_requires_php_version', '7.4' ),
            'plugin_file'  => $product['product_slug'] . '/' . $product['product_slug'] . '.php',
            'name'         => $product['product_name'],
        );
    }

    /**
     * Get product information for plugin details popup.
     *
     * @param string $product_slug The product slug.
     * @return array|WP_Error Product information or WP_Error.
     */
    public function get_product_info( $product_slug ) {
        $product = $this->get_product( $product_slug );

        if ( ! $product ) {
            return new WP_Error( 'product_not_found', __( 'Product not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        return array(
            'name'         => $product['product_name'],
            'slug'         => $product['product_slug'],
            'version'      => $product['current_version'],
            'author'       => get_option( 'dlmues_author_name', get_bloginfo( 'name' ) ),
            'homepage'     => home_url(),
            'requires'     => get_option( 'dlmues_requires_wp_version', '5.8' ),
            'tested'       => get_option( 'dlmues_tested_wp_version', '' ),
            'requires_php' => get_option( 'dlmues_requires_php_version', '7.4' ),
            'last_updated' => $product['updated_at'],
            'sections'     => array(
                'description' => $product['description'],
                'changelog'   => $this->get_changelog( $product_slug ),
            ),
        );
    }

    /**
     * Get a product by its slug.
     *
     * @param string $product_slug The product slug.
     * @return array|null Product data or null.
     */
    public function get_product( $product_slug ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE product_slug = %s",
                sanitize_text_field( $product_slug )
            ),
            ARRAY_A
        );
    }

    /**
     * Create or update a product.
     *
     * @param array $data Product data.
     * @return int|WP_Error Product ID or WP_Error.
     */
    public function save_product( $data ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;
        $slug  = isset( $data['product_slug'] ) ? sanitize_text_field( $data['product_slug'] ) : '';

        if ( empty( $slug ) ) {
            return new WP_Error( 'missing_slug', __( 'Product slug is required.', 'dlmues-server' ) );
        }

        $existing = $this->get_product( $slug );

        $product_data = array(
            'product_slug'       => $slug,
            'product_name'       => isset( $data['product_name'] ) ? sanitize_text_field( $data['product_name'] ) : $slug,
            'product_type'       => isset( $data['product_type'] ) ? sanitize_text_field( $data['product_type'] ) : 'plugin',
            'current_version'    => isset( $data['current_version'] ) ? sanitize_text_field( $data['current_version'] ) : '1.0.0',
            'update_package_url' => isset( $data['update_package_url'] ) ? esc_url_raw( $data['update_package_url'] ) : '',
            'description'        => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
            'updated_at'         => current_time( 'mysql' ),
        );

        if ( $existing ) {
            $wpdb->update(
                $table,
                $product_data,
                array( 'id' => $existing['id'] ),
                null,
                array( '%d' )
            );
            return absint( $existing['id'] );
        }

        $product_data['created_at'] = current_time( 'mysql' );
        $wpdb->insert( $table, $product_data );
        return $wpdb->insert_id;
    }

    /**
     * Get all products.
     *
     * @return array List of products.
     */
    public function get_all_products() {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;

        $results = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY product_name ASC",
            ARRAY_A
        );

        return $results ? $results : array();
    }

    /**
     * Delete a product.
     *
     * @param int $product_id The product ID.
     * @return bool True on success.
     */
    public function delete_product( $product_id ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;

        return false !== $wpdb->delete(
            $table,
            array( 'id' => absint( $product_id ) ),
            array( '%d' )
        );
    }

    /**
     * Handle update package upload.
     *
     * @param array  $file         The uploaded file ($_FILES entry).
     * @param string $product_slug The product slug.
     * @return string|WP_Error The file path or WP_Error.
     */
    public function upload_package( $file, $product_slug ) {
        if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
            return new WP_Error( 'upload_failed', __( 'File upload failed.', 'dlmues-server' ) );
        }

        // Validate file type.
        $file_type = wp_check_filetype( $file['name'], array( 'zip' => 'application/zip' ) );

        if ( ! $file_type['type'] ) {
            return new WP_Error( 'invalid_type', __( 'Only ZIP files are allowed.', 'dlmues-server' ) );
        }

        $packages_dir = $this->get_packages_directory();

        if ( ! file_exists( $packages_dir ) ) {
            wp_mkdir_p( $packages_dir );
        }

        $filename   = sanitize_file_name( $product_slug . '.zip' );
        $dest_path  = $packages_dir . '/' . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
            return new WP_Error( 'move_failed', __( 'Failed to move uploaded file.', 'dlmues-server' ) );
        }

        return $dest_path;
    }

    /**
     * Get the file path for a product's update package.
     *
     * @param string $product_slug The product slug.
     * @return string|false The file path or false if not found.
     */
    public function get_package_path( $product_slug ) {
        $product = $this->get_product( $product_slug );

        // Check for custom URL first.
        if ( $product && ! empty( $product['update_package_url'] ) ) {
            return $product['update_package_url'];
        }

        // Check local file.
        $packages_dir = $this->get_packages_directory();
        $file_path    = $packages_dir . '/' . sanitize_file_name( $product_slug ) . '.zip';

        if ( file_exists( $file_path ) ) {
            return $file_path;
        }

        return false;
    }

    /**
     * Build the download URL for an update package.
     *
     * @param string $product_slug The product slug.
     * @param string $license_key  The license key.
     * @return string The download URL.
     */
    private function get_download_url( $product_slug, $license_key ) {
        $product = $this->get_product( $product_slug );

        // If product has an external package URL, use it.
        if ( $product && ! empty( $product['update_package_url'] ) ) {
            return add_query_arg(
                array(
                    'license_key' => rawurlencode( $license_key ),
                ),
                $product['update_package_url']
            );
        }

        // Build internal download URL.
        return add_query_arg(
            array(
                'product_slug' => rawurlencode( $product_slug ),
                'license_key'  => rawurlencode( $license_key ),
            ),
            rest_url( 'dlmues/v1/update/download' )
        );
    }

    /**
     * Get the packages storage directory.
     *
     * @return string The directory path.
     */
    private function get_packages_directory() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/dlmues-packages';
    }

    /**
     * Get the changelog for a product.
     *
     * @param string $product_slug The product slug.
     * @return string The changelog HTML.
     */
    private function get_changelog( $product_slug ) {
        $changelog = get_option( 'dlmues_changelog_' . sanitize_key( $product_slug ), '' );

        if ( ! empty( $changelog ) ) {
            return wp_kses_post( $changelog );
        }

        return '<p>' . esc_html__( 'No changelog available.', 'dlmues-server' ) . '</p>';
    }
}
