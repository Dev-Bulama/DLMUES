<?php
/**
 * Admin Dashboard for DLMUES License Server.
 *
 * Provides the WordPress admin interface for managing licenses,
 * payments, products, settings, and analytics.
 *
 * @package DLMUES_Server
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DLMUES_Admin_Dashboard
 */
class DLMUES_Admin_Dashboard {

    /**
     * Constructor – register AJAX handlers.
     */
    public function __construct() {
        add_action( 'wp_ajax_dlmues_update_license',       array( $this, 'ajax_update_license' ) );
        add_action( 'wp_ajax_dlmues_quick_suspend',        array( $this, 'ajax_quick_suspend' ) );
        add_action( 'wp_ajax_dlmues_quick_reactivate',     array( $this, 'ajax_quick_reactivate' ) );
        add_action( 'wp_ajax_dlmues_inject_visitor_count', array( $this, 'ajax_inject_visitor_count' ) );
        add_action( 'wp_ajax_dlmues_get_site_health',      array( $this, 'ajax_get_site_health' ) );
        add_action( 'wp_ajax_dlmues_bulk_import',          array( $this, 'ajax_bulk_import' ) );
        add_action( 'wp_ajax_dlmues_create_coupon',        array( $this, 'ajax_create_coupon' ) );
        add_action( 'wp_ajax_dlmues_delete_coupon',        array( $this, 'ajax_delete_coupon' ) );
        add_action( 'wp_ajax_dlmues_get_invoice',          array( $this, 'ajax_get_invoice' ) );
        add_action( 'wp_ajax_dlmues_send_reminder',        array( $this, 'ajax_send_reminder' ) );
        add_action( 'wp_ajax_dlmues_create_trial',         array( $this, 'ajax_create_trial' ) );
        add_action( 'wp_ajax_dlmues_test_paystack',        array( $this, 'ajax_test_paystack' ) );
    }

