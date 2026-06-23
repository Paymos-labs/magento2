<?php

declare(strict_types=1);

namespace Paymos\Payment\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory;

/**
 * Order statuses that belong to the Processing state, offered for the "Paid
 * order status" admin field. A paid Paymos order is invoiced and moved to the
 * Processing state, so only Processing-state statuses are valid targets.
 */
final class ProcessingStatus implements OptionSourceInterface
{
    /** @var CollectionFactory */
    private $statusCollectionFactory;

    public function __construct(CollectionFactory $statusCollectionFactory)
    {
        $this->statusCollectionFactory = $statusCollectionFactory;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        $statuses = $this->statusCollectionFactory->create()->joinStates();

        $options = [];
        foreach ($statuses as $status) {
            if ($status->getState() === Order::STATE_PROCESSING) {
                $options[] = [
                    'value' => (string) $status->getStatus(),
                    'label' => (string) $status->getLabel(),
                ];
            }
        }

        if ($options === []) {
            $options[] = ['value' => 'processing', 'label' => (string) __('Processing')];
        }

        return $options;
    }
}
