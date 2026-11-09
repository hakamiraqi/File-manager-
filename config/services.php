<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    // Text recognition: leave empty to use the bundled copy or the one on PATH.
    'ocr' => [
        'tesseract' => env('OCR_TESSERACT_BIN'),
        'pdftoppm' => env('OCR_PDFTOPPM_BIN'),
    ],

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

    // Auto Multiple Choice connector (amc-connector/): beside the app on the hosted server, or on a
    // hall's Linux server (its address and token can then be set in the app instead).
    'amc' => [
        'url' => env('AMC_URL'),
        'token' => env('AMC_TOKEN'),
    ],

];
