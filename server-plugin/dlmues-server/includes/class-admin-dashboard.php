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
}
