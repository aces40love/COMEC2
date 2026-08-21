# COMEC Event Registration System

This build adds separate, reconcilable registration flows for COMEC's 2026 golf tournament and Christmas gala. This version is currently saved to pCloud only; it has not been pushed to the GitHub preview or deployed to Namecheap `public_html` at `www.comec.org`.

## What the system does

```text
COMEC form -> pending registration -> Square-hosted checkout
          -> signed Square webhook -> verified paid registration
          -> payer confirmation + three separate staff notifications
          -> private dashboard and CSV roster/export
```

The COMEC form stores event and roster information, but it never collects or stores card numbers. Square handles card entry. After Square confirms a completed payment, COMEC reliably sends a detailed payment/event confirmation containing the package and participant information; Square may also send its processor receipt depending on the buyer's receipt settings.

### Event categories

| Event code | Package | Price | Participants |
| --- | --- | ---: | ---: |
| `golf-2026` | Corporate sponsor | $1,000 per team | 1-10 teams; 1-4 named golfers per team |
| `golf-2026` | Contest sponsor | $500 | 0 |
| `golf-2026` | Drink-cart sponsor | $500 | 0 |
| `golf-2026` | Team sponsor | $400 per team | 1-10 teams; 1-4 named golfers per team |
| `golf-2026` | Hole sponsor | $250 | 0 |
| `golf-2026` | Individual player | $100 | 1 player |
| `golf-2026` | Eight team mulligans add-on | $40 per selected team | Team packages only; selected independently per team |
| `gala-2026` | Single | $135 per ticket group | 1-10 groups; exactly 1 named guest per group |
| `gala-2026` | Couple | $250 per couple group | 1-10 groups; 1-2 named guests per group; capacity 2 each |
| `gala-2026` | VIP single | $175 per ticket group | 1-10 groups; exactly 1 named guest per group |
| `gala-2026` | VIP couple | $300 per couple group | 1-10 groups; 1-2 named guests per group; capacity 2 each |

Every record snapshots its event, package, unit price, quantity, total package price, aggregate add-ons, included capacity, payer, mailing address, player/guest names, payment identifiers, and tax-disclosure values. Payer/receipt information is kept separate from the people attending. The number attending is represented by the number of saved participant rows; `participant_capacity` is the package's included maximum, not a count of missing names. Each couple group allows one or two named attendees while retaining a capacity snapshot of two; each single-ticket group requires exactly one name; each golf team requires one through four names and contributes four places to the registration capacity; sponsorship-only golf packages accept no player names.

Multi-team golf registrations use canonical ordered `teams` data. Each team has its own required name, ordered golfer list, and optional mulligan selection. The registration stores `package_quantity`, `package_unit_amount_cents`, and the total `base_amount_cents`. Aggregate add-on snapshots store unit price, quantity, and total, while relational `registration_teams` and `participants` rows preserve team and player order. Top-level participants, add-ons, and the deprecated single `team_name` field are rejected for team packages so a roster cannot be ambiguously split between structures.

Gala registrations use canonical ordered `ticket_groups` data. Each group contains its own ordered guest list. One through ten groups may be purchased in one checkout; the authoritative per-group price is multiplied by the group count, and capacity is the package's per-group capacity multiplied by that same quantity. Relational `registration_ticket_groups` rows snapshot group order and capacity, and each gala participant is linked to exactly one group with its position inside that group. Gala submissions reject top-level participants, add-ons, teams, and the deprecated team name so the roster has one unambiguous representation. Golf and other non-gala packages reject nonempty `ticket_groups`.

```json
{"ticket_groups":[{"participants":[{"name":"Guest One"},{"name":"Guest Two"}]}]}
```

Backend consumers load the normalized roster through `app_load_registration_roster()`. It returns three keys: `teams`, `ticket_groups`, and `participants`. Each team has `position`, `name`, `participant_capacity`, `addons`, and ordered `participants`; each ticket group has `position`, `participant_capacity`, and ordered `participants`; the separate top-level `participants` list contains only flat non-team/non-ticket-group attendees such as an individual golfer. Each nested participant has `position` and `name`. The private status response uses this same grouped shape and also exposes `package_quantity`.

Package and add-on benefit descriptions and fair-market values are multiplied by their verified quantities. For example, a three-team purchase with mulligans for two teams snapshots readable `3 × …; 2 × …` benefit text, while three VIP couple groups snapshot `3 × …`; both store the corresponding aggregate FMV and maximum potentially deductible excess.

The registration row carries the authoritative `event_code`, package, public reference, payer, and Square order ID. Each named attendee is linked to that registration by its database ID, and each verified Square payment is linked to the same registration. This is what keeps golf and gala payments and rosters separately identifiable in the dashboard, CSV export, and notification emails.

The current gala flyer also lists Platinum ($7,500), Gold ($5,500), Silver ($3,500), and Bronze ($1,500) sponsorships. They are displayed as call-to-arrange options but are intentionally excluded from online checkout until COMEC supplies each level's included admissions/benefits and its CPA approves the fair-market values.

