<?php

declare(strict_types=1);

namespace Paymos\Payment\Cron;

use Paymos\Payment\Service\GeneratedConfigProvider;
use Paymos\Payment\Service\InvoiceSnapshotRepository;
use Paymos\Payment\Service\MagentoOrderGateway;
use Paymos\Payment\Service\OrderMapper;
use Paymos\Payment\Service\PaymosClientFactory;
use Paymos\Payment\Service\Reconciler;
use Paymos\Payment\Service\Settings;

/**
 * Cron entry point that reconciles recent non-terminal Paymos invoices against
 * the API (catches any webhook that never arrived). Wired in etc/crontab.xml.
 * No-op when no credentials are configured.
 */
final class ReconcileInvoices
{
    /** @var GeneratedConfigProvider */
    private $configProvider;

    /** @var InvoiceSnapshotRepository */
    private $snapshots;

    /** @var MagentoOrderGateway */
    private $orderGateway;

    /** @var PaymosClientFactory */
    private $clientFactory;

    /** @var Settings */
    private $settings;

    public function __construct(
        GeneratedConfigProvider $configProvider,
        InvoiceSnapshotRepository $snapshots,
        MagentoOrderGateway $orderGateway,
        PaymosClientFactory $clientFactory,
        Settings $settings
    ) {
        $this->configProvider = $configProvider;
        $this->snapshots = $snapshots;
        $this->orderGateway = $orderGateway;
        $this->clientFactory = $clientFactory;
        $this->settings = $settings;
    }

    public function execute(): void
    {
        $config = $this->configProvider->get();
        if ($config->webhookSecrets() === []) {
            return;
        }

        $reconciler = new Reconciler(
            $config,
            $this->snapshots,
            new OrderMapper($this->orderGateway),
            $this->settings,
            $this->clientFactory->asCallable()
        );

        $reconciler->run();
    }
}
