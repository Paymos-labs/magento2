<?php

declare(strict_types=1);

namespace Paymos\Payment\Plugin;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Paymos\Payment\Service\RefundAdapter;
use Paymos\Payment\Service\RefundIntentStore;
use Paymos\Payment\Service\RefundNativeGateway;
use Paymos\Payment\Service\Logger;

class RefundEntry
{
    private $request;
    private $authorization;
    private $formKey;
    private $redirects;
    private $adapter;
    private $native;
    private $messages;
    private $logger;

    public function __construct(RequestInterface $request, AuthorizationInterface $authorization, Validator $formKey,
        RedirectFactory $redirects, RefundAdapter $adapter, RefundNativeGateway $native, ManagerInterface $messages, Logger $logger)
    {
        $this->request = $request;
        $this->authorization = $authorization;
        $this->formKey = $formKey;
        $this->redirects = $redirects;
        $this->adapter = $adapter;
        $this->native = $native;
        $this->messages = $messages;
        $this->logger = $logger;
    }

    public function aroundExecute($subject, callable $proceed)
    {
        $invoice = $this->request->getParam('invoice_id');
        $invoiceId = $invoice ? (int) $invoice : null;
        $orderId = $this->native->entryOrderId((int) $this->request->getParam('order_id'), $invoiceId);
        if (!$this->native->isPaymos($orderId)) {
            return $proceed();
        }
        try {
            $this->assertProtected();
            $input = $this->request->getPost('creditmemo');
            if (!is_array($input) || $this->request->getParam('creditmemo_id')) {
                throw new \RuntimeException('refund_native_new_draft_required');
            }
            $this->adapter->submit($orderId, 'full', (string) $this->request->getParam('paymos_refund_token'), $input, $invoiceId);
        } catch (\Throwable $error) {
            $this->logger->warning('Paymos credit memo refused', ['order_id' => $orderId, 'reason' => $error->getMessage()]);
            $reconnect = in_array($error->getMessage(), ['refund_reconnect_required', 'refunds_not_enabled'], true);
            $this->messages->addErrorMessage($reconnect
                ? __('Refunds through Paymos are not available for this connection right now. Click Connect Paymos below to reconnect, then create the credit memo again.')
                : __('Magento did not save this credit memo. To go through Paymos, the credit memo must cover the full order, with no adjustments and no comment. Refunds already started for this order are listed under Paymos refunds below.'));
            // Pending and refusal both return to the Paymos panel; native Save never runs.
            return $this->redirects->create()->setPath('adminhtml/system_config/edit',
                ['section' => 'payment', 'paymos_order_id' => $orderId]);
        }
        $this->messages->addNoticeMessage(__('Full refund requested through Paymos. Magento saves the credit memo only after the refund completes. See its status under Paymos refunds below.'));
        return $this->redirects->create()->setPath('adminhtml/system_config/edit',
            ['section' => 'payment', 'paymos_order_id' => $orderId]);
    }

    public function afterToHtml($subject, string $html): string
    {
        $invoice = $this->request->getParam('invoice_id');
        $orderId = $this->native->entryOrderId((int) $this->request->getParam('order_id'), $invoice ? (int) $invoice : null);
        if (!$this->native->isPaymos($orderId)) {
            return $html;
        }
        return $html . '<input type="hidden" name="paymos_refund_token" value="' . RefundIntentStore::uuid() . '" />';
    }

    public function assertProtected(): void
    {
        if (!$this->request->isPost() || !$this->authorization->isAllowed('Magento_Sales::creditmemo')
            || !$this->formKey->validate($this->request)) {
            throw new \RuntimeException('refund_access_denied');
        }
    }
}
