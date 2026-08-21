<?php

declare(strict_types=1);

/**
 * Dependency-free contract tests for COMEC's event registration code.
 *
 * Run from the site root with:
 *   php tests/run.php
 */

$siteRoot = dirname(__DIR__);

// Configuration is cached the first time app_config() is called, so all test-only
// values must be installed before any application file is loaded.
$testBenefits = [
    'corporate_sponsor' => ['description' => 'Corporate sponsor benefits', 'fair_market_value_cents' => 42000],
    'contest_sponsor' => ['description' => 'Contest sponsor recognition', 'fair_market_value_cents' => 5000],
    'drink_cart_sponsor' => ['description' => 'Drink-cart sponsor recognition', 'fair_market_value_cents' => 5000],
    'team_sponsor' => ['description' => 'Golf team and sponsor benefits', 'fair_market_value_cents' => 36000],
    'hole_sponsor' => ['description' => 'Hole sponsor recognition', 'fair_market_value_cents' => 2500],
    'individual_player' => ['description' => 'Golf player benefits', 'fair_market_value_cents' => 10000],
    'team_mulligans' => ['description' => 'Eight team mulligans', 'fair_market_value_cents' => 4000],
    'gala_single' => ['description' => 'One gala admission', 'fair_market_value_cents' => 6000],
    'gala_couple' => ['description' => 'Two gala admissions', 'fair_market_value_cents' => 12000],
    'gala_vip_single' => ['description' => 'One VIP gala admission', 'fair_market_value_cents' => 10000],
    'gala_vip_couple' => ['description' => 'Two VIP gala admissions', 'fair_market_value_cents' => 20000],
];

$testAppKey = 'test-only-app-key-0123456789-abcdef-0123456789';
$testWebhookKey = 'test-only-square-webhook-signature-key';
$testWebhookUrl = 'https://tests.comec.invalid/api/square-webhook.php';

putenv('COMEC_CONFIG_FILE');
putenv('APP_ENV=test');
putenv('APP_URL=https://tests.comec.invalid');
putenv('APP_KEY=' . $testAppKey);
putenv('RATE_LIMIT_SECRET=test-only-rate-limit-secret');
putenv('HTTPS_ONLY=false');
putenv('SQUARE_WEBHOOK_SIGNATURE_KEY=' . $testWebhookKey);
putenv('SQUARE_WEBHOOK_NOTIFICATION_URL=' . $testWebhookUrl);
putenv('EVENT_DISCLOSURE_MODE=benefit_fmv');
putenv('PACKAGE_BENEFITS_JSON=' . json_encode($testBenefits, JSON_THROW_ON_ERROR));

require_once $siteRoot . '/app/bootstrap.php';
require_once $siteRoot . '/app/packages.php';
require_once $siteRoot . '/app/registrations.php';
require_once $siteRoot . '/app/webhooks.php';
require_once $siteRoot . '/app/mailer.php';

final class TestFailure extends RuntimeException
{
}

function test_fail(string $message): never
{
    throw new TestFailure($message);
}

function test_assert_true(bool $condition, string $message = 'Expected true.'): void
{
    if (!$condition) {
        test_fail($message);
    }
}

function test_assert_false(bool $condition, string $message = 'Expected false.'): void
{
    if ($condition) {
        test_fail($message);
    }
}

function test_assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $prefix = $message !== '' ? $message . ' ' : '';
        test_fail($prefix . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

/** @return ApiException */
function test_expect_api_exception(callable $callback, string $errorCode): ApiException
{
    try {
        $callback();
    } catch (ApiException $exception) {
        test_assert_same($errorCode, $exception->errorCode, 'Unexpected API error code.');
        return $exception;
    }
    test_fail('Expected ApiException with code ' . $errorCode . '.');
}

function test_expect_runtime_exception(callable $callback): RuntimeException
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        return $exception;
    }
    test_fail('Expected RuntimeException.');
}

/**
 * Produces a valid request for any configured event/package. Optional values
 * can then be mutated by an individual test.
 */
function test_registration_input(
    string $eventCode,
    string $packageCode,
    ?int $participantCount = null,
    array $addons = []
): array {
    $events = app_event_definitions();
    $package = $events[$eventCode]['packages'][$packageCode];
    $participantCount ??= (int) $package['participant_min'];
    $participants = [];
    $teams = [];
    $ticketGroups = [];
    if (($package['team_package'] ?? false) === true) {
        $teamParticipants = [];
        for ($index = 1; $index <= $participantCount; $index++) {
            $teamParticipants[] = ['name' => 'Team 1 Golfer ' . $index];
        }
        $teams[] = [
            'name' => 'COMEC Champions 1',
            'participants' => $teamParticipants,
            'addons' => $addons,
        ];
        $addons = [];
    } elseif (($package['ticket_group_package'] ?? false) === true) {
        $ticketGroupParticipants = [];
        for ($index = 1; $index <= $participantCount; $index++) {
            $ticketGroupParticipants[] = ['name' => 'Guest ' . $index];
        }
        $ticketGroups[] = ['participants' => $ticketGroupParticipants];
    } else {
        for ($index = 1; $index <= $participantCount; $index++) {
            $participants[] = ['name' => 'Guest ' . $index];
        }
    }

    return [
        'event_code' => $eventCode,
        'package_code' => $packageCode,
        'payer' => [
            'first_name' => 'Pat',
            'last_name' => 'Payer',
            'company' => 'Example Company',
            'email' => 'pat@example.org',
            'phone' => '(901) 555-0199',
            'address_line1' => '123 Main Street',
            'city' => 'Memphis',
            'state' => 'tn',
            'postal_code' => '38103',
        ],
        'registration' => [
            'team_name' => $package['requires_team_name'] ? 'COMEC Champions' : '',
            'sponsor_display' => $package['requires_sponsor_display'] ? 'Example Company' : '',
            'contest_choice' => $package['requires_contest_choice'] ? 'closest_to_pin' : '',
            'notes' => '',
        ],
        'participants' => $participants,
        'addons' => $addons,
        'teams' => $teams,
        'ticket_groups' => $ticketGroups,
        'consent' => true,
        'website' => '',
    ];
}

function test_ticket_group_registration_input(string $packageCode, array $participantCounts): array
{
    $input = test_registration_input('gala-2026', $packageCode, 1);
    $input['ticket_groups'] = [];
    foreach ($participantCounts as $ticketGroupIndex => $participantCount) {
        $participants = [];
        for ($participantIndex = 1; $participantIndex <= $participantCount; $participantIndex++) {
            $participants[] = [
                'name' => sprintf('Ticket Group %d Guest %d', $ticketGroupIndex + 1, $participantIndex),
            ];
        }
        $input['ticket_groups'][] = ['participants' => $participants];
    }
    return $input;
}

function test_team_registration_input(
    string $packageCode,
    array $participantCounts,
    array $mulliganTeamIndexes = []
): array {
    $input = test_registration_input('golf-2026', $packageCode, 1);
    $input['teams'] = [];
    foreach ($participantCounts as $teamIndex => $participantCount) {
        $participants = [];
        for ($participantIndex = 1; $participantIndex <= $participantCount; $participantIndex++) {
            $participants[] = [
                'name' => sprintf('Team %d Golfer %d', $teamIndex + 1, $participantIndex),
            ];
        }
        $input['teams'][] = [
            'name' => 'COMEC Champions ' . ($teamIndex + 1),
            'participants' => $participants,
            'addons' => in_array($teamIndex, $mulliganTeamIndexes, true) ? ['team_mulligans'] : [],
        ];
    }
    return $input;
}

