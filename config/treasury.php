<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Exchange rates
    |--------------------------------------------------------------------------
    |
    | Official, public sources (no credentials): BCV through DolarAPI (bolívares per dollar and per
    | euro, field "promedio") and Colombia's TRM through datos.gov.co (pesos per dollar, field
    | "valor"). The date of a rate is Venezuela's. The ranges discard absurd responses. Same
    | sources and rules as safipuv-v4.
    |
    */
    'rates' => [
        'timezone' => 'America/Caracas',
        'timeout' => (int) env('TREASURY_RATES_TIMEOUT', 30),
        'bcv_usd_url' => env('TREASURY_BCV_USD_URL', 'https://ve.dolarapi.com/v1/dolares/oficial'),
        'bcv_eur_url' => env('TREASURY_BCV_EUR_URL', 'https://ve.dolarapi.com/v1/euros/oficial'),
        'trm_url' => env('TREASURY_TRM_URL', 'https://www.datos.gov.co/resource/32sa-8pi3.json'),
        'ranges' => [
            'bcv' => [1, 500000],
            'trm' => [1000, 10000],
        ],
    ],
];
