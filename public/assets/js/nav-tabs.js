// The bottom bar's per-device choice (lib/nav-tabs.ts): a stored list of
// hrefs resolved against the destinations this viewer may currently see, so a
// destination that disappears drops out instead of leading nowhere.

export const NAV_TABS_SNAPSHOT_KEY = 'marine-nav-tabs';
export const MAX_TABS = 10;
export const TABS_ACROSS = 5;

// null means "never chosen", which differs from an empty choice.
export function parseTabHrefs(raw) {
  if (!Array.isArray(raw)) return null;
  const seen = new Set();
  const out = [];
  for (const href of raw) {
    if (typeof href !== 'string' || !href.startsWith('/') || seen.has(href)) continue;
    seen.add(href);
    out.push(href);
    if (out.length >= MAX_TABS) break;
  }
  return out;
}

// suggested: the app's own default tabs; options: every destination this
// viewer could choose, with its label, icon and badge.
export function resolveTabs(stored, suggested, options) {
  const chosen = parseTabHrefs(stored);
  if (chosen === null) return suggested;
  const byHref = new Map(options.map((o) => [o.href, o]));
  const resolved = chosen.map((href) => byHref.get(href)).filter(Boolean);
  // An installed app with an empty bar has no way to get anywhere.
  return resolved.length > 0 ? resolved : suggested;
}

// Only what the offline shell can draw.
export function toSnapshot(tabs) {
  return tabs.map((t) => ({ href: t.href, label: t.label, icon: t.icon }));
}
