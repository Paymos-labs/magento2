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
class ConnectionStatus extends Field
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
            // Keys must be strings: __() returns a Phrase object, and an object
            // cannot be an array key — the page would fatal with an illegal
            // offset type before the merchant ever sees the Connect button.
            $rows = [
                (string) __('Status') => '<span style="color:#1e7e34;font-weight:600;">' . $this->escapeHtml(__('Connected (%1)', $mode)) . '</span>',
                (string) __('API key') => '<code>' . $this->escapeHtml($env->maskedApiKey()) . '</code>',
                (string) __('Project') => '<code>' . $this->escapeHtml($env->projectId()) . '</code>',
                (string) __('Webhook URL') => '<code>' . $this->escapeHtml($webhookUrl) . '</code>',
            ];
        } else {
            $rows = [
                (string) __('Status') => '<span style="color:#b30000;font-weight:600;">' . $this->escapeHtml(__('Not configured')) . '</span>',
                (string) __('Action') => $this->escapeHtml(__('Click Connect Paymos and approve this store in your Paymos dashboard.')),
                (string) __('Webhook URL') => '<code>' . $this->escapeHtml($webhookUrl) . '</code>',
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
        $html .= '<script>(function(){var b=document.getElementById("paymos-connect-button"),m=document.getElementById("paymos-connect-message"),manual=false;if(!b)return;'
            . 'function p(u,r){var d="form_key="+encodeURIComponent(window.FORM_KEY);if(r)d+="&paymos_return_url="+encodeURIComponent(r);return fetch(u,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d}).then(function(r2){return r2.json();});}'
            /* The tab is opened synchronously inside the click: browsers only honour
               window.open for a few seconds after the gesture, so opening it once the
               start request resolves is blocked on slow connections. No feature string —
               any feature string asks for a popup, which blockers reject far more often. */
            . 'b.onclick=function(){b.disabled=true;manual=false;m.textContent=" Starting…";'
            . 'var t=window.open("","_blank");if(t){try{t.opener=null;}catch(e){}}'
            . 'p(' . json_encode($startUrl) . ',location.href).then(function(x){if(x.error)throw new Error(x.error);'
            . 'if(t&&!t.closed){t.location=x.verification_url;m.textContent=" Waiting for approval. Code: "+x.user_code;}'
            . 'else{manual=true;m.textContent="";var a=document.createElement("a");a.href=x.verification_url;a.target="_blank";a.rel="noopener noreferrer";a.textContent="Open the approval page";'
            . 'm.appendChild(document.createTextNode(" Your browser blocked the approval tab. "));m.appendChild(a);m.appendChild(document.createTextNode(" Code: "+x.user_code));}'
            . 'var i=Math.max(1,Number(x.interval||5))*1000;setTimeout(function q(){p(' . json_encode($pollUrl) . ').then(function(y){if(y.error)throw new Error(y.error);if(y.status==="connected"){location.reload();return;}setTimeout(q,y.status==="slow_down"?i+5000:i);}).catch(function(e){if(!manual)m.textContent=" "+e.message;b.disabled=false;});},i);})'
            . '.catch(function(e){if(t&&!t.closed)t.close();m.textContent=" "+e.message;b.disabled=false;});};})();</script>';

        $refundUrl = $this->getUrl('paymos/refund/manage');
        $operationLabels = json_encode([
            'full' => (string) __('Check full refund'),
            'overpayment' => (string) __('Check overpayment refund'),
        ]);
        $html .= '<fieldset id="paymos-refunds"><legend>' . $this->escapeHtml(__('Paymos refunds')) . '</legend>'
            . '<label for="paymos-refund-order">' . $this->escapeHtml(__('Order ID from the order page URL')) . '</label> '
            . '<input id="paymos-refund-order" type="number" min="1" />'
            . '<button type="button" id="paymos-refund-panel">' . $this->escapeHtml(__('Load order')) . '</button>'
            . '<button type="button" id="paymos-refund-over" disabled>' . $this->escapeHtml(__('Refund overpayment')) . '</button>'
            . '<p><output id="paymos-refund-order-summary" aria-live="polite"></output></p>'
            . '<pre id="paymos-refund-state"></pre><div id="paymos-refund-operations"></div></fieldset>';
        $html .= '<script>(function(){var order=document.getElementById("paymos-refund-order"),'
            . 'load=document.getElementById("paymos-refund-panel"),over=document.getElementById("paymos-refund-over"),'
            . 'state=document.getElementById("paymos-refund-state"),ops=document.getElementById("paymos-refund-operations"),'
            . 'identity=document.getElementById("paymos-refund-order-summary"),token="",loaded=null,generation=0,busy=false,buttons=[],labels=' . $operationLabels . ';'
            . 'function matches(){return loaded&&String(loaded.order_id)===order.value;}'
            . 'function sync(){over.disabled=busy||!matches()||!token||!loaded.can_overpayment;buttons.forEach(function(b){b.disabled=busy||!matches();});}'
            . 'function invalidate(){generation++;loaded=null;token="";over.disabled=true;ops.textContent="";buttons=[];identity.textContent="";state.textContent="";}'
            . 'function post(action,key){if(busy)return;var id;if(action==="panel"){invalidate();id=order.value;}'
            . 'else{if(!matches()){invalidate();return;}id=String(loaded.order_id);token="";}'
            . 'if(!/^[1-9][0-9]*$/.test(id))return;var version=generation;busy=true;sync();load.disabled=true;var d=new URLSearchParams();'
            . 'd.set("form_key",window.FORM_KEY);d.set("order_id",id);d.set("action",action);if(key)d.set("request_token",key);'
            . 'return fetch(' . json_encode($refundUrl) . ',{method:"POST",credentials:"same-origin",body:d}).then(function(r){return r.json();})'
            . '.then(function(x){if(version!==generation||order.value!==id)return;state.textContent=JSON.stringify(x,null,2);if(x.error)return;'
            . 'if(action==="panel"){if(String(x.order_id)!==id||!x.order_increment_id||!x.order_amount||!x.order_currency)return;'
            . 'loaded=x;token=x.request_token;identity.textContent="#"+x.order_increment_id+" · "+x.order_amount+" "+x.order_currency;'
            . '(x.operations||[]).forEach(function(o){var b=document.createElement("button");b.type="button";b.textContent=(labels[o.mode]||o.mode)+" "+o.operation_id;'
            . 'b.onclick=function(){post("resume",o.request_token);};buttons.push(b);ops.appendChild(b);});}})'
            . '.catch(function(){if(version===generation&&order.value===id)state.textContent=' . json_encode((string) __('The request did not finish. Reload the page and load the order again to check the refund status.')) . ';}).then(function(){busy=false;load.disabled=false;sync();});}'
            . 'load.onclick=function(){post("panel");};over.onclick=function(){post("overpayment",token);};'
            . 'order.oninput=invalidate;order.onchange=invalidate;'
            . 'var selected=new URLSearchParams(location.search).get("paymos_order_id");if(selected&&/^[1-9][0-9]*$/.test(selected)){order.value=selected;post("panel");}})();</script>';
        return $html;
    }
}
