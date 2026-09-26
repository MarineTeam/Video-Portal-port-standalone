// Keeping the rotas: the order they appear in, the sheet one is fed from,
// the dates typed in here, and the people.
//
// Test connection is the point of this screen. A column mapping is guesswork
// until you can see what the parser made of the sheet, so it shows the first
// events exactly as they were read and every row it left out, with the
// reason, before anything is written.
function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

function say(form, message) {
  const error = form?.querySelector('[data-error]');
  if (error) { error.textContent = message; error.hidden = message === ''; }
}

function fields(form) {
  const body = {};
  for (const field of form.elements) {
    if (!field.name) continue;
    let value = field.type === 'checkbox' ? field.checked : field.value;
    if (field.dataset.type === 'int') value = Number.parseInt(String(value) || '0', 10);
    if (field.dataset.type === 'multi') value = String(value).split('\n').map((line) => line.trim()).filter(Boolean);
    if (field.dataset.null !== undefined && value === '') value = null;
    body[field.name] = value;
  }
  return body;
}

ready((MT) => {
  if (!MT) return;

  // -- The list of rotas ------------------------------------------------
  const list = document.querySelector('[data-schedule-list]');
  list?.querySelectorAll('[data-move]').forEach((button) => {
    button.addEventListener('click', async () => {
      const row = button.closest('[data-schedule]');
      const sibling = button.dataset.move === 'up' ? row.previousElementSibling : row.nextElementSibling;
      if (!sibling) return;
      if (button.dataset.move === 'up') row.parentNode.insertBefore(row, sibling);
      else row.parentNode.insertBefore(sibling, row);
      const ids = [...list.querySelectorAll('[data-schedule]')].map((one) => one.dataset.schedule);
      try {
        await MT.api('/api/admin/schedules/reorder', { method: 'POST', body: { ids } });
      } catch (e) {
        say(list.closest('[data-schedules]'), e.message);
      }
    });
  });

  document.querySelector('[data-schedule-new]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      const made = await MT.api('/api/admin/schedules', { method: 'POST', body: fields(event.target) });
      window.location.href = MT.url(`/admin/schedules/${made.id}`);
    } catch (e) {
      say(event.target, e.message);
    }
  });

  document.querySelector('[data-schedule-key]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await MT.api('/api/admin/schedules/key', { method: 'POST', body: fields(event.target) });
      window.location.reload();
    } catch (e) {
      say(event.target, e.message);
    }
  });

  // -- One rota ----------------------------------------------------------
  const page = document.querySelector('[data-schedule]:not(li)');
  if (page) {
    const id = page.dataset.schedule;
    const labels = JSON.parse(page.dataset.labels || '{}');

    page.querySelector('[data-schedule-edit]')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await MT.api(`/api/admin/schedules/${id}`, { method: 'PATCH', body: fields(event.target) });
        window.location.reload();
      } catch (e) {
        say(event.target, e.message);
      }
    });

    const sourceForm = document.querySelector('[data-source-form]');
    const result = document.querySelector('[data-source-result]');

    const drawResult = (data) => {
      if (!result) return;
      result.hidden = false;
      result.textContent = '';
      if (data.ok === false) {
        const p = document.createElement('p');
        p.className = 'notice warn small';
        p.textContent = data.error || '';
        result.appendChild(p);
        return;
      }
      if (Array.isArray(data.events) && data.events.length > 0) {
        const heading = document.createElement('h3');
        heading.className = 'small';
        heading.textContent = `${data.total}`;
        result.appendChild(heading);
        const ul = document.createElement('ul');
        ul.className = 'plain small';
        data.events.forEach((one) => {
          const li = document.createElement('li');
          li.textContent = [one.date, one.startTime, (one.people || []).join(', '), one.title, one.location, one.notes].filter(Boolean).join(' · ');
          ul.appendChild(li);
        });
        result.appendChild(ul);
      }
      if (Array.isArray(data.skipped) && data.skipped.length > 0) {
        const ul = document.createElement('ul');
        ul.className = 'plain small muted';
        data.skipped.forEach((one) => {
          const li = document.createElement('li');
          li.textContent = `${one.row > 0 ? one.row : labels.wholeSheet || ''}: ${one.reason}`;
          ul.appendChild(li);
        });
        result.appendChild(ul);
      }
    };

    document.querySelector('[data-source-test]')?.addEventListener('click', async () => {
      const body = fields(sourceForm);
      try {
        drawResult(await MT.api(`/api/admin/schedules/${id}/validate`, { method: 'POST', body }));
      } catch (e) {
        say(sourceForm, e.message);
      }
    });

    sourceForm?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await MT.api(`/api/admin/schedules/${id}`, { method: 'PATCH', body: { source: fields(sourceForm) } });
        window.location.reload();
      } catch (e) {
        say(sourceForm, e.message);
      }
    });

    document.querySelector('[data-source-sync]')?.addEventListener('click', async (event) => {
      event.target.disabled = true;
      try {
        const done = await MT.api(`/api/admin/schedules/${id}/sync`, { method: 'POST', body: {} });
        if (done.status === 'UNCHANGED') say(sourceForm, labels.unchanged || '');
        else window.location.reload();
      } catch (e) {
        say(sourceForm, e.message);
      }
      event.target.disabled = false;
    });

    document.querySelector('[data-event-new]')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await MT.api(`/api/admin/schedules/${id}/events`, { method: 'POST', body: fields(event.target) });
        window.location.reload();
      } catch (e) {
        say(event.target, e.message);
      }
    });
  }

  // -- People -------------------------------------------------------------
  document.querySelector('[data-person-new]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await MT.api('/api/admin/people', { method: 'POST', body: fields(event.target) });
      window.location.reload();
    } catch (e) {
      say(event.target, e.message);
    }
  });

  document.querySelectorAll('[data-merge]').forEach((button) => {
    button.addEventListener('click', async () => {
      if (!window.confirm(button.dataset.confirm || '')) return;
      try {
        await MT.api('/api/admin/people/merge', { method: 'POST', body: { keepId: button.dataset.keep, loseId: button.dataset.lose } });
        window.location.reload();
      } catch (e) {
        say(button.closest('[data-duplicates]'), e.message);
      }
    });
  });

  document.querySelectorAll('[data-rename]').forEach((button) => {
    button.addEventListener('click', async () => {
      const row = button.closest('[data-person]');
      const was = row.querySelector('strong')?.textContent || '';
      const now = window.prompt(was, was);
      if (now === null || now.trim() === '' || now === was) return;
      try {
        await MT.api(`/api/admin/people/${row.dataset.person}`, { method: 'PATCH', body: { displayName: now.trim() } });
        window.location.reload();
      } catch (e) {
        window.alert(e.message);
      }
    });
  });
});
