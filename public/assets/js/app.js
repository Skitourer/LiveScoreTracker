/**
 * Client entry point. Interactive features (offline entry, timer, counter)
 * are added in later steps.
 */
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

/**
 * Result entry (spec § 7.3): the value typed as digits is shown large as
 * understood ("1225" → "12,25 s") before it is saved. Same rules as the
 * server's EntryParser.
 */
const resultInput = document.querySelector('input.result-input');
const interpreted = document.querySelector('output.interpreted');

/** "{value} s" with the value filled in. */
const fill = (template, values) =>
  template.replace(/\{(\w+)\}/g, (match, key) => values[key] ?? match);

const hundredths = (value, decimal) =>
  `${Math.floor(value / 100)}${decimal}${String(value % 100).padStart(2, '0')}`;

const interpret = (input) => {
  const digits = input.value.trim();
  const data = input.dataset;
  if (digits === '') {
    return '';
  }
  if (!/^\d{1,7}$/.test(digits)) {
    return data.invalid;
  }
  const number = Number(digits);
  if (data.measure === 'distance') {
    return fill(data.unitMetres, { value: hundredths(number, data.decimal) });
  }
  if (data.measure !== 'time') {
    return fill(data.unitPoints, { value: number });
  }
  if (data.timeFormat !== 'm:ss.cc') {
    return fill(data.unitSeconds, { value: hundredths(number, data.decimal) });
  }
  const seconds = Math.floor(number / 100) % 100;
  const minutes = Math.floor(number / 10000);
  if (seconds >= 60) {
    return data.invalid;
  }
  if (minutes === 0) {
    return fill(data.unitSeconds, { value: hundredths(number, data.decimal) });
  }
  return fill(data.unitMinutes, {
    minutes,
    seconds: hundredths(number % 10000, data.decimal).padStart(5, '0'),
  });
};

if (resultInput instanceof HTMLInputElement && interpreted) {
  resultInput.addEventListener('input', () => {
    interpreted.textContent = interpret(resultInput);
  });
}

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
