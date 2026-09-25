// The video player: one module, two kinds. "native" is this site's own
// <video> (with hls.js where the browser can't play HLS itself); "iframe" is
// the provider's player, spoken to over its postMessage protocol where it has
// one (YouTube, Vimeo, Bunny's Player.js) so the page hears the real position
// and can seek. No provider script is loaded into this page.
//
//   <div data-player='{"kind":"native","sources":[…],"videoId":"…","start":90}' data-progress></div>
//   <button data-seek="125">2:05</button>
//
// data-progress (signed-in viewers) turns on the watch-progress heartbeat.
// Plugins can listen: MT.hooks.on('player.time', ({ videoId, seconds, duration }) => …).
import MT from './mt.js';

const HEARTBEAT_MS = 15000;
const COMPLETE_AT = 0.9;

function parse(data) {
  if (typeof data !== 'string') return data;
  try { return JSON.parse(data); } catch { return null; }
}

let hlsLoading;
function loadHls() {
  hlsLoading ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = MT.url('/vendor-js/hls/hls.light.min.js');
    script.onload = () => resolve(window.Hls);
    script.onerror = () => reject(new Error('hls.js failed to load'));
    document.head.append(script);
  });
  return hlsLoading;
}

// Each adapter: { seek(seconds), setRate(rate) } and calls onTime(seconds, duration).
function nativeAdapter(el, spec, settings, onTime) {
  const video = document.createElement('video');
  video.controls = true;
  video.playsInline = true;
  video.preload = 'metadata';
  if (spec.poster) video.poster = spec.poster;
  if (settings.autoplay) video.autoplay = true;
  for (const t of spec.tracks || []) {
    const track = document.createElement('track');
    Object.assign(track, { kind: 'subtitles', src: t.src, srclang: t.srclang, label: t.label });
    video.append(track);
  }
  const first = (spec.sources || [])[0];
  const nativeHls = video.canPlayType('application/vnd.apple.mpegurl') !== '';
  if (spec.hls && first && !nativeHls) {
    loadHls().then((Hls) => {
      if (Hls && Hls.isSupported()) {
        const hls = new Hls();
        hls.loadSource(first.src);
        hls.attachMedia(video);
      } else {
        video.src = first.src;
      }
    }).catch(() => { video.src = first.src; });
  } else {
    for (const s of spec.sources || []) {
      const source = document.createElement('source');
      source.src = s.src;
      if (s.type) source.type = s.type;
      video.append(source);
    }
  }
  video.addEventListener('loadedmetadata', () => {
    if (spec.start > 0 && video.currentTime < 1) video.currentTime = spec.start;
    video.playbackRate = settings.playbackSpeed || 1;
  }, { once: true });
  video.addEventListener('timeupdate', () => onTime(video.currentTime, Number.isFinite(video.duration) ? video.duration : null, !video.paused));
  el.append(video);
  return {
    element: video,
    seek(t) { video.currentTime = t; video.play().catch(() => {}); },
    setRate(r) { video.playbackRate = r; },
  };
}

