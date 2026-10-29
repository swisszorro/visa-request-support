<?php

/**
 * API clients of the visa service: one entry per client (tenant), each with its OWN
 *   - API key(s)            -> the key decides which client a request belongs to
 *   - SMTP server           -> mails of this client are sent through this server
 *   - mail settings         -> MAIL_ENABLED / DEFAULT_TO / SUBJECT / FROM / FROM_NAME / HTML / BODY
 *   - event window          -> EVENT_START / EVENT_END
 *   - Word letter template  -> .docx with the same structure as resources/BWC Visa Invitation Letter.docx
 *
 * Copy this file to config/clients.php (gitignored, outside the web root). Another location:
 * environment variable BWC_CLIENTS_CONFIG=/path/to/clients.php
 *
 * Rules
 *  - A named client inherits NOTHING from .env. It cannot accidentally send with another client's SMTP
 *    credentials or sender address; what is not set here stays empty / off.
 *  - The keys in .env SERVICE_API_KEYS keep working as the client "default" and use the .env settings
 *    (template: LETTER_TEMPLATE, default "resources/BWC Visa Invitation Letter.docx").
 *  - A key may belong to one client only. A typo in a setting name is an error (the service answers
 *    "client configuration is invalid" and logs the reason) instead of being silently ignored.
 *  - 'template' is relative to the project root (or an absolute path).
 *
 * Generate an API key:        openssl rand -hex 32
 * Store only its hash instead: printf '%s' "<key>" | openssl dgst -sha256     (-> api_key_hashes)
 */

return [

    'bwc' => [
        'name' => 'Berne World Cup',

        // One or more plain keys (several = key rotation), and/or sha256 hashes of keys.
        'api_keys'       => ['replace-with-openssl-rand-hex-32'],
        // 'api_key_hashes' => ['<sha256 hex of the key>'],

        // Optional replay protection for this client (see README "Authentisierung"); '' = off.
        'hmac_secret' => '',

        'template' => 'resources/BWC Visa Invitation Letter.docx',

        'event' => [
            'start' => '2026-11-12',   // travel dates outside the window only produce a warning
            'end'   => '2026-11-14',
        ],

        'smtp' => [
            'host'   => 'asmtp.mail.hostpoint.ch',
            'port'   => 587,
            'secure' => 'tls',          // tls | ssl | none
            'user'   => 'berneworldcup@example.ch',
            'pass'   => 'smtp-password',
        ],

        'mail' => [
            'enabled'    => true,       // false: only mails when the request sends send_email=1
            'default_to' => 'berneworldcup@example.ch',
            'subject'    => 'Berne World Cup – Visa Invitation Letter',
            'from'       => 'berneworldcup@example.ch',
            'from_name'  => 'Berne World Cup 2026',
            'html'       => true,
            // Placeholders: {federation} {name} {firstname} {filename} {applicants_total}
            //               {passports_detected} {successful} {failed} {failed_list}
            'body'       => '<p>Dear {federation},</p><p>Attached you will find the invitation letter (<b>{filename}</b>).</p>'
                . '<ul><li>Total applicants: {applicants_total}</li><li>Verified: {successful}</li><li>Failed: {failed}</li></ul>'
                . '<p>Best regards,<br>Berne World Cup Organizing Committee</p>',
        ],
    ],

    // 'other-event' => [
    //     'name'     => 'Another Event',
    //     'api_keys' => ['another-key'],
    //     'template' => 'resources/templates/other-event.docx',
    //     'event'    => ['start' => '2027-05-01', 'end' => '2027-05-03'],
    //     'smtp'     => ['host' => 'smtp.other.example', 'port' => 465, 'secure' => 'ssl', 'user' => 'u', 'pass' => 'p'],
    //     'mail'     => ['enabled' => true, 'default_to' => 'office@other.example', 'subject' => 'Visa letter',
    //                    'from' => 'office@other.example', 'from_name' => 'Other Event', 'html' => false, 'body' => ''],
    // ],

];
