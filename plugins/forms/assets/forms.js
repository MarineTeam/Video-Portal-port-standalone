// Filling in a form: the answers go as {fieldId: value}, and what comes
// back replaces the form with the words the form itself chose to say.
function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

ready((MT) => {
  const form = document.querySelector('[data-form]');
  if (!form || !MT) return;
  const slug = form.dataset.form;
  const done = document.querySelector('[data-form-done]');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const error = form.querySelector('[data-error]');
    const answers = {};
    for (const field of form.querySelectorAll('[data-field]')) {
      const id = field.dataset.field;
      if (field.dataset.kind === 'CHECKBOXES') {
        if (field.checked) (answers[id] = answers[id] || []).push(field.value);
      } else if (field.dataset.kind === 'CHECKBOX') {
        answers[id] = field.checked;
      } else if (field.type === 'radio') {
        if (field.checked) answers[id] = field.value;
      } else {
        answers[id] = field.value;
      }
    }
    try {
      const answer = await MT.api(`/api/forms/${slug}`, { method: 'POST', body: { answers, website: form.elements.namedItem('website')?.value || '' } });
      if (done) {
        done.textContent = answer.confirmation || '';
        done.hidden = false;
      }
      form.remove();
      done?.scrollIntoView({ block: 'center' });
    } catch (e) {
      if (error) { error.textContent = e.message; error.hidden = false; }
    }
  });
});
