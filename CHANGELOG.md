# Changelog

All notable changes to the Paymos Magento 2 payment module are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/), and the
project adheres to [Semantic Versioning](https://semver.org/).

## [1.2.7] - 2026-08-08

- fix(magento): record the payment instead of silently skipping the invoice
- chore: bundle Paymos PHP SDK v1.3.2

## [1.2.6] - 2026-08-08

- fix(magento): drop final so Magento can actually route to the module

## [1.2.5] - 2026-08-07

- fix(magento): stop the module from breaking every payment method

## [1.2.4] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.2.3] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.2.2] - 2026-08-07

- fix(plugins): open the approval tab in the six remaining CMS plugins

## [1.2.1] - 2026-08-07

- chore: bundle Paymos PHP SDK v1.3.1

## [1.2.0] - 2026-08-06

- feat(locales): Spanish blog and plugin catalogs
- feat(locales): German blog corpus, plugin catalogs and bot text
- feat(locales): tr + zh-Hans platform rollout — resx, bots, plugins
- fix(ecosystem): recover SDK releases
- chore: bundle Paymos PHP SDK v1.3.0

## [1.1.3] - 2026-08-03

- chore: bundle Paymos PHP SDK v1.3.0
- chore: rebuild canonical CMS package

## [1.1.2] - 2026-08-02

- chore: rebuild canonical CMS package

## [1.1.1] - 2026-08-02

- fix(ecosystem): recover SDK releases
- chore: bundle Paymos PHP SDK v1.2.1
- chore: rebuild canonical CMS package

## [1.1.0] - 2026-07-21

- feat(docs): make the developer surface consumable by LLM agents
- chore: bundle Paymos PHP SDK v1.2.0
- chore: rebuild canonical CMS package

## [1.0.6] - 2026-07-19

- chore: bundle Paymos PHP SDK v1.1.1

## [1.0.5] - 2026-07-13

- chore: rebuild canonical CMS package

## [1.0.4] - 2026-07-12

- fix(plugins): align CMS guidance with secure Connect

## [1.0.3] - 2026-07-12

- chore: rebuild canonical CMS package

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