function test_square_snapshot(array $validated): array
{
    $addons = [];
    foreach ($validated['addons'] as $code => $addon) {
        $addons[] = ['code' => $code, ...$addon];
    }
    return [
        'event_code' => $validated['event_code'],
        'package_code' => $validated['package_code'],
        'package_name' => $validated['package']['name'],
        'package_quantity' => $validated['package_quantity'],
        'package_unit_amount_cents' => $validated['package_unit_amount_cents'],
        'base_amount_cents' => $validated['base_amount_cents'],
        'addon_amount_cents' => $validated['addon_amount_cents'],
        'amount_cents' => $validated['amount_cents'],
        'participant_capacity' => $validated['participant_capacity'],
        'addons_json' => json_encode($addons, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ];
}

function test_roster_from_validated(array $validated): array
{
    $teams = [];
    foreach ($validated['teams'] as $teamIndex => $team) {
        $participants = [];
        foreach ($team['participants'] as $participantIndex => $participant) {
            $participants[] = ['position' => $participantIndex + 1, 'name' => $participant['name']];
        }
        $teams[] = [
            'position' => $teamIndex + 1,
            'name' => $team['name'],
            'participant_capacity' => 4,
            'addons' => $team['addons'],
            'participants' => $participants,
        ];
    }
    $participants = [];
    foreach ($validated['participants'] as $participantIndex => $participant) {
        $participants[] = ['position' => $participantIndex + 1, 'name' => $participant['name']];
    }
    $ticketGroups = [];
    foreach ($validated['ticket_groups'] as $ticketGroupIndex => $ticketGroup) {
        $ticketGroupParticipants = [];
        foreach ($ticketGroup['participants'] as $participantIndex => $participant) {
            $ticketGroupParticipants[] = ['position' => $participantIndex + 1, 'name' => $participant['name']];
        }
        $ticketGroups[] = [
            'position' => $ticketGroupIndex + 1,
            'participant_capacity' => (int) $validated['package']['participant_count'],
            'participants' => $ticketGroupParticipants,
        ];
    }
    return ['teams' => $teams, 'ticket_groups' => $ticketGroups, 'participants' => $participants];
}

function test_email_registration(array $validated): array
{
    return test_square_snapshot($validated) + [
        'id' => 42,
        'public_reference' => 'GOLF26-TEST123',
        'event_name' => $validated['event']['name'],
        'event_date_label' => $validated['event']['date'],
        'payer_first_name' => $validated['payer']['first_name'],
        'payer_last_name' => $validated['payer']['last_name'],
        'payer_company' => $validated['payer']['company'],
        'payer_email' => $validated['payer']['email'],
        'payer_phone' => $validated['payer']['phone'],
        'payer_address_line1' => $validated['payer']['address_line1'],
        'payer_city' => $validated['payer']['city'],
        'payer_state' => $validated['payer']['state'],
        'payer_postal_code' => $validated['payer']['postal_code'],
        'disclosure_mode' => $validated['disclosure_mode'],
        'benefit_description' => $validated['benefit']['description'],
        'fair_market_value_cents' => $validated['benefit']['fair_market_value_cents'],
        'deductible_amount_cents' => $validated['benefit']['deductible_amount_cents'],
        'sponsor_display' => $validated['registration']['sponsor_display'],
        'contest_choice' => $validated['registration']['contest_choice'],
        'notes' => $validated['registration']['notes'],
    ];
}

$tests = [];

$tests['golf package prices and participant ranges are exact'] = static function (): void {
    $expected = [
        'corporate_sponsor' => [100000, 1, 4],
        'contest_sponsor' => [50000, 0, 0],
        'drink_cart_sponsor' => [50000, 0, 0],
        'team_sponsor' => [40000, 1, 4],
        'hole_sponsor' => [25000, 0, 0],
        'individual_player' => [10000, 1, 1],
    ];
    $packages = app_event_definitions()['golf-2026']['packages'];
    test_assert_same(array_keys($expected), array_keys($packages), 'Golf package set changed.');
    foreach ($expected as $code => [$price, $minimum, $maximum]) {
        test_assert_same($price, $packages[$code]['amount_cents'], $code . ' price mismatch.');
        test_assert_same($minimum, $packages[$code]['participant_min'], $code . ' minimum mismatch.');
        test_assert_same($maximum, $packages[$code]['participant_count'], $code . ' maximum mismatch.');
    }
};

$tests['gala package prices and named-attendee ranges are exact'] = static function (): void {
    $expected = [
        'gala_single' => [13500, 1, 1],
        'gala_couple' => [25000, 1, 2],
        'gala_vip_single' => [17500, 1, 1],
        'gala_vip_couple' => [30000, 1, 2],
    ];
    $packages = app_event_definitions()['gala-2026']['packages'];
    test_assert_same(array_keys($expected), array_keys($packages), 'Gala package set changed.');
    foreach ($expected as $code => [$price, $minimum, $capacity]) {
        test_assert_same($price, $packages[$code]['amount_cents'], $code . ' price mismatch.');
        test_assert_same($minimum, $packages[$code]['participant_min'], $code . ' minimum mismatch.');
        test_assert_same($capacity, $packages[$code]['participant_count'], $code . ' capacity mismatch.');
        test_assert_true(($packages[$code]['ticket_group_package'] ?? false) === true);
        test_assert_same(1, $packages[$code]['ticket_group_min']);
        test_assert_same(10, $packages[$code]['ticket_group_max']);
    }
};

$tests['every event package accepts its required number of named attendees'] = static function (): void {
    foreach (app_event_definitions() as $eventCode => $event) {
        foreach ($event['packages'] as $packageCode => $package) {
            $validated = app_validate_registration_payload(test_registration_input($eventCode, $packageCode));
            test_assert_same($eventCode, $validated['event_code']);
            test_assert_same($packageCode, $validated['package_code']);
            if (($package['team_package'] ?? false) === true) {
                test_assert_same(1, count($validated['teams']));
                test_assert_same((int) $package['participant_min'], count($validated['teams'][0]['participants']));
                test_assert_same([], $validated['participants']);
                test_assert_same([], $validated['ticket_groups']);
            } elseif (($package['ticket_group_package'] ?? false) === true) {
                test_assert_same(1, count($validated['ticket_groups']));
                test_assert_same(
                    (int) $package['participant_min'],
                    count($validated['ticket_groups'][0]['participants'])
                );
                test_assert_same([], $validated['participants']);
                test_assert_same([], $validated['teams']);
            } else {
                test_assert_same((int) $package['participant_min'], count($validated['participants']));
                test_assert_same([], $validated['teams']);
                test_assert_same([], $validated['ticket_groups']);
            }
        }
    }
};

$tests['participant count boundaries are enforced for teams, tickets, and sponsor-only packages'] = static function (): void {
    foreach ([1, 4] as $count) {
        $validated = app_validate_registration_payload(
            test_registration_input('golf-2026', 'team_sponsor', $count)
        );
        test_assert_same($count, count($validated['teams'][0]['participants']));
        test_assert_same([], $validated['participants']);
    }

    foreach (['gala_couple', 'gala_vip_couple'] as $packageCode) {
        foreach ([1, 2] as $count) {
            $validated = app_validate_registration_payload(
                test_registration_input('gala-2026', $packageCode, $count)
            );
            test_assert_same($count, count($validated['ticket_groups'][0]['participants']));
            test_assert_same([], $validated['participants']);
            test_assert_same(2, $validated['package']['participant_count'], 'Couple capacity must remain two.');
        }
    }

    foreach ([0, 5] as $count) {
        $exception = test_expect_api_exception(
            static fn (): array => app_validate_registration_payload(
                test_registration_input('golf-2026', 'team_sponsor', $count)
            ),
            'validation_failed'
        );
        test_assert_true(isset($exception->details['teams.0.participants']));
    }

    foreach ([
        ['golf-2026', 'individual_player', 0],
        ['golf-2026', 'individual_player', 2],
        ['golf-2026', 'hole_sponsor', 1],
        ['gala-2026', 'gala_single', 0],
        ['gala-2026', 'gala_single', 2],
        ['gala-2026', 'gala_couple', 0],
        ['gala-2026', 'gala_couple', 3],
        ['gala-2026', 'gala_vip_single', 0],
        ['gala-2026', 'gala_vip_single', 2],
        ['gala-2026', 'gala_vip_couple', 0],
        ['gala-2026', 'gala_vip_couple', 3],
    ] as [$eventCode, $packageCode, $count]) {
        $exception = test_expect_api_exception(
            static fn (): array => app_validate_registration_payload(
                test_registration_input($eventCode, $packageCode, $count)
            ),
            'validation_failed'
        );
        $field = $eventCode === 'gala-2026' ? 'ticket_groups.0.participants' : 'participants';
        test_assert_true(isset($exception->details[$field]), sprintf(
            '%s must reject %d participant(s).',
            $packageCode,
            $count
        ));
    }
};

$tests['gala ticket groups multiply prices, capacities, attendance, and FMV'] = static function (): void {
    $cases = [
        ['gala_couple', [1, 2], 50000, 4, 3, 24000, '2 × Two gala admissions'],
        ['gala_vip_couple', [2, 1, 2], 90000, 6, 5, 60000, '3 × Two VIP gala admissions'],
        ['gala_single', [1, 1, 1], 40500, 3, 3, 18000, '3 × One gala admission'],
    ];
    foreach ($cases as [$packageCode, $counts, $total, $capacity, $attending, $fmv, $description]) {
        $validated = app_validate_registration_payload(
            test_ticket_group_registration_input($packageCode, $counts)
        );
        test_assert_same(count($counts), $validated['package_quantity']);
        test_assert_same(count($counts), count($validated['ticket_groups']));
        test_assert_same($total, $validated['base_amount_cents']);
        test_assert_same($total, $validated['amount_cents']);
        test_assert_same($capacity, $validated['participant_capacity']);
        test_assert_same($attending, array_sum(array_map(
            static fn (array $group): int => count($group['participants']),
            $validated['ticket_groups']
        )));
        test_assert_same($fmv, $validated['benefit']['fair_market_value_cents']);
        test_assert_same($total - $fmv, $validated['benefit']['deductible_amount_cents']);
        test_assert_same($description, $validated['benefit']['description']);
        test_assert_same([], $validated['participants']);
        test_assert_same([], $validated['teams']);
        test_assert_same([], $validated['addons']);
    }

    $maximum = app_validate_registration_payload(
        test_ticket_group_registration_input('gala_couple', [1, 2, 1, 2, 1, 2, 1, 2, 1, 2])
    );
    test_assert_same(10, $maximum['package_quantity']);
    test_assert_same(250000, $maximum['base_amount_cents']);
    test_assert_same(20, $maximum['participant_capacity']);
    test_assert_same(15, array_sum(array_map(
        static fn (array $group): int => count($group['participants']),
        $maximum['ticket_groups']
    )));
    test_assert_same('Ticket Group 10 Guest 2', $maximum['ticket_groups'][9]['participants'][1]['name']);
};

$tests['gala ticket-group boundaries and canonical structure are enforced'] = static function (): void {
    foreach ([[], array_fill(0, 11, 1)] as $invalidCounts) {
        $exception = test_expect_api_exception(
            static fn (): array => app_validate_registration_payload(
                test_ticket_group_registration_input('gala_couple', $invalidCounts)
            ),
            'validation_failed'
        );
        test_assert_true(isset($exception->details['ticket_group_count']));
    }

    $mixed = test_ticket_group_registration_input('gala_couple', [2, 1]);
    $mixed['participants'] = [['name' => 'Top-level Guest']];
    $mixed['addons'] = ['team_mulligans'];
    $mixed['teams'] = [[
        'name' => 'Not a Gala Structure',
        'participants' => [['name' => 'Golfer']],
        'addons' => [],
    ]];
    $mixed['registration']['team_name'] = 'Not a Gala Team';
    $exception = test_expect_api_exception(
        static fn (): array => app_validate_registration_payload($mixed),
        'validation_failed'
    );
    foreach (['participants', 'addons', 'teams', 'registration.team_name'] as $field) {
        test_assert_true(isset($exception->details[$field]), 'Missing gala mixed-structure error for ' . $field);
    }

    $invalidPerson = test_ticket_group_registration_input('gala_single', [1]);
    $invalidPerson['ticket_groups'][0]['participants'][0] = ['name' => ''];
    $exception = test_expect_api_exception(
        static fn (): array => app_validate_registration_payload($invalidPerson),
        'validation_failed'
    );
    foreach (['ticket_groups.0.participants.0.name', 'ticket_groups.0.participants'] as $field) {
        test_assert_true(isset($exception->details[$field]), 'Missing ticket-group field error for ' . $field);
    }

    $golfWithTicketGroup = test_registration_input('golf-2026', 'individual_player', 1);
    $golfWithTicketGroup['ticket_groups'] = [['participants' => [['name' => 'Not a Gala Guest']]]];
    $exception = test_expect_api_exception(
        static fn (): array => app_validate_registration_payload($golfWithTicketGroup),
        'validation_failed'
    );
    test_assert_true(isset($exception->details['ticket_groups']));
};

$tests['Square gala lines use ticket-group quantity and authoritative unit price'] = static function (): void {
    foreach ([
        ['gala_couple', [1, 2], '2', 25000, 50000],
        ['gala_vip_couple', [2, 1, 2], '3', 30000, 90000],
        ['gala_single', [1, 1, 1, 1], '4', 13500, 54000],
    ] as [$packageCode, $counts, $quantity, $unitPrice, $total]) {
        $validated = app_validate_registration_payload(
            test_ticket_group_registration_input($packageCode, $counts)
        );
        $lines = app_square_registration_line_items(test_square_snapshot($validated));
        test_assert_same(1, count($lines));
        test_assert_same($quantity, $lines[0]['quantity']);
        test_assert_same($unitPrice, $lines[0]['base_price_money']['amount']);
        test_assert_same($total, $validated['amount_cents']);
    }
};

$tests['team mulligans are limited to golf team packages and included in the total'] = static function (): void {
    foreach ([['corporate_sponsor', 104000], ['team_sponsor', 44000]] as [$packageCode, $total]) {
        $validated = app_validate_registration_payload(
            test_registration_input('golf-2026', $packageCode, 4, ['team_mulligans'])
        );
        test_assert_same(4000, $validated['addon_amount_cents']);
        test_assert_same($total, $validated['amount_cents']);
        test_assert_same(['team_mulligans'], array_keys($validated['addons']));
        test_assert_same(1, $validated['addons']['team_mulligans']['quantity']);
        test_assert_same(4000, $validated['addons']['team_mulligans']['total_amount_cents']);
    }

    foreach ([
        ['golf-2026', 'individual_player'],
        ['golf-2026', 'contest_sponsor'],
        ['gala-2026', 'gala_couple'],
    ] as [$eventCode, $packageCode]) {
        $exception = test_expect_api_exception(
            static fn (): array => app_validate_registration_payload(
                test_registration_input($eventCode, $packageCode, null, ['team_mulligans'])
            ),
            'validation_failed'
        );
        test_assert_true(isset($exception->details['addons']), $packageCode . ' must reject team mulligans.');
    }

    $duplicate = test_registration_input('golf-2026', 'team_sponsor', 4, ['team_mulligans', 'team_mulligans']);
    $exception = test_expect_api_exception(
        static fn (): array => app_validate_registration_payload($duplicate),
        'validation_failed'
    );
    test_assert_true(isset($exception->details['teams.0.addons']), 'Duplicate mulligans must be rejected.');
};

$tests['multi-team registrations support 2, 3, and 10 ordered teams with uneven rosters'] = static function (): void {
    foreach ([2, 3, 10] as $teamCount) {
        $participantCounts = [];
        for ($index = 0; $index < $teamCount; $index++) {
            $participantCounts[] = ($index % 4) + 1;
        }
        $validated = app_validate_registration_payload(
            test_team_registration_input('team_sponsor', $participantCounts)
        );
        test_assert_same($teamCount, $validated['package_quantity']);
        test_assert_same($teamCount, count($validated['teams']));
        test_assert_same(40000, $validated['package_unit_amount_cents']);
        test_assert_same(40000 * $teamCount, $validated['base_amount_cents']);
        test_assert_same(4 * $teamCount, $validated['participant_capacity']);
        foreach ($participantCounts as $index => $participantCount) {
            test_assert_same($participantCount, count($validated['teams'][$index]['participants']));
        }
    }

    foreach ([[], array_fill(0, 11, 1)] as $invalidCounts) {
        $exception = test_expect_api_exception(
            static fn (): array => app_validate_registration_payload(
                test_team_registration_input('team_sponsor', $invalidCounts)
            ),
            'validation_failed'
        );
        test_assert_true(isset($exception->details['team_count']), 'Invalid team count was not rejected.');
    }

    $punctuated = test_team_registration_input('team_sponsor', [2]);
    $punctuated['teams'][0]['name'] = 'Acme Team #1 / AM';
    $validated = app_validate_registration_payload($punctuated);
    test_assert_same('Acme Team #1 / AM', $validated['teams'][0]['name']);
};

$tests['per-team mulligans aggregate quantities, totals, and FMV'] = static function (): void {
    $validated = app_validate_registration_payload(
        test_team_registration_input('team_sponsor', [4, 2, 1], [0, 2])
    );
    test_assert_same(3, $validated['package_quantity']);
    test_assert_same(120000, $validated['base_amount_cents']);
    test_assert_same(8000, $validated['addon_amount_cents']);
    test_assert_same(128000, $validated['amount_cents']);
    test_assert_same(2, $validated['addons']['team_mulligans']['quantity']);
    test_assert_same(4000, $validated['addons']['team_mulligans']['unit_amount_cents']);
    test_assert_same(8000, $validated['addons']['team_mulligans']['total_amount_cents']);
    test_assert_same('3 × Golf team and sponsor benefits; 2 × Eight team mulligans', $validated['benefit']['description']);
    test_assert_same(116000, $validated['benefit']['fair_market_value_cents']);
    test_assert_same(12000, $validated['benefit']['deductible_amount_cents']);
    test_assert_same(['team_mulligans'], $validated['teams'][0]['addons']);
    test_assert_same([], $validated['teams'][1]['addons']);
    test_assert_same(['team_mulligans'], $validated['teams'][2]['addons']);
};

$tests['team and flat registration structures cannot be mixed'] = static function (): void {
    $mixed = test_team_registration_input('corporate_sponsor', [4, 3], [1]);
    $mixed['participants'] = [['name' => 'Top-level Golfer']];
    $mixed['addons'] = ['team_mulligans'];
    $mixed['registration']['team_name'] = 'Deprecated Team Name';
    $exception = test_expect_api_exception(
        static fn (): array => app_validate_registration_payload($mixed),
        'validation_failed'
    );
    foreach (['participants', 'addons', 'registration.team_name'] as $field) {
        test_assert_true(isset($exception->details[$field]), 'Missing mixed-structure error for ' . $field);
    }

    $flatWithTeam = test_registration_input('gala-2026', 'gala_couple', 2);
    $flatWithTeam['teams'] = [[
        'name' => 'Not Allowed',
        'participants' => [['name' => 'Guest One']],
        'addons' => [],
    ]];
    $exception = test_expect_api_exception(
        static fn (): array => app_validate_registration_payload($flatWithTeam),
        'validation_failed'
    );
    test_assert_true(isset($exception->details['teams']));

    $invalidTeam = test_team_registration_input('team_sponsor', [1]);
    $invalidTeam['teams'][0]['name'] = '';
    $invalidTeam['teams'][0]['participants'] = [];
    $invalidTeam['teams'][0]['addons'] = ['unknown_addon'];
    $exception = test_expect_api_exception(
        static fn (): array => app_validate_registration_payload($invalidTeam),
        'validation_failed'
    );
    foreach (['teams.0.name', 'teams.0.participants', 'teams.0.addons'] as $field) {
        test_assert_true(isset($exception->details[$field]), 'Missing invalid-team error for ' . $field);
    }
};

$tests['Square line items use authoritative unit prices and aggregate quantities'] = static function (): void {
    $validated = app_validate_registration_payload(
        test_team_registration_input('corporate_sponsor', [4, 3, 2], [0, 2])
    );
    $snapshot = test_square_snapshot($validated);
    $lines = app_square_registration_line_items($snapshot);
    test_assert_same(2, count($lines));
    test_assert_same('3', $lines[0]['quantity']);
    test_assert_same(100000, $lines[0]['base_price_money']['amount']);
    test_assert_same('2', $lines[1]['quantity']);
    test_assert_same(4000, $lines[1]['base_price_money']['amount']);

    $tampered = $snapshot;
    $tampered['base_amount_cents']--;
    test_expect_runtime_exception(static fn (): array => app_square_registration_line_items($tampered));

    $tampered = $snapshot;
    $addons = json_decode($tampered['addons_json'], true, 8, JSON_THROW_ON_ERROR);
    $addons[0]['quantity'] = 3;
    $tampered['addons_json'] = json_encode($addons, JSON_THROW_ON_ERROR);
    test_expect_runtime_exception(static fn (): array => app_square_registration_line_items($tampered));
};

$tests['payer mailing address is required and normalized'] = static function (): void {
    $input = test_registration_input('gala-2026', 'gala_single');
    $input['payer']['postal_code'] = '38103-1234';
    $validated = app_validate_registration_payload($input);
    test_assert_same('TN', $validated['payer']['state']);
    test_assert_same('38103-1234', $validated['payer']['postal_code']);

    $invalid = $input;
    $invalid['payer']['address_line1'] = '';
    $invalid['payer']['city'] = '';
    $invalid['payer']['state'] = 'Tennessee';
    $invalid['payer']['postal_code'] = 'ABC';
    $exception = test_expect_api_exception(
        static fn (): array => app_validate_registration_payload($invalid),
        'validation_failed'
    );
    foreach (['payer.address_line1', 'payer.city', 'payer.state', 'payer.postal_code'] as $field) {
        test_assert_true(isset($exception->details[$field]), 'Missing validation error for ' . $field . '.');
    }
};

$tests['benefit descriptions, FMV, and deductible amounts come from configuration'] = static function (): void {
    test_assert_same('benefit_fmv', app_config('EVENT_DISCLOSURE_MODE'));
    $snapshot = app_registration_benefit_snapshot(
        'corporate_sponsor',
        1,
        ['team_mulligans' => 1],
        104000
    );
    test_assert_same('Corporate sponsor benefits; Eight team mulligans', $snapshot['description']);
    test_assert_same(46000, $snapshot['fair_market_value_cents']);
    test_assert_same(58000, $snapshot['deductible_amount_cents']);

    $gala = app_registration_benefit_snapshot('gala_couple', 1, [], 25000);
    test_assert_same(12000, $gala['fair_market_value_cents']);
    test_assert_same(13000, $gala['deductible_amount_cents']);

    $clamped = app_registration_benefit_snapshot('gala_single', 1, [], 1000);
    test_assert_same(0, $clamped['deductible_amount_cents']);
};

$tests['payment-confirmation disclosure bypasses FMV and unknown modes fail closed'] = static function () use ($siteRoot): void {
    $snapshot = app_registration_disclosure_snapshot(
        'corporate_sponsor',
        3,
        ['team_mulligans' => 2],
        308000,
        'payment_confirmation_only'
    );
    test_assert_same('payment_confirmation_only', $snapshot['mode']);
    test_assert_same(null, $snapshot['description']);
    test_assert_same(null, $snapshot['fair_market_value_cents']);
    test_assert_same(null, $snapshot['deductible_amount_cents']);

    $benefit = app_registration_disclosure_snapshot('gala_couple', 2, [], 50000, 'benefit_fmv');
    test_assert_same('benefit_fmv', $benefit['mode']);
    test_assert_same('2 × Two gala admissions', $benefit['description']);
    test_assert_same(24000, $benefit['fair_market_value_cents']);
    test_assert_same(26000, $benefit['deductible_amount_cents']);

    test_expect_runtime_exception(static fn (): string => app_disclosure_mode_value('unknown_mode'));
    $configSource = file_get_contents($siteRoot . '/app/config.php');
    test_assert_true(is_string($configSource));
    test_assert_true(
        str_contains($configSource, "'EVENT_DISCLOSURE_MODE' => 'payment_confirmation_only'"),
        'The application default must remain payment_confirmation_only.'
    );
};

$tests['public event options expose configured prices and benefit snapshots'] = static function (): void {
    $options = app_public_event_options('gala-2026');
    test_assert_same('USD', $options['currency']);
    test_assert_same('benefit_fmv', $options['disclosure_mode']);
    test_assert_same(4, count($options['packages']));
    $byCode = [];
    foreach ($options['packages'] as $package) {
        $byCode[$package['code']] = $package;
    }
    test_assert_same(30000, $byCode['gala_vip_couple']['amount_cents']);
    test_assert_same(1, $byCode['gala_vip_couple']['participant_min']);
    test_assert_same(2, $byCode['gala_vip_couple']['participant_max']);
    test_assert_same(20000, $byCode['gala_vip_couple']['fair_market_value_cents']);
    test_assert_same(10000, $byCode['gala_vip_couple']['max_deductible_cents']);
};

$tests['public event options expose payment mode with a null tax trio'] = static function (): void {
    $options = app_public_event_options('golf-2026', 'payment_confirmation_only');
    test_assert_same('payment_confirmation_only', $options['disclosure_mode']);
    test_assert_same(6, count($options['packages']));
    test_assert_same(1, count($options['addons']));
    foreach (array_merge($options['packages'], $options['addons']) as $option) {
        test_assert_same(null, $option['benefit_description']);
        test_assert_same(null, $option['fair_market_value_cents']);
        test_assert_same(null, $option['max_deductible_cents']);
    }
};

$tests['registration fingerprint is stable for retries and changes with registration data'] = static function (): void {
    $validated = app_validate_registration_payload(
        test_registration_input('golf-2026', 'team_sponsor', 4, ['team_mulligans'])
    );
    $copy = $validated;
    test_assert_same(app_registration_fingerprint($validated), app_registration_fingerprint($copy));
    test_assert_same(32, strlen(app_registration_fingerprint($validated)));

    $changedMode = $validated;
    $changedMode['disclosure_mode'] = 'payment_confirmation_only';
    test_assert_false(
        hash_equals(app_registration_fingerprint($validated), app_registration_fingerprint($changedMode)),
        'Changing disclosure mode must change the idempotency fingerprint.'
    );

    $changed = $validated;
    $changed['teams'][0]['participants'][0]['name'] = 'A Different Player';
    test_assert_false(
        hash_equals(app_registration_fingerprint($validated), app_registration_fingerprint($changed)),
        'A changed participant must change the idempotency fingerprint.'
    );

    $multiTeam = app_validate_registration_payload(
        test_team_registration_input('team_sponsor', [4, 2, 3], [0, 2])
    );
    $changedTeamName = $multiTeam;
    $changedTeamName['teams'][1]['name'] = 'A Different Team';
    test_assert_false(
        hash_equals(app_registration_fingerprint($multiTeam), app_registration_fingerprint($changedTeamName)),
        'A changed team name must change the idempotency fingerprint.'
    );
    $changedTeamAddon = $multiTeam;
    $changedTeamAddon['teams'][2]['addons'] = [];
    test_assert_false(
        hash_equals(app_registration_fingerprint($multiTeam), app_registration_fingerprint($changedTeamAddon)),
        'A changed per-team add-on must change the idempotency fingerprint.'
    );
    $reorderedTeams = $multiTeam;
    [$reorderedTeams['teams'][0], $reorderedTeams['teams'][1]] = [
        $reorderedTeams['teams'][1],
        $reorderedTeams['teams'][0],
    ];
    test_assert_false(
        hash_equals(app_registration_fingerprint($multiTeam), app_registration_fingerprint($reorderedTeams)),
        'Changing team order must change the idempotency fingerprint.'
    );

    $oneGuest = app_validate_registration_payload(
        test_registration_input('gala-2026', 'gala_couple', 1)
    );
    $twoGuests = app_validate_registration_payload(
        test_registration_input('gala-2026', 'gala_couple', 2)
    );
    test_assert_false(
        hash_equals(app_registration_fingerprint($oneGuest), app_registration_fingerprint($twoGuests)),
        'The selected attendance count must change the idempotency fingerprint.'
    );

    $twoTicketGroups = app_validate_registration_payload(
        test_ticket_group_registration_input('gala_couple', [2, 1])
    );
    $changedTicketGuest = $twoTicketGroups;
    $changedTicketGuest['ticket_groups'][1]['participants'][0]['name'] = 'A Different Gala Guest';
    test_assert_false(
        hash_equals(app_registration_fingerprint($twoTicketGroups), app_registration_fingerprint($changedTicketGuest)),
        'A changed ticket-group guest must change the idempotency fingerprint.'
    );
    $reorderedTicketGroups = $twoTicketGroups;
    [$reorderedTicketGroups['ticket_groups'][0], $reorderedTicketGroups['ticket_groups'][1]] = [
        $reorderedTicketGroups['ticket_groups'][1],
        $reorderedTicketGroups['ticket_groups'][0],
    ];
    test_assert_false(
        hash_equals(app_registration_fingerprint($twoTicketGroups), app_registration_fingerprint($reorderedTicketGroups)),
        'Changing ticket-group order must change the idempotency fingerprint.'
    );
    $threeTicketGroups = app_validate_registration_payload(
        test_ticket_group_registration_input('gala_couple', [2, 1, 2])
    );
    test_assert_false(
        hash_equals(app_registration_fingerprint($twoTicketGroups), app_registration_fingerprint($threeTicketGroups)),
        'Changing ticket-group quantity must change the idempotency fingerprint.'
    );

    $linkageMutations = [
        'event' => static function (array $data): array {
            $data['event_code'] = 'golf-2026';
            return $data;
        },
        'package' => static function (array $data): array {
            $data['package_code'] = 'gala_vip_couple';
            return $data;
        },
        'payer' => static function (array $data): array {
            $data['payer']['email'] = 'another-payer@example.org';
            return $data;
        },
    ];
    foreach ($linkageMutations as $field => $mutate) {
        $changedLink = $mutate($oneGuest);
        test_assert_false(
            hash_equals(app_registration_fingerprint($oneGuest), app_registration_fingerprint($changedLink)),
            'Changing the ' . $field . ' linkage must change the idempotency fingerprint.'
        );
    }
};

$tests['maximum ten-team payload fits the expanded registration JSON limit'] = static function () use ($siteRoot): void {
    $input = test_team_registration_input('corporate_sponsor', array_fill(0, 10, 4), range(0, 9));
    foreach ($input['teams'] as $teamIndex => &$team) {
        $team['name'] = str_repeat(chr(65 + ($teamIndex % 26)), 120);
        foreach ($team['participants'] as $participantIndex => &$participant) {
            $participant['name'] = str_repeat(chr(65 + (($teamIndex + $participantIndex) % 26)), 120);
        }
        unset($participant);
    }
    unset($team);
    $json = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    test_assert_true(strlen($json) < 65536, 'A valid maximum team payload exceeds the API limit.');
    $validated = app_validate_registration_payload($input);
    test_assert_same(10, $validated['package_quantity']);
    test_assert_same(40, array_sum(array_map(
        static fn (array $team): int => count($team['participants']),
        $validated['teams']
    )));
    $apiSource = file_get_contents($siteRoot . '/api/registrations.php');
    test_assert_true(is_string($apiSource) && str_contains($apiSource, 'app_read_json(65536)'));
};

$tests['persistence contract links event, payer, roster, reference, and Square order'] = static function () use ($siteRoot): void {
    $registrationsSource = file_get_contents($siteRoot . '/app/registrations.php');
    $squareSource = file_get_contents($siteRoot . '/app/square.php');
    $webhooksSource = file_get_contents($siteRoot . '/app/webhooks.php');
    $schemaSource = file_get_contents($siteRoot . '/database/001_event_registrations.sql');
    test_assert_true(
        is_string($registrationsSource) && is_string($squareSource)
        && is_string($webhooksSource) && is_string($schemaSource)
    );

    foreach ([
        "':event_code' => \$data['event_code']",
        "':package_code' => \$data['package_code']",
        "':email' => \$payer['email']",
        "':capacity' => \$data['participant_capacity']",
        "':package_quantity' => \$data['package_quantity']",
        "':package_unit_amount' => \$data['package_unit_amount_cents']",
        "':disclosure_mode' => \$disclosureMode",
        'INSERT INTO registration_teams',
        'INSERT INTO registration_ticket_groups',
        '(registration_id,team_id,ticket_group_id,position,team_position,ticket_group_position,name,created_at)',
        "foreach (\$data['ticket_groups'] as \$ticketGroupIndex => \$ticketGroup)",
        'SELECT id,position,participant_capacity FROM registration_ticket_groups',
        'function app_load_registration_roster',
        "'ticket_groups' => \$roster['ticket_groups']",
        "return ['teams' => \$teams, 'ticket_groups' => \$ticketGroups, 'participants' => \$participants];",
        'SET square_payment_link_id=?,square_order_id=?',
    ] as $requiredRegistrationLink) {
        test_assert_true(
            str_contains($registrationsSource, $requiredRegistrationLink),
            'Registration persistence is missing linkage: ' . $requiredRegistrationLink
        );
    }
    foreach ([
        "'reference_id' => \$registration['public_reference']",
        "\$event['name'] . ' - ' . \$registration['package_name']",
        "'buyer_email' => \$registration['payer_email']",
        "'quantity' => (string) \$packageQuantity",
        "'quantity' => (string) \$quantity",
    ] as $requiredSquareLink) {
        test_assert_true(
            str_contains($squareSource, $requiredSquareLink),
            'Square integration is missing linkage: ' . $requiredSquareLink
        );
    }
    foreach ([
        'SELECT * FROM registrations WHERE square_order_id = ? LIMIT 1',
        "(string) (\$payment['order_id'] ?? '') !== (string) \$registration['square_order_id']",
        "(int) \$registration['id']",
        "(string) \$payment['order_id']",
    ] as $requiredPaymentLink) {
        test_assert_true(
            str_contains($webhooksSource, $requiredPaymentLink),
            'Verified-payment processing is missing linkage: ' . $requiredPaymentLink
        );
    }
    foreach ([
        'FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE CASCADE',
        'FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE RESTRICT',
        'UNIQUE KEY uq_registrations_square_order (square_order_id)',
        'CREATE TABLE registration_teams',
        'CREATE TABLE registration_ticket_groups',
        'FOREIGN KEY (registration_id, team_id) REFERENCES registration_teams (registration_id, id)',
        'FOREIGN KEY (registration_id, ticket_group_id)',
        'REFERENCES registration_ticket_groups (registration_id, id)',
        'CONSTRAINT chk_registrations_package_quantity CHECK (package_quantity BETWEEN 1 AND 10)',
        "disclosure_mode VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'payment_confirmation_only'",
        'CONSTRAINT chk_registrations_disclosure_values CHECK (',
        'CONSTRAINT chk_participants_roster_link CHECK (',
        'team_id IS NOT NULL AND team_position IS NOT NULL AND team_position BETWEEN 1 AND 4',
        'ticket_group_id IS NOT NULL AND ticket_group_position IS NOT NULL',
        'ticket_group_position BETWEEN 1 AND 2',
    ] as $requiredDatabaseLink) {
        test_assert_true(
            str_contains($schemaSource, $requiredDatabaseLink),
            'Database schema is missing linkage: ' . $requiredDatabaseLink
        );
    }
};

$tests['disclosure persistence, migration, status, and reporting contracts stay coherent'] = static function () use ($siteRoot): void {
    $files = [
        'registrations' => file_get_contents($siteRoot . '/app/registrations.php'),
        'schema' => file_get_contents($siteRoot . '/database/001_event_registrations.sql'),
        'migration' => file_get_contents($siteRoot . '/database/002_payment_confirmation_only.sql'),
        'admin' => file_get_contents($siteRoot . '/admin/index.php'),
        'export' => file_get_contents($siteRoot . '/admin/export.php'),
    ];
    foreach ($files as $name => $source) {
        test_assert_true(is_string($source), 'Could not read ' . $name . ' disclosure source.');
    }

    foreach ([
        'amount_cents,currency,disclosure_mode,benefit_description',
        "'disclosure_mode' => \$data['disclosure_mode']",
        "'disclosure_mode' => \$disclosureMode",
        "'benefit_description' => \$benefitDescription",
        "'fair_market_value_cents' => \$fairMarketValueCents",
        "'max_deductible_cents' => \$deductibleAmountCents",
    ] as $fragment) {
        test_assert_true(str_contains($files['registrations'], $fragment), 'Missing registration disclosure contract: ' . $fragment);
    }
    foreach ([
        'benefit_description VARCHAR(1000) NULL',
        'fair_market_value_cents INT UNSIGNED NULL',
        'deductible_amount_cents INT UNSIGNED NULL',
        "disclosure_mode IN ('payment_confirmation_only', 'benefit_fmv')",
        "disclosure_mode = 'payment_confirmation_only'",
        "disclosure_mode = 'benefit_fmv'",
    ] as $fragment) {
        test_assert_true(str_contains($files['schema'], $fragment), 'Missing schema disclosure contract: ' . $fragment);
    }
    foreach ([
        "SET disclosure_mode = 'benefit_fmv'",
        'WHERE disclosure_mode IS NULL',
        "NOT NULL DEFAULT 'payment_confirmation_only'",
        'MODIFY benefit_description VARCHAR(1000) NULL',
        "CONSTRAINT_NAME = 'chk_registrations_disclosure_values'",
    ] as $fragment) {
        test_assert_true(str_contains($files['migration'], $fragment), 'Missing migration safety contract: ' . $fragment);
    }
    test_assert_true(str_contains($files['admin'], "=== 'benefit_fmv'"));
    test_assert_true(str_contains($files['export'], "=== 'benefit_fmv'"));
    test_assert_true(str_contains($files['export'], "'Disclosure mode'"));
    test_assert_true(substr_count($files['export'], ": ''") >= 3, 'CSV tax values must have blank branches.');
};

$tests['attendance reporting distinguishes actual attendance from package capacity'] = static function () use ($siteRoot): void {
    $files = [
        $siteRoot . '/app/email.php',
        $siteRoot . '/app/registrations.php',
        $siteRoot . '/admin/index.php',
        $siteRoot . '/admin/export.php',
        $siteRoot . '/assets/js/registration-status.js',
        $siteRoot . '/database/001_event_registrations.sql',
    ];
    $combined = '';
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        test_assert_true(is_string($contents), 'Could not read attendance-reporting source: ' . $file);
        $combined .= "\n" . $contents;
    }
    $retiredIdentifier = 'expected_' . 'participant_count';
    test_assert_false(str_contains($combined, $retiredIdentifier), 'The retired capacity identifier must not return.');
    foreach (['Number attending', 'Package capacity', 'participant_capacity', 'Guest names', 'Golfer names'] as $label) {
        test_assert_true(str_contains($combined, $label), 'Attendance reporting is missing: ' . $label);
    }
    foreach (['Missing roster count', 'Participants expected', ' of " + capacity + " provided'] as $misleadingLabel) {
        test_assert_false(str_contains($combined, $misleadingLabel), 'Misleading attendance copy returned: ' . $misleadingLabel);
    }
};

