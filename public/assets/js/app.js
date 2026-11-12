/**
 * Client entry point. Interactive features: result fields, the built-in
 * timer (spec § 8.4), the counter (spec § 8.5) and the entry screens that
 * work offline (spec § 8.8).
 */
import { initCounter } from './counter.js';
import { initEntryApp } from './entry/app.js';
import { forgetPacks } from './entry/store.js';
import { initLiveRefresh } from './live.js';
import { initOffline } from './offline.js';
import { initResultInputs } from './result-input.js';
import { initStopwatch } from './stopwatch.js';
import { initTrackingRace } from './tracking.js';

document.documentElement.classList.add('js');

// Forms with data-confirm="message" ask before submitting (inline scripts are forbidden by the CSP).
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (
    form instanceof HTMLFormElement &&
    form.dataset.confirm &&
    !window.confirm(form.dataset.confirm)
  ) {
    event.preventDefault();
  }
});

initResultInputs();
initStopwatch();
initCounter();
initTrackingRace();
initEntryApp();

// Ceremony screen: full screen on the projector.
document.querySelector('.fullscreen-toggle')?.addEventListener('click', () => {
  document.documentElement.requestFullscreen?.().catch(() => {});
});
initLiveRefresh({ onRefresh: () => initTrackingRace() });
initOffline();

/**
 * Installable application, entry screens available offline (spec § 8.8).
 * Each deployment gets its own cache (sw.js?v=<revision>).
 */
if ('serviceWorker' in navigator) {
  const { base = '', revision = 'dev' } = document.documentElement.dataset;
  navigator.serviceWorker
    .register(`${base}/sw.js?v=${encodeURIComponent(revision)}`, { scope: `${base}/` })
    .catch(() => {});
}

// Logout: the children's data leaves the phone (queued results stay until sent).
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (form instanceof HTMLFormElement && /\/logout$/.test(form.getAttribute('action') ?? '')) {
    forgetPacks();
    navigator.serviceWorker?.controller?.postMessage('logout');
  }
});

/**
 * Keep the screen on while entering results (spec § 8.1), where the browser allows it.
 */
if (document.querySelector('.entry-nav') && 'wakeLock' in navigator) {
  const keepAwake = () => navigator.wakeLock.request('screen').catch(() => {});
  keepAwake();
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      keepAwake();
    }
  });
}