## Automatic emails

Only a canonically verified Square `COMPLETED` payment is treated as paid.

- Square may email its processor receipt to the payer, depending on buyer settings.
- COMEC emails the payer the reliable detailed payment/event confirmation and acknowledgment.
- COMEC separately emails each of these staff recipients:
  - `pboals@theboalsgroup.com`
  - `comecnonprofit@gmail.com`
  - `underwoodudl@gmail.com`

The staff message identifies the event, payer, company, contact information, mailing address, amount, package/add-ons, Square payment/reference, and all player or gala guest names. Separate queued messages isolate delivery failures and retries.

## Required before sandbox testing

1. PHP 8.1 or newer with cURL, PDO MySQL, OpenSSL, and JSON enabled.
2. A private MySQL/MariaDB database created from `database/001_event_registrations.sql`.
3. A private configuration file based on `config.example.php`, stored outside `public_html`.
4. A random `APP_KEY` and a rate-limit secret of at least 32 characters.
5. Square sandbox access token, location ID, merchant ID, webhook signature key, and exact notification URL.
6. A sender address plus either the hosting account's PHP mail service or SMTP credentials.
7. A private dashboard username and a password hash generated with PHP `password_hash()`.
8. CPA-approved benefit descriptions and good-faith fair-market values for every package and the mulligan add-on.

Do not put credentials in this repository, the public website folder, email, or browser JavaScript. `COMEC_CONFIG_FILE` must point to the private configuration file outside the web root.

## Tax acknowledgment launch gate

The example configuration intentionally uses blank descriptions and `null` fair-market values. Checkout must remain disabled until COMEC's CPA or tax adviser approves every value.

Once configured, the selected package's gross price, benefits, fair-market value, and maximum potentially deductible excess are shown before checkout and snapshotted with the registration. The COMEC confirmation repeats that disclosure. Square's card receipt alone is not a charitable acknowledgment.

## Square setup

Use a Square Developer application with separate sandbox and production credentials. The implementation uses Square's hosted Checkout API, so no Square secret is exposed in the page.

Configure the webhook destination as the exact public HTTPS URL for:

```text
https://www.comec.org/api/square-webhook.php
```

Subscribe the application to `payment.updated` and `refund.updated`. The configured URL must exactly match the URL Square signs. Catalog variation IDs are optional but recommended for clearer Square reporting; the database event/package fields remain the authoritative COMEC reconciliation keys. Square receives golf-team and gala-ticket package lines at their authoritative unit prices with the verified team/group quantity. Mulligan quantity is derived independently from the teams that selected it. The resulting Square order total must match the registration snapshot exactly.

## Background jobs

The webhook endpoint verifies and queues events quickly. cPanel cron must run both workers every minute, using the account's real PHP CLI path and site path:

```text
php /absolute/path/to/public_html/app/cli/process-square-webhooks.php
php /absolute/path/to/public_html/app/cli/process-email-outbox.php
```

Without these jobs, completed payments and emails will remain queued. Never expose the CLI scripts as web endpoints.
Both workers exit nonzero whenever their queue contains a terminally failed item. Configure cPanel cron failure notifications or another monitor for nonzero exits, and investigate failed rows before clearing or requeuing them.

## Staff dashboard

The private dashboard is at `/admin/` after deployment. It supports event, status, package, and text filters plus a CSV export. The export includes payer/contact/address information, packages/add-ons, rosters, payment IDs, refunds, and tax-value snapshots.

The dashboard contains personal information. Use a unique password, HTTPS, least-privilege staff access, and approved storage for downloaded CSV files.

## Required launch tests

1. Complete each package in Square sandbox, including both golf team packages with and without mulligans.
2. Confirm one-, two-, three-, and ten-group gala purchases, including one and two guests per couple group, plus one-, two-, three-, and ten-team golf registrations with uneven rosters and per-team mulligans.
3. Verify duplicate form submissions return the same checkout rather than creating two registrations.
4. Confirm wrong totals, locations, currencies, merchants, webhook signatures, and reused idempotency keys fail safely.
5. Confirm payer and all three staff emails arrive with the correct roster.
6. Run full and partial refund tests and confirm status, email, dashboard, and CSV updates.
7. Confirm the status link keeps its private token out of URL query logs.
8. Reconcile the database, Square dashboard, email queue, and CSV totals.
9. Perform one low-value production transaction and refund before public launch.

## Publishing and synchronization

After local approval, the intended canonical pCloud folder is:

```text
P:\AI Thaddeus\Businesses\COMEC\PUBLISHED SITE
```

The maintained locations have distinct roles: pCloud holds the working site copy, GitHub provides the preview and version history, and Namecheap cPanel `public_html` is the live production site. Temporary build folders are not permanent site copies and should be removed after verification.

The order of operations is: validate the pCloud build, compare and push the approved commit to GitHub, deploy an allowlisted web package to cPanel, then compare SHA-256 manifests. The live site should never be treated as the editing source, and private configuration must never be copied into GitHub or `public_html`.
