/**
 * Markup of the entry screens built on the phone. It mirrors the server
 * templates (templates/entry/*.twig) so that styles, the timer and the
 * counter work the same way.
 */

/** Escapes text put into markup. */
export const h = (value) =>
  String(value ?? '').replace(
    /[&<>"']/g,
    (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char],
  );

const hintKey = (variant) => {
  if (variant.measure === 'time') {
    return variant.timeFormat === 'm:ss.cc' ? 'entry.hint.time_msscc' : 'entry.hint.time_sscc';
  }
  return `entry.hint.${variant.measure}`;
};

/** Data attributes of a result field (read by result-input.js). */
const resultData = (variant, tx, output) =>
  [
    `data-output="${h(output)}"`,
    `data-measure="${h(variant.measure)}"`,
    `data-time-format="${h(variant.timeFormat ?? '')}"`,
    `data-unit-seconds="${h(tx('unit.seconds'))}"`,
    `data-unit-minutes="${h(tx('unit.minutes_seconds'))}"`,
    `data-unit-metres="${h(tx('unit.metres'))}"`,
    `data-unit-points="${h(tx('unit.points'))}"`,
    `data-decimal="${h(tx('format.decimal_separator'))}"`,
    `data-invalid="${h(tx('entry.error.invalid_value'))}"`,
  ].join(' ');

const modifierFields = (variant, prefix) =>
  variant.modifiers
    .map((modifier) =>
      modifier.repeatable
        ? `<label>${h(modifier.label)}
             <input type="number" name="${prefix}mod_${h(modifier.id)}" min="0" max="99" inputmode="numeric" value="0"></label>`
        : `<label class="choice"><input type="checkbox" name="${prefix}mod_${h(modifier.id)}" value="1"> ${h(modifier.label)}</label>`,
    )
    .join('');

export const warningList = (warnings, tx, variant) =>
  warnings.length === 0
    ? ''
    : `<ul class="notice warning">${warnings
        .map((code) => `<li>${h(tx(`entry.warning.${code}`, { of: variant.attempts }))}</li>`)
        .join('')}</ul>`;

/** Home: the bib field, or the race fields on a time workshop. */
export const homeView = ({ pack, tx, open, serverChildUrl }) => {
  const workshop = pack.workshop;
  const closed = open ? '' : 'disabled';
  if (!workshop.timed) {
    return `
      <form class="check-in-search entry-bib-form">
        <label>${h(tx('entry.bib'))}
          <input type="text" name="bib" class="bib-input" inputmode="numeric" pattern="[0-9]*" autocomplete="off" required autofocus>
        </label>
        <button type="submit" class="button" ${closed}>${h(tx('entry.open'))}</button>
      </form>`;
  }
  const bibs = [];
  for (let slot = 1; slot <= workshop.bibsPerRace; slot += 1) {
    const label =
      workshop.bibsPerRace > 1 ? tx('entry.race_bib', { number: slot }) : tx('entry.bib');
    bibs.push(`<label>${h(label)}${slot > 1 ? ` <small>${h(tx('entry.optional'))}</small>` : ''}
        <input type="text" name="bib${slot}" class="bib-input" inputmode="numeric" pattern="[0-9]*" autocomplete="off" ${slot === 1 ? 'required autofocus' : ''}>
      </label>`);
  }
  return `
    <form class="race-start entry-race-form">
      <div class="race-bibs">${bibs.join('')}</div>
      <button type="submit" name="mode" value="timer" class="button large" ${closed}>${h(tx('entry.mode_timer'))}</button>
      <button type="submit" name="mode" value="manual" class="button large secondary" ${closed}>${h(tx('entry.mode_manual'))}</button>
    </form>
    <h2>${h(tx('entry.see_bib'))}</h2>
    <form class="check-in-search entry-see-form" data-url="${h(serverChildUrl)}">
      <label>${h(tx('entry.bib'))}
        <input type="text" name="bib" inputmode="numeric" pattern="[0-9]*" autocomplete="off" required>
      </label>
      <button type="submit" class="button secondary">${h(tx('entry.open'))}</button>
    </form>`;
};

/** The child's card (bib, name, event, attempt number, best result). */
export const childCard = ({ child, variant, used, best, tx, format }) => `
  <div class="check-in-child">
    <span class="bib">${h(child.bib)}</span>
    <p class="check-in-first-name">${h(child.name)}</p>
    <p>${h(child.levelLabel)} → <strong>${h(variant.label)}</strong></p>
    <p class="entry-attempt">${
      used >= variant.attempts
        ? `<span class="badge muted">${h(tx('entry.attempts_finished', { of: variant.attempts }))}</span>`
        : h(tx('entry.attempt_number', { number: used + 1, of: variant.attempts }))
    }</p>
    ${best !== null && variant.attempts > 1 ? `<p class="meta">${h(tx('entry.best', { result: format(best, variant) }))}</p>` : ''}
  </div>`;

const counterFields = (variant, tx) => {
  const limit = variant.limit;
  const byShots = limit?.type === 'shots';
  const duration =
    limit && !byShots
      ? `${Math.floor(limit.value / 60)}:${String(limit.value % 60).padStart(2, '0')}`
      : '';
  const limitText = !limit
    ? ''
    : ` <small>${h(byShots ? tx('entry.counter.limit_shots', { count: limit.value }) : tx('entry.counter.limit_duration', { duration }))}</small>`;
  return `
    <div class="counter" hidden data-review="0"
      data-limit-type="${h(limit?.type ?? '')}" data-limit-value="${h(limit?.value ?? 0)}"
      data-label-shot="${h(tx('entry.counter.shot'))}" data-label-points="${h(tx('unit.points'))}">
      <p class="counter-status"><output class="counter-clock"></output></p>
      <output class="counter-total">${h(tx('unit.points', { value: 0 }))}</output>
      <button type="button" class="button large counter-start">${h(tx('entry.counter.start'))}</button>
      <div class="counter-actions">
        ${variant.actions
          .map(
            (
              action,
            ) => `<button type="button" class="counter-action" data-action="${h(action.id)}" data-points="${h(action.points)}" disabled>
              ${h(action.label)} <small>+${h(action.points)}</small> <span class="counter-count">0</span></button>`,
          )
          .join('')}
        ${byShots ? `<button type="button" class="counter-action missed" data-action="missed" data-points="0" disabled>${h(tx('entry.counter.missed'))} <span class="counter-count">0</span></button>` : ''}
      </div>
      <div class="toolbar">
        <button type="button" class="button secondary counter-undo" disabled>${h(tx('entry.counter.undo'))}</button>
        <button type="button" class="button secondary counter-stop" disabled>${h(tx('entry.counter.stop'))}</button>
      </div>
    </div>
    <fieldset class="counter-counts">
      <legend>${h(tx('entry.counter.summary'))}${limitText}</legend>
      ${variant.actions
        .map(
          (action) => `<label>${h(action.label)} (+${h(action.points)})
            <input type="number" name="act_${h(action.id)}" min="0" max="999" inputmode="numeric" value="0" data-points="${h(action.points)}"></label>`,
        )
        .join('')}
      ${byShots ? `<label>${h(tx('entry.counter.missed'))} <input type="number" name="missed" min="0" max="999" inputmode="numeric" value="0" data-points="0"></label>` : ''}
      <output class="interpreted counter-sum" aria-live="polite"></output>
      <button type="submit" name="kind" value="counter" class="button large entry-save">${h(tx('entry.save'))}</button>
    </fieldset>
    <details class="counter-direct">
      <summary>${h(tx('entry.counter.direct'))}</summary>
      <label>${h(tx('entry.counter.direct_total'))} <small>${h(tx('entry.hint.counter'))}</small>
        <input type="text" name="value" class="result-input" inputmode="numeric" pattern="[0-9]*" maxlength="7" autocomplete="off" ${resultData(variant, tx, 'counter-direct-value')}>
      </label>
      <output class="interpreted" id="counter-direct-value" aria-live="polite"></output>
      <button type="submit" name="kind" value="value" class="button secondary">${h(tx('entry.save'))}</button>
    </details>
    <button type="submit" name="kind" value="foul" class="button danger">${h(tx('entry.foul_button'))}</button>`;
};

/** One child: result, modifiers, save or foul; counter events get the counter. */
export const childView = ({ child, variant, card, tx, open, childUrl }) => `
  <p><a href="#">← ${h(tx('entry.other_bib'))}</a></p>
  ${card}
  ${
    child.present
      ? ''
      : `<div class="notice warning">${h(tx('entry.warning.not_present'))}
           ${open ? `<button type="button" class="button small secondary entry-mark-present">${h(tx('entry.mark_present'))}</button>` : ''}</div>`
  }
  ${
    open
      ? `<form class="form entry-form" novalidate>
          <div class="entry-messages"></div>
          ${
            variant.measure === 'counter'
              ? counterFields(variant, tx)
              : `<label>${h(tx('entry.result'))} <small>${h(tx(hintKey(variant)))}</small>
                   <input type="text" name="value" class="result-input" inputmode="numeric" pattern="[0-9]*" maxlength="7" autocomplete="off" autofocus ${resultData(variant, tx, 'entry-interpreted')}>
                 </label>
                 <output class="interpreted" id="entry-interpreted" aria-live="polite"></output>
                 ${modifierFields(variant, '')}
                 <button type="submit" name="kind" value="value" class="button large entry-save">${h(tx('entry.save'))}</button>
                 <button type="submit" name="kind" value="foul" class="button danger">${h(tx('entry.foul_button'))}</button>`
          }
          ${variant.foulMode === 'lenient' ? `<p class="meta">${h(tx('entry.lenient_hint'))}</p>` : ''}
        </form>`
      : ''
  }
  <p><a href="${h(childUrl)}" class="entry-online-link">${h(tx('entry.see_attempts'))}</a></p>`;

/** A race of 1 or 2 children (spec § 8.4). */
export const raceView = ({ lanes, mode, tx, open, differentEvents, childUrl }) => {
  const laneCards = lanes
    .map(
      ({ child, variant, used }) => `
      <li class="race-lane">
        <span class="bib">${h(child.bib)}</span>
        <span class="race-name">${h(child.name)}</span>
        <span>${h(child.levelLabel)} → <strong>${h(variant.label)}</strong></span>
        <span class="meta">${
          used >= variant.attempts
            ? `<span class="badge muted">${h(tx('entry.attempts_finished', { of: variant.attempts }))}</span>`
            : h(tx('entry.attempt_number', { number: used + 1, of: variant.attempts }))
        } · <a href="${h(childUrl(child.bib))}" class="entry-online-link">${h(tx('entry.see_attempts'))}</a></span>
        ${child.present ? '' : `<span class="badge warning">${h(tx('entry.not_checked_in'))}</span>`}
      </li>`,
    )
    .join('');
  const decimal = tx('format.decimal_separator');
  const stopwatch =
    mode !== 'timer'
      ? ''
      : `<section class="stopwatch" hidden data-review="0"
          data-label-start="${h(tx('entry.timer.start'))}" data-label-finish="${h(tx('entry.timer.finish'))}"
          data-place-first="${h(tx('entry.timer.place_first'))}" data-place-other="${h(tx('entry.timer.place_other'))}"
          data-decimal="${h(decimal)}">
          <output class="stopwatch-clock" aria-live="off">0${h(decimal)}00</output>
          <button type="button" class="button large stopwatch-go">${h(tx('entry.timer.start'))}</button>
          <div class="stopwatch-dnf">${lanes
            .map(
              ({ child }, index) =>
                `<button type="button" class="button secondary" data-lane="${index + 1}" disabled>${h(tx('entry.timer.dnf', { name: child.name }))}</button>`,
            )
            .join('')}</div>
          <button type="button" class="button danger stopwatch-cancel" data-confirm-text="${h(tx('entry.timer.confirm_cancel'))}">${h(tx('entry.timer.cancel'))}</button>
        </section>`;
  const fields = lanes
    .map(({ child, variant }, index) => {
      const n = index + 1;
      return `
      <fieldset class="race-result" data-lane="${n}" data-time-format="${h(variant.timeFormat ?? 'ss.cc')}">
        <legend><span class="bib">${h(child.bib)}</span> ${h(child.name)} <small>${h(variant.label)}</small></legend>
        <p class="race-place" hidden></p>
        <input type="hidden" name="${n}_timed" value="" class="race-timed">
        <div class="race-messages"></div>
        <label>${h(tx('entry.time'))} <small>${h(tx(hintKey(variant)))}</small>
          <input type="text" name="${n}_value" class="result-input" inputmode="numeric" pattern="[0-9]*" maxlength="7" autocomplete="off" ${resultData(variant, tx, `interpreted-${n}`)}>
        </label>
        <output class="interpreted" id="interpreted-${n}" aria-live="polite"></output>
        <label class="choice"><input type="checkbox" name="${n}_dnf" value="1" class="race-dnf"> ${h(tx('entry.did_not_finish'))}</label>
        ${modifierFields(variant, `${n}_`)}
      </fieldset>`;
    })
    .join('');
  return `
    <p><a href="#">← ${h(tx('entry.other_race'))}</a></p>
    <ol class="race-lanes">${laneCards}</ol>
    ${differentEvents ? `<p class="notice warning">${h(tx('entry.different_events'))}</p>` : ''}
    ${
      open
        ? `${stopwatch}
          <form class="form entry-form race-form" novalidate>
            <div class="entry-messages"></div>
            ${mode === 'timer' && lanes.length > 1 ? `<button type="button" class="button secondary race-swap" hidden>${h(tx('entry.timer.swap'))}</button>` : ''}
            ${fields}
            <button type="submit" class="button large entry-save">${h(tx('entry.save'))}</button>
            <a class="button danger race-abandon" href="#">${h(tx('entry.timer.cancel'))}</a>
          </form>`
        : ''
    }`;
};

/** Entries kept on the phone that still need attention. */
export const queueView = ({ entries, tx }) =>
  entries.length === 0
    ? ''
    : `<section class="entry-queue">
        <h2>${h(tx('entry.offline.queue_title'))}</h2>
        <ul class="history">${entries
          .map((entry) => {
            const status =
              entry.status === 'rejected'
                ? tx('entry.offline.status_rejected', {
                    reason: tx(entry.reason || 'entry.error.invalid_value', { bib: entry.bib }),
                  })
                : tx(`entry.offline.status_${entry.status}`);
            return `<li><strong>${h(entry.label)}</strong><br><small class="muted">${h(status)}</small>
              ${entry.status === 'pending' ? '' : `<button type="button" class="button small secondary entry-queue-remove" data-id="${h(entry.id)}">${h(tx('entry.offline.remove'))}</button>`}</li>`;
          })
          .join('')}</ul>
      </section>`;
