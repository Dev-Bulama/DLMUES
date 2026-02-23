<?php
/**
 * License Engine for DLMUES License Server.
 *
 * Core business logic for license creation, validation, activation,
 * renewal, plan management, trials, coupons, and analytics.
 *
 * @package DLMUES_Server
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_License_Engine
 */
class DLMUES_License_Engine {

    /**
     * Licenses table name (without prefix).
     *
     * @var string
     */
    private $table = 'dlmues_licenses';

    /**
     * Generate a cryptographically secure license key.
     *
     * Format: DLMUES-XXXX-XXXX-XXXX-XXXX where X is an uppercase
     * alphanumeric character.
     *
     * @return string The generated license key.
     */
    public function generate_license_key() {
        $segments = array();

        for ( $i = 0; $i < 4; $i++ ) {
            $segment = '';
            for ( $j = 0; $j < 4; $j++ ) {
                $chars   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                $index   = random_int( 0, strlen( $chars ) - 1 );
                $segment .= $chars[ $index ];
            }
            $segments[] = $segment;
        }

        return 'DLMUES-' . implode( '-', $segments );
    }

    /**
     * Create a new license.
     *
     * @param array $data {
     *     License data.
     *
     *     @type string $client_email      Client email address.
     *     @type string $client_domain     Client domain (optional, bound on activation).
     *     @type string $product_slug      Product identifier.
     *     @type string $product_type      'plugin' or 'theme'. Default 'plugin'.
     *     @type string $subscription_type 'monthly', 'bimonthly', 'quarterly', 'yearly'. Default 'monthly'.
     *     @type float  $price             License price. Default from settings.
     *     @type string $currency          Currency code. Default 'USD'.
     *     @type string $enforcement_mode  Enforcement mode. Default from settings.
     *     @type int    $grace_period_days Grace period days. Default from settings.
     *     @type string $notes             Admin notes.
     * }
     * @return array|WP_Error License data including the key and API token, or WP_Error.
     */
    public function create_license( $data ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;

        // Generate unique license key.
        $license_key = $this->generate_license_key();

        // Ensure uniqueness.
        $attempts = 0;
        while ( $this->license_exists( $license_key ) && $attempts < 10 ) {
            $license_key = $this->generate_license_key();
            $attempts++;
        }

        if ( $attempts >= 10 ) {
            return new WP_Error( 'key_generation_failed', __( 'Failed to generate a unique license key.', 'dlmues-server' ) );
        }

        $subscription_type = isset( $data['subscription_type'] ) ? sanitize_text_field( $data['subscription_type'] ) : 'monthly';
        $now               = current_time( 'mysql' );
        $expires_at        = $this->calculate_expiry( $now, $subscription_type );

        $default_price = $this->get_plan_price( $subscription_type );

        $insert_data = array(
            'license_key'       => $license_key,
            'client_domain'     => isset( $data['client_domain'] ) ? sanitize_text_field( $data['client_domain'] ) : '',
            'client_email'      => isset( $data['client_email'] ) ? sanitize_email( $data['client_email'] ) : '',
            'product_slug'      => isset( $data['product_slug'] ) ? sanitize_text_field( $data['product_slug'] ) : '',
            'product_type'      => isset( $data['product_type'] ) ? sanitize_text_field( $data['product_type'] ) : 'plugin',
            'status'            => 'active',
            'subscription_type' => $subscription_type,
            'price'             => isset( $data['price'] ) ? floatval( $data['price'] ) : $default_price,
            'currency'          => isset( $data['currency'] ) ? sanitize_text_field( $data['currency'] ) : get_option( 'dlmues_currency', 'USD' ),
            'created_at'        => $now,
            'expires_at'        => $expires_at,
            'grace_period_days' => isset( $data['grace_period_days'] ) ? absint( $data['grace_period_days'] ) : absint( get_option( 'dlmues_default_grace_period', 7 ) ),
            'last_payment_date' => $now,
            'last_check_in'     => $now,
            'visitor_count'     => 0,
            'injected_visitor_count' => 0,
            'enforcement_mode'  => isset( $data['enforcement_mode'] ) ? sanitize_text_field( $data['enforcement_mode'] ) : get_option( 'dlmues_enforcement_mode', 'restrict_admin' ),
            'notes'             => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '',
            'trial_days'        => 0,
            'trial_used'        => 0,
        );

        $formats = array(
            '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%f', '%s', '%s', '%s', '%d', '%s', '%s',
            '%d', '%d', '%s', '%s', '%d', '%d',
        );

        $result = $wpdb->insert( $table, $insert_data, $formats );

        if ( false === $result ) {
            return new WP_Error( 'db_insert_failed', __( 'Failed to create license.', 'dlmues-server' ) );
        }

        $license_id = $wpdb->insert_id;

        // Generate and store API token.
        $security  = new DLMUES_Security();
        $api_token = $security->generate_api_token();
        $security->store_api_token( $api_token, $license_id );

        return array(
            'license_id'  => $license_id,
            'license_key' => $license_key,
            'api_token'   => $api_token,
            'status'      => 'active',
            'expires_at'  => $expires_at,
            'product_slug' => $insert_data['product_slug'],
        );
    }

