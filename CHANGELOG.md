# Changelog

All notable changes to this project are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project will use [semantic versioning](https://semver.org/) from its first release.

Each step of the development plan (specification § 23) is one or more pull requests, tried on the test site before being merged.

## [Unreleased]

### Added

- **Step 12a — configuration screen** (`/admin/config`, admins): one tab per part of `competition.toml` (edition, participants, registration, scoring, workshops, Excel import, contacts, file); workshops can be added, edited, duplicated, moved and deleted (not when they have results); validation with errors next to the fields; last 20 versions kept with "go back to this version"; export, import with a preview of the changes, direct edit of the file; every change in the audit log; read-only parts depending on the phase; optional display names (`name`, `label`) in the file. Validation errors are translated (`config.error.*`); the fictitious example now uses French values (edition, schools, Excel column headers, contacts, comments); "Genre" is now called "Catégorie" in the French interface, and a level + gender combination is now called a "série" (French) / "division" (English).
- **Step 11 — online registration**: public form (`/inscription`) in French, English, Italian, German, Dutch, Spanish, Portuguese, Ukrainian, Simplified Chinese and Arabic (right to left), language menu without flags; protection against robots without third-party service (trap field, 5 s minimum, 10 registrations per hour per IP); one verification email per child (link valid 48 h, then expired); registered with a bib or put on the waiting list; withdrawal link in the confirmation and waiting-list emails (preparation phase only); family emails in the chosen language followed by French; notifications to the organization (`NOTIFY_EMAIL`); admin can force the online registration open or closed; rules translated and shown inside the registration form; "legal guardian" wording on every registration form; the "I have checked" box of a warning is no longer required (a corrected grade saves directly); grade names (CP…CM2) never translated; "Refresh" button on the registrations list; registration forms no longer overflow on narrow phones.
- **Step 10a — documents**: Documents page; rescue sheets per event and level group, sorted by bib, with a "present" box, a referee line, an "as of" date and empty lines, and a warning when registrations changed since the last printing; event sheets; podiums and full rankings in PDF (marked "provisional" before the closing); rankings and detailed results in spreadsheets; every download in the audit log; modifier values shown in the unit of the event.
- Repository documentation: README in English and French, contributing guide, security policy, code of conduct, this changelog, issue and pull request templates, developer documentation (`docs/development/`).
- **Step 9c — award ceremony** (#16): big screen for the projector (stepped podium revealed from the 3rd place to the 1st, full names, follows the state every 2 s, full screen) driven from a phone (reveal, hide, previous/next category, order of the categories); only once the competition is closed.
- **Step 9b — participant space and progress** (#15): participant space with bib + birth date (raw results while running; points, ranks and category ranking after the closing); public progress screen for a projector (workshops done/expected, least advanced highlighted, refreshed every 10 s); detailed progress by category for organizers.
- **Step 9a — public home page** (#14): edition, presentation, events, registration state with the places left, rules page; only the home page is indexed, in production only; technical status moved to `/status`.
- **Step 8b — checks and closing** (#13): control board (results to check, far from the median, wrong distance probable, phone conflicts, "checked" button), ties broken by hand, closing with a recap page and reopening, entries received after the closing kept aside for a decision; automatic refresh of the follow-up pages; referees read-only after the closing; follow-up and help available offline; no link leads to an error without network.
- **Step 8a — results and rankings** (#11): rankings by category recomputed on each display, missing events by workshop and by child, clearing a child's results on an event, fictitious results in the test data.
- **Step 7a — offline entry** (#10): the phone builds its entry screens from a workshop pack, keeps a local queue, sends entries idempotently, shows a status banner; installable web application (manifest, service worker).
- **Step 6 — stopwatch and counter** (#9): built-in stopwatch for 1–2 runners, counter mode, follow-up screen of a workshop, test data page.
- **Step 5 — result entry** (#8): workshop choice, attempts, strict and lenient fouls, modifiers, "to check" warnings, versioned corrections, remaining bibs, Markdown help pages.
- **Step 4 — reception** (#7): printable reception list (PDF), check-in and departure on a phone.
- **Step 3 — registrations** (#6): design tokens and style guide, admin registrations, spreadsheet import and export, bibs, confirmation emails, trusted devices, referee phones remembered and blockable.
- **Step 2 — accounts and security** (#5): accounts and roles, two-factor authentication for admins, shared referee account, audit log.
- **Step 1 — configuration and scoring** (#4): TOML configuration, translations, scoring and tie-breaking engine.
- **Step 0 — foundation** (#3): Slim application, quality tools, privacy safeguards, automatic deployment to the test site.
- Specification in French and its English mirror (#1, #2).

### Fixed

- Registration forms (child card, new registration, public form) no longer rely on the browser's own checks, which could block saving without any message: the server explains every missing or invalid field, and the page scrolls to the messages (#23).
- Confirmation emails: when emails are only written to the mail log (`MAIL_TRANSPORT=log`), the screen no longer says "sent" but warns that sending is not enabled; `/status` shows whether emails are sent by SMTP; the reason of a failed sending is kept in the audit log (#24).
- Podium rows of the rankings and of the calculation report were laid out as the ceremony podium (shared CSS class).
- License year set to 2026, the year the project was published.
- Exhausted attempts are blocked; every "Arrival" tap gives feedback and the last arrival can be undone (#12).
- The test deployment now includes the example help and public pages (#14).
