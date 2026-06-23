<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Paymos\Client;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\EventStoreInterface;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use Paymos\Webhook\WebhookEvent;

/**
 * Orchestrates a Paymos webhook for Magento, end to end:
 *
 *   verify (HMAC across sandbox+live secrets) + dedup
 *     -> non-invoice event? commit + 200 "ignored"
 *     -> assert payload environment matches the matched secret's environment
 *     -> find the order snapshot by external_order_id; assert it matches
 *     -> reverse-verify terminal events against the live API (anti-spoof)
 *     -> OrderMapper (StatusMapper -> roll-back guard -> AmountGuard -> mutate)
 *     -> commit dedup on success, release on failure (so the server retries)
 *
 * HTTP codes are the retry contract: duplicate->200, signature mismatch->401,
 * timestamp skew->401, config error->500, any other failure->400. There is no
 * 202 — a 2xx tells the server to stop retrying.
 */
final class WebhookProcessor
{
    /** @var Config */
    private $config;

    /** @var SnapshotRepositoryInterface */
    private $snapshots;

    /** @var EventStoreInterface */
    private $eventStore;

    /** @var OrderMapper */
    private $orderMapper;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(
        Config $config,
        SnapshotRepositoryInterface $snapshots,
        EventStoreInterface $eventStore,
        OrderMapper $orderMapper,
        callable $clientFactory = null
    ) {
        $this->config = $config;
        $this->snapshots = $snapshots;
        $this->eventStore = $eventStore;
        $this->orderMapper = $orderMapper;
        $this->clientFactory = $clientFactory;
    }

    public function handle($rawBody, $signatureHeader, string $paidStatus, bool $notifyCustomer, $now = null): CallbackResult
    {
        try {
            $verified = (new MultiEnvironmentWebhookVerifier($this->config->webhookSecrets(), $this->eventStore))
                ->process($signatureHeader, $rawBody, $now);
            $environment = $verified->environment();
            $event = $verified->event();

            if (!$event->isInvoiceEvent()) {
                $this->commitEvent();
                return new CallbackResult(200, 'OK');
            }

            $this->assertPayloadEnvironment($event, $environment);
            $this->applyVerifiedEvent($event, $environment, $paidStatus, $notifyCustomer);
            $this->commitEvent();

            return new CallbackResult(200, 'OK');
        } catch (DuplicateEventException $e) {
            return new CallbackResult(200, 'OK', true);
        } catch (SignatureMismatchException $e) {
            return new CallbackResult(401, 'Bad signature');
        } catch (TimestampSkewException $e) {
            return new CallbackResult(401, 'Bad timestamp');
        } catch (\InvalidArgumentException $e) {
            $this->releaseEvent();
            $this->orderMapper->gateway()->log('Paymos Magento configuration error.', ['error' => $e->getMessage()]);
            return new CallbackResult(500, 'Configuration error');
        } catch (\Throwable $e) {
            // A RuntimeException (snapshot/order/reverse-verify/amount) OR any
            // PHP Error during mutation must still release the in-flight dedup
            // lock, otherwise the event is durably marked seen and never retried.
            $this->releaseEvent();
            $this->orderMapper->gateway()->log('Paymos Magento webhook processing failed.', ['error' => $e->getMessage()]);
            return new CallbackResult(400, 'Processing failed');
        }
    }

    private function applyVerifiedEvent(WebhookEvent $event, string $environment, string $paidStatus, bool $notifyCustomer): void
    {
        $externalOrderId = $event->externalOrderId();
        if ($externalOrderId === '') {
            throw new \RuntimeException('Paymos webhook payload is missing the external order id.');
        }

        $row = $this->snapshots->findByExternalOrderId($externalOrderId);
        if (!is_array($row)) {
            throw new \RuntimeException('Paymos Magento invoice snapshot was not found.');
        }

        $this->assertRowMatchesEvent($row, $event, $environment);

        if ($this->requiresReverseVerify($event)) {
            $result = (new InvoiceReverseVerifier($this->client($environment)))->verify($event, [
                'project_id' => (string) $row['project_id'],
                'external_order_id' => (string) $row['external_order_id'],
                'amount' => (string) $row['amount'],
                'currency' => (string) $row['currency'],
            ]);

            if (!$result->isVerified()) {
                throw new \RuntimeException('Paymos reverse verification failed: ' . $result->reason());
            }
        }

        // Mutate the order first; only then persist the last-known status. If
        // apply() throws (e.g. AmountGuard holds the order for manual review), the
        // event is released for retry and the snapshot status must NOT be advanced
        // to a terminal value the order never actually reached.
        $this->orderMapper->apply($event, $row, $paidStatus, $notifyCustomer);

        $this->snapshots->updateStatus($event->invoiceId(), $event->status());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertRowMatchesEvent(array $row, WebhookEvent $event, string $environment): void
    {
        if ((string) $row['environment'] !== $environment) {
            throw new \RuntimeException('Paymos event environment does not match the Magento invoice snapshot.');
        }
        if ((string) $row['project_id'] !== '' && $event->projectId() !== '' && (string) $row['project_id'] !== $event->projectId()) {
            throw new \RuntimeException('Paymos event project does not match the Magento invoice snapshot.');
        }
        if ((string) $row['external_order_id'] !== '' && $event->externalOrderId() !== '' && (string) $row['external_order_id'] !== $event->externalOrderId()) {
            throw new \RuntimeException('Paymos event external order does not match the Magento invoice snapshot.');
        }
        if ((string) $row['paymos_invoice_id'] !== '' && $event->invoiceId() !== '' && (string) $row['paymos_invoice_id'] !== $event->invoiceId()) {
            throw new \RuntimeException('Paymos event invoice id does not match the Magento invoice snapshot.');
        }
    }

    private function assertPayloadEnvironment(WebhookEvent $event, string $environment): void
    {
        $isTest = $event->isTest();
        if ($isTest === null) {
            return;
        }

        if ($environment === 'sandbox' && $isTest !== true) {
            throw new \RuntimeException('Sandbox webhook payload is not marked as test.');
        }
        if ($environment === 'live' && $isTest !== false) {
            throw new \RuntimeException('Live webhook payload is marked as test.');
        }
    }

    private function requiresReverseVerify(WebhookEvent $event): bool
    {
        $action = StatusMapper::invoiceAction($event->type(), $event->status());

        return in_array($action, [
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ], true);
    }

    private function client(string $environment): Client
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $environment);
        }

        return new Client($this->config->clientConfigForEnvironment($environment));
    }

    private function commitEvent(): void
    {
        if (method_exists($this->eventStore, 'commit')) {
            $this->eventStore->commit();
        }
    }

    private function releaseEvent(): void
    {
        if (method_exists($this->eventStore, 'release')) {
            $this->eventStore->release();
        }
    }
}
