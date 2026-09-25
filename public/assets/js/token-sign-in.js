// The token flow on /auth/login: the provider's own script (loaded from its
// CDN just before this module) signs the person in, and the JWT it produces
// goes to /auth/token, which verifies it and decides. Nothing here is
// trusted by the server; it only carries the token across.
import MT from './mt.js';

const mount = document.querySelector('[data-token-sign-in]');
const errorBox = document.querySelector('[data-token-error]');

function fail(message) {
  if (!errorBox) return;
  errorBox.textContent = message;
  errorBox.hidden = false;
}

async function handOver(token, config) {
  try {
    const result = await MT.api('/auth/token', { method: 'POST', body: { token, returnTo: config.returnTo, trial: config.trial } });
    window.location.href = result.redirect || MT.url('/');
  } catch (error) {
    // A refusal answers with where to go (the plain access-denied page).
    if (error.data && error.data.redirect) window.location.href = error.data.redirect;
    else fail(error.message);
  }
}

async function clerk(config) {
  if (!window.Clerk) throw new Error('Clerk didn’t load.');
  const instance = typeof window.Clerk === 'function' ? new window.Clerk(config.publishableKey) : window.Clerk;
  await instance.load();
  let sent = false;
  const send = async () => {
    if (sent || !instance.session) return;
    sent = true;
    const token = await instance.session.getToken(config.template ? { template: config.template } : undefined);
    if (token) handOver(token, config);
  };
  // Already signed in to Clerk in this browser: carry straight on.
  if (instance.session) return send();
  instance.addListener(() => send());
  instance.mountSignIn(mount, { routing: 'virtual' });
}

function firebaseForm(config, auth) {
  const form = document.createElement('form');
  form.className = 'stack';
  if (config.google) {
    const google = document.createElement('button');
    google.type = 'button';
    google.className = 'button primary';
    google.textContent = mount.dataset.googleLabel || 'Continue with Google';
    google.addEventListener('click', async () => {
      try {
        await auth.signInWithPopup(new window.firebase.auth.GoogleAuthProvider());
      } catch (error) {
        fail(error.message);
      }
    });
    form.append(google);
  }
  if (config.password) {
    const email = Object.assign(document.createElement('input'), { type: 'email', name: 'email', required: true, autocomplete: 'username', placeholder: 'you@example.org' });
    const password = Object.assign(document.createElement('input'), { type: 'password', name: 'password', required: true, autocomplete: 'current-password' });
    const submit = Object.assign(document.createElement('button'), { type: 'submit', className: 'button', textContent: mount.dataset.signInLabel || 'Sign in' });
    form.append(email, password, submit);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await auth.signInWithEmailAndPassword(email.value, password.value);
      } catch (error) {
        fail(error.code === 'auth/invalid-credential' ? 'That email and password don’t match.' : error.message);
      }
    });
  }
  mount.append(form);
}

async function firebase(config) {
  if (!window.firebase) throw new Error('Firebase didn’t load.');
  const app = window.firebase.apps.length ? window.firebase.app() : window.firebase.initializeApp(config.firebase);
  const auth = app.auth();
  let sent = false;
  auth.onAuthStateChanged(async (user) => {
    if (!user || sent) return;
    sent = true;
    handOver(await user.getIdToken(), config);
  });
  firebaseForm(config, auth);
}

if (mount) {
  let config = {};
  try { config = JSON.parse(mount.dataset.tokenSignIn || '{}'); } catch { /* malformed */ }
  const run = config.kind === 'clerk' ? clerk : config.kind === 'firebase' ? firebase : null;
  // The provider scripts are deferred ahead of this module, so they have run by now.
  run?.(config).catch((error) => fail(error.message));
}
