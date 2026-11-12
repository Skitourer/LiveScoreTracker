/**
 * Follow-up list of a time workshop (spec § 8.6): at most 1 or 2 children
 * can be ticked for a race; the button waits for at least one.
 */
export const initTrackingRace = () => {
  const form = document.querySelector('form.tracking-race');
  if (!form) {
    return;
  }
  const max = Number(form.dataset.max);
  const boxes = [...form.querySelectorAll('input[type="checkbox"]')];
  const start = form.querySelector('button[type="submit"]');
  const update = () => {
    const ticked = boxes.filter((box) => box.checked).length;
    for (const box of boxes) {
      box.disabled = !box.checked && ticked >= max;
    }
    start.disabled = ticked === 0;
  };
  for (const box of boxes) {
    box.addEventListener('change', update);
  }
  // The race opens in the phone's entry screen (works offline once started).
  form.addEventListener('submit', (event) => {
    const ticked = boxes.filter((box) => box.checked).map((box) => box.value);
    if (ticked.length > 0 && form.dataset.app) {
      event.preventDefault();
      window.location.href = `${form.dataset.app}#race/${ticked[0]}/${ticked[1] ?? '-'}/timer`;
    }
  });
  update();
};
