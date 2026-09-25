// A dismissed announcement stays hidden for the rest of this browser session.
const KEY = 'marine-announcement-dismissed';
const banner = document.querySelector('[data-announcement]');

function dismissed() {
  try { return window.sessionStorage.getItem(KEY); } catch { return null; }
}

if (banner) {
  if (dismissed() === banner.dataset.announcement) banner.remove();
  banner.querySelector('[data-announcement-dismiss]')?.addEventListener('click', () => {
    try { window.sessionStorage.setItem(KEY, banner.dataset.announcement); } catch { /* storage blocked: hide for this page */ }
    banner.remove();
  });
}
