<?php

return [
    'google' => [
        'application_name' => env('IAP_GOOGLE_APP_NAME'),
        'service_account_json' => env('IAP_GOOGLE_SERVICE_ACCOUNT_JSON'),
        'package_name' => env('IAP_GOOGLE_PACKAGE_NAME'),
    ],
    'apple' => [
        'bundle_id' => env('IAP_APPLE_BUNDLE_ID'),
        'issuer_id' => env('IAP_APPLE_ISSUER_ID'),
        'key_id' => env('IAP_APPLE_KEY_ID'),
        'private_key_path' => env('IAP_APPLE_PRIVATE_KEY_PATH'),
        'environment' => env('IAP_APPLE_ENVIRONMENT', 'sandbox'),
    ],
];
