# Developer documentation

This folder is for people who want to understand or change the code. What the application must do, and why, is in the [specification](../../SPECIFICATION.md); how to contribute is in [CONTRIBUTING](../../CONTRIBUTING.md).

| Page | Content |
|---|---|
| [Architecture](architecture.md) | Stack, request lifecycle, code layout, configuration, database, front end, tests, deployment |
| [Business core](business-core.md) | Phases, roles, registrations, attempts and checks, scoring and tie-breaking, rankings |
| [Offline entry](offline-entry.md) | Workshop pack, local queue, sync protocol, service worker, behavior without network |

Every PHP class starts with a docblock describing its role, usually with a reference to the specification section it implements (`spec § 9.6`). Reading the docblocks of a folder is the quickest way to find your way around it.
