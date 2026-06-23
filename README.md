# Paymos for Magento 2

Accept USDT, USDC and other crypto in your Magento 2 / Adobe Commerce store with
on-chain settlement. Customers pay on the Paymos hosted checkout; your order is
invoiced automatically once the payment is confirmed.

- **Module:** `Paymos_Payment` (`app/code/Paymos/Payment`)
- **Magento:** 2.4.x (Open Source & Adobe Commerce)
- **PHP:** per your Magento version (Magento 2.4.6–2.4.7 run on PHP 8.1 / 8.2 / 8.3; the module's `composer.json` requires 8.1+). The bundled SDK and tests stay PHP 7.4-compatible.
- **Payment flow:** hosted redirect (the buyer chooses token + network on Paymos)

## How it works

1. The customer selects **Pay with crypto (Paymos)** at checkout and places the
   order.
2. The module creates a Paymos invoice for the order total and redirects the
   customer to the Paymos hosted checkout.
3. The customer pays; Paymos sends a signed webhook to
   `https://your-store/paymos/payment/callback`.
4. The module verifies the signature, re-checks the invoice against the Paymos
   API, invoices the Magento order and moves it to your configured paid status.

All crypto-critical logic (request signing, webhook verification, dedup, status
mapping, amount guarding, reverse verification) is handled by the bundled
[Paymos PHP SDK](https://github.com/paymos-labs/php-sdk) — the module never
reimplements it.

## Installation

### Option A — manual ZIP (Paymos dashboard)

1. In the Paymos dashboard, open **Dashboard → CMS** and select **Magento 2** to
   generate the configuration package. The downloaded ZIP already contains your
   read-only sandbox and live credentials in
   `app/code/Paymos/Payment/paymos-config.php`.
2. Extract the ZIP at your Magento root so the files land under
   `app/code/Paymos/Payment`.
3. From the Magento root, run:

   ```bash
   bin/magento setup:upgrade
   bin/magento setup:di:compile      # production mode only
   bin/magento cache:flush
   ```

The dashboard ZIP bundles the SDK under `app/code/Paymos/Payment/vendor/`, so no
separate Composer step is required.

### Option B — Composer (generic package)

```bash
composer require paymos/magento2-payment
bin/magento module:enable Paymos_Payment
bin/magento setup:upgrade
bin/magento cache:flush
```

The Composer package resolves the `paymos/php-sdk` dependency automatically and
does **not** include any credentials. After installing, place your
dashboard-generated `paymos-config.php` at the module root
(`app/code/Paymos/Payment/paymos-config.php`).

## Configuration

**Stores → Configuration → Sales → Payment Methods → Paymos (Pay with crypto)**

| Setting | Description |
|---|---|
| Enabled | Turn the payment method on. |
| Title | Name shown to customers at checkout. |
| Mode | `Sandbox` (test) or `Live`. The credential package must contain keys for the selected mode. |
| Connection status | Read-only diagnostics: whether credentials are present, the masked API key, the project id and the webhook URL. |
| New order status | Status while the customer is paying (before confirmation). |
| Paid order status | Processing-state status applied once payment is confirmed. |
| Debug logging | Write diagnostics to `var/log/paymos.log` (leave off in production). |

API keys are **never** typed here — they are read-only and arrive in the
generated `paymos-config.php`. The webhook destination is configured per project
in the Paymos dashboard; point it at
`https://your-store/paymos/payment/callback`.

## What this module does not do

- **No invoice "lifetime" setting** — invoice expiry is controlled server-side by
  Paymos, so the module does not pretend to.
- **No secret entry in admin** — credentials are delivered by the dashboard.
- **No partial/manual capture** — Paymos settles on-chain; the Magento invoice is
  created offline when the payment is confirmed.

## Uninstall

```bash
bin/magento module:disable Paymos_Payment
bin/magento setup:upgrade
```

To drop the module tables, remove the declarative schema entries and run
`bin/magento setup:upgrade` (or drop `paymos_payment_event` and
`paymos_payment_invoice` manually).

## Development

Run the bundled test suite (no Magento install required — Magento classes are
stubbed, and the Paymos PHP SDK is resolved from the sibling `php-sdk/` checkout).
From the monorepo's `plugins/` directory:

```bash
docker run --rm -v "$PWD":/plugins php:7.4-cli php /plugins/magento2-paymos/tests/run.php
```

The publishable module lives in `app/code/Paymos/Payment`. The repository root
holds build tooling and tests only.
