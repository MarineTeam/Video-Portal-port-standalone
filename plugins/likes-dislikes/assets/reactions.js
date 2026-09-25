// Thumbs: a click sets the member's reaction, the same one again takes it
// back; the answer carries both counts.
// window.MT is the core's, set by a module that may run after this one: read it on use.
const api = (path, options) => window.MT.api(path, options);

for (const box of document.querySelectorAll('[data-reactions]')) {
  box.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-reaction]');
    if (!button) return;
    const type = button.getAttribute('aria-pressed') === 'true' ? null : button.dataset.reaction;
    try {
      const summary = await api('/api/reactions', { method: 'POST', body: { ...JSON.parse(box.dataset.reactions), type } });
      for (const b of box.querySelectorAll('[data-reaction]')) {
        b.setAttribute('aria-pressed', String(summary.mine === b.dataset.reaction));
        b.querySelector('[data-count]').textContent = String(b.dataset.reaction === 'LIKE' ? summary.likes : summary.dislikes);
      }
    } catch (error) {
      window.alert(error.message);
    }
  });
}
