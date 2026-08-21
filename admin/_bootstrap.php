<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/square.php';

function admin_require_auth(): void
{
    app_require_https();

    $username = (string) app_config('ADMIN_USERNAME', '');
    $passwordHash = (string) app_config('ADMIN_PASSWORD_HASH', '');
    if ($username === '' || $passwordHash === '') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo "The registration dashboard is not configured.\n";
        exit;
    }

    [$providedUser, $providedPassword] = admin_basic_credentials();
    $validUser = hash_equals($username, $providedUser);
    $validPassword = $providedPassword !== '' && password_verify($providedPassword, $passwordHash);

    if (!$validUser || !$validPassword) {
        header('WWW-Authenticate: Basic realm="COMEC registrations", charset="UTF-8"');
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo "Authentication required.\n";
        exit;
    }
}

function admin_basic_credentials(): array
{
    $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $password = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
    if ($user !== '' || $password !== '') {
        return [$user, $password];
    }

    $authorization = (string) (
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    );
    if (preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', trim($authorization), $matches) !== 1) {
        return ['', ''];
    }

    $decoded = base64_decode($matches[1], true);
    if (!is_string($decoded) || !str_contains($decoded, ':')) {
        return ['', ''];
    }
    return explode(':', $decoded, 2);
}

function admin_html_headers(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
}

function admin_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_money(int $cents): string
{
    return '$' . number_format($cents / 100, 2, '.', ',');
}

function admin_package_options(): array
{
    $options = [];
    foreach (app_event_definitions() as $eventCode => $event) {
        foreach ($event['packages'] as $packageCode => $package) {
            $options[$packageCode] = [
                'name' => (string) $package['name'],
                'event_code' => (string) $eventCode,
                'event_name' => (string) $event['short_name'],
            ];
        }
    }
    return $options;
}

function admin_registration_addons(mixed $json): array
{
    if (!is_string($json) || $json === '') {
        return [];
    }
    try {
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return [];
    }

    $known = app_registration_addons();
    $addons = [];
    foreach ($decoded as $item) {
        $code = is_string($item) ? $item : (is_array($item) ? (string) ($item['code'] ?? '') : '');
        $name = is_array($item) ? trim((string) ($item['name'] ?? '')) : '';
        if ($name === '' && isset($known[$code])) {
            $name = (string) $known[$code]['name'];
        }
        if ($name === '') {
            continue;
        }

        $knownUnitAmount = isset($known[$code]) ? (int) $known[$code]['amount_cents'] : 0;
        $unitAmount = is_array($item) && is_numeric($item['unit_amount_cents'] ?? null)
            ? max(0, (int) $item['unit_amount_cents'])
            : $knownUnitAmount;
        $quantity = is_array($item) && is_numeric($item['quantity'] ?? null)
            ? max(1, (int) $item['quantity'])
            : 1;
        $totalAmount = is_array($item) && is_numeric($item['total_amount_cents'] ?? null)
            ? max(0, (int) $item['total_amount_cents'])
            : $unitAmount * $quantity;
        $addons[] = [
            'code' => $code,
            'name' => $name,
            'unit_amount_cents' => $unitAmount,
            'quantity' => $quantity,
            'total_amount_cents' => $totalAmount,
        ];
    }
    return $addons;
}

function admin_addon_labels(mixed $json): array
{
    return array_map(
        static fn (array $addon): string => $addon['name'] . ($addon['quantity'] > 1 ? ' x ' . $addon['quantity'] : ''),
        admin_registration_addons($json)
    );
}

function admin_team_addons(mixed $json): array
{
    if (!is_string($json) || $json === '') {
        return [];
    }
    try {
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return [];
    }

    $known = app_registration_addons();
    $addons = [];
    foreach ($decoded as $item) {
        $code = is_string($item) ? $item : (is_array($item) ? (string) ($item['code'] ?? '') : '');
        if ($code === '' || !isset($known[$code])) {
            continue;
        }
        $addons[] = [
            'code' => $code,
            'name' => (string) $known[$code]['name'],
            'unit_amount_cents' => (int) $known[$code]['amount_cents'],
        ];
    }
    return $addons;
}

