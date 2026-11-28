<?php

return [

    /*
    |--------------------------------------------------------------------------
    | External anchoring
    |--------------------------------------------------------------------------
    |
    | Pins the head of the sealed ledger and of the activity log outside the church (a digest only,
    | never data). Two complementary providers, no wallet: RFC 3161 (a signed timestamp within
    | seconds) and OpenTimestamps (Bitcoin, hours later). Publishing is an action towards the
    | outside, so it is switched on explicitly with INTEGRITY_ANCHOR_ENABLED.
    |
    */

    'anchor' => [
        'enabled' => (bool) env('INTEGRITY_ANCHOR_ENABLED', false),

        'providers' => array_values(array_filter(explode(',', (string) env('INTEGRITY_ANCHOR_PROVIDERS', 'rfc3161,opentimestamps')))),

        'timeout' => (int) env('INTEGRITY_ANCHOR_TIMEOUT', 20),

        'openssl' => env('INTEGRITY_ANCHOR_OPENSSL', 'openssl'),

        // Timestamp authorities, in order. Their certificates are kept in the repository with their published fingerprint.
        'rfc3161' => [
            [
                'url' => 'https://freetsa.org/tsr',
                'ca_file' => 'resources/certificates/tsa/freetsa-cacert.pem',
                'cert_file' => 'resources/certificates/tsa/freetsa-tsa.crt',
            ],
        ],

        'opentimestamps' => [
            'aggregators' => [
                'https://a.pool.opentimestamps.org',
                'https://b.pool.opentimestamps.org',
                'https://a.pool.eternitywall.com',
                'https://ots.btc.catallaxy.com',
            ],
            // Only these hosts are queried when a proof is completed (never a URL taken blindly).
            'whitelist' => [
                '*.calendar.opentimestamps.org',
                '*.calendar.eternitywall.com',
                '*.calendar.catallaxy.com',
                'ots.btc.catallaxy.com',
            ],
            // Esplora API to read Bitcoin block headers (blockstream.info or mempool.space).
            'esplora_url' => env('INTEGRITY_ANCHOR_ESPLORA_URL', 'https://blockstream.info/api'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Row seals
    |--------------------------------------------------------------------------
    |
    | Key of the HMAC that seals rows of sealed models. Its own key (not APP_KEY: rotating the
    | application key must not invalidate the history) and it never lives in the database: whoever
    | only has database access cannot forge a valid seal for a changed row. Losing it leaves the
    | history without verification: keep it with the production secrets. Without it nothing is sealed.
    |
    */

    'seal_key' => env('INTEGRITY_SEAL_KEY'),

];
