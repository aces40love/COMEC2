<?php

declare(strict_types=1);

function app_event_definitions(): array
{
    $plain = [
        'requires_team_name' => false,
        'requires_sponsor_display' => false,
        'requires_contest_choice' => false,
        'allowed_addons' => [],
        'team_package' => false,
        'team_min' => 0,
        'team_max' => 0,
        'ticket_group_package' => false,
        'ticket_group_min' => 0,
        'ticket_group_max' => 0,
    ];
    return [
        'golf-2026' => [
            'name' => '2026 COMEC Charity Golf Tournament',
            'short_name' => 'Golf',
            'date' => 'September 12, 2026',
            'reference_prefix' => 'GOLF26',
            'packages' => [
                'corporate_sponsor' => array_replace($plain, [
                    'name' => 'Corporate Sponsor', 'amount_cents' => 100000,
                    'participant_count' => 4, 'participant_min' => 1,
                    'requires_sponsor_display' => true,
                    'allowed_addons' => ['team_mulligans'],
                    'team_package' => true, 'team_min' => 1, 'team_max' => 10,
                ]),
                'contest_sponsor' => array_replace($plain, [
                    'name' => 'Contest Sponsor', 'amount_cents' => 50000,
                    'participant_count' => 0, 'participant_min' => 0,
                    'requires_sponsor_display' => true, 'requires_contest_choice' => true,
                ]),
                'drink_cart_sponsor' => array_replace($plain, [
                    'name' => 'Drink-Cart Sponsor', 'amount_cents' => 50000,
                    'participant_count' => 0, 'participant_min' => 0,
                    'requires_sponsor_display' => true,
                ]),
                'team_sponsor' => array_replace($plain, [
                    'name' => 'Team Sponsor', 'amount_cents' => 40000,
                    'participant_count' => 4, 'participant_min' => 1,
                    'requires_sponsor_display' => true,
                    'allowed_addons' => ['team_mulligans'],
                    'team_package' => true, 'team_min' => 1, 'team_max' => 10,
                ]),
                'hole_sponsor' => array_replace($plain, [
                    'name' => 'Hole Sponsor', 'amount_cents' => 25000,
                    'participant_count' => 0, 'participant_min' => 0,
                    'requires_sponsor_display' => true,
                ]),
                'individual_player' => array_replace($plain, [
                    'name' => 'Individual Player (Advance Registration)', 'amount_cents' => 10000,
                    'participant_count' => 1, 'participant_min' => 1,
                ]),
            ],
        ],
        'gala-2026' => [
            'name' => '2026 COMEC Christmas Gala',
            'short_name' => 'Gala',
            'date' => 'December 19, 2026',
            'reference_prefix' => 'GALA26',
            'packages' => [
                'gala_single' => array_replace($plain, [
                    'name' => 'Gala Single Ticket', 'amount_cents' => 13500,
                    'participant_count' => 1, 'participant_min' => 1,
                    'ticket_group_package' => true, 'ticket_group_min' => 1, 'ticket_group_max' => 10,
                ]),
                'gala_couple' => array_replace($plain, [
                    'name' => 'Gala Couple Tickets', 'amount_cents' => 25000,
                    'participant_count' => 2, 'participant_min' => 1,
                    'ticket_group_package' => true, 'ticket_group_min' => 1, 'ticket_group_max' => 10,
                ]),
                'gala_vip_single' => array_replace($plain, [
                    'name' => 'Gala VIP Single Ticket', 'amount_cents' => 17500,
                    'participant_count' => 1, 'participant_min' => 1,
                    'ticket_group_package' => true, 'ticket_group_min' => 1, 'ticket_group_max' => 10,
                ]),
                'gala_vip_couple' => array_replace($plain, [
                    'name' => 'Gala VIP Couple Tickets', 'amount_cents' => 30000,
                    'participant_count' => 2, 'participant_min' => 1,
                    'ticket_group_package' => true, 'ticket_group_min' => 1, 'ticket_group_max' => 10,
                ]),
            ],
        ],
    ];
}

function app_golf_packages(): array
{
    return app_event_definitions()['golf-2026']['packages'];
}