$tests['gala ticket groups remain grouped in status, dashboard, and CSV reporting'] = static function () use ($siteRoot): void {
    $requiredByFile = [
        '/app/registrations.php' => [
            "'package_code' => (string) \$row['package_code']",
            "'ticket_groups' => \$roster['ticket_groups']",
        ],
        '/admin/_bootstrap.php' => [
            'FROM registration_ticket_groups',
            "\$registration['ticket_groups'] = \$ticketGroups",
        ],
        '/admin/index.php' => [
            "\$ticketGroups = \$registration['ticket_groups']",
            "'Couple package '",
            'Guests attending:',
        ],
        '/admin/export.php' => [
            "\$ticketGroups = \$registration['ticket_groups']",
            "'Gala ticket groups'",
            "'Ticket package count'",
        ],
        '/assets/js/registration-status.js' => [
            'function renderTicketGroupParticipants',
            'data.ticket_groups',
            'data.package_code',
        ],
    ];
    foreach ($requiredByFile as $relativePath => $requiredFragments) {
        $source = file_get_contents($siteRoot . $relativePath);
        test_assert_true(is_string($source), 'Could not read ticket-group consumer: ' . $relativePath);
        foreach ($requiredFragments as $fragment) {
            test_assert_true(
                str_contains($source, $fragment),
                $relativePath . ' is missing ticket-group reporting contract: ' . $fragment
            );
        }
    }
};

