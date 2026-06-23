<?php

declare(strict_types=1);

namespace Paymos\Payment\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Sandbox / Live selector for the admin "Mode" field. The chosen mode decides
 * which environment block of the generated paymos-config.php is used.
 */
final class Mode implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'sandbox', 'label' => (string) __('Sandbox (test)')],
            ['value' => 'live', 'label' => (string) __('Live (production)')],
        ];
    }
}
