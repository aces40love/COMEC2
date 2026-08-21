<?php

declare(strict_types=1);

require_once __DIR__ . '/square.php';

function app_enqueue_paid_confirmation(PDO $pdo, array $registration, array $roster, array $payment): void
{
    $content = app_build_paid_confirmation_content($registration, $roster, $payment);
    app_enqueue_email(
        $pdo,
        (int) $registration['id'],
        'registration_paid',
        'registration-paid:' . $registration['id'],
        (string) $registration['payer_email'],
        $content['subject'],
        $content['text'],
        $content['html']
    );
}

function app_build_paid_confirmation_content(array $registration, array $roster, array $payment): array
{
    $reference = (string) $registration['public_reference'];
    $payerName = trim((string) $registration['payer_first_name'] . ' ' . (string) $registration['payer_last_name']);
    $gross = app_format_usd((int) $registration['amount_cents']);
    $disclosureMode = app_disclosure_mode_value($registration['disclosure_mode'] ?? null);
    $addons = app_decode_addons((string) $registration['addons_json']);
    $receiptUrl = app_email_receipt_url($payment['receipt_url'] ?? null);
    $paymentId = (string) ($payment['id'] ?? '');
    $paidAt = app_email_payment_time($payment['completed_at'] ?? null);
    $subject = $registration['event_name'] . ' registration confirmed - ' . $reference;
    $packageQuantity = max(1, (int) ($registration['package_quantity'] ?? 1));
    $rosterContent = app_email_roster_content($registration, $roster);
    $dateLabel = $disclosureMode === 'payment_confirmation_only'
        ? 'Payment date'
        : 'Payment/contribution date (UTC)';

    $text = "Hello {$payerName},\n\nThe Commission on Missing and Exploited Children (COMEC) "
        . "has confirmed your payment for {$registration['event_name']}.\n\n"
        . "Event: {$registration['event_name']}\nEvent date: {$registration['event_date_label']}\n"
        . "Payer: {$payerName}\n{$dateLabel}: {$paidAt}\n"
        . "Registration reference: {$reference}\nSquare payment ID: {$paymentId}\n"
        . "Package: {$registration['package_name']}\nPackage quantity: {$packageQuantity}\nGross payment: {$gross}\n";
    foreach ($addons as $addon) {
        $text .= 'Aggregate add-on: ' . app_email_addon_snapshot_label($addon) . "\n";
    }
    $text .= $rosterContent['text'];
    if ($receiptUrl !== null) {
        $text .= "Square receipt: {$receiptUrl}\n";
    }
    if ($disclosureMode === 'payment_confirmation_only') {
        $purchaseDescription = $registration['event_name'] . ' / ' . $registration['package_name']
            . ' / quantity ' . $packageQuantity;
        $text .= "\nPAYMENT CONFIRMATION — NOT A CHARITABLE-CONTRIBUTION ACKNOWLEDGMENT\n"
            . "COMEC received {$gross} on {$paidAt} for {$purchaseDescription}. "
            . 'This payment purchased the selected admissions, entries, and/or listed sponsorship-package benefits. '
            . 'COMEC has not represented any portion as a deductible charitable contribution. '
            . "Consult your tax adviser regarding your own tax treatment.\n\n"
            . "Keep this confirmation and contact COMEC at 901-222-0700 with registration questions.\n";
    } else {
        if ($registration['benefit_description'] === null
            || $registration['fair_market_value_cents'] === null
            || $registration['deductible_amount_cents'] === null) {
            throw new RuntimeException('The benefit/FMV email disclosure snapshot is incomplete.');
        }
        $fmv = app_format_usd((int) $registration['fair_market_value_cents']);
        $deductible = app_format_usd((int) $registration['deductible_amount_cents']);
        $text .= "\nQuid-pro-quo disclosure\n"
            . "Benefits provided in exchange for this payment: {$registration['benefit_description']}\n"
            . "Estimated fair market value of benefits: {$fmv}\n"
            . "Maximum amount potentially eligible for a charitable deduction (gross payment minus FMV): {$deductible}\n"
            . 'Any charitable deduction is limited to the excess of the gross payment over the fair market value of benefits, '
            . "subject to applicable law. Please consult your tax adviser.\n\n"
            . "Keep this confirmation and contact COMEC at 901-222-0700 with registration questions.\n";
    }

    $html = '<p>Hello ' . app_email_escape($payerName) . ',</p><p>The Commission on Missing and Exploited Children '
        . '(COMEC) has confirmed your payment for ' . app_email_escape((string) $registration['event_name'])
        . '.</p><dl><dt>Event</dt><dd>' . app_email_escape((string) $registration['event_name'])
        . '</dd><dt>Event date</dt><dd>' . app_email_escape((string) $registration['event_date_label'])
        . '</dd><dt>Payer</dt><dd>' . app_email_escape($payerName)
        . '</dd><dt>' . app_email_escape($dateLabel) . '</dt><dd>' . app_email_escape($paidAt)
        . '</dd><dt>Registration reference</dt><dd>' . app_email_escape($reference)
        . '</dd><dt>Square payment ID</dt><dd>' . app_email_escape($paymentId)
        . '</dd><dt>Package</dt><dd>' . app_email_escape((string) $registration['package_name'])
        . '</dd><dt>Package quantity</dt><dd>' . $packageQuantity
        . '</dd><dt>Gross payment</dt><dd>' . app_email_escape($gross) . '</dd></dl>';
    if ($addons !== []) {
        $html .= '<p><strong>Aggregate add-ons</strong></p><ul>';
        foreach ($addons as $addon) {
            $html .= '<li>' . app_email_escape(app_email_addon_snapshot_label($addon)) . '</li>';
        }
        $html .= '</ul>';
    }
    $html .= $rosterContent['html'];
    if ($receiptUrl !== null) {
        $html .= '<p><a href="' . app_email_escape($receiptUrl) . '">View your Square receipt</a></p>';
    }
    if ($disclosureMode === 'payment_confirmation_only') {
        $purchaseDescription = $registration['event_name'] . ' / ' . $registration['package_name']
            . ' / quantity ' . $packageQuantity;
        $html .= '<h2>PAYMENT CONFIRMATION — NOT A CHARITABLE-CONTRIBUTION ACKNOWLEDGMENT</h2><p>COMEC received '
            . '<strong>' . app_email_escape($gross) . '</strong> on ' . app_email_escape($paidAt) . ' for '
            . app_email_escape($purchaseDescription) . '. This payment purchased the selected admissions, entries, '
            . 'and/or listed sponsorship-package benefits. COMEC has not represented any portion as a deductible '
            . 'charitable contribution. Consult your tax adviser regarding your own tax treatment.</p>'
            . '<p>Keep this confirmation and contact COMEC at 901-222-0700 with registration questions.</p>';
    } else {
        $html .= '<h2>Quid-pro-quo disclosure</h2><p>Benefits provided in exchange for this payment: '
            . app_email_escape((string) $registration['benefit_description']) . '</p><p>Estimated fair market value of benefits: <strong>'
            . app_email_escape($fmv) . '</strong><br>Maximum amount potentially eligible for a charitable deduction '
            . '(gross payment minus FMV): <strong>' . app_email_escape($deductible) . '</strong></p>'
            . '<p>Any charitable deduction is limited to the excess of the gross payment over the fair market value of benefits, '
            . 'subject to applicable law. Please consult your tax adviser.</p><p>Keep this confirmation and contact COMEC at '
            . '901-222-0700 with registration questions.</p>';
    }

    return ['subject' => $subject, 'text' => $text, 'html' => $html];
}

