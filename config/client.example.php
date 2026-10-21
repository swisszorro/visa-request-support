<?php

/**
 * Connection settings of the client form (public/client/).
 *
 * Copy this file to config/client.php and fill it in. The real file is gitignored and
 * deliberately NOT part of .env: it lives outside the web root (public/) and is only read
 * by the server-side proxy public/client/submit.php. The API key never reaches the browser.
 *
 * Use another location with the environment variable BWC_CLIENT_CONFIG=/path/to/client.php.
 */

return [
    // Base URL of the visa service (no trailing path). The server calls POST <api_url>/api/visa-request.
    'api_url' => 'https://visa.example.com',

    // One of the SERVICE_API_KEYS of the service.
    'api_key' => 'change-me',

    // Only if the service has SERVICE_HMAC_SECRET set (leave '' otherwise).
    'hmac_secret' => '',

    // Who may open the form (HTTP Basic auth): username => password hash.
    // Create a hash:  php -r 'echo password_hash("the-password", PASSWORD_DEFAULT), "\n";'
    // Without any user the page refuses to work, unless allow_anonymous is true (only for localhost tests!).
    'users' => [
        // 'federation-office' => '$2y$10$...',
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
