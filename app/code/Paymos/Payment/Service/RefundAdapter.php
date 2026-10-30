<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\CredentialSet;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use Paymos\Webhook\WebhookEvent;

class RefundAdapter implements NativeRefundProjectorInterface
{
    private $store;
    private $native;
    private $credentials;
    private $clients;

    public function __construct(RefundIntentStore $store, RefundNativeGateway $native,
        CredentialStore $credentials, PaymosClientFactory $clients)
    {
        $this->store = $store;
        $this->native = $native;
        $this->credentials = $credentials;
        $this->clients = $clients;
    }

    public function submit(int $orderId, string $mode, string $token, ?array $input = null, ?int $invoiceId = null): RefundIntent
    {
        if (!in_array($mode, [RefundStates::FULL, RefundStates::OVERPAYMENT], true)
            || !preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $token)
            || ($mode === RefundStates::FULL && $input === null)
            || ($mode === RefundStates::OVERPAYMENT && $input !== null)) {
            throw new \RuntimeException('refund_mode_and_token_only');
        }
        $key = $this->native->paymentKey($orderId);
        return $this->store->withPaymentLock($key, function () use ($orderId, $mode, $token, $input, $invoiceId, $key) {
            $installation = $this->store->installationId();
            $existing = $this->store->findByRequestToken($installation, $token);
            if ($existing !== null) {
                if ($existing->paymentKey() !== $key || $existing->mode() !== $mode
                    || ($mode === RefundStates::FULL && ($existing->fullDraft()['request_input'] !== self::canonical($input)
                        || $existing->fullDraft()['entry_invoice_id'] !== $invoiceId))) {
                    throw new \RuntimeException('refund_request_conflict');
                }
                return $this->submitExisting($existing);
            }
            $this->store->assertNewActionAllowed($key);
            $mapping = $this->native->mapping($orderId);
            $draft = $mode === RefundStates::FULL ? $this->native->draft($mapping, $input, $invoiceId) : null;
            if ($draft !== null) {
                $draft['request_input'] = self::canonical($input);
                $draft['entry_invoice_id'] = $invoiceId;
            }
            $intent = new RefundIntent(['operation_id' => RefundIntentStore::uuid(), 'request_token' => $token,
                'installation_id' => $installation, 'platform' => 'magento2', 'native_payment_key' => $key,
                'invoice_id' => $mapping['snapshot']['paymos_invoice_id'], 'project_id' => $mapping['snapshot']['project_id'],
                'environment' => $mapping['snapshot']['environment'], 'mode' => $mode, 'full_draft' => $draft]);
            // Native guards run before the intent; server guards run from the committed intent.
            $this->native->assertBoundary($mode === RefundStates::FULL);
            $this->capability($intent);
            $intent = $this->store->createIntent($intent);
            return $this->submitExisting($intent);
        });
    }

    public function resume(int $orderId, string $token): RefundIntent
    {
        $key = $this->native->paymentKey($orderId);
        return $this->store->withPaymentLock($key, function () use ($key, $token) {
            $intent = $this->store->findByRequestToken($this->store->installationId(), $token);
            if ($intent === null || $intent->paymentKey() !== $key) {
                throw new \RuntimeException('refund_request_missing');
            }
            return $this->submitExisting($intent);
        });
    }

    private function submitExisting(RefundIntent $intent): RefundIntent
    {
        $this->assertIdentity($intent);
        $this->coordinator($intent)->submit($intent->operationId());
        $this->store->schedule($intent->operationId());
        return $this->store->getIntent($intent->operationId());
    }

    public function preflight(RefundIntent $intent): ?string
    {
        try {
            $this->capability($intent);
            $this->assertIdentity($intent);
            $mapping = $this->native->mapping($this->orderId($intent));
            $this->native->assertBoundary($intent->mode() === RefundStates::FULL);
            if ($intent->mode() === RefundStates::FULL) {
                $saved = $intent->fullDraft();
                $draft = $this->native->draft($mapping, $saved['request_input'], $saved['entry_invoice_id']);
                unset($saved['request_input'], $saved['entry_invoice_id']);
                if ($draft !== $saved) {
                    return 'refund_native_draft_changed';
                }
            }
            $invoice = $this->clients->create($intent->environment())->invoices()->get($intent->invoiceId());
            $snapshot = $mapping['snapshot'];
            if (($invoice['invoice_id'] ?? null) !== $intent->invoiceId()
                || ($invoice['project_id'] ?? null) !== $intent->projectId()
                || ($invoice['is_test'] ?? null) !== ($intent->environment() === 'sandbox')
                || ($invoice['order']['external_id'] ?? null) !== $snapshot['external_order_id']
                || !AmountGuard::amountsEqual($invoice['order']['amount'] ?? '', $snapshot['amount'])
                || ($invoice['order']['currency'] ?? null) !== $snapshot['currency']) {
                return 'refund_invoice_mapping_conflict';
            }
            $field = $intent->mode() === RefundStates::FULL ? 'can_refund_full' : 'can_refund_overpayment';
            return ($invoice['refund_summary'][$field] ?? false) === true ? null : 'refund_server_action_unavailable';
        } catch (\Throwable $error) {
            return 'refund_preflight_unavailable';
        }
    }

    private function capability(RefundIntent $intent): void
    {
        $credentials = $this->credentials->loadCredentials();
        $config = $credentials[$intent->environment()] ?? [];
        if (!CredentialSet::hasCapability($config, 'invoice_refunds_v1') || ($config['refund_contract_version'] ?? null) !== 1) {
            throw new \RuntimeException('refund_reconnect_required');
        }
        if (!CredentialSet::refundsEnabled($config)) {
            throw new \RuntimeException('refunds_not_enabled');
        }
    }

    private function assertIdentity(RefundIntent $intent): void
    {
        $mapping = $this->native->identity($this->orderId($intent));
        $credentials = $this->credentials->loadCredentials();
        $row = $mapping['snapshot'];
        if ($mapping['payment_key'] !== $intent->paymentKey() || $row['paymos_invoice_id'] !== $intent->invoiceId()
            || $row['project_id'] !== $intent->projectId() || $row['environment'] !== $intent->environment()
            || ($credentials[$intent->environment()]['project_id'] ?? null) !== $intent->projectId()) {
            throw new \RuntimeException('refund_mapping_conflict');
        }
    }

    public function inspect(string $operationId): NativeProjectionResult
    {
        $intent = $this->store->getIntent($operationId);
        $this->assertIdentity($intent);
        return $this->native->inspect($intent);
    }

    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        $this->assertIdentity($intent);
        if ($refund->status() !== 'completed' || $refund->withdrawalId() !== $intent->withdrawalId()
            || $intent->observation() === null || $refund->toArray() !== $intent->observation()->toArray()) {
            throw new \RuntimeException('refund_verified_completion_required');
        }
        return $this->native->apply($intent, $refund);
    }

    public function reconcile(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);
        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent, $operationId) {
            try {
                $this->assertIdentity($intent);
                if ($intent->cashState() === RefundStates::NOT_SUBMITTED) {
                    $this->coordinator($intent)->submit($operationId);
                }
                $action = $this->coordinator($intent)->reconcile($operationId);
                $this->notify($this->store->getIntent($operationId));
                return $action;
            } finally {
                $this->store->schedule($operationId);
            }
        });
    }

    public function handleEvent(WebhookEvent $event, string $environment): void
    {
        $payload = $event->toArray();
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        if (strpos($event->type(), 'withdrawal.') !== 0 || empty($data['invoice_id'])) {
            return;
        }
        if (($data['is_test'] ?? null) !== ($environment === 'sandbox')) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }
        $intent = isset($data['external_order_id']) && is_string($data['external_order_id'])
            ? $this->store->findByExternalId($data['external_order_id']) : null;
        if ($intent === null && isset($data['withdrawal_id']) && is_string($data['withdrawal_id'])) {
            $intent = $this->store->findByWithdrawalId($data['withdrawal_id']);
        }
        if ($intent === null) {
            $this->readUnbound((string) ($data['withdrawal_id'] ?? ''), (string) $data['invoice_id'],
                $environment, (string) ($data['project_id'] ?? ''), (string) ($data['refund_mode'] ?? $data['mode'] ?? ''));
            return;
        }
        if ($environment !== $intent->environment() || ($data['is_test'] ?? null) !== ($environment === 'sandbox')
            || ($data['invoice_id'] ?? null) !== $intent->invoiceId() || ($data['project_id'] ?? null) !== $intent->projectId()
            || ($data['refund_mode'] ?? $data['mode'] ?? null) !== $intent->mode()
            || ($intent->withdrawalId() !== null && ($data['withdrawal_id'] ?? null) !== $intent->withdrawalId())) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }
        $this->reconcile($intent->operationId());
    }

    public function run(): int
    {
        $count = 0;
        $page = $this->store->listDue(new \DateTimeImmutable('now'), 100, null);
        foreach ($page->items() as $intent) {
            try {
                $this->reconcile($intent->operationId());
                $count++;
            } catch (\Throwable $error) {
                $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent) {
                    $this->store->schedule($intent->operationId());
                });
            }
        }
        foreach ($this->store->unboundDue() as $row) {
            $refund = VerifiedRefund::fromStorage(json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR));
            try {
                $this->readUnbound($refund->withdrawalId(), $refund->invoiceId(), $refund->environment(), $refund->projectId(), $refund->mode());
                $count++;
            } catch (\Throwable $error) {
                $this->store->withPaymentLock($row['payment_key'], function () use ($row) {
                    $this->store->deferUnbound($row['withdrawal_id']);
                });
            }
        }
        return $count;
    }

    private function readUnbound(string $id, string $invoiceId, string $environment, string $projectId, string $mode): void
    {
        if ($id === '' || $invoiceId === '' || $projectId === '' || !in_array($mode, ['full', 'overpayment'], true)) {
            throw new \RuntimeException('refund_unbound_scope_invalid');
        }
        $orderId = $this->native->orderForProviderInvoice($invoiceId);
        $key = $this->native->paymentKey($orderId);
        $this->store->withPaymentLock($key, function () use ($id, $invoiceId, $environment, $projectId, $mode, $orderId) {
            $mapping = $this->native->identity($orderId);
            $row = $mapping['snapshot'];
            $credentials = $this->credentials->loadCredentials();
            if ($row['paymos_invoice_id'] !== $invoiceId || $row['project_id'] !== $projectId || $row['environment'] !== $environment
                || ($credentials[$environment]['project_id'] ?? null) !== $projectId) {
                throw new \RuntimeException('refund_unbound_scope_conflict');
            }
            $client = $this->clients->create($environment);
            $wire = $client->refunds()->get($id);
            $invoice = $client->invoices()->get($invoiceId);
            $refund = VerifiedRefund::fromApi($wire, $invoice, new \DateTimeImmutable('now'));
            if ($refund->withdrawalId() !== $id || $refund->invoiceId() !== $invoiceId || $refund->projectId() !== $projectId
                || $refund->environment() !== $environment || $refund->mode() !== $mode
                || ($invoice['order']['external_id'] ?? null) !== $row['external_order_id']
                || !AmountGuard::amountsEqual($invoice['order']['amount'] ?? '', $row['amount'])
                || ($invoice['order']['currency'] ?? null) !== $row['currency']) {
                throw new \RuntimeException('refund_unbound_evidence_conflict');
            }
            $local = $this->store->findByExternalId($refund->externalOrderId());
            if ($local !== null) {
                $this->reconcile($local->operationId());
                return;
            }
            $this->native->observeUnbound($orderId, $refund);
        });
    }

    private function notify(RefundIntent $intent): void
    {
        if ($intent->mode() !== RefundStates::FULL || $intent->cashState() !== RefundStates::COMPLETED
            || $intent->projectionState() !== RefundStates::PROJECTION_APPLIED || !$intent->fullDraft()['send_email']) {
            return;
        }
        $key = 'creditmemo_email:1';
        if ($this->store->claimEffect($intent->operationId(), 'creditmemo_email', 1)->kind() !== EffectClaim::CLAIMED) {
            if (($intent->toArray()['effects'][$key] ?? null) === RefundStates::EFFECT_STARTED) {
                $this->store->recordEffectOutcome($intent->operationId(), 'creditmemo_email', 1, RefundStates::EFFECT_UNKNOWN);
            }
            return;
        }
        try {
            $sent = $this->native->send($intent);
            $state = $sent ? RefundStates::EFFECT_DELIVERED : RefundStates::EFFECT_UNKNOWN;
        } catch (\Throwable $error) {
            $state = RefundStates::EFFECT_UNKNOWN;
        }
        $this->store->recordEffectOutcome($intent->operationId(), 'creditmemo_email', 1, $state);
    }

    public function operations(int $orderId): array
    {
        $key = $this->native->paymentKey($orderId);
        return $this->store->withPaymentLock($key, function () use ($key) {
            return array_map(function ($intent) { return $this->publicState($intent); }, $this->store->forPayment($key));
        });
    }

    public function panel(int $orderId): array
    {
        $key = $this->native->paymentKey($orderId);
        return $this->store->withPaymentLock($key, function () use ($orderId, $key) {
            $mapping = $this->native->identity($orderId);
            $row = $mapping['snapshot'];
            $credentials = $this->credentials->loadCredentials();
            if (($credentials[$row['environment']]['project_id'] ?? null) !== $row['project_id']) {
                throw new \RuntimeException('refund_mapping_conflict');
            }
            $invoice = $this->clients->create($row['environment'])->invoices()->get($row['paymos_invoice_id']);
            if (($invoice['invoice_id'] ?? null) !== $row['paymos_invoice_id']
                || ($invoice['project_id'] ?? null) !== $row['project_id']
                || ($invoice['is_test'] ?? null) !== ($row['environment'] === 'sandbox')) {
                throw new \RuntimeException('refund_mapping_conflict');
            }
            $summary = isset($invoice['refund_summary']) && is_array($invoice['refund_summary']) ? $invoice['refund_summary'] : [];
            $allowed = true;
            try {
                $this->store->assertNewActionAllowed($key);
                $this->native->mapping($orderId);
                $this->native->assertBoundary(false);
                $config = $credentials[$row['environment']];
                $allowed = CredentialSet::hasCapability($config, 'invoice_refunds_v1')
                    && ($config['refund_contract_version'] ?? null) === 1 && CredentialSet::refundsEnabled($config);
            } catch (\Throwable $error) {
                $allowed = false;
            }
            return ['message' => 'Full refund: open the order and click Credit Memo, then Refund Offline. On Paymos orders this button requests the refund through Paymos. Overpayment only: click Refund overpayment here; the order stays paid and stock is unchanged.',
                'order_id' => $orderId, 'order_increment_id' => (string) $mapping['order']['increment_id'],
                'order_amount' => (string) $mapping['order']['grand_total'],
                'order_currency' => (string) $mapping['order']['order_currency_code'], 'summary' => $summary,
                'can_overpayment' => $allowed && ($summary['can_refund_overpayment'] ?? false) === true,
                'request_token' => RefundIntentStore::uuid(), 'operations' => $this->operations($orderId),
                'unbound' => array_map(static function ($observation) {
                    $observation['observation'] = json_decode($observation['payload'], true, 512, JSON_THROW_ON_ERROR);
                    unset($observation['payload']);
                    return $observation;
                }, $this->store->unboundForPayment($key))];
        });
    }

    public function publicState(RefundIntent $intent): array
    {
        $data = $intent->toArray();
        return array_intersect_key($data, array_flip(['operation_id', 'request_token', 'mode', 'withdrawal_id',
            'cash_state', 'projection_state', 'review_reason', 'observation', 'effects']));
    }

    private function coordinator(RefundIntent $intent): RefundCoordinator
    {
        return new RefundCoordinator($this->clients->create($intent->environment()), $this->store, $this);
    }

    private function orderId(RefundIntent $intent): int { return (int) explode(':', $intent->paymentKey())[1]; }

    private static function canonical(array $input): array
    {
        ksort($input);
        foreach ($input as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            } elseif (is_scalar($value)) {
                $value = (string) $value;
            } else {
                throw new \RuntimeException('refund_native_draft_invalid');
            }
        }
        return $input;
    }
}
