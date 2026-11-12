/**
 * Values on the phone, with the same rules as the server (EntryParser,
 * ResultFormatter, EntryChecks), so that entering offline gives the same
 * results and warnings as online.
 */
import { fill, hundredths } from '../result-input.js';

/** Digits typed by the referee → value in storage units, or null if unreadable. */
export const parseEntry = (input, variant) => {
  const digits = String(input).trim();
  if (!/^\d{1,7}$/.test(digits)) {
    return null;
  }
  const number = Number(digits);
  if (variant.measure !== 'time' || variant.timeFormat !== 'm:ss.cc') {
    return number;
  }
  const seconds = Math.floor(number / 100) % 100;
  if (seconds >= 60) {
    return null;
  }
  return (Math.floor(number / 10000) * 60 + seconds) * 100 + (number % 100);
};

/** A result as shown everywhere: "12,25 s", "1 min 02,34 s", "3,45 m", "14 pts". */
export const formatResult = (value, variant, tx) => {
  const decimal = tx('format.decimal_separator');
  const signed = (v) => (v < 0 ? '-' : '') + hundredths(Math.abs(v), decimal);
  if (variant.measure === 'time') {
    if (variant.timeFormat === 'm:ss.cc' && value >= 6000) {
      return tx('unit.minutes_seconds', {
        minutes: Math.floor(value / 6000),
        seconds: hundredths(value % 6000, decimal).padStart(5, '0'),
      });
    }
    return tx('unit.seconds', { value: signed(value) });
  }
  if (variant.measure === 'distance') {
    return tx('unit.metres', { value: signed(value) });
  }
  return tx('unit.points', { value });
};

/** Attempts used: a foul counts only in strict mode. */
export const usedAttempts = (variant, attempts) =>
  attempts.filter((attempt) => attempt.m !== null || variant.foulMode === 'strict').length;

export const bestResult = (variant, attempts) => {
  let best = null;
  for (const attempt of attempts) {
    const result = attempt.r;
    if (
      result !== null &&
      (best === null || (variant.better === 'lower' ? result < best : result > best))
    ) {
      best = result;
    }
  }
  return best;
};

export const modifierTotal = (variant, modifiers) =>
  variant.modifiers.reduce(
    (total, modifier) => total + modifier.value * (modifiers[modifier.id] ?? 0),
    0,
  );

/** Non-blocking checks (spec § 10.1); the server makes them again. */
export const DUPLICATE_SECONDS = 120;
export const entryWarnings = (child, variant, attempts, measured, now) => {
  const warnings = [];
  if (!child.present) {
    warnings.push('not_present');
  }
  if (usedAttempts(variant, attempts) >= variant.attempts) {
    warnings.push('attempts_used');
  }
  if (measured !== null) {
    const range = variant.plausible;
    if (range && (measured < range[0] || measured > range[1])) {
      warnings.push('implausible');
    }
    if (variant.maxValue !== null && measured > variant.maxValue) {
      warnings.push('above_max');
    }
    const recent = attempts.some(
      (attempt) =>
        attempt.m === measured && now - attempt.at >= 0 && now - attempt.at <= DUPLICATE_SECONDS,
    );
    if (recent) {
      warnings.push('duplicate');
    }
  }
  return warnings;
};

/** A new entry id, made on the phone: sending it twice records it once. */
export const newEntryId = () => {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
};

export { fill };
