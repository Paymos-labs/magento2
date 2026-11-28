<?php

declare(strict_types=1);

use Paymos\Payment\Controller\Payment\Redirect;
use Paymos\Payment\Service\Config;
use Paymos\Payment\Tests\FakeClient;
use Paymos\Payment\Tests\FakeInvoices;
use Paymos\Payment\Tests\FakeScopeConfig;
use Paymos\Payment\Tests\InMemorySnapshotRepository;

// What the buyer reads when the checkout Redirect cannot hand them to Paymos.
// BUG-180: when the order's previous invoice may still be paid, "choose another
// method" invites a second payment beside the first.

final class RedirectTestOrder extends \Magento\Sales\Model\Order
{
    /** @var array<int, string> */
    public $comments = array();

    /** @var bool */
    public $cancelled = false;
    public $state = 'new';
    public $totalPaid = '0';
    public $saves = 0;
    public $onReload;
    /** @var array<int, object> */
    public $items = array();
    public function reset() { return $this; }
    public function getResource() { return $this; }
    public function load($order, $id) { if ($this->onReload) { ($this->onReload)($this); } return $this; }
    public function getState() { return $this->state; }
    public function getTotalPaid() { return $this->totalPaid; }
    public function getTotalRefunded() { return '0'; }
    public function getAllVisibleItems() { return $this->items; }

    public function getId() { return 42; }
    public function getEntityId() { return 42; }
    public function getIncrementId() { return '100000042'; }
    public function getGrandTotal() { return '150.00'; }
    public function getOrderCurrencyCode() { return 'USD'; }
    public function getCustomerId() { return 77; }
    public function getStoreId() { return 1; }
    public function getStatus() { return 'pending'; }
    public function setStatus($status) { return $this; }
    public function addCommentToStatusHistory($comment, $status = false) { $this->comments[] = (string) $comment; return $this; }
    public function save() { $this->saves++; return $this; }
    public function canCancel() { return true; }
    public function registerCancellation($comment = '') { $this->cancelled = true; $this->comments[] = (string) $comment; return $this; }
}

final class RedirectTestSession extends \Magento\Checkout\Model\Session
{
    /** @var RedirectTestOrder */
    private $order;

    public function __construct(RedirectTestOrder $order) { $this->order = $order; }
    public function getLastRealOrder() { return $this->order; }
}

final class RedirectTestMessages implements \Magento\Framework\Message\ManagerInterface
{
    /** @var array<int, string> */
    public $errors = array();

    public function addErrorMessage($message, $group = null)
    {
        $this->errors[] = (string) $message;
        return $this;
    }
}

final class RedirectTestConfigProvider extends \Paymos\Payment\Service\GeneratedConfigProvider
{
    public function __construct() {}

    public function get(): Config
    {
        return Config::fromArray(paymos_m2_generated_config());
    }
}

final class RedirectTestClientFactory extends \Paymos\Payment\Service\PaymosClientFactory
{
    /** @var callable */
    private $factory;

    public function __construct(callable $factory) { $this->factory = $factory; }

    public function asCallable(): callable
    {
        return $this->factory;
    }
}

final class RedirectTestSnapshots extends \Paymos\Payment\Service\InvoiceSnapshotRepository
{
    /** @var InMemorySnapshotRepository */
    public $inner;

    public function __construct(InMemorySnapshotRepository $inner) { $this->inner = $inner; }
    public function synchronized(int $orderId, callable $action) { return $this->inner->synchronized($orderId, $action); }
    public function assertLocked(int $orderId): void { $this->inner->assertLocked($orderId); }
    public function save(array $row): void { $this->inner->save($row); }
    public function findByExternalOrderId(string $externalOrderId) { return $this->inner->findByExternalOrderId($externalOrderId); }
    public function findByOrderId(int $orderId) { return $this->inner->findByOrderId($orderId); }
    public function updateStatus(string $paymosInvoiceId, string $status): void { $this->inner->updateStatus($paymosInvoiceId, $status); }
    public function findUnpaidRecent(int $limit, int $createdAfterUnix): array { return $this->inner->findUnpaidRecent($limit, $createdAfterUnix); }
}

/**
 * @param array<int, array<string, mixed>> $snapshots
 * @return array{0: Redirect, 1: RedirectTestOrder, 2: RedirectTestMessages}
 */
function paymos_m2_redirect(array $snapshots, callable $clientFactory)
{
    $order = new RedirectTestOrder();
    $messages = new RedirectTestMessages();
    $controller = new Redirect(
        new \Magento\Framework\Controller\ResultFactory(),
        new RedirectTestSession($order),
        new RedirectTestConfigProvider(),
        new RedirectTestSnapshots(new InMemorySnapshotRepository($snapshots)),
        new RedirectTestClientFactory($clientFactory),
        new \Paymos\Payment\Service\Settings(new FakeScopeConfig()),
        $messages,
        new \Paymos\Payment\Service\Logger()
    );

    return array($controller, $order, $messages);
}

