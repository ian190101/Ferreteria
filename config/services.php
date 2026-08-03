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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'siat' => [
        'pilot_base' => env('SIAT_PILOT_BASE', 'https://pilotosiatservicios.impuestos.gob.bo/v2'),
        'production_base' => env('SIAT_PRODUCTION_BASE', 'https://siatservicios.impuestos.gob.bo/v2'),
        'pilot_qr_base' => env('SIAT_PILOT_QR_BASE', 'https://pilotosiat.impuestos.gob.bo/consulta/QR'),
        'production_qr_base' => env('SIAT_PRODUCTION_QR_BASE', 'https://siat.impuestos.gob.bo/consulta/QR'),
    ],

    'openstreetmap' => [
        'tiles_url' => env('OSM_TILES_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'nominatim_url' => env('OSM_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'osrm_url' => env('OSM_OSRM_URL', 'https://router.project-osrm.org'),
        'user_agent' => env('OSM_USER_AGENT', env('APP_NAME', 'ERP POS').'/1.0 (contacto: soporte local)'),
        'countrycodes' => env('OSM_NOMINATIM_COUNTRYCODES', 'bo'),
        'route_cache_days' => (int) env('OSM_ROUTE_CACHE_DAYS', 30),
        'geocode_cache_days' => (int) env('OSM_GEOCODE_CACHE_DAYS', 7),
    ],

];
