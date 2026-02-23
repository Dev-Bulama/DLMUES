<?php
/**
 * Invoice Manager for DLMUES License Server.
 *
 * Generates and manages invoices for license payments.
 *
 * @package DLMUES_Server
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Invoice_Manager
 */
class DLMUES_Invoice_Manager {

    /**
     * Invoices table name (without prefix).
     *
     * @var string
     */
    private $table = 'dlmues_invoices';

    /**
     * Generate an invoice for a payment.
     *
     * @param int $payment_id The payment record ID.
     * @return int|WP_Error The invoice ID or WP_Error.
     */
    public function generate_invoice( $payment_id ) {
        global $wpdb;

        $payment_id = absint( $payment_id );

        // Get payment data.
        $payment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dlmues_payments WHERE id = %d",
                $payment_id
            ),
            ARRAY_A
        );

        if ( ! $payment ) {
            return new WP_Error( 'payment_not_found', __( 'Payment not found.', 'dlmues-server' ) );
        }

        // Check if invoice already exists for this payment.
        $table = $wpdb->prefix . $this->table;
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE payment_id = %d",
                $payment_id
            )
        );

        if ( $existing ) {
            return absint( $existing );
        }

        // Get license data.
        $license = null;
        if ( ! empty( $payment['license_id'] ) ) {
            $license = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}dlmues_licenses WHERE id = %d",
                    $payment['license_id']
                ),
                ARRAY_A
            );
        }

        // Generate invoice number.
        $invoice_number = $this->generate_invoice_number();

        // Build invoice data.
        $invoice_data = array(
            'invoice_number' => $invoice_number,
            'payment_id'     => $payment_id,
            'date'           => current_time( 'mysql' ),
            'seller'         => array(
                'name'    => get_option( 'dlmues_company_name', get_bloginfo( 'name' ) ),
                'address' => get_option( 'dlmues_company_address', '' ),
                'email'   => get_option( 'dlmues_company_email', get_option( 'admin_email' ) ),
            ),
            'buyer'          => array(
                'email'  => $license ? $license['client_email'] : '',
                'domain' => $license ? $license['client_domain'] : '',
            ),
            'items'          => array(
                array(
                    'description' => $this->get_item_description( $license, $payment ),
                    'quantity'    => 1,
                    'amount'      => floatval( $payment['amount'] ),
                    'currency'    => $payment['currency'],
                ),
            ),
            'total'          => floatval( $payment['amount'] ),
            'currency'       => $payment['currency'],
            'payment_ref'    => $payment['payment_reference'],
            'payment_method' => $payment['payment_provider'],
            'license_key'    => $license ? $license['license_key'] : '',
            'plan'           => $payment['plan_duration'],
        );

        $result = $wpdb->insert(
            $table,
            array(
                'payment_id'     => $payment_id,
                'license_id'     => $payment['license_id'],
                'invoice_number' => $invoice_number,
                'invoice_data'   => wp_json_encode( $invoice_data ),
                'created_at'     => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'invoice_failed', __( 'Failed to generate invoice.', 'dlmues-server' ) );
        }

        return $wpdb->insert_id;
    }

    /**
     * Get an invoice by ID.
     *
     * @param int $invoice_id The invoice ID.
     * @return array|null Invoice data or null.
     */
    public function get_invoice( $invoice_id ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                absint( $invoice_id )
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        $row['invoice_data'] = json_decode( $row['invoice_data'], true );

        return $row;
    }

    /**
     * Get an invoice by its number.
     *
     * @param string $invoice_number The invoice number.
     * @return array|null Invoice data or null.
     */
    public function get_invoice_by_number( $invoice_number ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE invoice_number = %s",
                sanitize_text_field( $invoice_number )
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        $row['invoice_data'] = json_decode( $row['invoice_data'], true );

        return $row;
    }

    /**
     * Get all invoices for a license.
     *
     * @param int $license_id The license ID.
     * @return array List of invoices.
     */
    public function get_invoices_for_license( $license_id ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE license_id = %d ORDER BY created_at DESC",
                absint( $license_id )
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            return array();
        }

        foreach ( $rows as &$row ) {
            $row['invoice_data'] = json_decode( $row['invoice_data'], true );
        }

        return $rows;
    }

    /**
     * Get all invoices with pagination.
     *
     * @param array $args Query arguments.
     * @return array Array with 'items' and 'total'.
     */
    public function get_all_invoices( $args = array() ) {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;

        $defaults = array(
            'per_page' => 50,
            'page'     => 1,
            'search'   => '',
        );

        $args = wp_parse_args( $args, $defaults );

        $where  = '1=1';
        $values = array();

        if ( ! empty( $args['search'] ) ) {
            $search  = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where  .= ' AND invoice_number LIKE %s';
            $values[] = $search;
        }

        $offset   = ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] );
        $per_page = absint( $args['per_page'] );

        $count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        if ( ! empty( $values ) ) {
            $count_query = $wpdb->prepare( $count_query, $values );
        }
        $total = absint( $wpdb->get_var( $count_query ) );

        $query        = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_values = array_merge( $values, array( $per_page, $offset ) );

        $items = $wpdb->get_results(
            $wpdb->prepare( $query, $query_values ),
            ARRAY_A
        );

        if ( $items ) {
            foreach ( $items as &$item ) {
                $item['invoice_data'] = json_decode( $item['invoice_data'], true );
            }
        }

        return array(
            'items' => $items ? $items : array(),
            'total' => $total,
        );
    }

    /**
     * Render an invoice as HTML for display or printing.
     *
     * @param int $invoice_id The invoice ID.
     * @return string The invoice HTML.
     */
    public function render_invoice_html( $invoice_id ) {
        $invoice = $this->get_invoice( $invoice_id );

        if ( ! $invoice || empty( $invoice['invoice_data'] ) ) {
            return '<p>' . esc_html__( 'Invoice not found.', 'dlmues-server' ) . '</p>';
        }

        $data = $invoice['invoice_data'];

        ob_start();
        ?>
        <div class="dlmues-invoice" style="max-width: 700px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            <div style="border-bottom: 3px solid #0073aa; padding-bottom: 20px; margin-bottom: 20px;">
                <h2 style="margin: 0;"><?php echo esc_html( $data['seller']['name'] ); ?></h2>
                <p style="color: #666; margin: 5px 0;"><?php echo esc_html( $data['seller']['address'] ); ?></p>
            </div>

            <table style="width: 100%; margin-bottom: 20px;">
                <tr>
                    <td style="vertical-align: top;">
                        <strong><?php esc_html_e( 'Invoice Number:', 'dlmues-server' ); ?></strong><br>
                        <?php echo esc_html( $data['invoice_number'] ); ?><br><br>
                        <strong><?php esc_html_e( 'Date:', 'dlmues-server' ); ?></strong><br>
                        <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $data['date'] ) ) ); ?>
                    </td>
                    <td style="vertical-align: top; text-align: right;">
                        <strong><?php esc_html_e( 'Bill To:', 'dlmues-server' ); ?></strong><br>
                        <?php echo esc_html( $data['buyer']['email'] ); ?><br>
                        <?php if ( ! empty( $data['buyer']['domain'] ) ) : ?>
                            <?php echo esc_html( $data['buyer']['domain'] ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr style="background: #f7f7f7;">
                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;"><?php esc_html_e( 'Description', 'dlmues-server' ); ?></th>
                        <th style="padding: 10px; text-align: center; border-bottom: 2px solid #ddd;"><?php esc_html_e( 'Qty', 'dlmues-server' ); ?></th>
                        <th style="padding: 10px; text-align: right; border-bottom: 2px solid #ddd;"><?php esc_html_e( 'Amount', 'dlmues-server' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $data['items'] ) ) : ?>
                        <?php foreach ( $data['items'] as $item ) : ?>
                            <tr>
                                <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo esc_html( $item['description'] ); ?></td>
                                <td style="padding: 10px; text-align: center; border-bottom: 1px solid #eee;"><?php echo absint( $item['quantity'] ); ?></td>
                                <td style="padding: 10px; text-align: right; border-bottom: 1px solid #eee;"><?php echo esc_html( $item['currency'] . ' ' . number_format( $item['amount'], 2 ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" style="padding: 10px; text-align: right; font-weight: bold;"><?php esc_html_e( 'Total:', 'dlmues-server' ); ?></td>
                        <td style="padding: 10px; text-align: right; font-weight: bold;"><?php echo esc_html( $data['currency'] . ' ' . number_format( $data['total'], 2 ) ); ?></td>
                    </tr>
                </tfoot>
            </table>

            <div style="border-top: 1px solid #ddd; padding-top: 15px; font-size: 12px; color: #666;">
                <p><strong><?php esc_html_e( 'Payment Reference:', 'dlmues-server' ); ?></strong> <?php echo esc_html( $data['payment_ref'] ); ?></p>
                <p><strong><?php esc_html_e( 'Payment Method:', 'dlmues-server' ); ?></strong> <?php echo esc_html( ucfirst( $data['payment_method'] ) ); ?></p>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Generate a unique invoice number.
     *
     * Format: INV-YYYYMMDD-XXXX
     *
     * @return string The invoice number.
     */
    private function generate_invoice_number() {
        global $wpdb;

        $table = $wpdb->prefix . $this->table;
        $date  = gmdate( 'Ymd' );

        // Get the count of invoices today.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE invoice_number LIKE %s",
                'INV-' . $date . '-%'
            )
        );

        $sequence = str_pad( absint( $count ) + 1, 4, '0', STR_PAD_LEFT );

        return 'INV-' . $date . '-' . $sequence;
    }

    /**
     * Build a description line item for the invoice.
     *
     * @param array|null $license The license data.
     * @param array      $payment The payment data.
     * @return string The description.
     */
    private function get_item_description( $license, $payment ) {
        $product_name = __( 'License', 'dlmues-server' );

        if ( $license && ! empty( $license['product_slug'] ) ) {
            global $wpdb;
            $name = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT product_name FROM {$wpdb->prefix}dlmues_products WHERE product_slug = %s",
                    $license['product_slug']
                )
            );
            if ( $name ) {
                $product_name = $name;
            }
        }

        $plan = ! empty( $payment['plan_duration'] ) ? ucfirst( $payment['plan_duration'] ) : '';

        if ( $plan ) {
            return sprintf(
                /* translators: 1: product name, 2: plan name */
                __( '%1$s - %2$s Subscription Renewal', 'dlmues-server' ),
                $product_name,
                $plan
            );
        }

        return sprintf(
            /* translators: %s: product name */
            __( '%s - License Subscription', 'dlmues-server' ),
            $product_name
        );
    }
}
