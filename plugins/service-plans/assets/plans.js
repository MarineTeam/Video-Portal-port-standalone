// The running order and the rota, edited in place: an order is one thing,
// so the whole list is sent when it is saved rather than a row at a time.
function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

ready((MT) => {
  const plan = document.querySelector('[data-plan]');
  if (!plan || !MT) return;
  const api = `/api/admin/services/${plan.dataset.plan}`;
  const order = plan.querySelector('[data-order]');
  const rota = document.querySelector('[data-rota]');
  const list = rota?.querySelector('[data-rota-list]');
  const say = (scope, message) => {
    const error = scope.querySelector('[data-error]');
    if (error) { error.textContent = message; error.hidden = message === ''; }
  };

  const rowControls = (li) => {
    for (const [attr, label] of [['data-move="up"', '↑'], ['data-move="down"', '↓']]) {
      const b = Object.assign(document.createElement('button'), { type: 'button', className: 'button small', textContent: label });
      b.setAttribute(attr.split('=')[0], attr.split('"')[1]);
      li.append(' ', b);
    }
    const x = Object.assign(document.createElement('button'), { type: 'button', className: 'button small danger', textContent: '×' });
    x.setAttribute('data-remove', '');
    li.append(' ', x);
  };

  plan.querySelector('[data-add-item]')?.addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.target;
    const file = form.elements.namedItem('fileId');
    if (!file.value) return;
    const li = document.createElement('li');
    li.className = 'row';
    li.setAttribute('data-row', '');
    li.dataset.file = file.value;
    li.dataset.number = form.elements.namedItem('hymnNumber').value || '';
    li.dataset.note = form.elements.namedItem('note').value || '';
    const label = file.options[file.selectedIndex].textContent;
    li.append(Object.assign(document.createElement('span'), { textContent: `${label}${li.dataset.number ? ' · ' + li.dataset.number : ''}${li.dataset.note ? ' · ' + li.dataset.note : ''}` }));
    rowControls(li);
    order.append(li);
    form.reset();
  });

  order?.addEventListener('click', (event) => {
    const li = event.target.closest('[data-row]');
    if (!li) return;
    if (event.target.closest('[data-remove]')) li.remove();
    const move = event.target.closest('[data-move]')?.dataset.move;
    if (move === 'up' && li.previousElementSibling) li.previousElementSibling.before(li);
    if (move === 'down' && li.nextElementSibling) li.nextElementSibling.after(li);
  });

  plan.querySelector('[data-save-order]')?.addEventListener('click', async () => {
    const items = [...order.querySelectorAll('[data-row]')].map((li) => ({
      fileId: li.dataset.file,
      hymnNumber: li.dataset.number ? Number.parseInt(li.dataset.number, 10) : null,
      note: li.dataset.note || null,
    })).filter((item) => item.fileId);
    try {
      await MT.api(api, { method: 'PATCH', body: { items } });
      window.location.reload();
    } catch (e) {
      say(plan, e.message);
    }
  });

  rota?.querySelector('[data-add-assignment]')?.addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.target;
    const user = form.elements.namedItem('userId');
    const team = form.elements.namedItem('teamId');
    const li = document.createElement('li');
    li.className = 'row';
    li.setAttribute('data-assignment', '');
    li.dataset.user = user.value;
    li.dataset.team = team.value;
    li.dataset.position = form.elements.namedItem('position').value || '';
    li.append(Object.assign(document.createElement('span'), { textContent: `${li.dataset.position || team.options[team.selectedIndex].textContent} — ${user.options[user.selectedIndex].textContent}` }));
    const x = Object.assign(document.createElement('button'), { type: 'button', className: 'button small danger', textContent: '×' });
    x.setAttribute('data-remove', '');
    li.append(' ', x);
    list.append(li);
    form.reset();
  });

  list?.addEventListener('click', (event) => {
    if (event.target.closest('[data-remove]')) event.target.closest('[data-assignment]')?.remove();
  });

  rota?.querySelector('[data-save-rota]')?.addEventListener('click', async () => {
    const assignments = [...list.querySelectorAll('[data-assignment]')].map((li) => ({
      userId: li.dataset.user,
      teamId: li.dataset.team,
      position: li.dataset.position || '',
    })).filter((a) => a.userId && a.teamId);
    try {
      await MT.api(api, { method: 'PATCH', body: { assignments } });
      window.location.reload();
    } catch (e) {
      say(rota, e.message);
    }
  });
});
