# Offline entry

Workshops are often outdoors with poor mobile coverage. The referees' entry screens therefore run **in the phone**, from data downloaded beforehand, and send the entries when they can (spec § 8.8).

## Workshop pack

`GET /entry/{workshop}/pack` (`EntryPackAction`, built by `Application/EntryPack`) returns, as JSON, everything a phone needs for one workshop:

- the workshop and its variants (measure, attempts, foul mode, modifiers, plausible range, counter actions, stopwatch settings);
- the children of the matching level groups: bib, **first name + initial** only, level, presence, and their current attempts (with a "to check" flag);
- the translated texts used by the screens, the help page and contacts (rendered on the server);
- the phase and the CSRF token.

The phone keeps it in `localStorage` (`lst.pack.<workshop>`) and refreshes it every 2 minutes when online.

## Screens built by the phone

`public/assets/js/entry/` holds a small single-page application, started on the workshop entry page:

| Module | Role |
|---|---|
| `app.js` | routing (`#bib/12`, `#race/12/15/timer`, `#tracking/todo`, `#help`), screens, entry forms, stopwatch and counter |
| `views.js` | HTML of the screens (escaped with `h()`) |
| `format.js` | parsing and formatting of values, attempts used, best result |
| `store.js` | packs and queue in `localStorage` |
| `sync.js` | pack refresh, sending the queue, status banner |

Every entry gets a random id and is added to the local **queue** (`lst.queue`) before anything else, so nothing is lost if the network or the battery fails. The screens combine the pack's attempts with the queued ones, so the referee sees the child's real progress offline.

## Sync protocol

`POST /entry/{workshop}/sync` (`EntrySyncAction`, `Application/EntrySync`) receives a batch of entries every 20 seconds, when the network comes back, or on "Send now". For each entry the server answers a status:

- `saved` — recorded, or already recorded with the same id (sending twice is harmless: the id makes it idempotent);
- `rejected` — refused by the blocking checks (unknown bib, other level group, unreadable value), kept on the phone and shown;
- `late` — the competition is closed: kept aside on the server for an admin or organizer to accept or refuse;
- `closed` — the competition is in preparation: kept on the phone.

The server redoes every check and adds its own warnings (for instance, another phone already entered the same attempt). When the entry was made more than a minute before it arrived, the phone's time is kept in the attempt's details.

On logout, the children's data is erased from the phone; the queue is kept so that no entry is lost.

## Service worker

`public/sw.js` caches the assets and the workshop entry pages:

- assets: cache first, refreshed when the revision changes;
- workshop entry pages: network first, the last copy when offline;
- a workshop's follow-up or help requested offline: redirected to the entry page, which shows the phone's own version;
- any other page offline: the cached **offline page** (`/offline`), never a browser error.

`public/assets/js/offline.js` complements it in the page: links that need the network are greyed and answer "No network" instead of leaving the page, and server forms (logout included) are held back.

## Trying it

Playwright can simulate a phone going offline (`context.set_offline(True)`), service worker included. The integration tests check the server side (pack contents, sync statuses, idempotency); the browser behavior is checked by hand or with Playwright against a local server.
