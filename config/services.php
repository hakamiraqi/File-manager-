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
        // Folder with the more accurate («best») Arabic model; used when «الدقة» is «عالية».
        'tessdata_best' => env('OCR_TESSDATA_BEST', '/usr/share/tessdata-best'),
        'qpdf' => env('OCR_QPDF'),
        'magick' => env('OCR_MAGICK'),
    ],

    // The office editor (ONLYOFFICE Docs). Off while no secret is set.
    // Ghostscript for «ضغط PDF» (found on PATH or next to the offline app when not set).
    'pdf' => ['gs' => env('PDF_GHOSTSCRIPT')],

    'onlyoffice' => [
        'secret' => env('ONLYOFFICE_JWT_SECRET'),
        // Where browsers load the editor from (a path on this site, or a full URL).
        'public_url' => env('ONLYOFFICE_PUBLIC_URL', '/office'),
        // How the editor reaches this site for the file and the save callback, and how this
        // site reaches the editor to fetch the saved file (container names on the server).
        'app_internal_url' => env('ONLYOFFICE_APP_INTERNAL_URL', 'http://filenest-web'),
        'internal_url' => env('ONLYOFFICE_INTERNAL_URL', 'http://onlyoffice'),
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

];
