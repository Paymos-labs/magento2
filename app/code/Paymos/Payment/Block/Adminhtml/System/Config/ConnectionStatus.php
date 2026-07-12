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
 * encrypted credential set is present for the selected mode, the masked API
 * key, the project id and the webhook URL.
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
                __('Action') => $this->escapeHtml(__('Click Connect Paymos and approve this store in your Paymos dashboard.')),
                __('Webhook URL') => '<code>' . $this->escapeHtml($webhookUrl) . '</code>',
            ];
        }

        $startUrl = $this->getUrl('paymos/connect/start');
        $pollUrl = $this->getUrl('paymos/connect/poll');
        $html = '<div class="paymos-connection-status" style="line-height:1.8;">';
        foreach ($rows as $label => $value) {
            $html .= '<div><strong>' . $this->escapeHtml($label) . ':</strong> ' . $value . '</div>';
        }
        $html .= '<div style="margin-top:10px"><button type="button" class="action-primary" id="paymos-connect-button"><span>'
            . $this->escapeHtml(__('Connect Paymos')) . '</span></button> <span id="paymos-connect-message"></span></div></div>';
        $html .= '<script>(function(){var b=document.getElementById("paymos-connect-button"),m=document.getElementById("paymos-connect-message");if(!b)return;'
            . 'function p(u){return fetch(u,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:"form_key="+encodeURIComponent(window.FORM_KEY)}).then(function(r){return r.json();});}'
            . 'b.onclick=function(){b.disabled=true;m.textContent=" Starting…";p(' . json_encode($startUrl) . ').then(function(x){if(x.error)throw new Error(x.error);window.open(x.verification_url,"_blank","noopener,noreferrer");m.textContent=" Waiting for approval. Code: "+x.user_code;var i=Math.max(1,Number(x.interval||5))*1000;setTimeout(function q(){p(' . json_encode($pollUrl) . ').then(function(y){if(y.error)throw new Error(y.error);if(y.status==="connected"){location.reload();return;}setTimeout(q,y.status==="slow_down"?i+5000:i);}).catch(function(e){m.textContent=" "+e.message;b.disabled=false;});},i);}).catch(function(e){m.textContent=" "+e.message;b.disabled=false;});};})();</script>';

        return $html;
    }
}
