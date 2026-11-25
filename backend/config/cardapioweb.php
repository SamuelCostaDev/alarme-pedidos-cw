<?php

$env = env('CARDAPIOWEB_ENV', 'sandbox');
$sandbox = $env !== 'production';

return [
    'env' => $env,

    // API (token, identity, pedidos)
    'base_url' => $sandbox
        ? 'https://integracao.sandbox.cardapioweb.com'
        : 'https://integracao.cardapioweb.com',

    // Página de autorização do portal (consentimento do proprietário)
    'authorize_url' => $sandbox
        ? 'https://portal.sandbox.cardapioweb.com/cw-apps'
        : 'https://portal.cardapioweb.com/cw-apps',

    'client_id' => env('CARDAPIOWEB_CLIENT_ID'),
    'redirect_uri' => env('CARDAPIOWEB_REDIRECT_URI'),
    'webhook_token' => env('CARDAPIOWEB_WEBHOOK_TOKEN'),

    // Status de ORDER_CREATED que NÃO tocam alarme.
    // Mesas/comandas só geram ORDER_CREATED quando já fechadas ou canceladas.
    'ignored_statuses' => ['closed', 'canceled', 'canceling', 'delivered'],

    'pending_statuses' => ['waiting_confirmation'],
    'scheduled_statuses' => ['scheduled_confirmed'], // confirmar no sandbox da CW
    'pending_reminder_after_minutes' => (int) env('PENDING_REMINDER_AFTER_MINUTES', 3),
    'pending_reminder_every_minutes' => (int) env('PENDING_REMINDER_EVERY_MINUTES', 3),
    'pending_reminder_max' => (int) env('PENDING_REMINDER_MAX', 5),

    // Validade do código de pareamento mostrado no painel
    'pairing_code_ttl_minutes' => 15,
];
