<?php

/**
 * Connection settings of the client form (public/client/).
 *
 * Copy this file to config/client.php and fill it in. The real file is gitignored and
 * deliberately NOT part of .env: it lives outside the web root (public/) and is only read
 * by the server-side proxy public/client/submit.php. The API keys never reach the browser.
 *
 * Use another location with the environment variable BWC_CLIENT_CONFIG=/path/to/client.php.
 *
 * ONE connection:   api_url + api_key at the top level (see the commented block below).
 * SEVERAL clients:  'connections' => [ id => [label, api_url, api_key, hmac_secret] ] - one entry per API key of the
 *                   visa service (the key decides which SMTP server, mail text, event window and letter template
 *                   apply, see config/clients.example.php). The form then shows a selection; users can be restricted.
 */

return [

    'connections' => [
        'bwc' => [
            'label'       => 'Berne World Cup',
            'api_url'     => 'https://visa.example.com',   // POST <api_url>/api/visa-request
            'api_key'     => 'change-me',                  // an API key of the client "bwc" in the service
            'hmac_secret' => '',                           // only if that client has hmac_secret set
        ],
        // 'other' => [
        //     'label'   => 'Another Event',
        //     'api_url' => 'https://visa.example.com',
        //     'api_key' => 'another-key',
        // ],
    ],

    // Who may open the form (HTTP Basic auth): username => password hash, or with a restriction:
    // username => ['password_hash' => '...', 'connections' => ['bwc']]   (without 'connections': all of them)
    // Create a hash:  php -r 'echo password_hash("the-password", PASSWORD_DEFAULT), "\n";'
    // Without any user the page refuses to work, unless allow_anonymous is true (only for localhost tests!).
    'users' => [
        // 'federation-office' => '$2y$10$...',
        // 'swiss-fencing'     => ['password_hash' => '$2y$10$...', 'connections' => ['bwc']],
    ],
    'allow_anonymous' => false,

    // Optional: prefilled form fields (name, firstname, address, zip, city, country, federation, email).
    'defaults' => [
        // 'country' => 'Switzerland',
    ],

    // Optional tuning.
    'timeout'    => 280,   // seconds to wait for the service (image analysis can take a while)
    'max_images' => 24,
    'verify_tls' => true,  // keep true; false only for self-signed test servers
];

/*
 * Single connection (the older, shorter form - still supported):
 *
 * return [
 *     'api_url' => 'https://visa.example.com',
 *     'api_key' => '...',
 *     'users'   => ['federation-office' => '$2y$10$...'],
 * ];
 */
