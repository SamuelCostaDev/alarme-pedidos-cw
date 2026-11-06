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

    // Validade do código de pareamento mostrado no painel
    'pairing_code_ttl_minutes' => 15,
];
