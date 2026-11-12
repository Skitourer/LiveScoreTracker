/**
 * Result entry on the phone, with or without network (spec § 8.8).
 *
 * The workshop page (/entry/<workshop>) holds this app. It works from the
 * workshop's data kept on the phone (pack) and puts every result in a queue
 * that is sent to the server as soon as possible. Screens: bib (home), one
 * child (#bib/12), race (#race/12/15/timer). Corrections, history and the
 * follow-up list stay on the server pages (network needed).
 */
import { initCounter } from '../counter.js';
import { initResultInputs } from '../result-input.js';
import { initStopwatch } from '../stopwatch.js';
import {
  bestResult,
  entryWarnings,
  fill,
  formatResult,
  modifierTotal,
  newEntryId,
  parseEntry,
  usedAttempts,
} from './format.js';
import { loadPack, loadQueue, updateQueue } from './store.js';
import { createSync } from './sync.js';
import { childCard, childView, h, homeView, queueView, raceView, warningList } from './views.js';

const ERRORS = {
  unknown: 'entry.error.unknown_bib',
  otherGroup: 'entry.error.other_group',
  sameBib: 'entry.error.same_bib',
  noBib: 'entry.error.no_bib',
};

export const initEntryApp = () => {
  const root = document.querySelector('.entry-app');
  if (!root) {
    return;
  }
  const workshop = root.dataset.workshop;
  const base = root.dataset.base ?? '';
  const status = root.querySelector('.sync-status');
  const flashArea = root.querySelector('.entry-flash');
  const view = root.querySelector('.entry-view');
  const phaseNotice = root.querySelector('.entry-phase');
  const fallback = document.querySelector('.entry-fallback');

  let pack = loadPack(workshop);
  let viewAbort = null;
  let currentRoute = null;
  // A message shown just before going back to the bib screen stays there.
  let keepFlash = false;

  const tx = (key, params = {}) => fill(pack?.texts?.[key] ?? key, params);
  const format = (value, variant) => formatResult(value, variant, tx);
  const childUrl = (bib) => `${base}/entry/${workshop}/${bib}`;
  const isOpen = () => pack?.phase === 'running';

  const flash = (type, messages, keep = false) => {
    keepFlash = keep;
    flashArea.innerHTML = messages
      .map((text) => `<p class="flash ${h(type)}" role="status">${h(text)}</p>`)
      .join('');
  };

  // ---------- Data of the children, local entries included ----------

  /** Entries of this phone not yet in the downloaded data. */
  const localAttempts = (child, variant) =>
    loadQueue()
      .filter(
        (entry) =>
          entry.workshop === workshop &&
          entry.bib === child.bib &&
          entry.variant === variant.id &&
          (entry.status === 'pending' || entry.status === 'sent'),
      )
      .map((entry) => ({
        m: entry.measured,
        r:
          entry.measured === null
            ? null
            : Math.max(0, entry.measured + modifierTotal(variant, entry.modifiers)),
        at: Math.floor(entry.at / 1000),
      }));

  const attemptsOf = (child, variant) => [...child.attempts, ...localAttempts(child, variant)];

  /** The child and the event for a bib, or the blocking error key. */
  const resolve = (bib) => {
    const child = pack.children.find((candidate) => String(candidate.bib) === String(bib).trim());
    if (!child) {
      return ERRORS.unknown;
    }
    const variant = pack.variants[pack.levels[child.level] ?? ''];
    return variant ? { child, variant } : ERRORS.otherGroup;
  };

  // ---------- Queue ----------

  const enqueue = (entries) => {
    updateQueue((queue) => [...queue, ...entries]);
    renderStatus();
    sync.send();
  };

  const entryLabel = (child, variant, measured, modifiers) =>
    measured === null
      ? `${child.name} (${child.bib}) · ${variant.label} · ${tx('entry.foul')}`
      : `${child.name} (${child.bib}) · ${variant.label} · ${format(Math.max(0, measured + modifierTotal(variant, modifiers)), variant)}`;

  const recordedMessage = (child, variant, measured, modifiers, number) =>
    measured === null
      ? tx('entry.recorded_foul', { name: child.name, bib: child.bib })
      : tx('entry.recorded', {
          name: child.name,
          bib: child.bib,
          result: format(Math.max(0, measured + modifierTotal(variant, modifiers)), variant),
          number,
          of: variant.attempts,
        });

  const makeEntry = (child, variant, measured, modifiers, details, warnings) => ({
    id: newEntryId(),
    workshop,
    bib: child.bib,
    variant: variant.id,
    measured,
    modifiers: measured === null ? {} : modifiers,
    details,
    warnings,
    at: Date.now(),
    status: 'pending',
    label: entryLabel(child, variant, measured, modifiers),
  });

  // ---------- Status line ----------

  const renderStatus = (syncState = sync?.state()) => {
    if (!syncState || !pack) {
      status.innerHTML = '';
      return;
    }
    const pending = loadQueue().filter((entry) => entry.status === 'pending').length;
    const parts = [];
    let type = 'ok';
    if (syncState.loginNeeded) {
      type = 'error';
      parts.push(`<a href="${h(base)}/login">${h(tx('entry.offline.login_again'))}</a>`);
    } else if (!syncState.online) {
      type = 'warning';
      parts.push(h(tx('entry.offline.offline')));
    } else if (pending === 0) {
      parts.push(h(tx('entry.offline.online')));
    }
    if (pending > 0) {
      type = type === 'ok' ? 'warning' : type;
      parts.push(h(tx('entry.offline.pending', { count: pending })));
      parts.push(
        syncState.sending
          ? h(tx('entry.offline.sending'))
          : `<button type="button" class="button small secondary entry-send-now">${h(tx('entry.offline.send_now'))}</button>`,
      );
    }
    if (pack.fetchedAt) {
      const time = new Date(pack.fetchedAt).toLocaleTimeString(
        document.documentElement.lang || [],
        {
          hour: '2-digit',
          minute: '2-digit',
        },
      );
      parts.push(`<small class="muted">${h(tx('entry.offline.updated_at', { time }))}</small>`);
    }
    status.className = `sync-status ${type}`;
    status.innerHTML = parts.join(' · ');
  };

  // ---------- Screens ----------

  const bindCommon = () => {
    initResultInputs(view);
    for (const link of view.querySelectorAll('.entry-online-link')) {
      link.addEventListener('click', (event) => {
        if (!navigator.onLine) {
          event.preventDefault();
          flash('warning', [tx('entry.offline.needs_network')]);
        }
      });
    }
  };

  const showHome = () => {
    const entries = loadQueue().filter((entry) => entry.status !== 'sent');
    view.innerHTML =
      homeView({ pack, tx, open: isOpen(), serverChildUrl: childUrl('') }) +
      queueView({ entries, tx });
    bindCommon();

    view.querySelector('.entry-bib-form')?.addEventListener('submit', (event) => {
      event.preventDefault();
      const bib = new FormData(event.target).get('bib');
      const resolved = resolve(bib);
      if (typeof resolved === 'string') {
        flash('error', [tx(resolved, { bib })]);
        return;
      }
      location.hash = `bib/${resolved.child.bib}`;
    });

    view.querySelector('.entry-race-form')?.addEventListener('submit', (event) => {
      event.preventDefault();
      const data = new FormData(event.target);
      const mode = event.submitter?.value === 'manual' ? 'manual' : 'timer';
      const bibs = [data.get('bib1'), data.get('bib2')]
        .map((bib) => String(bib ?? '').trim())
        .filter(Boolean);
      const error = raceError(bibs);
      if (error) {
        flash('error', [error]);
        return;
      }
      location.hash = `race/${bibs[0]}/${bibs[1] ?? '-'}/${mode}`;
    });

    view.querySelector('.entry-see-form')?.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!navigator.onLine) {
        flash('warning', [tx('entry.offline.needs_network')]);
        return;
      }
      window.location.href = childUrl(new FormData(event.target).get('bib'));
    });

    for (const button of view.querySelectorAll('.entry-queue-remove')) {
      button.addEventListener('click', () => {
        if (window.confirm(tx('entry.offline.confirm_remove'))) {
          updateQueue((queue) => queue.filter((entry) => entry.id !== button.dataset.id));
          showHome();
          renderStatus();
        }
      });
    }
    view.querySelector('.bib-input')?.focus();
  };

  /** Blocking checks of a race's bibs (spec § 8.4), as a message, or null. */
  const raceError = (bibs) => {
    if (bibs.length === 0) {
      return tx(ERRORS.noBib);
    }
    const seen = new Set();
    for (const bib of bibs) {
      const resolved = resolve(bib);
      if (typeof resolved === 'string') {
        return tx(resolved, { bib });
      }
      if (resolved.variant.measure !== 'time') {
        return tx(ERRORS.otherGroup, { bib });
      }
      if (seen.has(resolved.child.bib)) {
        return tx(ERRORS.sameBib, { bib });
      }
      // Every attempt is done: a result can only be corrected, on the server pages.
      const { child, variant } = resolved;
      if (usedAttempts(variant, attemptsOf(child, variant)) >= variant.attempts) {
        return tx('entry.error.attempts_finished', { bib, of: variant.attempts });
      }
      seen.add(resolved.child.bib);
    }
    return null;
  };

  const showChild = (bib, signal) => {
    const resolved = resolve(bib);
    if (typeof resolved === 'string') {
      flash('error', [tx(resolved, { bib })], true);
      location.hash = '';
      return;
    }
    const { child, variant } = resolved;
    const attempts = attemptsOf(child, variant);
    const used = usedAttempts(variant, attempts);
    const card = childCard({
      child,
      variant,
      used,
      best: bestResult(variant, attempts),
      tx,
      format,
    });
    view.innerHTML = childView({
      child,
      variant,
      card,
      tx,
      open: isOpen(),
      finished: used >= variant.attempts,
      childUrl: childUrl(child.bib),
    });
    bindCommon();
    if (variant.measure === 'counter') {
      initCounter(view, { signal });
    }

    view.querySelector('.entry-mark-present')?.addEventListener('click', () => markPresent(child));

    const form = view.querySelector('form.entry-form');
    let confirmed = null;
    form?.addEventListener('submit', (event) => {
      event.preventDefault();
      const data = new FormData(form);
      const kind = event.submitter?.value ?? 'value';
      let measured = null;
      let details = {};
      let modifiers = {};
      if (kind === 'counter') {
        const counts = {};
        for (const action of variant.actions) {
          const count = Math.min(999, Math.max(0, Number(data.get(`act_${action.id}`)) || 0));
          if (count > 0) {
            counts[action.id] = count;
          }
        }
        const missed = Math.min(999, Math.max(0, Number(data.get('missed')) || 0));
        measured = variant.actions.reduce(
          (total, action) => total + action.points * (counts[action.id] ?? 0),
          0,
        );
        details = { source: 'counter', actions: counts, ...(missed > 0 ? { missed } : {}) };
      } else if (kind === 'value') {
        measured = parseEntry(data.get('value') ?? '', variant);
        if (measured === null) {
          messages(form, 'error', [tx('entry.error.invalid_value')]);
          return;
        }
        modifiers = readModifiers(data, variant, '');
      }
      const warnings = entryWarnings(
        child,
        variant,
        attempts,
        measured,
        Math.floor(Date.now() / 1000),
      );
      if (kind === 'counter' && variant.limit?.type === 'shots') {
        const shots =
          Object.values(details.actions).reduce((a, b) => a + b, 0) + (details.missed ?? 0);
        if (shots > variant.limit.value) {
          warnings.push('too_many_shots');
        }
      }
      const key = JSON.stringify(warnings);
      if (warnings.length > 0 && confirmed !== key) {
        confirmed = key;
        form.querySelector('.entry-messages').innerHTML =
          warningList(warnings, tx, variant) +
          `<p class="notice warning">${h(tx('entry.confirm_hint'))}</p>`;
        for (const save of form.querySelectorAll('.entry-save')) {
          save.textContent = tx('entry.save_anyway');
        }
        form.querySelector('.entry-messages').scrollIntoView({ block: 'nearest' });
        return;
      }
      enqueue([makeEntry(child, variant, measured, modifiers, details, warnings)]);
      flash(
        warnings.length ? 'warning' : 'success',
        [recordedMessage(child, variant, measured, modifiers, used + 1)],
        true,
      );
      location.hash = '';
    });
  };

  const readModifiers = (data, variant, prefix) => {
    const applied = {};
    for (const modifier of variant.modifiers) {
      const raw = data.get(`${prefix}mod_${modifier.id}`);
      const count = modifier.repeatable
        ? Math.min(99, Math.max(0, Number(raw) || 0))
        : raw === '1'
          ? 1
          : 0;
      if (count > 0) {
        applied[modifier.id] = count;
      }
    }
    return applied;
  };

  const messages = (container, type, texts) => {
    container.querySelector('.entry-messages').innerHTML = texts
      .map((text) => `<p class="flash ${h(type)}">${h(text)}</p>`)
      .join('');
  };

  const showRace = (bibs, mode, signal) => {
    const error = raceError(bibs);
    if (error) {
      flash('error', [error], true);
      location.hash = '';
      return;
    }
    const lanes = bibs.map((bib) => {
      const { child, variant } = resolve(bib);
      const attempts = attemptsOf(child, variant);
      return { child, variant, attempts, used: usedAttempts(variant, attempts) };
    });
    const differentEvents = new Set(lanes.map((lane) => lane.variant.id)).size > 1;
    view.innerHTML = raceView({ lanes, mode, tx, open: isOpen(), differentEvents, childUrl });
    bindCommon();
    if (mode === 'timer') {
      initStopwatch(view, { signal });
    }

    const form = view.querySelector('form.race-form');
    let confirmed = null;
    form?.addEventListener('submit', (event) => {
      event.preventDefault();
      const data = new FormData(form);
      const results = [];
      let blocked = false;
      for (const [index, lane] of lanes.entries()) {
        const n = index + 1;
        const box = form.querySelector(`fieldset[data-lane="${n}"] .race-messages`);
        box.innerHTML = '';
        const dnf = data.get(`${n}_dnf`) === '1';
        const measured = dnf ? null : parseEntry(data.get(`${n}_value`) ?? '', lane.variant);
        if (!dnf && measured === null) {
          box.innerHTML = `<p class="flash error">${h(tx('entry.error.invalid_value'))}</p>`;
          blocked = true;
          continue;
        }
        const timed = String(data.get(`${n}_timed`) ?? '');
        const details =
          measured !== null && /^\d+$/.test(timed)
            ? { source: Number(timed) === measured ? 'timer' : 'timer_edited' }
            : {};
        const warnings = entryWarnings(
          lane.child,
          lane.variant,
          lane.attempts,
          measured,
          Math.floor(Date.now() / 1000),
        );
        box.innerHTML = warningList(warnings, tx, lane.variant);
        results.push({
          lane,
          measured,
          details,
          warnings,
          modifiers: readModifiers(data, lane.variant, `${n}_`),
        });
      }
      if (blocked) {
        return;
      }
      // The race is validated as a whole.
      const key = JSON.stringify(results.map((result) => result.warnings));
      if (results.some((result) => result.warnings.length > 0) && confirmed !== key) {
        confirmed = key;
        messages(form, 'warning', [tx('entry.confirm_hint')]);
        form.querySelector('.entry-save').textContent = tx('entry.save_anyway');
        return;
      }
      enqueue(
        results.map(({ lane, measured, details, warnings, modifiers }) =>
          makeEntry(lane.child, lane.variant, measured, modifiers, details, warnings),
        ),
      );
      flash(
        results.some((result) => result.warnings.length > 0) ? 'warning' : 'success',
        results.map(({ lane, measured, modifiers }) =>
          recordedMessage(lane.child, lane.variant, measured, modifiers, lane.used + 1),
        ),
        true,
      );
      location.hash = '';
    });
  };

  const markPresent = async (child) => {
    if (!navigator.onLine) {
      flash('warning', [tx('entry.offline.needs_network')]);
      return;
    }
    if (!window.confirm(tx('entry.confirm_present', { name: child.name }))) {
      return;
    }
    const body = new URLSearchParams({ op: 'mark_present', _csrf: pack.csrf ?? '' });
    try {
      await fetch(childUrl(child.bib), { method: 'POST', credentials: 'same-origin', body });
    } catch {
      flash('warning', [tx('entry.offline.needs_network')]);
      return;
    }
    await sync.refreshPack();
    route(true);
  };

  // ---------- Routing ----------

  const route = (force = false) => {
    const hash = location.hash.replace(/^#/, '');
    if (!force && hash === currentRoute) {
      return;
    }
    currentRoute = hash;
    viewAbort?.abort();
    viewAbort = new AbortController();
    if (!pack) {
      view.innerHTML = `<p class="notice warning">${h(root.dataset.noPack)}</p>`;
      return;
    }
    const key = pack.phase === 'finished' ? 'entry.error.finished' : 'entry.error.not_running';
    phaseNotice.innerHTML = isOpen() ? '' : `<p class="notice">${h(tx(key))}</p>`;
    const [screen, ...args] = hash.split('/');
    if (screen === 'bib' && args[0]) {
      showChild(args[0], viewAbort.signal);
    } else if (screen === 'race' && args[0]) {
      const bibs = [args[0], args[1]].filter((bib) => bib && bib !== '-');
      showRace(bibs, args[2] === 'manual' ? 'manual' : 'timer', viewAbort.signal);
    } else {
      showHome();
    }
    window.scrollTo(0, 0);
  };

  const sync = createSync({
    base,
    workshop,
    getPack: () => pack,
    onPack: (fresh) => {
      const first = pack === null;
      pack = fresh;
      // Refresh the home screen; never interrupt a child or a race being entered.
      if (first || currentRoute === '') {
        route(true);
      }
    },
    onChange: (state) => renderStatus(state),
  });

  window.addEventListener('hashchange', () => {
    if (!keepFlash) {
      flashArea.innerHTML = '';
    }
    keepFlash = false;
    route();
  });
  status.addEventListener('click', (event) => {
    if (event.target.closest('.entry-send-now')) {
      sync.send();
    }
  });

  fallback?.remove();
  root.hidden = false;
  route(true);
  renderStatus();
  sync.refreshPack();
  sync.send();
};
