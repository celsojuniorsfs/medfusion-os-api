<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // Decidido em docs/ambientes.md: token Bearer, sem cookies cross-site — por isso
    // supports_credentials fica false e não há necessidade de SANCTUM_STATEFUL_DOMAINS.
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:4200'),
    ],

    // Laravel não faz wildcard em allowed_origins — os previews da Vercel
    // (medfusion-os-web-*.vercel.app) precisam do padrão de regex abaixo. Restrito ao prefixo do
    // nome do projeto ("medfusion-os-web"), não `.*\.vercel\.app` genérico (que liberaria
    // QUALQUER app hospedado na Vercel, não só os previews deste — ver ambientes.md § CORS). É
    // como a Vercel nomeia tanto o alias de produção (`medfusion-os-web.vercel.app`) quanto cada
    // preview (`medfusion-os-web-<hash-ou-branch>-<time>.vercel.app`).
    'allowed_origins_patterns' => [
        '#^https://medfusion-os-web(-[a-z0-9-]+)?\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
