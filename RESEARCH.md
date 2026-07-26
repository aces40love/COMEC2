# COMEC comparable-organization website research

Research completed July 25, 2026. The purpose was not to imitate one nonprofit. It was to identify repeatable patterns that help a family act quickly, help a donor trust the organization, and help a small staff keep the site current.

## Executive conclusion

The best model for COMEC is a hybrid:

- the task clarity of MissingKids.ca;
- the crisis hierarchy of NCMEC;
- the three-pillar storytelling of Texas Center for the Missing;
- the local credibility and event detail of Memphis Child Advocacy Center;
- the giving clarity of Childhelp and Polly Klaas Foundation;
- the youth-centered channel design of National Runaway Safeline; and
- the concise impact-and-audience framing of Thorn.

COMEC should not copy the large-site complexity of national organizations. Its advantage is a short path from a family’s situation to the right resource, combined with unusually clear local identity and current event information.

## Benchmark matrix

| Organization | Strongest pattern | What COMEC adopts | What COMEC avoids |
|---|---|---|---|
| [National Center for Missing & Exploited Children](https://www.missingkids.org/gethelpnow) | Immediate “Is your child missing?” task and authoritative emergency sequence | Persistent emergency guidance; law enforcement first, then 1-800-THE-LOST | A donation popup that can obscure crisis content |
| [MissingKids.ca](https://missingkids.ca/en/) | Exceptionally clear mobile choices: missing child vs. tip/sighting | Scenario-based Get Help page and large tap targets | Unnecessary depth for a local organization |
| [Texas Center for the Missing](https://centerforthemissing.org/) | Clear GET HELP/GIVE split and Impact/Prepare/Respond pillars | Separate crisis and donor paths; Respond/Prevent/Support program structure | Oversized campaign banners that clip on narrow screens |
| [Memphis Child Advocacy Center](https://www.memphiscac.org/) | Strong local credibility, report-abuse action, event and sponsor detail | Memphis-specific voice; current event pages; leadership visibility | Allowing a donation banner to dominate every mobile entry point |
| [Childhelp](https://www.childhelp.org/) | Emotional storytelling, program cards, giving modes, visible impact proof | Human language and focused program cards | Autoplay/carousel hero behavior and mobile clipping |
| [Polly Klaas Foundation](https://www.pollyklaas.org/) | Persistent 24/7 contact, safety kit, annual metrics, testimonials | Highly visible hotline and transparent nonprofit identity | Dense navigation and stale affiliate fundraising references |
| [National Runaway Safeline](https://www.1800runaway.org/) | 24/7 multi-channel, confidential, nonjudgmental support | Plain-language runaway support and direct call/text route | Implying COMEC operates channels it does not staff |
| [Thorn](https://www.thorn.org/) | One crisp promise, audience segmentation, current resources, legal identity | Strong top-line promise and resource cards | Technology-company language outside COMEC’s scope |
| [Cleveland Family Center for Missing Children and Adults](https://www.clevelandmissing.org/) | A realistic small-organization model with resources, events, volunteer and PayPal paths | Simple static architecture and clear free-service message | Burying emergency instructions and phone numbers |

## Findings that changed the build

### 1. Crisis and fundraising must be visually separate

A person searching for a missing child is in a different state of mind from a donor. COMEC’s emergency guidance uses a red, persistent path; donation uses gold and blue. The donor message never interrupts the crisis flow.

### 2. Situation labels outperform organization labels

Families do not arrive thinking in program-department names. “A child is missing,” “I suspect abuse,” and “There is an online safety concern” are faster than asking the visitor to understand an internal service taxonomy.

### 3. A small nonprofit needs fewer pages, not a smaller font

The old site accumulated many narrow topic pages and dated posts. The replacement has seven durable pages, a shallow navigation, and visible next actions. There is no carousel, popup, mega-menu, or fake contact form.

### 4. Trust requires current specifics

The new build shows the legal name, address, phone, current leaders, current event dates, and verified PayPal route. It does not publish an efficiency percentage or impact total without a current supporting document.

### 5. Events need transactional detail

The golf page includes the date, start time, venue, address, actual 2026 price levels, downloadable flyer, and office phone. The gala is clearly labeled “save the date,” with unpublished ticket details explicitly described as forthcoming.

### 6. Video should tell the story without controlling the page

COMEC’s confirmed YouTube video is embedded with YouTube’s privacy-enhanced domain, lazy loading, no autoplay, a descriptive title, and a direct-watch fallback.

### 7. Mobile failures are common even on prominent nonprofit sites

Live 390-pixel reviews found clipped or oversized hero content on several benchmark sites. The COMEC design uses fluid type, single-column fallbacks, 44-pixel-plus controls, no horizontally dependent carousels, and tables that remain readable on narrow screens.

## Information architecture

```text
Home
├── Get Help
│   ├── Missing child
│   ├── Abuse or neglect
│   ├── Online exploitation
│   ├── Runaway youth
│   ├── Trafficking
│   └── Youth crisis
├── What We Do
│   ├── Respond
│   ├── Prevent
│   └── Support
├── Events
│   ├── 2026 Golf Tournament
│   └── 2026 Christmas Gala
├── Resources
├── About
└── Donate
```

## Accessibility and technical standard

The build follows the intent of [WCAG 2.2](https://www.w3.org/TR/WCAG22/): semantic landmarks, a skip link, visible keyboard focus, high-contrast text, responsive reflow, large controls, reduced-motion support, descriptive alternatives, no autoplay, and no color-only instruction.

The site uses native HTML, CSS, and a small navigation script. It has no third-party font or JavaScript dependency. Organization, event, and video structured data follow [Google’s event guidance](https://developers.google.com/search/docs/appearance/structured-data/event) and Schema.org types.

## Content-governance rule

The archived WordPress site is an asset library and historical reference, not the source of truth for people, dates, prices, hotlines, or current program availability. The current COMEC documents, leadership confirmation, and first-party government/nonprofit sources outrank archived website copy.

See `CONTENT-SOURCES.md` for the fact-level publishing record.
