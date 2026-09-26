// Signing up for an event, and giving a place back, without a reload: the
// page says what happened where the form was.
function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

ready((MT) => {
  const box = document.querySelector('[data-event]');
  if (!box || !MT) return;
  const slug = box.dataset.event;
  const labels = JSON.parse(box.dataset.labels || '{}');

  box.querySelector('[data-event-form]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.target;
    const error = form.querySelector('[data-error]');
    const body = {};
    for (const field of form.elements) {
      if (!field.name) continue;
      body[field.name] = field.dataset.type === 'int' ? Number.parseInt(field.value || '0', 10) : field.value;
    }
    try {
      await MT.api(`/api/events/${slug}/register`, { method: 'POST', body });
      window.location.reload();
    } catch (e) {
      if (error) { error.textContent = e.message; error.hidden = false; }
    }
  });

  box.querySelector('[data-event-cancel]')?.addEventListener('click', async () => {
    if (labels.cancelConfirm && !window.confirm(labels.cancelConfirm)) return;
    try {
      await MT.api(`/api/events/${slug}/register`, { method: 'DELETE' });
      window.location.reload();
    } catch (e) {
      window.alert(e.message);
    }
  });
});
