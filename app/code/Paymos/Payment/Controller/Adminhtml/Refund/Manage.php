<?php

declare(strict_types=1);

namespace Paymos\Payment\Controller\Adminhtml\Refund;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Paymos\Payment\Plugin\RefundEntry;
use Paymos\Payment\Service\RefundAdapter;

class Manage extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Sales::creditmemo';
    private $json;
    private $entry;
    private $adapter;

    public function __construct(Action\Context $context, JsonFactory $json, RefundEntry $entry, RefundAdapter $adapter)
    {
        parent::__construct($context);
        $this->json = $json;
        $this->entry = $entry;
        $this->adapter = $adapter;
    }

    public function execute()
    {
        $result = $this->json->create();
        try {
            $this->entry->assertProtected();
            $input = $this->getRequest()->getPostValue();
            if (array_diff(array_keys($input), ['form_key', 'order_id', 'action', 'request_token'])
                || !isset($input['order_id'], $input['action']) || !is_scalar($input['order_id'])
                || !preg_match('/^[1-9][0-9]*$/D', (string) $input['order_id'])) {
                throw new \RuntimeException('refund_mode_and_token_only');
            }
            $orderId = (int) $input['order_id'];
            if ($input['action'] === 'panel') {
                return $result->setData($this->adapter->panel($orderId));
            }
            if (!isset($input['request_token']) || !is_string($input['request_token'])) {
                throw new \RuntimeException('refund_request_token_required');
            }
            if ($input['action'] === 'overpayment') {
                $intent = $this->adapter->submit($orderId, 'overpayment', $input['request_token']);
            } elseif ($input['action'] === 'resume') {
                $intent = $this->adapter->resume($orderId, $input['request_token']);
            } else {
                throw new \RuntimeException('refund_action_unsupported');
            }
            return $result->setData(['message' => 'Current refund status is below.', 'operation' => $this->adapter->publicState($intent)]);
        } catch (\Throwable $error) {
            return $result->setHttpResponseCode(400)->setData(['error' => 'The request failed. Check that the order ID matches the order page URL and that the order was paid with Paymos, then reload the page and try again.']);
        }
    }
}
