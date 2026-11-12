# Contributing to LiveScoreTracker

Thank you for your interest! This project is run by volunteers for a children's sports day, so contributions that keep it simple, reliable on cheap hosting and usable on ordinary phones are the most welcome.

## Before you start

- Read the [specification](SPECIFICATION.md): it describes what the application does and why. A change in behavior should come with an update of the specification, in **both** `SPECIFICATION.md` (English) and `CAHIER_DES_CHARGES.md` (French), in the same commit.
- For anything larger than a small fix, open an issue first to discuss the idea.
- The [developer documentation](docs/development/README.md) explains the architecture and where things live.

## Privacy: fictitious data only

The repository is public. It must **never** contain real personal data or anything identifying the organizer: names, birth dates, emails, phone numbers, association or school names, venue, production domain, real results, registration files or backups (spec § 25).

- Use the fictitious examples (`config.example/`, the test data generator, the test fixtures).
- Enable the git hooks once: `scripts/install-hooks.sh`. They block spreadsheets, databases, secrets and forbidden terms before a commit. Put your own forbidden terms (one per line) in `~/.config/livescoretracker/forbidden-terms.txt`; they are never stored in the repository.
- Screenshots in issues and pull requests must show fictitious data only.

## Development setup

See the quick start in the [README](README.md). In short:

```bash
composer install
cp .env.example .env            # SQLite by default
php bin/console.php migrate
php -S 127.0.0.1:8000 -t public public/index.php
npm ci                          # only for the front-end linters
```

Useful commands:

| Command | What it does |
|---|---|
| `composer qa` | code style check, PHPStan, PHPUnit |
| `composer cs-fix` | fix the PHP code style |
| `composer test` | run the tests only |
| `npm run lint` / `npm run format` | check / format JavaScript and CSS |
| `php bin/console.php migrate` / `status` | apply / list database migrations |
| `php bin/console.php config:check [file]` | validate a `competition.toml` |

## Conventions

- **English** for all code, identifiers, comments, commit messages and technical documentation. User-facing texts go through the translation files (`config.example/i18n/fr.toml` and `en.toml`, kept in sync).
- **PHP**: PER Coding Style 2.0 (PHP-CS-Fixer), `declare(strict_types=1)`, full typing, PHPStan level 9 without baseline. Every class has a docblock saying what it is for, with a reference to the specification section when relevant.
- **Layers**: business rules in `src/Domain` (pure, no I/O), use cases in `src/Application`, database, files and mail in `src/Infrastructure`, one invokable class per route in `src/Action`. No business logic in Twig templates.
- **JavaScript**: ES2022 modules without build step, ESLint + Prettier. **CSS**: only the design tokens of `public/assets/css/tokens.css` (Stylelint enforces it). The content security policy forbids inline scripts and styles.
- **Database**: one new SQL file per change in `migrations/`, `snake_case` names, compatible with both SQLite and MySQL.
- **Tests**: PHPUnit. Unit tests for the domain (`tests/Unit`), integration tests that run the whole application on a temporary SQLite database (`tests/Integration`, see `WebClient`). The business core (scoring, tie-breaking, attempts, sync) must stay fully covered.
- **Commits**: [Conventional Commits](https://www.conventionalcommits.org/) in English (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`…).

## Pull requests

1. Create a branch from `main`: `feat/…` for a feature, `fix/…` for a fix, `docs/…` for documentation.
2. Keep the pull request focused on one subject, with tests and, when behavior changes, the specification update.
3. Run `composer qa` and `npm run lint` before pushing.
4. Fill in the pull request template: what changes, how to try it, what was checked.
5. Continuous integration must be green before merging. Branches `feat/**` and `fix/**` of the main repository are also deployed to the test site, on fictitious data, so that the maintainer can try them.

## Reporting bugs and security issues

- Bugs and ideas: use the issue templates.
- Security vulnerabilities: **do not open a public issue**; follow the [security policy](SECURITY.md).

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE) and that you follow the [code of conduct](CODE_OF_CONDUCT.md).