$tests['status token is deterministic, keyed, URL-safe, and 43 characters'] = static function () use ($testAppKey): void {
    $clientKey = 'browser-key-0123456789abcdef';
    $token = app_status_token_for_idempotency_key($clientKey);
    $expected = rtrim(strtr(base64_encode(hash_hmac(
        'sha256',
        "registration-status\0" . $clientKey,
        $testAppKey,
        true
    )), '+/', '-_'), '=');
    test_assert_same($expected, $token);
    test_assert_same(43, strlen($token));
    test_assert_true((bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $token));
    test_assert_same($token, app_status_token_for_idempotency_key($clientKey));
    test_assert_false(hash_equals($token, app_status_token_for_idempotency_key($clientKey . '-different')));
};

$tests['Square checkout URLs use HTTPS and an explicitly allowed Square host'] = static function (): void {
    foreach ([
        'https://square.link/u/abc123',
        'https://sandbox.square.link/u/abc123',
        'https://checkout.square.site/merchant/order',
        'https://pay.squareup.com/checkout/abc123',
    ] as $url) {
        test_assert_true(app_is_safe_square_checkout_url($url), 'Expected allowed Square URL: ' . $url);
    }
    foreach ([
        'http://square.link/u/abc123',
        'https://square.link.evil.example/u/abc123',
        'https://evilsquare.link/u/abc123',
        'https://example.org/checkout',
        'javascript:alert(1)',
        'not a URL',
    ] as $url) {
        test_assert_false(app_is_safe_square_checkout_url($url), 'Expected rejected checkout URL: ' . $url);
    }
};

