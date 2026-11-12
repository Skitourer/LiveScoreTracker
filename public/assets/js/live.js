/**
 * Pages that follow the competition (control board, results, missing events,
 * follow-up, dashboard) refresh themselves: every 30 seconds the page is
 * fetched again and the part marked data-live is replaced when it changed.
 *
 * Nothing moves while someone is acting on the page: a field being typed in,
 * a list open, a box ticked, or a tap in the last few seconds. Open sections
 * (<details id=...>) and the scroll position are kept.
 */
const EVERY = 30_000; // data-live="10" sets another period, in seconds
const QUIET_AFTER_TAP = 5_000;

export const initLiveRefresh = ({ onRefresh = () => {} } = {}) => {
  const region = document.querySelector('[data-live]');
  if (!region) {
    return;
  }
  let lastTap = 0;
  document.addEventListener('pointerdown', () => {
    lastTap = Date.now();
  });

  const stamp = document.createElement('p');
  stamp.className = 'meta live-stamp';
  stamp.setAttribute('role', 'status');
  region.after(stamp);
  const text = document.documentElement.dataset.liveText ?? '{time}';
  const showTime = () => {
    const time = new Date().toLocaleTimeString(document.documentElement.lang || [], {
      hour: '2-digit',
      minute: '2-digit',
    });
    stamp.textContent = text.replace('{time}', time);
  };
  showTime();

  const busy = () => {
    const active = document.activeElement;
    if (active && active !== document.body && active.matches('input, select, textarea')) {
      return true;
    }
    if (region.querySelector('input[type="checkbox"]:checked')) {
      return true;
    }
    return Date.now() - lastTap < QUIET_AFTER_TAP;
  };

  const refresh = async () => {
    if (document.visibilityState !== 'visible' || !navigator.onLine || busy()) {
      return;
    }
    let html;
    try {
      const response = await fetch(window.location.href, {
        credentials: 'same-origin',
        headers: { 'X-Live-Refresh': '1' },
      });
      // Logged out or an error: leave the page as it is.
      if (!response.ok || response.redirected) {
        return;
      }
      html = await response.text();
    } catch {
      return;
    }
    const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-live]');
    if (!fresh || busy()) {
      return;
    }
    if (fresh.innerHTML !== region.innerHTML) {
      const open = [...region.querySelectorAll('details[open][id]')].map((details) => details.id);
      const scroll = window.scrollY;
      region.innerHTML = fresh.innerHTML;
      for (const id of open) {
        region.querySelector(`details#${CSS.escape(id)}`)?.setAttribute('open', '');
      }
      window.scrollTo(0, scroll);
      onRefresh(region);
    }
    showTime();
  };

  const seconds = Number.parseInt(region.dataset.live ?? '', 10);
  window.setInterval(refresh, seconds > 0 ? seconds * 1000 : EVERY);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      refresh();
    }
  });
};