function app_enqueue_internal_paid_notifications(PDO $pdo, array $registration, array $roster, array $payment): void
{
    $recipients = app_config('INTERNAL_NOTIFICATION_RECIPIENTS', []);
    if (!is_array($recipients) || $recipients === []) {
        throw new RuntimeException('INTERNAL_NOTIFICATION_RECIPIENTS must contain at least one address.');
    }
    $content = app_build_internal_paid_content($registration, $roster, $payment);
    foreach (array_values(array_unique($recipients)) as $recipient) {
        if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('An internal notification recipient is invalid.');
        }
        $recipient = strtolower($recipient);
        app_enqueue_email($pdo, (int) $registration['id'], 'internal_registration_paid',
            'internal-paid:' . $registration['id'] . ':' . hash('sha256', $recipient),
            $recipient, $content['subject'], $content['text'], $content['html']);
    }
}

function app_build_internal_paid_content(array $registration, array $roster, array $payment): array
{
    $eventShort = app_event_definitions()[$registration['event_code']]['short_name'] ?? 'Event';
    $payerName = trim((string) $registration['payer_first_name'] . ' ' . (string) $registration['payer_last_name']);
    $subject = 'PAID - ' . $eventShort . ' - ' . $registration['public_reference'] . ' - ' . $payerName;
    $contestCode = (string) ($registration['contest_choice'] ?? '');
    $contest = app_contest_choices()[$contestCode] ?? $contestCode;
    $receipt = app_email_receipt_url($payment['receipt_url'] ?? null);
    $paidAt = app_email_payment_time($payment['completed_at'] ?? null);
    $packageQuantity = max(1, (int) ($registration['package_quantity'] ?? 1));
    $rosterContent = app_email_roster_content($registration, $roster);
    $lines = [
        'Event: ' . $registration['event_name'], 'Event date: ' . $registration['event_date_label'],
        'Registration reference: ' . $registration['public_reference'], 'Paid timestamp (UTC): ' . $paidAt,
        'Package: ' . $registration['package_name'], 'Package quantity: ' . $packageQuantity,
        'Gross amount: ' . app_format_usd((int) $registration['amount_cents']),
    ];
    foreach (app_decode_addons((string) $registration['addons_json']) as $addon) {
        $lines[] = 'Aggregate add-on: ' . app_email_addon_snapshot_label($addon);
    }
    array_push($lines,
        'Payer/contact: ' . $payerName,
        'Company: ' . ((string) ($registration['payer_company'] ?? '') ?: '(none)'),
        'Email: ' . $registration['payer_email'], 'Phone: ' . $registration['payer_phone'],
        'Mailing address: ' . $registration['payer_address_line1'] . ', ' . $registration['payer_city'] . ', '
            . $registration['payer_state'] . ' ' . $registration['payer_postal_code'],
        'Sponsor display: ' . ((string) ($registration['sponsor_display'] ?? '') ?: '(none)'),
        'Contest: ' . ($contest !== '' ? $contest : '(none)'),
        'Notes: ' . ((string) ($registration['notes'] ?? '') ?: '(none)'),
        'Square payment ID: ' . (string) $payment['id'], 'Square receipt: ' . ($receipt ?? '(not supplied)')
    );
    $text = implode("\n", $lines) . "\n" . $rosterContent['text'];
    $html = '<pre style="font-family:Arial,sans-serif;white-space:pre-wrap">' . app_email_escape($text) . '</pre>';
    return ['subject' => $subject, 'text' => $text, 'html' => $html];
}