$tests['Square receipt URLs are strict and sandbox hosts are environment-gated'] = static function (): void {
    foreach ([
        'https://squareup.com/receipt/preview/abc123',
        'https://sandbox.squareup.com/receipt/preview/abc123',
    ] as $url) {
        test_assert_same($url, app_safe_receipt_url($url));
        test_assert_same($url, app_email_receipt_url($url));
    }
    $sandboxReceipt = 'https://squareupsandbox.com/receipt/preview/sandbox123';
    $sandboxSubdomainReceipt = 'https://app.squareupsandbox.com/receipt/preview/sandbox123';
    test_assert_same(null, app_validated_square_receipt_url($sandboxReceipt, 'production'));
    test_assert_same(null, app_safe_receipt_url($sandboxReceipt), 'Production accepted a sandbox receipt host.');
    test_assert_same(null, app_email_receipt_url($sandboxReceipt), 'Production email accepted a sandbox receipt host.');
    test_assert_same($sandboxReceipt, app_validated_square_receipt_url($sandboxReceipt, 'sandbox'));
    test_assert_same($sandboxSubdomainReceipt, app_validated_square_receipt_url($sandboxSubdomainReceipt, 'sandbox'));
    foreach ([
        'http://squareup.com/receipt/preview/abc123',
        'https://user:pass@squareup.com/receipt/preview/abc123',
        'https://squareup.com:443/receipt/preview/abc123',
        'https://squareup.com.evil.example/receipt/abc123',
        'https://squareupsandbox.com.evil.example/receipt/abc123',
        'https://example.org/fake-square-receipt',
        'javascript:alert(1)',
    ] as $url) {
        test_assert_same(null, app_safe_receipt_url($url), 'Unsafe stored receipt URL was accepted.');
        test_assert_same(null, app_email_receipt_url($url), 'Unsafe emailed receipt URL was accepted.');
        test_assert_same(null, app_validated_square_receipt_url($url, 'sandbox'), 'Unsafe sandbox URL was accepted.');
    }
};

