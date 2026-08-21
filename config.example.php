<?php

declare(strict_types=1);

// Copy this file outside public_html, restrict it to the account owner, and set
// COMEC_CONFIG_FILE to its absolute path. Never put real credentials in this repository.
return [
    'APP_ENV' => 'production',
    'APP_URL' => 'https://www.comec.org',
    'APP_KEY' => '', // Generate 32 random bytes and encode them as hex or Base64.
    'HTTPS_ONLY' => true,
    'ERROR_LOG_FILE' => '', // Prefer an absolute path outside public_html.
    'TRUSTED_PROXY_IPS' => [],

    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,
    'DB_NAME' => '',
    'DB_USER' => '',
    'DB_PASSWORD' => '',

    'SQUARE_ENVIRONMENT' => 'production',
    'SQUARE_API_VERSION' => '2026-07-15',
    'SQUARE_ACCESS_TOKEN' => '',
    'SQUARE_LOCATION_ID' => '',
    'SQUARE_MERCHANT_ID' => '',
    'SQUARE_WEBHOOK_SIGNATURE_KEY' => '',
    'SQUARE_WEBHOOK_NOTIFICATION_URL' => 'https://www.comec.org/api/square-webhook.php',
    'SQUARE_CATALOG_VARIATIONS' => [
        // 'corporate_sponsor' => 'CATALOG_VARIATION_ID',
    ],
    // A CPA must approve every description and FMV before checkout is enabled.
    // Null placeholders intentionally fail closed; do not guess these values.
    'PACKAGE_BENEFITS' => [
        'corporate_sponsor' => ['description' => '', 'fair_market_value_cents' => null],
        'contest_sponsor' => ['description' => '', 'fair_market_value_cents' => null],
        'drink_cart_sponsor' => ['description' => '', 'fair_market_value_cents' => null],
        'team_sponsor' => ['description' => '', 'fair_market_value_cents' => null],
        'hole_sponsor' => ['description' => '', 'fair_market_value_cents' => null],
        'individual_player' => ['description' => '', 'fair_market_value_cents' => null],
        'team_mulligans' => ['description' => '', 'fair_market_value_cents' => null],
        'gala_single' => ['description' => '', 'fair_market_value_cents' => null],
        'gala_couple' => ['description' => '', 'fair_market_value_cents' => null],
        'gala_vip_single' => ['description' => '', 'fair_market_value_cents' => null],
        'gala_vip_couple' => ['description' => '', 'fair_market_value_cents' => null],
    ],
    'CHECKOUT_RETURN_URL' => 'https://www.comec.org/registration-status.html',
    'WEBHOOK_MAX_ATTEMPTS' => 12,
    'WEBHOOK_BATCH_SIZE' => 25,

    'ADMIN_USERNAME' => '',
    'ADMIN_PASSWORD_HASH' => '', // Generate with password_hash(), never store a plain password.

    'MAIL_TRANSPORT' => 'mail',
    'MAIL_FROM_EMAIL' => '',
    'MAIL_FROM_NAME' => 'COMEC',
    'MAIL_REPLY_TO' => '',
    'MAIL_SMTP_HOST' => '',
    'MAIL_SMTP_PORT' => 587,
    'MAIL_SMTP_ENCRYPTION' => 'tls',
    'MAIL_SMTP_USERNAME' => '',
    'MAIL_SMTP_PASSWORD' => '',
    'MAIL_SMTP_TIMEOUT' => 10,
    'MAIL_MAX_ATTEMPTS' => 5,
    'MAIL_BATCH_SIZE' => 25,
    // Sent message content is scrubbed after this period; delivery audit metadata remains.
    'EMAIL_OUTBOX_RETENTION_DAYS' => 90,
    'INTERNAL_NOTIFICATION_RECIPIENTS' => [
        'pboals@theboalsgroup.com',
        'comecnonprofit@gmail.com',
        'underwoodudl@gmail.com',
    ],
];
