# Paymos for Magento 2

Official Paymos payment module for Magento Open Source and Adobe Commerce. It adds one method to
checkout: the shopper places the order, lands on the Paymos hosted checkout, picks a token and a
network, and sends the transfer from their own wallet. Magento invoices the order when a signed
webhook reports the payment confirmed on-chain — not when the shopper finds their way back to the
store.

## Requirements

- Magento Open Source or Adobe Commerce 2.4 — the module requires `magento/framework` 103.0 or newer
- PHP 8.3, 8.4 or 8.5 (Adobe Commerce 2.4.9 dropped 8.1/8.2)
- Shell access for `bin/magento`
- A storefront served over HTTPS; the connect flow refuses a plain-HTTP base URL
- A Paymos account, with the project you want to bind open in the dashboard

Those versions are declared in `app/code/Paymos/Payment/composer.json`. The release archive vendors
the Paymos PHP SDK under the module's own `vendor/`, so a manual install needs no Composer step.

## Install

Take `paymos-magento2-<version>.zip` from
[Releases](https://github.com/Paymos-labs/magento2/releases/latest), or from the **CMS integration**
panel in the Paymos dashboard, and extract it at the Magento root. Entries are laid out as
`app/code/Paymos/Payment/…`, so nothing has to be moved afterwards.

```bash
bin/magento module:enable Paymos_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode only
bin/magento cache:flush
```

`setup:upgrade` creates two tables: `paymos_payment_invoice`, one snapshot per order, and
`paymos_payment_event`, the webhook de-duplication ledger.

One archive is published per release and every merchant downloads that same file. It carries no API
key, API secret, project id, webhook secret, OAuth token or device code, and installing it connects
nothing.

## Connect

1. In the Paymos dashboard, open the project this store should bill through. That project is the
   one the store gets — the module has no project selector.
2. In Magento open **Stores → Configuration → Sales → Payment Methods → Paymos** and press
   **Connect Paymos**.
3. Approve the store URL and project shown in the Paymos tab that opens. If the browser blocked the
   tab, the row prints the approval link and the user code instead.
4. Wait for **Connection status** to read connected, then set **Enabled** to Yes.

One approval provisions Sandbox and Live together. Your single active Payment key is reused, or one
is created when none exists, and an Invoice webhook is registered at

```text
https://your-store.example/paymos/payment/callback
```

An existing webhook is reused only when its callback URL, category and project all match; a
conflicting webhook at the same address is never overwritten. The device authorization is a one-time
delivery channel — the short-lived token is discarded and every later Merchant API call is
HMAC-signed.

Credentials go into `core_config_data` encrypted through Magento's `EncryptorInterface`, and are
never rendered back into the admin page. There is no field to paste a secret into; reconnecting is
the only way to replace one.

## Configuration scope

The module's fields are exposed at **Default Config** and **website** scope. Store views inherit
through Magento's normal fallback, and the value a store view resolves is what its checkout and its
orders use.

| Setting | Default | Purpose |
|---|---|---|
| Enabled | No | Whether the method is offered at all |
| Title | `Pay with crypto (Paymos)` | The label the shopper sees |
| Mode | `sandbox` | Which credential set the website uses |
| New order status | `pending_payment` | Applied while the shopper is paying |
| Paid order status | `processing` | Applied once the payment confirms |
| Debug logging | No | Diagnostics to `var/log/paymos.log` |
| Sort order | `1` | Position among the other methods |

The connected credentials are one set per installation rather than per website, so a second website
does not need its own approval. Move a website between environments with **Mode** alone.
Reconnecting is only needed after the base URL changes, a Payment key is revoked, or a webhook
secret is rotated.

## What happens to the order

| Paymos event | Result in Magento |
|---|---|
| Invoice created, shopper redirected | The configured **New order status**, plus a history comment naming the invoice |
| `invoice.confirming` | Status unchanged; a comment records that the payment is confirming |
| `invoice.underpaid_waiting` | Status unchanged; the order waits for the rest of the amount |
| `invoice.awaiting_payment` | Status unchanged; a payment that had been counted vanished in a chain reorg |
| `invoice.paid`, `invoice.paid_over` | An offline invoice document is created, the order moves to state Processing and the configured **Paid order status**, and the invoice email goes out |
| `invoice.underpaid` | Order cancelled |
| `invoice.expired`, `invoice.cancelled` | Order cancelled |

The invoice document records the on-chain transaction hash of the last confirmed transfer as its
transaction id, and falls back to the Paymos invoice id when the payload carries none.

Two guards sit in front of that table. An order whose grand total or currency changed after the
invoice was created is never completed automatically — it gets a comment asking for manual review,
and the delivery is still acknowledged so the retry ladder stops repeating it. And a late
`confirming`, `underpaid`, `expired` or `cancelled` that arrives after the order is already paid is
logged and dropped, never applied.

## Test before going live

1. Set **Mode** to Sandbox and place an order in the storefront.
2. Open that invoice in the Paymos dashboard while the dashboard is in Sandbox. **Pay Full**,
   **Pay 50%**, **Pay 150%** and **Cancel** drive the invoice through the same lifecycle events a
   real payment emits. No crypto moves.
3. Watch the Magento order — status, history comments, and whether the invoice document appears.
4. Set **Mode** to Live. The Live credentials arrived with the same approval, so there is nothing to
   reconnect and nothing to configure a second time.

Sandbox payloads carry no transfers, so a sandbox order records the invoice id where a live one
records a transaction hash.

## Webhooks

Deliveries arrive as `POST` at `/paymos/payment/callback`. The route is exempt from Magento's form
key because the `X-Webhook-Signature` header is the trust boundary; an unsigned or mis-signed body
gets `401` and never reaches an order. `X-Webhook-Id` is stable across retries and is the
de-duplication key stored in `paymos_payment_event`, so a repeat delivery answers `200` and changes
nothing.

Terminal events are reverse-verified. Before an order is completed or cancelled the invoice is read
back from the Merchant API and checked against the stored snapshot, and a payload that disagrees is
refused.

One delivery cycle is 11 attempts spread over roughly 16 hours, so a store that was briefly
unreachable still receives the event. Behind that, the `paymos_reconcile_invoices` cron job runs
every 15 minutes, pulls recent non-terminal invoices from the API and re-applies their current
status through the same mapper and the same guards. It is a safety net, and it needs Magento's cron
to be running.

## Troubleshooting

**Paymos is not offered at checkout.** The method ships disabled. Set **Enabled** to Yes on the
website the storefront belongs to, confirm **Connection status** reads connected, and flush the
configuration cache — Magento caches `payment/paymos/*` like any other config path.

**Orders sit in the awaiting-payment status.** The webhook is not landing. The callback has to be
reachable from the public internet at the exact base URL that was approved: loopback, private and
link-local destinations are blocked, and redirects are not followed, so a store that moved host or
picked up a redirect needs reconnecting. Running `bin/magento cron:run` shows whether the
reconciliation job can close the gap meanwhile.

**Connect fails with "Magento base URL must use HTTPS".** The device flow reads the default store
view's base URL, and that URL is what the approval screen shows and what the webhook is registered
against. Serve it over HTTPS and start the connection again.

## Links

- Documentation: [paymos.io/docs/cms-magento2](https://paymos.io/docs/cms-magento2)
- Source and releases: [Paymos-labs/magento2](https://github.com/Paymos-labs/magento2)
- Support: [support@paymos.io](mailto:support@paymos.io)
