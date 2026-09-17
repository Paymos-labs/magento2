# Changelog

All notable changes to the Paymos Magento 2 payment module are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/), and the
project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.2.12] - 2026-09-17

- changelog(magento2): v1.2.12 — resubmission of 1.2.11 после инфраструктурного сбоя автотеста Adobe
- chore: bundle Paymos PHP SDK v1.4.1

## [1.2.11] - 2026-08-30

- fix(plugins): гейт di:compile теперь запускается, а CS-Cart больше не конвертирует таблицу до её создания
- fix(plugins): CMS marketplace readiness spec, phases 1-3
- chore: rebuild canonical CMS package

- fix(admin): the payment configuration page no longer fatals with an illegal offset type

### Fixed
- The `Stores → Configuration → Sales → Payment Methods` page returned a 500 in
  every released version since June: the diagnostics row used `__()` Phrase
  objects as array keys, and an object cannot be an array key. Keys are now
  cast to string — the Connect button is finally reachable for merchants.
- The PHP constraint narrows to `~8.3.0||~8.4.0||~8.5.0`: Adobe Commerce 2.4.9
  dropped PHP 8.1/8.2, and every claimed version is a test Adobe will run.
- A failed webhook or reconcile left no trace in production logs (the entry
  went through the diagnostics-gated `log()`); failures now log unconditionally
  at warning level via `logFailure()`.
- In multi-store installs the paid order status came from the default scope;
  it is now read per the order's own `store_id`.
- The reconcile snapshot guard treated an empty actual value as a match; a
  changed API response shape now fails closed instead of rubber-stamping.

### Changed
- Test harness compiles every module source file with deprecations escalated
  to errors — the gate that would have caught both the 1.2.9 nullable fatal
  and this release's admin-page fatal before Adobe did.

- fix(php85): DI compilation no longer dies on implicitly nullable parameters

### Fixed
- Adobe's automated Installation & Varnish and MFTF tests (PHP 8.5, Adobe
  Commerce 2.4.9) failed during `setup:di:compile`: Magento escalates the
  PHP 8.4 deprecation for implicitly nullable parameters to a fatal error.
  `callable $clientFactory = null` became `?callable $clientFactory = null`
  in `CheckoutProcessor`, `Reconciler` and `WebhookProcessor`. The same latent
  pattern was fixed in the CS-Cart, OpenCart, PrestaShop, Shopware and WHMCS
  plugins, where it only polluted PHP 8.4+ logs.

- fix(marketplace): ship a Marketplace component that passes Adobe's technical review

### Fixed
- The Marketplace component failed Adobe's automated Code Sniffer with
  `Magento2.Security.IncludeFile`: packaging used to strip the vendored SDK but
  keep the dead autoloader and its `require` statements. Packaging now removes
  `Autoloader.php` and the marked bundled-SDK fallback block from
  `registration.php`, and the fallback's explanation lives inside the stripped
  block, so the Marketplace file no longer documents code it does not carry.
  Manual dashboard installs are unchanged: the ZIP still vendors the SDK and
  registers it locally.

## [1.2.10] - 2026-08-30

- fix(plugins): implicitly nullable factory params break Magento DI compile on PHP 8.5
- fix(magento): маркетплейс-файл самодостаточен, и сниффер Adobe гоняется в CI до подачи
- fix(magento2): маркетплейс-пакет больше не везёт мёртвый автозагрузчик
- chore: rebuild canonical CMS package

- fix(php85): DI compilation no longer dies on implicitly nullable parameters

### Fixed
- Adobe's automated Installation & Varnish and MFTF tests (PHP 8.5, Adobe
  Commerce 2.4.9) failed during `setup:di:compile`: Magento escalates the
  PHP 8.4 deprecation for implicitly nullable parameters to a fatal error.
  `callable $clientFactory = null` became `?callable $clientFactory = null`
  in `CheckoutProcessor`, `Reconciler` and `WebhookProcessor`. The same latent
  pattern was fixed in the CS-Cart, OpenCart, PrestaShop, Shopware and WHMCS
  plugins, where it only polluted PHP 8.4+ logs.

- fix(marketplace): ship a Marketplace component that passes Adobe's technical review

### Fixed
- The Marketplace component failed Adobe's automated Code Sniffer with
  `Magento2.Security.IncludeFile`: packaging used to strip the vendored SDK but
  keep the dead autoloader and its `require` statements. Packaging now removes
  `Autoloader.php` and the marked bundled-SDK fallback block from
  `registration.php`, and the fallback's explanation lives inside the stripped
  block, so the Marketplace file no longer documents code it does not carry.
  Manual dashboard installs are unchanged: the ZIP still vendors the SDK and
  registers it locally.

## [1.2.8] - 2026-08-28

- fix(magento2): Adobe rejects a module that stops at PHP 8.4
- release: the changelog rot had a cause, and it was not the one I named
- docs(plugins): eight README stubs become the front pages they already were
- docs(plugins): the changelogs stopped in June and the audit never reached them
- chore: bundle Paymos PHP SDK v1.4.0
- chore: rebuild canonical CMS package

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
