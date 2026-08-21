<?php

declare(strict_types=1);

require_once __DIR__ . '/packages.php';

final class SquareApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly array $squareErrorCodes,
        string $message = 'Square API request failed.',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

function app_square_create_payment_link(array $registration, string $statusToken): array
{
    app_require_config(['SQUARE_ACCESS_TOKEN', 'SQUARE_LOCATION_ID']);

    $event = app_event_definitions()[$registration['event_code']] ?? null;
    if ($event === null) {
        throw new RuntimeException('Unknown server-side package.');
    }
    $lineItems = app_square_registration_line_items($registration);

    $returnBase = preg_replace('/#.*$/s', '', trim((string) app_config('CHECKOUT_RETURN_URL')));
    if (!is_string($returnBase) || !app_is_safe_checkout_return_url($returnBase)) {
        throw new RuntimeException('CHECKOUT_RETURN_URL must be an HTTPS URL on the configured COMEC host.');
    }
    $returnUrl = $returnBase . '#'
        . http_build_query(['token' => $statusToken, 'reference' => $registration['public_reference']], '', '&', PHP_QUERY_RFC3986);

    $body = [
        'idempotency_key' => $registration['square_idempotency_key'],
        'order' => [
            'location_id' => (string) app_config('SQUARE_LOCATION_ID'),
            'reference_id' => $registration['public_reference'],
            'line_items' => $lineItems,
        ],
        'checkout_options' => [
            'redirect_url' => $returnUrl,
            'ask_for_shipping_address' => false,
            'allow_tipping' => false,
            'enable_coupon' => false,
            'enable_loyalty' => false,
        ],
        'pre_populated_data' => [
            'buyer_email' => $registration['payer_email'],
            'buyer_phone_number' => $registration['payer_phone'],
        ],
        'payment_note' => 'COMEC ' . $event['short_name'] . ' registration ' . $registration['public_reference'],
    ];

    $response = app_square_request('POST', '/v2/online-checkout/payment-links', $body);
    $link = $response['payment_link'] ?? null;
    if (!is_array($link)) {
        throw new SquareApiException(502, [], 'Square did not return a payment link.');
    }

    $url = (string) ($link['url'] ?? $link['long_url'] ?? '');
    $orderId = trim((string) ($link['order_id'] ?? ''));
    $linkId = trim((string) ($link['id'] ?? ''));
    if ($url === '' || $orderId === '' || $linkId === '' || !app_is_safe_square_checkout_url($url)) {
        throw new SquareApiException(502, [], 'Square returned an incomplete payment link.');
    }

    $matchedOrder = false;
    foreach (($response['related_resources']['orders'] ?? []) as $order) {
        if (!is_array($order) || (string) ($order['id'] ?? '') !== $orderId) {
            continue;
        }
        $matchedOrder = true;
        $total = $order['total_money'] ?? null;
        if ((string) ($order['location_id'] ?? '') !== (string) app_config('SQUARE_LOCATION_ID')
            || (string) ($order['reference_id'] ?? '') !== (string) $registration['public_reference']
            || !is_array($total)
            || (int) ($total['amount'] ?? -1) !== (int) $registration['amount_cents']
            || strtoupper((string) ($total['currency'] ?? '')) !== 'USD') {
            throw new SquareApiException(502, [], 'Square returned an order that did not match the registration.');
        }
        break;
    }
    if (!$matchedOrder) {
        throw new SquareApiException(502, [], 'Square did not return the created order for verification.');
    }

    return [
        'payment_link_id' => $linkId,
        'order_id' => $orderId,
        'url' => $url,
    ];
}

