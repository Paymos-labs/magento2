<?php

declare(strict_types=1);

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Url\Url;
use Paymos\Payment\Block\Adminhtml\System\Config\ConnectionStatus;
use Paymos\Payment\Service\GeneratedConfigProvider;
use Paymos\Payment\Tests\FakeCredentialStore;

/**
 * The diagnostics row must render. Regression guard for the June–August 2026
 * fatal: array keys built from __() are Phrase objects, and an object cannot be
 * an array key — the whole payment config page 500ed before every merchant's
 * eyes since 48fb60d72.
 */
function paymos_m2_status_block()
{
    return new ConnectionStatus(
        new Context(new Url('https://store.example/')),
        new GeneratedConfigProvider(new FakeCredentialStore()),
        paymos_m2_settings(array('payment/paymos/mode' => 'sandbox'))
    );
}

function test_magento_connection_status_renders_rows()
{
    $block = paymos_m2_status_block();
    $method = new ReflectionMethod($block, '_getElementHtml');

    $html = $method->invoke($block, new AbstractElement());

    assertSameValue(4, substr_count($html, '<strong>'), 'The four diagnostics rows must render as labelled rows.');
    assertTrueValue(strpos($html, 'Status') !== false, 'The status row label must be present.');
    assertTrueValue(strpos($html, 'paymos-connect-button') !== false, 'The Connect button must be present.');
    assertTrueValue(strpos($html, 'Webhook URL') !== false, 'The webhook URL row must be present.');
}

function test_magento_connection_status_connected_state_shows_key()
{
    $block = paymos_m2_status_block();
    $method = new ReflectionMethod($block, '_getElementHtml');

    $html = $method->invoke($block, new AbstractElement());

    assertTrueValue(strpos($html, 'Connected') !== false, 'A configured environment must render the connected state.');
    assertTrueValue(strpos($html, 'prj_test123') !== false, 'The project id must be rendered.');
}
