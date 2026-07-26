# CODEX COMEC website

This directory contains a complete, static replacement website for COMEC. It is intentionally dependency-free: no WordPress, package manager, database, or build command is required.

## Preview locally

On Windows, double-click `Preview COMEC Website.cmd`. It starts a private local web server, opens the site in your browser, and allows the YouTube video to play inside the page. Leave the preview window open while reviewing the site; press Enter in that window to stop it.

Or, from the `CODEX COMEC` directory, run:

```powershell
python -m http.server 8765
```

Then open `http://127.0.0.1:8765/`.

Do not review the site by double-clicking `index.html`. YouTube blocks embedded playback on `file://` pages, so that method intentionally opens the video on YouTube instead.

## Site map

- `index.html` — mission, video, current events, current leadership, donation path
- `get-help.html` — crisis-first, scenario-based reporting and support guide
- `programs.html` — documented COMEC services grouped as Respond, Prevent, Support
- `events.html` — September 12 golf tournament, December 19 gala, and a past-events photo gallery
- `resources.html` — official hotlines, reporting portals, and prevention resources
- `about.html` — mission, history, Greg Bethel, Philip Boals, legal identity
- `donate.html` — verified PayPal route, check instructions, sponsorship, giving trust
- `RESEARCH.md` — comparable-organization deep dive and design decisions
- `CONTENT-SOURCES.md` — fact provenance and update controls
- `CRM-AND-90-DAY-FUNDRAISING-PLAN.md` — verified-data workflow and rapid fundraising plan

## Content and assets

All public-facing images and PDFs are in `assets/`. Greg Bethel's photo and other large imagery are optimized as WebP; Philip Boals's portrait uses the exact full-resolution JPEG supplied for maximum fidelity. Original files remain untouched elsewhere in the COMEC project.

The old WordPress backup was used only to recover COMEC-owned assets, historical mission language, contact details, and the official YouTube ID. It was not treated as authoritative for current leadership or current events.

## Launch checklist

1. Have Greg Bethel and Philip Boals approve their biographies and photos.
2. Reconfirm the gala time and publish ticket/sponsor details when finalized.
3. Confirm the golf registration workflow and the public point of contact.
4. Confirm that `comec@comec.org` is monitored before adding it to the site; the current build routes contact through the verified office phone.
5. Connect donation and event transactions to the CRM before claiming automated donor records.
6. Configure the production domain, HTTPS, analytics/consent settings, and redirects from important WordPress URLs.
7. Run the link and accessibility checks again after any production-platform conversion.

## Deployment and credentials

No credentials are needed to review or host this folder on a static preview service. Production deployment will require access to COMEC's domain/DNS and hosting account. Updating Facebook, PayPal configuration, or the existing WordPress installation requires the corresponding administrator access; those credentials should be entered only in the relevant service, never stored in this folder.

## Maintenance rule

Treat `CONTENT-SOURCES.md` as the publishing gate. Any leadership, event, hotline, price, address, or donation change should be updated there and on every affected page in the same edit.
