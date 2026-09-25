// Drives the installer's migrations one file per request, so no single
// request runs long enough for a host to kill it.
const form = document.querySelector('[data-install-migrate]');
const bar = document.querySelector('[data-install-progress]');
if (form) {
  const run = async () => {
    const body = new FormData(form);
    for (;;) {
      const response = await fetch(form.action || window.location.href, { method: 'POST', body, credentials: 'same-origin' });
      if (!response.ok) {
        form.insertAdjacentHTML('beforebegin', '<p class="error">A step failed. Reload the page to carry on from where it stopped.</p>');
        return;
      }
      const result = await response.json();
      if (bar) { bar.max = result.total; bar.value = result.total - result.remaining; }
      if (result.done) { window.location.reload(); return; }
    }
  };
  form.addEventListener('submit', (e) => { e.preventDefault(); run(); });
  run();
}
