<?php

// Example of the dashboard-generated paymos-config.php. The real file is injected
// into the module ZIP by the Paymos dashboard and is read-only — the merchant
// never edits it. It sits at the module root (next to registration.php) and is
// loaded by Service\GeneratedConfigProvider.
//
// Shape matches the generator: config_version + per-environment base_url. The
// sandbox/live mode is an admin setting (Stores > Configuration > Payment
// Methods > Paymos > Mode), NOT part of this file.

return array(
    'config_version' => 2,
    'environments' => array(
        'sandbox' => array(
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_test_xxxxxxxxxxxx',
            'api_secret' => 'sk_test_xxxxxxxxxxxx',
            'project_id' => 'prj_xxxxxxxxxxxx',
            'webhook_secret' => 'whsec_xxxxxxxxxxxx',
        ),
        'live' => array(
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_live_xxxxxxxxxxxx',
            'api_secret' => 'sk_live_xxxxxxxxxxxx',
            'project_id' => 'prj_xxxxxxxxxxxx',
            'webhook_secret' => 'whsec_xxxxxxxxxxxx',
        ),
    ),
);
