<?php

return [
    'client_id'     => env('PAYPAL_CLIENT_ID', ''),
    'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
    'mode'          => env('PAYPAL_MODE', 'sandbox'),
    'base_url'      => env('PAYPAL_MODE', 'sandbox') === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com',
    'return_url'    => env('FRONTEND_URL', 'http://localhost:5173') . '/invoices',
    'cancel_url'    => env('FRONTEND_URL', 'http://localhost:5173') . '/invoices',
];
