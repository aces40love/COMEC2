# Verified CRM system and 90-day fundraising plan

Prepared July 25, 2026. The fastest credible path to cash is COMEC’s existing relationships plus sellable 2026 event inventory—not broad cold advertising.

## What “verified CRM enrichment” means

An enriched field is not accepted because an AI model or data broker guessed it. Every usable record must carry provenance:

| Required field | Example | Rule |
|---|---|---|
| `source_url` or source document | Company leadership page, IRS record, returned event form | Required for name, title, company, nonprofit, and relationship claims |
| `verification_method` | First-party page, direct confirmation, email validator, USPS-standardized address | Required |
| `verified_at` | ISO date/time | Required; stale records return to review |
| `confidence` | Verified / probable / unverified | Only Verified enters high-value outreach without manual review |
| `consent_or_basis` | Donor, event registrant, inquiry, existing relationship, public business contact | Required for communication governance |
| `record_owner` | Named staff or board member | Every priority record must have an accountable owner |
| `next_action` and `next_action_date` | “Philip to call re: corporate sponsor — Jul 29” | No priority record without a next step |

### Data flow

```text
COMEC source data
    ↓
Normalize + deduplicate
    ↓
Verify email / phone / address / role
    ↓
Attach source + date + confidence
    ↓
Human review of exceptions
    ↓
CRM campaign segment
    ↓
Outreach, donation, or registration
    ↓
Receipt + outcome written back to CRM
```

### Non-negotiable controls

- Never overwrite a verified field with an inferred field.
- Keep the original value and the normalized value.
- Dedupe by email, phone, organization domain, and postal address—not name alone.
- Validate deliverability; do not treat an email pattern as a verified mailbox.
- Use business-role information from first-party organization sources for sponsorship outreach.
- Do not enrich records about minors or scrape private social profiles.
- Sync PayPal/event outcomes back to the same constituent record; a spreadsheet copy is not a system of record.
- Review bounced email, disconnected phone, returned mail, duplicate gifts, and unmatched PayPal transactions every week.

## Five-step plan to raise money in 0–90 days

### 1. Days 1–10: build the verified opportunity list

Import prior donors, sponsors, board contacts, event participants, vendors, and community partners. Deduplicate them, verify contact channels, record the relationship owner, and score only on observable facts:

- prior gift or sponsorship;
- relationship to a board/staff member;
- Memphis-area business presence;
- child/family/community funding alignment;
- golf or gala participation history; and
- a verified decision-maker or community-relations contact.

Deliverable: a top-50 call list, a next-100 cultivation list, and a complete exceptions queue. Success measure: at least 90% of top-50 records have a verified channel, source, owner, and next action.

### 2. Days 4–35: close golf inventory with board-led asks

Use the September 12 tournament as the immediate deadline. Package the already approved inventory: $1,000 corporate sponsors, $500 contest/drink-cart sponsors, $400 teams, $250 holes, and $100 advance players.

Run a three-touch sequence for each qualified prospect: warm introduction, personal call/meeting, and written follow-up with the flyer and payment instructions. The relationship owner remains responsible until the opportunity is won or declined.

Success measures: calls completed, proposals sent, dollars committed, dollars received, teams filled, and sponsor inventory remaining. Do not count verbal interest as cash.

### 3. Days 10–45: launch a current-leadership reintroduction campaign

Use the new site, confirmed COMEC video, Greg’s appointment, and Philip’s board leadership to reintroduce COMEC to lapsed donors and community partners. The message should make one concrete request: donate now, sponsor golf, field a team, or introduce one qualified sponsor.

Use tracked links by audience (`utm_source`, `utm_medium`, `utm_campaign`, `utm_content`) and record every response in the CRM. Add a match only after a donor has signed the match terms; never advertise an assumed match.

Success measures: delivered messages, replies, meetings, gifts received, reactivated donors, and introductions—not opens alone.

### 4. Days 35–70: convert the golf event into retained supporters

Capture registrant and sponsor data with clear permission, a consistent event ID, and one CRM record per constituent. At the event, offer a simple QR route to donate or request a follow-up. Within 48 hours, send a receipt/thank-you; within seven days, send a sponsor recap and a specific next ask.

Reconcile the roster, payment processor, checks, and CRM before reporting results. Build segments for players, corporate sponsors, volunteers, and first-time donors.

Success measures: 100% of transactions reconciled, thank-yous within 48 hours, sponsor recaps within seven days, and a documented next action for every sponsor.

### 5. Days 45–90: pre-sell gala sponsorship and submit warm institutional asks

The December 19 gala falls outside the 90-day window, but sponsor cash can be committed and collected sooner. Finalize gala packages, then offer first choice to golf sponsors and existing partners. In parallel, submit a small number of strong corporate/foundation requests where COMEC has a verified relationship and clear fit.

Do not mass-submit generic proposals. Each request should include the documented program need, approved budget, responsible program owner, specific outcome, reporting plan, and current legal/financial attachments.

Success measures: gala sponsor dollars received, sponsor renewal rate, qualified institutional asks submitted, and a dated decision pipeline.

## Weekly command dashboard

Review this every Monday with the Executive Director and Board President:

| Measure | Definition |
|---|---|
| Cash received | Settled processor transactions plus deposited checks |
| Commitments outstanding | Signed or written commitments not yet paid |
| Weighted pipeline | Opportunity amount × stage probability, using fixed stage rules |
| Top-50 coverage | Percent with verified contact, owner, next action, and date |
| Golf inventory | Sold and remaining units by approved level |
| Outreach execution | Calls/meetings completed vs. scheduled |
| Data exceptions | Duplicates, bounces, unmatched gifts, stale roles, missing consent/basis |
| Stewardship SLA | Percent thanked within 48 hours and sponsors recapped within seven days |

## Website-to-CRM implementation requirements

The current website contains no fake forms and makes no claim of CRM automation. When COMEC selects or confirms its CRM, implement these connections:

1. Use hosted forms or a secure server endpoint; never expose a CRM secret in browser JavaScript.
2. Give each form submission a source page, campaign ID, consent statement/version, timestamp, and deduplication key.
3. Connect PayPal transactions through an authenticated server-side webhook or scheduled reconciliation process.
4. Write event registrations, sponsorships, gifts, refunds, and acknowledgments back to the constituent timeline.
5. Create an exception queue for unmatched transactions and failed enrichments; do not silently create duplicates.

Credentials and CRM/API access are required to implement the live transaction sync. They should be supplied through the chosen service’s secure administrator interface, not placed in the website folder or sent in a document.
