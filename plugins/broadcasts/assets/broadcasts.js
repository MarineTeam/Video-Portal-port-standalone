// The broadcast composer and the batch loop.
//
// The loop lives here rather than on the server because one request that
// tried to send four hundred emails would be killed at the host's timeout
// with no record of how far it got. Calling /send repeatedly does the same
// work in pieces the host will finish, and draws a progress bar out of the
// same mechanism. The cron job is the backstop for a closed laptop.
import { smsSegments } from '../../../assets/js/sms.js';

function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

function fill(template, values) {
  return String(template ?? '').replace(/\{(\w+)\}/g, (_, key) => String(values[key] ?? ''));
}

ready((MT) => {
  if (!MT) return;

  // -- The composer -------------------------------------------------------
  const box = document.querySelector('[data-broadcast-new]');
  if (box) {
    const labels = JSON.parse(box.dataset.labels || '{}');
    const skipLabels = JSON.parse(labels.skips || '{}');
    const audiences = JSON.parse(box.dataset.audiences || '{}');
    const form = box.querySelector('[data-broadcast-form]');
    const reach = box.querySelector('[data-reach]');
    const cost = box.querySelector('[data-sms-cost]');
    const error = box.querySelector('[data-error]');
    const progress = box.querySelector('[data-progress]');
    const pick = box.querySelector('[data-audience-pick]');
    const pickLabel = box.querySelector('[data-audience-label]');
    const pickSelect = pick?.querySelector('select');
    const audienceSelect = form.elements.audience;
    let last = 0;

    const channels = () => [...form.querySelectorAll('input[name="channels"]:checked')].map((box) => box.value);

    const say = (message) => {
      if (!error) return;
      error.textContent = message;
      error.hidden = message === '';
    };

    /** Which group, event, team — only when the audience names one. */
    function showAudiencePick() {
      const list = audiences[audienceSelect.value] || [];
      const named = audienceSelect.value !== 'EVERYONE';
      if (pick) pick.hidden = !named;
      if (pickLabel) pickLabel.textContent = audienceSelect.selectedOptions[0]?.textContent || '';
      if (pickSelect) {
        pickSelect.innerHTML = '';
        for (const option of list) {
          const element = document.createElement('option');
          element.value = option.id;
          element.textContent = option.name;
          pickSelect.appendChild(element);
        }
      }
    }

    /** The count that will go out, asked for as the composer is filled in. */
    async function look() {
      const at = ++last;
      const chosen = channels();
      if (chosen.length === 0) {
        if (reach) reach.textContent = '';
        return;
      }
      try {
        const seen = await MT.api('/api/admin/broadcasts/preview', {
          method: 'POST',
          body: {
            audience: audienceSelect.value,
            audienceId: audienceSelect.value === 'EVERYONE' ? null : pickSelect?.value || null,
            channels: chosen,
            body: form.elements.body.value,
          },
        });
        if (at !== last || !reach) return;
        const bits = [fill(labels.reaches, { count: seen.reached })];
        if (seen.missed > 0) {
          const why = (seen.skips || []).map((skip) => `${skip.count} ${skipLabels[skip.reason] || skip.reason}`);
          bits.push(`${fill(labels.andMissed, { count: seen.missed })}${why.length ? ' · ' + why.join(', ') : ''}`);
        }
        reach.textContent = bits.join(' — ');
        reach.dataset.reached = String(seen.reached);
      } catch (e) {
        if (at === last) say(e.message);
      }
    }

    /** The cost as you type, counted the way the carrier charges. */
    function showCost() {
      if (!cost) return;
      const on = channels().includes('SMS');
      cost.hidden = !on;
      if (!on) return;
      const counted = smsSegments(form.elements.body.value);
      cost.textContent = fill(labels.smsCost, counted);
    }

    audienceSelect.addEventListener('change', () => { showAudiencePick(); look(); });
    pickSelect?.addEventListener('change', look);
    for (const input of form.querySelectorAll('input[name="channels"]')) {
      input.addEventListener('change', () => { showCost(); look(); });
    }
    let typing;
    form.elements.body.addEventListener('input', () => {
      showCost();
      window.clearTimeout(typing);
      typing = window.setTimeout(look, 400);
    });
    showAudiencePick();
    showCost();
    look();

    const draft = async () => {
      const chosen = channels();
      const made = await MT.api('/api/admin/broadcasts', {
        method: 'POST',
        body: {
          subject: form.elements.subject.value,
          body: form.elements.body.value,
          channels: chosen,
          audience: audienceSelect.value,
          audienceId: audienceSelect.value === 'EVERYONE' ? null : pickSelect?.value || null,
        },
      });
      return made.id;
    };

    box.querySelector('[data-broadcast-test]')?.addEventListener('click', async () => {
      say('');
      try {
        const id = box.dataset.draftId || (box.dataset.draftId = await draft());
        await MT.api(`/api/admin/broadcasts/${id}/test`, { method: 'POST', body: {} });
        if (progress) { progress.textContent = labels.testSent; progress.hidden = false; }
      } catch (e) {
        say(e.message);
      }
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      say('');
      const reached = Number(reach?.dataset.reached || '0');
      if (!window.confirm(fill(labels.sendConfirm, { count: reached }))) return;
      const button = form.querySelector('button[type="submit"]');
      if (button) button.disabled = true;
      try {
        const id = box.dataset.draftId || (box.dataset.draftId = await draft());
        await run(MT, id, progress, labels);
        window.location.href = MT.url(`/admin/broadcasts/${id}`);
      } catch (e) {
        say(e.message);
        if (button) button.disabled = false;
      }
    });
  }

  // -- Carrying on with one that stopped ------------------------------------
  const one = document.querySelector('[data-broadcast-one]');
  one?.querySelector('[data-broadcast-resume]')?.addEventListener('click', async (event) => {
    event.target.disabled = true;
    try {
      await run(MT, one.dataset.broadcastOne, one.querySelector('p'), JSON.parse(one.dataset.labels || '{}'));
      window.location.reload();
    } catch (e) {
      const error = one.querySelector('[data-error]');
      if (error) { error.textContent = e.message; error.hidden = false; }
      event.target.disabled = false;
    }
  });
});

/**
 * Call /send until nothing is pending. Each call marks what it managed, so
 * closing the laptop half-way means the rest go later — not that everybody
 * gets it twice.
 */
async function run(MT, id, where, labels) {
  for (let round = 0; round < 500; round += 1) {
    const state = await MT.api(`/api/admin/broadcasts/${id}/send`, { method: 'POST', body: {} });
    const progress = state.progress || {};
    if (where) {
      where.hidden = false;
      where.textContent = fill(labels.sending, progress);
    }
    if (progress.finished || state.status === 'CANCELLED') return state;
  }
  throw new Error('This is taking longer than expected. It will carry on by itself.');
}
