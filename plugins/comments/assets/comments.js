// Comments: post, reply, delete and report without reloading the page
// (under a video, a reload would stop it).
function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

ready((MT) => {
  const box = document.querySelector('[data-comments]');
  if (!box || !MT) return;
  const item = JSON.parse(box.dataset.comments);
  const labels = JSON.parse(box.dataset.labels);
  const list = box.querySelector('[data-comment-list]');

  const button = (label, attr) => {
    const b = Object.assign(document.createElement('button'), { type: 'button', className: 'link', textContent: label });
    b.setAttribute(attr, '');
    return b;
  };
  const render = (c, reply) => {
    const li = document.createElement('li');
    li.dataset.comment = c.id;
    const wrap = Object.assign(document.createElement('div'), { className: 'comment' });
    const head = Object.assign(document.createElement('p'), { className: 'small' });
    head.append(Object.assign(document.createElement('strong'), { textContent: c.author }));
    const body = Object.assign(document.createElement('p'), { className: 'comment-body', textContent: c.body });
    const actions = Object.assign(document.createElement('p'), { className: 'small comment-actions' });
    if (!reply) actions.append(button(labels.reply, 'data-comment-reply'), ' ');
    if (c.canDelete) actions.append(button(labels.delete, 'data-comment-delete'));
    wrap.append(head, body, actions);
    li.append(wrap);
    if (!reply) {
      const replies = Object.assign(document.createElement('ol'), { className: 'plain comment-replies' });
      replies.dataset.replies = '';
      li.append(replies);
    }
    return li;
  };
  const post = async (form, parentId) => {
    const error = form.querySelector('[data-error]') || box.querySelector('[data-error]');
    const text = form.elements.namedItem('body');
    try {
      const c = await MT.api('/api/comments', { method: 'POST', body: { ...item, body: text.value, parentId } });
      if (c.parentId) {
        list.querySelector(`[data-comment="${c.parentId}"] [data-replies]`)?.append(render(c, true));
        form.remove();
      } else {
        list.append(render(c, false));
        text.value = '';
      }
      box.querySelector('[data-no-comments]')?.remove();
      if (error) error.hidden = true;
    } catch (e) {
      if (error) { error.textContent = e.message; error.hidden = false; }
    }
  };
  box.querySelector('[data-comment-form]')?.addEventListener('submit', (event) => {
    event.preventDefault();
    post(event.target, null);
  });
  list.addEventListener('click', async (event) => {
    const li = event.target.closest('[data-comment]');
    if (!li) return;
    if (event.target.closest('[data-comment-reply]')) {
      if (li.querySelector(':scope > form')) return;
      const form = document.createElement('form');
      form.className = 'row';
      const text = Object.assign(document.createElement('textarea'), { name: 'body', rows: 1, required: true, maxLength: 2000 });
      const send = Object.assign(document.createElement('button'), { type: 'submit', className: 'button small', textContent: labels.reply });
      form.append(text, send);
      form.addEventListener('submit', (e) => { e.preventDefault(); post(form, li.dataset.comment); });
      li.querySelector('.comment').after(form);
      text.focus();
    } else if (event.target.closest('[data-comment-delete]')) {
      if (!window.confirm(labels.confirmDelete)) return;
      await MT.api(`/api/comments/${li.dataset.comment}`, { method: 'DELETE' }).then(() => li.remove()).catch((e) => window.alert(e.message));
    } else if (event.target.closest('[data-comment-report]')) {
      const b = event.target.closest('[data-comment-report]');
      await MT.api(`/api/comments/${li.dataset.comment}/report`, { method: 'POST' }).then(() => { b.textContent = labels.reported; b.disabled = true; }).catch((e) => window.alert(e.message));
    }
  });
});
