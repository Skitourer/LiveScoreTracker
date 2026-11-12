/**
 * Without network, no link leads to a browser error (spec § 8.8):
 * - the workshop entry page, its follow-up and its help work offline (the
 *   follow-up and the help open inside the entry page, from the phone's data);
 * - any other link or form says that it needs the network and stays put;
 * - links that need the network are greyed while offline (html.is-offline).
 *
 * The service worker shows the "offline" page for anything else (a reload,
 * an address typed by hand).
 */
import { listPacks } from './entry/store.js';
import { h } from './entry/views.js';

const base = () => document.documentElement.dataset.base ?? '';

const localPath = (url) =>
  url.pathname.startsWith(base()) ? url.pathname.slice(base().length) : url.pathname;

const WORKSHOP = /^\/entry\/([a-z0-9_]+)$/;
const INSIDE = /^\/entry\/([a-z0-9_]+)\/(tracking|help)$/;

/**
 * Where a link can go without network: the address itself, the entry page
 * with the screen to show, or null when it needs the network.
 */
export const offlineTarget = (url) => {
  const path = localPath(url);
  if (WORKSHOP.test(path)) {
    return url.href;
  }
  const inside = path.match(INSIDE);
  return inside ? `${base()}/entry/${inside[1]}#${inside[2]}` : null;
};

const notice = () => {
  const main = document.querySelector('main');
  if (!main) {
    return;
  }
  let box = main.querySelector('.offline-notice');
  if (!box) {
    box = document.createElement('p');
    box.className = 'flash warning offline-notice';
    box.setAttribute('role', 'status');
    main.prepend(box);
  }
  box.textContent = document.documentElement.dataset.offlineText ?? '';
  box.scrollIntoView({ block: 'nearest' });
};

const initOfflinePage = () => {
  document.querySelector('.offline-back')?.addEventListener('click', () => window.history.back());
  const section = document.querySelector('.offline-workshops');
  const workshops = listPacks();
  if (section && workshops.length > 0) {
    section.querySelector('.toolbar').innerHTML = workshops
      .map(
        (workshop) =>
          `<a class="button secondary" href="${h(base())}/entry/${h(workshop.id)}">${h(workshop.name)}</a>`,
      )
      .join('');
    section.hidden = false;
  }
};

export const initOffline = () => {
  const root = document.documentElement;
  const update = () => root.classList.toggle('is-offline', !navigator.onLine);
  update();
  window.addEventListener('online', () => {
    update();
    document.querySelector('.offline-notice')?.remove();
  });
  window.addEventListener('offline', update);

  // Links: after the page's own handlers (the entry screens deal with theirs).
  document.addEventListener('click', (event) => {
    if (navigator.onLine || event.defaultPrevented) {
      return;
    }
    const link = event.target.closest('a[href]');
    if (!link || (link.getAttribute('href') ?? '').startsWith('#')) {
      return;
    }
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin) {
      return; // phone numbers, other sites
    }
    if (url.pathname === window.location.pathname && url.hash) {
      return;
    }
    const target = offlineTarget(url);
    if (target !== url.href) {
      event.preventDefault();
      if (target === null) {
        notice();
      } else {
        window.location.href = target;
      }
    }
  });

  // Forms sent to the server (the entry screens' forms stay on the phone).
  document.addEventListener(
    'submit',
    (event) => {
      if (navigator.onLine || event.target.closest('.entry-app')) {
        return;
      }
      event.preventDefault();
      event.stopImmediatePropagation();
      notice();
    },
    true,
  );

  initOfflinePage();
};
