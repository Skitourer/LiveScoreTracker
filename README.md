# LiveScoreTracker

*[Version française](README.fr.md)*

LiveScoreTracker runs a one-day, multi-sport competition for children: registrations, check-in, result entry at each workshop from the referees' phones (even without network), automatic scoring and rankings by category, checks, progress screens and the award ceremony.

It is a mobile-first web application in PHP (Slim 4) with MySQL, designed for cheap shared hosting and volunteers with ordinary phones.

> Status: under active development. The first production use is planned for spring 2027. Everything in this repository uses **fictitious data** only.

## Features

- **Registrations**: admin entry, spreadsheet import and export, waiting list, bibs, confirmation emails (public online form coming).
- **Check-in**: printable reception list (PDF), arrival and departure on a phone.
- **Result entry** on the referees' phones: attempts, fouls, modifiers, built-in stopwatch for 1–2 runners, counter mode, corrections with full history.
- **Offline first**: the phone builds its own entry screens, follow-up and help from a workshop pack, queues the entries and sends them when the network is back (idempotent sync).
- **Scoring and rankings**: points per event from configurable rules, totals and tie-breaking (first places, top-3 places, youngest, then by hand).
- **Checks**: results "to check", values far from the median, wrong distance probable, ties to break, entries received after the closing.
- **Documents**: rescue sheets for paper entry (per event, sorted by bib), event sheets, podiums and full rankings (PDF), rankings and detailed results (spreadsheets).
- **Displays**: public home page, participant space (bib + birth date), progress screen for a projector, award ceremony screen driven from a phone.
- **Security and privacy**: roles and permissions, two-factor authentication for admins, throttled logins, CSRF protection, strict content security policy, audit log, only first name + initial shown where possible.
- Interface in **French and English**; everything specific to an edition (workshops, scoring, schools, texts) lives in configuration files.

## Quick start

Requirements: PHP 8.2 or later with `pdo_sqlite` (or `pdo_mysql`), `mbstring` and `intl`; Composer. Node.js is only needed for the front-end linters.

```bash
git clone https://github.com/Skitourer/LiveScoreTracker.git
cd LiveScoreTracker
composer install
cp .env.example .env          # SQLite in data/ by default; set DEPLOY_TOKEN to any long random string
php bin/console.php migrate
php -S 127.0.0.1:8000 -t public public/index.php
```

Open http://127.0.0.1:8000/setup to create the first admin account (the deployment token from `.env` is asked once). Then, in the admin's competition phase page, the **Test data** page fills the database with 100 fictitious children and, optionally, realistic results.

The example competition lives in [`config.example/`](config.example/): `competition.toml` (edition, levels, workshops, scoring), translations, help pages and public pages. An installation overrides them with its own files in the data folder.

## Quality checks

```bash
composer qa      # code style (PHP-CS-Fixer), static analysis (PHPStan level 9), tests (PHPUnit)
npm ci && npm run lint   # ESLint, Stylelint, Prettier for public/assets
```

Continuous integration runs the same checks, plus the privacy checks, on every push and pull request.

## Documentation

- [Specification](SPECIFICATION.md) (English mirror of the French [cahier des charges](CAHIER_DES_CHARGES.md)): what the application does and why.
- [Developer documentation](docs/development/README.md): architecture, code organization, business core, offline entry.
- [Contributing](CONTRIBUTING.md), [Security policy](SECURITY.md), [Code of conduct](CODE_OF_CONDUCT.md), [Changelog](CHANGELOG.md).

Full user and installation guides (French and English) will follow once the application is stable.

## License

[MIT](LICENSE)
