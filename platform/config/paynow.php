<?php

return [
    'enabled' => env('PAYNOW_ENABLED', false),
    'integration_id' => env('PAYNOW_INTEGRATION_ID'),
    'integration_key' => env('PAYNOW_INTEGRATION_KEY'),
    'currency' => env('PAYNOW_CURRENCY'),
    'public_url' => env('PAYNOW_PUBLIC_URL'),
    'merchant' => 'Upper Bound Solutions PVT',
];
