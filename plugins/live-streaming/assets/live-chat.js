// Live chat: the page asks for what is new every few seconds and stops
// entirely while the tab is hidden — a church leaves this open on a laptop
// all week. No socket: there is no long-lived process on the other end.
function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

const EVERY = 5000;

ready((MT) => {
  // Times in the reader's own zone (the core does this on a video page; the
  // live page carries its own module, so it does it here).
  for (const el of document.querySelectorAll('time[data-local-time]')) {
    const d = new Date(el.getAttribute('datetime'));
    if (!Number.isNaN(d.getTime())) el.textContent = d.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  }

  // A countdown to a stream that hasn't started, refreshed in place.
  const clock = document.querySelector('[data-live-countdown]');
  if (clock) {
    const at = new Date(clock.getAttribute('datetime'));
    const tick = () => {
      const left = Math.round((at.getTime() - Date.now()) / 1000);
      if (left <= 0) { window.location.reload(); return; }
      const parts = [Math.floor(left / 3600), Math.floor(left / 60) % 60, left % 60];
      clock.textContent = parts.map((n, i) => (i ? String(n).padStart(2, '0') : String(n))).join(':');
    };
    tick();
    window.setInterval(tick, 1000);
  }

  const box = document.querySelector('[data-live-chat]');
  if (!box || !MT) return;
  const streamId = box.dataset.liveChat;
  const labels = JSON.parse(box.dataset.labels);
  const list = box.querySelector('[data-live-messages]');
  const form = box.querySelector('[data-live-form]');
  const error = box.querySelector('[data-error]');
  let since = list.lastElementChild ? list.lastElementChild.dataset.message : null;

  const say = (message) => {
    if (error) { error.textContent = message; error.hidden = message === ''; }
  };
  const button = (label, attr) => {
    const b = Object.assign(document.createElement('button'), { type: 'button', className: 'link small', textContent: label });
    b.setAttribute(attr, '');
    return b;
  };
  const render = (m) => {
    const li = document.createElement('li');
    li.dataset.message = m.id;
    li.append(Object.assign(document.createElement('strong'), { textContent: m.author }), ' ');
    li.append(Object.assign(document.createElement('span'), { className: 'live-chat-body', textContent: m.body }));
    if (m.canDelete) li.append(' ', button(labels.delete, 'data-message-delete'));
    if (box.dataset.canModerate === '1' && !m.mine) li.append(' ', button(labels.mute, 'data-message-mute'));
    return li;
  };
  const add = (messages) => {
    const atBottom = list.scrollTop + list.clientHeight >= list.scrollHeight - 20;
    for (const m of messages) {
      if (!list.querySelector(`[data-message="${m.id}"]`)) list.append(render(m));
      since = m.id;
    }
    if (atBottom) list.scrollTop = list.scrollHeight;
  };
  // A message taken down goes, even from a tab that was a few seconds behind.
  const drop = (ids) => {
    for (const id of ids) list.querySelector(`[data-message="${id}"]`)?.remove();
  };

  const poll = async () => {
    if (document.hidden) return;
    try {
      const answer = await MT.api(`/api/live/${streamId}/chat${since ? `?since=${encodeURIComponent(since)}` : ''}`);
      add(answer.messages || []);
      drop(answer.removed || []);
      if (form && answer.state !== 'OPEN') {
        form.remove();
        const note = Object.assign(document.createElement('p'), { className: 'small muted', textContent: labels.closed });
        box.append(note);
      }
    } catch { /* a poll that fails is tried again in a few seconds */ }
  };
  let timer = window.setInterval(poll, EVERY);
  document.addEventListener('visibilitychange', () => {
    window.clearInterval(timer);
    if (!document.hidden) { poll(); timer = window.setInterval(poll, EVERY); }
  });

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = form.elements.namedItem('body');
    const body = input.value;
    if (!body.trim()) return;
    try {
      add([await MT.api(`/api/live/${streamId}/chat`, { method: 'POST', body: { body } })]);
      input.value = '';
      say('');
    } catch (e) {
      say(e.message);
    }
  });

  list.addEventListener('click', async (event) => {
    const li = event.target.closest('[data-message]');
    if (!li) return;
    if (event.target.closest('[data-message-delete]')) {
      await MT.api(`/api/live/${streamId}/chat/${li.dataset.message}`, { method: 'DELETE' }).then(() => li.remove()).catch((e) => say(e.message));
    } else if (event.target.closest('[data-message-mute]')) {
      if (!window.confirm(labels.confirmMute)) return;
      await MT.api(`/api/live/${streamId}/chat/mute`, { method: 'POST', body: { messageId: li.dataset.message } })
        .then(() => poll())
        .catch((e) => say(e.message));
    }
  });
});
