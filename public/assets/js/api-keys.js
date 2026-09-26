// Making an API key: the one time the key itself is on screen.
import MT from './mt.js';

const box = document.querySelector('[data-api-keys]');
if (box) {
  const form = box.querySelector('[data-api-key-new]');
  const error = box.querySelector('[data-error]');
  const made = box.querySelector('[data-api-key-made]');
  const value = box.querySelector('[data-api-key-value]');

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (error) error.hidden = true;
    const scopes = [...form.querySelectorAll('input[name="scopes"]:checked')].map((one) => one.value);
    try {
      const key = await MT.api('/api/admin/api-keys', {
        method: 'POST',
        body: {
          name: form.elements.name.value,
          scopes,
          expiresAt: form.elements.expiresAt.value || null,
        },
      });
      if (value) value.textContent = key.key;
      if (made) made.hidden = false;
      form.reset();
      // Left on screen rather than reloaded away: the key is not recoverable.
      made?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    } catch (e) {
      if (error) { error.textContent = e.message; error.hidden = false; }
    }
  });

  box.querySelector('[data-api-key-copy]')?.addEventListener('click', async (event) => {
    try {
      await navigator.clipboard.writeText(value?.textContent || '');
      event.target.textContent = 'Copied';
    } catch {
      // No clipboard permission: it is on screen to select by hand.
      event.target.textContent = 'Select it above';
    }
  });
}