$tests['Square webhook HMAC binds the exact notification URL and raw body'] = static function () use (
    $testWebhookKey,
    $testWebhookUrl
): void {
    $body = '{"merchant_id":"merchant-test","type":"payment.updated"}';
    $signature = base64_encode(hash_hmac('sha256', $testWebhookUrl . $body, $testWebhookKey, true));
    test_assert_true(app_verify_square_webhook($body, $signature));
    test_assert_false(app_verify_square_webhook($body . ' ', $signature));
    test_assert_false(app_verify_square_webhook($body, base64_encode(random_bytes(32))));
    test_assert_false(app_verify_square_webhook($body, ''));
};

$tests['stale Square snapshots cannot regress refund amount, timestamp, or registration status'] = static function (): void {
    $fullRefund = 30000;
    test_assert_same(
        $fullRefund,
        app_monotonic_refunded_cents($fullRefund, 5000, $fullRefund),
        'A stale partial refund reduced a stored full refund.'
    );
    test_assert_same(
        $fullRefund,
        app_monotonic_refunded_cents(5000, $fullRefund, $fullRefund),
        'A newer full refund did not advance the stored refund.'
    );
    test_assert_false(app_square_update_is_current(
        '2026-12-20T12:00:00.000000Z',
        '2026-12-20T11:59:59.999999Z'
    ));
    test_assert_true(app_square_update_is_current(
        '2026-12-20T12:00:00.000000Z',
        '2026-12-20T12:00:00.000000Z'
    ));
    test_assert_same('refunded', app_completed_registration_status('refunded', 5000, $fullRefund));
    test_assert_same('refunded', app_completed_registration_status('partially_refunded', $fullRefund, $fullRefund));
    test_assert_same('partially_refunded', app_completed_registration_status('partially_refunded', 0, $fullRefund));
};