function iframeAdapter(el, spec, settings, onTime) {
  const frame = document.createElement('iframe');
  frame.src = spec.src;
  frame.allow = 'autoplay; fullscreen; picture-in-picture; encrypted-media';
  frame.allowFullscreen = true;
  frame.referrerPolicy = 'strict-origin-when-cross-origin';
  frame.title = el.dataset.title || 'Video player';
  el.append(frame);
  const origin = (() => { try { return new URL(spec.src).origin; } catch { return '*'; } })();
  const post = (message) => frame.contentWindow?.postMessage(spec.protocol === 'youtube' ? JSON.stringify(message) : message, origin);

  const protocols = {
    youtube: {
      start() { post({ event: 'listening', id: 1, channel: 'widget' }); },
      receive(m) {
        if (m.event === 'infoDelivery' && m.info && typeof m.info.currentTime === 'number') {
          onTime(m.info.currentTime, m.info.duration || null, m.info.playerState === 1);
        }
        if (m.event === 'onReady' && settings.playbackSpeed !== 1) this.setRate(settings.playbackSpeed);
      },
      seek(t) { post({ event: 'command', func: 'seekTo', args: [t, true] }); },
      setRate(r) { post({ event: 'command', func: 'setPlaybackRate', args: [r] }); },
    },
    vimeo: {
      start() {},
      receive(m) {
        if (m.event === 'ready') {
          post({ method: 'addEventListener', value: 'timeupdate' });
          if (settings.playbackSpeed !== 1) this.setRate(settings.playbackSpeed);
        }
        if (m.event === 'timeupdate' && m.data) onTime(m.data.seconds, m.data.duration || null, true);
      },
      seek(t) { post({ method: 'setCurrentTime', value: t }); post({ method: 'play' }); },
      setRate(r) { post({ method: 'setPlaybackRate', value: r }); },
    },
    playerjs: {
      start() {},
      receive(m) {
        if (m.context !== 'player.js') return;
        if (m.event === 'ready') post({ context: 'player.js', version: '0.0.11', method: 'addEventListener', value: 'timeupdate', listener: 'mt' });
        if (m.event === 'timeupdate' && m.value) onTime(m.value.seconds, m.value.duration || null, true);
      },
      seek(t) { post({ context: 'player.js', version: '0.0.11', method: 'setCurrentTime', value: t }); post({ context: 'player.js', version: '0.0.11', method: 'play' }); },
      setRate() {},
    },
  };
  const protocol = protocols[spec.protocol];
  if (protocol) {
    window.addEventListener('message', (event) => {
      if (event.source !== frame.contentWindow) return;
      const m = parse(event.data);
      if (m && typeof m === 'object') protocol.receive(m);
    });
    frame.addEventListener('load', () => protocol.start());
  }
  return {
    element: frame,
    seek(t) {
      if (protocol) protocol.seek(t);
    },
    setRate(r) { protocol?.setRate(r); },
  };
}

export function mount(el) {
  if (el.mtPlayer) return el.mtPlayer;
  let spec;
  try { spec = JSON.parse(el.dataset.player || '{}'); } catch { return null; }
  const settings = MT.settings.read();
  let position = spec.start || 0;
  let duration = spec.duration || null;
  let heard = false; // whether the player has reported a real position
  let playing = false;

  const onTime = (seconds, total, isPlaying) => {
    if (typeof seconds !== 'number' || !Number.isFinite(seconds)) return;
    heard = true;
    position = seconds;
    if (total) duration = total;
    playing = isPlaying;
    MT.hooks.do('player.time', { videoId: spec.videoId, seconds, duration });
  };
  el.textContent = '';
  const adapter = spec.kind === 'iframe' ? iframeAdapter(el, spec, settings, onTime) : nativeAdapter(el, spec, settings, onTime);

  if ('progress' in el.dataset && spec.videoId) {
    // Where no position is heard, approximate it from time the page was visible.
    let last = Date.now();
    const tick = () => {
      const now = Date.now();
      if (!heard && !document.hidden) position += (now - last) / 1000;
      last = now;
    };
    const send = (keepalive = false) => {
      tick();
      const seconds = Math.floor(position);
      if (seconds <= 0) return;
      const completed = Boolean(duration && seconds >= duration * COMPLETE_AT);
      MT.api('/api/watch-progress', { method: 'POST', keepalive, body: { videoId: spec.videoId, positionSeconds: seconds, completed } }).catch(() => {});
    };
    setInterval(() => { if (!heard || playing) send(); else tick(); }, HEARTBEAT_MS);
    // Leaving, or putting the tab away: say where playback got to.
    window.addEventListener('pagehide', () => send(true));
    document.addEventListener('visibilitychange', () => { if (document.hidden) send(true); });
  }

  const api = {
    spec,
    element: adapter.element,
    seek(t) {
      position = t;
      adapter.seek(t);
      el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    },
    setRate: (r) => adapter.setRate(r),
    position: () => position,
  };
  el.mtPlayer = api;
  MT.hooks.do('player.ready', api);
  return api;
}

for (const el of document.querySelectorAll('[data-player]')) mount(el);

// Chapter rows and timestamps: <button data-seek="125">. Seeks the page's
// first player, or the one data-seek-target names.
document.addEventListener('click', (event) => {
  const button = event.target.closest?.('[data-seek]');
  if (!button) return;
  const target = button.dataset.seekTarget ? document.getElementById(button.dataset.seekTarget) : document.querySelector('[data-player]');
  const player = target?.mtPlayer;
  const t = Number(button.dataset.seek);
  if (!player || !Number.isFinite(t)) return;
  event.preventDefault();
  player.seek(t);
});
