// Admin → Update: drives an update a request at a time — unpack, swap,
// then each migration — so no step has to fit more than one request.
import MT from './mt.js';

const box = document.querySelector('[data-update]');
const progress = box?.querySelector('[data-update-progress]');
const log = box?.querySelector('[data-update-log]');

function say(text) {
  const li = document.createElement('li');
  li.textContent = text;
  log?.append(li);
}

function fail(error) {
  const target = box?.querySelector('[data-error]');
  if (target) {
    target.textContent = error.message;
    target.hidden = false;
  }
  for (const button of box?.querySelectorAll('button') || []) button.disabled = false;
}

async function migrate() {
  if (progress) progress.hidden = false;
  for (;;) {
    const step = await MT.api('/api/admin/update/step', { method: 'POST' });
    if (step.applied) say(`Applied ${step.applied}`);
    if (progress && step.total) progress.value = Math.round(((step.total - step.remaining) / step.total) * 100);
    if (!step.applied || step.remaining === 0) break;
  }
  const done = await MT.api('/api/admin/update/finish', { method: 'POST' });
  say(`Finished: the site is at version ${done.version} and open again.`);
  if (progress) progress.value = 100;
}

async function run(task) {
  for (const button of box.querySelectorAll('button')) button.disabled = true;
  box.querySelector('[data-error]').hidden = true;
  try {
    await task();
    window.setTimeout(() => window.location.reload(), 1200);
  } catch (error) {
    fail(error);
  }
}

box?.querySelector('[data-update-run]')?.addEventListener('click', () => run(migrate));

box?.querySelector('[data-release-continue]')?.addEventListener('click', (event) => {
  const { id } = event.currentTarget.dataset;
  let { stage } = event.currentTarget.dataset;
  run(async () => {
    if (stage === 'verified') {
      say('Unpacking and checking every file…');
      stage = (await MT.api(`/api/admin/update/release/${id}/extract`, { method: 'POST' })).stage;
    }
    if (stage === 'extracted') {
      say('Moving the new files into place…');
      stage = (await MT.api(`/api/admin/update/release/${id}/swap`, { method: 'POST' })).stage;
    }
    if (stage === 'swapped') {
      say('Updating the database…');
      await migrate();
    }
  });
});
