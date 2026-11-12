# Business core

The rules below are implemented in `src/Domain` (pure code) and `src/Application` (use cases). The specification gives the full detail; section numbers are given in brackets.

## Phases and roles (§ 3, § 4)

The edition goes through three phases (`Phase`): **preparation** → **running** → **finished**, with a way back from running to preparation and a traced reopening from finished to running. Closing goes through a recap page listing what is still pending (missing events, ties, results to check, late entries).

Roles (`Role`): **admin** (everything, two-factor authentication), **organizer**, **referee** (one shared account, one name per phone; active while running, read only once finished). Permissions are an enum (`Permission`) granted per role by `AccessPolicy`. Families have no account: the participant space uses bib + birth date.

## Registrations and check-in (§ 5, § 6)

A `Registration` holds the child and guardian data (`ChildData`), the status (`RegistrationStatus`: pending verification, registered, waiting list, withdrawn, expired) and the bib. Bibs are handed out from `first_bib`; in preparation a withdrawn child frees the bib. Check-in marks arrival and departure (`AttendanceMark`). Only present children are expected at the workshops.

## Workshops, events and attempts (§ 7, § 8)

A **workshop** has one **variant** per level group (for example sprint 30 m for CP-CE1, 40 m for CE2-CM2). A variant defines the measure (time, distance, count, score), whether higher or lower is better, the number of attempts, the foul mode (strict: a foul uses an attempt; lenient: it does not), modifiers (penalties or bonuses), a plausible range and, for counters, the actions and limits.

Each entry is a `StoredAttempt` with the measured value, modifiers, warnings and author. `AttemptProgress` tells how many attempts a child has used. `EntryChecks` adds warnings when entering (child not checked in, attempt too many, outside the plausible range, probable duplicate…): the referee confirms them, and the result is then "to check".

## Checks (§ 10)

The control board (`ControlBoard`) lists:

- current results with warnings, not yet marked as checked (a correction starts unchecked again);
- results compared with the others (`GlobalChecks`, at least 5 other results, times and distances only): **far from the median** of the category (more than twice or less than half), **wrong distance probable** (a time typical of the other distance of the workshop, using the distances written in the variants' labels);
- unbroken ties (once the tied children have finished, or after the closing);
- entries received after the closing (`late_entries`), to accept or refuse.

## Scoring (§ 9)

For each event and category, `EventScorer` keeps each child's best valid result and turns it into points with the scoring rules (`ScoringRules`): the points are linear between the worst valid result of the category (`floor`, 100 by default) and the best one (`max_score`, 1000 by default), computed with integers and rounded half up. Only fouls get `foul_score`, and an event not done gets `dnf_score` once the competition is closed. Ranks per event follow the points, ties sharing a rank (`Ranks`).

`CategoryRanker` adds up the points of every event of the category, then breaks ties with the configured criteria (`TieBreaker`): number of first places, number of top-3 places, youngest child. What no criterion can separate is left as a tie; an admin can order it by hand (`ManualTieBreak`, table `tie_decisions`), and that decision applies only while exactly the same children are tied.

`Standings` puts it together for one category, reading the current attempts from the database. Rankings are recomputed on every display: before the closing an event not done counts as "to do", after it as "not done".

## Displays (§ 13)

- `Progress`: for each workshop, present children who have at least one attempt, out of those expected.
- `Ceremony`: podium of the current category (present children ranked 1 to 3), places revealed one by one; state kept in `app_meta` so that the big screen and the phone share it.
- Participant space: the child's raw results while running; points, ranks and the category ranking after the closing. Other children are shown as first name + initial.
