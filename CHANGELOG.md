# Changelog

All notable changes to this project are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project will use [semantic versioning](https://semver.org/) from its first release.

Each step of the development plan (specification § 23) is one or more pull requests, tried on the test site before being merged.

## [Unreleased]

### Added

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

- Podium rows of the rankings and of the calculation report were laid out as the ceremony podium (shared CSS class).
- Exhausted attempts are blocked; every "Arrival" tap gives feedback and the last arrival can be undone (#12).
- The test deployment now includes the example help and public pages (#14).
