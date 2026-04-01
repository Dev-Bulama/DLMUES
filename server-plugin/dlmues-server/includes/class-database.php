<?php
/**
 * Database handler for DLMUES License Server.
 *
 * Manages table creation, upgrades, and schema management.
 *
 * @package DLMUES_Server
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Database
 *
 * Handles all database table creation and upgrades using dbDelta.
 */
class DLMUES_Database {

    /**
     * WordPress database object.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Table prefix.
     *
     * @var string
     */
    private $prefix;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb   = $wpdb;
        $this->prefix  = $wpdb->prefix;
    }

    /**
     * Get full table name.
     *
     * @param string $table Short table name without prefix.
     * @return string Full table name.
     */
    public function get_table( $table ) {
        return $this->prefix . $table;
    }

    /**
     * Create all plugin tables.
     *
     * Uses dbDelta for safe table creation and upgrades.
     */
    public function create_tables() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $this->wpdb->get_charset_collate();

        $this->create_licenses_table( $charset_collate );
        $this->create_payments_table( $charset_collate );
        $this->create_products_table( $charset_collate );
        $this->create_site_health_table( $charset_collate );
        $this->create_invoices_table( $charset_collate );
        $this->create_coupons_table( $charset_collate );
        $this->create_api_tokens_table( $charset_collate );

        $this->maybe_upgrade();
    }

    /**
     * Create the dlmues_licenses table.
     *
     * @param string $charset_collate Database charset and collation.
     */
    private function create_licenses_table( $charset_collate ) {
        $table_name = $this->get_table( 'dlmues_licenses' );

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            license_key varchar(255) NOT NULL,
            client_domain varchar(255) DEFAULT '',
            client_email varchar(255) DEFAULT '',
            product_slug varchar(255) DEFAULT '',
            product_type varchar(20) DEFAULT 'plugin',
            status varchar(20) DEFAULT 'active',
            subscription_type varchar(20) DEFAULT 'monthly',
            price decimal(10,2) DEFAULT 0.00,
            currency varchar(10) DEFAULT 'USD',
            created_at datetime DEFAULT '0000-00-00 00:00:00',
            expires_at datetime DEFAULT '0000-00-00 00:00:00',
            grace_period_days int(11) DEFAULT 7,
            last_payment_date datetime DEFAULT '0000-00-00 00:00:00',
            last_check_in datetime DEFAULT '0000-00-00 00:00:00',
            visitor_count bigint(20) unsigned DEFAULT 0,
            injected_visitor_count bigint(20) unsigned DEFAULT 0,
            enforcement_mode varchar(30) DEFAULT 'restrict_admin',
            notes text DEFAULT '',
            trial_days int(11) DEFAULT 0,
            trial_used tinyint(1) DEFAULT 0,
            custom_renewal_amount decimal(10,2) DEFAULT NULL,
            allow_deactivation tinyint(1) DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY license_key (license_key),
            KEY status (status),
            KEY product_slug (product_slug),
            KEY client_email (client_email),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create the dlmues_payments table.
     *
     * @param string $charset_collate Database charset and collation.
     */
    private function create_payments_table( $charset_collate ) {
        $table_name  = $this->get_table( 'dlmues_payments' );
        $license_tbl = $this->get_table( 'dlmues_licenses' );

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            license_id bigint(20) unsigned NOT NULL DEFAULT 0,
            amount decimal(10,2) DEFAULT 0.00,
            currency varchar(10) DEFAULT 'USD',
            payment_reference varchar(255) DEFAULT '',
            payment_provider varchar(50) DEFAULT 'paystack',
            status varchar(20) DEFAULT 'pending',
            plan_duration varchar(20) DEFAULT '',
            created_at datetime DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY license_id (license_id),
            KEY payment_reference (payment_reference),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create the dlmues_products table.
     *
     * @param string $charset_collate Database charset and collation.
     */
    private function create_products_table( $charset_collate ) {
        $table_name = $this->get_table( 'dlmues_products' );

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_slug varchar(255) NOT NULL,
            product_name varchar(255) DEFAULT '',
            product_type varchar(20) DEFAULT 'plugin',
            current_version varchar(50) DEFAULT '1.0.0',
            update_package_url varchar(500) DEFAULT '',
            description text DEFAULT '',
            created_at datetime DEFAULT '0000-00-00 00:00:00',
            updated_at datetime DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY product_slug (product_slug)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create the dlmues_site_health table.
     *
     * @param string $charset_collate Database charset and collation.
     */
    private function create_site_health_table( $charset_collate ) {
        $table_name  = $this->get_table( 'dlmues_site_health' );

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            license_id bigint(20) unsigned NOT NULL DEFAULT 0,
            wp_version varchar(20) DEFAULT '',
            active_theme varchar(255) DEFAULT '',
            plugin_list longtext DEFAULT '',
            php_version varchar(20) DEFAULT '',
            server_software varchar(255) DEFAULT '',
            all_themes longtext DEFAULT '',
            last_reported datetime DEFAULT '0000-00-00 00:00:00',
            site_url varchar(500) DEFAULT '',
            PRIMARY KEY  (id),
            KEY license_id (license_id)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create the dlmues_invoices table.
     *
     * @param string $charset_collate Database charset and collation.
     */
    private function create_invoices_table( $charset_collate ) {
        $table_name = $this->get_table( 'dlmues_invoices' );

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            license_id bigint(20) unsigned NOT NULL DEFAULT 0,
            invoice_number varchar(100) NOT NULL,
            invoice_data longtext DEFAULT '',
            created_at datetime DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY invoice_number (invoice_number),
            KEY payment_id (payment_id),
            KEY license_id (license_id)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create the dlmues_coupons table.
     *
     * @param string $charset_collate Database charset and collation.
     */
    private function create_coupons_table( $charset_collate ) {
        $table_name = $this->get_table( 'dlmues_coupons' );

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(100) NOT NULL,
            discount_type varchar(20) DEFAULT 'percentage',
            discount_value decimal(10,2) DEFAULT 0.00,
            max_uses int(11) DEFAULT 0,
            used_count int(11) DEFAULT 0,
            valid_from datetime DEFAULT '0000-00-00 00:00:00',
            valid_until datetime DEFAULT '0000-00-00 00:00:00',
            created_at datetime DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create the dlmues_api_tokens table.
     *
     * @param string $charset_collate Database charset and collation.
     */
    private function create_api_tokens_table( $charset_collate ) {
        $table_name = $this->get_table( 'dlmues_api_tokens' );

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash varchar(255) NOT NULL,
            license_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT '0000-00-00 00:00:00',
            expires_at datetime DEFAULT NULL,
            last_used datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY token_hash (token_hash),
            KEY license_id (license_id)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Handle database upgrades.
     *
     * Compares stored DB version against current and runs migrations.
     */
    private function maybe_upgrade() {
        $installed_version = get_option( 'dlmues_server_db_version', '0.0.0' );

        if ( version_compare( $installed_version, DLMUES_SERVER_DB_VERSION, '<' ) ) {
            if ( version_compare( $installed_version, '1.1.0', '<' ) ) {
                $this->wpdb->query( "ALTER TABLE {$this->get_table('dlmues_licenses')} ADD COLUMN IF NOT EXISTS custom_renewal_amount decimal(10,2) DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->wpdb->query( "ALTER TABLE {$this->get_table('dlmues_site_health')} ADD COLUMN IF NOT EXISTS all_themes longtext DEFAULT ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }

            if ( version_compare( $installed_version, '1.2.0', '<' ) ) {
                $this->wpdb->query( "ALTER TABLE {$this->get_table('dlmues_licenses')} ADD COLUMN IF NOT EXISTS allow_deactivation tinyint(1) DEFAULT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }

            update_option( 'dlmues_server_db_version', DLMUES_SERVER_DB_VERSION );
        }
    }

    /**
     * Drop all plugin tables.
     *
     * Only used during uninstall.
     */
    public function drop_tables() {
        $tables = array(
            'dlmues_api_tokens',
            'dlmues_invoices',
            'dlmues_site_health',
            'dlmues_payments',
            'dlmues_coupons',
            'dlmues_products',
            'dlmues_licenses',
        );

        foreach ( $tables as $table ) {
            $table_name = $this->get_table( $table );
            $this->wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }

    /**
     * Get row count for a table.
     *
     * @param string $table Short table name.
     * @param string $where Optional WHERE clause conditions.
     * @param array  $args  Optional prepare arguments.
     * @return int Row count.
     */
    public function get_count( $table, $where = '', $args = array() ) {
        $table_name = $this->get_table( $table );
        $sql        = "SELECT COUNT(*) FROM {$table_name}";

        if ( ! empty( $where ) ) {
            $sql .= " WHERE {$where}";
        }

        if ( ! empty( $args ) ) {
            $sql = $this->wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        return (int) $this->wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Check if a table exists.
     *
     * @param string $table Short table name.
     * @return bool True if table exists.
     */
    public function table_exists( $table ) {
        $table_name = $this->get_table( $table );
        $result     = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $table_name
            )
        );
        return ( $result === $table_name );
    }
}