$tests['completed-and-refunded first snapshot schedules paid notices before refund notices'] = static function () use ($siteRoot): void {
    $source = file_get_contents($siteRoot . '/app/webhooks.php');
    test_assert_true(is_string($source));
    $storeStart = strpos($source, 'function app_store_square_payment');
    $upsertStart = strpos($source, 'function app_upsert_payment_row');
    test_assert_true(is_int($storeStart) && is_int($upsertStart) && $upsertStart > $storeStart);
    $storeSource = substr($source, $storeStart, $upsertStart - $storeStart);
    $rosterLoad = strpos($storeSource, 'app_load_registration_roster');
    $paidPayer = strpos($storeSource, 'app_enqueue_paid_confirmation');
    $paidStaff = strpos($storeSource, 'app_enqueue_internal_paid_notifications');
    $refundPayer = strpos($storeSource, 'app_enqueue_refund_confirmation');
    $refundStaff = strpos($storeSource, 'app_enqueue_internal_refund_notifications');
    test_assert_true(
        is_int($rosterLoad) && is_int($paidPayer) && is_int($paidStaff)
        && is_int($refundPayer) && is_int($refundStaff),
        'The completed-payment notification sequence is incomplete.'
    );
    test_assert_true(
        $rosterLoad < $paidPayer && $paidPayer < $refundPayer && $paidStaff < $refundStaff,
        'Paid confirmations must be queued before refund notices for a first-seen refunded payment.'
    );
    test_assert_same(2, substr_count($source, 'app_store_square_payment($pdo, $registration, $payment);'));
};

$tests['registration paid timestamp comes from Square completed_at'] = static function () use ($siteRoot): void {
    test_assert_same(
        '2026-09-12 18:34:56.123456',
        app_square_datetime('2026-09-12T13:34:56.123456-05:00')
    );
    $source = file_get_contents($siteRoot . '/app/webhooks.php');
    test_assert_true(is_string($source));
    test_assert_true(str_contains($source, 'paid_at = COALESCE(paid_at, ?)'));
    test_assert_false(str_contains($source, 'paid_at = COALESCE(paid_at, UTC_TIMESTAMP(6))'));
    test_assert_true(str_contains($source, '$completedAt = app_square_datetime($storedPayment'));
};

