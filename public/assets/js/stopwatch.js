/**
 * Built-in timer for a race of 1 or 2 children (spec § 8.4).
 *
 * Start (at the signal) → the button becomes Finish; one tap on Finish each
 * time a child crosses the line. "Did not finish" per child; "Cancel the
 * race" (false start) clears everything. When every child has finished (or
 * not), the times are put in the race form for checking: the first time goes
 * to the first bib typed, and "Swap" exchanges them. Times stay editable.
 *
 * The time runs in the phone (no network needed). Taps are timed with the
 * event's own timestamp, so a busy screen does not delay them.
 */
import { clockText, refreshInterpreted, toDigits } from './result-input.js';

export const initStopwatch = (root = document, { signal } = {}) => {
  const panel = root.querySelector('section.stopwatch');
  const form = root.querySelector('form.race-form');
  if (!panel || !form) {
    return;
  }
  const data = panel.dataset;
  const clock = panel.querySelector('.stopwatch-clock');
  const go = panel.querySelector('.stopwatch-go');
  const cancel = panel.querySelector('.stopwatch-cancel');
  const dnfButtons = [...panel.querySelectorAll('.stopwatch-dnf button')];
  const lanes = [...form.querySelectorAll('fieldset.race-result')];
  const swap = form.querySelector('.race-swap');
  const abandon = form.querySelector('.race-abandon');
  const timeFormat = lanes[0]?.dataset.timeFormat ?? 'ss.cc';

  const field = (lane, name) => lane.querySelector(`[name="${lane.dataset.lane}_${name}"]`);
  const valueOf = (lane) => field(lane, 'value');
  const dnfOf = (lane) => field(lane, 'dnf');
  const timedOf = (lane) => field(lane, 'timed');
  const placeOf = (lane) => lane.querySelector('.race-place');

  let state = data.review === '1' ? 'review' : 'idle';
  let start = 0;
  let arrivals = [];
  let finished = new Set();
  let frame = 0;
  let submitting = false;

  const show = () => {
    panel.hidden = state === 'review';
    form.hidden = state !== 'review';
    if (swap) {
      swap.hidden = state !== 'review';
    }
  };

  const tick = () => {
    clock.textContent = clockText(
      Math.floor((performance.now() - start) / 10),
      timeFormat,
      data.decimal,
    );
    frame = requestAnimationFrame(tick);
  };

  const setLane = (lane, time, place) => {
    valueOf(lane).value = time === null ? '' : toDigits(time, timeFormat);
    timedOf(lane).value = time === null ? '' : String(time);
    dnfOf(lane).checked = time === null;
    const label = placeOf(lane);
    label.textContent = place;
    label.hidden = place === '';
    refreshInterpreted(valueOf(lane));
  };

  /** Every child has finished or not: the times go to the check screen. */
  const review = () => {
    cancelAnimationFrame(frame);
    state = 'review';
    let next = 0;
    for (const lane of lanes) {
      if (finished.has(lane.dataset.lane)) {
        setLane(lane, null, '');
      } else {
        const time = Math.floor(arrivals[next] / 10);
        setLane(lane, time, next === 0 ? data.placeFirst : data.placeOther);
        next += 1;
      }
    }
    if (arrivals.length > 0) {
      clock.textContent = clockText(
        Math.floor(arrivals[arrivals.length - 1] / 10),
        timeFormat,
        data.decimal,
      );
    }
    show();
  };

  const checkDone = () => {
    if (arrivals.length >= lanes.length - finished.size) {
      review();
    }
  };

  const reset = () => {
    cancelAnimationFrame(frame);
    state = 'idle';
    arrivals = [];
    finished = new Set();
    clock.textContent = clockText(0, timeFormat, data.decimal);
    go.textContent = data.labelStart;
    go.disabled = false;
    for (const button of dnfButtons) {
      button.disabled = true;
      button.setAttribute('aria-pressed', 'false');
    }
    for (const lane of lanes) {
      setLane(lane, null, '');
      dnfOf(lane).checked = false;
    }
    show();
  };

  const press = (at) => {
    if (state === 'idle') {
      state = 'running';
      start = at;
      go.textContent = data.labelFinish;
      for (const button of dnfButtons) {
        button.disabled = false;
      }
      tick();
    } else if (state === 'running') {
      arrivals.push(at - start);
      checkDone();
    }
  };

  // The finish line is timed on touch-down, the closest to the real moment.
  go.addEventListener('pointerdown', (event) => {
    event.preventDefault();
    press(event.timeStamp);
  });
  // Keyboard use (Enter, Space): no pointer event, detail is 0.
  go.addEventListener('click', (event) => {
    if (event.detail === 0) {
      press(performance.now());
    }
  });

  for (const button of dnfButtons) {
    button.addEventListener('click', () => {
      if (state !== 'running') {
        return;
      }
      const lane = button.dataset.lane;
      if (finished.has(lane)) {
        finished.delete(lane);
      } else {
        finished.add(lane);
      }
      button.setAttribute('aria-pressed', String(finished.has(lane)));
      checkDone();
    });
  }

  const cancelRace = (event) => {
    if (state === 'idle') {
      return;
    }
    event.preventDefault();
    if (window.confirm(cancel.dataset.confirmText)) {
      reset();
    }
  };
  cancel.addEventListener('click', (event) => {
    if (state === 'idle') {
      window.location.href = abandon.href;
      return;
    }
    cancelRace(event);
  });
  abandon.addEventListener('click', cancelRace);

  swap?.addEventListener('click', () => {
    const [first, second] = lanes;
    if (dnfOf(first).checked || dnfOf(second).checked) {
      return;
    }
    const times = [first, second].map((lane) => [valueOf(lane).value, timedOf(lane).value]);
    const places = [placeOf(first).textContent, placeOf(second).textContent];
    for (const [index, lane] of [second, first].entries()) {
      valueOf(lane).value = times[index][0];
      timedOf(lane).value = times[index][1];
      placeOf(lane).textContent = places[index];
      refreshInterpreted(valueOf(lane));
    }
  });

  form.addEventListener('submit', () => {
    submitting = true;
  });
  signal?.addEventListener('abort', () => cancelAnimationFrame(frame));
  window.addEventListener(
    'beforeunload',
    (event) => {
      if (!submitting && (state === 'running' || (state === 'review' && arrivals.length > 0))) {
        event.preventDefault();
      }
    },
    { signal },
  );

  show();
};
