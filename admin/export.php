<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/app/packages.php';

admin_require_auth();

try {
    $registrations = admin_registration_query(app_db(), admin_filters(), null);
} catch (Throwable $exception) {
    app_log('error', 'Registration CSV export failed', ['exception' => get_class($exception)]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "The registration export could not be generated.\n";
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="comec-event-registrations-' . gmdate('Y-m-d-His') . '.csv"');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$stream = fopen('php://output', 'wb');
if ($stream === false) {
    http_response_code(500);
    exit;
}

fwrite($stream, "\xEF\xBB\xBF");
fputcsv($stream, [
    'Reference', 'Event code', 'Event', 'Event date', 'Status', 'Created UTC', 'Paid UTC',
    'Package', 'Package unit amount', 'Package quantity', 'Team count', 'Ticket package count', 'Add-ons',
    'Base amount', 'Add-on amount', 'Registration total', 'Net received', 'Disclosure mode',
    'Benefits FMV', 'Maximum deductible amount', 'Refunded', 'Payer', 'Company', 'Email', 'Phone',
    'Address', 'City', 'State', 'ZIP', 'Sponsor display', 'Contest',
    'Number attending', 'Package capacity', 'Team rosters', 'Gala ticket groups', 'Flat attendee names',
    'Notes', 'Square order ID', 'Square payment ID', 'Receipt URL',
], ',', '"', '');

foreach ($registrations as $registration) {
    $participants = array_column($registration['participants'], 'name');
    $teams = $registration['teams'];
    $ticketGroups = $registration['ticket_groups'];
    $teamParticipantCount = array_sum(array_map(
        static fn (array $team): int => count($team['participants']),
        $teams
    ));
    $ticketGroupParticipantCount = array_sum(array_map(
        static fn (array $ticketGroup): int => count($ticketGroup['participants']),
        $ticketGroups
    ));
    $addons = array_map(
        static function (array $addon): string {
            return sprintf(
                '%s x %d (%s each; %s total)',
                $addon['name'],
                $addon['quantity'],
                admin_money((int) $addon['unit_amount_cents']),
                admin_money((int) $addon['total_amount_cents'])
            );
        },
        admin_registration_addons($registration['addons_json'] ?? '')
    );
    $teamRosters = [];
    foreach ($teams as $team) {
        $teamAddons = array_column($team['addons'], 'name');
        $teamRosters[] = sprintf(
            'Team %d - %s | Golfers: %s | Mulligans: %s',
            (int) $team['position'],
            (string) $team['name'],
            $team['participants'] !== []
                ? implode('; ', array_column($team['participants'], 'name'))
                : 'None',
            $teamAddons !== [] ? implode('; ', $teamAddons) : 'None'
        );
    }
    $isCouplePackage = in_array(
        $registration['package_code'],
        ['gala_couple', 'gala_vip_couple'],
        true
    );
    $galaTicketGroups = [];
    foreach ($ticketGroups as $ticketGroup) {
        $galaTicketGroups[] = sprintf(
            '%s %d | Guests: %s',
            $isCouplePackage ? 'Couple package' : 'Ticket',
            (int) $ticketGroup['position'],
            $ticketGroup['participants'] !== []
                ? implode('; ', array_column($ticketGroup['participants'], 'name'))
                : 'None'
        );
    }
    $refundedCents = (int) ($registration['refunded_amount_cents'] ?? 0);
    $netReceived = in_array($registration['status'], ['paid', 'partially_refunded', 'refunded'], true)
        ? number_format(max(0, (int) $registration['amount_cents'] - $refundedCents) / 100, 2, '.', '')
        : '';
    $isBenefitFmv = ($registration['disclosure_mode'] ?? null) === 'benefit_fmv';
    $row = [
        $registration['public_reference'],
        $registration['event_code'],
        $registration['event_name'],
        $registration['event_date_label'],
        $registration['status'],
        $registration['created_at'],
        $registration['paid_at'],
        $registration['package_name'],
        number_format((int) $registration['package_unit_amount_cents'] / 100, 2, '.', ''),
        $registration['package_quantity'],
        count($teams),
        count($ticketGroups),
        implode('; ', $addons),
        number_format((int) $registration['base_amount_cents'] / 100, 2, '.', ''),
        number_format((int) $registration['addon_amount_cents'] / 100, 2, '.', ''),
        number_format((int) $registration['amount_cents'] / 100, 2, '.', ''),
        $netReceived,
        $registration['disclosure_mode'] ?? '',
        $isBenefitFmv && $registration['fair_market_value_cents'] !== null
            ? number_format((int) $registration['fair_market_value_cents'] / 100, 2, '.', '')
            : '',
        $isBenefitFmv && $registration['deductible_amount_cents'] !== null
            ? number_format((int) $registration['deductible_amount_cents'] / 100, 2, '.', '')
            : '',
        number_format($refundedCents / 100, 2, '.', ''),
        trim($registration['payer_first_name'] . ' ' . $registration['payer_last_name']),
        $registration['payer_company'],
        $registration['payer_email'],
        $registration['payer_phone'],
        $registration['payer_address_line1'],
        $registration['payer_city'],
        $registration['payer_state'],
        $registration['payer_postal_code'],
        $registration['sponsor_display'],
        $registration['contest_choice'],
        count($participants) + $teamParticipantCount + $ticketGroupParticipantCount,
        $registration['participant_capacity'],
        implode(' || ', $teamRosters),
        implode(' || ', $galaTicketGroups),
        implode('; ', $participants),
        $registration['notes'],
        $registration['square_order_id'],
        $registration['square_payment_id'],
        admin_safe_square_receipt_url($registration['receipt_url'] ?? null),
    ];
    fputcsv($stream, array_map('admin_csv_cell', $row), ',', '"', '');
}

fclose($stream);

function admin_csv_cell(mixed $value): string
{
    $value = (string) ($value ?? '');
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
        return "'" . $value;
    }
    return $value;
}
