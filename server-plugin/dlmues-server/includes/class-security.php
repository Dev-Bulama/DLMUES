<?php
/**
 * Security handler for DLMUES License Server.
 *
 * Provides cryptographic operations, token management,
 * domain verification, request signing, replay prevention,
 * and rate limiting.
 *
 * @package DLMUES_Server
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Security
 */
class DLMUES_Security {

    /**
     * Encryption cipher method.
     *
     * @var string
     */
    private $cipher = 'aes-256-cbc';

    /**
     * Rate limit transient prefix.
     *
     * @var string
     */
    private $rate_limit_prefix = 'dlmues_rl_';

    /**
     * Nonce transient prefix.
     *
     * @var string
     */
    private $nonce_prefix = 'dlmues_nonce_';

    /**
     * Maximum replay window in seconds (5 minutes).
     *
     * @var int
     */
    private $replay_window = 300;

    /**
     * Generate a cryptographically secure API token.
     *
     * @return string The raw API token (64 hex characters).
     */
    public function generate_api_token() {
        return bin2hex( random_bytes( 32 ) );
    }

    /**
     * Hash an API token for storage.
     *
     * @param string $token The raw API token.
     * @return string The hashed token.
     */
    public function hash_token( $token ) {
        return hash( 'sha256', $token );
    }

    /**
     * Store an API token in the database.
     *
     * @param string   $token      The raw API token.
     * @param int      $license_id The license ID to associate with.
     * @param int|null $expires_in Optional expiry in seconds from now.
     * @return int|false The token record ID or false on failure.
     */
    public function store_api_token( $token, $license_id, $expires_in = null ) {
        global $wpdb;

        $table      = $wpdb->prefix . 'dlmues_api_tokens';
        $token_hash = $this->hash_token( $token );
        $expires_at = null;

        if ( $expires_in ) {
            $expires_at = gmdate( 'Y-m-d H:i:s', time() + $expires_in );
        }

        $result = $wpdb->insert(
            $table,
            array(
                'token_hash' => $token_hash,
                'license_id' => absint( $license_id ),
                'created_at' => current_time( 'mysql' ),
                'expires_at' => $expires_at,
            ),
            array( '%s', '%d', '%s', '%s' )
        );

        if ( false === $result ) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Validate an API token.
     *
     * Checks the token against stored hashes, verifies it has not expired,
     * and updates the last_used timestamp.
     *
     * @param string $token The raw API token to validate.
     * @return array|false The token record (including license_id) or false if invalid.
     */
    public function validate_api_token( $token ) {
        global $wpdb;

        if ( empty( $token ) ) {
            return false;
        }

        $table      = $wpdb->prefix . 'dlmues_api_tokens';
        $token_hash = $this->hash_token( $token );

        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE token_hash = %s",
                $token_hash
            ),
            ARRAY_A
        );

        if ( ! $record ) {
            return false;
        }

        // Check expiry.
        if ( ! empty( $record['expires_at'] ) && strtotime( $record['expires_at'] ) < time() ) {
            // Token has expired -- remove it.
            $wpdb->delete( $table, array( 'id' => $record['id'] ), array( '%d' ) );
            return false;
        }

        // Update last_used timestamp.
        $wpdb->update(
            $table,
            array( 'last_used' => current_time( 'mysql' ) ),
            array( 'id' => $record['id'] ),
            array( '%s' ),
            array( '%d' )
        );

