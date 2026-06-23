<?php

declare(strict_types=1);

namespace Paymos\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Paymos\Payment\Service\EventStore;
use Paymos\Payment\Service\GeneratedConfigProvider;
use Paymos\Payment\Service\InvoiceSnapshotRepository;
use Paymos\Payment\Service\MagentoOrderGateway;
use Paymos\Payment\Service\OrderMapper;
use Paymos\Payment\Service\PaymosClientFactory;
use Paymos\Payment\Service\Settings;
use Paymos\Payment\Service\WebhookProcessor;

/**
 * Receives signed Paymos webhooks at paymos/payment/callback.
 *
 * Implements HttpPostActionInterface (POST marker) and CsrfAwareActionInterface
 * so the external webhook is exempt from Magento's form-key CSRF check. The
 * exemption only lets the request through — the Paymos HMAC signature
 * (verified in WebhookProcessor) is the sole trust boundary, and an invalid
 * signature returns 401.
 *
 * Webhook signature algorithm (handled by the SDK, NOT this controller): hex
 * HMAC-SHA256 over "{ts}.{rawBody}" in the X-Webhook-Signature header. This is
 * different from the base64 API request signing — never conflate the two.
 *
 * @see Magento\Framework\App\CsrfAwareActionInterface
 */
final class Callback implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** @var HttpRequest */
    private $request;

    /** @var HttpResponse */
    private $response;

    /** @var GeneratedConfigProvider */
    private $configProvider;

    /** @var InvoiceSnapshotRepository */
    private $snapshots;

    /** @var EventStore */
    private $eventStore;

    /** @var MagentoOrderGateway */
    private $orderGateway;

    /** @var PaymosClientFactory */
    private $clientFactory;

    /** @var Settings */
    private $settings;

    public function __construct(
        HttpRequest $request,
        HttpResponse $response,
        GeneratedConfigProvider $configProvider,
        InvoiceSnapshotRepository $snapshots,
        EventStore $eventStore,
        MagentoOrderGateway $orderGateway,
        PaymosClientFactory $clientFactory,
        Settings $settings
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->configProvider = $configProvider;
        $this->snapshots = $snapshots;
        $this->eventStore = $eventStore;
        $this->orderGateway = $orderGateway;
        $this->clientFactory = $clientFactory;
        $this->settings = $settings;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Allow the request through; Paymos signature verification is the trust
        // boundary, not the Magento form key.
        return true;
    }

    /**
     * @return HttpResponse
     */
    public function execute()
    {
        $rawBody = (string) $this->request->getContent();
        $signature = $this->signatureHeader();

        $processor = new WebhookProcessor(
            $this->configProvider->get(),
            $this->snapshots,
            $this->eventStore,
            new OrderMapper($this->orderGateway),
            $this->clientFactory->asCallable()
        );

        // Always notify the customer on the paid invoice — this is the buyer's
        // payment receipt. It must NOT be tied to the diagnostics flag.
        $result = $processor->handle(
            $rawBody,
            $signature,
            $this->settings->paidOrderStatus(),
            true
        );

        return $this->response
            ->setHttpResponseCode($result->httpCode())
            ->setHeader('Content-Type', 'text/plain', true)
            ->setBody($result->message());
    }

    /**
     * The server sends X-Webhook-Signature; Magento header lookup is
     * case-insensitive but be explicit about the canonical name.
     */
    private function signatureHeader(): string
    {
        $value = $this->request->getHeader('X-Webhook-Signature');

        return $value === false || $value === null ? '' : (string) $value;
    }
}
