# Security policy

LiveScoreTracker handles personal data about children (names, birth dates, guardians' contacts). Security reports are taken seriously.

## Supported versions

The project has no released version yet: only the `main` branch is supported.

## Reporting a vulnerability

**Please do not open a public issue.** Report it privately through GitHub: on the repository page, open **Security → Advisories → Report a vulnerability** ([private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)).

Please include:

- what the vulnerability is and what an attacker could do with it;
- the steps to reproduce it, on a local installation or with fictitious data;
- the version or commit concerned.

Never include real personal data in a report, and do not test against an installation you do not own.

You should get an answer within a week. Once fixed, the vulnerability is described in the [changelog](CHANGELOG.md), with credit if you wish.

## Security measures in place

For reviewers, the main protections are described in the specification (§ 15 personal data, § 19 security): roles and permissions checked on every route, two-factor authentication for admins, throttled logins, CSRF tokens, strict content security policy and security headers, hashed passwords (Argon2id when available), audit log, no real data in the repository, secrets only in the server's `.env` and encrypted GitHub secrets.
