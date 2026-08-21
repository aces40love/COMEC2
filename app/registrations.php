<?php

declare(strict_types=1);

require_once __DIR__ . '/packages.php';
require_once __DIR__ . '/square.php';

function app_create_registration(PDO $pdo, array $data, string $clientKey): array
{
    app_require_config(['APP_URL', 'APP_KEY', 'SQUARE_ACCESS_TOKEN', 'SQUARE_LOCATION_ID']);
    if (!preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $clientKey)) {
        throw new ApiException(400, 'invalid_idempotency_key', 'A valid Idempotency-Key header is required.');
    }

    $clientHash = hash('sha256', $clientKey, true);
    $fingerprint = app_registration_fingerprint($data);
    $statusToken = app_status_token_for_idempotency_key($clientKey);
    $row = app_find_registration_by_client_hash($pdo, $clientHash);

    for ($attempt = 0; $row === null && $attempt < 4; $attempt++) {
        $reference = $data['event']['reference_prefix'] . '-' . strtoupper(bin2hex(random_bytes(5)));
        $squareIdempotency = app_uuid_v4();
        try {
            $id = app_transaction($pdo, static function (PDO $pdo) use (
                $data, $reference, $squareIdempotency, $statusToken, $clientHash, $fingerprint
            ): int {
                $payer = $data['payer'];
                $details = $data['registration'];
                $benefit = $data['benefit'];
                $addons = [];
                foreach ($data['addons'] as $code => $addon) {
                    $addons[] = [
                        'code' => $code,
                        'name' => $addon['name'],
                        'unit_amount_cents' => $addon['unit_amount_cents'],
                        'quantity' => $addon['quantity'],
                        'total_amount_cents' => $addon['total_amount_cents'],
                    ];
                }
                $sql = 'INSERT INTO registrations '
                    . '(public_reference,status_token_hash,client_idempotency_hash,request_fingerprint,event_code,event_name,'
                    . 'event_date_label,package_code,package_name,package_quantity,package_unit_amount_cents,'
                    . 'base_amount_cents,addon_amount_cents,addons_json,'
                    . 'amount_cents,currency,benefit_description,fair_market_value_cents,deductible_amount_cents,'
                    . 'participant_capacity,payer_first_name,payer_last_name,payer_company,payer_email,payer_phone,'
                    . 'payer_address_line1,payer_city,payer_state,payer_postal_code,team_name,sponsor_display,contest_choice,'
                    . 'notes,status,consent_at,consent_ip_hash,terms_version,square_idempotency_key,square_location_id,'
                    . 'created_at,updated_at) VALUES '
                    . '(:ref,:token_hash,:client_hash,:fingerprint,:event_code,:event_name,:event_date,:package_code,'
                    . ':package_name,:package_quantity,:package_unit_amount,:base_amount,:addon_amount,:addons_json,'
                    . ':amount,:currency,:benefit,:fmv,:deductible,'
                    . ':capacity,:first,:last,:company,:email,:phone,:address,:city,:state,:postal,:team,:sponsor,:contest,'
                    . ':notes,:status,UTC_TIMESTAMP(6),:consent_ip,:terms,:square_key,:location,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))';
                $statement = $pdo->prepare($sql);
                $values = [
                    ':ref' => $reference, ':token_hash' => hash('sha256', $statusToken, true),
                    ':client_hash' => $clientHash, ':fingerprint' => $fingerprint,
                    ':event_code' => $data['event_code'], ':event_name' => $data['event']['name'],
                    ':event_date' => $data['event']['date'], ':package_code' => $data['package_code'],
                    ':package_name' => $data['package']['name'], ':package_quantity' => $data['package_quantity'],
                    ':package_unit_amount' => $data['package_unit_amount_cents'],
                    ':base_amount' => $data['base_amount_cents'],
                    ':addon_amount' => $data['addon_amount_cents'],
                    ':addons_json' => json_encode($addons, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':amount' => $data['amount_cents'], ':currency' => 'USD',
                    ':benefit' => $benefit['description'], ':fmv' => $benefit['fair_market_value_cents'],
                    ':deductible' => $benefit['deductible_amount_cents'],
                    ':capacity' => $data['participant_capacity'], ':first' => $payer['first_name'],
                    ':last' => $payer['last_name'], ':company' => $payer['company'] ?: null,
                    ':email' => $payer['email'], ':phone' => $payer['phone'], ':address' => $payer['address_line1'],
                    ':city' => $payer['city'], ':state' => $payer['state'], ':postal' => $payer['postal_code'],
                    ':team' => $details['team_name'] ?: null, ':sponsor' => $details['sponsor_display'] ?: null,
                    ':contest' => $details['contest_choice'] ?: null, ':notes' => $details['notes'] ?: null,
                    ':status' => 'pending_checkout', ':consent_ip' => app_ip_hash(), ':terms' => 'events-2026-v2',
                    ':square_key' => $squareIdempotency, ':location' => (string) app_config('SQUARE_LOCATION_ID'),
                ];
                foreach ($values as $name => $value) {
                    $binary = in_array($name, [':token_hash', ':client_hash', ':fingerprint'], true);
                    $type = $value === null ? PDO::PARAM_NULL
                        : (is_int($value) ? PDO::PARAM_INT : ($binary ? PDO::PARAM_LOB : PDO::PARAM_STR));
                    $statement->bindValue($name, $value, $type);
                }
                $statement->execute();
                $id = (int) $pdo->lastInsertId();
                $participant = $pdo->prepare(
                    'INSERT INTO participants '
                    . '(registration_id,team_id,ticket_group_id,position,team_position,ticket_group_position,name,created_at) '
                    . 'VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6))'
                );
                $participantPosition = 0;
                foreach ($data['participants'] as $index => $person) {
                    $participantPosition++;
                    $participant->execute([$id, null, null, $participantPosition, null, null, $person['name']]);
                }
                $teamInsert = $pdo->prepare(
                    'INSERT INTO registration_teams '
                    . '(registration_id,position,name,participant_capacity,addons_json,created_at) '
                    . 'VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))'
                );
                foreach ($data['teams'] as $teamIndex => $team) {
                    $teamInsert->execute([
                        $id,
                        $teamIndex + 1,
                        $team['name'],
                        (int) $data['package']['participant_count'],
                        json_encode($team['addons'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
                    $teamId = (int) $pdo->lastInsertId();
                    foreach ($team['participants'] as $teamParticipantIndex => $person) {
                        $participantPosition++;
                        $participant->execute([
                            $id,
                            $teamId,
                            null,
                            $participantPosition,
                            $teamParticipantIndex + 1,
                            null,
                            $person['name'],
                        ]);
                    }
                }
                $ticketGroupInsert = $pdo->prepare(
                    'INSERT INTO registration_ticket_groups '
                    . '(registration_id,position,participant_capacity,created_at) '
                    . 'VALUES (?,?,?,UTC_TIMESTAMP(6))'
                );
                foreach ($data['ticket_groups'] as $ticketGroupIndex => $ticketGroup) {
                    $ticketGroupInsert->execute([
                        $id,
                        $ticketGroupIndex + 1,
                        (int) $data['package']['participant_count'],
                    ]);
                    $ticketGroupId = (int) $pdo->lastInsertId();
                    foreach ($ticketGroup['participants'] as $ticketGroupParticipantIndex => $person) {
                        $participantPosition++;
                        $participant->execute([
                            $id,
                            null,
                            $ticketGroupId,
                            $participantPosition,
                            null,
                            $ticketGroupParticipantIndex + 1,
                            $person['name'],
                        ]);
                    }
                }
                return $id;
            });
            $select = $pdo->prepare('SELECT * FROM registrations WHERE id = ?');
            $select->execute([$id]);
            $row = $select->fetch() ?: null;
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $row = app_find_registration_by_client_hash($pdo, $clientHash);
            if ($row === null && $attempt === 3) {
                throw $exception;
            }
        }
    }

    if ($row === null) {
        throw new RuntimeException('Could not allocate a registration reference.');
    }
    if (!hash_equals((string) $row['request_fingerprint'], $fingerprint)) {
        throw new ApiException(409, 'idempotency_conflict', 'This Idempotency-Key was already used for different registration details.');
    }
    return app_ensure_registration_checkout($pdo, $row, $statusToken);
}

function app_ensure_registration_checkout(PDO $pdo, array $row, string $statusToken): array
{
    if (!empty($row['checkout_url']) && !empty($row['square_order_id'])
        && !in_array($row['status'], ['pending_checkout', 'checkout_error'], true)) {
        return app_registration_checkout_response($row, (string) $row['checkout_url'], $statusToken);
    }

    try {
        $link = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $link = app_square_create_payment_link($row, $statusToken);
                break;
            } catch (SquareApiException $exception) {
                if ($attempt === 0 && $exception->httpStatus >= 500) {
                    continue;
                }
                throw $exception;
            }
        }
        if (!is_array($link)) {
            throw new SquareApiException(503, [], 'Square payment-link creation did not complete.');
        }
    } catch (Throwable $exception) {
        $code = $exception instanceof SquareApiException
            ? implode(',', array_slice($exception->squareErrorCodes, 0, 5)) : 'INTEGRATION_ERROR';
        $update = $pdo->prepare(
            "UPDATE registrations SET status='checkout_error',checkout_error_code=?,updated_at=UTC_TIMESTAMP(6) "
            . "WHERE id=? AND status IN ('pending_checkout','checkout_error')"
        );
        $update->execute([$code, (int) $row['id']]);
        app_log('error', 'Square payment-link creation failed', [
            'reference' => $row['public_reference'], 'exception' => get_class($exception),
        ]);
        throw new ApiException(502, 'checkout_unavailable',
            'Secure checkout is temporarily unavailable. Please try again or call COMEC at 901-222-0700.');
    }

    $update = $pdo->prepare(
        "UPDATE registrations SET square_payment_link_id=?,square_order_id=?,checkout_url=?,status='pending_payment',"
        . "checkout_error_code=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status IN ('pending_checkout','checkout_error')"
    );
    $update->execute([$link['payment_link_id'], $link['order_id'], $link['url'], (int) $row['id']]);
    if ($update->rowCount() === 1) {
        return app_registration_checkout_response($row, $link['url'], $statusToken);
    }

    $select = $pdo->prepare('SELECT * FROM registrations WHERE id = ?');
    $select->execute([(int) $row['id']]);
    $current = $select->fetch();
    if (!$current) {
        throw new RuntimeException('Registration disappeared while checkout was being prepared.');
    }
    $checkoutUrl = trim((string) ($current['checkout_url'] ?? ''));
    if ($checkoutUrl === '') {
        throw new ApiException(409, 'registration_state_changed',
            'The registration changed while checkout was being prepared. Please check its status.');
    }
    return app_registration_checkout_response($current, $checkoutUrl, $statusToken);
}

function app_registration_checkout_response(array $row, string $checkoutUrl, string $statusToken): array
{
    $statusUrl = rtrim((string) app_config('APP_URL'), '/') . '/registration-status.html#'
        . http_build_query(['token' => $statusToken, 'reference' => $row['public_reference']], '', '&', PHP_QUERY_RFC3986);
    return ['ok' => true, 'checkout_url' => $checkoutUrl, 'reference' => $row['public_reference'], 'status_url' => $statusUrl];
}

function app_find_registration_by_client_hash(PDO $pdo, string $hash): ?array
{
    $statement = $pdo->prepare('SELECT * FROM registrations WHERE client_idempotency_hash = ? LIMIT 1');
    $statement->bindValue(1, $hash, PDO::PARAM_LOB);
    $statement->execute();
    return $statement->fetch() ?: null;
}

function app_registration_fingerprint(array $data): string
{
    return hash('sha256', json_encode([
        'event_code' => $data['event_code'], 'package_code' => $data['package_code'],
        'addons' => $data['addons'], 'payer' => $data['payer'],
        'registration' => $data['registration'], 'participants' => $data['participants'],
        'teams' => $data['teams'], 'ticket_groups' => $data['ticket_groups'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true);
}

function app_status_token_for_idempotency_key(string $clientKey): string
{
    $key = (string) app_config('APP_KEY');
    if (strlen($key) < 32) {
        throw new RuntimeException('APP_KEY must contain at least 32 characters.');
    }
    return app_base64url(hash_hmac('sha256', "registration-status\0" . $clientKey, $key, true));
}

function app_registration_status(PDO $pdo, string $token): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
        throw new ApiException(404, 'registration_not_found', 'The registration could not be found.');
    }
    $sql = 'SELECT r.id,r.public_reference,r.status,r.event_code,r.event_name,r.event_date_label,r.package_code,r.package_name,'
        . 'r.package_quantity,'
        . 'r.addons_json,r.amount_cents,r.currency,r.benefit_description,r.fair_market_value_cents,'
        . 'r.deductible_amount_cents,r.participant_capacity,r.payer_first_name,r.payer_last_name,r.payer_email,'
        . '(SELECT p.receipt_url FROM payments p WHERE p.registration_id=r.id AND p.receipt_url IS NOT NULL '
        . 'ORDER BY p.completed_at DESC,p.id DESC LIMIT 1) AS receipt_url '
        . 'FROM registrations r WHERE r.status_token_hash=? LIMIT 1';
    $statement = $pdo->prepare($sql);
    $statement->bindValue(1, hash('sha256', $token, true), PDO::PARAM_LOB);
    $statement->execute();
    $row = $statement->fetch();
    if (!$row) {
        throw new ApiException(404, 'registration_not_found', 'The registration could not be found.');
    }
    $roster = app_load_registration_roster($pdo, (int) $row['id']);
    $messages = [
        'pending_checkout' => 'Your registration was saved and checkout is being prepared.',
        'pending_payment' => 'Your registration is saved. Payment has not yet been confirmed.',
        'checkout_error' => 'Checkout could not be prepared. Please call COMEC at 901-222-0700.',
        'paid' => 'Payment is confirmed. Confirmation email has been queued.',
        'partially_refunded' => 'A partial refund has been recorded for this registration.',
        'refunded' => 'This registration payment has been refunded.',
    ];
    return [
        'ok' => true, 'status' => (string) $row['status'], 'reference' => (string) $row['public_reference'],
        'event_code' => (string) $row['event_code'], 'event_name' => (string) $row['event_name'],
        'event_date' => (string) $row['event_date_label'], 'package_code' => (string) $row['package_code'],
        'package_name' => (string) $row['package_name'],
        'package_quantity' => (int) $row['package_quantity'],
        'addons' => app_decode_registration_addons((string) $row['addons_json']),
        'amount_cents' => (int) $row['amount_cents'], 'currency' => (string) $row['currency'],
        'payer_name' => trim((string) $row['payer_first_name'] . ' ' . (string) $row['payer_last_name']),
        'payer_email' => (string) $row['payer_email'], 'participants' => $roster['participants'],
        'teams' => $roster['teams'],
        'ticket_groups' => $roster['ticket_groups'],
        'participant_capacity' => (int) $row['participant_capacity'],
        'benefit_description' => (string) $row['benefit_description'],
        'fair_market_value_cents' => (int) $row['fair_market_value_cents'],
        'max_deductible_cents' => (int) $row['deductible_amount_cents'],
        'receipt_url' => $row['receipt_url'] !== null ? (string) $row['receipt_url'] : null,
        'message' => $messages[$row['status']] ?? 'Please contact COMEC at 901-222-0700 for status.',
    ];
}

function app_load_registration_roster(PDO $pdo, int $registrationId): array
{
    $teamStatement = $pdo->prepare(
        'SELECT id,position,name,participant_capacity,addons_json FROM registration_teams '
        . 'WHERE registration_id=? ORDER BY position ASC'
    );
    $teamStatement->execute([$registrationId]);
    $teams = [];
    $teamIndexes = [];
    foreach ($teamStatement->fetchAll() as $team) {
        $teamId = (int) $team['id'];
        $teamIndexes[$teamId] = count($teams);
        $teams[] = [
            'position' => (int) $team['position'],
            'name' => (string) $team['name'],
            'participant_capacity' => (int) $team['participant_capacity'],
            'addons' => app_decode_team_addons((string) $team['addons_json']),
            'participants' => [],
        ];
    }

    $ticketGroupStatement = $pdo->prepare(
        'SELECT id,position,participant_capacity FROM registration_ticket_groups '
        . 'WHERE registration_id=? ORDER BY position ASC'
    );
    $ticketGroupStatement->execute([$registrationId]);
    $ticketGroups = [];
    $ticketGroupIndexes = [];
    foreach ($ticketGroupStatement->fetchAll() as $ticketGroup) {
        $ticketGroupId = (int) $ticketGroup['id'];
        $ticketGroupIndexes[$ticketGroupId] = count($ticketGroups);
        $ticketGroups[] = [
            'position' => (int) $ticketGroup['position'],
            'participant_capacity' => (int) $ticketGroup['participant_capacity'],
            'participants' => [],
        ];
    }

    $participantStatement = $pdo->prepare(
        'SELECT team_id,ticket_group_id,position,team_position,ticket_group_position,name FROM participants '
        . 'WHERE registration_id=? ORDER BY position ASC'
    );
    $participantStatement->execute([$registrationId]);
    $participants = [];
    foreach ($participantStatement->fetchAll() as $person) {
        $hasTeam = $person['team_id'] !== null;
        $hasTicketGroup = $person['ticket_group_id'] !== null;
        if ($hasTeam && $hasTicketGroup) {
            throw new RuntimeException('A participant cannot belong to both a team and a ticket group.');
        }
        if (!$hasTeam && !$hasTicketGroup) {
            $participants[] = [
                'position' => (int) $person['position'],
                'name' => (string) $person['name'],
            ];
            continue;
        }
        if ($hasTeam) {
            $teamId = (int) $person['team_id'];
            if (!array_key_exists($teamId, $teamIndexes)) {
                throw new RuntimeException('A participant is linked to an unavailable registration team.');
            }
            $teams[$teamIndexes[$teamId]]['participants'][] = [
                'position' => (int) $person['team_position'],
                'name' => (string) $person['name'],
            ];
            continue;
        }

        $ticketGroupId = (int) $person['ticket_group_id'];
        if (!array_key_exists($ticketGroupId, $ticketGroupIndexes)) {
            throw new RuntimeException('A participant is linked to an unavailable registration ticket group.');
        }
        $ticketGroups[$ticketGroupIndexes[$ticketGroupId]]['participants'][] = [
            'position' => (int) $person['ticket_group_position'],
            'name' => (string) $person['name'],
        ];
    }

    return ['teams' => $teams, 'ticket_groups' => $ticketGroups, 'participants' => $participants];
}

function app_decode_team_addons(string $json): array
{
    try {
        $addons = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }
    if (!is_array($addons) || !array_is_list($addons)) {
        return [];
    }
    return array_values(array_filter($addons, static fn (mixed $code): bool => is_string($code) && $code !== ''));
}

function app_decode_registration_addons(string $json): array
{
    try {
        $addons = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }
    return is_array($addons) && array_is_list($addons) ? $addons : [];
}

function app_base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function app_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}
