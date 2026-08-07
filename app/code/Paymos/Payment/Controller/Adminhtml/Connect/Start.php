<?php

declare(strict_types=1);

namespace Paymos\Payment\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Paymos\Connect\DeviceConnectClient;
use Paymos\Payment\Service\CredentialStore;

final class Start extends Action
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
            if (stripos($sourceUrl, 'https://') !== 0) {
                throw new \RuntimeException('Magento base URL must use HTTPS.');
            }
            // The admin page posts its own URL so approval can return the merchant to it.
            // Paymos drops it unless it shares an origin with the base URL above.
            $returnUrl = (string) $this->getRequest()->getPost('paymos_return_url', '');
            $state = (new DeviceConnectClient('https://app.paymos.io'))->start('magento2', $sourceUrl, $returnUrl);
            $this->credentialStore->saveState($state);
            return $json->setData([
                'verification_url' => $state['verification_url'],
                'user_code' => $state['user_code'],
                'interval' => $state['interval'],
            ]);
        } catch (\Throwable $exception) {
            return $json->setHttpResponseCode(400)->setData(['error' => $exception->getMessage()]);
        }
    }
}
