<?php

declare(strict_types=1);

namespace Paymos\Payment\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;

/**
 * Authorize command for the Paymos hosted-redirect gateway.
 *
 * No money is collected inside Magento here: the actual charge happens on the
 * Paymos hosted checkout after the redirect. This command only flags the
 * payment as pending (an open authorization) and leaves the transaction open so
 * the signed webhook can later invoice the order. It deliberately does NOT
 * create the Paymos invoice — that is the redirect controller's job, where the
 * order increment id (the external order id) is already assigned.
 *
 * @see https://developer.adobe.com/commerce/php/development/payments-integrations/payment-gateway/
 */
final class AuthorizeCommand implements CommandInterface
{
    /**
     * @param array<string, mixed> $commandSubject
     * @return void
     */
    public function execute(array $commandSubject)
    {
        $paymentDataObject = SubjectReader::readPayment($commandSubject);
        $payment = $paymentDataObject->getPayment();

        if ($payment instanceof Payment) {
            // Keep the authorization open: the order stays pending payment until
            // the Paymos webhook confirms it and triggers the Magento invoice.
            $payment->setIsTransactionPending(true);
            $payment->setIsTransactionClosed(false);
        }
    }
}
