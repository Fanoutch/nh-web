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

    'pipeline' => [
        'path' => env('PIPELINE_PATH', base_path('..')),
    ],

    // Script Python bmn/daily_report.py : génération de la dispo (Excel) à partir d'un CSV.
    'excel_pipeline' => [
        'path' => env('EXCEL_PIPELINE_PATH', base_path('bmn')),
        'python' => env('EXCEL_PIPELINE_PYTHON', 'python3'),
        // Rétention des dispos générées (jours). 0 = pas de purge.
        'retention_days' => (int) env('EXCEL_REPORTS_RETENTION_DAYS', 30),
    ],

];