function test_magento_redirect_asks_the_buyer_to_contact_the_store_when_the_old_invoice_may_still_be_paid()
{
    // The order was cut for 100.00 and now totals 150.00; the old invoice has
    // a network picked, so the server refuses to cancel it (BUG-166).
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting', 'paid') as $status) {
        $invoices = new FakeInvoices(array(), array('invoice_id' => 'inv_123', 'status' => $status));
        list($controller, $order, $messages) = paymos_m2_redirect(
            array(paymos_m2_snapshot(array('status' => 'awaiting_client'))),
            static function () use ($invoices) {
                return new FakeClient($invoices);
            }
        );

        $redirect = $controller->execute();

        $sdkMessage = (new \Paymos\Plugin\InvoiceReplacementBlockedException(
            \Paymos\Plugin\InvoiceReplacementResult::blocked('inv_123', $status, \Paymos\Plugin\InvoiceReplacementResult::REASON_OPEN)
        ))->getMessage();
        assertSameValue(array($sdkMessage), $messages->errors, $status . ': the buyer reads the SDK message, not "choose another method".');
        assertFalseValue($order->cancelled, $status . ': the order is not cancelled.');
        assertSameValue(1, count($order->comments), $status . ': one note for the merchant.');
        assertTrueValue(strpos($order->comments[0], 'inv_123') !== false, $status . ': the note names the old invoice.');
        assertSameValue('checkout/cart', $redirect->path, $status . ': the buyer goes back to the cart.');
        assertSameValue(0, count($invoices->createPayloads), $status . ': no second invoice.');
    }
}

function test_magento_redirect_keeps_the_choose_another_method_message_for_a_failed_create()
{
    list($controller, $order, $messages) = paymos_m2_redirect(array(), static function () {
        throw new \RuntimeException('Paymos credentials are missing.');
    });

    $controller->execute();

    assertSameValue(array('We could not start the crypto payment. Please choose another method.'), $messages->errors, 'a create that never happened can still be paid another way.');
    assertTrueValue($order->cancelled, 'the order without an invoice is cancelled.');
}

function test_magento_redirect_preserves_paid_fulfilled_refunded_orders()
{
    foreach (array('processing', 'complete', 'closed', 'total_paid') as $case) {
        $calls = 0;
        list($controller, $order, $messages) = paymos_m2_redirect(
            $case === 'snapshot_paid' ? array(paymos_m2_snapshot(array('status' => 'paid'))) : array(),
            static function () use (&$calls) { $calls++; throw new RuntimeException('No HTTP expected.'); });
        if ($case === 'total_paid') { $order->totalPaid = '150'; }
        elseif ($case !== 'snapshot_paid') { $order->state = $case; }
        $result = $controller->execute();
        assertSameValue('checkout/onepage/success', $result->path, $case . ' goes to the result page.');
        assertSameValue(0, $order->saves, $case . ' is never saved by redirect.');
        assertSameValue(0, $calls, $case . ' makes no API call.');
        assertSameValue(array(), $messages->errors, 'No error on a completed order.');
    }
}

function test_magento_redirect_reloads_paid_state_after_network_before_status_save()
{
    list($controller, $order) = paymos_m2_redirect(array(), static function () { return new FakeClient(); });
    $loads = 0;
    $order->onReload = static function ($fresh) use (&$loads): void {
        if (++$loads === 2) { $fresh->state = 'processing'; $fresh->totalPaid = '150'; }
    };
    assertSameValue('checkout/onepage/success', $controller->execute()->path, 'Payment during HTTP is observed.');
    assertSameValue(2, $loads, 'Native state is read before and after the network call.');
    assertSameValue(0, $order->saves, 'No pending status overwrites paid state.');
}

function test_magento_translations_carry_the_contact_the_store_message()
{
    $key = 'The store needs to review this order before payment can continue. Please contact the store.';
    foreach (array('en_US', 'ru_RU', 'de_DE', 'es_ES', 'tr_TR', 'zh_Hans_CN') as $locale) {
        $rows = array();
        $handle = fopen(PAYMOS_M2_MODULE_DIR . 'i18n/' . $locale . '.csv', 'r');
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (isset($row[0], $row[1])) {
                $rows[$row[0]] = $row[1];
            }
        }
        fclose($handle);

        assertTrueValue(isset($rows[$key]) && $rows[$key] !== '', $locale . ': the message has a translation row.');
    }
}

function test_magento_redirect_does_not_treat_unapplied_snapshot_as_native_payment()
{
    foreach (array('paid', 'paid_over', 'payment_review') as $status) {
        list($controller, $order, $messages) = paymos_m2_redirect(
            array(paymos_m2_snapshot(array('status' => $status))),
            static function () { throw new RuntimeException('No API call expected.'); });
        assertSameValue('checkout/cart', $controller->execute()->path, 'Unapplied payment needs review.');
        assertSameValue(1, count($messages->errors), 'Buyer sees the review instruction.');
        assertFalseValue($order->cancelled, 'Review does not cancel the order.');
    }
}

