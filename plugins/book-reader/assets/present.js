// The presenter: one verse at a time, arrow keys or the buttons, and the
// copyright line stays up throughout.
const box = document.querySelector('[data-present]');
if (box) {
  const verses = [...box.querySelectorAll('.present-verse')];
  const count = box.querySelector('[data-present-count]');
  let at = 0;
  const show = (n) => {
    at = Math.max(0, Math.min(verses.length - 1, n));
    verses.forEach((verse, i) => { verse.hidden = i !== at; });
    if (count) count.textContent = `${at + 1} / ${verses.length}`;
  };
  box.querySelector('[data-present-prev]')?.addEventListener('click', () => show(at - 1));
  box.querySelector('[data-present-next]')?.addEventListener('click', () => show(at + 1));
  document.addEventListener('keydown', (event) => {
    if (['ArrowRight', 'PageDown', ' '].includes(event.key)) { event.preventDefault(); show(at + 1); }
    if (['ArrowLeft', 'PageUp'].includes(event.key)) { event.preventDefault(); show(at - 1); }
  });
  show(0);
}