function app_enqueue_refund_confirmation(PDO $pdo, array $registration, int $refundedCents, bool $fullyRefunded): void
{
    $reference = (string) $registration['public_reference'];
    $name = trim((string) $registration['payer_first_name'] . ' ' . (string) $registration['payer_last_name']);
    $kind = $fullyRefunded ? 'refund' : 'partial refund';
    $text = "Hello {$name},\n\nA {$kind} totaling " . app_format_usd($refundedCents)
        . " has been recorded for registration {$reference}. Contact COMEC at 901-222-0700 with questions.\n";
    $html = '<p>' . app_email_escape($text) . '</p>';
    app_enqueue_email($pdo, (int) $registration['id'], $fullyRefunded ? 'registration_refunded' : 'registration_partially_refunded',
        sprintf('registration-refund:%d:%d', (int) $registration['id'], $refundedCents),
        (string) $registration['payer_email'], 'COMEC event registration ' . $kind . ' - ' . $reference, $text, $html);
}

function app_enqueue_internal_refund_notifications(
    PDO $pdo,
    array $registration,
    int $refundedCents,
    bool $fullyRefunded,
    array $payment
): void {
    $recipients = app_config('INTERNAL_NOTIFICATION_RECIPIENTS', []);
    if (!is_array($recipients) || $recipients === []) {
        throw new RuntimeException('INTERNAL_NOTIFICATION_RECIPIENTS must contain at least one address.');
    }
    $eventShort = app_event_definitions()[$registration['event_code']]['short_name'] ?? 'Event';
    $payerName = trim((string) $registration['payer_first_name'] . ' ' . (string) $registration['payer_last_name']);
    $statusLabel = $fullyRefunded ? 'FULL REFUND' : 'PARTIAL REFUND';
    $subject = $statusLabel . ' - ' . $eventShort . ' - ' . $registration['public_reference'] . ' - ' . $payerName;
    $receipt = app_email_receipt_url($payment['receipt_url'] ?? null);
    $lines = [
        'Status: ' . $statusLabel,
        'Event: ' . $registration['event_name'],
        'Event date: ' . $registration['event_date_label'],
        'Registration: ' . $registration['public_reference'],
        'Payer/contact: ' . $payerName,
        'Payer email: ' . $registration['payer_email'],
        'Cumulative refund total: ' . app_format_usd($refundedCents),
        'Original gross payment: ' . app_format_usd((int) $registration['amount_cents']),
        'Square payment ID: ' . (string) ($payment['id'] ?? '(not supplied)'),
        'Square receipt: ' . ($receipt ?? '(not supplied)'),
    ];
    $text = implode("\n", $lines) . "\n";
    $html = '<pre style="font-family:Arial,sans-serif;white-space:pre-wrap">' . app_email_escape($text) . '</pre>';

    foreach (array_values(array_unique($recipients)) as $recipient) {
        if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('An internal notification recipient is invalid.');
        }
        $recipient = strtolower($recipient);
        app_enqueue_email(
            $pdo,
            (int) $registration['id'],
            'internal_registration_refund',
            'internal-refund:' . $registration['id'] . ':' . $refundedCents . ':' . hash('sha256', $recipient),
            $recipient,
            $subject,
            $text,
            $html
        );
    }
}

