<?php

return [
    'device_host' => env('TRACCAR_DEVICE_HOST'),
    'client_url' => env('TRACCAR_CLIENT_URL'),
    'private_host' => env('TRACCAR_PRIVATE_HOST'),
    'port_overrides' => json_decode(env('TRACCAR_PORT_OVERRIDES', '{}'), true) ?: [],
    'url' => env('TRACCAR_URL', 'http://127.0.0.1:8082'),
    'username' => env('TRACCAR_USERNAME'),
    'password' => env('TRACCAR_PASSWORD'),
    'timeout' => (int) env('TRACCAR_TIMEOUT', 15),
    'connect_timeout' => (int) env('TRACCAR_CONNECT_TIMEOUT', 5),
];
