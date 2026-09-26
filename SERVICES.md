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
The right-hand column is each provider's own `limits()` sentence — the same
words the Services screen shows — and `tools/ci/check-docs.php` fails the
build if this document and the code drift apart.

## Sign-in

| Provider | Id | What it can't do, and what to know before you pick it |
|---|---|---|
| **Local accounts (password, magic link)** | `local` | Members manage one more password. Magic links and password resets need email to be set up. |
| **OpenID Connect (Google, Microsoft, Apple, Okta, Keycloak…)** | `oidc` | Needs HTTPS on this site and outbound HTTPS. Every preset is this one provider with its details filled in. |
| **Auth0** | `auth0` | Needs HTTPS on this site and outbound HTTPS. Organizations, the guest link and the Pre-User-Registration Action work as before. |
| **Clerk** | `clerk` | Needs HTTPS and the DNS records Clerk asks for (production instances). For the address, add a JWT template named “marine-team” with {"email": "{{user.primary_email_address}}", "email_verified": "{{user.email_verified}}"} — or leave it out and the secret key is used to look the address up. |
| **Supabase Auth** | `supabase` | Needs HTTPS. Add /auth/supabase/callback on this site to the project’s redirect URLs. For magic links, the email template may link to /auth/supabase/verify?token_hash={{ .TokenHash }}&type=magiclink; the default PKCE link works too. |
| **Firebase Authentication** | `firebase` | Needs HTTPS. Add this site’s domain to Firebase → Authentication → Settings → Authorized domains. |

One provider is active at a time, and members survive a switch: an account is
matched by the identity a provider gives, and by an address that provider has
**verified**. An unverified address never attaches to an existing member —
and that refusal is indistinguishable from any other, on purpose, because a
message saying "that address is already here" is a way of asking whether it
is.

Local accounts stay available for administrators whatever the primary
provider is — it is the recovery path (see INSTALL.md, *Locked out*).

## Email

| Provider | Id | What it can't do, and what to know before you pick it |
|---|---|---|
| **Not set up** | `none` | Nothing is sent. Password resets and magic links are unavailable; an administrator sets passwords instead. |
| **PHP mail() — the host’s own mail server** | `mail` | Delivery depends entirely on the host; messages often land in spam unless the domain’s SPF and DKIM include the host’s servers. |
| **SMTP** | `smtp` | Many shared hosts block outbound ports 25, 465 and 587 to other servers; if the test cannot connect, use the host’s own mail server or an HTTPS API provider. |
| **Resend** | `resend` | Needs outbound HTTPS from this host. The "send as" address must be on a domain verified in Resend. |
| **Mailgun** | `mailgun` | Needs outbound HTTPS. The sending domain must be verified in Mailgun. |
| **SendGrid** | `sendgrid` | Needs outbound HTTPS. The "send as" address must be a verified sender or on an authenticated domain. |
| **Postmark** | `postmark` | Needs outbound HTTPS. The "send as" address must be a confirmed sender signature or on a verified domain. |
| **Amazon SES** | `ses` | Needs outbound HTTPS. While the account is in the SES sandbox it can only send to verified addresses. |
| **Brevo** | `brevo` | Needs outbound HTTPS. The "send as" address must be a verified sender in Brevo. |
| **Microsoft 365 (Graph)** | `graph` | Needs outbound HTTPS. Sends as the mailbox named in "Send as"; the app needs the Mail.Send application permission. |

Every send writes an email-log row (to, subject, provider, status, the
provider's message id or error). Messages that carry a one-time link (resets,
sign-in links) are logged without their body, so they can't be resent and the
log never holds a working link.

Whichever you pick, the domain you send from needs SPF and DKIM records that
name the sender, or a good part of what you send is filed as spam by people
who never see it. The test sends a real message and, for `mail()`, asks you to
type back a code from it — because `mail()` returning true proves nothing
about delivery.

## Files

| Provider | Id | What it can't do, and what to know before you pick it |
|---|---|---|
| **This host’s own disk** | `local` | Every download passes through PHP and counts against the hosting plan’s disk and bandwidth. |
| **Bunny Storage** | `bunny` | Needs outbound HTTPS. Turn on token authentication for the pull zone and add its key here: without it every file is proxied through this site, using its bandwidth. |

Members-only files are enforced either way: on local disk every download goes
through the app, which checks access first (and uses X-Sendfile or
X-Accel-Redirect where the host offers it, so the bytes still need not pass
through PHP); on Bunny, token authentication makes each link signed and
short-lived. Without that token key, Bunny files are proxied through this site
instead — which works, and defeats the point of paying for a CDN.

## Video

