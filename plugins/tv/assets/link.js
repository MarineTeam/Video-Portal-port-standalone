// Signing a television in, from a phone: type the code, read what it is,
// and say yes or no.
function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

ready((MT) => {
  const box = document.querySelector('[data-tv-link]');
  if (!box || !MT) return;
  const form = box.querySelector('[data-tv-lookup]');
  const error = box.querySelector('[data-error]');
  const approve = box.querySelector('[data-tv-approve]');
  const prompt = box.querySelector('[data-tv-prompt]');
  const done = box.querySelector('[data-tv-done]');
  const input = form?.querySelector('input[name="code"]');
  let code = '';

  const fail = (message) => {
    if (error) { error.textContent = message; error.hidden = false; }
    if (approve) approve.hidden = true;
  };

  async function look(typed) {
    if (error) error.hidden = true;
    try {
      const found = await MT.api('/api/tv/lookup', { method: 'POST', body: { code: typed } });
      code = found.userCode;
      if (prompt) prompt.textContent = found.prompt;
      if (approve) approve.hidden = false;
      if (form) form.hidden = true;
    } catch (e) {
      fail(e.message);
    }
  }

  form?.addEventListener('submit', (event) => {
    event.preventDefault();
    look(input?.value || '');
  });

  async function answer(yes) {
    try {
      const said = await MT.api('/api/tv/approve', { method: 'POST', body: { code, approve: yes } });
      if (approve) approve.hidden = true;
      if (done) { done.textContent = said.message; done.hidden = false; }
    } catch (e) {
      fail(e.message);
    }
  }

  box.querySelector('[data-tv-yes]')?.addEventListener('click', () => answer(true));
  box.querySelector('[data-tv-no]')?.addEventListener('click', () => answer(false));

  // A code in the address — from a QR on the screen — is looked up at once.
  if (box.dataset.code) look(box.dataset.code);
});
