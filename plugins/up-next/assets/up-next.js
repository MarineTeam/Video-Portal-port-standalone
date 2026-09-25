// Up next: when the device's autoplay setting is on and the video ends,
// count down and go to the next one. The end is heard from the player's
// position where it reports one (player.time), else guessed from the
// video's known duration.
const COUNTDOWN = 5;
const panel = document.querySelector('[data-up-next]');

function ready(fn) {
  // window.MT is the core's; every deferred module has run by DOMContentLoaded.
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

ready((MT) => {
  if (!panel || !MT) return;
  const toggle = panel.querySelector('[data-up-next-autoplay]');
  const box = panel.querySelector('[data-up-next-countdown]');
  const text = panel.querySelector('[data-up-next-text]');
  toggle.checked = MT.settings.read().autoplay;
  toggle.addEventListener('change', () => MT.settings.write({ autoplay: toggle.checked }));

  let timer = null;
  let started = false;
  let heard = false;
  const cancel = () => {
    clearInterval(timer);
    timer = null;
    box.hidden = true;
  };
  const start = () => {
    if (started || !MT.settings.read().autoplay) return;
    started = true;
    let left = COUNTDOWN;
    const show = () => { text.textContent = text.dataset.template.replace('{seconds}', String(left)); };
    box.hidden = false;
    show();
    timer = setInterval(() => {
      left -= 1;
      if (left <= 0) {
        clearInterval(timer);
        window.location.href = panel.dataset.upNext;
        return;
      }
      show();
    }, 1000);
  };
  panel.querySelector('[data-up-next-cancel]').addEventListener('click', cancel);

  MT.hooks.on('player.time', ({ seconds, duration }) => {
    heard = true;
    if (duration > 0 && seconds >= duration - 1) start();
  });
  // A player that reports nothing (an embed without a protocol): the known duration.
  const known = Number(panel.dataset.duration);
  if (known > 0) {
    setTimeout(() => { if (!heard) start(); }, (known + 2) * 1000);
  }
});