function app_square_registration_line_items(array $registration): array
{
    $event = app_event_definitions()[$registration['event_code']] ?? null;
    $package = is_array($event) ? ($event['packages'][$registration['package_code']] ?? null) : null;
    if ($event === null || $package === null) {
        throw new RuntimeException('Unknown server-side package.');
    }

    $packageQuantity = (int) ($registration['package_quantity'] ?? 0);
    $teamPackage = ($package['team_package'] ?? false) === true;
    $ticketGroupPackage = ($package['ticket_group_package'] ?? false) === true;
    $quantityPackage = $teamPackage || $ticketGroupPackage;
    $maximumQuantity = $teamPackage
        ? (int) $package['team_max']
        : ($ticketGroupPackage ? (int) $package['ticket_group_max'] : 1);
    if ($packageQuantity < 1 || $packageQuantity > $maximumQuantity || (!$quantityPackage && $packageQuantity !== 1)) {
        throw new RuntimeException('Registration package quantity is invalid.');
    }
    $packageUnitAmount = (int) ($registration['package_unit_amount_cents'] ?? -1);
    $baseAmount = (int) ($registration['base_amount_cents'] ?? -1);
    $expectedCapacity = (int) $package['participant_count'] * $packageQuantity;
    if ((string) ($registration['package_name'] ?? '') !== (string) $package['name']
        || $packageUnitAmount !== (int) $package['amount_cents']
        || $baseAmount !== $packageUnitAmount * $packageQuantity
        || (int) ($registration['participant_capacity'] ?? -1) !== $expectedCapacity) {
        throw new RuntimeException('Registration package snapshot does not match the server package.');
    }

    $catalogVariations = app_config('SQUARE_CATALOG_VARIATIONS', []);
    $packageLine = [
        'name' => $event['name'] . ' - ' . $registration['package_name'],
        'quantity' => (string) $packageQuantity,
        'base_price_money' => ['amount' => $packageUnitAmount, 'currency' => 'USD'],
    ];
    $catalogObjectId = trim((string) ($catalogVariations[$registration['package_code']] ?? ''));
    if ($catalogObjectId !== '') {
        $packageLine['catalog_object_id'] = $catalogObjectId;
    }
    $lineItems = [$packageLine];

    try {
        $addons = json_decode((string) ($registration['addons_json'] ?? '[]'), true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Registration add-on snapshot is invalid.', 0, $exception);
    }
    if (!is_array($addons) || !array_is_list($addons)) {
        throw new RuntimeException('Registration add-on snapshot is invalid.');
    }

    $authoritativeAddons = app_registration_addons();
    $seenAddons = [];
    $addonTotal = 0;
    foreach ($addons as $addon) {
        if (!is_array($addon)) {
            throw new RuntimeException('Registration add-on snapshot is invalid.');
        }
        $addonCode = (string) ($addon['code'] ?? '');
        $authoritativeAddon = $authoritativeAddons[$addonCode] ?? null;
        $quantity = (int) ($addon['quantity'] ?? 0);
        $unitAmount = (int) ($addon['unit_amount_cents'] ?? -1);
        $totalAmount = (int) ($addon['total_amount_cents'] ?? -1);
        if (!is_array($authoritativeAddon)
            || isset($seenAddons[$addonCode])
            || !in_array($addonCode, $package['allowed_addons'], true)
            || (string) ($addon['name'] ?? '') !== (string) $authoritativeAddon['name']
            || $quantity < 1
            || $quantity > $packageQuantity
            || $unitAmount !== (int) $authoritativeAddon['amount_cents']
            || $totalAmount !== $unitAmount * $quantity) {
            throw new RuntimeException('Registration add-on snapshot does not match the server package.');
        }
        $seenAddons[$addonCode] = true;
        $addonTotal += $totalAmount;
        $addonLine = [
            'name' => (string) $addon['name'],
            'quantity' => (string) $quantity,
            'base_price_money' => ['amount' => $unitAmount, 'currency' => 'USD'],
        ];
        $addonCatalogId = trim((string) ($catalogVariations[$addonCode] ?? ''));
        if ($addonCatalogId !== '') {
            $addonLine['catalog_object_id'] = $addonCatalogId;
        }
        $lineItems[] = $addonLine;
    }

    if ($addonTotal !== (int) ($registration['addon_amount_cents'] ?? -1)
        || $baseAmount + $addonTotal !== (int) ($registration['amount_cents'] ?? -1)) {
        throw new RuntimeException('Registration total snapshot does not match its line items.');
    }
    return $lineItems;
}

function app_square_get_payment(string $paymentId): array
{
    $response = app_square_request('GET', '/v2/payments/' . rawurlencode($paymentId));
    if (!isset($response['payment']) || !is_array($response['payment'])) {
        throw new SquareApiException(502, [], 'Square did not return the payment.');
    }
    return $response['payment'];
}

function app_square_get_refund(string $refundId): array
{
    $response = app_square_request('GET', '/v2/refunds/' . rawurlencode($refundId));
    if (!isset($response['refund']) || !is_array($response['refund'])) {
        throw new SquareApiException(502, [], 'Square did not return the refund.');
    }
    return $response['refund'];
}

function app_square_request(string $method, string $path, ?array $body = null): array
{
    app_require_config(['SQUARE_ACCESS_TOKEN']);
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required.');
    }

    $environment = strtolower((string) app_config('SQUARE_ENVIRONMENT', 'production'));
    if (!in_array($environment, ['production', 'sandbox'], true)) {
        throw new RuntimeException('SQUARE_ENVIRONMENT must be production or sandbox.');
    }
    $baseUrl = $environment === 'sandbox'
        ? 'https://connect.squareupsandbox.com'
        : 'https://connect.squareup.com';

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . (string) app_config('SQUARE_ACCESS_TOKEN'),
        'Square-Version: ' . (string) app_config('SQUARE_API_VERSION', '2026-07-15'),
    ];

    $encodedBody = null;
    if ($body !== null) {
        $encodedBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    }

    $handle = curl_init($baseUrl . $path);
    if ($handle === false) {
        throw new RuntimeException('Could not initialize cURL.');
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    if ($encodedBody !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $encodedBody);
    }

    $rawResponse = curl_exec($handle);
    $curlError = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if (!is_string($rawResponse)) {
        throw new SquareApiException(503, [], 'Square could not be reached: ' . $curlError);
    }
    if (strlen($rawResponse) > 2_000_000) {
        throw new SquareApiException(502, [], 'Square returned an oversized response.');
    }

    try {
        $decoded = json_decode($rawResponse, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new SquareApiException(502, [], 'Square returned invalid JSON.', $exception);
    }
    if (!is_array($decoded)) {
        throw new SquareApiException(502, [], 'Square returned an invalid response.');
    }

    if ($status < 200 || $status >= 300) {
        $codes = [];
        foreach (($decoded['errors'] ?? []) as $error) {
            if (is_array($error) && isset($error['code']) && is_string($error['code'])) {
                $codes[] = $error['code'];
            }
        }
        throw new SquareApiException($status, array_values(array_unique($codes)));
    }

    return $decoded;
}

