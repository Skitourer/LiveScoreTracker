/**
 * Client entry point. Interactive features: result fields, the built-in
 * timer (spec § 8.4) and the counter (spec § 8.5). Offline entry comes in a
 * later step.
 */
import { initCounter } from './counter.js';
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