function app_registration_addons(): array
{
    return [
        'team_mulligans' => ['name' => 'Eight Team Mulligans', 'amount_cents' => 4000],
    ];
}

function app_contest_choices(): array
{
    return [
        'hole_in_one' => 'Hole-in-One',
        'longest_drive' => 'Longest Drive',
        'closest_to_pin' => 'Closest to the Pin',
        'putting_contest' => 'Putting Contest',
    ];
}

function app_validate_registration_payload(array $input): array
{
    $errors = [];

    $website = $input['website'] ?? '';
    if (!is_string($website) || trim($website) !== '') {
        throw new ApiException(422, 'invalid_registration', 'The registration could not be submitted.');
    }

    if (($input['consent'] ?? null) !== true) {
        $errors['consent'] = 'Consent is required.';
    }

    $eventCode = app_clean_text($input['event_code'] ?? null, 40, 'event_code', $errors, true);
    $events = app_event_definitions();
    $event = $events[$eventCode] ?? null;
    if ($event === null) {
        $errors['event_code'] = 'Choose an available 2026 COMEC event.';
    }

    $packageCode = app_clean_text($input['package_code'] ?? null, 60, 'package_code', $errors, true);
    $packages = $event['packages'] ?? [];
    $package = $packages[$packageCode] ?? null;
    if ($package === null) {
        $errors['package_code'] = 'Choose an available registration package.';
    }

    $payerInput = app_object_field($input, 'payer', $errors);
    $registrationInput = app_object_field($input, 'registration', $errors, false);

    $firstName = app_clean_name($payerInput['first_name'] ?? null, 'payer.first_name', $errors, true);
    $lastName = app_clean_name($payerInput['last_name'] ?? null, 'payer.last_name', $errors, true);
    $company = app_clean_text($payerInput['company'] ?? '', 150, 'payer.company', $errors, false);

    $email = '';
    if (!isset($payerInput['email']) || !is_string($payerInput['email'])) {
        $errors['payer.email'] = 'Enter a valid email address.';
    } else {
        $email = strtolower(trim($payerInput['email']));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['payer.email'] = 'Enter a valid email address.';
        }
    }

    $phone = app_normalize_phone($payerInput['phone'] ?? null);
    if ($phone === null) {
        $errors['payer.phone'] = 'Enter a valid phone number, including area code.';
        $phone = '';
    }

    $addressLine1 = app_clean_text($payerInput['address_line1'] ?? null, 180, 'payer.address_line1', $errors, true);
    $city = app_clean_text($payerInput['city'] ?? null, 100, 'payer.city', $errors, true);
    $state = strtoupper(app_clean_text($payerInput['state'] ?? null, 2, 'payer.state', $errors, true));
    if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
        $errors['payer.state'] = 'Enter a two-letter state abbreviation.';
    }
    $postalCode = app_clean_text($payerInput['postal_code'] ?? null, 10, 'payer.postal_code', $errors, true);
    if ($postalCode !== '' && !preg_match('/^\d{5}(?:-\d{4})?$/', $postalCode)) {
        $errors['payer.postal_code'] = 'Enter a valid ZIP code.';
    }

    $teamName = app_clean_text($registrationInput['team_name'] ?? '', 150, 'registration.team_name', $errors, false);
    $sponsorDisplay = app_clean_text(
        $registrationInput['sponsor_display'] ?? '',
        180,
        'registration.sponsor_display',
        $errors,
        false
    );
    $contestChoice = app_clean_text(
        $registrationInput['contest_choice'] ?? '',
        40,
        'registration.contest_choice',
        $errors,
        false
    );
    $notes = app_clean_multiline($registrationInput['notes'] ?? '', 1000, 'registration.notes', $errors);

    $addonsInput = $input['addons'] ?? [];
    if (!is_array($addonsInput) || !array_is_list($addonsInput)) {
        $errors['addons'] = 'Add-ons must be a list.';
        $addonsInput = [];
    }
    $participantsInput = $input['participants'] ?? [];
    if (!is_array($participantsInput) || !array_is_list($participantsInput)) {
        $errors['participants'] = 'Participant information must be a list.';
        $participantsInput = [];
    }
    $teamsInput = $input['teams'] ?? [];
    if (!is_array($teamsInput) || !array_is_list($teamsInput)) {
        $errors['teams'] = 'Teams must be a list.';
        $teamsInput = [];
    }
    $ticketGroupsInput = $input['ticket_groups'] ?? [];
    if (!is_array($ticketGroupsInput) || !array_is_list($ticketGroupsInput)) {
        $errors['ticket_groups'] = 'Ticket groups must be a list.';
        $ticketGroupsInput = [];
    }

    $availableAddons = app_registration_addons();
    $isTeamPackage = $package !== null && ($package['team_package'] ?? false) === true;
    $isTicketGroupPackage = $package !== null && ($package['ticket_group_package'] ?? false) === true;
    $teams = [];
    $ticketGroups = [];
    $participants = [];
    $addonQuantities = [];

    if ($isTeamPackage) {
        $teamCount = count($teamsInput);
        if ($teamCount < (int) $package['team_min'] || $teamCount > (int) $package['team_max']) {
            $errors['team_count'] = 'Choose between 1 and 10 teams.';
        }
        if ($participantsInput !== []) {
            $errors['participants'] = 'Team-package golfers must be entered within their teams.';
        }
        if ($addonsInput !== []) {
            $errors['addons'] = 'Team-package add-ons must be selected within each team.';
        }
        if ($teamName !== '') {
            $errors['registration.team_name'] = 'Use the name field within each team.';
        }
        if ($ticketGroupsInput !== []) {
            $errors['ticket_groups'] = 'Ticket groups are only valid for a gala package.';
        }

        foreach (array_slice($teamsInput, 0, 10) as $teamIndex => $teamInput) {
            $teamField = 'teams.' . $teamIndex;
            if (!is_array($teamInput) || array_is_list($teamInput)) {
                $errors[$teamField] = 'Enter valid team information.';
                continue;
            }
            $normalizedTeamName = app_clean_text(
                $teamInput['name'] ?? null,
                150,
                $teamField . '.name',
                $errors,
                true
            );

            $teamParticipantsInput = $teamInput['participants'] ?? [];
            if (!is_array($teamParticipantsInput) || !array_is_list($teamParticipantsInput)) {
                $errors[$teamField . '.participants'] = 'Team participants must be a list.';
                $teamParticipantsInput = [];
            }
            $teamParticipants = [];
            foreach (array_slice($teamParticipantsInput, 0, 4) as $participantIndex => $participantInput) {
                $participantField = $teamField . '.participants.' . $participantIndex . '.name';
                if (!is_array($participantInput) || array_is_list($participantInput)) {
                    $errors[$participantField] = 'Enter a golfer name.';
                    continue;
                }
                $participantName = app_clean_name(
                    $participantInput['name'] ?? null,
                    $participantField,
                    $errors,
                    true,
                    160
                );
                if ($participantName !== '') {
                    $teamParticipants[] = ['name' => $participantName];
                }
            }
            if (count($teamParticipantsInput) < 1 || count($teamParticipantsInput) > 4
                || count($teamParticipantsInput) !== count($teamParticipants)) {
                $errors[$teamField . '.participants'] = 'Enter between 1 and 4 named golfers for this team.';
            }

            $teamAddonsInput = $teamInput['addons'] ?? [];
            if (!is_array($teamAddonsInput) || !array_is_list($teamAddonsInput)) {
                $errors[$teamField . '.addons'] = 'Team add-ons must be a list.';
                $teamAddonsInput = [];
            }
            $teamAddons = [];
            foreach ($teamAddonsInput as $addonCode) {
                if (!is_string($addonCode)
                    || !isset($availableAddons[$addonCode])
                    || !in_array($addonCode, $package['allowed_addons'], true)
                    || in_array($addonCode, $teamAddons, true)) {
                    $errors[$teamField . '.addons'] = 'Choose each available team add-on at most once.';
                    continue;
                }
                $teamAddons[] = $addonCode;
                $addonQuantities[$addonCode] = ($addonQuantities[$addonCode] ?? 0) + 1;
            }

            $teams[] = [
                'name' => $normalizedTeamName,
                'participants' => $teamParticipants,
                'addons' => $teamAddons,
            ];
        }
    } elseif ($isTicketGroupPackage) {
        $ticketGroupCount = count($ticketGroupsInput);
        if ($ticketGroupCount < (int) $package['ticket_group_min']
            || $ticketGroupCount > (int) $package['ticket_group_max']) {
            $errors['ticket_group_count'] = 'Choose between 1 and 10 ticket groups.';
        }
        if ($participantsInput !== []) {
            $errors['participants'] = 'Gala guests must be entered within their ticket groups.';
        }
        if ($addonsInput !== []) {
            $errors['addons'] = 'Gala ticket packages do not include add-ons.';
        }
        if ($teamsInput !== []) {
            $errors['teams'] = 'Teams are only valid for a golf team package.';
        }
        if ($teamName !== '') {
            $errors['registration.team_name'] = 'A team name is not valid for gala tickets.';
        }

        $participantMin = (int) $package['participant_min'];
        $participantCount = (int) $package['participant_count'];
        foreach (array_slice($ticketGroupsInput, 0, 10) as $ticketGroupIndex => $ticketGroupInput) {
            $ticketGroupField = 'ticket_groups.' . $ticketGroupIndex;
            if (!is_array($ticketGroupInput) || array_is_list($ticketGroupInput)) {
                $errors[$ticketGroupField] = 'Enter valid ticket-group information.';
                continue;
            }

            $ticketGroupParticipantsInput = $ticketGroupInput['participants'] ?? [];
            if (!is_array($ticketGroupParticipantsInput) || !array_is_list($ticketGroupParticipantsInput)) {
                $errors[$ticketGroupField . '.participants'] = 'Ticket-group participants must be a list.';
                $ticketGroupParticipantsInput = [];
            }
            $ticketGroupParticipants = [];
            foreach (array_slice($ticketGroupParticipantsInput, 0, $participantCount) as $index => $participantInput) {
                $participantField = $ticketGroupField . '.participants.' . $index . '.name';
                if (!is_array($participantInput) || array_is_list($participantInput)) {
                    $errors[$participantField] = 'Enter a guest name.';
                    continue;
                }
                $name = app_clean_name(
                    $participantInput['name'] ?? null,
                    $participantField,
                    $errors,
                    true,
                    160
                );
                if ($name !== '') {
                    $ticketGroupParticipants[] = ['name' => $name];
                }
            }
            if (count($ticketGroupParticipantsInput) < $participantMin
                || count($ticketGroupParticipantsInput) > $participantCount
                || count($ticketGroupParticipantsInput) !== count($ticketGroupParticipants)) {
                if ($participantMin === $participantCount) {
                    $errors[$ticketGroupField . '.participants'] = sprintf(
                        'This ticket group requires exactly %d named guest%s.',
                        $participantCount,
                        $participantCount === 1 ? '' : 's'
                    );
                } else {
                    $errors[$ticketGroupField . '.participants'] = sprintf(
                        'Enter between %d and %d named guests for this ticket group.',
                        $participantMin,
                        $participantCount
                    );
                }
            }
            $ticketGroups[] = ['participants' => $ticketGroupParticipants];
        }
    } else {
        if ($teamsInput !== []) {
            $errors['teams'] = 'Teams are only valid for a golf team package.';
        }
        if ($ticketGroupsInput !== []) {
            $errors['ticket_groups'] = 'Ticket groups are only valid for a gala package.';
        }

        $selectedAddons = [];
        foreach ($addonsInput as $addonCode) {
            if (!is_string($addonCode)
                || !isset($availableAddons[$addonCode])
                || isset($selectedAddons[$addonCode])
                || $package === null
                || !in_array($addonCode, $package['allowed_addons'], true)) {
                $errors['addons'] = 'Choose only available add-ons.';
                continue;
            }
            $selectedAddons[$addonCode] = true;
            $addonQuantities[$addonCode] = 1;
        }

        if (count($participantsInput) > 4) {
            $errors['participants'] = 'No more than four participants may be entered.';
        }
        foreach (array_slice($participantsInput, 0, 4) as $index => $participantInput) {
            if (!is_array($participantInput) || array_is_list($participantInput)) {
                $errors['participants.' . $index . '.name'] = 'Enter a participant name.';
                continue;
            }
            $name = app_clean_name(
                $participantInput['name'] ?? null,
                'participants.' . $index . '.name',
                $errors,
                true,
                160
            );
            if ($name !== '') {
                $participants[] = ['name' => $name];
            }
        }

        if ($package !== null) {
            $participantCount = (int) $package['participant_count'];
            $participantMin = (int) $package['participant_min'];
            if (count($participants) < $participantMin || count($participants) > $participantCount
                || count($participantsInput) !== count($participants)) {
                if ($participantCount === 0) {
                    $errors['participants'] = 'This sponsorship does not include player registration.';
                } elseif ($participantMin === $participantCount) {
                    $errors['participants'] = sprintf(
                        'This package requires %d participant name%s.',
                        $participantCount,
                        $participantCount === 1 ? '' : 's'
                    );
                } else {
                    $errors['participants'] = sprintf(
                        'Enter at least %d and no more than %d participant names.',
                        $participantMin,
                        $participantCount
                    );
                }
            }
        }
    }

    if ($package !== null) {
        if ($package['requires_team_name'] && $teamName === '') {
            $errors['registration.team_name'] = 'Enter the team name.';
        }
        if ($package['requires_sponsor_display'] && $sponsorDisplay === '') {
            $errors['registration.sponsor_display'] = 'Enter the sponsor name to display.';
        }
        if ($package['requires_contest_choice']) {
            if (!array_key_exists($contestChoice, app_contest_choices())) {
                $errors['registration.contest_choice'] = 'Choose an available contest.';
            }
        } elseif ($contestChoice !== '') {
            $errors['registration.contest_choice'] = 'A contest choice is only valid for a contest sponsorship.';
        }
    }

    if ($errors !== []) {
        throw new ApiException(422, 'validation_failed', 'Please correct the highlighted registration details.', $errors);
    }

    $packageQuantity = $isTeamPackage
        ? count($teams)
        : ($isTicketGroupPackage ? count($ticketGroups) : 1);
    $packageUnitAmountCents = (int) $package['amount_cents'];
    $baseAmountCents = $packageUnitAmountCents * $packageQuantity;
    $addons = [];
    $addonAmountCents = 0;
    foreach ($addonQuantities as $addonCode => $quantity) {
        $addon = $availableAddons[$addonCode];
        $unitAmountCents = (int) $addon['amount_cents'];
        $totalAmountCents = $unitAmountCents * $quantity;
        $addons[$addonCode] = [
            'name' => $addon['name'],
            'unit_amount_cents' => $unitAmountCents,
            'quantity' => $quantity,
            'total_amount_cents' => $totalAmountCents,
        ];
        $addonAmountCents += $totalAmountCents;
    }
    $amountCents = $baseAmountCents + $addonAmountCents;
    $benefit = app_registration_disclosure_snapshot(
        $packageCode,
        $packageQuantity,
        $addonQuantities,
        $amountCents
    );
    $participantCapacity = ($isTeamPackage || $isTicketGroupPackage)
        ? (int) $package['participant_count'] * $packageQuantity
        : (int) $package['participant_count'];

    return [
        'event_code' => $eventCode,
        'event' => $event,
        'package_code' => $packageCode,
        'package' => $package,
        'package_quantity' => $packageQuantity,
        'package_unit_amount_cents' => $packageUnitAmountCents,
        'base_amount_cents' => $baseAmountCents,
        'addons' => $addons,
        'addon_amount_cents' => $addonAmountCents,
        'amount_cents' => $amountCents,
        'disclosure_mode' => $benefit['mode'],
        'benefit' => $benefit,
        'participant_capacity' => $participantCapacity,
        'payer' => [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company' => $company,
            'email' => $email,
            'phone' => $phone,
            'address_line1' => $addressLine1,
            'city' => $city,
            'state' => $state,
            'postal_code' => $postalCode,
        ],
        'registration' => [
            'team_name' => $teamName,
            'sponsor_display' => $sponsorDisplay,
            'contest_choice' => $contestChoice,
            'notes' => $notes,
        ],
        'participants' => $participants,
        'teams' => $teams,
        'ticket_groups' => $ticketGroups,
    ];
}

