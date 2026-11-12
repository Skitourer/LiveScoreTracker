# LiveScoreTracker — Specification

> Version 1.24 — 12 November 2026
> This document is the reference for development, carried out step by step under the supervision of the project owner.
> English mirror of [CAHIER_DES_CHARGES.md](CAHIER_DES_CHARGES.md) (French). The French version is the reference; both are kept in sync.

---

## 1. Context and goals

A school sports association (hereafter "the organizer") holds an **individual multisport competition** every year for about 100 primary school children (French grades CP to CM2, ages 6–11), over one morning (about 3 hours).

Until now, it has been managed with an online form, spreadsheets and paper sheets. **LiveScoreTracker** replaces all of this with a single web application covering:

- online **registration**;
- **check-in** of children on competition day (sign-in, bibs, medals);
- **result entry** in the field, from the referees' phones, including without network;
- **automatic computation of scores** and rankings;
- **displays** (progress, award ceremony, each child's page);
- printable **documents** (lists, backup sheets, podiums).

The software is **open source** (MIT license). Everything specific to an organizer (name, events, scoring, texts, language) must be **configurable**, so that another organizer can reuse it without touching the code.

---

## 2. Glossary

| Term | Definition |
|---|---|
| **Edition** | A competition of a given year (e.g. "2027 edition"). |
| **Level** | School grade: CP, CE1, CE2, CM1, CM2 (French primary school, roughly ages 6 to 11). |
| **Level group** | Set of levels doing the same events (e.g. "CP-CE1", "CE2-CM2"). |
| **Gender** | Girl or Boy. |
| **Category** | Level + gender combination (e.g. "CM1 Girls"). 10 categories by default. It is the unit for computing scores and rankings. |
| **Workshop** | A physical station of the competition (e.g. "Sprint"). A workshop can serve several level groups. |
| **Variant** | The version of a workshop for a level group (e.g. "Sprint 30 m" for CP-CE1, "Sprint 40 m" for CE2-CM2). It is the **event** for ranking purposes. |
| **Attempt** | One try by a child at an event. An event may allow several attempts. |
| **Raw result** | The measured performance: time, distance or score. |
| **Score** | The points (0 to 1000) awarded to a raw result by the scoring scale. |
| **Foul** | An attempt with no valid performance (line crossed, fall…). |
| **DNF** | *Did Not Finish*: event not done by a present child at closing time. |
| **Bib** | Unique number assigned to a child for the edition. |
| **Table referee** | Volunteer who enters results at a workshop. |

---

## 3. Roles and permissions

### 3.1 Roles

| Role | Authentication | Main device |
|---|---|---|
| **Admin** (head referee) | Individual account + password + **mandatory 2FA** | Computer and phone |
| **Organizer** | Individual account + password | Phone |
| **Table referee** | Single **shared account** (common login + password) | Phone |
| **Participant** | Bib number + date of birth | Phone (child or parent) |
| **Public** | None (dedicated links without personal data) | Screen or projector |

Having **at least 2 admins** is recommended, so that they can reset each other's 2FA.

### 3.2 Permission matrix

| Feature | Admin | Organizer | Table referee | Participant |
|---|:-:|:-:|:-:|:-:|
| Configuration (events, scoring, texts, help) | ✅ | | | |
| Phase change (preparation, in progress, closing, reopening) | ✅ | | | |
| Account management | ✅ | | | |
| Manually open/close registration | ✅ | | | |
| Approve waiting list, exceed quota | ✅ | | | |
| Spreadsheet import (registrations, backup results) | ✅ | | | |
| Backup restore, purge | ✅ | | | |
| Email to all registrants | ✅ | | | |
| View registrations | ✅ | ✅ | | |
| Fix a registration (typo) | ✅ | ✅ | | |
| Add a child on site (competition day) | ✅ | ✅ | | |
| Unregister a child | ✅ | | | |
| Check-in and check-out | ✅ | ✅ | | |
| Enter/correct results — all events | ✅ | ✅ | | |
| Enter/correct results — selected event | ✅ | ✅ | ✅ | |
| Workshop follow-up (remaining and done bibs) | ✅ | ✅ | ✅ | |
| Workshop help page | ✅ | ✅ | ✅ | |
| Monitoring screens (progress, missing events, checks) | ✅ | ✅ | | |
| Results and scores per event/category, rankings | ✅ | ✅ | | |
| Printing (lists, paper sheets, podiums) | ✅ | ✅ | | |
| Driving the ceremony screen | ✅ | ✅ | | |
| Approving actions proposed by Claude | ✅ | ✅ | | |
| Audit log | ✅ | | | |
| Child's personal page | | | | ✅ (own) |

The **table referee** account is active during the "competition in progress" phase and, **read only**, after closing ("closed" phase): the referee can look at the workshop follow-up and each child's attempts, without entering or correcting anything. It is inactive in preparation.

---

## 4. Edition lifecycle

```
 Preparation ──► Competition in progress ──► Competition closed ──► GDPR purge (D+30)
      ▲                  ▲     │                    │
      │                  └─────┼──── reopening ◄────┘
      └── possible return ─────┘    (admin, logged)
```

| Phase | Result entry | Configuration | Participant view |
|---|---|---|---|
| **Preparation** | Closed (except tests) | Free | List of events to do |
| **In progress** | Open | Editable with safeguards (§ 10.4) | Raw results + events done/to do |
| **Closed** | **Locked** | Locked | Results, scores, ranks, own category ranking |

- Switching to "closed" requires **explicit admin approval**. It is preceded by a **blocking alert** listing present children with missing events and unresolved ties. The admin confirms knowingly.
- **Reopening** (back to "in progress") is possible if something was forgotten. It is recorded in the log.
- Details of the closing and of the checks dashboard: § 10, step 8b details.
- Online registration has its own state (§ 5.2), independent of these phases.

---

## 5. Registration

### 5.1 Public registration form

Accessible without an account, on the application website. Fields:

**Child**
- Last name *(required)*
- First name *(required)*
- Date of birth *(required)* — also used as the access code to the participant page and as a tie-breaker.
- Gender *(required, list)*
- School *(required, configurable list)*
- Grade *(required, list: CP, CE1, CE2, CM1, CM2)*

**Responsible adult present on competition day**
- First and last name *(required)*
- Phone *(required)*
- Email *(required)*

**Checkboxes**
- *(required)* "I understand that the child is under the full responsibility of the accompanying adult for the whole event."
- *(required)* "I have read and accept the rules" (link to the rules).
- *(optional)* "I volunteer to help run a station."

**GDPR information notice** displayed below the form (see § 15).

Checks: required fields, format of the email (full address with a domain) and of the phone (10 digits starting with 0, or international format "+33 6…"; a leading zero lost by a spreadsheet is restored), checked in the browser and on the server, date of birth / grade consistency (warning), probable duplicate (same last name + first name + date of birth: rejected with a message).

Anti-bot protection **without any third-party service**: invisible honeypot field, minimum fill-in time, rate limiting per IP address.

A parent can register **several children** (siblings): after one registration, offer "register another child" while keeping the adult's details.

### 5.2 Opening, closing, quota

- Configurable **opening and closing date and time**. Outside that period, the form shows a message ("opens on …" / "registration closed").
- The admin can **force the state** manually (open/close) regardless of the dates.
- Configurable **quota** (100 by default).

### 5.3 Registration flow

1. The parent fills in the form.
2. The application sends a **verification email** (link valid for **48 h**).
3. The parent clicks: the registration is **verified**.
   - If the quota is not reached: status **registered**, bib assigned, **confirmation email**.
   - Otherwise: status **waitlisted**, "you are on the waiting list" email, admin notification.
4. A registration not verified within 48 h **expires**. Only verified registrations count toward the quota. The admin can manually approve a pending registration.

Registration statuses: `pending_verification` → `registered` | `waitlisted` → `registered` | `withdrawn` | `expired`. A withdrawn child can be **registered again** by the admin (or put back on the waiting list).

### 5.4 Waiting list

- Handled **manually by the admin**: they move a child from the waiting list to "registered", including beyond the quota.
- The move triggers the "your registration is confirmed" email.
- **Confirmation email**: the same email is sent whatever the origin of the registration (public form, admin or organizer; box ticked by default for a manual entry, unticked by default for an import). It recalls the bib, date, venue, welcome time and the adult's responsibility, and **thanks volunteers** for offering to run a stand. The admin can send it again from the child's record.

### 5.5 Withdrawal

Two ways:
- **Unsubscribe link** in the confirmation email (with confirmation);
- **Removal by the admin** (at the parent's request).

In both cases the admin is notified, so they can approve a child from the waiting list.

### 5.6 Bibs

- Assigned **in the order children reach the "registered" status**, starting from 1 (or a configurable starting number): each newly registered child gets the **lowest free number**.
- **During preparation**, a withdrawn child **gives their bib back**, and it goes to the next registered child: the stock of paper bibs is limited. If registered again, they get the first free number.
- **Once the competition has started**, a withdrawn child **keeps their bib**: it is never given to another child. If registered again, they get their bib back.
- On-site additions (§ 5.8) get the first free number.

### 5.7 Spreadsheet import (fallback and migration)

For registrations collected elsewhere (external form, HelloAsso…):
- Accepted formats: **.xlsx** and **.csv**.
- **Configurable column mapping** (e.g. "the gender column is called *Catégorie*").
- **Cleanup**: trimming extra spaces, normalizing name case, recognizing value variants ("Garçon", "garcon", "G").
- **Incremental import**: only **new** children are added (identified by last name + first name + grade, plus date of birth if present). Existing bibs do not change.
- **Preview before approval**: new rows, already-known rows, invalid rows (missing field, unknown grade…), probable duplicates.

> **Open point**: exact origin of the file used in 2026 (Google form or HelloAsso) — to be checked to test the import.

### 5.8 On-site addition on competition day

The rules officially forbid on-site registration, but the application lets an **admin or organizer** add a child at any time, **regardless of the quota**, with the same fields as the form (date of birth optional in this case).

### 5.9 "Registrations" screen (admin, organizer)

- Full list with all information (full name, contacts, volunteering…), search, filters (status, school, grade, gender, volunteers).
- Child record: view, edit, change history.
- Counters: registered / quota, waiting list, pending verification.
- Spreadsheet export of the list.

---

## 6. Check-in

### 6.1 Printable check-in list

PDF containing, for each registered child: **first name, full last name, bib number** (and grade). **Sorted by first name** (alphabetical, ignoring accents), with a heading per letter and the bib in large type on the right. Displayed at the entrance so children can find their number. Its download is recorded in the audit log (it holds full names).

### 6.2 Mobile check-in view (admin, organizer)

1. Enter the **bib number** (numeric keypad) or search by name.
2. Large display of **first name, last name, grade** for verification.
3. **Check-in**: "bib handed out" box (counts as present) — timestamped.
4. **Check-out**: "bib returned" and "gift + medal handed out" boxes — timestamped.

Counters at the top of the screen: present / registered, bibs returned, gifts handed out.

Details:
- The name search only looks at the child's first and last names.
- After the bib is handed out, or once both check-out boxes are ticked, the screen goes back to the "bib" field for the next child.
- A box ticked by mistake can be unticked (with confirmation). Check-in cannot be undone while a check-out box is ticked. Every change is timestamped and recorded in the record's history, with the person's name.
- Check-out boxes are only offered for a child checked in on arrival; only a registered child can be checked in.
- Check-in opens in the "in progress" phase and remains possible after closing (medal ceremony); during preparation the screen is read-only.
- Lists, with their size on each button: **all bibs** (sorted by number, with each child's state, including withdrawn children who keep their bib), **present**, **absent** (registered, no bib handed out), **not left yet** (bib not returned or gift not handed out, to collect the bibs at the end of the day) and **left**.

### 6.3 Attendance statuses

- **Absent**: registered but bib never handed out. Excluded from everything (entry lists, scoring, rankings); only appears in an "absentees" summary.
- **Present**: bib handed out. Appears in the workshops' "Follow-up" lists.

---

## 7. Events: configuration model

### 7.1 Workshops and variants

- A **workshop** (e.g. Sprint) has a name, a help page and one or more **variants**.
- Each **variant** is tied to a **level group** and carries its own parameters (distance, plausible range, etc.). The variant is the event used for ranking.
- A workshop may have a single variant (e.g. Football, CP-CE1 only).
- **Level groups** are configurable (default: CP-CE1 and CE2-CM2).

### 7.2 Variant parameters

| Parameter | Values | Example |
|---|---|---|
| `measure` | `time`, `distance`, `score`, `counter` | `time` |
| `better` | `lower`, `higher` | `lower` |
| `time_format` | `ss.cc` or `m:ss.cc` | `ss.cc` |
| `attempts` | integer ≥ 1 | 2 |
| `foul_mode` | `strict` or `lenient` (§ 7.4) | `strict` |
| `plausible_range` | plausible values (warning if out of range) | `[5.00, 12.00]` |
| `max_value` | (score/counter) maximum possible | 30 |
| `modifiers` | list of bonuses/penalties (§ 7.5) | goal scored: −3 s |
| `bibs_per_timer` | 1 or 2 (§ 8.4) | 2 |
| `label` | text shown during entry (translation key or text) | "30 m" |

### 7.3 Measure types and storage

| Type | Entry | Storage | Display |
|---|---|---|---|
| **Time** | digits only: `1225` → 12.25 s; `12324` → 1 min 23.24 s | integer in **hundredths of a second** | `12.25 s` or `1 min 23.24 s` (decimal separator per locale) |
| **Distance** | digits only: `1234` → 12.34 m | integer in **centimeters** | `12.34 m` |
| **Score** | integer | integer | `22 pts` |
| **Counter** | buttons (§ 8.5) | detail of each action + total | `3 baskets, 2 rims, 1 board = 22 pts` |

The interpreted value is displayed **in large type** before approval, to limit typing errors.

### 7.4 Attempts and fouls

- **Several attempts**: all are recorded. The **best** one is kept, according to the event's direction.
- Attempts **are not necessarily consecutive**: when a bib is entered, the application shows "attempt 2 of 3" and the history of previous attempts.
- **Foul**: a dedicated button during entry. Two modes, configurable **per variant**:
  - **strict**: the foul uses up an attempt;
  - **lenient**: the foul is recorded (traceability) but does not use up an attempt; the application asks for the same attempt number again.
- A child whose **attempts are all fouls** gets the **foul score** (§ 9.2).

### 7.5 Modifiers (bonuses and penalties)

- Configurable list per variant: label, value, in the event's unit (e.g. "Goal scored: −3.00 s", "Cone knocked over: +2.00 s").
- During entry, each modifier appears as a **checkbox** (or a counter if it can apply several times).
- The **measured result and the modifiers are stored separately**. Retained result = measured + sum of modifiers.

### 7.6 Counter mode

For skill events (e.g. basketball):
- Configurable **actions** with their points (e.g. basket 3, rim 2, board 1).
- **Limit**: either a **duration** (e.g. 1 min 30) with a built-in timer, or a **number of shots** (e.g. 10) with an extra "miss" button to count shots without points.
- **Single attempt** in counter mode.

---

## 8. Result entry (table referee)

### 8.1 Interface principles

- **Mobile first**: big buttons, numeric keypad, strong contrast (use in bright sunlight), usable with one hand.
- The referee logs in with the shared account, then **chooses their workshop**. The choice is remembered on the phone.
- The **screen stays on** during entry and timing.
- Permanent indicator: **online / offline** and **number of entries waiting to be sent**.

### 8.2 Standard entry flow

1. Enter the **bib**.
2. The application displays in large type: **first name + initial, level, variant** (e.g. "Léa M. — CE1 → **30 m**"), the **current attempt** and history.
3. Immediate checks (§ 10): unknown bib, child not checked in, event outside their group, no attempts left.
4. Enter the **result** (or **Foul** button), **modifier** checkboxes.
5. **Approval**. Any warnings ask for confirmation, without blocking.

Details:
- After approval, the screen goes back to the "bib" field for the next child.
- Entry is open only in the "in progress" phase (during preparation the screens can be viewed but the form is hidden).
- A result saved with a confirmed warning is flagged **"to check"** (checks dashboard, step 8).
- Each entry carries a unique id created with the form: sending it twice (double tap, slow network) saves it once.
- A child not checked in can be **marked present** from the entry screen, after confirmation.
- Time events are entered by **race** (§ 8.4) and counter events with the **counter** (§ 8.5). The single-child screen remains available to view and correct a child's attempts.

### 8.3 Correction

- The referee can **correct a result already entered** at their workshop (search by bib).
- A correction **does not overwrite** the entry: it creates a new version. The full history (who, when, previous value) is kept and visible to the admin.
- An attempt entered by mistake can be deleted (same principle: flagged, not physically erased).
- Below the form, the list of attempts shows for each one the result, the modifiers, the time and the author (referee's first name), the versions before correction (struck through) and the deleted attempts.

### 8.4 Built-in timer (1 or 2 bibs)

For standing-start events (sprint, hurdles, hockey, obstacle course, football…), depending on the variant's configuration:

**Built-in timer mode**
1. The referee enters **1 or 2 bibs** (per `bibs_per_timer`). Visual check of first names and variants.
2. **Start** button (on the signal) → it turns into **Finish**.
3. One tap on **Finish** each time a child crosses the line. With 2 bibs, the application records two times.
4. The referee gives the **finishing order**: the application suggests the entry order, with a **Swap** button.
5. **Review screen**: each bib's time, modifiers, then **approval**. Times remain editable before approval.

Additional buttons:
- **"Did not finish"** (per bib): fall, stop → foul;
- **"Cancel race"**: false start, everything is reset without recording anything.

The timer runs **on the phone** and works offline. The Finish button is only active after Start.

**Manual timer mode**: the referee enters 1 or 2 bibs, then each child's time, measured with an external stopwatch.

Both modes are always available. The **built-in timer is offered by default** on every time workshop (the former `built_in_timer` setting is ignored).

Details:
- On a time workshop, the "bib" screen shows the race fields (the 2nd bib is optional) and two buttons: **Built-in timer** (first, main button) and **External stopwatch**. A separate field opens a bib's attempts to view or correct them.
- Checks before the start: unknown bib, wrong level or bib typed twice → refused. Two children on different variants (e.g. a CP child on 30 m and a CM1 child on 40 m) → allowed, with a clearly visible message.
- The time is taken **when the button is touched** (not when released), then rounded down to the hundredth, as on a stopwatch.
- **Each Finish tap is acknowledged at once**: the button flashes, the phone vibrates, the time shows in the list of arrivals ("1st to finish: 12.34") and the button names the arrival expected ("Finish 2/2"). An **"Undo the last finish"** button corrects an extra tap.
- The first time goes to the first bib typed; **Swap** exchanges the two times. With a "Did not finish", the only time goes to the other child.
- The race is approved **as a whole**: nothing is saved until both times are readable and the warnings (§ 10.1) confirmed.
- Each time records where it came from: **built-in timer**, or **built-in timer, time edited by hand** when the referee changed it before approval (shown in the attempt history).
- Leaving the page during a race asks for confirmation.
- Without JavaScript, the screen works as the external stopwatch mode.

### 8.5 Counter-mode entry

1. Enter the bib, verification.
2. **Start**: countdown (duration limit) or shot counter "shot 7/10" (shot limit).
3. One **big button per action** (+ "miss" with a shot limit). Live total. **Undo last action** button.
4. Automatic end (time up or last shot) with **sound and vibration**. Buttons are locked.
5. Detailed summary, then approval.

Direct entry of the total is possible as a fallback.

Details:
- A **Stop now** button ends the count early (incident).
- The summary shows the count of each action in **editable** fields, with the total recomputed; it is also the correction screen.
- The saved result is the total of the points, with the **detail of the actions** (e.g. "Basket ×3, Rim ×2, Board ×1"), shown in the attempt history.
- More shots than allowed → warning (§ 10.1).
- Sound and vibration depend on the phone (no vibration on iPhone; no sound when the phone is on silent).
- Without JavaScript, the referee types the count of each action.

### 8.6 Workshop follow-up

**"Follow-up"** screen listing, for the chosen workshop, the **registered children** of the relevant levels, in tabs showing their number:
- **To do**: checked in, with attempts left;
- **Started**: at least one attempt made, but not all;
- **Finished**: all attempts made;
- **Not checked in**: not yet arrived at reception;
- **All**.

One row per child: bib, first name, event (e.g. 30 m / 40 m), attempts made out of attempts allowed, best result, "not checked in" and "to check" badges. Sorted by bib. The search (bib or first name) covers every child; a bib not found gives the reason (unknown, wrong level).

On a time workshop, a checkbox per unfinished child and a **"Start the race"** button open the race (§ 8.4) of the 1 or 2 ticked children directly.

### 8.7 Workshop help page

Accessible at any time from the entry screen (§ 12.6).

### 8.8 Offline operation

- The application is a **PWA** (installable on the home screen, iOS and Android).
- Data needed for entry (children list: bib, first name, initial, level, gender, attendance; event configuration) is **cached** on the phone and refreshed regularly.
- Each entry gets a **unique identifier generated on the phone**. It is stored locally, then **sent as soon as the network is back**. The server ignores an entry it already received (no duplicates on resend).
- Conflicts (e.g. another phone already entered the last allowed attempt) are **accepted and reported** to the admin, never lost.
- Offline entries arriving **after closing** are reported to the admin and not merged automatically.

Details (step 7a):
- The entry screens (bib, child, race, counter) are built **by the phone**, with or without network: a single behaviour. Corrections, attempt history, "Follow-up" and reception stay server pages (network needed). Without JavaScript, the server screens remain usable.
- **Workshop pack**: when a workshop is opened, the phone downloads and keeps its events, the children with a bib (bib, first name and initial of the last name, level, presence), their attempts at the workshop and the screens' texts; it refreshes it every 2 minutes and when the network comes back. The full last name never leaves the server.
- **Warnings** (§ 10.1) are computed on the phone with the server's rules, counting entries still waiting; the server checks again on receipt and adds its own (e.g. one attempt too many entered by another phone → "to check").
- **Queue**: every result is first kept on the phone (it survives a network cut, a restart or a logout), then sent as soon as possible (every 20 s, when the network comes back, or with "Send now"). A race is sent as a whole. A counter's total is recomputed by the server from the detail of the actions. An entry made more than a minute before it arrives keeps the phone's time.
- **Status line**: online (everything sent), offline, number of entries waiting, session expired (log in again: the entries stay on the phone), time of the data. The "Entries on this phone" list shows what is waiting; an entry refused by the server (unknown bib…) or arriving outside the "running" phase stays flagged there and can be removed.
- **Installable application**: manifest and icon; a *service worker* keeps the application's files (renewed at each deployment) and the last version of each workshop page opened.
- On **logout**, the children's data is erased from the phone; waiting entries are kept.
- **After closing** (step 8b): the referee phone keeps sending the entries it still holds (the account stays active, read only, § 3.2). These entries are kept aside on the server, not counted, then accepted or refused from the checks dashboard; the phone removes them from its queue. In preparation, entries stay on the phone as before.
- **Follow-up and help without network**: from a workshop's entry page, "Follow-up" and "Help" open in the phone's application, built from the workshop pack (which also holds the help page and the contacts): same tabs, search and race start as the server page (§ 8.6), counting the entries still on the phone; a line leads to the child's screen on the phone. Help images are only visible with network.
- **No link leads to an error without network**: the entry page, follow-up and help of a workshop already opened work; links that need the network (change workshop, a bib's attempts, dashboard…) are greyed and show "No network: this action needs it" without leaving the page; forms sent to the server (logout included) are held back the same way. Any other page requested without network (reload, typed address) shows an "Offline" page kept by the *service worker*, with a way back to the previous page and the list of workshops usable without network; a workshop's follow-up or help requested without network opens the phone's.

### 8.9 Fallbacks

Three fallback levels in case of trouble:
1. **Offline** on the phone (§ 8.8).
2. **Entry by an admin or organizer** from their interface, for any event and any child.
3. **Paper, then spreadsheet import**:
   - **printable paper sheets** (PDF) per workshop and category: bib, first name, last name, attempt and modifier columns;
   - **spreadsheet template** downloadable in advance (one sheet per variant, columns: bib, attempts, modifiers);
   - **import** of the filled-in template, with validation and display of conflicts with existing results before approval.

---

## 9. Scoring and rankings

### 9.1 Computation population

For each **variant** and each **category**, the scale is computed over the **best valid performances** of the **present** children in the category. Absentees, fouls and DNFs **never** serve as bounds.

### 9.2 Event score computation

Parameters (configurable globally or per variant):
- `max_score` = 1000
- `floor` = score of the worst valid performance (**proposed default: 100**)
- `foul_score` = score of a child whose attempts are all fouls (**default: 1**)
- `dnf_score` = score for an event not done (**0**)

With `best` and `worst` the best and worst valid performances in the category, and `v` the child's performance:

```
progression = (v − worst) / (best − worst)        # between 0 and 1, whatever the direction
score       = floor + progression × (max_score − floor)
```

The score is **rounded to the nearest integer**. For a "lower is better" event the formula applies as is: `best` < `worst`, and the ratio stays between 0 and 1.

**Edge cases**
- Only one child with a valid performance, or all performances identical: **1000 points** for everyone.
- No child with a valid performance in the category: no scale. Fouls get `foul_score`.

**Note**: with `floor = 1`, you get the historical scale (1 to 1000). Then set `foul_score = 0` to distinguish a foul from the worst performance. The configuration is **rejected** if `foul_score` is not strictly lower than `floor`, or if `dnf_score` is higher than `foul_score`.

**Example** (`floor` 100, higher is better): best = 68 m, worst = 8 m, v = 45 m
→ progression = 37/60 = 0.6167 → score = 100 + 0.6167 × 900 = **655 points**.

### 9.3 Per-event statuses

| Situation | Score |
|---|---|
| At least one valid attempt | scale (§ 9.2) |
| Fouls only | `foul_score` |
| No attempt at closing (present child) | **DNF** → `dnf_score` (0) |
| Absent child | excluded from ranking |

### 9.4 Per-event rank

Within each category, children are ranked by decreasing score for each event. Children with the same score on an event share the same rank.

### 9.5 Overall ranking

- **Total** = sum of the scores of all events of the level group.
- **10 rankings**, one per category.
- No ranking by school.

### 9.6 Tie-breaking on the total

Criteria applied **in order** until the tie is broken:
1. Highest **number of 1st places** in events;
2. Highest **number of top-3 places** in events;
3. The **youngest** (most recent date of birth).

Only events with a **valid performance** count as places (a foul or a DNF is never a 1st place). The "youngest" criterion only applies when every tied child has a date of birth.

If the tie persists (twins, missing date of birth), the application reports it to the admin, who **decides manually** before closing.

The order and choice of criteria are configurable. The ranking discreetly shows the criterion that broke the tie (e.g. "tie broken: 1st places").

**There can be no ex aequo on the podium.**

### 9.7 Recomputation

Scores and rankings are **always recomputed from raw results**. No computed value is frozen before closing.

---

## 10. Automatic checks

Checks are **non-blocking warnings** unless stated otherwise. The affected results are flagged **"to review"** and gathered in a **checks dashboard** (admin, organizer), with direct access to correction and the ability to mark them "reviewed".

### 10.1 On entry

| Check | Effect |
|---|---|
| Unknown bib | **Blocking** |
| Event outside the child's level group | **Blocking** |
| Entry during the "closed" phase | **Blocking** |
| Child not checked in | Warning + offer to mark as present |
| Number of attempts already reached | Warning (entry allowed, flagged) |
| Value outside the variant's plausible range | Warning |
| Value far from the category median (once some results exist) | Warning |
| **Probable wrong distance**: time closer to the other variant's distribution (e.g. a CE1 time typical of the 40 m) | Warning "Correct distance?" |
| Probable duplicate (same bib, same event, same value within a few seconds) | Warning; deduplication during sync |
| Counter mode: more shots than allowed | Warning |
| Race: same bib typed twice | **Blocking** |

### 10.2 Global checks

- Present children with **missing events** (§ 11.3).
- **Unresolved ties** in rankings.
- **Sync conflicts** and entries arriving after closing.
- Children with **more attempts than allowed**.

Details (step 8b):
- **Checks dashboard** (`/checks`, admin and organizer; count of pending items on the "Checks" tile of the dashboard, outside preparation). Everything is recomputed on every display. Three parts:
  - **Results to check**: every current result flagged "to check" on entry (warnings confirmed by the referee or added by the server, including conflicts between phones), plus those flagged by the two checks below. For each: bib, child, event, result, author, reasons, "Correct" button (child's screen) and "Checked" button (admin, organizer). "Checked" is recorded (who, when) in the attempt history and the audit log. A correction creates a new version of the result: it starts unchecked again.
  - **Ties to break** (§ 9.6): during the competition, a tie is only listed once the tied children have done all their events (before, it may still change); after closing, all of them. The admin chooses the order (one place per child); the ranking shows "decided by the head referee". The decision applies to these children as long as they stay tied: if results change, it is ignored. It can be cancelled. Decision and cancellation are recorded in the audit log.
  - **Entries after closing** (§ 8.8): result, time on the phone, time received, referee; "Accept" (the result is recorded as a normal entry, with the usual checks, and marked "received after the closing, accepted") and "Refuse" (kept on the server). Possible while running or closed, without reopening; rankings are recomputed. Recorded in the audit log.
- **Checks against the other results** (§ 10.1), made on the checks dashboard rather than on entry, only for times and distances, and only once at least 5 other results are known. Only each child's retained result is compared:
  - **far from the median**: more than twice, or less than half, the median of the other children of the category;
  - **right distance?** (timed events with one variant per level group, e.g. sprint 30 m / 40 m): a time at least as slow as the median time of the other (longer) distance, or at least as fast as that of the other (shorter) distance, among children of the same gender.
- **Closing** (admin, "Competition phase" page): the "Close the competition" button first opens a recap page: present children with missing events (list), ties to break, results to check, late entries waiting. The admin can close anyway ("Close anyway"). The number of pending items is written to the audit log with the phase change. After closing: entry closed, an event not done counts as "not done", referee account inactive (except for sending waiting entries, § 8.8).
- **Reopening** (admin): back to "in progress", after confirmation; recorded in the audit log.
- **Test data**: with the "fictitious results" option, a few children get an odd value (three times too big, or a time half as long again) so that the checks dashboard has content.
- **Automatic refresh** of the follow-up pages (checks dashboard, results and rankings, missing events, workshop follow-up, dashboard): every 30 seconds, the changing part is reloaded if it changed, keeping the scroll position and open sections, with "Updated automatically · HH:MM". Nothing moves during an action (field being typed in, list open, box ticked, tap in the last 5 seconds), nor when the page is not visible or without network. Form pages are not concerned.

### 10.3 On import

Probable duplicates, grade inconsistent with date of birth, missing required fields, unrecognized values.

### 10.4 Configuration changes during the competition

Settings remain editable during the "in progress" phase, with three levels of safeguards:

**No side effects, automatic recomputation**
- floor, foul score, plausible ranges, labels.

**With impact: preview then confirmation**
The application shows the affected children before approval (e.g. "12 children will have to redo an attempt") and can alert the relevant workshops.
- **More attempts**: the affected children get attempts to do again.
- **Fewer attempts**: only the first N **attempts** (chronological order) are kept. The others are kept but excluded.
- **Foul mode strict → lenient**: fouls no longer count, attempts become available again.
- **Foul mode lenient → strict**: fouls count, possibly exceeding the limit (same first-N rule).
- **New event**: all the relevant children have to do it.
- **Change of a child's grade or gender**: their results follow them, and the affected categories are recomputed.

**Forbidden once results exist**
- Changing a variant's measure type or unit.
- Deleting an event: it is **deactivated** instead (results kept but excluded from computation).

Every change is written to the **audit log** (who, what, when, old and new value).

---

## 11. Admin and organizer screens

### 11.1 Dashboard

- Current phase, phase change buttons (admin).
- Counters: registered, present, checked out, results entered, results to review, entries waiting for sync.
- Quick access to all features.

### 11.2 Results per event and category

For each variant × category: list of children with **all attempts**, modifiers, **retained result**, **score**, **rank**, status (valid, foul, DNF, to review). Corrections possible from this screen.

### 11.3 Missing events

- For each workshop: the list of **present children who have not done it** (bib, first name, last name, category), in large type, readable for a **PA announcement**.
- Reverse view: for each child, their missing events.
- Filters by workshop and category.

### 11.4 Rankings

Overall ranking of each category: rank, first name, last name, total, score per event, tie-break criterion if any.

- **Attempts used up**: once a child has done every attempt, no attempt can be added (child screen, race, offline phone); a result can only be changed with "Correct". Only exception: an entry made offline by another phone is accepted and marked "to check" (§ 8.8).

Details (step 8a):
- **Results and rankings** (`/results`): one page per category (chosen with chips) with the overall ranking (rank, bib, first name, last name, total, score of each event, tie-break criterion, "events pending" badge), then the detail of each event (scale bounds, every attempt, retained result, score, rank, status, "to check" badge). Each line leads to the child's correction screen. Everything is recomputed on every display (§ 9.7); before closing, an event not done yet is "to do".
- **Missing events** (`/missing`): "by workshop" view in large print (bib, first name, last name, grade) and "by child" view, with filters by workshop and by category; only present children are listed.
- **Clear a bib's results at an event** (admin, organizer, "running" phase): a button on the child's entry screen deletes all their attempts at the event (they stay visible in the history, the action is written to the audit log); the child can do the event again.

### 11.5 Progress

Detailed version of the public screen (§ 13.1): per workshop and per category.

Details (step 9b): address `/progress` (admin, organizer; "Competition monitoring" tile of the dashboard); workshop × category table "done / expected", links to each workshop's follow-up and to the public screen; automatic refresh.

### 11.6 Audit log (admin)

All sensitive actions: logins, entries and corrections (with the originating phone for the shared account), configuration and phase changes, imports, restores, Claude's proposals and their approval.

---

## 12. Configuration

### 12.1 Files

The GitHub repository only contains **fictitious examples** and **generic texts**. An installation's real configuration lives in a server-specific **data directory**, outside the repository (see § 25).

```
GitHub repository                    Server (data directory, outside the repository)
config.example/                      data/
  competition.toml   (fictitious)      competition.toml   (real: name, date, venue, schools, contacts…)
  i18n/                                i18n/              (optional text overrides)
    fr.toml                            help/              (real help pages)
    en.toml                            media/             (workshop maps)
  help/                                backups/
    fr/sprint.md …                     logs/
    en/…                             .env                 (secrets)
```

- As long as the data directory holds no `competition.toml`, the application uses the **fictitious example** from `config.example/` (shown as such on the home page). The configuration screen **copies** it there on the first save, then the admin adapts it. This way the test site always follows the up-to-date example.
- **Generic translations** (`i18n/`) are versioned in the repository. The data directory can **override** them key by key (e.g. the organizer's name in an email).
- **Secrets** (database access, SMTP, API keys, Google Drive access) are in a per-installation `.env` file, never versioned.

### 12.2 `competition.toml`

**Keys** are in English (like the code); displayed **labels** go through translations. Main content:
- edition information: name, date, venue, times, organizer, contact;
- levels, genders, **level groups**, category display order;
- list of offered **schools**;
- registration: opening and closing dates, quota, first bib number;
- scoring: maximum score, floor, foul score, DNF score, tie-break criteria;
- **workshops and variants** (§ 7.2);
- column mapping for spreadsheet import;
- contacts shown on help pages;
- backup and purge settings.

A complete example **with fictitious data** is given in **appendix A**.

### 12.3 Configuration screen (admin)

- Forms to change every setting without editing the file by hand. The screen **reads and writes** `competition.toml`.
- Configuration validation before saving (explicit errors).
- Safeguards from § 10.4 during the "in progress" phase.
- Export and import of the configuration file (to start from a previous edition or share with another organizer).

### 12.4 Translations (i18n)

- **All** displayed texts (interface, error messages, emails, PDFs) go through **keys** defined in `i18n/<locale>.toml`.
- Configurable default locale. No hard-coded text in the code.

### 12.5 Email texts

Subjects and bodies of all emails live in the translation files, with variables (`{child_first_name}`, `{date}`, `{link}`…).

### 12.6 Workshop help pages

- One **Markdown** file per workshop and per locale, editable from the configuration screen (editor with preview).
- Typical content: equipment, staffing, procedure, rules, attempts, foul cases.
- **Images** possible (workshop map).
- Global **"Contacts" block** (e.g. "Data entry: …", "Other issues: …") with **tap-to-call numbers**. Visible only to logged-in accounts (referees, organizers, admins).

---

## 13. Displays

### 13.0 Public home page (step 9a)

At the site's address (`/`), **without login**, readable on a phone, **with no personal data**:
- **The edition**: name, date, times, venue (link to the OpenStreetMap map), organizer, contact email (configuration § 12).
- **Presentation text** in Markdown (`pages/<language>/home.md` in the installation's data folder, else the fictitious example), like the help pages; editable online in step 12.
- **The events** of each level group, read from the configuration.
- **Registration**, according to the opening and closing dates (local time of the installation) and the quota (§ 5.2): "opens on …", **places left out of the quota** with the closing date, "full: new registrations go to the waiting list", "closed", or "dates announced soon". The "Register a child" button leads to the public form (step 11; until then it shows as "coming soon"). During the competition and after closing, this block gives way to a message (and later to the link to the progress screen and the participant space).
- Links to the **rules** (`/reglement`, Markdown `pages/<language>/rules.md`, also linked from the registration form) and to the contact email; at the bottom, a discreet "Organization space (admins, organizers, referees)" link to the login (also "Log in" in the top bar).
- **Only the home page is indexed by search engines, and only in production**; every other page, and the whole test site, stay excluded.
- The technical status (database, migrations, configuration, version) moves to the `/status` page, reached from the admin's dashboard ("System status" tile).

### 13.1 Progress screen (public)

- Dedicated link, **no login**, for a projector or TV. **No personal data.**
- For each workshop: children done / expected, progress bar.
- **Least advanced workshops highlighted**, to steer children toward them.
- Overall progress. Automatic refresh (every few seconds).
- Details (step 9b): address `/avancement`, linked from the home page during the competition. A child has "gone through" a workshop as soon as there is at least one attempt; "expected" children are the present (checked-in) children whose level group has an event at that workshop. The **2 least advanced** workshops still incomplete (in proportion) show "Go there" during the competition. Refreshed every 10 seconds; two columns on a large screen; nothing but the screen (no menu). Before the competition: "not started yet"; after closing: the final figures, without highlighting.

### 13.2 Ceremony screen

- Available **only after closing**.
- Shown on a big screen, **driven from the phone** of an admin or organizer: "next category", "reveal next place".
- For each category: reveal **3rd, then 2nd, then 1st**, with **full first and last names** (the children are called over the microphone; requested in step 9c) and total points, on the screen and on the control page.
- Configurable category order.
- Details (step 9c):
  - **Screen** `/ceremony`: on the computer connected to the projector, **logged in as admin or organizer** (no public link). It follows the state every 2 seconds; no menu nor messages; "Full screen" button. Stepped podium (2nd on the left, 1st in the middle, 3rd on the right), a place not revealed shows "?", the last place revealed is animated (unless the device asks for less motion).
  - **Control** `/ceremony/control` (admin, organizer; "Ceremony" tile of the dashboard, link from the phase page once the competition is closed): big "Reveal the 3rd / 2nd / 1st place" button, then "Next category"; "Hide the last place", "Previous category"; category order adjustable (up, down, go to a category), by default the configuration's.
  - Podium: **present** children ranked 1 to 3 in the final ranking (ties broken by hand included); an unbroken tie shares the same place and is revealed at once. The state (order, category, places revealed) is kept in the database, shared by the screen and the phone.
  - Only available in the "closed" phase; before, both pages say so.

### 13.3 Participant page

Login with **bib + date of birth**, with temporary lockout after several failures.

| Phase | Displayed content |
|---|---|
| Preparation | List of their group's events |
| In progress | Events done with **raw results** (attempts), events **still to do**. No scores or ranks. |
| Closed | Raw results, **scores**, **per-event rank**, **overall rank**, and their **category ranking** as a list (first name + initial, scores, ranks). |

Other children are always shown as **first name + last-name initial**.

Details (step 9b): address `/participant`, linked from the home page. Only registered children with a bib can log in; temporary block after 5 failures for a bib or 20 for an IP address (§ 15). This login is independent of the volunteers' accounts and ends after 30 minutes without a visit ("Leave" button). The logged-in child sees their full name. During the competition, raw results show as they come, including those "to check", without any particular mention. After closing: total and rank in the category, table of events (results, points, rank per event; "not done" if needed), then the category ranking (present children, first name + initial, points) with their own line highlighted.

---

## 14. Generated documents (PDF and spreadsheets)

| Document | Format | Content |
|---|---|---|
| Check-in list | PDF | First name, full last name, bib, grade — **sorted by first name** |
| Backup sheets | PDF | Per workshop and category: bib, first name, last name, attempt and modifier columns |
| Backup entry template | Spreadsheet | One sheet per variant |
| Podiums | PDF | **Top 3 of each category** (first name, last name, total) |
| Full rankings | PDF / spreadsheet | Per category |
| Detailed results | Spreadsheet | All attempts, scores, ranks |
| Registrations list | Spreadsheet | All registration information |
| Event sheets | PDF | Content of the help pages (printed for workshops) |

---

## 15. Personal data (GDPR)

- **Minimization**: only data needed for the organization is collected (§ 5.1).
- **Display**: full name visible only on restricted screens (admin, organizer, check-in) and on the printed check-in list. Everywhere else: **first name + initial**.
- **Hosting**: in France (OVH). No data passed to third parties.
  - Backup copies on Google Drive are **encrypted** before upload (§ 16).
  - The **API** only exposes **bib numbers** (§ 18).
- **Retention period**: **automatic deletion one month after the competition** (§ 17). Only anonymous statistics are kept.
- **Information notice** on the registration form: data controller (the organizer), data collected and purpose, publication of results as first name + initial, retention period, rights of access, rectification and erasure (configurable contact address), hosting provider.
- **Data subject rights**: the admin can export or delete a child's data on request.
- **Security**: see § 19.

> **Open point**: update article 12 of the competition rules, and mention the publication of results.

---

## 16. Backups

- **During the competition**: backup **every 5 minutes**, all kept on competition day.
  - Shared hosting restricts scheduled tasks, so backups are **triggered by activity**: on each entry, if the last backup is older than 5 minutes, a new one is made.
- **Outside the competition**: one backup a day (OVH scheduled task, or triggered on the first visit).
- **"Back up now" button** (admin) with file **download**.
- **Automatic copy to Google Drive**, **encrypted** with a password known only to admins, in a dedicated folder.
- **Restore** from the admin interface, with confirmation, and an automatic backup of the current state just before.
- **Readable spreadsheet export** of all results, in addition to the technical backup.
- The **entry log** makes it possible to rebuild any entry made after the last backup.
- Backups (local and on Drive) are **subject to the purge** at D+30.

---

## 17. Purge and new edition

### 17.1 Automatic GDPR purge (D+30)

- **D+23**: warning email to admins.
- **D+30**: before deletion, archiving of **anonymous statistics**:
  - number of participants per category and per school;
  - results per event **without names** (distributions, best performances, averages);
  - records per event and category.
- Then **deletion** of all personal data: children, adults, emails, personal logs, local backups and Drive copies.
- Triggered by an OVH scheduled task, or on the first visit after the deadline.

### 17.2 New edition

Wizard for the admin:
1. Start from the previous edition's configuration (events, scoring, texts, help).
2. Enter the new date, venue, registration dates.
3. Change the shared table-referee account password.
4. Reset: "preparation" phase, bibs from the starting number.

The **anonymous history** of previous editions remains available (year-over-year comparison).

---

## 18. API and Claude integration (phase 2)

- **JSON REST API**, protected by a **key** generated by the admin (revocable).
- **Data exposed by bib number only** (no names).
- **Read**: phase, progress per workshop and category, raw results and scores, results "to review", missing events, anomalies, sync status.
- **Action proposals**: the API lets a client **propose** an action (e.g. flag a result "to review", report an anomaly). Proposals go into a **queue** that an admin or organizer **approves or rejects** in one click. Nothing is applied without human approval.
- All proposals and decisions are recorded in the log.
- Phase 1: the API is designed and documented, and already used internally (auto-refreshing screens). Phase 2: connecting Claude for real-time monitoring.

---

## 19. Security

### 19.1 Authentication

- Passwords **hashed** (`password_hash`, Argon2id), **12 characters minimum** for individual accounts.
- **Mandatory 2FA for admins**: TOTP codes (authenticator app: Google Authenticator, Microsoft Authenticator…). No SMS.
- **Trusted device**: when typing the code, the admin can tick "trust this device". The code is then no longer asked on that browser for **30 days** (the password still is). Trust is tied to the browser (secret cookie, hashed in the database): a cookie copied to another device is not enough. Each admin sees their trusted devices and can remove them; a password change, a 2FA reset or disabling the account removes them all.
- **10 recovery codes** provided when 2FA is enabled. An admin can reset another admin's 2FA.
- **Forgotten password** (admin, organizer): email link, valid for **1 hour**, single use.
- **Rate limiting** on all login screens (including bib + date of birth): progressive temporary lockout. A one-minute lockout starts after **5 failures** for an account (counted **per phone** for the shared referee account, so one mistake does not lock every phone) or **20 failures** from one IP address; the delay doubles with each new failure, up to 1 hour.
- **Shared referee account**:
  - active only during the "in progress" phase;
  - password changed every edition;
  - after logging in, each referee **types their first name**, recorded with each of their actions and entries;
  - each phone is **remembered with its referee's first name**: after being logged out, a referee who logs in again on the same phone gets their first name back without typing it;
  - the admin sees **the known phones** (first name, phone, last activity, connected or not) and can **log out one referee**, **block a phone for good** (misuse, e.g. a child changing results), unblock it, or forget it;
  - blocking relies on the device identifier (cookie): clearing cookies gets around it. Against a determined person who knows the password, the answer is to **change the referee password**, which logs out every phone;
  - admin "**log out all phones**" button;
  - each entry records a device identifier;
  - password of **at least 8 characters** (it is typed on many phones).
- **Account creation**: the admin creates admin and organizer accounts with a **temporary password**, which must be changed at first login.
- **First admin**: on a new installation, the `/setup` page creates the first admin account, once, with the `DEPLOY_TOKEN` of the server's `.env` file (shared hosting offers no command line).
- **Sessions** stored in the database: they expire after **4 hours of inactivity** (configurable) and at the latest **16 hours** after login. The admin sees the phones logged in to the referee account.

### 19.2 Application

- **HTTPS mandatory** (free certificate provided by OVH).
- **Prepared** SQL statements (injection protection).
- **Anti-CSRF tokens** on all forms.
- Systematic output escaping (XSS protection), security headers (CSP, etc.).
- Secure sessions (`HttpOnly`, `Secure`, `SameSite` cookies), expiry.
- **Server-side** permission check on every action (§ 3.2).
- Configuration files, backups and `.env` **outside the public directory** or protected.
- Log of logins and sensitive actions.

---

## 20. Technical architecture

### 20.1 Server

| Component | Choice | Reason |
|---|---|---|
| Language | **PHP 8.2+** | The only server-side language on OVH Perso shared hosting |
| Framework | **Slim 4** (micro-framework) | Lightweight, readable for contributors, suited to shared hosting |
| Templates | **Twig** | Automatic escaping, separation of code and presentation |
| Database | **MySQL** (included in OVH Perso) in production; **SQLite** for development and tests | Shared-hosting file systems handle concurrent SQLite writes poorly |
| Data access | PDO + prepared statements, versioned migrations | MySQL/SQLite portability |
| Spreadsheets | **PhpSpreadsheet** | .xlsx and .csv import/export |
| PDF | **Dompdf** | PDFs generated from HTML templates |
| Email | **PHPMailer** (hosting SMTP) | Reliable sending, batch sending |
| TOML | TOML 1.0 compatible library, read **and** write | Configuration screen |
| 2FA | proven TOTP library | |
| Google Drive | Google Drive API (account authorized once by the admin) | Backup copies |
| Markdown | safe Markdown converter | Help pages |
| Dependencies | **Composer** | |

### 20.2 Client (browser)

- **Server-rendered HTML** + **Alpine.js** for interactivity (timer, counter, guided entry). No complex JavaScript build.
- **PWA**: manifest, service worker, asset caching; **IndexedDB** for offline entries and data cache.
- **Automatic refresh** of screens by polling the API every few seconds.
- **Wake Lock** API to keep the screen on; vibration and sound at the end of timers.
- Light, mobile-first CSS, light/dark theme, high contrast, built on design tokens (§ 20.6).

### 20.3 Code layout

```
/public          web entry point (index.php, assets, service worker)
/src             PHP code (domain, services, controllers)
  /Domain        business model: scoring, tie-breaking, attempts… (framework-independent)
/templates       Twig templates
/config.example  fictitious example configuration (TOML, translations, help)
/migrations      database schema
/tests           automated tests (PHPUnit)
/docs            documentation (installation, configuration, contribution)
```

The **business core** (scoring, fouls, modifiers, tie-breaking, sync) is isolated and **covered by automated tests**.

### 20.4 Compatibility

- Recent mobile browsers: **Safari iOS** and **Chrome Android** (last two major versions), recent desktop browsers.
- Interface usable on a 360 px wide screen.

### 20.5 Performance

- Target scale: ~150 children, ~15 simultaneous phones, ~2,000 entries over 3 h.
- Entry response time < 1 s under normal conditions.
- Monitoring screen refresh: ≤ 10 s.

### 20.6 Visual theme

The look stays **plain** and must be easy to change without touching the screens. The final theme is chosen at the end of development (step 14).

- **Design tokens**: every visual value (accent color, neutrals, success/warning/error colors, font, text sizes, spacing, radii) is a **CSS variable** gathered in a single `tokens.css` file, in light and dark versions. Component styles only use these variables.
- **Automatic safeguard**: a **Stylelint** check in continuous integration rejects any color or size hard-coded outside the tokens file.
- **Few, named components** (button, card, table, form, badge, message…), semantic HTML, **no styles written in pages** (the Content-Security-Policy forbids them anyway).
- **Style guide**: a demo page (not available in production) shows every component in light and dark mode; automatic screenshots of this page compare a theme change before and after, phones included.
- **Per-installation customization**: a `[theme]` section of `competition.toml` (accent color, logo) overrides the tokens, just as translations override texts. The configuration screen edits it (step 12), with an **automatic contrast check** (WCAG AA level): an unreadable color is rejected.
- **Plain by design**: a single accent color, system font, no external font or graphics library (faster, GDPR-compliant, works offline).

---

## 21. Hosting and deployment

- **Production**: dedicated subdomain of the organizer's website (e.g. `competition.example.org`), **OVH Perso** hosting (PHP + MySQL), SSL certificate.
- **Test site**: test subdomain (e.g. `test.competition.example.org`), with **its own database**, to test each step in real conditions (real phones, 4G) without touching production.
- **Automated deployment** with **GitHub Actions**: install dependencies, run tests, then upload via **SFTP** to OVH (the Perso plan offers SFTP but no SSH access), and apply database migrations through a token-protected call. Step branches and the test branch → test site; approved release → production.
- Deployment **only uploads code**: the server's data directory, `.env` and backups are never overwritten (§ 25). SFTP credentials and server addresses are **GitHub secrets**.
- **Step-by-step installation guide** for the organizer (OVH setup, subdomains, database, PHP, GitHub secrets) and for any other organizer.
- PHP version set by the OVH configuration file (`.ovhconfig`).

---

## 22. Documentation to provide

Documentation is written **at a later stage**, once the application is stable, **in French and English**.

### 22.1 Repository files

- `README.md` (English) and `README.fr.md` (French), each linking to the other at the top: overview, screenshots on fictitious data, quick start, link to the full documentation.
- `CONTRIBUTING.md`: contribution rules, conventions, running tests.
- `CHANGELOG.md`: release history.
- Already in place (November 2026, before the full documentation): `README.md` and `README.fr.md`, `CONTRIBUTING.md`, `SECURITY.md` (private vulnerability reporting), `CODE_OF_CONDUCT.md`, `CHANGELOG.md`, issue and pull request templates, and the English developer documentation in `docs/development/` (architecture, business core, offline entry).

### 22.2 Documentation site

- Written in **Markdown** in the repository's `docs/` folder, so **versioned with the code** and reviewed through pull requests, which the GitHub wiki does not allow.
- Built with **MkDocs + Material theme** and the **mkdocs-static-i18n** plugin: built-in search, navigation, FR/EN language switcher, dark mode, phone-friendly.
- Published automatically to **GitHub Pages** (free) on every update.
- Sections:
  - **Usage**: admin guide, organizer guide, table referee guide (one printable page), participant guide;
  - **Installation and deployment**: shared hosting (OVH as an example), subdomains, database, secrets, updates;
  - **Configuration**: full reference of `competition.toml`, translations, help pages;
  - **Development**: architecture, business core, API, tests.

### 22.3 Specification

The reference specification is written in French (`CAHIER_DES_CHARGES.md`), with this **English mirror** (`SPECIFICATION.md`) for contributors. **Any change to one is applied to the other in the same commit.** Both will be **archived** (`docs/archive/`) once the application is delivered.

---

## 23. Step-by-step development plan

Each step is delivered on a branch with a pull request, deployed to the test site, **tested and approved by the project owner** before moving on.

| Step | Content | Testable outcome |
|---|---|---|
| **0. Foundation** | Repository cleanup, structure, Slim, database, **conventions and quality tooling**, **confidentiality safeguards** (§ 25.3), automatic deployment to the test site, home page | The "empty" app shows up on the test site |
| **1. Configuration and business core** | TOML loading, i18n, event model, **scoring and tie-break engine** + automated tests | Computation report on fictitious data (`/demo/report` page, not available in production) |
| **2. Accounts and security** | Login, roles, admin 2FA, forgotten password, shared account, audit log | Login with each role |
| **3. Registrations (admin)** | Theme foundations (tokens, Stylelint, style guide, § 20.6), registrations screen, manual addition, spreadsheet import, bibs | Import of a fictitious test file |
| **4. Check-in** | Check-in list PDF, check-in/check-out | Check-in on a phone |
| **5. Referee entry** | Workshop choice, standard entry, attempts, fouls, modifiers, correction, remaining bibs, help | Full online entry |
| **6. Timer and counter** | 1-2 bib timer, counter mode | Trial on a real sprint and a real basketball session |
| **7. Offline** | PWA, local queue, sync, conflicts | Airplane-mode test |
| **8. Monitoring and rankings** | Results per event, rankings, missing events, checks, phases and closing | Simulation of a full competition |
| **9. Participant and displays** | Participant page, progress screen, ceremony screen | |
| **10. Documents and fallbacks** | PDFs (podiums, backup sheets…), backup template and import, exports | |
| **11. Online registration** | Public form, email verification, waiting list, withdrawal, automatic emails | End-to-end test registration |
| **12. Online configuration** | Configuration screen, help editor, theme customization (color, logo), in-competition safeguards | |
| **13. Backups and yearly cycle** | Backups, encrypted Drive copy, restore, D+30 purge, statistics, new edition | |
| **14. Dress rehearsal** | Choice of the final theme, full-scale simulation with volunteers, fixes | Go/no-go for production |
| **15. Phase 2 — API and Claude** | Documented public API, action proposals, connecting Claude | |

The order may be adjusted.

**Deadline: complete application in production by mid-March 2027** (steps 0 to 14), the planned registration opening date. Phase 2 (step 15) and the full documentation (§ 22) come afterwards.

---

## 24. Open points

1. **Origin of the 2026 registration file** (Google form or HelloAsso): to be checked for the migration import.
2. **Default floor value**: 100 proposed, to be confirmed.
3. **Plausible ranges** for each event: to be filled in from the 2026 results.
4. **2027 competition rules**: revision needed (wrong computation example in article 7, scale with floor, fouls, DNF, tie-breaking, article 2 on registration, article 12 on GDPR).
5. **Date of the 2027 edition**: to be set. The application must be ready by mid-March 2027.
6. **Scheduled tasks** on the OVH Perso plan: to be confirmed during installation.
7. **2027 event list**: the 2026 events serve as an example and may change.

---

## 25. Confidentiality: the repository contains no real data

### 25.1 Rule

The GitHub repository is **public**. It must contain **no real data** that could identify a person or the organizer: last names, first names, dates of birth, emails, phone numbers, association name, school names, venue, production domain name, real results, registration files, backups.

Only **fictitious data** is allowed (example configuration, test data sets, demo screenshots).

### 25.2 Where real data lives

| Data | Location | Never in |
|---|---|---|
| Real configuration (name, venue, schools, contacts, help) | **Production server** data directory | Repository |
| Registrations, results, logs | **Production database** | Repository, test site |
| Secrets (database, SMTP, API, Drive, SFTP) | Server `.env`; **encrypted GitHub secrets** for deployment | Versioned files |
| Backups | Production server + **encrypted** copy on Drive | Repository |
| Imported files (spreadsheets) | Processed in memory then deleted | Repository, disk after import |

- The **test site** runs on **generated fictitious data** by default (a command generates a test set: ~100 fictitious children, realistic random results). A **Test data** page (admin, absent in production) replaces every registration and result by 100 fictitious children ready for the start of the competition: 95 with a bib including 90 present (bib handed out), 5 on the waiting list; typing "EFFACER" confirms it. A checkbox adds realistic fictitious results (about 3 events out of 4 per present child) to judge the rankings. Real data is only copied there when strictly necessary, then deleted.
- The **data directory** is **outside the public web directory** and set by an environment variable. Deployment **never touches** this directory (explicit exclusion in the deployment script).

### 25.3 Technical safeguards

1. **Strict `.gitignore`**: `.env`, data directory, `*.sqlite`, `*.xlsx`, `*.csv` (except explicitly allowed fictitious fixtures), backups, logs, uploads.
2. **Generated test data** from a fake-data generator: no test set reuses real data.
3. **Automatic scan on every push** (GitHub Actions):
   - secret detection (keys, passwords, tokens);
   - forbidden file detection (spreadsheets, databases, backups);
   - **forbidden terms** detection: a list of sensitive words (real names, association name, domain) stored **in a GitHub secret**, never in the repository. The push fails if any of them appears.
4. **Same check locally** before each commit (`pre-commit` hook), with a term list kept outside the repository.
5. **Commit messages and pull request descriptions**: same rule, no real data.
6. **Documentation screenshots**: taken on fictitious data only.

---

## 26. Code conventions

- **Language**: all code is in **English** (variable, function, class and file names, configuration keys, database tables and columns), as are **comments**, **commit messages** and technical documentation (`README.md`, `CONTRIBUTING.md`, `docs/api.md`, `docs/configuration.md`). User guides also exist in French, and the interface is translated through the i18n files.
- **PHP**: **PER Coding Style 2.0** (successor of PSR-12), `declare(strict_types=1)` everywhere, full parameter and return typing, **PSR-4** autoloading.
  - Automatic formatting and checks: **PHP-CS-Fixer** (or PHP_CodeSniffer).
  - Static analysis: **PHPStan** at the highest reasonable level.
  - Docblocks for public classes and methods.
- **JavaScript**: ES2022 modules, **ESLint** + **Prettier**.
- **CSS**: only design tokens (CSS variables from `tokens.css`) for colors, sizes and spacing, checked by **Stylelint** (§ 20.6).
- **Twig**: no business logic in templates.
- **SQL**: versioned migrations, English `snake_case` names.
- **Tests**: PHPUnit; business core (scoring, tie-breaking, attempts, sync) covers 100% of meaningful branches.
- **Continuous integration**: on every push, formatting, static analysis, tests and confidentiality checks (§ 25.3). A pull request can only be merged when everything is green.
- **Commits**: English messages following *Conventional Commits* (`feat:`, `fix:`, `docs:`…).

---

## Appendix A — Example configuration (fictitious data)

This example follows the event structure of a real edition, but **all identifying data is fictitious**. It is the intended content of `config.example/competition.toml`.

```toml
[edition]
name = "Multisport Games 2027"
organizer = "Springfield School Sports Club"
date = 2027-06-06
start = 09:00:00
end = 12:00:00
venue = "Springfield Municipal Stadium"
contact_email = "contact@example.org"
default_locale = "fr"

[participants]
levels = ["CP", "CE1", "CE2", "CM1", "CM2"]
genders = ["girl", "boy"]
schools = ["Springfield Elementary", "Shelbyville Elementary"]

[level_groups]
junior = ["CP", "CE1"]
senior = ["CE2", "CM1", "CM2"]

[registration]
opens_at = 2027-03-15T08:00:00
closes_at = 2027-05-30T23:59:00
quota = 100
first_bib = 1

[scoring]
max_score = 1000
floor = 100
foul_score = 1
dnf_score = 0
tie_breakers = ["first_places", "top3_places", "youngest"]

# ---------- Workshops ----------

[[workshops]]
id = "sprint"
help = "sprint.md"

  [[workshops.variants]]
  group = "junior"
  label = "30 m"
  measure = "time"
  time_format = "ss.cc"
  better = "lower"
  attempts = 1
  foul_mode = "strict"
  plausible_range = [4.00, 12.00]
  bibs_per_timer = 2

  [[workshops.variants]]
  group = "senior"
  label = "40 m"
  measure = "time"
  time_format = "ss.cc"
  better = "lower"
  attempts = 1
  foul_mode = "strict"
  plausible_range = [5.00, 14.00]
  bibs_per_timer = 2

[[workshops]]
id = "hurdles"
help = "hurdles.md"

  [[workshops.variants]]
  group = "junior"
  label = "30 m"
  measure = "time"
  time_format = "ss.cc"
  better = "lower"
  attempts = 1
  foul_mode = "strict"
  plausible_range = [5.00, 15.00]
  bibs_per_timer = 2

  [[workshops.variants]]
  group = "senior"
  label = "40 m"
  measure = "time"
  time_format = "ss.cc"
  better = "lower"
  attempts = 1
  foul_mode = "strict"
  plausible_range = [6.00, 18.00]
  bibs_per_timer = 2

[[workshops]]
id = "vortex_throw"
help = "vortex_throw.md"

  [[workshops.variants]]
  group = "junior"
  measure = "distance"
  better = "higher"
  attempts = 2
  foul_mode = "strict"
  plausible_range = [1.00, 50.00]

  [[workshops.variants]]
  group = "senior"
  measure = "distance"
  better = "higher"
  attempts = 2
  foul_mode = "strict"
  plausible_range = [2.00, 80.00]

[[workshops]]
id = "hockey"
help = "hockey.md"

  [[workshops.variants]]
  group = "junior"
  measure = "time"
  time_format = "ss.cc"
  better = "lower"
  attempts = 2
  foul_mode = "strict"
  plausible_range = [5.00, 60.00]
  bibs_per_timer = 1
  modifiers = [ { id = "goal_scored", value = -3.00 } ]

  [[workshops.variants]]
  group = "senior"
  measure = "time"
  time_format = "ss.cc"
  better = "lower"
  attempts = 2
  foul_mode = "strict"
  plausible_range = [5.00, 60.00]
  bibs_per_timer = 1
  modifiers = [ { id = "goal_scored", value = -3.00 } ]

[[workshops]]
id = "football"
help = "football.md"

  [[workshops.variants]]
  group = "junior"
  measure = "time"
  time_format = "m:ss.cc"
  better = "lower"
  attempts = 1
  foul_mode = "strict"
  plausible_range = [10.00, 180.00]
  bibs_per_timer = 1

[[workshops]]
id = "obstacle_course"
help = "obstacle_course.md"

  [[workshops.variants]]
  group = "junior"
  measure = "time"
  time_format = "m:ss.cc"
  better = "lower"
  attempts = 1
  foul_mode = "strict"
  plausible_range = [10.00, 180.00]
  bibs_per_timer = 1

[[workshops]]
id = "long_jump"
help = "long_jump.md"

  [[workshops.variants]]
  group = "senior"
  measure = "distance"
  better = "higher"
  attempts = 2
  foul_mode = "lenient"
  plausible_range = [0.50, 5.00]

[[workshops]]
id = "basketball"
help = "basketball.md"

  [[workshops.variants]]
  group = "senior"
  measure = "counter"
  better = "higher"
  attempts = 1
  limit = { type = "duration", value = "1:30" }
  actions = [
    { id = "basket", points = 3 },
    { id = "rim",    points = 2 },
    { id = "board",  points = 1 },
  ]
  max_value = 60

# ---------- Spreadsheet import ----------
# Maps internal fields to the column headers of the source file.

[import.columns]
last_name = "Child last name"
first_name = "Child first name"
birth_date = "Child birth date"
gender = "Gender"
school = "School"
level = "Class"
guardian_name = "Guardian full name"
guardian_phone = "Guardian phone"
guardian_email = "Contact email"
volunteer = "Volunteer"

# ---------- Contacts shown on help pages ----------

[[contacts]]
role = "data_entry"
name = "Jane Doe"
phone = "+33 6 00 00 00 01"

[[contacts]]
role = "general"
name = "John Smith"
phone = "+33 6 00 00 00 02"
```

Displayed labels (workshop, action, modifier and contact role names) are in the translation files, with keys derived from the `id`s (e.g. `workshop.hockey.name`, `modifier.goal_scored`). `plausible_range` values are indicative.

---

## Appendix B — Computation examples

**"Higher is better" event** (throw, `floor` = 100)
Best performance in the category 68.00 m, worst 8.00 m.

| Child | Result | Computation | Score |
|---|---|---|---|
| A | 68.00 m | 100 + 60/60 × 900 | 1000 |
| B | 45.00 m | 100 + 37/60 × 900 | 655 |
| C | 8.00 m | 100 + 0/60 × 900 | 100 |
| D | 2 fouls | `foul_score` | 1 |
| E | present, event not done | DNF | 0 |

**"Lower is better" event** (sprint, `floor` = 100)
Best time 6.20 s, worst 9.80 s. Child at 7.10 s:
progression = (7.10 − 9.80) / (6.20 − 9.80) = 2.70 / 3.60 = 0.75 → score = 100 + 0.75 × 900 = **775**.

**Hockey with a modifier**
Timed 18.40 s, goal scored (−3.00 s) → retained result **15.40 s**.
