/**
 * Exchanges with the server (spec § 8.8): refreshes the workshop's data and
 * sends the queued entries as soon as the network allows. Every entry has an
 * id made on the phone, so sending it again never creates a duplicate.
 */
import { loadQueue, savePack, updateQueue } from './store.js';

const SEND_EVERY = 20_000;
const REFRESH_EVERY = 120_000;

const isJson = (response) =>
  (response.headers.get('Content-Type') ?? '').includes('application/json');

export const createSync = ({ base, workshop, getPack, onPack, onChange }) => {
  const state = { sending: false, loginNeeded: false, online: navigator.onLine, failed: false };
  const changed = () => onChange({ ...state });

  const refreshPack = async () => {
    const started = Date.now();
    try {
      const response = await fetch(`${base}/entry/${workshop}/pack`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!isJson(response) || !response.ok) {
        // Redirected to the login page: the session has expired.
        state.loginNeeded =
          response.redirected || response.status === 401 || response.status === 403;
        changed();
        return null;
      }
      const pack = await response.json();
      pack.fetchedAt = started;
      state.loginNeeded = false;
      state.online = true;
      savePack(workshop, pack);
      // Entries confirmed before this download are now in the data: drop them.
      updateQueue((queue) =>
        queue.filter((entry) => !(entry.status === 'sent' && entry.sentAt < started)),
      );
      onPack(pack);
      changed();
      return pack;
    } catch {
      state.online = false;
      changed();
      return null;
    }
  };

  const post = (workshopId, entries) =>
    fetch(`${base}/entry/${workshopId}/sync`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': getPack()?.csrf ?? '',
      },
      body: JSON.stringify({ entries }),
    });

  const send = async () => {
    const pending = loadQueue().filter((entry) => entry.status === 'pending');
    if (state.sending || pending.length === 0) {
      return;
    }
    state.sending = true;
    changed();
    let sent = false;
    try {
      const byWorkshop = new Map();
      for (const entry of pending) {
        byWorkshop.set(entry.workshop, [...(byWorkshop.get(entry.workshop) ?? []), entry]);
      }
      for (const [workshopId, entries] of byWorkshop) {
        const payload = entries.map(
          ({ id, bib, variant, measured, modifiers, details, warnings, at }) => ({
            id,
            bib,
            variant,
            measured,
            modifiers,
            details,
            warnings,
            at,
          }),
        );
        let response = await post(workshopId, payload);
        if (response.status === 400 && !isJson(response)) {
          // Security token renewed by a new login: fetch it and try once more.
          await refreshPack();
          response = await post(workshopId, payload);
        }
        if (!isJson(response) || !response.ok) {
          state.loginNeeded =
            response.redirected || response.status === 401 || response.status === 403;
          continue;
        }
        const { results } = await response.json();
        const byId = new Map(results.map((result) => [result.id, result]));
        const now = Date.now();
        updateQueue((queue) =>
          queue.map((entry) => {
            const result = byId.get(entry.id);
            if (!result) {
              return entry;
            }
            // "late": received after the closing, kept on the server for the admin to decide.
            if (result.status === 'saved' || result.status === 'late') {
              return { ...entry, status: 'sent', sentAt: now };
            }
            return { ...entry, status: result.status, reason: result.reason ?? '' };
          }),
        );
        sent = true;
        state.loginNeeded = false;
      }
      state.online = true;
      state.failed = false;
    } catch {
      state.online = false;
      state.failed = true;
    } finally {
      state.sending = false;
      changed();
    }
    if (sent) {
      await refreshPack();
    }
  };

  window.addEventListener('online', () => {
    state.online = true;
    send();
    refreshPack();
  });
  window.addEventListener('offline', () => {
    state.online = false;
    changed();
  });
  window.setInterval(send, SEND_EVERY);
  window.setInterval(refreshPack, REFRESH_EVERY);

  return { send, refreshPack, state: () => ({ ...state }) };
};