function app_registration_disclosure_snapshot(
    string $packageCode,
    int $packageQuantity,
    array $addonQuantities,
    int $grossCents,
    ?string $mode = null
): array {
    $mode = app_disclosure_mode_value($mode ?? app_config('EVENT_DISCLOSURE_MODE'));
    if ($packageQuantity < 1 || $packageQuantity > 10 || $grossCents < 0) {
        throw new ApiException(422, 'invalid_registration', 'The registration quantities are invalid.');
    }
    foreach ($addonQuantities as $code => $quantity) {
        if (!is_string($code) || !is_int($quantity) || $quantity < 1 || $quantity > 10) {
            throw new ApiException(422, 'invalid_registration', 'The registration quantities are invalid.');
        }
    }

    if ($mode === 'payment_confirmation_only') {
        return [
            'mode' => $mode,
            'description' => null,
            'fair_market_value_cents' => null,
            'deductible_amount_cents' => null,
        ];
    }

    return ['mode' => $mode] + app_registration_benefit_snapshot(
        $packageCode,
        $packageQuantity,
        $addonQuantities,
        $grossCents
    );
}

function app_registration_benefit_snapshot(
    string $packageCode,
    int $packageQuantity,
    array $addonQuantities,
    int $grossCents
): array
{
    $configured = app_config('PACKAGE_BENEFITS', []);
    $quantities = [$packageCode => $packageQuantity];
    foreach ($addonQuantities as $code => $quantity) {
        if (!is_string($code) || !is_int($quantity) || $quantity < 1) {
            throw new ApiException(422, 'invalid_registration', 'The registration quantities are invalid.');
        }
        $quantities[$code] = $quantity;
    }
    $descriptions = [];
    $fmvCents = 0;
    foreach ($quantities as $code => $quantity) {
        if (!is_int($quantity) || $quantity < 1 || $quantity > 10) {
            throw new ApiException(422, 'invalid_registration', 'The registration quantities are invalid.');
        }
        $entry = is_array($configured) ? ($configured[$code] ?? null) : null;
        $description = is_array($entry) ? trim((string) ($entry['description'] ?? '')) : '';
        $fmv = is_array($entry) ? ($entry['fair_market_value_cents'] ?? null) : null;
        if ($description === '' || app_text_length($description) > 1000
            || !is_int($fmv) || $fmv < 0 || $fmv > 4_294_967_295) {
            app_log('critical', 'CPA-approved package benefit configuration is missing', ['package_code' => $code]);
            throw new ApiException(
                503,
                'benefit_configuration_required',
                'Online checkout is not yet available for this selection. Please contact COMEC.'
            );
        }
        $descriptions[] = $quantity > 1 ? $quantity . ' × ' . $description : $description;
        $lineFmvCents = $fmv * $quantity;
        if ($lineFmvCents > 4_294_967_295 - $fmvCents) {
            throw new ApiException(
                503,
                'benefit_configuration_required',
                'Online checkout is not yet available for this selection. Please contact COMEC.'
            );
        }
        $fmvCents += $lineFmvCents;
    }

    $description = implode('; ', $descriptions);
    if (app_text_length($description) > 1000 || $fmvCents > 4_294_967_295) {
        app_log('critical', 'Combined package benefit configuration exceeds storage limits', [
            'package_code' => $packageCode,
        ]);
        throw new ApiException(
            503,
            'benefit_configuration_required',
            'Online checkout is not yet available for this selection. Please contact COMEC.'
        );
    }

    return [
        'description' => $description,
        'fair_market_value_cents' => $fmvCents,
        'deductible_amount_cents' => max(0, $grossCents - $fmvCents),
    ];
}