    /**
     * Register admin menus.
     */
    public function register_menus() {
        add_menu_page(
            __( 'DLMUES Licenses', 'dlmues-server' ),
            __( 'DLMUES', 'dlmues-server' ),
            'manage_options',
            'dlmues-dashboard',
            array( $this, 'render_dashboard_page' ),
            'dashicons-admin-network',
            58
        );

        add_submenu_page(
            'dlmues-dashboard',
            __( 'Dashboard', 'dlmues-server' ),
            __( 'Dashboard', 'dlmues-server' ),
            'manage_options',
            'dlmues-dashboard',
            array( $this, 'render_dashboard_page' )
        );

        add_submenu_page(
            'dlmues-dashboard',
            __( 'Licenses', 'dlmues-server' ),
            __( 'Licenses', 'dlmues-server' ),
            'manage_options',
            'dlmues-licenses',
            array( $this, 'render_licenses_page' )
        );

        add_submenu_page(
            'dlmues-dashboard',
            __( 'Payments', 'dlmues-server' ),
            __( 'Payments', 'dlmues-server' ),
            'manage_options',
            'dlmues-payments',
            array( $this, 'render_payments_page' )
        );

        add_submenu_page(
            'dlmues-dashboard',
            __( 'Products', 'dlmues-server' ),
            __( 'Products', 'dlmues-server' ),
            'manage_options',
            'dlmues-products',
            array( $this, 'render_products_page' )
        );

        add_submenu_page(
            'dlmues-dashboard',
            __( 'Clients', 'dlmues-server' ),
            __( 'Clients', 'dlmues-server' ),
            'manage_options',
            'dlmues-clients',
            array( $this, 'render_clients_page' )
        );

        add_submenu_page(
            'dlmues-dashboard',
            __( 'Invoices', 'dlmues-server' ),
            __( 'Invoices', 'dlmues-server' ),
            'manage_options',
            'dlmues-invoices',
            array( $this, 'render_invoices_page' )
        );

        add_submenu_page(
            'dlmues-dashboard',
            __( 'Coupons', 'dlmues-server' ),
            __( 'Coupons', 'dlmues-server' ),
            'manage_options',
            'dlmues-coupons',
            array( $this, 'render_coupons_page' )
        );

        add_submenu_page(
            'dlmues-dashboard',
            __( 'Bulk Import', 'dlmues-server' ),
            __( 'Bulk Import', 'dlmues-server' ),
            'manage_options',
            'dlmues-import',
            array( $this, 'render_import_page' )
        );

        add_submenu_page(
            'dlmues-dashboard',
            __( 'Settings', 'dlmues-server' ),
            __( 'Settings', 'dlmues-server' ),
            'manage_options',
            'dlmues-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Render the main dashboard page with analytics overview.
     */
    public function render_dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'dlmues-server' ) );
        }

        $license_engine = new DLMUES_License_Engine();
        $analytics      = $license_engine->get_usage_analytics();

        ?>
        <div class="wrap dlmues-admin">
            <h1><?php esc_html_e( 'DLMUES Dashboard', 'dlmues-server' ); ?></h1>

            <div class="dlmues-stats-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 20px 0;">
                <div class="dlmues-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px; border-left: 4px solid #0073aa;">
                    <h3 style="margin: 0; font-size: 28px; color: #0073aa;"><?php echo absint( $analytics['total_licenses'] ); ?></h3>
                    <p style="margin: 5px 0 0; color: #666;"><?php esc_html_e( 'Total Licenses', 'dlmues-server' ); ?></p>
                </div>
                <div class="dlmues-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px; border-left: 4px solid #46b450;">
                    <h3 style="margin: 0; font-size: 28px; color: #46b450;"><?php echo absint( isset( $analytics['status_breakdown']['active'] ) ? $analytics['status_breakdown']['active'] : 0 ); ?></h3>
                    <p style="margin: 5px 0 0; color: #666;"><?php esc_html_e( 'Active Licenses', 'dlmues-server' ); ?></p>
                </div>
                <div class="dlmues-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px; border-left: 4px solid #ffba00;">
                    <h3 style="margin: 0; font-size: 28px; color: #ffba00;"><?php echo absint( isset( $analytics['status_breakdown']['expired'] ) ? $analytics['status_breakdown']['expired'] : 0 ); ?></h3>
                    <p style="margin: 5px 0 0; color: #666;"><?php esc_html_e( 'Expired', 'dlmues-server' ); ?></p>
                </div>
                <div class="dlmues-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px; border-left: 4px solid #0073aa;">
                    <h3 style="margin: 0; font-size: 28px; color: #0073aa;">$<?php echo esc_html( number_format( $analytics['total_revenue'], 2 ) ); ?></h3>
                    <p style="margin: 5px 0 0; color: #666;"><?php esc_html_e( 'Total Revenue', 'dlmues-server' ); ?></p>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
                <div class="dlmues-panel" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
                    <h2><?php esc_html_e( 'Revenue (Last 12 Months)', 'dlmues-server' ); ?></h2>
                    <canvas id="dlmues-revenue-chart" height="200"></canvas>
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        if (typeof Chart !== 'undefined') {
                            var ctx = document.getElementById('dlmues-revenue-chart').getContext('2d');
                            new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: <?php echo wp_json_encode( array_column( $analytics['monthly_revenue'] ? $analytics['monthly_revenue'] : array(), 'month' ) ); ?>,
                                    datasets: [{
                                        label: '<?php esc_attr_e( 'Revenue', 'dlmues-server' ); ?>',
                                        data: <?php echo wp_json_encode( array_map( 'floatval', array_column( $analytics['monthly_revenue'] ? $analytics['monthly_revenue'] : array(), 'revenue' ) ) ); ?>,
                                        backgroundColor: 'rgba(0, 115, 170, 0.7)'
                                    }]
                                },
                                options: { responsive: true, scales: { y: { beginAtZero: true } } }
                            });
                        }
                    });
                    </script>
                </div>

                <div class="dlmues-panel" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
                    <h2><?php esc_html_e( 'Renewal Forecast', 'dlmues-server' ); ?></h2>
                    <?php if ( ! empty( $analytics['renewal_forecast'] ) ) : ?>
                        <table class="widefat" style="margin-top: 10px;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Window', 'dlmues-server' ); ?></th>
                                    <th><?php esc_html_e( 'Count', 'dlmues-server' ); ?></th>
                                    <th><?php esc_html_e( 'Revenue', 'dlmues-server' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $analytics['renewal_forecast'] as $window => $forecast ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( str_replace( '_', ' ', $window ) ); ?></td>
                                        <td><?php echo absint( $forecast['count'] ); ?></td>
                                        <td>$<?php echo esc_html( number_format( $forecast['potential_revenue'], 2 ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h2 style="margin-top: 20px;"><?php esc_html_e( 'Plan Distribution', 'dlmues-server' ); ?></h2>
                    <canvas id="dlmues-plan-chart" height="200"></canvas>
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        if (typeof Chart !== 'undefined') {
                            var ctx = document.getElementById('dlmues-plan-chart').getContext('2d');
                            new Chart(ctx, {
                                type: 'doughnut',
                                data: {
                                    labels: <?php echo wp_json_encode( array_keys( $analytics['plan_distribution'] ) ); ?>,
                                    datasets: [{
                                        data: <?php echo wp_json_encode( array_values( $analytics['plan_distribution'] ) ); ?>,
                                        backgroundColor: ['#0073aa', '#46b450', '#ffba00', '#dc3232']
                                    }]
                                },
                                options: { responsive: true }
                            });
                        }
                    });
                    </script>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the licenses management page.
     */
    public function render_licenses_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'dlmues-server' ) );
        }

        // Handle form submissions.
        $this->handle_license_actions();

        $license_engine = new DLMUES_License_Engine();

        $status  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        $search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $page    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

        $result = $license_engine->get_all_licenses( array(
            'status'   => $status,
            'search'   => $search,
            'page'     => $page,
            'per_page' => 25,
        ) );

        ?>
        <div class="wrap dlmues-admin">
            <h1>
                <?php esc_html_e( 'Licenses', 'dlmues-server' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dlmues-licenses&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'dlmues-server' ); ?></a>
            </h1>

            <?php $this->render_admin_notices(); ?>

            <?php if ( isset( $_GET['action'] ) && 'new' === $_GET['action'] ) : ?>
                <?php $this->render_new_license_form(); ?>
            <?php else : ?>

            <form method="get" style="margin: 15px 0;">
                <input type="hidden" name="page" value="dlmues-licenses">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <select name="status">
                        <option value=""><?php esc_html_e( 'All Statuses', 'dlmues-server' ); ?></option>
                        <option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'dlmues-server' ); ?></option>
                        <option value="expired" <?php selected( $status, 'expired' ); ?>><?php esc_html_e( 'Expired', 'dlmues-server' ); ?></option>
                        <option value="suspended" <?php selected( $status, 'suspended' ); ?>><?php esc_html_e( 'Suspended', 'dlmues-server' ); ?></option>
                        <option value="trial" <?php selected( $status, 'trial' ); ?>><?php esc_html_e( 'Trial', 'dlmues-server' ); ?></option>
                    </select>
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search licenses...', 'dlmues-server' ); ?>">
                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'dlmues-server' ); ?></button>
                </div>
            </form>

            <table class="widefat striped" id="dlmues-licenses-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'License Key', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Domain', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Product', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Plan', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Expires', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Price', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'dlmues-server' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $result['items'] ) ) : ?>
                        <tr><td colspan="9"><?php esc_html_e( 'No licenses found.', 'dlmues-server' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $result['items'] as $license ) : ?>
                            <?php
                            $security = new DLMUES_Security();
                            $status_class = 'active' === $license['status'] ? 'color: #46b450;' : ( 'expired' === $license['status'] ? 'color: #dc3232;' : 'color: #ffba00;' );
                            ?>
                            <tr>
                                <td><code style="font-size: 11px;"><?php echo esc_html( $security->mask_license_key( $license['license_key'] ) ); ?></code></td>
                                <td><?php echo esc_html( $license['client_email'] ); ?></td>
                                <td><?php echo esc_html( $license['client_domain'] ); ?></td>
                                <td><?php echo esc_html( $license['product_slug'] ); ?></td>
                                <td><span style="<?php echo esc_attr( $status_class ); ?> font-weight: bold;"><?php echo esc_html( ucfirst( $license['status'] ) ); ?></span></td>
                                <td><?php echo esc_html( ucfirst( $license['subscription_type'] ) ); ?></td>
                                <td><?php echo esc_html( $license['expires_at'] ); ?></td>
                                <td><?php echo esc_html( $license['currency'] . ' ' . number_format( $license['price'], 2 ) ); ?></td>
                                <td>
                                    <?php
                                    $suspend_url = wp_nonce_url(
                                        admin_url( 'admin.php?page=dlmues-licenses&action=suspend&license_key=' . rawurlencode( $license['license_key'] ) ),
                                        'dlmues_license_action'
                                    );
                                    $activate_url = wp_nonce_url(
                                        admin_url( 'admin.php?page=dlmues-licenses&action=reactivate&license_key=' . rawurlencode( $license['license_key'] ) ),
                                        'dlmues_license_action'
                                    );
                                    ?>
                                    <?php if ( 'active' === $license['status'] || 'trial' === $license['status'] ) : ?>
                                        <a href="<?php echo esc_url( $suspend_url ); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Suspend this license?', 'dlmues-server' ); ?>');"><?php esc_html_e( 'Suspend', 'dlmues-server' ); ?></a>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url( $activate_url ); ?>" class="button button-small button-primary"><?php esc_html_e( 'Reactivate', 'dlmues-server' ); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            $total_pages = ceil( $result['total'] / 25 );
            if ( $total_pages > 1 ) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo wp_kses_post( paginate_links( array(
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $total_pages,
                ) ) );
                echo '</div></div>';
            }
            ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the new license creation form.
     */
    private function render_new_license_form() {
        ?>
        <form method="post" style="max-width: 600px; background: #fff; padding: 20px; border: 1px solid #ddd; margin-top: 15px;">
            <?php wp_nonce_field( 'dlmues_create_license', 'dlmues_nonce' ); ?>
            <input type="hidden" name="dlmues_action" value="create_license">

            <table class="form-table">
                <tr>
                    <th><label for="client_email"><?php esc_html_e( 'Client Email', 'dlmues-server' ); ?></label></th>
                    <td><input type="email" name="client_email" id="client_email" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="product_slug"><?php esc_html_e( 'Product Slug', 'dlmues-server' ); ?></label></th>
                    <td><input type="text" name="product_slug" id="product_slug" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="subscription_type"><?php esc_html_e( 'Plan', 'dlmues-server' ); ?></label></th>
                    <td>
                        <select name="subscription_type" id="subscription_type">
                            <option value="monthly"><?php esc_html_e( 'Monthly', 'dlmues-server' ); ?></option>
                            <option value="bimonthly"><?php esc_html_e( 'Bi-Monthly', 'dlmues-server' ); ?></option>
                            <option value="quarterly"><?php esc_html_e( 'Quarterly', 'dlmues-server' ); ?></option>
                            <option value="yearly"><?php esc_html_e( 'Yearly', 'dlmues-server' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="currency"><?php esc_html_e( 'Currency', 'dlmues-server' ); ?></label></th>
                    <td>
                        <select name="currency" id="currency">
                            <option value="USD">USD</option>
                            <option value="NGN">NGN</option>
                            <option value="GBP">GBP</option>
                            <option value="EUR">EUR</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="notes"><?php esc_html_e( 'Notes', 'dlmues-server' ); ?></label></th>
                    <td><textarea name="notes" id="notes" class="large-text" rows="3"></textarea></td>
                </tr>
            </table>

            <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create License', 'dlmues-server' ); ?></button></p>
        </form>
        <?php
    }

    /**
     * Handle license admin actions (create, suspend, reactivate).
     */
    private function handle_license_actions() {
        // Create license.
        if ( isset( $_POST['dlmues_action'] ) && 'create_license' === $_POST['dlmues_action'] ) {
            if ( ! wp_verify_nonce( isset( $_POST['dlmues_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dlmues_nonce'] ) ) : '', 'dlmues_create_license' ) ) {
                return;
            }

            $license_engine = new DLMUES_License_Engine();
            $result = $license_engine->create_license( array(
                'client_email'      => isset( $_POST['client_email'] ) ? sanitize_email( wp_unslash( $_POST['client_email'] ) ) : '',
                'product_slug'      => isset( $_POST['product_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['product_slug'] ) ) : '',
                'subscription_type' => isset( $_POST['subscription_type'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_type'] ) ) : 'monthly',
                'currency'          => isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : 'USD',
                'notes'             => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
            ) );

            if ( is_wp_error( $result ) ) {
                add_settings_error( 'dlmues', 'create_failed', $result->get_error_message(), 'error' );
            } else {
                add_settings_error( 'dlmues', 'created', sprintf( __( 'License created: %s', 'dlmues-server' ), $result['license_key'] ), 'success' );
            }
        }

        // Suspend/Reactivate via URL actions.
        if ( isset( $_GET['action'] ) && isset( $_GET['license_key'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dlmues_license_action' ) ) {
                return;
            }

            $license_engine = new DLMUES_License_Engine();
            $license_key    = sanitize_text_field( wp_unslash( $_GET['license_key'] ) );

            if ( 'suspend' === $_GET['action'] ) {
                $license_engine->suspend_license( $license_key );
                add_settings_error( 'dlmues', 'suspended', __( 'License suspended.', 'dlmues-server' ), 'success' );
            } elseif ( 'reactivate' === $_GET['action'] ) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'dlmues_licenses',
                    array( 'status' => 'active' ),
                    array( 'license_key' => $license_key ),
                    array( '%s' ),
                    array( '%s' )
                );
                add_settings_error( 'dlmues', 'reactivated', __( 'License reactivated.', 'dlmues-server' ), 'success' );
            }
        }
    }

    /**
     * Render the payments page.
     */
    public function render_payments_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'dlmues-server' ) );
        }

        $paystack = new DLMUES_Paystack();
        $page     = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

        $result = $paystack->get_payments( array(
            'page'     => $page,
            'per_page' => 25,
            'search'   => $search,
            'status'   => $status,
        ) );

        ?>
        <div class="wrap dlmues-admin">
            <h1><?php esc_html_e( 'Payments', 'dlmues-server' ); ?></h1>

            <form method="get" style="margin: 15px 0;">
                <input type="hidden" name="page" value="dlmues-payments">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <select name="status">
                        <option value=""><?php esc_html_e( 'All', 'dlmues-server' ); ?></option>
                        <option value="success" <?php selected( $status, 'success' ); ?>><?php esc_html_e( 'Success', 'dlmues-server' ); ?></option>
                        <option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'dlmues-server' ); ?></option>
                    </select>
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search payments...', 'dlmues-server' ); ?>">
                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'dlmues-server' ); ?></button>
                </div>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Reference', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Amount', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Plan', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'dlmues-server' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $result['items'] ) ) : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No payments found.', 'dlmues-server' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $result['items'] as $payment ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $payment['payment_reference'] ); ?></code></td>
                                <td><?php echo esc_html( isset( $payment['client_email'] ) ? $payment['client_email'] : '' ); ?></td>
                                <td><?php echo esc_html( $payment['currency'] . ' ' . number_format( $payment['amount'], 2 ) ); ?></td>
                                <td>
                                    <?php
                                    $badge_color = 'success' === $payment['status'] ? '#46b450' : '#ffba00';
                                    printf(
                                        '<span style="color: %s; font-weight: bold;">%s</span>',
                                        esc_attr( $badge_color ),
                                        esc_html( ucfirst( $payment['status'] ) )
                                    );
                                    ?>
                                </td>
                                <td><?php echo esc_html( ucfirst( $payment['plan_duration'] ) ); ?></td>
                                <td><?php echo esc_html( $payment['created_at'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the products management page.
     */
    public function render_products_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'dlmues-server' ) );
        }

        $this->handle_product_actions();

        $update_manager = new DLMUES_Update_Manager();
        $products       = $update_manager->get_all_products();

        ?>
        <div class="wrap dlmues-admin">
            <h1>
                <?php esc_html_e( 'Products', 'dlmues-server' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dlmues-products&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'dlmues-server' ); ?></a>
            </h1>

            <?php $this->render_admin_notices(); ?>

            <?php if ( isset( $_GET['action'] ) && 'new' === $_GET['action'] ) : ?>
                <form method="post" enctype="multipart/form-data" style="max-width: 600px; background: #fff; padding: 20px; border: 1px solid #ddd; margin-top: 15px;">
                    <?php wp_nonce_field( 'dlmues_save_product', 'dlmues_nonce' ); ?>
                    <input type="hidden" name="dlmues_action" value="save_product">
                    <table class="form-table">
                        <tr>
                            <th><label for="product_slug"><?php esc_html_e( 'Product Slug', 'dlmues-server' ); ?></label></th>
                            <td><input type="text" name="product_slug" id="product_slug" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="product_name"><?php esc_html_e( 'Product Name', 'dlmues-server' ); ?></label></th>
                            <td><input type="text" name="product_name" id="product_name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="current_version"><?php esc_html_e( 'Current Version', 'dlmues-server' ); ?></label></th>
                            <td><input type="text" name="current_version" id="current_version" class="regular-text" value="1.0.0"></td>
                        </tr>
                        <tr>
                            <th><label for="description"><?php esc_html_e( 'Description', 'dlmues-server' ); ?></label></th>
                            <td><textarea name="description" id="description" class="large-text" rows="4"></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="update_package"><?php esc_html_e( 'Update Package (ZIP)', 'dlmues-server' ); ?></label></th>
                            <td><input type="file" name="update_package" id="update_package" accept=".zip"></td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Product', 'dlmues-server' ); ?></button></p>
                </form>
            <?php else : ?>
                <table class="widefat striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Slug', 'dlmues-server' ); ?></th>
                            <th><?php esc_html_e( 'Name', 'dlmues-server' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'dlmues-server' ); ?></th>
                            <th><?php esc_html_e( 'Version', 'dlmues-server' ); ?></th>
                            <th><?php esc_html_e( 'Updated', 'dlmues-server' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'dlmues-server' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $products ) ) : ?>
                            <tr><td colspan="6"><?php esc_html_e( 'No products found.', 'dlmues-server' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $products as $product ) : ?>
                                <tr>
                                    <td><code><?php echo esc_html( $product['product_slug'] ); ?></code></td>
                                    <td><?php echo esc_html( $product['product_name'] ); ?></td>
                                    <td><?php echo esc_html( ucfirst( $product['product_type'] ) ); ?></td>
                                    <td><?php echo esc_html( $product['current_version'] ); ?></td>
                                    <td><?php echo esc_html( $product['updated_at'] ); ?></td>
                                    <td>
                                        <?php
                                        $delete_url = wp_nonce_url(
                                            admin_url( 'admin.php?page=dlmues-products&action=delete&product_id=' . $product['id'] ),
                                            'dlmues_product_action'
                                        );
                                        ?>
                                        <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Delete this product?', 'dlmues-server' ); ?>');"><?php esc_html_e( 'Delete', 'dlmues-server' ); ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle product admin actions.
     */
    private function handle_product_actions() {
        if ( isset( $_POST['dlmues_action'] ) && 'save_product' === $_POST['dlmues_action'] ) {
            if ( ! wp_verify_nonce( isset( $_POST['dlmues_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dlmues_nonce'] ) ) : '', 'dlmues_save_product' ) ) {
                return;
            }

            $update_manager = new DLMUES_Update_Manager();
            $product_slug   = isset( $_POST['product_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['product_slug'] ) ) : '';

            // Handle file upload.
            $package_url = '';
            if ( ! empty( $_FILES['update_package']['tmp_name'] ) ) {
                $upload_result = $update_manager->upload_package( $_FILES['update_package'], $product_slug );
                if ( ! is_wp_error( $upload_result ) ) {
                    $package_url = $upload_result;
                }
            }

            $result = $update_manager->save_product( array(
                'product_slug'       => $product_slug,
                'product_name'       => isset( $_POST['product_name'] ) ? sanitize_text_field( wp_unslash( $_POST['product_name'] ) ) : '',
                'current_version'    => isset( $_POST['current_version'] ) ? sanitize_text_field( wp_unslash( $_POST['current_version'] ) ) : '1.0.0',
                'description'        => isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '',
                'update_package_url' => $package_url,
            ) );

            if ( is_wp_error( $result ) ) {
                add_settings_error( 'dlmues', 'save_failed', $result->get_error_message(), 'error' );
            } else {
                add_settings_error( 'dlmues', 'saved', __( 'Product saved.', 'dlmues-server' ), 'success' );
            }
        }

        // Delete product.
        if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['product_id'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dlmues_product_action' ) ) {
                $update_manager = new DLMUES_Update_Manager();
                $update_manager->delete_product( absint( $_GET['product_id'] ) );
                add_settings_error( 'dlmues', 'deleted', __( 'Product deleted.', 'dlmues-server' ), 'success' );
            }
        }
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'dlmues-server' ) );
        }

        $this->handle_settings_save();

        ?>
        <div class="wrap dlmues-admin">
            <h1><?php esc_html_e( 'DLMUES Settings', 'dlmues-server' ); ?></h1>

            <?php $this->render_admin_notices(); ?>

            <form method="post">
                <?php wp_nonce_field( 'dlmues_save_settings', 'dlmues_settings_nonce' ); ?>
                <input type="hidden" name="dlmues_action" value="save_settings">

                <h2><?php esc_html_e( 'General Settings', 'dlmues-server' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="dlmues_company_name"><?php esc_html_e( 'Company Name', 'dlmues-server' ); ?></label></th>
                        <td><input type="text" name="dlmues_company_name" id="dlmues_company_name" class="regular-text" value="<?php echo esc_attr( get_option( 'dlmues_company_name', '' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="dlmues_currency"><?php esc_html_e( 'Default Currency', 'dlmues-server' ); ?></label></th>
                        <td>
                            <select name="dlmues_currency" id="dlmues_currency">
                                <?php
                                $current_currency = get_option( 'dlmues_currency', 'USD' );
                                $currencies = array( 'USD', 'NGN', 'GBP', 'EUR', 'GHS', 'ZAR', 'KES' );
                                foreach ( $currencies as $curr ) {
                                    printf( '<option value="%s" %s>%s</option>', esc_attr( $curr ), selected( $current_currency, $curr, false ), esc_html( $curr ) );
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="dlmues_default_grace_period"><?php esc_html_e( 'Default Grace Period (days)', 'dlmues-server' ); ?></label></th>
                        <td><input type="number" name="dlmues_default_grace_period" id="dlmues_default_grace_period" value="<?php echo absint( get_option( 'dlmues_default_grace_period', 7 ) ); ?>" min="0" max="90"></td>
                    </tr>
                    <tr>
                        <th><label for="dlmues_enforcement_mode"><?php esc_html_e( 'Default Enforcement Mode', 'dlmues-server' ); ?></label></th>
                        <td>
                            <select name="dlmues_enforcement_mode" id="dlmues_enforcement_mode">
                                <?php $current_mode = get_option( 'dlmues_enforcement_mode', 'restrict_admin' ); ?>
                                <option value="restrict_admin" <?php selected( $current_mode, 'restrict_admin' ); ?>><?php esc_html_e( 'Restrict Admin', 'dlmues-server' ); ?></option>
                                <option value="lock_frontend" <?php selected( $current_mode, 'lock_frontend' ); ?>><?php esc_html_e( 'Lock Frontend', 'dlmues-server' ); ?></option>
                                <option value="maintenance" <?php selected( $current_mode, 'maintenance' ); ?>><?php esc_html_e( 'Maintenance Mode', 'dlmues-server' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="dlmues_default_trial_days"><?php esc_html_e( 'Default Trial Period (days)', 'dlmues-server' ); ?></label></th>
                        <td>
                            <input type="number" name="dlmues_default_trial_days" id="dlmues_default_trial_days" value="<?php echo absint( get_option( 'dlmues_default_trial_days', 14 ) ); ?>" min="0" max="365">
                            <p class="description"><?php esc_html_e( 'Default number of trial days for new trial licenses. Set to 0 to disable trials.', 'dlmues-server' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Pricing', 'dlmues-server' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="dlmues_pricing_monthly"><?php esc_html_e( 'Monthly Price (USD)', 'dlmues-server' ); ?></label></th>
                        <td><input type="number" name="dlmues_pricing_monthly" id="dlmues_pricing_monthly" step="0.01" value="<?php echo esc_attr( get_option( 'dlmues_pricing_monthly', '9.99' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="dlmues_pricing_bimonthly"><?php esc_html_e( 'Bi-Monthly Price (USD)', 'dlmues-server' ); ?></label></th>
                        <td><input type="number" name="dlmues_pricing_bimonthly" id="dlmues_pricing_bimonthly" step="0.01" value="<?php echo esc_attr( get_option( 'dlmues_pricing_bimonthly', '17.99' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="dlmues_pricing_quarterly"><?php esc_html_e( 'Quarterly Price (USD)', 'dlmues-server' ); ?></label></th>
                        <td><input type="number" name="dlmues_pricing_quarterly" id="dlmues_pricing_quarterly" step="0.01" value="<?php echo esc_attr( get_option( 'dlmues_pricing_quarterly', '24.99' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="dlmues_pricing_yearly"><?php esc_html_e( 'Yearly Price (USD)', 'dlmues-server' ); ?></label></th>
                        <td><input type="number" name="dlmues_pricing_yearly" id="dlmues_pricing_yearly" step="0.01" value="<?php echo esc_attr( get_option( 'dlmues_pricing_yearly', '89.99' ) ); ?>"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Paystack Settings', 'dlmues-server' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="dlmues_paystack_test_mode"><?php esc_html_e( 'Test Mode', 'dlmues-server' ); ?></label></th>
                        <td><label><input type="checkbox" name="dlmues_paystack_test_mode" id="dlmues_paystack_test_mode" value="1" <?php checked( get_option( 'dlmues_paystack_test_mode', 1 ) ); ?>> <?php esc_html_e( 'Enable Test Mode', 'dlmues-server' ); ?></label></td>
                    </tr>
                    <tr>
                        <th><label for="dlmues_paystack_test_public_key"><?php esc_html_e( 'Test Public Key', 'dlmues-server' ); ?></label></th>
                        <td><input type="text" name="dlmues_paystack_test_public_key" id="dlmues_paystack_test_public_key" class="regular-text" value="<?php echo esc_attr( get_option( 'dlmues_paystack_test_public_key', '' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="dlmues_paystack_test_secret_key"><?php esc_html_e( 'Test Secret Key', 'dlmues-server' ); ?></label></th>
                        <td><input type="password" name="dlmues_paystack_test_secret_key" id="dlmues_paystack_test_secret_key" class="regular-text" placeholder="<?php esc_attr_e( 'Enter to update', 'dlmues-server' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="dlmues_paystack_public_key"><?php esc_html_e( 'Live Public Key', 'dlmues-server' ); ?></label></th>
                        <td><input type="text" name="dlmues_paystack_public_key" id="dlmues_paystack_public_key" class="regular-text" value="<?php echo esc_attr( get_option( 'dlmues_paystack_public_key', '' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="dlmues_paystack_secret_key"><?php esc_html_e( 'Live Secret Key', 'dlmues-server' ); ?></label></th>
                        <td><input type="password" name="dlmues_paystack_secret_key" id="dlmues_paystack_secret_key" class="regular-text" placeholder="<?php esc_attr_e( 'Enter to update', 'dlmues-server' ); ?>"></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handle settings form save.
     */
    private function handle_settings_save() {
        if ( ! isset( $_POST['dlmues_action'] ) || 'save_settings' !== $_POST['dlmues_action'] ) {
            return;
        }

        if ( ! wp_verify_nonce( isset( $_POST['dlmues_settings_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dlmues_settings_nonce'] ) ) : '', 'dlmues_save_settings' ) ) {
            return;
        }

        $text_options = array(
            'dlmues_company_name',
            'dlmues_currency',
            'dlmues_enforcement_mode',
        );

        foreach ( $text_options as $option ) {
            if ( isset( $_POST[ $option ] ) ) {
                update_option( $option, sanitize_text_field( wp_unslash( $_POST[ $option ] ) ) );
            }
        }

        $number_options = array(
            'dlmues_default_grace_period',
            'dlmues_default_trial_days',
            'dlmues_pricing_monthly',
            'dlmues_pricing_bimonthly',
            'dlmues_pricing_quarterly',
            'dlmues_pricing_yearly',
        );

        foreach ( $number_options as $option ) {
            if ( isset( $_POST[ $option ] ) ) {
                update_option( $option, floatval( wp_unslash( $_POST[ $option ] ) ) );
            }
        }

        // Checkbox.
        update_option( 'dlmues_paystack_test_mode', isset( $_POST['dlmues_paystack_test_mode'] ) ? 1 : 0 );

        // Paystack public keys.
        if ( isset( $_POST['dlmues_paystack_test_public_key'] ) ) {
            update_option( 'dlmues_paystack_test_public_key', sanitize_text_field( wp_unslash( $_POST['dlmues_paystack_test_public_key'] ) ) );
        }
        if ( isset( $_POST['dlmues_paystack_public_key'] ) ) {
            update_option( 'dlmues_paystack_public_key', sanitize_text_field( wp_unslash( $_POST['dlmues_paystack_public_key'] ) ) );
        }

        // Paystack secret keys (encrypted).
        $paystack = new DLMUES_Paystack();
        if ( ! empty( $_POST['dlmues_paystack_test_secret_key'] ) ) {
            $paystack->save_secret_key( sanitize_text_field( wp_unslash( $_POST['dlmues_paystack_test_secret_key'] ) ), true );
        }
        if ( ! empty( $_POST['dlmues_paystack_secret_key'] ) ) {
            $paystack->save_secret_key( sanitize_text_field( wp_unslash( $_POST['dlmues_paystack_secret_key'] ) ), false );
        }

        add_settings_error( 'dlmues', 'settings_saved', __( 'Settings saved.', 'dlmues-server' ), 'success' );
    }

    /**
     * Render admin notices.
     */
    private function render_admin_notices() {
        settings_errors( 'dlmues' );
    }

    /* =================================================================
     *  CLIENTS PAGE
     * ================================================================= */

    /**
     * Render the clients management page.
     */
    public function render_clients_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'dlmues-server' ) );
        }

        global $wpdb;
        $license_table = $wpdb->prefix . 'dlmues_licenses';
        $health_table  = $wpdb->prefix . 'dlmues_site_health';

        $status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

        $where = "WHERE l.client_domain != ''";
        $args  = array();

        if ( ! empty( $status ) ) {
            $where .= ' AND l.status = %s';
            $args[] = $status;
        }
        if ( ! empty( $search ) ) {
            $where .= ' AND (l.client_domain LIKE %s OR l.client_email LIKE %s OR l.product_slug LIKE %s)';
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $args[]  = $like;
            $args[]  = $like;
            $args[]  = $like;
        }

        $sql = "SELECT l.*, h.wp_version, h.active_theme, h.plugin_list, h.php_version, h.server_software, h.last_reported AS health_reported
                FROM {$license_table} l
                LEFT JOIN {$health_table} h ON h.license_id = l.id
                {$where}
                ORDER BY l.last_check_in DESC";

        if ( ! empty( $args ) ) {
            $sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $clients = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        ?>
        <div class="wrap dlmues-admin">
            <h1><?php esc_html_e( 'Client Management', 'dlmues-server' ); ?></h1>

            <?php $this->render_admin_notices(); ?>

            <div class="dlmues-filter-bar">
                <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="page" value="dlmues-clients">
                    <select name="status">
                        <option value=""><?php esc_html_e( 'All Statuses', 'dlmues-server' ); ?></option>
                        <option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'dlmues-server' ); ?></option>
                        <option value="expired" <?php selected( $status, 'expired' ); ?>><?php esc_html_e( 'Expired', 'dlmues-server' ); ?></option>
                        <option value="suspended" <?php selected( $status, 'suspended' ); ?>><?php esc_html_e( 'Suspended', 'dlmues-server' ); ?></option>
                        <option value="trial" <?php selected( $status, 'trial' ); ?>><?php esc_html_e( 'Trial', 'dlmues-server' ); ?></option>
                    </select>
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search domain / email / product...', 'dlmues-server' ); ?>">
                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'dlmues-server' ); ?></button>
                </form>
            </div>

            <table class="widefat striped" id="dlmues-clients-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Domain', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Product', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Plan', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Expires', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Last Payment', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Visitors', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Health', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'dlmues-server' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $clients ) ) : ?>
                        <tr><td colspan="10"><?php esc_html_e( 'No connected clients found.', 'dlmues-server' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $clients as $client ) : ?>
                            <?php $safe_key = esc_attr( preg_replace( '/[^a-zA-Z0-9]/', '-', $client['license_key'] ) ); ?>
                            <tr class="dlmues-client-row">
                                <td><strong><?php echo esc_html( $client['client_domain'] ); ?></strong></td>
                                <td><?php echo esc_html( $client['client_email'] ); ?></td>
                                <td><code><?php echo esc_html( $client['product_slug'] ); ?></code> (<?php echo esc_html( $client['product_type'] ); ?>)</td>
                                <td class="dlmues-status-cell">
                                    <span class="dlmues-badge dlmues-badge-<?php echo esc_attr( $client['status'] ); ?>"><?php echo esc_html( ucfirst( $client['status'] ) ); ?></span>
                                </td>
                                <td><?php echo esc_html( ucfirst( $client['subscription_type'] ) ); ?> &mdash; <?php echo esc_html( $client['currency'] . ' ' . number_format( (float) $client['price'], 2 ) ); ?></td>
                                <td><?php echo esc_html( $client['expires_at'] ); ?></td>
                                <td><?php echo esc_html( $client['last_payment_date'] ); ?></td>
                                <td>
                                    <span class="dlmues-visitor-display"><?php echo absint( $client['visitor_count'] + $client['injected_visitor_count'] ); ?></span>
                                    <div style="margin-top:4px;display:flex;gap:4px;align-items:center;">
                                        <input type="number" min="0" class="dlmues-visitor-inject-input" style="width:70px;font-size:12px;" placeholder="0">
                                        <button type="button" class="button button-small dlmues-inject-visitors" data-license-key="<?php echo esc_attr( $client['license_key'] ); ?>"><?php esc_html_e( 'Set', 'dlmues-server' ); ?></button>
                                    </div>
                                </td>
                                <td>
                                    <?php if ( ! empty( $client['health_reported'] ) ) : ?>
                                        <button type="button" class="button button-small dlmues-view-health" data-license-key="<?php echo esc_attr( $client['license_key'] ); ?>">
                                            <?php esc_html_e( 'View', 'dlmues-server' ); ?>
                                        </button>
                                    <?php else : ?>
                                        <em style="color:#999;font-size:12px;"><?php esc_html_e( 'None', 'dlmues-server' ); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="dlmues-actions">
                                        <button type="button" class="button button-small dlmues-edit-btn" data-license-key="<?php echo esc_attr( $client['license_key'] ); ?>"><?php esc_html_e( 'Edit', 'dlmues-server' ); ?></button>
                                        <?php if ( 'active' === $client['status'] || 'trial' === $client['status'] ) : ?>
                                            <button type="button" class="button button-small dlmues-quick-suspend" data-license-key="<?php echo esc_attr( $client['license_key'] ); ?>"><?php esc_html_e( 'Suspend', 'dlmues-server' ); ?></button>
                                        <?php else : ?>
                                            <button type="button" class="button button-small dlmues-quick-reactivate" data-license-key="<?php echo esc_attr( $client['license_key'] ); ?>"><?php esc_html_e( 'Reactivate', 'dlmues-server' ); ?></button>
                                        <?php endif; ?>
                                        <button type="button" class="button button-small dlmues-send-reminder" data-license-key="<?php echo esc_attr( $client['license_key'] ); ?>"><?php esc_html_e( 'Remind', 'dlmues-server' ); ?></button>
                                    </div>
                                </td>
                            </tr>
                            <!-- Inline edit row -->
                            <tr class="dlmues-inline-edit-row" id="dlmues-edit-<?php echo esc_attr( $safe_key ); ?>">
                                <td colspan="10">
                                    <div class="dlmues-inline-edit-grid">
                                        <label><?php esc_html_e( 'Plan', 'dlmues-server' ); ?>
                                            <select name="subscription_type">
                                                <option value="monthly" <?php selected( $client['subscription_type'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'dlmues-server' ); ?></option>
                                                <option value="bimonthly" <?php selected( $client['subscription_type'], 'bimonthly' ); ?>><?php esc_html_e( 'Bi-Monthly', 'dlmues-server' ); ?></option>
                                                <option value="quarterly" <?php selected( $client['subscription_type'], 'quarterly' ); ?>><?php esc_html_e( 'Quarterly', 'dlmues-server' ); ?></option>
                                                <option value="yearly" <?php selected( $client['subscription_type'], 'yearly' ); ?>><?php esc_html_e( 'Yearly', 'dlmues-server' ); ?></option>
                                            </select>
                                        </label>
                                        <label><?php esc_html_e( 'Price', 'dlmues-server' ); ?>
                                            <input type="number" name="price" step="0.01" value="<?php echo esc_attr( $client['price'] ); ?>">
                                        </label>
                                        <label><?php esc_html_e( 'Currency', 'dlmues-server' ); ?>
                                            <select name="currency">
                                                <?php foreach ( array( 'USD', 'NGN', 'GBP', 'EUR', 'GHS', 'ZAR', 'KES' ) as $c ) : ?>
                                                    <option value="<?php echo esc_attr( $c ); ?>" <?php selected( $client['currency'], $c ); ?>><?php echo esc_html( $c ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label><?php esc_html_e( 'Grace Period (days)', 'dlmues-server' ); ?>
                                            <input type="number" name="grace_period_days" min="0" max="90" value="<?php echo absint( $client['grace_period_days'] ); ?>">
                                        </label>
                                        <label><?php esc_html_e( 'Enforcement', 'dlmues-server' ); ?>
                                            <select name="enforcement_mode">
                                                <option value="restrict_admin" <?php selected( $client['enforcement_mode'], 'restrict_admin' ); ?>><?php esc_html_e( 'Restrict Admin', 'dlmues-server' ); ?></option>
                                                <option value="lock_frontend" <?php selected( $client['enforcement_mode'], 'lock_frontend' ); ?>><?php esc_html_e( 'Lock Frontend', 'dlmues-server' ); ?></option>
                                                <option value="maintenance" <?php selected( $client['enforcement_mode'], 'maintenance' ); ?>><?php esc_html_e( 'Maintenance', 'dlmues-server' ); ?></option>
                                            </select>
                                        </label>
                                        <label><?php esc_html_e( 'Notes', 'dlmues-server' ); ?>
                                            <input type="text" name="notes" value="<?php echo esc_attr( $client['notes'] ); ?>">
                                        </label>
                                    </div>
                                    <div style="display:flex;gap:8px;">
                                        <button type="button" class="button button-primary button-small dlmues-save-edit" data-license-key="<?php echo esc_attr( $client['license_key'] ); ?>"><?php esc_html_e( 'Save Changes', 'dlmues-server' ); ?></button>
                                        <button type="button" class="button button-small dlmues-cancel-edit"><?php esc_html_e( 'Cancel', 'dlmues-server' ); ?></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Site health popup -->
            <div id="dlmues-popup-overlay" class="dlmues-popup-overlay"></div>
            <div id="dlmues-site-health-popup" class="dlmues-site-health-popup">
                <div class="dlmues-popup-header">
                    <h3><?php esc_html_e( 'Site Health Details', 'dlmues-server' ); ?></h3>
                    <button type="button" class="dlmues-popup-close">&times;</button>
                </div>
                <div class="dlmues-popup-body"></div>
            </div>
        </div>
        <?php
    }

    /* =================================================================
     *  INVOICES PAGE
     * ================================================================= */

    /**
     * Render the invoices page.
     */
    public function render_invoices_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'dlmues-server' ) );
        }

        global $wpdb;
        $inv_table = $wpdb->prefix . 'dlmues_invoices';
        $pay_table = $wpdb->prefix . 'dlmues_payments';
        $lic_table = $wpdb->prefix . 'dlmues_licenses';

        $invoices = $wpdb->get_results(
            "SELECT i.*, p.amount, p.currency, p.status AS payment_status, p.plan_duration,
                    l.client_email, l.client_domain
             FROM {$inv_table} i
             LEFT JOIN {$pay_table} p ON p.id = i.payment_id
             LEFT JOIN {$lic_table} l ON l.id = i.license_id
             ORDER BY i.created_at DESC
             LIMIT 200",
            ARRAY_A
        );

        ?>
        <div class="wrap dlmues-admin">
            <h1><?php esc_html_e( 'Invoices', 'dlmues-server' ); ?></h1>

            <table class="widefat striped" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Invoice #', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Client', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Amount', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Plan', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'dlmues-server' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $invoices ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No invoices found.', 'dlmues-server' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $invoices as $inv ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $inv['invoice_number'] ); ?></code></td>
                                <td>
                                    <?php echo esc_html( isset( $inv['client_email'] ) ? $inv['client_email'] : '' ); ?>
                                    <?php if ( ! empty( $inv['client_domain'] ) ) : ?>
                                        <br><small style="color:#666;"><?php echo esc_html( $inv['client_domain'] ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( ( isset( $inv['currency'] ) ? $inv['currency'] : '' ) . ' ' . number_format( (float) ( isset( $inv['amount'] ) ? $inv['amount'] : 0 ), 2 ) ); ?></td>
                                <td><?php echo esc_html( ucfirst( isset( $inv['plan_duration'] ) ? $inv['plan_duration'] : '' ) ); ?></td>
                                <td>
                                    <?php
                                    $ps = isset( $inv['payment_status'] ) ? $inv['payment_status'] : 'pending';
                                    $badge = 'success' === $ps ? 'dlmues-badge-success' : 'dlmues-badge-pending';
                                    ?>
                                    <span class="dlmues-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( ucfirst( $ps ) ); ?></span>
                                </td>
                                <td><?php echo esc_html( $inv['created_at'] ); ?></td>
                                <td>
                                    <button type="button" class="button button-small dlmues-view-invoice" data-id="<?php echo absint( $inv['id'] ); ?>">
                                        <?php esc_html_e( 'View', 'dlmues-server' ); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Invoice popup -->
            <div id="dlmues-invoice-popup-overlay" class="dlmues-popup-overlay"></div>
            <div id="dlmues-invoice-popup" class="dlmues-site-health-popup" style="display:none;">
                <div class="dlmues-popup-header">
                    <h3><?php esc_html_e( 'Invoice', 'dlmues-server' ); ?></h3>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <button type="button" id="dlmues-print-invoice" class="button button-small" style="color:#fff;border-color:#fff;"><?php esc_html_e( 'Print', 'dlmues-server' ); ?></button>
                        <button type="button" class="dlmues-popup-close" id="dlmues-invoice-popup-close">&times;</button>
                    </div>
                </div>
                <div class="dlmues-popup-body" id="dlmues-invoice-content"></div>
            </div>
        </div>
        <?php
    }

    /* =================================================================
     *  COUPONS PAGE
     * ================================================================= */

    /**
     * Render the coupons management page.
     */
    public function render_coupons_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'dlmues-server' ) );
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'dlmues_coupons';
        $coupons = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );

        ?>
        <div class="wrap dlmues-admin">
            <h1><?php esc_html_e( 'Renewal Coupons', 'dlmues-server' ); ?></h1>

            <?php $this->render_admin_notices(); ?>

            <div class="dlmues-coupon-form">
                <h3><?php esc_html_e( 'Create Coupon', 'dlmues-server' ); ?></h3>
                <form id="dlmues-create-coupon-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="coupon-code"><?php esc_html_e( 'Coupon Code', 'dlmues-server' ); ?></label></th>
                            <td><input type="text" id="coupon-code" class="regular-text" required placeholder="e.g. SAVE20"></td>
                        </tr>
                        <tr>
                            <th><label for="coupon-type"><?php esc_html_e( 'Discount Type', 'dlmues-server' ); ?></label></th>
                            <td>
                                <select id="coupon-type">
                                    <option value="percentage"><?php esc_html_e( 'Percentage', 'dlmues-server' ); ?></option>
                                    <option value="fixed"><?php esc_html_e( 'Fixed Amount', 'dlmues-server' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="coupon-value"><?php esc_html_e( 'Discount Value', 'dlmues-server' ); ?></label></th>
                            <td><input type="number" id="coupon-value" step="0.01" min="0" required></td>
                        </tr>
                        <tr>
                            <th><label for="coupon-max-uses"><?php esc_html_e( 'Max Uses (0 = unlimited)', 'dlmues-server' ); ?></label></th>
                            <td><input type="number" id="coupon-max-uses" min="0" value="0"></td>
                        </tr>
                        <tr>
                            <th><label for="coupon-valid-from"><?php esc_html_e( 'Valid From', 'dlmues-server' ); ?></label></th>
                            <td><input type="datetime-local" id="coupon-valid-from"></td>
                        </tr>
                        <tr>
                            <th><label for="coupon-valid-until"><?php esc_html_e( 'Valid Until', 'dlmues-server' ); ?></label></th>
                            <td><input type="datetime-local" id="coupon-valid-until"></td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create Coupon', 'dlmues-server' ); ?></button></p>
                </form>
            </div>

            <table class="widefat striped" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Code', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Value', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Used / Max', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Valid From', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Valid Until', 'dlmues-server' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'dlmues-server' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $coupons ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No coupons found.', 'dlmues-server' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $coupons as $coupon ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $coupon['code'] ); ?></code></td>
                                <td><?php echo esc_html( ucfirst( $coupon['discount_type'] ) ); ?></td>
                                <td>
                                    <?php
                                    if ( 'percentage' === $coupon['discount_type'] ) {
                                        echo esc_html( $coupon['discount_value'] . '%' );
                                    } else {
                                        echo esc_html( number_format( (float) $coupon['discount_value'], 2 ) );
                                    }
                                    ?>
                                </td>
                                <td><?php echo absint( $coupon['used_count'] ); ?> / <?php echo ( 0 === (int) $coupon['max_uses'] ) ? '&infin;' : absint( $coupon['max_uses'] ); ?></td>
                                <td><?php echo esc_html( $coupon['valid_from'] > '0000-00-00' ? $coupon['valid_from'] : '—' ); ?></td>
                                <td><?php echo esc_html( $coupon['valid_until'] > '0000-00-00' ? $coupon['valid_until'] : '—' ); ?></td>
                                <td>
                                    <button type="button" class="button button-small dlmues-delete-coupon" data-id="<?php echo absint( $coupon['id'] ); ?>"><?php esc_html_e( 'Delete', 'dlmues-server' ); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* =================================================================
     *  BULK IMPORT PAGE
     * ================================================================= */

    /**
     * Render the bulk import page.
     */
    public function render_import_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'dlmues-server' ) );
        }

        ?>
        <div class="wrap dlmues-admin">
            <h1><?php esc_html_e( 'Bulk Client Import', 'dlmues-server' ); ?></h1>

            <?php $this->render_admin_notices(); ?>

            <div class="dlmues-import-section">
                <h3><?php esc_html_e( 'Import Clients from CSV', 'dlmues-server' ); ?></h3>
                <p><?php esc_html_e( 'Upload a CSV file with the following columns: client_email, client_domain, product_slug, product_type, subscription_type, price, currency', 'dlmues-server' ); ?></p>
                <p><a href="#" id="dlmues-download-csv-template" class="button button-small"><?php esc_html_e( 'Download CSV Template', 'dlmues-server' ); ?></a></p>

                <form id="dlmues-bulk-import-form" style="margin-top:15px;">
                    <table class="form-table">
                        <tr>
                            <th><label for="dlmues-import-file"><?php esc_html_e( 'CSV File', 'dlmues-server' ); ?></label></th>
                            <td><input type="file" id="dlmues-import-file" accept=".csv" required></td>
                        </tr>
                        <tr>
                            <th><label for="dlmues-import-default-plan"><?php esc_html_e( 'Default Plan', 'dlmues-server' ); ?></label></th>
                            <td>
                                <select id="dlmues-import-default-plan">
                                    <option value="monthly"><?php esc_html_e( 'Monthly', 'dlmues-server' ); ?></option>
                                    <option value="bimonthly"><?php esc_html_e( 'Bi-Monthly', 'dlmues-server' ); ?></option>
                                    <option value="quarterly"><?php esc_html_e( 'Quarterly', 'dlmues-server' ); ?></option>
                                    <option value="yearly"><?php esc_html_e( 'Yearly', 'dlmues-server' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="dlmues-import-default-currency"><?php esc_html_e( 'Default Currency', 'dlmues-server' ); ?></label></th>
                            <td>
                                <select id="dlmues-import-default-currency">
                                    <?php foreach ( array( 'USD', 'NGN', 'GBP', 'EUR', 'GHS', 'ZAR', 'KES' ) as $c ) : ?>
                                        <option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Options', 'dlmues-server' ); ?></th>
                            <td>
                                <label><input type="checkbox" id="dlmues-import-send-email" value="1"> <?php esc_html_e( 'Send welcome email with license key', 'dlmues-server' ); ?></label>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import CSV', 'dlmues-server' ); ?></button></p>
                </form>

                <div id="dlmues-import-results"></div>
            </div>

            <div class="dlmues-import-section" style="margin-top:20px;">
                <h3><?php esc_html_e( 'Create Trial License', 'dlmues-server' ); ?></h3>
                <form id="dlmues-create-trial-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="trial_email"><?php esc_html_e( 'Client Email', 'dlmues-server' ); ?></label></th>
                            <td><input type="email" name="trial_email" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="trial_product"><?php esc_html_e( 'Product Slug', 'dlmues-server' ); ?></label></th>
                            <td><input type="text" name="trial_product" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="trial_days"><?php esc_html_e( 'Trial Days', 'dlmues-server' ); ?></label></th>
                            <td><input type="number" name="trial_days" value="<?php echo absint( get_option( 'dlmues_default_trial_days', 14 ) ); ?>" min="1" max="365"></td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create Trial', 'dlmues-server' ); ?></button></p>
                </form>
            </div>

            <script>
            jQuery('#dlmues-download-csv-template').on('click', function(e) {
                e.preventDefault();
                var csv = 'client_email,client_domain,product_slug,product_type,subscription_type,price,currency\n';
                csv += 'client@example.com,example.com,my-plugin,plugin,monthly,9.99,USD\n';
                var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                var link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = 'dlmues-import-template.csv';
                link.click();
            });
            </script>
        </div>
        <?php
    }

    /* =================================================================
     *  AJAX HANDLERS
     * ================================================================= */

    /**
     * AJAX: Update a license (inline edit from clients page).
     */
    public function ajax_update_license() {
        check_ajax_referer( 'dlmues_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dlmues-server' ) ) );
        }

        global $wpdb;
        $table       = $wpdb->prefix . 'dlmues_licenses';
        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

        if ( empty( $license_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing license key.', 'dlmues-server' ) ) );
        }

        $data = array(
            'subscription_type' => isset( $_POST['subscription_type'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_type'] ) ) : 'monthly',
            'price'             => isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0,
            'currency'          => isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : 'USD',
            'grace_period_days' => isset( $_POST['grace_period_days'] ) ? absint( $_POST['grace_period_days'] ) : 7,
            'enforcement_mode'  => isset( $_POST['enforcement_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['enforcement_mode'] ) ) : 'restrict_admin',
            'notes'             => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
        );

        $result = $wpdb->update(
            $table,
            $data,
            array( 'license_key' => $license_key ),
            array( '%s', '%f', '%s', '%d', '%s', '%s' ),
            array( '%s' )
        );

        if ( false === $result ) {
            wp_send_json_error( array( 'message' => __( 'Failed to update license.', 'dlmues-server' ) ) );
        }

        wp_send_json_success( array( 'message' => __( 'License updated successfully.', 'dlmues-server' ) ) );
    }

    /**
     * AJAX: Quick suspend a license.
     */
    public function ajax_quick_suspend() {
        check_ajax_referer( 'dlmues_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dlmues-server' ) ) );
        }

        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
        $engine      = new DLMUES_License_Engine();
        $result      = $engine->suspend_license( $license_key );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'License suspended.', 'dlmues-server' ) ) );
    }

    /**
     * AJAX: Quick reactivate a license.
     */
    public function ajax_quick_reactivate() {
        check_ajax_referer( 'dlmues_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dlmues-server' ) ) );
        }

        global $wpdb;
        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

        $wpdb->update(
            $wpdb->prefix . 'dlmues_licenses',
            array( 'status' => 'active' ),
            array( 'license_key' => $license_key ),
            array( '%s' ),
            array( '%s' )
        );

        wp_send_json_success( array( 'message' => __( 'License reactivated.', 'dlmues-server' ) ) );
    }

    /**
     * AJAX: Inject visitor count for a license.
     */
    public function ajax_inject_visitor_count() {
        check_ajax_referer( 'dlmues_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dlmues-server' ) ) );
        }

        global $wpdb;
        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
        $count       = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 0;

        $license = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dlmues_licenses WHERE license_key = %s", $license_key ),
            ARRAY_A
        );

        if ( ! $license ) {
            wp_send_json_error( array( 'message' => __( 'License not found.', 'dlmues-server' ) ) );
        }

        $wpdb->update(
            $wpdb->prefix . 'dlmues_licenses',
            array( 'injected_visitor_count' => $count ),
            array( 'license_key' => $license_key ),
            array( '%d' ),
            array( '%s' )
        );

        $display = absint( $license['visitor_count'] ) + $count;

        wp_send_json_success( array(
            'message'       => sprintf( __( 'Visitor count set to %d.', 'dlmues-server' ), $display ),
            'display_count' => $display,
        ) );
    }

    /**
     * AJAX: Get site health data for a license.
     */
    public function ajax_get_site_health() {
        check_ajax_referer( 'dlmues_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dlmues-server' ) ) );
        }

        global $wpdb;
        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

        $license = $wpdb->get_row(
            $wpdb->prepare( "SELECT id, client_domain FROM {$wpdb->prefix}dlmues_licenses WHERE license_key = %s", $license_key ),
            ARRAY_A
        );

        if ( ! $license ) {
            wp_send_json_error( array( 'message' => __( 'License not found.', 'dlmues-server' ) ) );
        }

        $health = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dlmues_site_health WHERE license_id = %d ORDER BY last_reported DESC LIMIT 1", $license['id'] ),
            ARRAY_A
        );

        if ( ! $health ) {
            wp_send_json_error( array( 'message' => __( 'No health data available.', 'dlmues-server' ) ) );
        }

        $plugins = '';
        if ( ! empty( $health['plugin_list'] ) ) {
            $plugin_arr = json_decode( $health['plugin_list'], true );
            if ( is_array( $plugin_arr ) ) {
                $plugins = implode( ', ', array_map( 'esc_html', $plugin_arr ) );
            } else {
                $plugins = esc_html( $health['plugin_list'] );
            }
        }

        $html  = '<table>';
        $html .= '<tr><th>' . esc_html__( 'Domain', 'dlmues-server' ) . '</th><td>' . esc_html( $license['client_domain'] ) . '</td></tr>';
        $html .= '<tr><th>' . esc_html__( 'WordPress', 'dlmues-server' ) . '</th><td>' . esc_html( $health['wp_version'] ) . '</td></tr>';
        $html .= '<tr><th>' . esc_html__( 'PHP', 'dlmues-server' ) . '</th><td>' . esc_html( $health['php_version'] ) . '</td></tr>';
        $html .= '<tr><th>' . esc_html__( 'Server', 'dlmues-server' ) . '</th><td>' . esc_html( $health['server_software'] ) . '</td></tr>';
        $html .= '<tr><th>' . esc_html__( 'Active Theme', 'dlmues-server' ) . '</th><td>' . esc_html( $health['active_theme'] ) . '</td></tr>';
        $html .= '<tr><th>' . esc_html__( 'Plugins', 'dlmues-server' ) . '</th><td>' . $plugins . '</td></tr>';
        $html .= '<tr><th>' . esc_html__( 'Last Reported', 'dlmues-server' ) . '</th><td>' . esc_html( $health['last_reported'] ) . '</td></tr>';
        $html .= '</table>';

        wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * AJAX: Bulk import clients from CSV.
     */
    public function ajax_bulk_import() {
        check_ajax_referer( 'dlmues_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dlmues-server' ) ) );
        }

        if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'dlmues-server' ) ) );
        }

        $csv_data = file_get_contents( $_FILES['csv_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        if ( empty( $csv_data ) ) {
            wp_send_json_error( array( 'message' => __( 'Empty CSV file.', 'dlmues-server' ) ) );
        }

        $engine  = new DLMUES_License_Engine();
        $result  = $engine->bulk_import( $csv_data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Build per-row results for the UI.
        $lines   = explode( "\n", trim( $csv_data ) );
        $header  = str_getcsv( array_shift( $lines ) );
        $header  = array_map( 'trim', array_map( 'strtolower', $header ) );
        $rows    = array();
        $err_idx = 0;

        foreach ( $lines as $i => $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }
            $fields   = str_getcsv( $line );
            $row_data = count( $fields ) >= count( $header ) ? array_combine( $header, array_slice( $fields, 0, count( $header ) ) ) : array();
            $email    = isset( $row_data['client_email'] ) ? $row_data['client_email'] : 'Row ' . ( $i + 2 );

            if ( isset( $result['errors'][ $err_idx ] ) && strpos( $result['errors'][ $err_idx ], 'Row ' . ( $i + 2 ) ) !== false ) {
                $rows[] = array( 'email' => $email, 'success' => false, 'message' => $result['errors'][ $err_idx ] );
                $err_idx++;
            } else {
                $rows[] = array( 'email' => $email, 'success' => true, 'message' => __( 'License created.', 'dlmues-server' ) );
            }
        }

        $summary = sprintf(
            __( 'Import complete: %d total, %d imported, %d failed.', 'dlmues-server' ),
            $result['total'],
            $result['imported'],
            $result['failed']
        );

        // Optionally send welcome emails.
        $send_email = isset( $_POST['send_welcome_email'] ) && '1' === $_POST['send_welcome_email'];
        if ( $send_email && ! empty( $result['licenses'] ) ) {
            $notification = new DLMUES_Notification_Manager();
            foreach ( $result['licenses'] as $key ) {
                $license = $engine->get_license( $key );
                if ( $license && ! empty( $license['client_email'] ) ) {
                    $notification->send_welcome_email( $license );
                }
            }
        }

        wp_send_json_success( array(
            'summary' => $summary,
            'results' => $rows,
        ) );
    }

    /**
     * AJAX: Create a coupon.
     */
    public function ajax_create_coupon() {
        check_ajax_referer( 'dlmues_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dlmues-server' ) ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dlmues_coupons';

        $code = isset( $_POST['code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';

        if ( empty( $code ) ) {
            wp_send_json_error( array( 'message' => __( 'Coupon code is required.', 'dlmues-server' ) ) );
        }

        // Check uniqueness.
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE code = %s", $code ) );
        if ( $exists > 0 ) {
            wp_send_json_error( array( 'message' => __( 'Coupon code already exists.', 'dlmues-server' ) ) );
        }

        $valid_from  = isset( $_POST['valid_from'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_from'] ) ) : '';
        $valid_until = isset( $_POST['valid_until'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_until'] ) ) : '';

        $wpdb->insert(
            $table,
            array(
                'code'           => $code,
                'discount_type'  => isset( $_POST['discount_type'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_type'] ) ) : 'percentage',
                'discount_value' => isset( $_POST['discount_value'] ) ? floatval( $_POST['discount_value'] ) : 0,
                'max_uses'       => isset( $_POST['max_uses'] ) ? absint( $_POST['max_uses'] ) : 0,
                'used_count'     => 0,
                'valid_from'     => ! empty( $valid_from ) ? gmdate( 'Y-m-d H:i:s', strtotime( $valid_from ) ) : '0000-00-00 00:00:00',
                'valid_until'    => ! empty( $valid_until ) ? gmdate( 'Y-m-d H:i:s', strtotime( $valid_until ) ) : '0000-00-00 00:00:00',
                'created_at'     => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%f', '%d', '%d', '%s', '%s', '%s' )
        );

        wp_send_json_success( array( 'message' => sprintf( __( 'Coupon "%s" created.', 'dlmues-server' ), $code ) ) );
    }

    /**
     * AJAX: Delete a coupon.
     */
    public function ajax_delete_coupon() {
        check_ajax_referer( 'dlmues_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dlmues-server' ) ) );
        }

        global $wpdb;
        $id = isset( $_POST['coupon_id'] ) ? absint( $_POST['coupon_id'] ) : 0;

        $wpdb->delete( $wpdb->prefix . 'dlmues_coupons', array( 'id' => $id ), array( '%d' ) );

        wp_send_json_success( array( 'message' => __( 'Coupon deleted.', 'dlmues-server' ) ) );
    }

    /**
     * AJAX: Get invoice HTML for popup viewer.
     */
    public function ajax_get_invoice() {
        check_ajax_referer( 'dlmues_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dlmues-server' ) ) );
        }

        $invoice_id = isset( $_POST['invoice_id'] ) ? absint( $_POST['invoice_id'] ) : 0;

        $invoice_manager = new DLMUES_Invoice_Manager();
        $html            = $invoice_manager->render_invoice_html( $invoice_id );

        if ( is_wp_error( $html ) ) {
            wp_send_json_error( array( 'message' => $html->get_error_message() ) );
        }

        wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * AJAX: Send renewal reminder email.
     */
    public function ajax_send_reminder() {
        check_ajax_referer( 'dlmues_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dlmues-server' ) ) );
        }

        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
        $engine      = new DLMUES_License_Engine();
        $license     = $engine->get_license( $license_key );

        if ( ! $license ) {
            wp_send_json_error( array( 'message' => __( 'License not found.', 'dlmues-server' ) ) );
        }

        $notification = new DLMUES_Notification_Manager();
        $sent         = $notification->send_renewal_reminder( $license );

        if ( $sent ) {
            wp_send_json_success( array( 'message' => sprintf( __( 'Reminder sent to %s.', 'dlmues-server' ), $license['client_email'] ) ) );
        }

        wp_send_json_error( array( 'message' => __( 'Failed to send reminder.', 'dlmues-server' ) ) );
    }

    /**
     * AJAX: Create a trial license.
     */
    public function ajax_create_trial() {
        check_ajax_referer( 'dlmues_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dlmues-server' ) ) );
        }

        $engine = new DLMUES_License_Engine();
        $result = $engine->create_trial( array(
            'client_email' => isset( $_POST['client_email'] ) ? sanitize_email( wp_unslash( $_POST['client_email'] ) ) : '',
            'product_slug' => isset( $_POST['product_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['product_slug'] ) ) : '',
            'trial_days'   => isset( $_POST['trial_days'] ) ? absint( $_POST['trial_days'] ) : 14,
        ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => sprintf( __( 'Trial license created: %s', 'dlmues-server' ), $result['license_key'] ),
        ) );
    }

    /**
     * AJAX: Test Paystack connection.
     */
    public function ajax_test_paystack() {
        check_ajax_referer( 'dlmues_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dlmues-server' ) ) );
        }

        $paystack = new DLMUES_Paystack();
        $result   = $paystack->test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Paystack connection successful.', 'dlmues-server' ) ) );
    }
}