function app_enqueue_email(PDO $pdo, int $registrationId, string $messageType, string $idempotencyKey,
    string $recipient, string $subject, string $textBody, string $htmlBody): void
{
    $statement = $pdo->prepare(
        'INSERT INTO email_outbox (registration_id, message_type, idempotency_key, recipient_email, subject, text_body, '
        . "html_body, status, attempts, available_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, "
        . 'UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id = id'
    );
    $statement->execute([$registrationId, $messageType, $idempotencyKey, $recipient, $subject, $textBody, $htmlBody]);
}

function app_email_roster_content(array $registration, array $roster): array
{
    $teams = isset($roster['teams']) && is_array($roster['teams']) && array_is_list($roster['teams'])
        ? $roster['teams'] : [];
    $participants = isset($roster['participants'])
        && is_array($roster['participants'])
        && array_is_list($roster['participants'])
        ? $roster['participants'] : [];
    $ticketGroups = isset($roster['ticket_groups'])
        && is_array($roster['ticket_groups'])
        && array_is_list($roster['ticket_groups'])
        ? $roster['ticket_groups'] : [];
    $capacity = (int) ($registration['participant_capacity'] ?? 0);
    $text = '';
    $html = '';

    if ($teams !== []) {
        $totalGolfers = 0;
        foreach ($teams as $team) {
            $teamParticipants = is_array($team) && isset($team['participants']) && is_array($team['participants'])
                ? $team['participants'] : [];
            $totalGolfers += count($teamParticipants);
        }
        $text .= 'Team count: ' . count($teams) . "\n"
            . 'Total golfers: ' . $totalGolfers . "\n"
            . 'Package capacity: ' . $capacity . "\nTeams and golfers:\n";
        $html .= '<dl><dt>Team count</dt><dd>' . count($teams)
            . '</dd><dt>Total golfers</dt><dd>' . $totalGolfers
            . '</dd><dt>Package capacity</dt><dd>' . $capacity . '</dd></dl><h2>Teams and golfers</h2>';
        foreach ($teams as $teamIndex => $team) {
            if (!is_array($team)) {
                continue;
            }
            $position = max(1, (int) ($team['position'] ?? ($teamIndex + 1)));
            $name = (string) ($team['name'] ?? 'Unnamed team');
            $text .= 'Team ' . $position . ': ' . $name . "\n";
            $html .= '<section><h3>Team ' . $position . ': ' . app_email_escape($name) . '</h3>';
            $teamParticipants = isset($team['participants']) && is_array($team['participants'])
                ? $team['participants'] : [];
            if ($teamParticipants !== []) {
                $html .= '<ul>';
                foreach ($teamParticipants as $participant) {
                    if (!is_array($participant)) {
                        continue;
                    }
                    $participantName = (string) ($participant['name'] ?? '');
                    $text .= '- ' . $participantName . "\n";
                    $html .= '<li>' . app_email_escape($participantName) . '</li>';
                }
                $html .= '</ul>';
            }
            $teamAddons = isset($team['addons']) && is_array($team['addons']) ? $team['addons'] : [];
            foreach ($teamAddons as $addonCode) {
                if (!is_string($addonCode)) {
                    continue;
                }
                $addonName = (string) (app_registration_addons()[$addonCode]['name'] ?? $addonCode);
                $text .= 'Team add-on: ' . $addonName . "\n";
                $html .= '<p><strong>Team add-on:</strong> ' . app_email_escape($addonName) . '</p>';
            }
            $html .= '</section>';
        }
        return ['text' => $text, 'html' => $html, 'number_attending' => $totalGolfers, 'team_count' => count($teams)];
    }

    if ($ticketGroups !== []) {
        $totalGuests = 0;
        foreach ($ticketGroups as $ticketGroup) {
            $ticketParticipants = is_array($ticketGroup)
                && isset($ticketGroup['participants'])
                && is_array($ticketGroup['participants'])
                ? $ticketGroup['participants'] : [];
            foreach ($ticketParticipants as $participant) {
                if (is_array($participant)) {
                    $totalGuests++;
                }
            }
        }
        $isCouplePackage = in_array(
            (string) ($registration['package_code'] ?? ''),
            ['gala_couple', 'gala_vip_couple'],
            true
        );
        $ticketLabel = $isCouplePackage ? 'Couple package' : 'Ticket';
        $text .= 'Ticket package count: ' . count($ticketGroups) . "\n"
            . 'Total guests attending: ' . $totalGuests . "\n"
            . 'Package capacity: ' . $capacity . "\nTickets and guests:\n";
        $html .= '<dl><dt>Ticket package count</dt><dd>' . count($ticketGroups)
            . '</dd><dt>Total guests attending</dt><dd>' . $totalGuests
            . '</dd><dt>Package capacity</dt><dd>' . $capacity . '</dd></dl><h2>Tickets and guests</h2>';
        foreach ($ticketGroups as $ticketGroupIndex => $ticketGroup) {
            if (!is_array($ticketGroup)) {
                continue;
            }
            $position = max(1, (int) ($ticketGroup['position'] ?? ($ticketGroupIndex + 1)));
            $heading = $ticketLabel . ' ' . $position;
            $text .= $heading . ":\n";
            $html .= '<section><h3>' . app_email_escape($heading) . '</h3>';
            $ticketParticipants = isset($ticketGroup['participants']) && is_array($ticketGroup['participants'])
                ? $ticketGroup['participants'] : [];
            if ($ticketParticipants !== []) {
                $html .= '<ul>';
                foreach ($ticketParticipants as $participant) {
                    if (!is_array($participant)) {
                        continue;
                    }
                    $participantName = (string) ($participant['name'] ?? '');
                    $text .= '- ' . $participantName . "\n";
                    $html .= '<li>' . app_email_escape($participantName) . '</li>';
                }
                $html .= '</ul>';
            }
            $html .= '</section>';
        }
        return [
            'text' => $text,
            'html' => $html,
            'number_attending' => $totalGuests,
            'team_count' => 0,
            'ticket_group_count' => count($ticketGroups),
        ];
    }

    $numberAttending = count($participants);
    $attendeeNamesLabel = $registration['event_code'] === 'gala-2026' ? 'Guest names' : 'Golfer names';
    $text .= 'Number attending: ' . $numberAttending . "\n"
        . 'Package capacity: ' . $capacity . "\n";
    $html .= '<dl><dt>Number attending</dt><dd>' . $numberAttending
        . '</dd><dt>Package capacity</dt><dd>' . $capacity . '</dd></dl>';
    if ($participants !== []) {
        $text .= $attendeeNamesLabel . ":\n";
        $html .= '<p><strong>' . app_email_escape($attendeeNamesLabel) . '</strong></p><ul>';
        foreach ($participants as $participant) {
            if (!is_array($participant)) {
                continue;
            }
            $name = (string) ($participant['name'] ?? '');
            $text .= '- ' . $name . "\n";
            $html .= '<li>' . app_email_escape($name) . '</li>';
        }
        $html .= '</ul>';
    }
    return ['text' => $text, 'html' => $html, 'number_attending' => $numberAttending, 'team_count' => 0];
}