function app_public_event_options(string $eventCode, ?string $disclosureMode = null): array
{
    $disclosureMode = app_disclosure_mode_value(
        $disclosureMode ?? app_config('EVENT_DISCLOSURE_MODE')
    );
    $event = app_event_definitions()[$eventCode] ?? null;
    if ($event === null) {
        throw new ApiException(404, 'event_not_found', 'The event could not be found.');
    }

    $packages = [];
    $usedAddons = [];
    foreach ($event['packages'] as $code => $package) {
        $benefit = app_registration_disclosure_snapshot(
            $code,
            1,
            [],
            (int) $package['amount_cents'],
            $disclosureMode
        );
        $packages[] = [
            'code' => $code,
            'name' => $package['name'],
            'amount_cents' => (int) $package['amount_cents'],
            'participant_min' => (int) $package['participant_min'],
            'participant_max' => (int) $package['participant_count'],
            'allowed_addons' => array_values($package['allowed_addons']),
            'benefit_description' => $benefit['description'],
            'fair_market_value_cents' => $benefit['fair_market_value_cents'],
            'max_deductible_cents' => $benefit['deductible_amount_cents'],
        ];
        foreach ($package['allowed_addons'] as $addonCode) {
            $usedAddons[$addonCode] = true;
        }
    }

    $addons = [];
    foreach (array_keys($usedAddons) as $code) {
        $addon = app_registration_addons()[$code];
        $benefit = app_registration_disclosure_snapshot(
            $code,
            1,
            [],
            (int) $addon['amount_cents'],
            $disclosureMode
        );
        $addons[] = [
            'code' => $code,
            'name' => $addon['name'],
            'amount_cents' => (int) $addon['amount_cents'],
            'benefit_description' => $benefit['description'],
            'fair_market_value_cents' => $benefit['fair_market_value_cents'],
            'max_deductible_cents' => $benefit['deductible_amount_cents'],
        ];
    }

    return [
        'ok' => true,
        'event_code' => $eventCode,
        'event_name' => $event['name'],
        'event_date' => $event['date'],
        'currency' => 'USD',
        'disclosure_mode' => $disclosureMode,
        'packages' => $packages,
        'addons' => $addons,
    ];
}