$tests['payment-only payer email is a detailed non-charitable payment confirmation'] = static function (): void {
    $validated = app_validate_registration_payload(
        test_team_registration_input('team_sponsor', [4, 3, 2], [0, 2])
    );
    $benefitRegistration = test_email_registration($validated);
    $registration = $benefitRegistration;
    $registration['disclosure_mode'] = 'payment_confirmation_only';
    $registration['benefit_description'] = null;
    $registration['fair_market_value_cents'] = null;
    $registration['deductible_amount_cents'] = null;
    $roster = test_roster_from_validated($validated);
    $payment = [
        'id' => 'square-payment-test',
        'completed_at' => '2026-09-12T18:30:00Z',
        'receipt_url' => 'https://squareup.com/receipt/preview/test',
    ];

    $payer = app_build_paid_confirmation_content($registration, $roster, $payment);
    $heading = 'PAYMENT CONFIRMATION — NOT A CHARITABLE-CONTRIBUTION ACKNOWLEDGMENT';
    $wording = 'COMEC received $1,280.00 on 2026-09-12 18:30:00 UTC for '
        . '2026 COMEC Charity Golf Tournament / Team Sponsor / quantity 3. '
        . 'This payment purchased the selected admissions, entries, and/or listed sponsorship-package benefits. '
        . 'COMEC has not represented any portion as a deductible charitable contribution. '
        . 'Consult your tax adviser regarding your own tax treatment.';
    test_assert_true(str_contains($payer['text'], $heading));
    test_assert_true(str_contains($payer['html'], $heading));
    test_assert_true(str_contains($payer['text'], $wording));
    foreach ([
        'Event: 2026 COMEC Charity Golf Tournament',
        'Package: Team Sponsor',
        'Package quantity: 3',
        'Team 3: COMEC Champions 3',
        'Team 3 Golfer 2',
        'Square receipt: https://squareup.com/receipt/preview/test',
    ] as $detail) {
        test_assert_true(str_contains($payer['text'], $detail), 'Payment confirmation omitted: ' . $detail);
    }
    foreach (['Quid-pro-quo disclosure', 'Estimated fair market value', 'Maximum amount potentially eligible'] as $taxCopy) {
        test_assert_false(str_contains($payer['text'], $taxCopy), 'Payment-only email included tax copy: ' . $taxCopy);
    }
    test_assert_false(str_contains($payer['text'], 'Payment/contribution date'));

    $paymentStaff = app_build_internal_paid_content($registration, $roster, $payment);
    $benefitStaff = app_build_internal_paid_content($benefitRegistration, $roster, $payment);
    test_assert_same($benefitStaff, $paymentStaff, 'Staff paid notifications must remain mode-independent.');

    $invalid = $registration;
    $invalid['disclosure_mode'] = 'unexpected';
    test_expect_runtime_exception(
        static fn (): array => app_build_paid_confirmation_content($invalid, $roster, $payment)
    );
};

$tests['paid emails preserve team grouping, per-team add-ons, and aggregate quantities'] = static function (): void {
    $validated = app_validate_registration_payload(
        test_team_registration_input('team_sponsor', [4, 3, 2], [0, 2])
    );
    $registration = test_email_registration($validated);
    $roster = test_roster_from_validated($validated);
    $payment = [
        'id' => 'square-payment-test',
        'completed_at' => '2026-09-12T18:30:00Z',
        'receipt_url' => 'https://squareup.com/receipt/preview/test',
    ];
    $payer = app_build_paid_confirmation_content($registration, $roster, $payment);
    $staff = app_build_internal_paid_content($registration, $roster, $payment);

    foreach ([$payer['text'], $staff['text']] as $body) {
        foreach ([
            'Event: 2026 COMEC Charity Golf Tournament',
            'Registration reference: GOLF26-TEST123',
            'Pat Payer',
            'square-payment-test',
            'Package quantity: 3',
            'Team count: 3',
            'Total golfers: 9',
            'Package capacity: 12',
            'Team 1: COMEC Champions 1',
            'Team 2: COMEC Champions 2',
            'Team 3: COMEC Champions 3',
            'Team 3 Golfer 2',
            'Eight Team Mulligans',
        ] as $required) {
            test_assert_true(str_contains($body, $required), 'Paid email omitted: ' . $required);
        }
        test_assert_true(
            str_contains($body, 'Eight Team Mulligans — $40.00 each × 2 = $80.00'),
            'Aggregate mulligan quantity/total is missing.'
        );
        test_assert_same(2, substr_count($body, 'Team add-on: Eight Team Mulligans'));
        test_assert_false(str_contains($body, 'Ticket package count:'));
    }
    test_assert_true(str_contains($payer['text'], 'Quid-pro-quo disclosure'));
    test_assert_true(str_contains($payer['html'], '<h3>Team 2: COMEC Champions 2</h3>'));
};

$tests['paid gala emails preserve ticket groups, uneven guests, and multiplied capacity'] = static function (): void {
    $cases = [
        ['gala_couple', [1, 2], 'Couple package', 3, 4],
        ['gala_vip_couple', [2, 1, 2], 'Couple package', 5, 6],
        ['gala_single', [1, 1, 1], 'Ticket', 3, 3],
    ];
    foreach ($cases as $caseIndex => [$packageCode, $counts, $groupLabel, $attending, $capacity]) {
        $validated = app_validate_registration_payload(
            test_ticket_group_registration_input($packageCode, $counts)
        );
        $registration = test_email_registration($validated);
        $registration['public_reference'] = 'GALA26-TEST' . ($caseIndex + 1);
        $roster = test_roster_from_validated($validated);
        $payment = [
            'id' => 'gala-payment-' . ($caseIndex + 1),
            'completed_at' => '2026-12-20T01:30:00Z',
        ];
        $payer = app_build_paid_confirmation_content($registration, $roster, $payment);
        $staff = app_build_internal_paid_content($registration, $roster, $payment);

        foreach ([$payer['text'], $staff['text']] as $body) {
            foreach ([
                'Event: 2026 COMEC Christmas Gala',
                'GALA26-TEST' . ($caseIndex + 1),
                'Pat Payer',
                'gala-payment-' . ($caseIndex + 1),
                'Package quantity: ' . count($counts),
                'Ticket package count: ' . count($counts),
                'Total guests attending: ' . $attending,
                'Package capacity: ' . $capacity,
            ] as $required) {
                test_assert_true(str_contains($body, $required), 'Gala paid email omitted: ' . $required);
            }
            $previousHeading = -1;
            foreach ($counts as $groupIndex => $participantCount) {
                $heading = $groupLabel . ' ' . ($groupIndex + 1) . ':';
                $headingPosition = strpos($body, $heading);
                test_assert_true(is_int($headingPosition) && $headingPosition > $previousHeading);
                $previousHeading = $headingPosition;
                for ($participantIndex = 1; $participantIndex <= $participantCount; $participantIndex++) {
                    $name = sprintf('Ticket Group %d Guest %d', $groupIndex + 1, $participantIndex);
                    test_assert_true(str_contains($body, '- ' . $name), 'Gala paid email omitted guest: ' . $name);
                }
            }
            test_assert_false(str_contains($body, 'Team count:'));
        }
        test_assert_true(str_contains($payer['text'], 'Quid-pro-quo disclosure'));
        test_assert_true(str_contains($payer['html'], '<h3>' . $groupLabel . ' 1</h3>'));
    }
};

$tests['email addresses, headers, HTML, and MIME fields resist injection'] = static function (): void {
    test_assert_same('person@example.org', app_valid_mailbox(' person@example.org ', 'test'));
    test_expect_runtime_exception(static fn (): string => app_valid_mailbox("victim@example.org\r\nBcc: thief@example.org", 'test'));
    test_expect_runtime_exception(static fn (): string => app_valid_mailbox('not-an-email', 'test'));

    $cleanSubject = app_clean_mail_header("Registration paid\r\nBcc: thief@example.org");
    test_assert_same('Registration paid Bcc: thief@example.org', $cleanSubject);
    test_assert_false(str_contains($cleanSubject, "\r"));
    test_assert_false(str_contains($cleanSubject, "\n"));
    test_assert_same('&lt;script&gt;&amp;&quot;&#039;', app_email_escape('<script>&"\''));

    $message = app_build_mime_message(
        'recipient@example.org',
        'sender@example.org',
        "COMEC\r\nBcc: thief@example.org",
        null,
        $cleanSubject,
        'Plain text',
        '<p>HTML</p>'
    );
    test_assert_false(str_contains(implode("\r\n", $message['headers']), "\r\nBcc:"));
    test_assert_true(str_contains($message['body'], 'multipart') === false, 'MIME body should contain only parts.');
};

$passed = 0;
$failed = 0;
$startedAt = microtime(true);

foreach ($tests as $name => $test) {
    try {
        $test();
        $passed++;
        fwrite(STDOUT, "PASS  {$name}\n");
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL  {$name}\n      " . $exception->getMessage() . "\n");
    }
}

$duration = number_format((microtime(true) - $startedAt) * 1000, 1);
$total = $passed + $failed;
fwrite(STDOUT, "\n{$passed}/{$total} tests passed in {$duration} ms.\n");
exit($failed === 0 ? 0 : 1);