function test_magento_redirect_option_suffixes_respect_200_scalar_title_budget()
{
    // BUG-1480: appending configurable/custom options must not push a valid base title past 200 UTF-8 scalars.
    $base195 = str_repeat('Ж', 195);
    $exactBase = str_repeat('Б', 193); // 193 + ' · ' (3) + 'Blue' (4) = 200 scalars

    $makeItem = static function (string $name, array $options) {
        return new class ($name, $options) {
            private $name;
            private $options;
            public function __construct(string $name, array $options) { $this->name = $name; $this->options = $options; }
            public function isDeleted() { return false; }
            public function getParentItemId() { return null; }
            public function getName() { return $this->name; }
            public function getProductOptions() { return $this->options; }
            public function getDescription() { return null; }
            public function getQtyOrdered() { return 1; }
            public function getPrice() { return '75.00'; }
        };
    };

    $invoices = new FakeInvoices();
    list($controller, $order) = paymos_m2_redirect(array(), static function () use ($invoices) {
        return new FakeClient($invoices);
    });
    $order->items = array(
        $makeItem($base195, array('attributes_info' => array(array('label' => 'Color', 'value' => 'Midnight Blue')))),
        $makeItem($exactBase, array('options' => array(array('label' => 'Color', 'print_value' => 'Blue')))),
    );

    $result = $controller->execute();
    assertSameValue('https://paymos.test/pay/inv_123', $result->url, 'Redirect succeeds with long-titled options.');
    assertSameValue(1, count($invoices->createPayloads), 'One invoice created.');
    $products = $invoices->createPayloads[0]['products'] ?? null;
    assertTrueValue(is_array($products) && count($products) === 2, 'products[] must be preserved rather than dropped on option overflow.');
    assertSameValue($base195, $products[0]['title'], 'Overflowing options keep the 195-scalar base title.');
    assertSameValue($exactBase . ' · Blue', $products[1]['title'], 'Options fitting within 200 UTF-8 scalars are appended.');
}

function test_magento_redirect_item_description_drops_page_builder_style_blocks()
{
    // CMSP-28: since 2.4.3 Page Builder keeps element styles in a <style> block
    // inside the product description. strip_tags() leaves its CSS as text, so
    // the buyer read "#html-body [data-pb-style=…]{…}" as the item description.
    $pageBuilder = '<style>#html-body [data-pb-style=B9CB6F6]{justify-content:flex-start;display:flex;flex-direction:column}</style>'
        . '<div data-content-type="row" data-appearance="contained" data-element="main">'
        . '<div data-enable-parallax="0" data-element="inner" data-pb-style="B9CB6F6">'
        . '<div data-content-type="text" data-appearance="default" data-element="main"><p>Hand-stitched leather strap.</p></div>'
        . '</div></div><script type="text/x-magento-init">{"*":{"Magento_PageBuilder/js/widget-initializer":{}}}</script>';
    $cssOnly = '<style>#html-body [data-pb-style=AAA111]{display:none}</style>';
    $makeItem = static function (string $name, string $shortDescription, string $description) {
        $product = new class ($shortDescription, $description) {
            private $short;
            private $long;
            public function __construct(string $short, string $long) { $this->short = $short; $this->long = $long; }
            public function getShortDescription() { return $this->short; }
            public function getDescription() { return $this->long; }
        };
        return new class ($name, $product) {
            private $name;
            private $product;
            public function __construct(string $name, $product) { $this->name = $name; $this->product = $product; }
            public function isDeleted() { return false; }
            public function getParentItemId() { return null; }
            public function getName() { return $this->name; }
            public function getProductOptions() { return array(); }
            public function getDescription() { return null; }
            public function getProduct() { return $this->product; }
            public function getQtyOrdered() { return 1; }
            public function getPrice() { return '75.00'; }
        };
    };

    $invoices = new FakeInvoices();
    list($controller, $order) = paymos_m2_redirect(array(), static function () use ($invoices) {
        return new FakeClient($invoices);
    });
    $order->items = array(
        $makeItem('Leather watch', '', $pageBuilder),
        $makeItem('Steel watch', $cssOnly, '<p>Brushed steel case.</p>'),
    );

    $controller->execute();

    $products = $invoices->createPayloads[0]['products'] ?? null;
    assertTrueValue(is_array($products) && count($products) === 2, 'both items are sent.');
    assertSameValue('Hand-stitched leather strap.', $products[0]['description'] ?? null, 'the description keeps the text without the Page Builder CSS.');
    assertSameValue('Brushed steel case.', $products[1]['description'] ?? null, 'a short description that is only styles falls back to the description.');
}
