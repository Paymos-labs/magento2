# Changelog

All notable changes to the Paymos Magento 2 payment module are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/), and the
project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.2] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.1] - 2026-07-12

- fix(release): align package stamping and webhook fixtures
- chore: rebuild canonical CMS package

## [1.0.0] - 2026-06-18

### Added

- Initial release of the Paymos hosted-checkout payment module for
  Magento 2.4.x / Adobe Commerce.
- Payment method built on the modern payment provider gateway (a
  `Magento\Payment\Model\Method\Adapter` virtual type with value-handler,
  validator and command pools) — no deprecated `AbstractMethod`.
- Storefront checkout renderer that redirects the customer to the Paymos hosted
  checkout after order placement.
- Signed webhook callback controller (`HttpPostActionInterface` +
  `CsrfAwareActionInterface`) with HMAC verification, dedup, terminal-event
  reverse verification and amount guarding via the Paymos PHP SDK.
- Magento invoice creation on confirmed payment through `InvoiceService` +
  `DB\Transaction` (order moved to the Processing state); roll-back guard against
  out-of-order webhooks downgrading a paid order.
- Declarative schema tables `paymos_payment_event` (dedup) and
  `paymos_payment_invoice` (snapshot).
- Read-only admin connection-status panel; API credentials are delivered via the
  dashboard-generated `paymos-config.php` and never typed in admin.
- Cron reconciliation of recent non-terminal invoices as a missed-webhook
  safety net.