function app_object_field(array $input, string $key, array &$errors, bool $required = true): array
{
    if (!array_key_exists($key, $input) && !$required) {
        return [];
    }
    if (!$required && isset($input[$key]) && $input[$key] === []) {
        // json_decode represents an empty JSON object as an empty PHP array.
        return [];
    }
    if (!isset($input[$key]) || !is_array($input[$key]) || array_is_list($input[$key])) {
        $errors[$key] = 'This section is invalid.';
        return [];
    }
    return $input[$key];
}

function app_clean_text(mixed $value, int $maxLength, string $field, array &$errors, bool $required): string
{
    if (!is_string($value)) {
        if ($required || $value !== null) {
            $errors[$field] = 'Enter a valid value.';
        }
        return '';
    }
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    if ($required && $value === '') {
        $errors[$field] = 'This field is required.';
    } elseif (app_text_length($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        $errors[$field] = 'This value is too long or contains invalid characters.';
    }
    return $value;
}

function app_clean_name(
    mixed $value,
    string $field,
    array &$errors,
    bool $required,
    int $maxLength = 80
): string {
    $name = app_clean_text($value, $maxLength, $field, $errors, $required);
    if ($name !== '' && !preg_match('/^[\p{L}\p{M}0-9][\p{L}\p{M}0-9 .\'’\-()&]*$/u', $name)) {
        $errors[$field] = 'Enter a valid name.';
    }
    return $name;
}

function app_clean_multiline(mixed $value, int $maxLength, string $field, array &$errors): string
{
    if (!is_string($value)) {
        if ($value !== null) {
            $errors[$field] = 'Enter valid notes.';
        }
        return '';
    }
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if (app_text_length($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        $errors[$field] = 'Notes are too long or contain invalid characters.';
    }
    return $value;
}

function app_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function app_normalize_phone(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '' || preg_match('/[^0-9+().\-\s]/', $value)) {
        return null;
    }
    $digits = preg_replace('/\D+/', '', $value);
    if (strlen($digits) === 10) {
        return '+1' . $digits;
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
        return '+' . $digits;
    }
    if (str_starts_with($value, '+') && strlen($digits) >= 8 && strlen($digits) <= 15) {
        return '+' . $digits;
    }
    return null;
}
