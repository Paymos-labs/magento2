<?php

declare(strict_types=1);

namespace Paymos\Payment\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Paymos\Payment\Service\GeneratedConfigProvider;
use Paymos\Payment\Service\Settings;

/**
 * Read-only diagnostics row in the admin payment config: shows whether the
 * dashboard credential package is present for the selected mode, the masked API
 * key, the project id and the webhook URL. Merchants never type secrets here —
 * this only reflects what the generated paymos-config.php contains.
 */
final class ConnectionStatus extends Field
{
    /** @var GeneratedConfigProvider */
    private $configProvider;

    /** @var Settings */
    private $settings;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        GeneratedConfigProvider $configProvider,
        Settings $settings,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->configProvider = $configProvider;
        $this->settings = $settings;
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $config = $this->configProvider->get();
        $mode = $this->settings->mode();
        $env = $config->environment($mode);

        $webhookUrl = rtrim($this->_urlBuilder->getBaseUrl(), '/') . '/paymos/payment/callback';

        if ($env->isConfigured()) {
            $rows = [
                __('Status') => '<span style="color:#1e7e34;font-weight:600;">' . $this->escapeHtml(__('Connected (%1)', $mode)) . '</span>',
                __('API key') => '<code>' . $this->escapeHtml($env->maskedApiKey()) . '</code>',
                __('Project') => '<code>' . $this->escapeHtml($env->projectId()) . '</code>',
                __('Webhook URL') => '<code>' . $this->escapeHtml($webhookUrl) . '</code>',
            ];
        } else {
            $rows = [
                __('Status') => '<span style="color:#b30000;font-weight:600;">' . $this->escapeHtml(__('Not configured')) . '</span>',
                __('Action') => $this->escapeHtml(__('Generate the configuration package in your Paymos dashboard and upload it with the module.')),
                __('Webhook URL') => '<code>' . $this->escapeHtml($webhookUrl) . '</code>',
            ];
        }

        $html = '<div class="paymos-connection-status" style="line-height:1.8;">';
        foreach ($rows as $label => $value) {
            $html .= '<div><strong>' . $this->escapeHtml($label) . ':</strong> ' . $value . '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}
