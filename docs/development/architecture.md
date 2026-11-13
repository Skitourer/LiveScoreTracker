# Architecture

## Stack

| Layer | Choice |
|---|---|
| Server | PHP 8.2+, [Slim 4](https://www.slimframework.com/) with [PHP-DI](https://php-di.org/) |
| Templates | Twig (automatic escaping) |
| Database | MySQL in production, SQLite for development and tests, plain PDO with prepared statements |
| Configuration | TOML files (edition, levels, workshops, scoring), translations in TOML, help and public pages in Markdown |
| Libraries | PhpSpreadsheet (spreadsheets), Dompdf (PDF), PHPMailer (email), OTPHP (two-factor), CommonMark (Markdown), BaconQrCode |
| Browser | Server-rendered HTML, small vanilla ES2022 modules (no build step), a service worker for the installable, offline-capable entry screens |

The target is cheap shared hosting (no shell, no long-running process, SFTP deployment) and about 15 phones at the same time.

## Code layout

```
public/            web root: index.php, assets (css, js), service worker (sw.js), manifest
src/
  Kernel.php       builds the container, the middleware stack and the routes
  Action/          one invokable class per route (HTTP in, HTTP out), grouped by screen
  Application/     use cases that combine the domain and the repositories (Standings, EntrySync, ControlBoard…)
  Domain/          business rules, pure PHP without I/O: Config, Registration, Results, Scoring, Security
  Infrastructure/  database (Persistence, Database), configuration loading, i18n, mail, PDF, clock, settings
  Http/            request context, middleware (session, authentication, CSRF, security headers), error handling
  Presentation/    Twig extension (translation, formatting, permission helpers)
  Demo/            fictitious data: test data generator, scoring report, import sample
templates/         Twig templates, one folder per area (entry, results, participant, ceremony…)
migrations/        numbered SQL files
config.example/    fictitious example: competition.toml, i18n/, help/, pages/
tests/             Unit/ (domain) and Integration/ (whole application on SQLite)
bin/console.php    command line: migrate, status, config:check
scripts/           privacy check, git hooks installer, style guide screenshots
```

Dependencies only point inwards: `Action` → `Application` → `Domain`, with `Infrastructure` implementing storage. The `Domain` folder knows nothing about HTTP, the database or files, which keeps the business core easy to test.

## Request lifecycle

`public/index.php` calls `Kernel::createApp()`. Each request goes through the middleware stack, outermost first:

1. **SecurityHeadersMiddleware** — strict Content-Security-Policy (no inline script or style), other security headers.
2. **SessionMiddleware** — database-backed session cookie, trusted-device cookie for admins.
3. **Twig** — template rendering.
4. **AuthenticationMiddleware** — resolves the logged-in user, checks that the account is enabled, that the role is active in the current phase and that a referee phone is not blocked; fills the `RequestContext`.
5. **CsrfMiddleware** — every POST carries the session's token (hidden field or `X-CSRF-Token` header).
6. **Route guard** (`Authorize`) — each route declares the permission it needs (`Permission` enum, granted per role by `AccessPolicy`).

Then the route's **Action** reads the request, calls the application services and repositories, and answers with a Twig page (through `Responder`), a redirect after POST, JSON (`JsonResponder`) or a file. Actions write to the audit log and set flash messages through `Responder`.

## Configuration

- **Environment** (`.env`, read by `Settings`): environment, URL, time zone, data folder, database, deployment token, SMTP. Secrets live only there and in encrypted GitHub secrets.
- **Competition** (`competition.toml`): edition, levels and level groups, genders, schools, registration window and quota, scoring rules, workshops and their variants (one per level group), import columns, contacts. Parsed and validated by `Domain/Config/ConfigParser` into immutable value objects (`CompetitionConfig`, `Workshop`, `Variant`…). `php bin/console.php config:check` reports every error with its path.
- **Configuration screen** (`/admin/config`, `Action/Admin/ConfigAction`): `Application/ConfigForms` turns each section of the decoded file into form values and back (validation is left to `ConfigParser`, so the screen and the file follow the same rules); `Infrastructure/Config/ConfigStore` validates, writes the file atomically into the data folder and keeps the last 20 versions in `config-history/`; `Domain/Config/ConfigDiff` lists the changes setting by setting (import preview, audit log). Optional `name`/`label` keys of the file become translation overrides (`CompetitionConfig::$labels`, `Translator::override()`).
- **Texts**: `i18n/fr.toml` and `i18n/en.toml` (keys must exist in both; partial catalogs `it`, `de`, `nl`, `es`, `pt`, `uk`, `zh`, `ar` cover the public registration pages and family emails, checked by `TranslationTest`), help pages `help/<lang>/<workshop>.md`, public pages `pages/<lang>/home.md` and `rules.md`.

Each file is looked up first in the installation's **data folder** (outside the web root, never deployed), then in `config.example/`. The repository therefore only ever contains the fictitious example.

## Database

- Migrations are numbered SQL files in `migrations/`, applied by `Infrastructure/Database/Migrator` (from `bin/console.php migrate`, or `POST /internal/migrate` with the deployment token, which the deployment workflow calls). Placeholders such as `{{id}}` and `{{table_options}}` adapt the SQL to SQLite and MySQL.
- One repository class per table group in `Infrastructure/Persistence` (`RegistrationRepository`, `AttemptRepository`, `LateEntryRepository`…). They return domain objects.
- Results are **never overwritten**: a correction creates a new version of the attempt (`replaced_at`) and a deletion marks it (`deleted_at`), so the history and the audit log stay complete.
- Small application-wide values (current phase, ceremony state, last rescue-sheet printing) live in the `app_meta` table (`AppState`).

## Front end

- Pages are rendered on the server and work without JavaScript wherever possible; scripts add comfort (stopwatch, counter, confirmations, automatic refresh).
- `public/assets/js/app.js` is the single entry point; it loads small modules: `live.js` (automatic refresh of follow-up pages), `offline.js` (behavior without network), `stopwatch.js`, `counter.js`, `tracking.js`, and the offline entry application in `entry/` (see [Offline entry](offline-entry.md)).
- Styles use only the design tokens of `public/assets/css/tokens.css` (light and dark themes); Stylelint rejects any hard-coded value. The style guide page (not in production) shows every component.

## Tests

- `tests/Unit`: domain rules (scoring, tie-breaking, attempts, checks, configuration parsing…), no database.
- `tests/Integration`: the whole Slim application on a temporary SQLite database, driven by `WebClient` (cookies, CSRF, redirects) and `AdminSession` (creates the first admin and logs in). Each test tells a story through the screens: register children, enter results, close the competition, look at the rankings.
- `composer qa` runs PHP-CS-Fixer, PHPStan (level 9, no baseline) and PHPUnit; `npm run lint` checks JavaScript and CSS.

## Deployment

- `.github/workflows/ci.yml` runs on every push and pull request: code style, static analysis, tests on PHP 8.2 and 8.3, migrations on MySQL, JavaScript and CSS linters, privacy checks.
- `.github/workflows/deploy-test.yml` deploys branches `feat/**`, `fix/**` and `test` to the test site by SFTP (code only: `.env`, the data folder and Markdown documentation are excluded), then applies the migrations and checks `/health`.
- The test site runs on fictitious data, created by the admin's **Test data** page.
