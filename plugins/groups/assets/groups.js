// The group's page: asking to join, the conversation, and the roll — each
// without a reload, because a leader marking a roll has the form open.
function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

ready((MT) => {
  const box = document.querySelector('[data-group]');
  if (!box || !MT) return;
  const slug = box.dataset.group;
  const labels = JSON.parse(box.dataset.labels || '{}');
  const say = (scope, message) => {
    const error = scope.querySelector('[data-error]') || document.querySelector('[data-error]');
    if (error) { error.textContent = message; error.hidden = message === ''; }
  };

  box.querySelector('[data-group-join]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.target;
    try {
      await MT.api(`/api/groups/${slug}/join`, { method: 'POST', body: { note: form.elements.namedItem('note')?.value || null } });
      window.location.reload();
    } catch (e) {
      say(form, e.message);
    }
  });

  box.querySelector('[data-group-leave]')?.addEventListener('click', async () => {
    if (labels.leaveConfirm && !window.confirm(labels.leaveConfirm)) return;
    await MT.api(`/api/groups/${slug}/join`, { method: 'DELETE' }).then(() => window.location.reload()).catch((e) => window.alert(e.message));
  });

  // The conversation.
  const list = document.querySelector('[data-group-messages]');
  const form = document.querySelector('[data-group-say]');
  const render = (message) => {
    const li = document.createElement('li');
    li.dataset.message = message.id;
    const head = Object.assign(document.createElement('p'), { className: 'small' });
    head.append(Object.assign(document.createElement('strong'), { textContent: message.author }));
    if (message.canRemove) {
      const remove = Object.assign(document.createElement('button'), { type: 'button', className: 'link small', textContent: 'Take it down' });
      remove.setAttribute('data-message-remove', '');
      head.append(' ', remove);
    }
    li.append(head, Object.assign(document.createElement('p'), { className: 'group-message', textContent: message.body }));
    return li;
  };
  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const text = form.elements.namedItem('body');
    if (!text.value.trim()) return;
    try {
      list?.append(render(await MT.api(`/api/groups/${slug}/messages`, { method: 'POST', body: { body: text.value } })));
      text.value = '';
      document.querySelector('[data-thread-empty]')?.remove();
      say(form, '');
    } catch (e) {
      say(form, e.message);
    }
  });
  list?.addEventListener('click', async (event) => {
    const li = event.target.closest('[data-message]');
    if (!li || !event.target.closest('[data-message-remove]')) return;
    if (labels.removeConfirm && !window.confirm(labels.removeConfirm)) return;
    await MT.api(`/api/groups/${slug}/messages/${li.dataset.message}`, { method: 'DELETE' }).then(() => li.remove()).catch((e) => window.alert(e.message));
  });

  // The roll.
  document.querySelector('[data-group-roll]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const roll = event.target;
    const attendance = [];
    for (const row of roll.querySelectorAll('[data-roll-row]')) {
      const userId = row.dataset.rollRow;
      const chosen = row.querySelector('input[type=radio]:checked');
      attendance.push({ userId, status: chosen ? chosen.value : 'ABSENT', note: row.querySelector(`input[name="n-${userId}"]`)?.value || null });
    }
    const value = (name) => roll.elements.namedItem(name)?.value || null;
    try {
      await MT.api(`/api/groups/${slug}/meetings`, {
        method: 'POST',
        body: {
          date: value('date'),
          topic: value('topic'),
          visitorCount: Number.parseInt(value('visitorCount') || '0', 10),
          cancelled: roll.elements.namedItem('cancelled')?.checked || false,
          leaderNotes: value('leaderNotes'),
          guideId: value('guideId') || null,
          attendance,
        },
      });
      window.location.reload();
    } catch (e) {
      say(roll, e.message);
    }
  });
});
