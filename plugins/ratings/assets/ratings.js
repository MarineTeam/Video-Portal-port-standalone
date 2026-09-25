// Stars: a click sets the member's rating, the same star again takes it
// back. The answer carries the new average, so nothing else is fetched.
// window.MT is the core's, set by a module that may run after this one: read it on use.
const api = (path, options) => window.MT.api(path, options);

function paint(box, summary) {
  box.dataset.mine = summary.mine ?? '';
  for (const star of box.querySelectorAll('[data-stars]')) {
    star.setAttribute('aria-pressed', String(summary.mine !== null && Number(star.dataset.stars) <= summary.mine));
  }
  const text = box.querySelector('[data-rating-summary]');
  if (text) {
    text.textContent = summary.count > 0
      ? (box.dataset.summaryTemplate || '{average} ({count})').replace('{average}', summary.average.toFixed(1)).replace('{count}', summary.count)
      : box.dataset.noneText || '';
  }
}

for (const box of document.querySelectorAll('[data-rating]')) {
  box.addEventListener('click', async (event) => {
    const star = event.target.closest('[data-stars]');
    if (!star) return;
    const value = Number(star.dataset.stars);
    const mine = Number(box.dataset.mine || 0);
    try {
      const summary = await api('/api/ratings', { method: 'POST', body: { ...JSON.parse(box.dataset.rating), value: value === mine ? null : value } });
      paint(box, summary);
    } catch (error) {
      window.alert(error.message);
    }
  });
}
