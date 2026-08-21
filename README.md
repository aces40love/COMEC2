# COMEC website

This directory contains COMEC's public website plus a PHP/MySQL event-registration service. Public content remains dependency-free HTML, CSS, and JavaScript. Golf and gala checkout require PHP 8.1+, MySQL/MariaDB, Square, and queued email processing as described in `REGISTRATION-SYSTEM.md`.

## Preview locally

On Windows, double-click `Preview COMEC Website.cmd`. It starts a private local web server, opens the site in your browser, and allows the YouTube video to play inside the page. Leave the preview window open while reviewing the site; press Enter in that window to stop it.

Or, from the `PUBLISHED SITE` directory, run:

```powershell
python -m http.server 8765
```

Then open `http://127.0.0.1:8765/`. This previews page design only. The event forms let reviewers choose packages, attendance counts, and names, but clearly keep payment disabled. Working checkout requires the configured PHP/MySQL/Square environment on the server.

Do not review the site by double-clicking `index.html`. YouTube blocks embedded playback on `file://` pages, so that method intentionally opens the video on YouTube instead.

## Site map

- `index.html` — mission, video, current events, current leadership, donation path
- `get-help.html` — crisis-first, scenario-based reporting and support guide
- `programs.html` — documented COMEC services grouped as Respond, Prevent, Support
- `events.html` — September 12 golf tournament, December 19 gala, and a past-events photo gallery
- `golf-register.html` — golf purchaser, sponsorship, add-on, and player intake
- `gala-register.html` — gala purchaser and guest intake
- `registration-status.html` — private payment/registration confirmation view
- `volunteer.html` — community engagement, event, and business-development volunteer opportunities
- `resources.html` — official hotlines, reporting portals, and prevention resources
- `about.html` — mission, history, Greg Bethel, Philip Boals, legal identity
- `donate.html` — verified PayPal route, check instructions, sponsorship, giving trust
- `RESEARCH.md` — comparable-organization deep dive and design decisions
- `CONTENT-SOURCES.md` — fact provenance and update controls
- `CRM-AND-90-DAY-FUNDRAISING-PLAN.md` — verified-data workflow and rapid fundraising plan
- `REGISTRATION-SYSTEM.md` — Square, database, email, dashboard, tax-disclosure, testing, and deployment setup

## Content and assets

All public-facing images and PDFs are in `assets/`. Greg Bethel's photo and other large imagery are optimized as WebP; Philip Boals's portrait uses the exact full-resolution JPEG supplied for maximum fidelity. Original files remain untouched elsewhere in the COMEC project.

The old WordPress backup was used only to recover COMEC-owned assets, historical mission language, contact details, and the official YouTube ID. It was not treated as authoritative for current leadership or current events.

## Launch checklist

1. Have Greg Bethel and Philip Boals approve their biographies and photos.
2. Confirm gala VIP and sponsorship inclusions/guest counts, and have COMEC approve all published event descriptions.
3. Complete every registration-system prerequisite and sandbox test in `REGISTRATION-SYSTEM.md`.
4. Confirm that `comec@comec.org` is monitored before adding it to the site; the current build routes contact through the verified office phone.
5. Reconcile event transactions through the private dashboard/CSV, and connect them to the CRM before claiming automated CRM records.
6. Configure the production domain, HTTPS, analytics/consent settings, and redirects from important WordPress URLs.
7. Run the link and accessibility checks again after any production-platform conversion.
8. Assign a staff owner for volunteer calls and confirm the current orientation, screening, and placement process.

## Deployment and credentials

No credentials are needed for a static design preview. Working registration requires private database, Square, mail, and dashboard credentials plus cPanel/cron access. Store them in a configuration file outside `public_html`; never put them in this folder, GitHub, browser JavaScript, or documentation. Updating Facebook, PayPal, or DNS requires the corresponding administrator access.

## Maintenance rule

Treat `CONTENT-SOURCES.md` as the publishing gate. Any leadership, event, hotline, price, address, or donation change should be updated there and on every affected page in the same edit.
