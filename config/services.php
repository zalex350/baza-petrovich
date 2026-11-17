<?php

return [

    /*
    | Уведомления о заказах и заявках в Telegram (App\Services\Integrations\Telegram): бот
    | (@BotFather → токен) и чат (группа менеджеров; id — через getUpdates). Пусто — не отправляется.
    */
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    /*
    | Битрикс24 (App\Services\Integrations\Bitrix24): входящий вебхук с правом «CRM»
    | (https://портал.bitrix24.by/rest/1/код/). Заказы и заявки — лиды с товарами. Пусто — не отправляется.
    */
    'bitrix24' => [
        'webhook_url' => env('BITRIX24_WEBHOOK_URL'),
        'source_id' => env('BITRIX24_SOURCE_ID', 'WEB'),
        'assigned_by_id' => env('BITRIX24_ASSIGNED_BY_ID'),
    ],

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

];
