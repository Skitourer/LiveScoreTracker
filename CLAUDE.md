# Instructions for AI coding assistants

Stable rules for working on this repository. Current status and next steps live outside the repo (project notes).

## Communication and workflow

- Reply to the maintainer (Boris) **in French, concisely**. Code, comments, commits and PRs in English.
- Propose the next plan step (specification § 23) only when asked to continue; present it and wait for approval before coding. Fixes requested during testing: do them directly.
- Save tokens: read files partially (grep, offset/limit), don't re-read the specification unless needed, batch feedback, commit and push often.
- One branch and one PR per step. The maintainer tests on the test site, then merges. Before pushing a fix, check whether the PR is already merged; if so, new `fix/...` branch from `main` and a new PR.
- PR conflicts (often `CHANGELOG.md`): merge `origin/main` into the branch, keep both lines, push (no force push).
- At session start, list open issues: `gh api repos/Skitourer/LiveScoreTracker/issues?state=open`.
- Commit author: `Skitourer <149181038+Skitourer@users.noreply.github.com>`.
- No real personal data in the repository: fictitious data only.

## Definition of done

- `composer qa` passes (PHPUnit, PHPStan level 9, code style) and `npm` linters pass.
- Docs updated in the same PR: `CAHIER_DES_CHARGES.md` (FR) and `SPECIFICATION.md` (EN) together, `docs/development/`, `README.md` + `README.fr.md` when relevant, one line in `CHANGELOG.md` under "Unreleased".
- Every on-screen app text is in `fr.toml` and `en.toml` (error messages included, e.g. `config.error.*`). The 10-language translations (home, emails, registration form) are separate.

## Vocabulary

- Catégorie = girl/boy (`gender` in code). Série = class + gender, e.g. "CM1 filles": unit of scores and rankings; EN "division", `category` in code (not renamed). Groupe de classes (junior, senior…) sets event variants.
- Names: full names for admins/organisers (ceremony, documents); first name + initial for referees and for other children in the participant area.
- `config.example/competition.toml` is in French and fictitious (Olympiades 2027, écoles des Tilleuls / des Marronniers, contacts Camille Fournier / Paul Garnier).

## Code conventions

- The CSP forbids inline styles: use classes. PDF templates (`templates/pdf/`) may use them.
- Base template blocks to override: `topbar_nav`, `main_class`, `flashes`, `robots`. Beware of shared CSS class names.
- Stylelint enforces the tokens of `tokens.css` (mono font: `--font-family-mono`).
- Manually typed surnames are stored in uppercase.

## Sandbox environment

- `composer install`: use `--prefer-source` (no GH_TOKEN/GITHUB_TOKEN); provide phpstan through the composer cache: clone the tag, zip it to `~/.cache/composer/files/phpstan/phpstan/<sha1 of the zipball URL in composer.lock>.zip` (2.3.5: `066248ce7498d4ce23bfbe683ffa5427b936d610.zip`).
- `export COMPOSER_ALLOW_SUPERUSER=1` before `composer qa` / `composer cs-fix`. `npm ci` works.
- PDF checks: `pdftotext -layout`, `pdftoppm -png`. Sample data through the test WebClient: POST `/admin/test-data` with `confirmation=EFFACER`, `with_results=1`. Rankings: `/results?category=CE1-girl`.
- Screenshots: render with the WebClient, write the page temporarily into `public/`, open it with Playwright via `file://`, then delete it.
- Tests: `WebClient->clock` (ShiftedClock) moves time; `WebClient::post` accepts nested fields; `WebClient->mailTransport` (`'smtp'` default, `'log'`). Don't use `@example.org` when a test expects a send through `SmtpMailer` (fictitious domains are skipped with `MailSkipped`).
- The test site (deployed on push to `feat/**`, `fix/**`, `test`) is unreachable from the sandbox: rely on the deploy job.
- PRs: `gh api repos/Skitourer/LiveScoreTracker/pulls --input file.json`; CI: `gh api repos/Skitourer/LiveScoreTracker/commits/<sha>/check-runs`. API 503 "Session policy temporarily unavailable": retry. Push refused by the proxy (403): add the repo with push access, then push again.