function app_email_addon_snapshot_label(array $addon): string
{
    $name = trim((string) ($addon['name'] ?? 'Add-on')) ?: 'Add-on';
    $unitAmountCents = max(0, (int) ($addon['unit_amount_cents'] ?? 0));
    $quantity = max(1, (int) ($addon['quantity'] ?? 1));
    $totalAmountCents = max(0, (int) ($addon['total_amount_cents'] ?? ($unitAmountCents * $quantity)));
    return $name . ' — ' . app_format_usd($unitAmountCents) . ' each × ' . $quantity
        . ' = ' . app_format_usd($totalAmountCents);
}

function app_decode_addons(string $json): array
{
    try {
        $addons = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }
    return is_array($addons) && array_is_list($addons) ? $addons : [];
}

function app_email_receipt_url(mixed $url): ?string
{
    return app_validated_square_receipt_url($url);
}

function app_email_payment_time(mixed $value): string
{
    try {
        return is_string($value) && $value !== ''
            ? (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s T')
            : gmdate('Y-m-d H:i:s') . ' UTC';
    } catch (Throwable) {
        return gmdate('Y-m-d H:i:s') . ' UTC';
    }
}

function app_email_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_format_usd(int $cents): string
{
    return '$' . number_format($cents / 100, 2, '.', ',');
}
