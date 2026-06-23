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

/**
 * Creates the Paymos invoice for the just-placed order and redirects the
 * customer to the hosted checkout. No paid-state mutation happens here — the
 * order stays pending until the signed webhook confirms it.
 *
 * Implements HttpGetActionInterface (the renderer navigates here after placing
 * the order). On any failure the customer is returned to the cart with a notice.
 */
final class Redirect implements HttpGetActionInterface
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
            $environment = $this->settings->mode($order->getStoreId());
            $processor = new CheckoutProcessor(
                $this->configProvider->get(),
                $this->snapshots,
                $this->clientFactory->asCallable()
            );

            $result = $processor->start([
                'order_id' => (int) $order->getEntityId(),
                'increment_id' => (string) $order->getIncrementId(),
                'amount' => (string) $order->getGrandTotal(),
                'currency' => (string) $order->getOrderCurrencyCode(),
                'customer_id' => $order->getCustomerId(),
            ], $environment);

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
        } catch (ApiException $e) {
            $this->failOrder($order, 'Paymos invoice creation failed: ' . $e->detail());
            $this->messageManager->addErrorMessage((string) __('We could not start the crypto payment: %1', $e->detail()));
        } catch (\Throwable $e) {
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
}