| Provider | Id | What it can't do, and what to know before you pick it |
|---|---|---|
| **bunny.net Stream** | `bunny` | A paid service (cheap). Downloads and casting need “MP4 fallback” turned on for the library, which only applies to videos uploaded after it was switched on. |
| **This host’s own disk** | `local` | For a church with a few videos: they use the hosting plan’s disk and bandwidth, and members-only ones pass through PHP. No transcoding — upload an MP4 browsers can play. |
| **S3-compatible storage (R2, B2, Wasabi, AWS)** | `s3` | No transcoding: upload an MP4 that browsers can play (H.264/AAC). The bucket’s CORS must allow this site to PUT and to read the ETag header. Egress pricing varies — R2’s is free. |
| **YouTube** | `youtube` | No MP4 for downloads or casting, and "unlisted" is a secret, not a lock: anyone with the link can watch. The default API quota allows about six uploads a day, and an app Google hasn’t verified has its uploads set to private. |
| **Vimeo** | `vimeo` | Keeps a members-only video from strangers only partly: a domain-restricted embed on paid plans. File links for downloads and casting need a paid plan; the free tier uploads 500 MB a week. |
| **Dropbox shared link** | `dropbox` | The link itself is the credential: anyone who has it can watch, members-only or not. Dropbox pauses links that pass its daily bandwidth (20 GB a day on Basic, 200 GB on paid plans). The file must already be an H.264/AAC MP4. |
| **Google Drive shared link** | `gdrive` | Drive is not a video host: a much-watched file passes its download quota and is refused for a day. The link is the credential — anyone with it can watch. |
| **OneDrive / SharePoint shared link** | `onedrive` | The link is the credential, so members-only can only hide the page here. Microsoft throttles files that are watched a lot, and a tenant can forbid anonymous links. |
| **Internet Archive** | `archive` | Everything on the Archive is public, so "members only" can only hide the page here, never the file. Playback speed varies. |
| **Direct link** | `direct` | Anybody with the address can watch it; the site can’t make a members-only video private. It plays as well as whatever hosts the file. |

The provider decides which player fills the frame, and each video row
remembers its own, so a switch changes where *new* videos go rather than
rewriting what is already there. A video whose provider cannot do something —
a download, captions, an automatic transcript — says so on the screen rather
than failing when somebody taps it.

One thing is worth saying plainly, because it surprises people: for the shared
link and direct providers, **the link is the credential**. Marking such a
video members-only hides the page on this site; it does nothing to anybody who
has the address. Only Bunny Stream and the host's own disk can actually keep a
video from a stranger.

## Text messages

Off by default; nothing is texted until a member gives their own number and
agrees to it. Consent is tracked per member, and every provider here honours a
STOP reply whether or not the network does it too — the same number may be
here under another provider tomorrow.

| Provider | Id | What it can't do, and what to know before you pick it |
|---|---|---|
| **Twilio** | `twilio` | Needs outbound HTTPS. US and Canadian numbers need a registered campaign (A2P 10DLC) before they will deliver. Twilio answers STOP itself on those numbers; this app honours the reply as well, because the same number may be here under another provider tomorrow. |
| **Vonage** | `vonage` | Needs outbound HTTPS. An alphanumeric sender ID works in much of Europe and not in the US; Vonage rejects the message rather than silently swapping it. |
| **MessageBird (Bird)** | `messagebird` | Needs outbound HTTPS. Sender IDs must be registered in most countries; MessageBird substitutes a number where they are not allowed. |
| **Plivo** | `plivo` | Needs outbound HTTPS. US and Canadian numbers need a registered campaign before they will deliver. |
| **Sinch** | `sinch` | Needs outbound HTTPS. The region must match the one the service plan was created in, or Sinch answers 404 rather than routing it. |
| **Telnyx** | `telnyx` | Needs outbound HTTPS. Checking its signed callbacks needs PHP’s sodium extension, which nearly every host has; without it the callbacks are refused rather than trusted. |
| **Amazon SNS** | `sns` | Needs outbound HTTPS. SNS has no delivery receipts or replies without wiring up CloudWatch and a topic, so a broadcast says "sent" rather than "reached". A new account is in the SMS sandbox, where only verified numbers receive anything. |
| **ClickSend** | `clicksend` | Needs outbound HTTPS. Its callbacks are not signed, so this app gives it a secret in the callback address instead. |
| **Textlocal** | `textlocal` | Needs outbound HTTPS. The sender name must be one registered in the Textlocal account; its callbacks are not signed, so this app gives it a secret in the callback address instead. |
| **BulkSMS** | `bulksms` | Needs outbound HTTPS. Its callbacks are not signed, so this app gives it a secret in the callback address instead. |
| **JSON webhook** | `webhook` | Posts {"to","from","body"} to an address of yours and treats any 2xx as accepted. It cannot report delivery or receive replies unless your gateway calls this site back; it will not reach a private or loopback address, so a gateway on the same machine needs a public name. |

Two things differ between them and are worth checking before you commit: what
the provider can tell you about **delivery** (several can only say
"accepted"), and whether its callbacks are **signed**. Where they are not,
this app gives the provider a secret in the callback address instead, which is
weaker, and is said out loud on the settings screen rather than hidden.

## Adding a provider

A provider is one class: it implements `App\Services\ServiceProvider` and the
slot's interface (`EmailProvider`, `FilesProvider`, `AuthProvider`, …),
declares its settings form in `configSchema()`, says what it cannot do in
`limits()`, and fails its `test()` with a sentence a volunteer can act on.
Register it in `App\Services\Registry` (the core's) or from a plugin whose
header says `Provides: <slot>`:

```php
$hooks->on('services.providers', fn ($registry) => $registry->register(MailjetProvider::class));
```

The admin screens read nothing but the registry, so a new provider appears
with nothing else changed. Add its row to the table above — CI fails until you
do — and recorded HTTP fixtures for its test and main calls, so the suite
covers it without an account.