function admin_safe_square_receipt_url(mixed $value): ?string
{
    return app_validated_square_receipt_url($value);
}

function admin_filters(): array
{
    $allowedStatuses = [
        '', 'pending_checkout', 'pending_payment', 'checkout_error', 'paid',
        'partially_refunded', 'refunded',
    ];
    $status = is_string($_GET['status'] ?? null) ? trim($_GET['status']) : '';
    if (!in_array($status, $allowedStatuses, true)) {
        $status = '';
    }

    $event = is_string($_GET['event'] ?? null) ? trim($_GET['event']) : '';
    if ($event !== '' && !array_key_exists($event, app_event_definitions())) {
        $event = '';
    }

    $package = is_string($_GET['package'] ?? null) ? trim($_GET['package']) : '';
    if ($package !== '' && !array_key_exists($package, admin_package_options())) {
        $package = '';
    }

    $search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
    if (app_text_length($search) > 100) {
        $search = function_exists('mb_substr') ? mb_substr($search, 0, 100, 'UTF-8') : substr($search, 0, 100);
    }

    return ['event' => $event, 'status' => $status, 'package' => $package, 'q' => $search];
}

function admin_registration_query(PDO $pdo, array $filters, ?int $limit = 500): array
{
    $where = ['1 = 1'];
    $values = [];
    if ($filters['event'] !== '') {
        $where[] = 'r.event_code = ?';
        $values[] = $filters['event'];
    }
    if ($filters['status'] !== '') {
        $where[] = 'r.status = ?';
        $values[] = $filters['status'];
    }
    if ($filters['package'] !== '') {
        $where[] = 'r.package_code = ?';
        $values[] = $filters['package'];
    }
    if ($filters['q'] !== '') {
        $needle = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']) . '%';
        $where[] = '(r.public_reference LIKE ? ESCAPE \'!\' OR r.event_name LIKE ? ESCAPE \'!\' '
            . 'OR r.payer_first_name LIKE ? ESCAPE \'!\' '
            . 'OR r.payer_last_name LIKE ? ESCAPE \'!\' OR r.payer_company LIKE ? ESCAPE \'!\' '
            . 'OR r.payer_email LIKE ? ESCAPE \'!\' OR r.team_name LIKE ? ESCAPE \'!\' '
            . 'OR r.sponsor_display LIKE ? ESCAPE \'!\' '
            . 'OR EXISTS (SELECT 1 FROM registration_teams rt '
            . 'WHERE rt.registration_id = r.id AND rt.name LIKE ? ESCAPE \'!\') '
            . 'OR EXISTS (SELECT 1 FROM participants sp '
            . 'WHERE sp.registration_id = r.id AND sp.name LIKE ? ESCAPE \'!\'))';
        array_push(
            $values,
            $needle,
            $needle,
            $needle,
            $needle,
            $needle,
            $needle,
            $needle,
            $needle,
            $needle,
            $needle
        );
    }

    $sql = 'SELECT r.*, p.square_payment_id, p.receipt_url, p.refunded_amount_cents '
        . 'FROM registrations r LEFT JOIN payments p ON p.id = ('
        . 'SELECT p2.id FROM payments p2 WHERE p2.registration_id = r.id '
        . 'ORDER BY p2.completed_at DESC, p2.id DESC LIMIT 1) '
        . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY r.created_at DESC';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, min($limit, 2000));
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($values);
    $registrations = $statement->fetchAll();
    if ($registrations === []) {
        return [];
    }

    $ids = array_map(static fn (array $row): int => (int) $row['id'], $registrations);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $teamStatement = $pdo->prepare(
        'SELECT id, registration_id, position, name, participant_capacity, addons_json '
        . "FROM registration_teams WHERE registration_id IN ({$placeholders}) "
        . 'ORDER BY registration_id, position, id'
    );
    $teamStatement->execute($ids);
    $teams = [];
    $teamLocations = [];
    foreach ($teamStatement->fetchAll() as $team) {
        $registrationId = (int) $team['registration_id'];
        $teamId = (int) $team['id'];
        $teams[$registrationId] ??= [];
        $teamLocations[$teamId] = [$registrationId, count($teams[$registrationId])];
        $teams[$registrationId][] = [
            'position' => (int) $team['position'],
            'name' => (string) $team['name'],
            'participant_capacity' => (int) $team['participant_capacity'],
            'addons' => admin_team_addons($team['addons_json'] ?? ''),
            'participants' => [],
        ];
    }

    $ticketGroupStatement = $pdo->prepare(
        'SELECT id, registration_id, position, participant_capacity '
        . "FROM registration_ticket_groups WHERE registration_id IN ({$placeholders}) "
        . 'ORDER BY registration_id, position, id'
    );
    $ticketGroupStatement->execute($ids);
    $ticketGroups = [];
    $ticketGroupLocations = [];
    foreach ($ticketGroupStatement->fetchAll() as $ticketGroup) {
        $registrationId = (int) $ticketGroup['registration_id'];
        $ticketGroupId = (int) $ticketGroup['id'];
        $ticketGroups[$registrationId] ??= [];
        $ticketGroupLocations[$ticketGroupId] = [$registrationId, count($ticketGroups[$registrationId])];
        $ticketGroups[$registrationId][] = [
            'position' => (int) $ticketGroup['position'],
            'participant_capacity' => (int) $ticketGroup['participant_capacity'],
            'participants' => [],
        ];
    }

    $participantStatement = $pdo->prepare(
        'SELECT registration_id, team_id, ticket_group_id, position, team_position, ticket_group_position, name '
        . 'FROM participants '
        . "WHERE registration_id IN ({$placeholders}) ORDER BY registration_id, position"
    );
    $participantStatement->execute($ids);
    $participants = [];
    foreach ($participantStatement->fetchAll() as $participant) {
        $registrationId = (int) $participant['registration_id'];
        if ($participant['team_id'] !== null) {
            $teamId = (int) $participant['team_id'];
            if (isset($teamLocations[$teamId])) {
                [$teamRegistrationId, $teamIndex] = $teamLocations[$teamId];
                $teams[$teamRegistrationId][$teamIndex]['participants'][] = [
                    'position' => (int) $participant['team_position'],
                    'name' => (string) $participant['name'],
                ];
            }
            continue;
        }
        if ($participant['ticket_group_id'] !== null) {
            $ticketGroupId = (int) $participant['ticket_group_id'];
            if (isset($ticketGroupLocations[$ticketGroupId])) {
                [$ticketRegistrationId, $ticketGroupIndex] = $ticketGroupLocations[$ticketGroupId];
                $ticketGroups[$ticketRegistrationId][$ticketGroupIndex]['participants'][] = [
                    'position' => (int) $participant['ticket_group_position'],
                    'name' => (string) $participant['name'],
                ];
            }
            continue;
        }
        $participants[$registrationId][] = [
            'position' => (int) $participant['position'],
            'name' => (string) $participant['name'],
        ];
    }
    foreach ($teams as &$registrationTeams) {
        foreach ($registrationTeams as &$team) {
            usort(
                $team['participants'],
                static fn (array $left, array $right): int => $left['position'] <=> $right['position']
            );
        }
        unset($team);
    }
    unset($registrationTeams);
    foreach ($ticketGroups as &$registrationTicketGroups) {
        foreach ($registrationTicketGroups as &$ticketGroup) {
            usort(
                $ticketGroup['participants'],
                static fn (array $left, array $right): int => $left['position'] <=> $right['position']
            );
        }
        unset($ticketGroup);
    }
    unset($registrationTicketGroups);
    foreach ($registrations as &$registration) {
        $registrationId = (int) $registration['id'];
        $registration['teams'] = $teams[$registrationId] ?? [];
        $registration['ticket_groups'] = $ticketGroups[$registrationId] ?? [];
        $registration['participants'] = $participants[$registrationId] ?? [];
    }
    unset($registration);

    return $registrations;
}