        return $record;
    }

    /**
     * Revoke all API tokens for a given license.
     *
     * @param int $license_id The license ID.
     * @return int|false Number of rows deleted or false on error.
     */
    public function revoke_tokens_for_license( $license_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'dlmues_api_tokens';

        return $wpdb->delete(
            $table,
            array( 'license_id' => absint( $license_id ) ),
            array( '%d' )
        );
    }

    /**
     * Get the encryption key derived from AUTH_KEY or a fallback.
     *
     * @return string 32-byte encryption key.
     */
    private function get_encryption_key() {
        $source = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'dlmues-default-encryption-key-change-me';
        return hash( 'sha256', $source, true );
    }

    /**
     * Encrypt a license key using OpenSSL.
     *
     * @param string $key The plain-text license key.
     * @return string Base64-encoded encrypted string (IV + ciphertext).
     */
    public function encrypt_license_key( $key ) {
        $encryption_key = $this->get_encryption_key();
        $iv_length      = openssl_cipher_iv_length( $this->cipher );
        $iv             = openssl_random_pseudo_bytes( $iv_length );

        $encrypted = openssl_encrypt( $key, $this->cipher, $encryption_key, OPENSSL_RAW_DATA, $iv );

        if ( false === $encrypted ) {
            return '';
        }

        // Prepend IV to ciphertext and base64-encode.
        return base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypt an encrypted license key.
     *
     * @param string $encrypted Base64-encoded encrypted string.
     * @return string The decrypted license key, or empty string on failure.
     */
    public function decrypt_license_key( $encrypted ) {
        if ( empty( $encrypted ) ) {
            return '';
        }

        $encryption_key = $this->get_encryption_key();
        $data           = base64_decode( $encrypted, true );

        if ( false === $data ) {
            return '';
        }

        $iv_length = openssl_cipher_iv_length( $this->cipher );

        if ( strlen( $data ) <= $iv_length ) {
            return '';
        }

        $iv         = substr( $data, 0, $iv_length );
        $ciphertext = substr( $data, $iv_length );

        $decrypted = openssl_decrypt( $ciphertext, $this->cipher, $encryption_key, OPENSSL_RAW_DATA, $iv );

        return ( false === $decrypted ) ? '' : $decrypted;
    }

    /**
     * Verify that a domain matches the one bound to a license.
     *
     * Performs normalisation before comparison: strips protocol, www prefix,
     * trailing slashes, and converts to lowercase.
     *
     * @param string $domain      The domain to verify.
     * @param string $license_key The license key to check against.
     * @return bool True if domain matches the stored domain for the license.
     */
    public function verify_domain( $domain, $license_key ) {
        global $wpdb;

        $table = $wpdb->prefix . 'dlmues_licenses';

        $stored_domain = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT client_domain FROM {$table} WHERE license_key = %s",
                $license_key
            )
        );

        if ( ! $stored_domain ) {
            return false;
        }

        $normalized_input  = $this->sanitize_domain( $domain );
        $normalized_stored = $this->sanitize_domain( $stored_domain );

        return ( $normalized_input === $normalized_stored );
    }

    /**
     * Create an HMAC signature for a data payload.
     *
     * @param mixed $data The data to sign (will be JSON-encoded if not a string).
     * @return string The HMAC-SHA256 signature.
     */
    public function sign_request( $data ) {
        $secret = get_option( 'dlmues_hmac_secret', '' );

        if ( ! is_string( $data ) ) {
            $data = wp_json_encode( $data );
        }

        return hash_hmac( 'sha256', $data, $secret );
    }

    /**
     * Verify an HMAC signature against a data payload.
     *
     * @param mixed  $data      The data that was signed.
     * @param string $signature The signature to verify.
     * @return bool True if the signature is valid.
     */
    public function verify_signature( $data, $signature ) {
        if ( empty( $signature ) ) {
            return false;
        }

        $expected = $this->sign_request( $data );

        return hash_equals( $expected, $signature );
    }

    /**
     * Prevent replay attacks by checking nonce + timestamp.
     *
     * The nonce must be unique within the replay window (5 minutes),
     * and the timestamp must be within the window.
     *
     * @param string $nonce     A unique nonce for the request.
     * @param int    $timestamp The Unix timestamp of the request.
     * @return bool True if the request is valid (not a replay).
     */
    public function prevent_replay( $nonce, $timestamp ) {
        $timestamp = absint( $timestamp );

        // Check that timestamp is within the replay window.
        $current_time = time();
        if ( abs( $current_time - $timestamp ) > $this->replay_window ) {
            return false;
        }

        // Check that nonce has not been used before.
        $transient_key = $this->nonce_prefix . md5( $nonce );
        $existing      = get_transient( $transient_key );

        if ( false !== $existing ) {
            // Nonce already used -- replay attempt.
            return false;
        }

        // Store the nonce for the duration of the replay window.
        set_transient( $transient_key, 1, $this->replay_window );

        return true;
    }

    /**
     * Rate-limit requests by IP and endpoint.
     *
     * Uses WordPress transients to track request counts within a 60-second window.
     *
     * @param string $ip       The client IP address.
     * @param string $endpoint The API endpoint being accessed.
     * @return bool True if the request is within the rate limit, false if exceeded.
     */
    public function rate_limit( $ip, $endpoint ) {
        $max_requests = absint( get_option( 'dlmues_rate_limit_per_minute', 60 ) );

        if ( $max_requests <= 0 ) {
            // Rate limiting disabled.
            return true;
        }

        $key  = $this->rate_limit_prefix . md5( $ip . '|' . $endpoint );
        $data = get_transient( $key );

        if ( false === $data ) {
            // First request in this window.
            set_transient( $key, 1, 60 );
            return true;
        }

        $count = absint( $data );

        if ( $count >= $max_requests ) {
            return false;
        }

        // Increment counter.
        set_transient( $key, $count + 1, 60 );

        return true;
    }

    /**
     * Get the remaining rate limit for an IP/endpoint combination.
     *
     * @param string $ip       The client IP address.
     * @param string $endpoint The API endpoint.
     * @return int Remaining requests in the current window.
     */
    public function get_rate_limit_remaining( $ip, $endpoint ) {
        $max_requests = absint( get_option( 'dlmues_rate_limit_per_minute', 60 ) );
        $key          = $this->rate_limit_prefix . md5( $ip . '|' . $endpoint );
        $data         = get_transient( $key );

        if ( false === $data ) {
            return $max_requests;
        }

        $remaining = $max_requests - absint( $data );

        return max( 0, $remaining );
    }

    /**
     * Sanitize and normalize a domain name.
     *
     * Strips protocol scheme, www prefix, trailing slashes, paths,
     * and converts to lowercase.
     *
     * @param string $domain The raw domain input.
     * @return string The sanitized domain.
     */
    public function sanitize_domain( $domain ) {
        $domain = strtolower( trim( $domain ) );

        // Remove protocol.
        $domain = preg_replace( '#^https?://#', '', $domain );

        // Remove www prefix.
        $domain = preg_replace( '#^www\.#', '', $domain );

        // Remove trailing slash and path.
        $domain = preg_replace( '#/.*$#', '', $domain );

        // Remove port number.
        $domain = preg_replace( '#:\d+$#', '', $domain );

        // Final sanitization.
        $domain = sanitize_text_field( $domain );

        return $domain;
    }

    /**
     * Log a failed authentication attempt.
     *
     * @param string $ip       The client IP address.
     * @param string $endpoint The endpoint that was accessed.
     * @param string $reason   The reason for failure.
     */
    public function log_failed_auth( $ip, $endpoint, $reason = '' ) {
        $log_entry = array(
            'ip'        => sanitize_text_field( $ip ),
            'endpoint'  => sanitize_text_field( $endpoint ),
            'reason'    => sanitize_text_field( $reason ),
            'timestamp' => current_time( 'mysql' ),
            'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
                : '',
        );

        $log = get_option( 'dlmues_auth_failure_log', array() );

        // Keep only last 500 entries.
        if ( count( $log ) >= 500 ) {
            $log = array_slice( $log, -499 );
        }

        $log[] = $log_entry;

        update_option( 'dlmues_auth_failure_log', $log, false );
    }

    /**
     * Get the client IP address from the request.
     *
     * @return string The client IP address.
     */
    public function get_client_ip() {
        $ip = '';

        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $ips       = explode( ',', $forwarded );
            $ip        = trim( $ips[0] );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }

    /**
     * Mask a license key for display purposes.
     *
     * Shows only first and last segments. Example: DLMUES-XXXX-****-****-XXXX
     *
     * @param string $license_key The full license key.
     * @return string The masked license key.
     */
    public function mask_license_key( $license_key ) {
        $parts = explode( '-', $license_key );

        if ( count( $parts ) < 5 ) {
            return str_repeat( '*', strlen( $license_key ) );
        }

        // Keep prefix and first segment, mask middle, keep last.
        $parts[2] = '****';
        $parts[3] = '****';

        return implode( '-', $parts );
    }
}
