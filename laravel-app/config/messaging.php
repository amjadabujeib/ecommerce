<?php

$retryDelays = explode(',', (string) env('MESSAGING_RETRY_DELAYS', '5,30,120'));
if (count($retryDelays) !== 3 || array_filter($retryDelays, fn ($delay) => ! ctype_digit(trim($delay)) || (int) $delay < 1 || (int) $delay > 3600)) {
    throw new InvalidArgumentException('MESSAGING_RETRY_DELAYS requires three comma-separated delays between 1 and 3600 seconds.');
}
$retryDelays = array_map(fn ($delay): int => (int) trim($delay), $retryDelays);
if ($retryDelays[0] >= $retryDelays[1] || $retryDelays[1] >= $retryDelays[2]) {
    throw new InvalidArgumentException('MESSAGING_RETRY_DELAYS must be strictly increasing.');
}

return [
    'enabled' => (bool) env('MESSAGING_ENABLED', false),
    'publish_immediately' => (bool) env('MESSAGING_PUBLISH_IMMEDIATELY', true),
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'vhost' => env('RABBITMQ_VHOST', 'novacart'),
    'username' => env('RABBITMQ_USER', 'novacart'),
    'password' => env('RABBITMQ_PASSWORD', ''),
    'exchange' => 'novacart.orders.v1',
    'retry_delays' => $retryDelays,
    'lease_seconds' => 90,
    'publish_timeout' => 2.0,
    'require_mail_allowlist' => (bool) env('MAIL_REQUIRE_ALLOWLIST', true),
    'mail_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('MAIL_RECIPIENT_ALLOWLIST', ''))))),
];
