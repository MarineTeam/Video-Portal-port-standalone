// Cast to a television (src/components/cast-button.tsx). The original loaded
// Google's Cast Web Sender SDK; this uses the browser's own Remote Playback
// API instead, so no third-party script runs in the site's origin and the CSP
// needs nothing added. Chrome offers Chromecast through it and Safari offers
// AirPlay, and where the page has no media element of its own the signed MP4
// already built for Downloads becomes one, off-screen.
//
// mt.js is imported where it is used rather than at the top, so the decisions
// below can be tested outside a browser.

/** Whether this browser can cast at all: no API, no button. */
export function canCast(media) {
  return Boolean(media && media.remote && typeof media.remote.prompt === 'function');
}

/**
 * What to cast. The page's own <video> is preferred — it is already at the
 * member's position — and an iframe player has nothing a receiver could take,
 * so the MP4 behind the Download button stands in for it.
 */
export function castSource(nativeVideo) {
  return nativeVideo ? { kind: 'element' } : { kind: 'mp4' };
}

/**
 * What to tell the member after a failed cast, or null to say nothing:
 * closing the picker without choosing a television is not a problem, and
 * neither is a cast the browser itself called off.
 */
export function castError(error) {
  if (!error || error.name === 'NotAllowedError' || error.name === 'AbortError') {
    return null;
  }
  return error.message || 'No television answered.';
}

if (typeof document !== 'undefined') {
  for (const button of document.querySelectorAll('[data-cast-video]')) {
    const id = button.dataset.castVideo;
    const status = document.querySelector('[data-cast-status]');
    let media = document.querySelector('[data-player] video');
    if (!canCast(media || document.createElement('video'))) continue;

    // Shown once a receiver is actually within reach, the way a cast button
    // behaves elsewhere; a browser that won't say is taken at its word and the
    // button is offered.
    if (media) {
      try {
        media.remote.watchAvailability((available) => { button.hidden = !available; }).catch(() => { button.hidden = false; });
      } catch { button.hidden = false; }
    } else {
      button.hidden = false;
    }

    button.addEventListener('click', async () => {
      button.disabled = true;
      if (status) status.hidden = true;
      try {
        if (castSource(media).kind === 'mp4') {
          // The same endpoint, the same gate, the same reasons: a refusal here
          // is the one the member should read.
          const { default: MT } = await import('./mt.js');
          const info = await MT.api(`/api/downloads/${encodeURIComponent(id)}`);
          media = document.createElement('video');
          media.src = info.url;
          media.crossOrigin = 'anonymous';
          media.hidden = true;
          document.body.append(media);
        }
        await media.remote.prompt();
        await media.play();
      } catch (error) {
        const message = castError(error);
        if (message === null) {
          // nothing to say
        } else if (status) {
          status.textContent = message;
          status.hidden = false;
        } else {
          window.alert(message);
        }
      } finally {
        button.disabled = false;
      }
    });
  }
}
