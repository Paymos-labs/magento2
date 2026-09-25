<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Paymos\Client;
use Paymos\Exception\NotFoundException;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\StatusMapper;

/**
 * Flow A — checkout to invoice. Builds the Paymos invoice payload from a Magento
 * order summary, creates the invoice via the SDK, persists a snapshot, and
 * returns the hosted-checkout URL to redirect to.
 *
 * The payload carries ONLY the fields CreateInvoiceRequest accepts:
 *   project_id, amount (dot-decimal string), currency, external_order_id,
 *   allow_multiple_payments, and optional client_id (the Magento customer id,
 *   never the email; omitted for guest checkout).
 *
 * MerchantId is NEVER sent (the server derives it from project_id). There is NO
 * lifetime/TTL field — invoice expiry is server-side.
 *
 * external_order_id is deterministic and version-bumped: the saved invoice is
 * reused while the amount/currency snapshot matches; a renew suffix is appended
 * when the order amount changed, so a changed order gets a fresh invoice.
 */
class CheckoutProcessor
{
    /** @var Config */
    private $config;

    /** @var SnapshotRepositoryInterface */
    private $snapshots;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(Config $config, SnapshotRepositoryInterface $snapshots, ?callable $clientFactory = null)
    {
        $this->config = $config;
        $this->snapshots = $snapshots;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array{order_id:int, increment_id:string, amount:string, currency:string, customer_id?:int|string|null} $order
     * @param string $environment sandbox|live (the admin mode)
     * @return array{invoice_id:string, payment_url:string, reused:bool}
     */
    public function start(array $order, string $environment): array
    {
        $environment = $environment === 'live' ? 'live' : 'sandbox';
        $env = $this->config->environment($environment);

        $orderId = (int) $order['order_id'];
        $incrementId = (string) $order['increment_id'];
        $amount = $this->formatAmount($order['amount']);
        $currency = strtoupper(trim((string) $order['currency']));
        if ($incrementId === '') {
            throw new \RuntimeException('Magento order increment id is missing.');
        }
        if ($currency === '') {
            throw new \RuntimeException('Magento order currency is missing.');
        }

        $existing = $this->snapshots->findByOrderId($orderId);
        if (is_array($existing) && $this->snapshotMatches($existing, $amount, $currency, $environment, $env->projectId())
            && $this->keepsExistingInvoice($existing, $environment)) {
            return [
                'invoice_id' => (string) $existing['paymos_invoice_id'],
                'payment_url' => (string) $existing['payment_url'],
                'reused' => true,
            ];
        }

        $renewCount = is_array($existing) && isset($existing['renew_count']) ? ((int) $existing['renew_count'] + 1) : 0;
        $externalOrderId = $incrementId . '-' . $renewCount;

        $payload = [
            'project_id' => $env->projectId(),
            'amount' => $amount,
            'currency' => $currency,
            'external_order_id' => $externalOrderId,
            'allow_multiple_payments' => true,
        ];

        $clientId = $this->clientId($order);
        if ($clientId !== '') {
            $payload['client_id'] = $clientId;
        }

        $response = $this->client($environment)->invoices()->create($payload);

        $paymosInvoiceId = $this->responseField($response, ['invoice_id']);
        $paymentUrl = $this->responseField($response, ['payment_url']);
        if ($paymosInvoiceId === '' || $paymentUrl === '') {
            throw new \RuntimeException('Paymos invoice create response is missing the invoice id or payment URL.');
        }

        $status = $this->responseField($response, ['status']);

        $this->snapshots->save([
            'order_id' => $orderId,
            'order_increment_id' => $incrementId,
            'external_order_id' => $externalOrderId,
            'paymos_invoice_id' => $paymosInvoiceId,
            'environment' => $environment,
            'project_id' => $env->projectId(),
            'amount' => $amount,
            'currency' => $currency,
            'payment_url' => $paymentUrl,
            'status' => $status !== '' ? $status : 'created',
            'renew_count' => $renewCount,
        ]);

        return [
            'invoice_id' => $paymosInvoiceId,
            'payment_url' => $paymentUrl,
            'reused' => false,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function snapshotMatches(array $row, string $amount, string $currency, string $environment, string $projectId): bool
    {
        return (string) $row['amount'] === $amount
            && strtoupper((string) $row['currency']) === $currency
            && (string) $row['environment'] === $environment
            && (string) $row['project_id'] === $projectId
            && trim((string) $row['payment_url']) !== '';
    }

    /**
     * Whether the Paymos invoice behind a matching snapshot is still the one to
     * send the buyer to.
     *
     * A matching amount is not enough: the server answers a repeated
     * external_order_id with the same invoice whatever became of it, and a buyer
     * returning after it ended would land on an expired checkout. Its deadline is
     * the server's, not a copy kept here: confirming a network moves expires_at to
     * now + InvoiceOptions.PaymentTtl and sends no webhook. So: a row that already
     * ended unpaid is renewed at once (that final status came from the server and
     * never changes again); a paid one is kept (a second invoice would invite a
     * second payment); anything else is read back from the server (one GET) and
     * renewed only if the server says it ended unpaid or was never started before
     * its deadline (InvoiceRenewal). An invoice the server holds open — network
     * picked, funds confirming, part paid — is kept. When the server cannot be
     * reached the existing link is kept — the checkout it leads to is down just the
     * same.
     *
     * @param array<string, mixed> $row
     */
    private function keepsExistingInvoice(array $row, string $environment): bool
    {
        if (InvoiceRenewal::isRequired($row)) {
            return false;
        }
        if (StatusMapper::isFinalStatus(isset($row['status']) ? (string) $row['status'] : '')) {
            return true;
        }

        try {
            $invoice = $this->client($environment)->invoices()->get((string) $row['paymos_invoice_id']);
        } catch (NotFoundException $e) {
            return false;
        } catch (\Exception $e) {
            return true;
        }

        if (!is_array($invoice) || !InvoiceRenewal::isRequired($invoice)) {
            return true;
        }

        $status = $this->responseField($invoice, ['status']);
        if ($status !== '') {
            $this->snapshots->updateStatus((string) $row['paymos_invoice_id'], $status);
        }

        return false;
    }

    /**
     * @return Client|object An SDK client (or a duck-typed test double exposing
     *                       invoices()->create() and ->get()).
     */
    private function client(string $environment)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $environment);
        }

        return new Client($this->config->clientConfigForEnvironment($environment));
    }

    /**
     * @param array<string, mixed> $order
     */
    private function clientId(array $order): string
    {
        $customerId = isset($order['customer_id']) && is_scalar($order['customer_id'])
            ? trim((string) $order['customer_id'])
            : '';

        return ($customerId !== '' && $customerId !== '0') ? $customerId : '';
    }

    /**
     * @param mixed $amount
     */
    private function formatAmount($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $path
     */
    private function responseField(array $source, array $path): string
    {
        $current = $source;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }
}
