/**
 * Counter events, e.g. basketball (spec § 8.5).
 *
 * Start → countdown (time limit) or "shot 7/10" (shot limit). One big button
 * per action, plus "missed" with a shot limit; the total is shown live and
 * the last action can be undone. The run ends by itself (time up, last shot)
 * with a beep and a vibration, and the buttons lock. The counts then appear
 * in the summary fields, still editable, for validation.
 */
import { fill } from './result-input.js';

/** A short tone: the start and end signal (where the phone allows sound). */
const beep = (audio, seconds) => {
  if (!audio) {
    return;
  }
  const oscillator = audio.createOscillator();
  const gain = audio.createGain();
  oscillator.frequency.value = 880;
  gain.gain.value = 0.4;
  oscillator.connect(gain).connect(audio.destination);
  oscillator.start();
  oscillator.stop(audio.currentTime + seconds);
};

const vibrate = (pattern) => {
  if ('vibrate' in navigator) {
    navigator.vibrate(pattern);
  }
};

const minutesSeconds = (seconds) =>
  `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;

export const initCounter = (root = document, { signal } = {}) => {
  const panel = root.querySelector('div.counter');
  const summary = root.querySelector('fieldset.counter-counts');
  if (!panel || !summary) {
    return;
  }
  const data = panel.dataset;
  const inputs = [...summary.querySelectorAll('input[type="number"]')];
  const sum = summary.querySelector('.counter-sum');
  const total = (counts) =>
    inputs.reduce(
      (points, input) => points + Number(input.dataset.points) * (counts[input.name] ?? 0),
      0,
    );
  const pointsText = (points) => fill(data.labelPoints, { value: points });

  // Summary fields typed by hand: keep the total up to date.
  const fromInputs = () => {
    const counts = {};
    for (const input of inputs) {
      counts[input.name] = Math.max(0, Number(input.value) || 0);
    }
    return counts;
  };
  for (const input of inputs) {
    input.addEventListener('input', () => {
      sum.textContent = pointsText(total(fromInputs()));
    });
  }

  // Checking or correcting: only the summary, no new run.
  if (data.review === '1') {
    return;
  }

  const clock = panel.querySelector('.counter-clock');
  const liveTotal = panel.querySelector('.counter-total');
  const startButton = panel.querySelector('.counter-start');
  const actions = [...panel.querySelectorAll('.counter-action')];
  const undo = panel.querySelector('.counter-undo');
  const stop = panel.querySelector('.counter-stop');
  const byShots = data.limitType === 'shots';
  const limit = Number(data.limitValue);
  const nameOf = (action) => (action === 'missed' ? 'missed' : `act_${action}`);

  let state = 'idle';
  let history = [];
  let endAt = 0;
  let frame = 0;
  let audio = null;

  const counts = () => {
    const result = {};
    for (const action of history) {
      result[nameOf(action)] = (result[nameOf(action)] ?? 0) + 1;
    }
    return result;
  };

  const showClock = () => {
    if (byShots) {
      const shot = state === 'ended' ? history.length : Math.min(history.length + 1, limit);
      clock.textContent = fill(data.labelShot, { number: shot, of: limit });
    } else if (state === 'idle') {
      clock.textContent = minutesSeconds(limit);
    }
  };

  const render = () => {
    const current = counts();
    for (const button of actions) {
      button.disabled = state !== 'running';
      button.querySelector('.counter-count').textContent =
        current[nameOf(button.dataset.action)] ?? 0;
    }
    undo.disabled = state !== 'running' || history.length === 0;
    stop.disabled = state !== 'running';
    startButton.hidden = state !== 'idle';
    liveTotal.textContent = pointsText(total(current));
    for (const input of inputs) {
      input.value = current[input.name] ?? 0;
    }
    sum.textContent = pointsText(total(current));
    summary.hidden = state !== 'ended';
    showClock();
  };

  const end = () => {
    cancelAnimationFrame(frame);
    state = 'ended';
    beep(audio, 0.8);
    vibrate([400, 150, 400]);
    render();
  };

  const countdown = () => {
    const left = Math.max(0, Math.ceil((endAt - performance.now()) / 1000));
    clock.textContent = minutesSeconds(left);
    if (left === 0) {
      end();
      return;
    }
    frame = requestAnimationFrame(countdown);
  };

  startButton.addEventListener('click', () => {
    const Context = window.AudioContext ?? window.webkitAudioContext;
    audio = Context ? new Context() : null;
    beep(audio, 0.25);
    vibrate(200);
    state = 'running';
    if (!byShots) {
      endAt = performance.now() + limit * 1000;
      countdown();
    }
    render();
  });

  for (const button of actions) {
    button.addEventListener('click', () => {
      if (state !== 'running') {
        return;
      }
      history.push(button.dataset.action);
      vibrate(30);
      if (byShots && history.length >= limit) {
        end();
        return;
      }
      render();
    });
  }

  undo.addEventListener('click', () => {
    if (state === 'running') {
      history = history.slice(0, -1);
      render();
    }
  });
  stop.addEventListener('click', () => {
    if (state === 'running') {
      end();
    }
  });

  panel.closest('form')?.addEventListener('submit', () => {
    state = 'submitted';
  });
  signal?.addEventListener('abort', () => cancelAnimationFrame(frame));
  window.addEventListener(
    'beforeunload',
    (event) => {
      if (state === 'running' || (state === 'ended' && history.length > 0)) {
        event.preventDefault();
      }
    },
    { signal },
  );

  panel.hidden = false;
  render();
};
