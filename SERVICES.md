# Services

A **service** is a slot the site depends on — sign-in, video, email, files,
text messages. A **provider** is one way of filling it. Admin → Services
(`/admin/providers`) shows each slot, its active provider, when it was set and
by whom, and every provider it could switch to.

Switching always goes: choose a provider → fill in its settings → **Test** →
**Switch**. The switch is refused unless the test passed in that same
submission: a pass is signed, lasts fifteen minutes, and is bound to the exact
settings that were tested, so changing a field means testing again. Secrets
(passwords, API keys) are write-only: the form shows *set* and never echoes
them, and they are stored encrypted with the key in `storage/config.php`.

Two sentences the Services screen says, both true: **if your host blocks
outbound HTTPS, SMTP is usually the email service that works; if it blocks the
SMTP ports (25, 465, 587) instead, the HTTPS API ones are.** The screen shows
whether this host can reach the internet over HTTPS beside every provider
that needs it.

Every table below says what a provider can't do as plainly as what it can.
*Status* is where the port has got to; see `docs/PORT_MAP.md`.

## Sign-in

| Provider | Flow | Membership claim | Needs HTTPS | Status |
|---|---|---|---|---|
| Local accounts (password, magic link) | form | none — the allowlist decides | no | **available** |
| Auth0 | redirect (PKCE) | `org_id` | yes | planned (step 3) |
| OpenID Connect, with presets for Google, Microsoft Entra ID, Apple, Okta, Keycloak, Authentik, Zitadel, Logto, Kinde, Clerk | redirect (PKCE) | configurable (`hd`, `tid`, `groups`, `org_id`) | yes | planned (step 3) |
| Clerk (native) | token | `org_id` | yes | planned (step 3) |
| Supabase Auth | form + redirect + token | none | yes | planned (step 3) |
| Firebase Authentication | token | none | yes | planned (step 3) |

Local accounts stay available for administrators whatever the primary
provider is — it is the recovery path (see INSTALL.md, *Locked out*).

## Email

| Provider | Talks over | Test before switching | Can't |
|---|---|---|---|
| Not set up | — | — | send anything: messages are recorded at Admin → Email log, not sent; password resets need an administrator |
| SMTP (native client; presets for this host's server, Google Workspace, Microsoft 365, Zoho, Fastmail, and the Mailgun, SendGrid, Postmark, Amazon SES, Brevo and SMTP2GO relays) | 25 / 465 / 587 | connect, STARTTLS or TLS, EHLO, AUTH, then a message to you | reach anything when the host blocks those ports |
| PHP `mail()` | the host's own mail server | the function exists, then a message carrying a six-digit code you type back — `mail()` returning true proves nothing about delivery | say whether a message arrived; often lands in spam without SPF/DKIM for the host |
| Resend | HTTPS | the API key is accepted, the "send as" domain is verified, then a message to you | work when the host blocks outbound HTTPS |
| Mailgun, SendGrid, Postmark, Amazon SES, Brevo, Microsoft 365 via Graph | HTTPS | per provider, as in the brief | — | planned (step 3) |

Every send writes an email-log row (to, subject, provider, status, the
provider's message id or error). Messages that carry a one-time link (resets,
sign-in links) are logged without their body, so they can't be resent and the
log never holds a working link.

## Files

| Provider | Where the bytes are | Members-only files | Can't |
|---|---|---|---|
| This host's own disk | `storage/uploads/`, outside the web root | enforced: every download goes through the app, which checks access | save bandwidth: every byte passes through PHP (unless the host supports X-Sendfile / X-Accel-Redirect, which the site uses when configured) |
| Bunny Storage | a Bunny storage zone behind a pull zone | enforced with token authentication (signed, ten-minute redirects) | — | planned (step 3) |

## Video

Planned for step 3: bunny.net Stream, YouTube, Vimeo, Dropbox, Google Drive,
OneDrive/SharePoint, Internet Archive, S3-compatible storage, a direct link,
and the host's own disk, each with the capabilities table from the brief.

## Text messages

Off by default; nothing is texted until a member gives their own number and
agrees. Providers (Twilio, Vonage, MessageBird/Bird, Plivo, Sinch, Telnyx,
Amazon SNS, ClickSend, Textlocal, BulkSMS, a JSON webhook) arrive with the
broadcasts plugin in step 5.

## Adding a provider

A provider is one class: it implements `App\Services\ServiceProvider` and the
slot's interface (`EmailProvider`, `FilesProvider`, `AuthProvider`, …),
declares its settings form in `configSchema()`, and fails its `test()` with a
sentence a volunteer can act on. Register it in `App\Services\Registry` (the
core's) or from a plugin whose header says `Provides: <slot>`:

```php
$hooks->on('services.providers', fn ($registry) => $registry->register(MailjetProvider::class));
```

The admin screens read nothing but the registry, so a new provider appears
with nothing else changed. Add its row to the table above, and recorded HTTP
fixtures for its test and main calls so CI covers it without an account.
