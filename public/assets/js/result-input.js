/**
 * Result fields (spec § 7.3): the value typed as digits is shown large as
 * understood ("1225" → "12,25 s") before it is saved. Same rules as the
 * server's EntryParser.
 */

/** "{value} s" with the value filled in. */
export const fill = (template, values) =>
  template.replace(/\{(\w+)\}/g, (match, key) => values[key] ?? match);

/** 1225 → "12,25" */
export const hundredths = (value, decimal) =>
  `${Math.floor(value / 100)}${decimal}${String(value % 100).padStart(2, '0')}`;

/**
 * The digits a referee would type for a time in hundredths (reverse of the
 * parser): 1225 → "1225"; in m:ss.cc format, 8324 → "12324".
 */
export const toDigits = (value, timeFormat) => {
  if (timeFormat !== 'm:ss.cc' || value < 6000) {
    return String(value);
  }
  return `${Math.floor(value / 6000)}${String(value % 6000).padStart(4, '0')}`;
};

/** A time in hundredths as a stopwatch shows it: "12,34" or "1:02,34". */
export const clockText = (value, timeFormat, decimal) => {
  if (timeFormat !== 'm:ss.cc' || value < 6000) {
    return hundredths(value, decimal);
  }
  return `${Math.floor(value / 6000)}:${hundredths(value % 6000, decimal).padStart(5, '0')}`;
};

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

/** The output showing what a field is understood as. */
const outputOf = (input) => {
  if (input.dataset.output) {
    return document.getElementById(input.dataset.output);
  }
  const next = input.closest('label')?.nextElementSibling;
  return next instanceof HTMLOutputElement ? next : null;
};

/** Updates the output of a field (also after a script filled it in). */
export const refreshInterpreted = (input) => {
  const output = outputOf(input);
  if (output) {
    output.textContent = interpret(input);
  }
};

export const initResultInputs = (root = document) => {
  for (const input of root.querySelectorAll('input.result-input')) {
    input.addEventListener('input', () => refreshInterpreted(input));
  }
};
