<?php

declare(strict_types=1);

namespace Paymos\Payment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\Order;
use Paymos\Exception\ApiException;
use Paymos\Payment\Service\CheckoutProcessor;
use Paymos\Payment\Service\GeneratedConfigProvider;
use Paymos\Payment\Service\InvoiceSnapshotRepository;
use Paymos\Payment\Service\Logger;
use Paymos\Payment\Service\PaymosClientFactory;
use Paymos\Payment\Service\Settings;
use Paymos\Plugin\AmountGuard;

/**
 * Creates the Paymos invoice for the just-placed order and redirects the
 * customer to the hosted checkout. No paid-state mutation happens here — the
 * order stays pending until the signed webhook confirms it.
 *
 * Implements HttpGetActionInterface (the renderer navigates here after placing
 * the order). On any failure the customer is returned to the cart with a notice.
 */
/**
 * NOT final — and no class in this module may be.
 *
 * Magento generates an Interceptor subclass for every DI-resolved class so its
 * plugins can wrap it; a final class makes that generated file fatal at include
 * time. On a controller the fatal is served as HTTP 200, so Paymos reads a webhook
 * delivery as successful and never retries — a payment is lost in silence.
 */
class Redirect implements HttpGetActionInterface
{
    /** @var ResultFactory */
    private $resultFactory;

    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var GeneratedConfigProvider */
    private $configProvider;

    /** @var InvoiceSnapshotRepository */
    private $snapshots;

    /** @var PaymosClientFactory */
    private $clientFactory;

    /** @var Settings */
    private $settings;

    /** @var \Magento\Framework\Message\ManagerInterface */
    private $messageManager;

    /** @var Logger */
    private $logger;

    public function __construct(
        ResultFactory $resultFactory,
        CheckoutSession $checkoutSession,
        GeneratedConfigProvider $configProvider,
        InvoiceSnapshotRepository $snapshots,
        PaymosClientFactory $clientFactory,
        Settings $settings,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        Logger $logger
    ) {
        $this->resultFactory = $resultFactory;
        $this->checkoutSession = $checkoutSession;
        $this->configProvider = $configProvider;
        $this->snapshots = $snapshots;
        $this->clientFactory = $clientFactory;
        $this->settings = $settings;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
    }