    /**
     * Validate a license key and domain combination.
     *
     * @param string $license_key The license key to validate.
     * @param string $domain      The requesting domain.
     * @return array|WP_Error Validation result or WP_Error.
     */
    public function validate_license( $license_key, $domain ) {
        global $wpdb;

        $table   = $wpdb->prefix . $this->table;
        $license = $this->get_license( $license_key );

        if ( ! $license ) {
            return new WP_Error( 'invalid_license', __( 'License key not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        // Update last check-in.
        $wpdb->update(
            $table,
            array( 'last_check_in' => current_time( 'mysql' ) ),
            array( 'id' => $license['id'] ),
            array( '%s' ),
            array( '%d' )
        );

        // Check domain binding.
        if ( ! empty( $license['client_domain'] ) ) {
            $security = new DLMUES_Security();
            $normalized_domain = $security->sanitize_domain( $domain );
            $normalized_stored = $security->sanitize_domain( $license['client_domain'] );

            if ( $normalized_domain !== $normalized_stored ) {
                return new WP_Error(
                    'domain_mismatch',
                    __( 'License is not authorized for this domain.', 'dlmues-server' ),
                    array( 'status' => 403 )
                );
            }
        }

        // Check status.
        if ( 'suspended' === $license['status'] ) {
            return new WP_Error(
                'license_suspended',
                __( 'This license has been suspended.', 'dlmues-server' ),
                array(
                    'status'           => 403,
                    'enforcement_mode' => $license['enforcement_mode'],
                )
            );
        }

        // Check expiry.
        $now = current_time( 'timestamp' );
        $expiry = strtotime( $license['expires_at'] );

        if ( $expiry < $now ) {
            // Check grace period.
            $grace_end = $expiry + ( absint( $license['grace_period_days'] ) * DAY_IN_SECONDS );

            if ( $now <= $grace_end ) {
                $days_remaining = ceil( ( $grace_end - $now ) / DAY_IN_SECONDS );

                return array(
                    'valid'              => true,
                    'status'             => 'grace',
                    'enforcement_mode'   => $license['enforcement_mode'],
                    'grace_days_remaining' => $days_remaining,
                    'message'            => sprintf(
                        __( 'License expired. Grace period: %d day(s) remaining.', 'dlmues-server' ),
                        $days_remaining
                    ),
                    'expires_at'         => $license['expires_at'],
                    'product_slug'       => $license['product_slug'],
                    'subscription_type'  => $license['subscription_type'],
                );
            }

            // Grace period expired.
            $this->update_status( $license_key, 'expired' );

            return new WP_Error(
                'license_expired',
                __( 'License has expired and grace period has ended.', 'dlmues-server' ),
                array(
                    'status'           => 403,
                    'enforcement_mode' => $license['enforcement_mode'],
                )
            );
        }

        // Trial status check.
        if ( 'trial' === $license['status'] ) {
            $trial_end = strtotime( $license['created_at'] ) + ( absint( $license['trial_days'] ) * DAY_IN_SECONDS );
            $trial_remaining = ceil( ( $trial_end - $now ) / DAY_IN_SECONDS );

            if ( $trial_remaining <= 0 ) {
                $this->update_status( $license_key, 'expired' );

                return new WP_Error(
                    'trial_expired',
                    __( 'Trial period has ended.', 'dlmues-server' ),
                    array( 'status' => 403 )
                );
            }

            return array(
                'valid'             => true,
                'status'            => 'trial',
                'trial_days_remaining' => max( 0, $trial_remaining ),
                'message'           => sprintf(
                    __( 'Trial license: %d day(s) remaining.', 'dlmues-server' ),
                    $trial_remaining
                ),
                'expires_at'        => $license['expires_at'],
                'product_slug'      => $license['product_slug'],
                'subscription_type' => $license['subscription_type'],
            );
        }

        // License is valid and active.
        $days_until_expiry = ceil( ( $expiry - $now ) / DAY_IN_SECONDS );

        return array(
            'valid'              => true,
            'status'             => $license['status'],
            'days_until_expiry'  => $days_until_expiry,
            'expires_at'         => $license['expires_at'],
            'product_slug'       => $license['product_slug'],
            'subscription_type'  => $license['subscription_type'],
            'enforcement_mode'   => $license['enforcement_mode'],
        );
    }

    /**
     * Activate a license and bind it to a domain.
     *
     * @param string $license_key The license key.
     * @param string $domain      The domain to bind.
     * @return array|WP_Error Activation result or WP_Error.
     */
    public function activate_license( $license_key, $domain ) {
        global $wpdb;

        $table   = $wpdb->prefix . $this->table;
        $license = $this->get_license( $license_key );

        if ( ! $license ) {
            return new WP_Error( 'invalid_license', __( 'License key not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        if ( 'suspended' === $license['status'] ) {
            return new WP_Error( 'license_suspended', __( 'Cannot activate a suspended license.', 'dlmues-server' ), array( 'status' => 403 ) );
        }

        if ( 'expired' === $license['status'] ) {
            return new WP_Error( 'license_expired', __( 'Cannot activate an expired license.', 'dlmues-server' ), array( 'status' => 403 ) );
        }

        $security          = new DLMUES_Security();
        $sanitized_domain  = $security->sanitize_domain( $domain );

        // Check if already bound to a different domain.
        if ( ! empty( $license['client_domain'] ) ) {
            $stored_domain = $security->sanitize_domain( $license['client_domain'] );
            if ( $stored_domain !== $sanitized_domain ) {
                return new WP_Error(
                    'already_activated',
                    __( 'License is already activated on another domain. Please deactivate first.', 'dlmues-server' ),
                    array( 'status' => 409 )
                );
            }
        }

        $update_data = array(
            'client_domain' => $sanitized_domain,
            'status'        => ( 'trial' === $license['status'] ) ? 'trial' : 'active',
            'last_check_in' => current_time( 'mysql' ),
        );

        $result = $wpdb->update(
            $table,
            $update_data,
            array( 'license_key' => $license_key ),
            array( '%s', '%s', '%s' ),
            array( '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'activation_failed', __( 'Failed to activate license.', 'dlmues-server' ), array( 'status' => 500 ) );
        }

        return array(
            'activated'    => true,
            'license_key'  => $license_key,
            'domain'       => $sanitized_domain,
            'status'       => $update_data['status'],
            'expires_at'   => $license['expires_at'],
            'product_slug' => $license['product_slug'],
        );
    }

    /**
     * Deactivate a license (set status to suspended, clear domain).
     *
     * @param string $license_key The license key to deactivate.
     * @return array|WP_Error Deactivation result or WP_Error.
     */
    public function deactivate_license( $license_key ) {
        global $wpdb;

        $table   = $wpdb->prefix . $this->table;
        $license = $this->get_license( $license_key );

        if ( ! $license ) {
            return new WP_Error( 'invalid_license', __( 'License key not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        $result = $wpdb->update(
            $table,
            array(
                'status'        => 'suspended',
                'client_domain' => '',
            ),
            array( 'license_key' => $license_key ),
            array( '%s', '%s' ),
            array( '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'deactivation_failed', __( 'Failed to deactivate license.', 'dlmues-server' ), array( 'status' => 500 ) );
        }

        return array(
            'deactivated' => true,
            'license_key' => $license_key,
            'status'      => 'suspended',
        );
    }

    /**
     * Renew a license by extending its expiry date.
     *
     * @param string $license_key The license key.
     * @param string $duration    The subscription duration type.
     * @return array|WP_Error Renewal result or WP_Error.
     */
    public function renew_license( $license_key, $duration ) {
        global $wpdb;

        $table   = $wpdb->prefix . $this->table;
        $license = $this->get_license( $license_key );

        if ( ! $license ) {
            return new WP_Error( 'invalid_license', __( 'License key not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        // Calculate new expiry based on current expiry or now (whichever is later).
        $current_expiry = strtotime( $license['expires_at'] );
        $now            = current_time( 'timestamp' );
        $base_time      = max( $current_expiry, $now );
        $base_datetime  = gmdate( 'Y-m-d H:i:s', $base_time );

        $new_expiry = $this->calculate_expiry( $base_datetime, $duration );

        $result = $wpdb->update(
            $table,
            array(
                'expires_at'        => $new_expiry,
                'status'            => 'active',
                'subscription_type' => sanitize_text_field( $duration ),
                'last_payment_date' => current_time( 'mysql' ),
            ),
            array( 'license_key' => $license_key ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'renewal_failed', __( 'Failed to renew license.', 'dlmues-server' ), array( 'status' => 500 ) );
        }

        return array(
            'renewed'      => true,
            'license_key'  => $license_key,
            'new_expiry'   => $new_expiry,
            'duration'     => $duration,
            'status'       => 'active',
        );
    }

    /**
     * Get full license data by license key.
     *
     * @param string $license_key The license key.
     * @return array|null License data as associative array, or null if not found.
     */
    public function get_license( $license_key ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE license_key = %s",
                sanitize_text_field( $license_key )
            ),
            ARRAY_A
        );

        return $result ? $result : null;
    }

    /**
     * Get a license by its ID.
     *
     * @param int $license_id The license ID.
     * @return array|null License data or null.
     */
    public function get_license_by_id( $license_id ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                absint( $license_id )
            ),
            ARRAY_A
        );

        return $result ? $result : null;
    }

    /**
     * Get all licenses filtered by status.
     *
     * @param string $status The status to filter by.
     * @return array Array of license records.
     */
    public function get_licenses_by_status( $status ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC",
                sanitize_text_field( $status )
            ),
            ARRAY_A
        );
    }

    /**
     * Get licenses expiring within a given number of days.
     *
     * @param int $days Number of days to look ahead.
     * @return array Array of license records expiring within the window.
     */
    public function get_expiring_licenses( $days ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;
        $now   = current_time( 'mysql' );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'active'
                   AND expires_at BETWEEN %s AND DATE_ADD(%s, INTERVAL %d DAY)
                 ORDER BY expires_at ASC",
                $now,
                $now,
                absint( $days )
            ),
            ARRAY_A
        );
    }

    /**
     * Check if a license is within its grace period and apply enforcement.
     *
     * @param string $license_key The license key.
     * @return array Grace period status information.
     */
    public function check_grace_period( $license_key ) {
        global $wpdb;

        $table   = $wpdb->prefix . $this->table;
        $license = $this->get_license( $license_key );

        if ( ! $license ) {
            return array( 'in_grace' => false, 'message' => 'License not found.' );
        }

        $now    = current_time( 'timestamp' );
        $expiry = strtotime( $license['expires_at'] );

        if ( $expiry >= $now ) {
            return array( 'in_grace' => false, 'message' => 'License has not expired yet.' );
        }

        $grace_end = $expiry + ( absint( $license['grace_period_days'] ) * DAY_IN_SECONDS );

        if ( $now <= $grace_end ) {
            $days_remaining = ceil( ( $grace_end - $now ) / DAY_IN_SECONDS );

            // Update status to grace indicator.
            if ( 'expired' !== $license['status'] ) {
                $wpdb->update(
                    $table,
                    array( 'status' => 'expired' ),
                    array( 'license_key' => $license_key ),
                    array( '%s' ),
                    array( '%s' )
                );
            }

            return array(
                'in_grace'           => true,
                'grace_days_remaining' => $days_remaining,
                'enforcement_mode'   => $license['enforcement_mode'],
                'message'            => sprintf( 'Grace period active. %d day(s) remaining.', $days_remaining ),
            );
        }

        // Grace period has expired -- apply enforcement.
        $wpdb->update(
            $table,
            array( 'status' => 'suspended' ),
            array( 'license_key' => $license_key ),
            array( '%s' ),
            array( '%s' )
        );

        return array(
            'in_grace'         => false,
            'enforcement_mode' => $license['enforcement_mode'],
            'message'          => 'Grace period ended. License suspended.',
        );
    }

    /**
     * Suspend a license remotely.
     *
     * @param string $license_key The license key.
     * @return array|WP_Error Suspension result or WP_Error.
     */
    public function suspend_license( $license_key ) {
        $license = $this->get_license( $license_key );

        if ( ! $license ) {
            return new WP_Error( 'invalid_license', __( 'License key not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        $this->update_status( $license_key, 'suspended' );

        return array(
            'suspended'   => true,
            'license_key' => $license_key,
            'status'      => 'suspended',
        );
    }

    /**
     * Upgrade a license plan to a higher tier.
     *
     * @param string $license_key The license key.
     * @param string $new_plan    The new plan (monthly, bimonthly, quarterly, yearly).
     * @return array|WP_Error Upgrade result or WP_Error.
     */
    public function upgrade_plan( $license_key, $new_plan ) {
        global $wpdb;

        $table   = $wpdb->prefix . $this->table;
        $license = $this->get_license( $license_key );

        if ( ! $license ) {
            return new WP_Error( 'invalid_license', __( 'License key not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        $valid_plans = array( 'monthly', 'bimonthly', 'quarterly', 'yearly' );
        if ( ! in_array( $new_plan, $valid_plans, true ) ) {
            return new WP_Error( 'invalid_plan', __( 'Invalid subscription plan.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        $new_price = $this->get_plan_price( $new_plan );

        // Recalculate expiry from now.
        $new_expiry = $this->calculate_expiry( current_time( 'mysql' ), $new_plan );

        $result = $wpdb->update(
            $table,
            array(
                'subscription_type' => $new_plan,
                'price'             => $new_price,
                'expires_at'        => $new_expiry,
                'status'            => 'active',
            ),
            array( 'license_key' => $license_key ),
            array( '%s', '%f', '%s', '%s' ),
            array( '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'upgrade_failed', __( 'Failed to upgrade plan.', 'dlmues-server' ), array( 'status' => 500 ) );
        }

        return array(
            'upgraded'     => true,
            'license_key'  => $license_key,
            'old_plan'     => $license['subscription_type'],
            'new_plan'     => $new_plan,
            'new_price'    => $new_price,
            'new_expiry'   => $new_expiry,
        );
    }

    /**
     * Downgrade a license plan to a lower tier.
     *
     * The change takes effect at the next renewal.
     *
     * @param string $license_key The license key.
     * @param string $new_plan    The new plan.
     * @return array|WP_Error Downgrade result or WP_Error.
     */
    public function downgrade_plan( $license_key, $new_plan ) {
        global $wpdb;

        $table   = $wpdb->prefix . $this->table;
        $license = $this->get_license( $license_key );

        if ( ! $license ) {
            return new WP_Error( 'invalid_license', __( 'License key not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        $valid_plans = array( 'monthly', 'bimonthly', 'quarterly', 'yearly' );
        if ( ! in_array( $new_plan, $valid_plans, true ) ) {
            return new WP_Error( 'invalid_plan', __( 'Invalid subscription plan.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        $new_price = $this->get_plan_price( $new_plan );

        // Downgrade: keep current expiry, change plan for next renewal.
        $result = $wpdb->update(
            $table,
            array(
                'subscription_type' => $new_plan,
                'price'             => $new_price,
                'notes'             => $license['notes'] . "\n[" . current_time( 'mysql' ) . '] Plan downgraded from ' . $license['subscription_type'] . ' to ' . $new_plan,
            ),
            array( 'license_key' => $license_key ),
            array( '%s', '%f', '%s' ),
            array( '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'downgrade_failed', __( 'Failed to downgrade plan.', 'dlmues-server' ), array( 'status' => 500 ) );
        }

        return array(
            'downgraded'   => true,
            'license_key'  => $license_key,
            'old_plan'     => $license['subscription_type'],
            'new_plan'     => $new_plan,
            'new_price'    => $new_price,
            'effective_at' => $license['expires_at'],
            'message'      => __( 'Plan downgrade will take effect at next renewal.', 'dlmues-server' ),
        );
    }

    /**
     * Create a trial license.
     *
     * @param array $data {
     *     Trial data.
     *
     *     @type string $client_email Client email.
     *     @type string $product_slug Product identifier.
     *     @type string $product_type 'plugin' or 'theme'.
     *     @type int    $trial_days   Number of trial days. Default from settings.
     * }
     * @return array|WP_Error Trial license data or WP_Error.
     */
    public function create_trial( $data ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;
        $email = isset( $data['client_email'] ) ? sanitize_email( $data['client_email'] ) : '';

        if ( empty( $email ) ) {
            return new WP_Error( 'missing_email', __( 'Email address is required for trial.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        $product_slug = isset( $data['product_slug'] ) ? sanitize_text_field( $data['product_slug'] ) : '';

        // Check if this email already used a trial for this product.
        $existing_trial = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE client_email = %s
                   AND product_slug = %s
                   AND trial_used = 1",
                $email,
                $product_slug
            )
        );

        if ( $existing_trial > 0 ) {
            return new WP_Error( 'trial_used', __( 'A trial has already been used for this email and product.', 'dlmues-server' ), array( 'status' => 409 ) );
        }

        $trial_days = isset( $data['trial_days'] ) ? absint( $data['trial_days'] ) : absint( get_option( 'dlmues_default_trial_days', 14 ) );

        $license_key = $this->generate_license_key();
        $now         = current_time( 'mysql' );
        $expires_at  = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + ( $trial_days * DAY_IN_SECONDS ) );

        $insert_data = array(
            'license_key'       => $license_key,
            'client_domain'     => isset( $data['client_domain'] ) ? sanitize_text_field( $data['client_domain'] ) : '',
            'client_email'      => $email,
            'product_slug'      => $product_slug,
            'product_type'      => isset( $data['product_type'] ) ? sanitize_text_field( $data['product_type'] ) : 'plugin',
            'status'            => 'trial',
            'subscription_type' => 'monthly',
            'price'             => 0.00,
            'currency'          => get_option( 'dlmues_currency', 'USD' ),
            'created_at'        => $now,
            'expires_at'        => $expires_at,
            'grace_period_days' => 0,
            'last_payment_date' => $now,
            'last_check_in'     => $now,
            'visitor_count'     => 0,
            'injected_visitor_count' => 0,
            'enforcement_mode'  => get_option( 'dlmues_enforcement_mode', 'restrict_admin' ),
            'notes'             => 'Trial license',
            'trial_days'        => $trial_days,
            'trial_used'        => 1,
        );

        $formats = array(
            '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%f', '%s', '%s', '%s', '%d', '%s', '%s',
            '%d', '%d', '%s', '%s', '%d', '%d',
        );

        $result = $wpdb->insert( $table, $insert_data, $formats );

        if ( false === $result ) {
            return new WP_Error( 'trial_creation_failed', __( 'Failed to create trial license.', 'dlmues-server' ), array( 'status' => 500 ) );
        }

        $license_id = $wpdb->insert_id;

        // Generate API token.
        $security  = new DLMUES_Security();
        $api_token = $security->generate_api_token();
        $security->store_api_token( $api_token, $license_id );

        return array(
            'license_id'   => $license_id,
            'license_key'  => $license_key,
            'api_token'    => $api_token,
            'status'       => 'trial',
            'trial_days'   => $trial_days,
            'expires_at'   => $expires_at,
            'product_slug' => $product_slug,
        );
    }

    /**
     * Apply a coupon code to a license.
     *
     * @param string $license_key The license key.
     * @param string $coupon_code The coupon code.
     * @return array|WP_Error Discount result or WP_Error.
     */
    public function apply_coupon( $license_key, $coupon_code ) {
        global $wpdb;

        $license = $this->get_license( $license_key );
        if ( ! $license ) {
            return new WP_Error( 'invalid_license', __( 'License key not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        $coupon_table = $wpdb->prefix . 'dlmues_coupons';

        $coupon = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$coupon_table} WHERE code = %s",
                sanitize_text_field( $coupon_code )
            ),
            ARRAY_A
        );

        if ( ! $coupon ) {
            return new WP_Error( 'invalid_coupon', __( 'Coupon code not found.', 'dlmues-server' ), array( 'status' => 404 ) );
        }

        // Check validity period.
        $now = current_time( 'mysql' );

        if ( ! empty( $coupon['valid_from'] ) && $coupon['valid_from'] > '0000-00-00 00:00:00' && $now < $coupon['valid_from'] ) {
            return new WP_Error( 'coupon_not_active', __( 'Coupon is not yet active.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        if ( ! empty( $coupon['valid_until'] ) && $coupon['valid_until'] > '0000-00-00 00:00:00' && $now > $coupon['valid_until'] ) {
            return new WP_Error( 'coupon_expired', __( 'Coupon has expired.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        // Check usage limit.
        if ( $coupon['max_uses'] > 0 && $coupon['used_count'] >= $coupon['max_uses'] ) {
            return new WP_Error( 'coupon_limit_reached', __( 'Coupon usage limit has been reached.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        // Calculate discount.
        $original_price  = floatval( $license['price'] );
        $discount_value  = floatval( $coupon['discount_value'] );
        $discount_amount = 0;

        if ( 'percentage' === $coupon['discount_type'] ) {
            $discount_amount = $original_price * ( $discount_value / 100 );
        } else {
            $discount_amount = min( $discount_value, $original_price );
        }

        $new_price = max( 0, $original_price - $discount_amount );

        // Update license price.
        $license_table = $wpdb->prefix . $this->table;
        $wpdb->update(
            $license_table,
            array( 'price' => $new_price ),
            array( 'license_key' => $license_key ),
            array( '%f' ),
            array( '%s' )
        );

        // Increment coupon usage.
        $wpdb->update(
            $coupon_table,
            array( 'used_count' => $coupon['used_count'] + 1 ),
            array( 'id' => $coupon['id'] ),
            array( '%d' ),
            array( '%d' )
        );

        return array(
            'coupon_applied'  => true,
            'coupon_code'     => $coupon_code,
            'discount_type'   => $coupon['discount_type'],
            'discount_value'  => $discount_value,
            'discount_amount' => round( $discount_amount, 2 ),
            'original_price'  => $original_price,
            'new_price'       => round( $new_price, 2 ),
        );
    }

    /**
     * Bulk import licenses from CSV data.
     *
     * Expected CSV columns: client_email, client_domain, product_slug, product_type,
     * subscription_type, price, currency
     *
     * @param string $csv_data Raw CSV string.
     * @return array Import results with success/error counts.
     */
    public function bulk_import( $csv_data ) {
        $lines    = explode( "\n", trim( $csv_data ) );
        $results  = array(
            'total'    => 0,
            'imported' => 0,
            'failed'   => 0,
            'errors'   => array(),
            'licenses' => array(),
        );

        if ( count( $lines ) < 2 ) {
            return new WP_Error( 'invalid_csv', __( 'CSV must have a header row and at least one data row.', 'dlmues-server' ), array( 'status' => 400 ) );
        }

        // Parse header.
        $header = str_getcsv( array_shift( $lines ) );
        $header = array_map( 'trim', $header );
        $header = array_map( 'strtolower', $header );

        foreach ( $lines as $line_num => $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            $results['total']++;
            $fields = str_getcsv( $line );

            if ( count( $fields ) < count( $header ) ) {
                $results['failed']++;
                $results['errors'][] = sprintf( 'Row %d: Not enough fields.', $line_num + 2 );
                continue;
            }

            $row_data = array_combine( $header, array_slice( $fields, 0, count( $header ) ) );

            $license_data = array(
                'client_email'      => isset( $row_data['client_email'] ) ? $row_data['client_email'] : '',
                'client_domain'     => isset( $row_data['client_domain'] ) ? $row_data['client_domain'] : '',
                'product_slug'      => isset( $row_data['product_slug'] ) ? $row_data['product_slug'] : '',
                'product_type'      => isset( $row_data['product_type'] ) ? $row_data['product_type'] : 'plugin',
                'subscription_type' => isset( $row_data['subscription_type'] ) ? $row_data['subscription_type'] : 'monthly',
                'price'             => isset( $row_data['price'] ) ? $row_data['price'] : '',
                'currency'          => isset( $row_data['currency'] ) ? $row_data['currency'] : 'USD',
            );

            $result = $this->create_license( $license_data );

            if ( is_wp_error( $result ) ) {
                $results['failed']++;
                $results['errors'][] = sprintf( 'Row %d: %s', $line_num + 2, $result->get_error_message() );
            } else {
                $results['imported']++;
                $results['licenses'][] = $result['license_key'];
            }
        }

        return $results;
    }

    /**
     * Get renewal forecast analytics.
     *
     * Returns counts of licenses expiring in upcoming time windows.
     *
     * @return array Forecast data.
     */
    public function get_renewal_forecast() {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;
        $now   = current_time( 'mysql' );

        $windows = array(
            '7_days'  => 7,
            '14_days' => 14,
            '30_days' => 30,
            '60_days' => 60,
            '90_days' => 90,
        );

        $forecast = array();

        foreach ( $windows as $label => $days ) {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE status = 'active'
                       AND expires_at BETWEEN %s AND DATE_ADD(%s, INTERVAL %d DAY)",
                    $now,
                    $now,
                    $days
                )
            );

            $revenue = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(price), 0) FROM {$table}
                     WHERE status = 'active'
                       AND expires_at BETWEEN %s AND DATE_ADD(%s, INTERVAL %d DAY)",
                    $now,
                    $now,
                    $days
                )
            );

            $forecast[ $label ] = array(
                'count'            => absint( $count ),
                'potential_revenue' => round( floatval( $revenue ), 2 ),
            );
        }

        return $forecast;
    }

    /**
     * Get usage analytics.
     *
     * @return array Usage statistics.
     */
    public function get_usage_analytics() {
        global $wpdb;

        $table         = $wpdb->prefix . $this->table;
        $payments_table = $wpdb->prefix . 'dlmues_payments';

        // Status counts.
        $status_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
            ARRAY_A
        );

        $statuses = array();
        foreach ( $status_counts as $row ) {
            $statuses[ $row['status'] ] = absint( $row['count'] );
        }

        // Plan distribution.
        $plan_counts = $wpdb->get_results(
            "SELECT subscription_type, COUNT(*) as count FROM {$table} GROUP BY subscription_type",
            ARRAY_A
        );

        $plans = array();
        foreach ( $plan_counts as $row ) {
            $plans[ $row['subscription_type'] ] = absint( $row['count'] );
        }

        // Product distribution.
        $product_counts = $wpdb->get_results(
            "SELECT product_slug, COUNT(*) as count FROM {$table} WHERE product_slug != '' GROUP BY product_slug ORDER BY count DESC LIMIT 20",
            ARRAY_A
        );

        // Revenue (last 12 months by month).
        $monthly_revenue = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(created_at, '%%Y-%%m') as month, SUM(amount) as revenue, COUNT(*) as transactions
                 FROM {$payments_table}
                 WHERE status = 'success'
                   AND created_at >= DATE_SUB(%s, INTERVAL 12 MONTH)
                 GROUP BY DATE_FORMAT(created_at, '%%Y-%%m')
                 ORDER BY month ASC",
                current_time( 'mysql' )
            ),
            ARRAY_A
        );

        // Total revenue.
        $total_revenue = $wpdb->get_var(
            "SELECT COALESCE(SUM(amount), 0) FROM {$payments_table} WHERE status = 'success'"
        );

        // Recent activity (last 30 days).
        $new_licenses_30d = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(%s, INTERVAL 30 DAY)",
                current_time( 'mysql' )
            )
        );

        // Total license count.
        $total_licenses = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        return array(
            'total_licenses'    => absint( $total_licenses ),
            'status_breakdown'  => $statuses,
            'plan_distribution' => $plans,
            'product_distribution' => $product_counts,
            'monthly_revenue'   => $monthly_revenue,
            'total_revenue'     => round( floatval( $total_revenue ), 2 ),
            'new_licenses_30d'  => absint( $new_licenses_30d ),
            'renewal_forecast'  => $this->get_renewal_forecast(),
        );
    }

    /**
     * Process expired licenses (called by daily maintenance).
     *
     * Finds active licenses past expiry and updates their status.
     */
    public function process_expired_licenses() {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;
        $now   = current_time( 'mysql' );

        // Find active licenses that have expired.
        $expired = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'active' AND expires_at < %s",
                $now
            ),
            ARRAY_A
        );

        foreach ( $expired as $license ) {
            $grace_end = strtotime( $license['expires_at'] ) + ( absint( $license['grace_period_days'] ) * DAY_IN_SECONDS );

            if ( current_time( 'timestamp' ) > $grace_end ) {
                // Grace period also expired.
                $this->update_status( $license['license_key'], 'suspended' );
            } else {
                $this->update_status( $license['license_key'], 'expired' );
            }
        }

        // Find expired licenses past grace period.
        $past_grace = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'expired'
                   AND DATE_ADD(expires_at, INTERVAL grace_period_days DAY) < %s",
                $now
            ),
            ARRAY_A
        );

        foreach ( $past_grace as $license ) {
            $this->update_status( $license['license_key'], 'suspended' );
        }
    }

    /**
     * Get all licenses (for admin listing).
     *
     * @param array $args {
     *     Optional query arguments.
     *
     *     @type int    $per_page Number of results per page. Default 50.
     *     @type int    $page     Page number. Default 1.
     *     @type string $status   Filter by status.
     *     @type string $search   Search term for license key, email, or domain.
     *     @type string $orderby  Column to order by. Default 'created_at'.
     *     @type string $order    ASC or DESC. Default 'DESC'.
     * }
     * @return array Array with 'items' and 'total' keys.
     */
    public function get_all_licenses( $args = array() ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;

        $defaults = array(
            'per_page' => 50,
            'page'     => 1,
            'status'   => '',
            'search'   => '',
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        $where_clauses = array( '1=1' );
        $where_values  = array();

        if ( ! empty( $args['status'] ) ) {
            $where_clauses[] = 'status = %s';
            $where_values[]  = sanitize_text_field( $args['status'] );
        }

        if ( ! empty( $args['search'] ) ) {
            $search_term     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where_clauses[] = '(license_key LIKE %s OR client_email LIKE %s OR client_domain LIKE %s)';
            $where_values[]  = $search_term;
            $where_values[]  = $search_term;
            $where_values[]  = $search_term;
        }

        $where = implode( ' AND ', $where_clauses );

        // Whitelist orderby.
        $allowed_orderby = array( 'id', 'license_key', 'client_email', 'status', 'created_at', 'expires_at', 'price' );
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $offset   = ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] );
        $per_page = absint( $args['per_page'] );

        // Get total count.
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        if ( ! empty( $where_values ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $where_values );
        }
        $total = absint( $wpdb->get_var( $count_sql ) );

        // Get items.
        $query = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $query_values = array_merge( $where_values, array( $per_page, $offset ) );
        $items = $wpdb->get_results(
            $wpdb->prepare( $query, $query_values ),
            ARRAY_A
        );

        return array(
            'items' => $items ? $items : array(),
            'total' => $total,
        );
    }

    /**
     * Check whether a license key already exists.
     *
     * @param string $license_key The license key to check.
     * @return bool True if the key exists.
     */
    private function license_exists( $license_key ) {
        global $wpdb;
        $table = $wpdb->prefix . $this->table;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE license_key = %s",
                $license_key
            )
        );

        return ( $count > 0 );
    }

    /**
     * Update the status of a license.
     *
     * @param string $license_key The license key.
     * @param string $status      The new status.
     * @return bool True on success, false on failure.
     */
    private function update_status( $license_key, $status ) {
        global $wpdb;
        $table = $wpdb->prefix . $this->table;

        $result = $wpdb->update(
            $table,
            array( 'status' => sanitize_text_field( $status ) ),
            array( 'license_key' => $license_key ),
            array( '%s' ),
            array( '%s' )
        );

        return ( false !== $result );
    }

    /**
     * Calculate the expiry date based on subscription type.
     *
     * @param string $from     Start date (Y-m-d H:i:s).
     * @param string $duration Subscription type.
     * @return string Expiry date (Y-m-d H:i:s).
     */
    private function calculate_expiry( $from, $duration ) {
        $intervals = array(
            'monthly'   => '+1 month',
            'bimonthly' => '+2 months',
            'quarterly' => '+3 months',
            'yearly'    => '+1 year',
        );

        $interval = isset( $intervals[ $duration ] ) ? $intervals[ $duration ] : '+1 month';

        return gmdate( 'Y-m-d H:i:s', strtotime( $from . ' ' . $interval ) );
    }

    /**
     * Get the plan price from settings.
     *
     * @param string $plan The plan type.
     * @return float The price.
     */
    private function get_plan_price( $plan ) {
        $prices = array(
            'monthly'   => floatval( get_option( 'dlmues_pricing_monthly', 9.99 ) ),
            'bimonthly' => floatval( get_option( 'dlmues_pricing_bimonthly', 17.99 ) ),
            'quarterly' => floatval( get_option( 'dlmues_pricing_quarterly', 24.99 ) ),
            'yearly'    => floatval( get_option( 'dlmues_pricing_yearly', 89.99 ) ),
        );

        return isset( $prices[ $plan ] ) ? $prices[ $plan ] : $prices['monthly'];
    }
}
