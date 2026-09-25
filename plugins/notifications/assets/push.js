// Profile → Inbox: turn push notifications on or off for this browser.
const box = document.querySelector('[data-push-toggle]');

function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

// The VAPID public key, base64url, as the bytes PushManager wants.
function keyBytes(b64) {
  const s = atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (b64.length % 4)) % 4));
  return Uint8Array.from(s, (c) => c.charCodeAt(0));
}

ready(async (MT) => {
  if (!box || !MT) return;
  const texts = box.querySelector('[data-push-texts]').dataset;
  const state = box.querySelector('[data-push-state]');
  const on = box.querySelector('[data-push-on]');
  const off = box.querySelector('[data-push-off]');
  box.hidden = false;
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !window.isSecureContext) {
    state.textContent = texts.unsupported;
    on.hidden = true;
    return;
  }
  const registration = await navigator.serviceWorker.ready;
  const show = (sub) => {
    const blocked = Notification.permission === 'denied';
    state.textContent = blocked ? texts.blocked : sub ? texts.on : texts.off;
    on.hidden = Boolean(sub) || blocked;
    off.hidden = !sub;
  };
  show(await registration.pushManager.getSubscription());
  on.addEventListener('click', async () => {
    try {
      const sub = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(box.dataset.pushToggle) });
      await MT.api('/api/push/subscribe', { method: 'POST', body: sub.toJSON() });
      show(sub);
    } catch (error) {
      state.textContent = Notification.permission === 'denied' ? texts.blocked : error.message;
    }
  });
  off.addEventListener('click', async () => {
    const sub = await registration.pushManager.getSubscription();
    if (sub) {
      await MT.api('/api/push/unsubscribe', { method: 'POST', body: { endpoint: sub.endpoint } }).catch(() => {});
      await sub.unsubscribe();
    }
    show(null);
  });
});
