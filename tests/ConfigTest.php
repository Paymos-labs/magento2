<?php

declare(strict_types=1);

use Paymos\Payment\Service\Config;

function test_magento_config_reads_environments_from_array()
{
    $config = Config::fromArray(array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array(
                'base_url' => 'https://api.paymos.local',
                'api_key' => 'pk_test',
                'api_secret' => 'sk_test',
                'project_id' => 'prj_test',
                'webhook_secret' => 'whsec_test',
            ),
            'live' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_live',
                'api_secret' => 'sk_live',
                'project_id' => 'prj_live',
                'webhook_secret' => 'whsec_live',
            ),
        ),
    ));

    assertSameValue('pk_test', $config->environment('sandbox')->apiKey(), 'Sandbox api key must be read.');
    assertSameValue('prj_live', $config->environment('live')->projectId(), 'Live project id must be read.');
    assertSameValue('whsec_live', $config->webhookSecrets()['live'], 'Live webhook secret must be exposed.');
    assertSameValue('whsec_test', $config->webhookSecrets()['sandbox'], 'Sandbox webhook secret must be exposed.');
}

function test_magento_config_defaults_base_url_when_missing()
{
    $config = Config::fromArray(array(
        'environments' => array(
            'sandbox' => array(
                'api_key' => 'pk_test',
                'api_secret' => 'sk_test',
                'project_id' => 'prj_test',
                'webhook_secret' => 'whsec_test',
            ),
        ),
    ));

    assertSameValue('https://api.paymos.io', $config->environment('sandbox')->baseUrl(), 'Missing base_url must default to the public host.');
}

function test_magento_config_unknown_environment_resolves_to_sandbox()
{
    $config = Config::fromArray(paymos_m2_generated_config());

    assertSameValue('sandbox', $config->environment('nonsense')->name(), 'Unknown environment must resolve to sandbox.');
}

function test_magento_config_client_config_asserts_sandbox_credential_kind()
{
    $config = Config::fromArray(array(
        'environments' => array(
            'sandbox' => array(
                'api_key' => 'pk_live_wrong',
                'api_secret' => 'sk_live_wrong',
                'project_id' => 'prj_test',
                'webhook_secret' => 'whsec_test',
            ),
        ),
    ));

    $threw = false;
    try {
        $config->clientConfigForEnvironment('sandbox');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }

    assertTrueValue($threw, 'A live key under sandbox mode must be rejected.');
}

function test_magento_config_webhook_secrets_skip_empty()
{
    $config = Config::fromArray(array(
        'environments' => array(
            'sandbox' => array(
                'api_key' => 'pk_test',
                'api_secret' => 'sk_test',
                'project_id' => 'prj_test',
                'webhook_secret' => 'whsec_test',
            ),
            'live' => array(
                'api_key' => '',
                'api_secret' => '',
                'project_id' => '',
                'webhook_secret' => '',
            ),
        ),
    ));

    $secrets = $config->webhookSecrets();
    assertSameValue(1, count($secrets), 'Empty live secret must be skipped.');
    assertTrueValue(isset($secrets['sandbox']), 'Sandbox secret must remain.');
    assertFalseValue(isset($secrets['live']), 'Empty live secret must not appear.');
}

function test_magento_sdk_dependency_preserves_supported_retry_contract()
{
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/app/code/Paymos/Payment/composer.json'), true);
    // ^1.10.0 means >=1.10.0 <2.0.0: old SDKs lack PREFLIGHT_RETRY.
    assertSameValue('^1.10.0', $manifest['require']['paymos/php-sdk'], 'Require the first SDK with transient preflight retry, without admitting the next major.');
}

function test_magento_mirror_uses_verified_sdk_and_explicit_conformance()
{
    $workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/ci.yml');
    assertTrueValue(strpos($workflow, 'git clone --branch v1.10.0 --depth=1 https://github.com/paymos-labs/php-sdk') !== false, 'Mirror tests must exercise the published minimum SDK.');
    assertTrueValue(strpos($workflow, 'PAYMOS_CONFORMANCE_CONTRACT="$RUNNER_TEMP/php-sdk/conformance/contract.json"') !== false, 'Mirror tests must load the pinned SDK conformance contract explicitly.');
}
