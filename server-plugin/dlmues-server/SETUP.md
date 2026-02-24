# DLMUES Server Plugin — Setup Guide

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- MySQL 5.7 or higher
- A Paystack account (for payment processing)
- SSL certificate recommended for production

## Installation

1. Download or clone the `dlmues-server` folder.
2. Upload it to `/wp-content/plugins/` on your WordPress server.
3. Navigate to **Plugins > Installed Plugins** and activate **DLMUES License Server**.
4. The plugin creates its database tables automatically on activation.

## Initial Configuration

### 1. General Settings

Navigate to **DLMUES > Settings** in the WordPress admin sidebar.

| Setting | Description |
|---------|-------------|
| **Company Name** | Displayed on invoices and emails sent to clients. |
| **Default Currency** | USD, NGN, GBP, EUR, GHS, ZAR, or KES. |
| **Default Grace Period** | Days after expiry before enforcement kicks in (0–90). |
| **Default Enforcement Mode** | `Restrict Admin`, `Lock Frontend`, or `Maintenance Mode`. |
| **Default Trial Period** | Days for new trial licenses (0 to disable). |

### 2. Pricing

Set subscription prices for each plan tier:

- **Monthly** — billed every month
- **Bi-Monthly** — billed every 2 months
- **Quarterly** — billed every 3 months
- **Yearly** — billed once per year

### 3. Paystack Settings

| Setting | Description |
|---------|-------------|
| **Test Mode** | Enable to use Paystack test keys during development. |
| **Test Public Key** | `pk_test_...` from your Paystack dashboard. |
| **Test Secret Key** | `sk_test_...` (encrypted at rest). |
| **Live Public Key** | `pk_live_...` for production payments. |
| **Live Secret Key** | `sk_live_...` (encrypted at rest). |

Click **Test Connection** on the settings page to verify your Paystack keys.

### 4. Paystack Webhook

Set up a webhook in your Paystack dashboard pointing to:

```
https://your-server.com/wp-json/dlmues/v1/payment/webhook
```

This ensures automatic payment verification even if the client's browser does not return.

## Creating Products

Navigate to **DLMUES > Products** and click **Add New**.

| Field | Description |
|-------|-------------|
| **Product Slug** | Unique identifier matching the plugin/theme folder name (e.g., `my-premium-theme`). |
| **Product Name** | Human-readable name. |
| **Product Type** | `plugin` or `theme`. |
| **Current Version** | The latest version you want to distribute (e.g., `2.1.0`). |
| **Update Package URL** | Direct URL to the `.zip` file for the latest version. |

> **Tip:** Protect your update packages by placing them in a directory with an `.htaccess` rule that only allows requests with a valid token.

## Creating Licenses

### Manual Creation

Go to **DLMUES > Licenses > Add New** and fill in:

- Client email address
- Product slug (must match a registered product)
- Subscription plan and price
- Expiry date

### Bulk Import

Go to **DLMUES > Bulk Import** to:

1. Download the CSV template.
2. Fill in: `client_email, client_domain, product_slug, product_type, subscription_type, price, currency`.
3. Upload the CSV and choose default plan/currency.
4. Optionally check **Send welcome email with license key**.

### Trial Licenses

On the same Bulk Import page, use the **Create Trial License** form to issue time-limited trial keys.

## Managing Clients

Navigate to **DLMUES > Clients** to see all connected sites.

### Client Table Columns

| Column | Description |
|--------|-------------|
| **Domain** | The client site's domain. |
| **Email** | Client's registered email. |
| **Product** | Product slug and type. |
| **Status** | Active, Expired, Suspended, Trial, or Grace. |
| **Plan** | Subscription type and price. |
| **Expires** | License expiry date. |
| **Last Payment** | Date of last successful payment. |
| **Visitors** | Total visitor count (reported + injected). |
| **Health** | Click **View** to see WordPress version, PHP version, active theme, plugin list, and server software. |
| **Actions** | Edit inline, Suspend/Reactivate, Send Reminder. |

### Visitor Count Injection

You can manually set a visitor count for any client using the input in the Visitors column. This is useful for demo/marketing purposes.

### Site Health

When the client plugin is activated on a live site, it immediately sends a health report containing:

- WordPress version, PHP version, server software
- Active theme (name + version)
- Full plugin list (name + version for each)
- Site URL and visitor count

Health data updates automatically once daily via cron.

## Invoices

Navigate to **DLMUES > Invoices** to view payment invoices. Click **View** on any invoice to see the full invoice, or **Print** to open in a new window for printing.

## Coupons

Navigate to **DLMUES > Coupons** to create discount codes.

| Field | Description |
|-------|-------------|
| **Code** | The coupon code clients enter (e.g., `SAVE20`). |
| **Discount Type** | `Percentage` or `Fixed` amount. |
| **Discount Value** | Percentage (e.g., 20) or fixed amount (e.g., 5.00). |
| **Max Uses** | Maximum redemptions (0 = unlimited). |
| **Valid From / Until** | Optional date range for coupon validity. |

## REST API Endpoints

All endpoints are available at `https://your-server.com/wp-json/dlmues/v1/`:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/license/activate` | POST | Activate a license for a domain. |
| `/license/validate` | POST | Validate current license status. |
| `/license/deactivate` | POST | Deactivate a license. |
| `/update/check` | POST | Check for product updates. |
| `/payment/initialize` | POST | Initialize a Paystack payment. |
| `/payment/verify` | POST | Verify a payment reference. |
| `/payment/plans` | POST | Get available subscription plans. |
| `/payment/webhook` | POST | Paystack webhook callback. |
| `/coupon/apply` | POST | Apply a coupon code. |
| `/health/report` | POST | Receive site health report. |

## Email Notifications

The server sends automated emails for:

- **Welcome** — license key and plan details on new activation
- **Expiry Warning** — sent before license expires
- **Renewal Reminder** — manual reminder from the Clients page
- **Payment Confirmation** — after successful payment

Configure your WordPress mail settings (SMTP recommended) to ensure delivery.
