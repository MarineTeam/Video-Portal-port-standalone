// The prayer wall: asking, praying and taking down without a reload — the
// form keeps its place on a long page, and the answer says what happened.
function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

ready((MT) => {
  const form = document.querySelector('[data-prayer-form]');
  const list = document.querySelector('[data-prayer-list]');
  if (!MT || !list) return;
  const labels = JSON.parse(list.dataset.labels || '{}');

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const error = form.querySelector('[data-error]');
    const thanks = form.querySelector('[data-prayer-thanks]');
    const body = {};
    for (const field of form.elements) {
      if (!field.name) continue;
      body[field.name] = field.type === 'checkbox' ? field.checked : field.value;
    }
    try {
      await MT.api('/api/prayer', { method: 'POST', body });
      form.reset();
      if (error) error.hidden = true;
      // Nothing is added to the wall: it waits to be read first.
      if (thanks) thanks.hidden = false;
    } catch (e) {
      if (error) { error.textContent = e.message; error.hidden = false; }
    }
  });

  list.addEventListener('click', async (event) => {
    const li = event.target.closest('[data-prayer]');
    if (!li) return;
    const id = li.dataset.prayer;
    if (event.target.closest('[data-prayer-pray]')) {
      const button = event.target.closest('[data-prayer-pray]');
      try {
        const answer = await MT.api(`/api/prayer/${id}/pray`, { method: 'POST' });
        button.setAttribute('aria-pressed', 'true');
        button.disabled = true;
        if (labels.prayed) button.textContent = labels.prayed;
        const count = li.querySelector('[data-prayer-count]');
        if (count) count.textContent = count.textContent.replace(/\d+/, String(answer.prayers));
      } catch (e) {
        window.alert(e.message);
      }
    } else if (event.target.closest('[data-prayer-delete]')) {
      if (labels.confirmDelete && !window.confirm(labels.confirmDelete)) return;
      await MT.api(`/api/prayer/${id}`, { method: 'DELETE' }).then(() => li.remove()).catch((e) => window.alert(e.message));
    }
  });
});
