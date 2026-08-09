<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'razorpay' => [
        'key' => env('RAZORPAY_KEY_ID'),
        'secret' => env('RAZORPAY_KEY_SECRET'),
    ],

'delhivery' => [
    'base_url' => env('DELHIVERY_BASE_URL'),
    'token' => env('DELHIVERY_TOKEN'),
    'pickup_location' => env('DELHIVERY_PICKUP_LOCATION'),
    'client_name' => env('DELHIVERY_CLIENT_NAME'),
    'seller_name' => env('DELHIVERY_CLIENT_NAME'),
    'seller_address' => env('DELHIVERY_SELLER_ADDRESS'),
    'seller_gst_tin' => env('DELHIVERY_SELLER_GST_TIN'),
],


'whatsapp' => [
    'api_url' => env('WHATSAPP_API_URL'),
    'bearer_token' => env('WHATSAPP_BEARER_TOKEN'),
    'api_key' => env('WHATSAPP_API_KEY'),
    'phone_no_id' => env('WHATSAPP_PHONE_NO_ID'),
],

];