function app_is_safe_square_checkout_url(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
        return false;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    return $host === 'square.link' || str_ends_with($host, '.square.link')
        || $host === 'square.site' || str_ends_with($host, '.square.site')
        || $host === 'squareup.com' || str_ends_with($host, '.squareup.com');
}

function app_validated_square_receipt_url(mixed $url, ?string $environment = null): ?string
{
    if (!is_string($url) || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['port'])) {
        return null;
    }

    $environment = strtolower(trim($environment ?? (string) app_config('SQUARE_ENVIRONMENT', 'production')));
    if (!in_array($environment, ['production', 'sandbox'], true)) {
        return null;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $productionHost = $host === 'squareup.com' || str_ends_with($host, '.squareup.com');
    $sandboxHost = $host === 'squareupsandbox.com' || str_ends_with($host, '.squareupsandbox.com');
    return $productionHost || ($environment === 'sandbox' && $sandboxHost) ? $url : null;
}

function app_is_safe_checkout_return_url(string $url): bool
{
    $returnParts = parse_url($url);
    $appParts = parse_url((string) app_config('APP_URL'));
    if (!is_array($returnParts) || !is_array($appParts)
        || strtolower((string) ($returnParts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($appParts['scheme'] ?? '')) !== 'https'
        || isset($returnParts['user']) || isset($returnParts['pass'])) {
        return false;
    }
    $returnPort = (int) ($returnParts['port'] ?? 443);
    $appPort = (int) ($appParts['port'] ?? 443);
    return strtolower((string) ($returnParts['host'] ?? '')) === strtolower((string) ($appParts['host'] ?? ''))
        && $returnPort === $appPort;
}
