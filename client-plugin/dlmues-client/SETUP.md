# DLMUES Client Plugin — Setup Guide

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- A valid DLMUES license key from the server
- The DLMUES Server Plugin must be installed and configured on a separate WordPress site

## Installation

1. Download or clone the `dlmues-client` folder.
2. Upload it to `/wp-content/plugins/` on the client WordPress site.
3. Navigate to **Plugins > Installed Plugins** and activate **DLMUES License Client**.
4. A **Settings** link appears in the plugin action links.

## License Activation

1. Go to **Settings > DLMUES License** in the WordPress admin.
2. Enter your **License Key** (provided by the server admin or via welcome email).
3. Enter the **Server URL** — the full URL of the WordPress site running the DLMUES Server Plugin (e.g., `https://licenses.example.com/`).
4. Click **Activate License**.

On successful activation, the plugin will:

- Store all license data (status, plan, expiry, product info)
- Immediately send a **site health report** to the server (WordPress version, PHP version, active theme, all plugins, server software)
- Register the product slug for managed updates
- Schedule hourly license validation and daily health reporting

## Admin Dashboard

After activation, the license settings page shows:

### License Status Card

| Field | Description |
|-------|-------------|
| **Status** | Active, Expired, Suspended, Trial, or Grace Period. |
| **License Key** | Your unique license key (partially masked). |
| **Domain** | The domain this license is locked to. |
| **Product** | The licensed product name and slug. |
| **Plan** | Subscription type (Monthly, Bi-Monthly, Quarterly, Yearly). |
| **Expires** | License expiration date and time. |
| **Grace Period** | Days of grace after expiry before enforcement. |
| **Enforcement** | Mode that activates when license expires. |

### Refresh Status

Click the **Refresh** button to force an immediate license revalidation with the server.

## Renewal & Payments

### When License Expires

When your license enters the grace period or expires:

- A **grace period bar** appears at the top of every admin page with a live countdown timer.
- A **Renew License** button appears, opening the payment modal.
- The payment modal loads available plans from the server.

### Payment Flow

1. Click **Renew License** (visible on all admin pages when expired).
2. Select a subscription plan (Monthly, Bi-Monthly, Quarterly, Yearly).
3. Optionally enter a **coupon code** and click **Apply** for a discount.
4. Click **Pay Now** to initiate payment via Paystack.
5. Complete payment on the Paystack page.
6. You will be redirected back to WordPress. The license refreshes automatically.

### Paystack Inline vs Redirect

The plugin supports both Paystack modes:

- **Redirect mode**: Opens the Paystack payment page in your browser.
- **Inline popup mode**: Opens a Paystack popup if the Paystack JS library is loaded and an `access_code` is returned.

## Enforcement Modes

When the license expires and the grace period ends, enforcement activates based on the mode set by the server admin:

| Mode | Effect |
|------|--------|
| **Restrict Admin** | Admin dashboard access is restricted. A renewal notice is shown. The frontend remains functional. |
| **Lock Frontend** | The site's frontend displays a maintenance/renewal page to visitors. Admin access is restricted. |
| **Maintenance** | The entire site (frontend + admin) shows a maintenance page until the license is renewed. |

### Grace Period Countdown

During the grace period, a top bar appears on every admin page showing:

- Days, hours, and minutes remaining
- A **Renew Now** button linking to the payment modal

## Update Management

The client plugin replaces WordPress's native update system for managed products:

### How It Works

1. On license activation, the **product slug** from the server is stored as a managed product.
2. The plugin hooks into WordPress's `pre_set_site_transient_update_plugins` and `pre_set_site_transient_update_themes` filters.
3. When WordPress checks for updates, DLMUES queries the license server instead of WordPress.org.
4. If an update is available and the license is valid, the update package URL is injected into WordPress's update transient.
5. If the license is expired, the `package` URL is removed — blocking the download.

### Plugin Details Popup

When you click "View Details" on a managed plugin in the WordPress updates screen, the DLMUES server provides the plugin information (description, changelog, version, compatibility).

### Managed Products

The list of managed products comes from:

1. The **managed_products** array returned by the server during activation.
2. The primary **product_slug** stored during activation.

These are automatically configured — no manual setup is needed.

## Site Health Reporting

The plugin automatically reports site health data to the server:

### What Is Reported

| Data | Description |
|------|-------------|
| **WordPress Version** | e.g., 6.4.2 |
| **PHP Version** | e.g., 8.1.27 |
| **Active Theme** | Theme name and version |
| **Plugin List** | All active plugins with versions |
| **Server Software** | e.g., Apache/2.4.57, nginx/1.24.0 |
| **Site URL** | The full site URL |
| **Visitor Count** | Total unique visitors in the last 30 days |

### Reporting Schedule

- **Immediate**: A health report is sent right after license activation.
- **Daily**: A scheduled cron job sends updated health data every 24 hours.

### Visitor Tracking

The plugin tracks unique front-end visitors (excluding admin pages, AJAX requests, cron jobs, and known bots). Daily counts are stored for 30 days and the total is included in health reports.

## Deactivation

1. Go to **Settings > DLMUES License**.
2. Click **Deactivate License**.
3. This notifies the server and clears all local license data.

> **Note:** Deactivating the license does not cancel your subscription on the server. Contact the server admin to cancel billing.

## Troubleshooting

### License won't activate

- Verify the **Server URL** is correct and accessible (try visiting it in a browser).
- Ensure the server has the DLMUES Server Plugin activated.
- Check that your license key is valid and not already activated on another domain.

### Payment modal not appearing

- Clear your browser cache and reload.
- Check the browser console for JavaScript errors.
- Ensure the server's Paystack keys are configured correctly.

### Updates not showing

- Verify the **product slug** in your license matches the plugin/theme folder name.
- Click **Refresh** on the license settings page to re-validate.
- Go to **Dashboard > Updates** and click **Check Again**.

### Health data not appearing on server

- Wait up to 24 hours for the daily cron to send the report.
- Or deactivate and reactivate the license to trigger an immediate report.
- Ensure WordPress cron is running (check with WP-Cron Status plugins).

## Hooks & Filters

| Hook | Type | Description |
|------|------|-------------|
| `dlmues_checking_plugin_updates` | Action | Fires when plugin updates are being checked. |
| `dlmues_checking_theme_updates` | Action | Fires when theme updates are being checked. |
| `pre_set_site_transient_update_plugins` | Filter | Used to inject/block plugin updates. |
| `pre_set_site_transient_update_themes` | Filter | Used to inject/block theme updates. |
| `plugins_api` | Filter | Used to provide custom plugin details. |
