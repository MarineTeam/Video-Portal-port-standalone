// The device's own share sheet, where the browser offers one.
for (const button of document.querySelectorAll('[data-native-share]')) {
  if (!navigator.share) continue;
  button.hidden = false;
  button.addEventListener('click', () => {
    navigator.share({ url: button.dataset.nativeShare, title: button.dataset.shareTitle }).catch(() => { /* dismissed */ });
  });
}
