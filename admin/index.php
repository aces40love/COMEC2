<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/app/packages.php';

admin_require_auth();
admin_html_headers();

try {
    $filters = admin_filters();
    $registrations = admin_registration_query(app_db(), $filters);
} catch (Throwable $exception) {
    app_log('error', 'Registration dashboard query failed', ['exception' => get_class($exception)]);
    http_response_code(500);
    $filters = ['event' => '', 'status' => '', 'package' => '', 'q' => ''];
    $registrations = [];
    $loadError = true;
}

$paidCount = 0;
$paidCents = 0;
$pendingCount = 0;
foreach ($registrations as $registration) {
    if (in_array($registration['status'], ['paid', 'partially_refunded'], true)) {
        $paidCount++;
        $paidCents += max(0, (int) $registration['amount_cents'] - (int) ($registration['refunded_amount_cents'] ?? 0));
    } elseif (str_starts_with((string) $registration['status'], 'pending')) {
        $pendingCount++;
    }
}

$query = http_build_query(array_filter($filters, static fn (string $value): bool => $value !== ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Event Registrations | COMEC</title>
  <link rel="stylesheet" href="../assets/css/styles.css">
  <link rel="stylesheet" href="admin.css">
</head>
<body class="admin-body">
  <main class="admin-shell">
    <header class="admin-header">
      <div>
        <span class="eyebrow">Private staff dashboard</span>
        <h1>2026 Event Registrations</h1>
        <p>Payment status, payer details, sponsorship information, and golf or gala rosters.</p>
      </div>
      <a class="button button--outline" href="export.php<?= $query !== '' ? '?' . admin_h($query) : '' ?>">Download CSV</a>
    </header>

    <?php if (isset($loadError)): ?>
      <div class="admin-alert" role="alert">The registration data could not be loaded. Check the server log and database configuration.</div>
    <?php endif; ?>

    <section class="admin-summary" aria-label="Filtered registration summary">
      <div><strong><?= count($registrations) ?></strong><span>registrations shown</span></div>
      <div><strong><?= $paidCount ?></strong><span>paid</span></div>
      <div><strong><?= $pendingCount ?></strong><span>pending</span></div>
      <div><strong><?= admin_h(admin_money($paidCents)) ?></strong><span>net paid after recorded refunds</span></div>
    </section>

    <form class="admin-filters" method="get" action="index.php">
      <label>Search
        <input type="search" name="q" value="<?= admin_h($filters['q']) ?>" placeholder="Name, company, email, event, reference">
      </label>
      <label>Event
        <select name="event">
          <option value="">All events</option>
          <?php foreach (app_event_definitions() as $eventCode => $event): ?>
            <option value="<?= admin_h($eventCode) ?>"<?= $filters['event'] === $eventCode ? ' selected' : '' ?>><?= admin_h($event['short_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Status
        <select name="status">
          <option value="">All statuses</option>
          <?php foreach (['pending_payment', 'paid', 'partially_refunded', 'refunded', 'checkout_error'] as $status): ?>
            <option value="<?= admin_h($status) ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>><?= admin_h(ucwords(str_replace('_', ' ', $status))) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Package
        <select name="package">
          <option value="">All packages</option>
          <?php foreach (app_event_definitions() as $event): ?>
            <optgroup label="<?= admin_h($event['short_name']) ?>">
              <?php foreach ($event['packages'] as $code => $package): ?>
                <option value="<?= admin_h($code) ?>"<?= $filters['package'] === $code ? ' selected' : '' ?>><?= admin_h($package['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="button" type="submit">Apply filters</button>
      <a class="text-link" href="index.php">Clear</a>
    </form>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <caption class="sr-only">Event registration records</caption>
        <thead>
          <tr>
            <th scope="col">Registration</th>
            <th scope="col">Payer</th>
            <th scope="col">Package</th>
            <th scope="col">Registration details</th>
            <th scope="col">Players / guests</th>
            <th scope="col">Payment</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($registrations === []): ?>
            <tr><td colspan="6" class="admin-empty">No registrations match these filters.</td></tr>
          <?php endif; ?>
          <?php foreach ($registrations as $registration): ?>
            <?php
              $participants = $registration['participants'];
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
              $attendingCount = count($participants) + $teamParticipantCount + $ticketGroupParticipantCount;
              $isCouplePackage = in_array(
                  $registration['package_code'],
                  ['gala_couple', 'gala_vip_couple'],
                  true
              );
              $capacity = (int) $registration['participant_capacity'];
              $addons = admin_registration_addons($registration['addons_json'] ?? '');
              $receiptUrl = admin_safe_square_receipt_url($registration['receipt_url'] ?? null);
              $isBenefitFmv = ($registration['disclosure_mode'] ?? null) === 'benefit_fmv';
            ?>
            <tr>
              <td>
                <strong><?= admin_h($registration['public_reference']) ?></strong>
                <span><?= admin_h($registration['event_name']) ?></span>
                <span class="admin-status admin-status--<?= admin_h($registration['status']) ?>"><?= admin_h(str_replace('_', ' ', $registration['status'])) ?></span>
                <small>Created <?= admin_h($registration['created_at']) ?> UTC</small>
              </td>
              <td>
                <strong><?= admin_h($registration['payer_first_name'] . ' ' . $registration['payer_last_name']) ?></strong>
                <?php if ($registration['payer_company']): ?><span><?= admin_h($registration['payer_company']) ?></span><?php endif; ?>
                <a href="mailto:<?= admin_h($registration['payer_email']) ?>"><?= admin_h($registration['payer_email']) ?></a>
                <a href="tel:<?= admin_h($registration['payer_phone']) ?>"><?= admin_h($registration['payer_phone']) ?></a>
                <small><?= admin_h($registration['payer_address_line1']) ?><br><?= admin_h($registration['payer_city'] . ', ' . $registration['payer_state'] . ' ' . $registration['payer_postal_code']) ?></small>
              </td>
              <td>
                <strong><?= admin_h($registration['package_name']) ?></strong>
                <span>Unit price: <?= admin_h(admin_money((int) $registration['package_unit_amount_cents'])) ?></span>
                <span>Quantity: <?= (int) $registration['package_quantity'] ?></span>
                <?php if ($teams !== []): ?><span>Teams: <?= count($teams) ?></span><?php endif; ?>
                <?php if ($ticketGroups !== []): ?><span>Ticket packages: <?= count($ticketGroups) ?></span><?php endif; ?>
                <small>Base total: <?= admin_h(admin_money((int) $registration['base_amount_cents'])) ?></small>
                <?php foreach ($addons as $addon): ?>
                  <small>+ <?= admin_h($addon['name']) ?> x <?= (int) $addon['quantity'] ?>
                    (<?= admin_h(admin_money((int) $addon['unit_amount_cents'])) ?> each):
                    <?= admin_h(admin_money((int) $addon['total_amount_cents'])) ?></small>
                <?php endforeach; ?>
                <?php if ((int) $registration['addon_amount_cents'] > 0): ?>
                  <small>Add-ons total: <?= admin_h(admin_money((int) $registration['addon_amount_cents'])) ?></small>
                <?php endif; ?>
                <strong>Total: <?= admin_h(admin_money((int) $registration['amount_cents'])) ?></strong>
                <?php if ($registration['contest_choice']): ?><small><?= admin_h(ucwords(str_replace('_', ' ', $registration['contest_choice']))) ?></small><?php endif; ?>
                <?php if ($isBenefitFmv && $registration['fair_market_value_cents'] !== null): ?>
                  <small>Benefits FMV: <?= admin_h(admin_money((int) $registration['fair_market_value_cents'])) ?></small>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($teams === [] && $registration['team_name']): ?><strong><?= admin_h($registration['team_name']) ?></strong><?php endif; ?>
                <?php if ($registration['sponsor_display']): ?><span>Sign: <?= admin_h($registration['sponsor_display']) ?></span><?php endif; ?>
                <?php if ($registration['notes']): ?><small><?= nl2br(admin_h($registration['notes'])) ?></small><?php endif; ?>
              </td>
              <td>
                <strong>Number attending: <?= $attendingCount ?></strong>
                <small>Package capacity: <?= $capacity ?></small>
                <?php if ($teams !== []): ?>
                  <?php foreach ($teams as $team): ?>
                    <section class="admin-team-roster">
                      <strong>Team <?= (int) $team['position'] ?>: <?= admin_h($team['name']) ?></strong>
                      <small>Golfers attending: <?= count($team['participants']) ?>; team capacity: <?= (int) $team['participant_capacity'] ?></small>
                      <?php if ($team['addons'] !== []): ?>
                        <?php foreach ($team['addons'] as $teamAddon): ?>
                          <small>Mulligans: <?= admin_h($teamAddon['name']) ?>
                            (<?= admin_h(admin_money((int) $teamAddon['unit_amount_cents'])) ?>)</small>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <small>Mulligans: none</small>
                      <?php endif; ?>
                      <?php if ($team['participants'] !== []): ?>
                        <ol><?php foreach ($team['participants'] as $player): ?><li><?= admin_h($player['name']) ?></li><?php endforeach; ?></ol>
                      <?php else: ?>
                        <span>No golfers entered</span>
                      <?php endif; ?>
                    </section>
                  <?php endforeach; ?>
                <?php elseif ($ticketGroups !== []): ?>
                  <?php foreach ($ticketGroups as $ticketGroup): ?>
                    <section class="admin-team-roster">
                      <strong><?= $isCouplePackage ? 'Couple package ' : 'Ticket ' ?><?= (int) $ticketGroup['position'] ?></strong>
                      <small>Guests attending: <?= count($ticketGroup['participants']) ?>; package capacity: <?= (int) $ticketGroup['participant_capacity'] ?></small>
                      <?php if ($ticketGroup['participants'] !== []): ?>
                        <ol><?php foreach ($ticketGroup['participants'] as $guest): ?><li><?= admin_h($guest['name']) ?></li><?php endforeach; ?></ol>
                      <?php else: ?>
                        <span>No guests entered</span>
                      <?php endif; ?>
                    </section>
                  <?php endforeach; ?>
                <?php elseif ($participants !== []): ?>
                  <ol><?php foreach ($participants as $participant): ?><li><?= admin_h($participant['name']) ?></li><?php endforeach; ?></ol>
                <?php else: ?><span>No attendees for this package</span><?php endif; ?>
              </td>
              <td>
                <?php if ($registration['square_payment_id']): ?><span>ID: <?= admin_h($registration['square_payment_id']) ?></span><?php endif; ?>
                <?php if ((int) ($registration['refunded_amount_cents'] ?? 0) > 0): ?><span>Refunded: <?= admin_h(admin_money((int) $registration['refunded_amount_cents'])) ?></span><?php endif; ?>
                <?php if ($receiptUrl): ?><a href="<?= admin_h($receiptUrl) ?>" rel="noopener noreferrer" target="_blank">Square receipt</a><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="admin-footnote">For privacy, export files should be stored only in an approved COMEC location and deleted when no longer needed.</p>
  </main>
</body>
</html>