    /**
     * @return RedirectResult
     */
    public function execute()
    {
        /** @var RedirectResult $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $order = $this->checkoutSession->getLastRealOrder();
        if ($order === null || !$order->getId()) {
            $this->messageManager->addErrorMessage((string) __('We could not find your order. Please try again.'));
            return $redirect->setPath('checkout/cart');
        }

        try {
            return $this->snapshots->synchronized((int) $order->getEntityId(), function () use ($order, $redirect) {
                return $this->executeOwned($order, $redirect);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Paymos checkout could not acquire or retain the order lock.', ['error' => $e->getMessage()]);
            $this->messageManager->addErrorMessage((string) __('We could not start the crypto payment. Please choose another method.'));
            return $redirect->setPath('checkout/cart');
        }
    }

    private function executeOwned(Order $order, RedirectResult $redirect)
    {
        try {
            if ($this->reloadAndHasPayment($order)) { return $redirect->setPath('checkout/onepage/success'); }
            $snapshot = $this->snapshots->findByOrderId((int) $order->getEntityId());
            if (is_array($snapshot) && in_array((string) $snapshot['status'], ['paid', 'paid_over', 'payment_review'], true)) {
                $this->messageManager->addErrorMessage((string) __('The store needs to review this order before payment can continue. Please contact the store.'));
                return $redirect->setPath('checkout/cart');
            }
            $environment = $this->settings->mode($order->getStoreId());
            $processor = new CheckoutProcessor(
                $this->configProvider->get(),
                $this->snapshots,
                $this->clientFactory->asCallable()
            );

            $orderPayload = [
                'order_id' => (int) $order->getEntityId(),
                'increment_id' => (string) $order->getIncrementId(),
                'amount' => (string) $order->getGrandTotal(),
                'currency' => (string) $order->getOrderCurrencyCode(),
                'customer_id' => $order->getCustomerId(),
            ];
            $products = $this->extractProducts($order);
            if ($products !== []) {
                $orderPayload['products'] = $products;
            }

            $result = $processor->start($orderPayload, $environment);

            $this->snapshots->assertLocked((int) $order->getEntityId());
            if ($this->reloadAndHasPayment($order)) { return $redirect->setPath('checkout/onepage/success'); }

            // Apply the configured "while awaiting payment" status so the merchant
            // sees the order in their chosen pre-payment status during the redirect.
            // The invoice already exists at this point, so a failure to persist a
            // cosmetic status must NEVER fall through to the catch below (which would
            // cancel a successfully-invoiced order) — isolate and swallow it.
            try {
                $newStatus = $this->settings->newOrderStatus($order->getStoreId());
                if ($newStatus !== '' && $newStatus !== (string) $order->getStatus()) {
                    $order->setStatus($newStatus);
                    $order->addCommentToStatusHistory(
                        (string) __('Customer is paying via Paymos. Invoice: %1', $result['invoice_id']),
                        $newStatus
                    );
                    $order->save();
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Paymos could not apply the awaiting-payment order status.', [
                    'order_id' => (int) $order->getEntityId(),
                    'error' => $e->getMessage(),
                ]);
            }

            return $redirect->setUrl($result['payment_url']);
        } catch (\Paymos\Plugin\InvoiceReplacementBlockedException $e) {
            if ($this->reloadAndHasPayment($order)) { return $redirect->setPath('checkout/onepage/success'); }
            // The order's previous Paymos invoice may still be paid (BUG-166): no
            // second invoice was cut, and cancelling the order here would hide a
            // payment that can still arrive on the old one. Leave the order as it
            // is and note it for manual review. The buyer is asked to contact
            // the store, not to pay another way: that could charge them twice
            // (BUG-180). Same words as the SDK's InvoiceReplacementBlockedException,
            // spelled out so Magento's i18n collector sees the phrase.
            $this->noteForManualReview($order, $e->result()->summary());
            $this->messageManager->addErrorMessage((string) __('The store needs to review this order before payment can continue. Please contact the store.'));
        } catch (ApiException $e) {
            if ($this->reloadAndHasPayment($order)) { return $redirect->setPath('checkout/onepage/success'); }
            $this->failOrder($order, 'Paymos invoice creation failed: ' . $e->detail());
            $this->messageManager->addErrorMessage((string) __('We could not start the crypto payment: %1', $e->detail()));
        } catch (\Throwable $e) {
            if ($this->reloadAndHasPayment($order)) { return $redirect->setPath('checkout/onepage/success'); }
            $this->failOrder($order, 'Paymos invoice creation failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage((string) __('We could not start the crypto payment. Please choose another method.'));
        }

        if ($this->settings->debugEnabled($order->getStoreId())) {
            $this->logger->info('Paymos redirect returned the customer to the cart after a failed invoice create.', [
                'order_id' => (int) $order->getEntityId(),
            ]);
        }

        return $redirect->setPath('checkout/cart');
    }

    private function reloadAndHasPayment(Order $order): bool
    {
        $orderId = (int) $order->getEntityId();
        $this->snapshots->assertLocked($orderId);
        $order->reset();
        $order->getResource()->load($order, $orderId);
        if (!$order->getEntityId()) { throw new \RuntimeException('Magento order no longer exists.'); }
        return (float) $order->getTotalPaid() > 0 || (float) $order->getTotalRefunded() > 0
            || in_array((string) $order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED], true);
    }

    private function noteForManualReview(Order $order, string $summary): void
    {
        $this->logger->warning('Paymos order needs manual review.', [
            'order_id' => (int) $order->getEntityId(),
            'summary' => $summary,
        ]);
        try {
            $order->addCommentToStatusHistory('Paymos payment needs manual review. ' . $summary);
            $order->save();
        } catch (\Throwable $e) {
            $this->logger->warning('Paymos could not note the order for manual review.', [
                'order_id' => (int) $order->getEntityId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function failOrder(Order $order, string $comment): void
    {
        try {
            $this->checkoutSession->restoreQuote();
            if ($order->canCancel()) {
                $order->registerCancellation($comment);
                $order->save();
            }
        } catch (\Throwable $e) {
            // Best-effort cleanup; the customer already sees an error message.
            $this->logger->warning('Paymos could not roll back the order after a failed invoice create.', [
                'order_id' => (int) $order->getEntityId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract native visible product line items for Paymos invoice presentation.
     *
     * Shipping, tax, and order-level discounts live on the Magento order rather
     * than sales_order_item; composite child rows are excluded by getAllVisibleItems().
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractProducts(Order $order): array
    {
        if (method_exists($order, 'getAllVisibleItems')) {
            $rawItems = $order->getAllVisibleItems();
        } elseif (method_exists($order, 'getAllItems')) {
            $rawItems = $order->getAllItems();
        } else {
            return [];
        }

        if (!is_iterable($rawItems)) {
            return [];
        }

        $products = [];
        foreach ($rawItems as $item) {
            if (!is_object($item)) {
                continue;
            }
            if (method_exists($item, 'isDeleted') && $item->isDeleted()) {
                continue;
            }
            if (method_exists($item, 'getParentItemId') && $item->getParentItemId()) {
                continue;
            }

            $products[] = [
                'title' => $this->extractItemTitle($item),
                'description' => $this->extractItemDescription($item),
                'quantity' => $this->extractItemQuantity($item),
                'unit_amount' => $this->extractItemUnitAmount($item),
            ];
        }

        return $products;
    }

    /**
     * @param object $item
     */
    private function extractItemTitle($item): ?string
    {
        $name = method_exists($item, 'getName') ? $item->getName() : null;
        if (!is_string($name)) {
            return null;
        }
        if (trim($name) === '') {
            return $name;
        }

        $options = method_exists($item, 'getProductOptions') ? $item->getProductOptions() : null;
        if (!is_array($options)) {
            return $name;
        }

        $suffixes = [];
        foreach (['attributes_info', 'options'] as $group) {
            if (!isset($options[$group]) || !is_array($options[$group])) {
                continue;
            }
            foreach ($options[$group] as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $rawVal = $option['print_value'] ?? ($option['value'] ?? null);
                if (!is_scalar($rawVal)) {
                    continue;
                }
                $val = trim((string) $rawVal);
                if ($val !== '' && stripos($name, $val) === false && !in_array($val, $suffixes, true)) {
                    $suffixes[] = $val;
                }
            }
        }

        if ($suffixes === []) {
            return $name;
        }

        $withOptions = $name . ' · ' . implode(' · ', $suffixes);
        $chars = preg_split('//u', $withOptions, 201, PREG_SPLIT_NO_EMPTY);
        if (is_array($chars) && count($chars) <= 200) {
            return $withOptions;
        }

        return $name;
    }

    /**
     * @param object $item
     */
    private function extractItemDescription($item): ?string
    {
        $description = method_exists($item, 'getDescription') ? $item->getDescription() : null;
        if (is_string($description) && trim($description) !== '') {
            return $description;
        }

        $product = $this->safeItemProduct($item);
        if ($product === null) {
            return null;
        }

        foreach (['getShortDescription', 'getDescription'] as $getter) {
            if (method_exists($product, $getter)) {
                $val = $product->$getter();
                $val = is_string($val) ? self::withoutStyleAndScriptBlocks($val) : null;
                if ($val !== null && trim($val) !== '') {
                    return $val;
                }
            }
        }

        return null;
    }

    /**
     * Page Builder (2.4.3+) keeps element styles in a <style> block and widget
     * setup in <script type="text/x-magento-init"> inside the description.
     * strip_tags() would leave their content as text, so both are removed with
     * their content. Null when the pattern cannot be applied.
     */
    private static function withoutStyleAndScriptBlocks(string $html): ?string
    {
        $clean = preg_replace('#<(style|script)\b[^>]*>.*?</\1\s*>#is', '', $html);

        return is_string($clean) ? $clean : null;
    }

    /**
     * @param object $item
     * @return object|null
     */
    private function safeItemProduct($item)
    {
        if (!method_exists($item, 'getProduct')) {
            return null;
        }
        try {
            $product = $item->getProduct();
            return is_object($product) ? $product : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Paymos could not load product presentation metadata.', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param object $item
     * @return int|string|null
     */
    private function extractItemQuantity($item)
    {
        if (!method_exists($item, 'getQtyOrdered')) {
            return null;
        }
        $rawQty = $item->getQtyOrdered();
        if (is_int($rawQty)) {
            return $rawQty;
        }
        if (is_float($rawQty)) {
            $normalized = AmountGuard::normalizeDecimal($rawQty);
            return $normalized !== null ? $normalized : $rawQty;
        }
        if (is_string($rawQty)) {
            $trimmed = trim($rawQty);
            // Magento DECIMAL(12,4) columns return strings like "2.0000" for whole quantities.
            if (preg_match('/\A(?:0|[1-9][0-9]*)\.[0-9]+\z/', $trimmed) === 1) {
                $normalized = AmountGuard::normalizeDecimal($trimmed);
                return $normalized !== null ? $normalized : $rawQty;
            }
            return $rawQty;
        }

        return null;
    }

    /**
     * @param object $item
     */
    private function extractItemUnitAmount($item): ?string
    {
        if (!method_exists($item, 'getPrice')) {
            return null;
        }
        $rawPrice = $item->getPrice();
        if (is_int($rawPrice)) {
            return (string) $rawPrice;
        }
        if (is_float($rawPrice) && is_finite($rawPrice)) {
            $str = (string) $rawPrice;
            if (stripos($str, 'e') === false) {
                return $str;
            }
            if (abs($rawPrice) < 1.0) {
                $formatted = rtrim(rtrim(sprintf('%.18F', $rawPrice), '0'), '.');
                return $formatted === '-0' ? '0' : $formatted;
            }
            return number_format($rawPrice, 0, '.', '');
        }
        if (is_string($rawPrice)) {
            $trimmed = trim($rawPrice);
            if (preg_match('/\A(?:0|[1-9][0-9]*)\.[0-9]{2}([0-9]+)\z/', $trimmed, $matches) === 1) {
                $head = substr($trimmed, 0, strlen($trimmed) - strlen($matches[1]));
                return $head . rtrim($matches[1], '0');
            }
            return $trimmed;
        }

        return null;
    }
}
