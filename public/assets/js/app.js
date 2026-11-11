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
