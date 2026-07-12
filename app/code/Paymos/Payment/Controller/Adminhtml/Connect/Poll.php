<?php

declare(strict_types=1);

namespace Paymos\Payment\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Paymos\Connect\DeviceConnectClient;
use Paymos\Payment\Service\CredentialStore;

final class Poll extends Action
{
    public const ADMIN_RESOURCE = 'Paymos_Payment::connect';
    private $jsonFactory;
    private $storeManager;
    private $credentialStore;

    public function __construct(Action\Context $context, JsonFactory $jsonFactory, StoreManagerInterface $storeManager, CredentialStore $credentialStore)
    {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->storeManager = $storeManager;
        $this->credentialStore = $credentialStore;
    }

    public function execute()
    {
        $json = $this->jsonFactory->create();
        try {
            $sourceUrl = rtrim((string) $this->storeManager->getDefaultStoreView()->getBaseUrl(), '/');
            $state = $this->credentialStore->loadState();
            if (!isset($state['device_code'])) {
                throw new \RuntimeException('No active Paymos connection request.');
            }
            $result = (new DeviceConnectClient('https://app.paymos.io'))->poll((string) $state['device_code']);
            if ($result['status'] === 'connected') {
                if ($result['plugin'] !== 'magento2' || rtrim((string) $result['source_url'], '/') !== $sourceUrl) {
                    throw new \RuntimeException('Paymos connection response does not match this Magento store.');
                }
                $this->credentialStore->saveCredentials($result['credentials']);
                $this->credentialStore->clearState();
                return $json->setData(['status' => 'connected']);
            }
            if (in_array($result['status'], ['authorization_pending', 'slow_down'], true)) {
                return $json->setData(['status' => $result['status']]);
            }
            $this->credentialStore->clearState();
            throw new \RuntimeException('Paymos connection was denied or expired.');
        } catch (\Throwable $exception) {
            return $json->setHttpResponseCode(400)->setData(['error' => $exception->getMessage()]);
        }
    }
}
