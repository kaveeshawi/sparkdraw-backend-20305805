<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo OAuth (tests only — never pretend to be real authorize in the UI)
    |--------------------------------------------------------------------------
    | Keep false so "Connect with Google" always means a real provider redirect.
    | PHPUnit may enable this explicitly for isolated demo-flow coverage.
    */
    'demo_oauth' => (bool) env('INTEGRATIONS_DEMO_OAUTH', false),

    'oauth' => [

        'google_meet' => [
            'label'         => 'Google',
            'client_id'     => env('GOOGLE_OAUTH_CLIENT_ID', ''),
            'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET', ''),
            'auth_url'      => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url'     => 'https://oauth2.googleapis.com/token',
            'scopes'        => [
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/calendar.events',
            ],
            'extra_auth'    => [
                'access_type' => 'offline',
                'prompt'      => 'consent',
            ],
        ],

        'google_drive' => [
            'label'         => 'Google',
            'client_id'     => env('GOOGLE_OAUTH_CLIENT_ID', ''),
            'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET', ''),
            'auth_url'      => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url'     => 'https://oauth2.googleapis.com/token',
            'scopes'        => [
                'openid',
                'email',
                'https://www.googleapis.com/auth/drive.file',
            ],
            'extra_auth'    => [
                'access_type' => 'offline',
                'prompt'      => 'consent',
            ],
        ],

        'microsoft_teams' => [
            'label'         => 'Microsoft',
            'client_id'     => env('MICROSOFT_OAUTH_CLIENT_ID', ''),
            'client_secret' => env('MICROSOFT_OAUTH_CLIENT_SECRET', ''),
            'auth_url'      => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url'     => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'scopes'        => [
                'openid',
                'profile',
                'email',
                'offline_access',
                'OnlineMeetings.ReadWrite',
            ],
            'extra_auth'    => [
                'response_mode' => 'query',
            ],
        ],

        'zoom' => [
            'label'         => 'Zoom',
            'client_id'     => env('ZOOM_OAUTH_CLIENT_ID', ''),
            'client_secret' => env('ZOOM_OAUTH_CLIENT_SECRET', ''),
            'auth_url'      => 'https://zoom.us/oauth/authorize',
            'token_url'     => 'https://zoom.us/oauth/token',
            'scopes'        => [],
            'extra_auth'    => [],
        ],

        'stripe' => [
            'label'         => 'Stripe',
            'client_id'     => env('STRIPE_CONNECT_CLIENT_ID', ''),
            'client_secret' => env('STRIPE_SECRET_KEY', ''),
            'auth_url'      => 'https://connect.stripe.com/oauth/authorize',
            'token_url'     => 'https://connect.stripe.com/oauth/token',
            'scopes'        => ['read_write'],
            'extra_auth'    => [
                'response_type' => 'code',
            ],
        ],

        'paypal' => [
            'label'         => 'PayPal',
            'client_id'     => env('PAYPAL_OAUTH_CLIENT_ID', ''),
            'client_secret' => env('PAYPAL_OAUTH_CLIENT_SECRET', ''),
            'auth_url'      => env('PAYPAL_MODE', 'sandbox') === 'live'
                ? 'https://www.paypal.com/signin/authorize'
                : 'https://www.sandbox.paypal.com/signin/authorize',
            'token_url'     => env('PAYPAL_MODE', 'sandbox') === 'live'
                ? 'https://api-m.paypal.com/v1/oauth2/token'
                : 'https://api-m.sandbox.paypal.com/v1/oauth2/token',
            'scopes'        => ['openid', 'profile', 'email'],
            'extra_auth'    => [],
        ],

        'slack' => [
            'label'         => 'Slack',
            'client_id'     => env('SLACK_OAUTH_CLIENT_ID', ''),
            'client_secret' => env('SLACK_OAUTH_CLIENT_SECRET', ''),
            'auth_url'      => 'https://slack.com/oauth/v2/authorize',
            'token_url'     => 'https://slack.com/api/oauth.v2.access',
            'scopes'        => ['incoming-webhook', 'chat:write'],
            'extra_auth'    => [],
            'scope_param'   => 'scope',
        ],
    ],
];
