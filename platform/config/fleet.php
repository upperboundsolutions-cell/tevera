<?php

return [
    'fuel_sensor_unit' => env('FLEET_FUEL_SENSOR_UNIT', 'unknown'),
    'fuel_drop_litres' => (float) env('FLEET_FUEL_DROP_LITRES', 10),
    'whatsapp' => [
        'enabled' => env('WHATSAPP_ENABLED', false),
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_WHATSAPP_FROM'),
        'content_sid' => env('TWILIO_WHATSAPP_CONTENT_SID'),
    ],
];
