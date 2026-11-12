/**
 * What the phone keeps (spec § 8.8), in the browser's local storage:
 * - the data of each workshop opened ("pack"), refreshed regularly;
 * - the queue of entries not yet confirmed by the server, kept even after
 *   a logout or a restart of the phone.
 */
const PACK_PREFIX = 'lst.pack.';
const QUEUE = 'lst.queue';

const read = (key, fallback) => {
  try {
    const raw = window.localStorage.getItem(key);
    return raw === null ? fallback : JSON.parse(raw);
  } catch {
    return fallback;
  }
};

const write = (key, value) => {
  try {
    window.localStorage.setItem(key, JSON.stringify(value));
    return true;
  } catch {
    return false;
  }
};

export const loadPack = (workshop) => read(PACK_PREFIX + workshop, null);
export const savePack = (workshop, pack) => write(PACK_PREFIX + workshop, pack);

/** Workshops whose data is on the phone: [{id, name}]. */
export const listPacks = () => {
  try {
    return Object.keys(window.localStorage)
      .filter((key) => key.startsWith(PACK_PREFIX))
      .map((key) => read(key, null)?.workshop)
      .filter((workshop) => workshop?.id)
      .map((workshop) => ({ id: workshop.id, name: workshop.name ?? workshop.id }));
  } catch {
    return [];
  }
};

/** Logout: the children's data leaves the phone; the queue stays so nothing is lost. */
export const forgetPacks = () => {
  try {
    for (const key of Object.keys(window.localStorage)) {
      if (key.startsWith(PACK_PREFIX)) {
        window.localStorage.removeItem(key);
      }
    }
  } catch {
    // Storage unavailable: nothing kept.
  }
};

/** @returns {Array<object>} entries: {id, workshop, bib, variant, measured, modifiers, details, warnings, at, status, ...} */
export const loadQueue = () => {
  const queue = read(QUEUE, []);
  return Array.isArray(queue) ? queue : [];
};
export const saveQueue = (queue) => write(QUEUE, queue);

/** Changes the queue in one step (re-read first: another tab may have changed it). */
export const updateQueue = (change) => {
  const queue = change(loadQueue());
  saveQueue(queue);
  return queue;
};
