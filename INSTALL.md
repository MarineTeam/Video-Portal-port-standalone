# Installing Marine Team

This is written for somebody with a hosting account, a web browser and an
FTP program or their host's file manager — no command line needed, now or
later.

## What your hosting needs

- **PHP 8.2, 8.3 or 8.4.** Most control panels let you pick the version per
  site (cPanel: *Select PHP Version* or *MultiPHP Manager*).
- **PHP extensions:** `pdo_mysql`, `mbstring`, `json`, `openssl`, `ctype`,
  `fileinfo`. Nice to have: `curl`, `zip` (install plugins and updates from a
  zip), `sodium` (check an update's signature), `gd`
  (clean up uploaded images), `intl`, and `bcmath` or `gmp` (push
  notifications). The installer checks all of these and says what's missing.
- **A MySQL 8.0+ or MariaDB 10.6+ database** and a user with all privileges on
  it. It can be shared with other sites: every table name starts with a prefix
  you choose (`mt_` by default).
- **Apache or LiteSpeed** with `.htaccess` files allowed (almost every shared
  host). nginx works too — see below.

HTTPS is not needed to start. Local sign-in, video and email all work over
plain HTTP while you wait for a certificate; external sign-in providers
(Auth0, Google, Microsoft…) switch on once the site is reached over HTTPS.

## Step by step

1. **Create the database.** In your control panel (cPanel → *MySQL
   Databases*), create a database, create a user with a strong password, and
   add the user to the database with **all privileges**. Write down all three
   names and the password.

2. **Upload the files.** Unzip `marine-team-<version>.zip` on your computer
   and upload the contents to your site's folder. Make sure the hidden
   `.htaccess` files go too — some FTP programs hide them; turn on "show hidden
   files".
   - Best: point the domain's document root at the `public/` folder (cPanel →
     *Domains* → edit the domain). Then nothing outside `public/` is ever
     reachable from the web.
   - If you can't change the document root, upload everything into the site
     folder as it is. The `.htaccess` at the top sends every request into
     `public/`, and every other folder refuses direct access.
   - To install in a subfolder (`example.org/church/`), upload into that
     subfolder. Every link the site makes is built from where it is installed.

3. **Make `storage/` writable.** In the file manager, set the `storage` folder's
   permissions to 755 (or 775 if 755 doesn't work). The installer checks this.

4. **Open the site in your browser.** You'll see *Welcome* and a request for
   the install key. Open `storage/install.key` in your file manager, copy its
   one line, and paste it in. (This proves the person installing can reach the
   site's files — a new site is reachable by anyone who guesses its address.)

5. **Follow the wizard.**
   - *Check this host* lists what your hosting has. Anything marked ✗ must be
     fixed first; each says what to ask your host for. The installer also
     checks that `storage/` can't be downloaded from the web and refuses to
     continue if it can.
   - *Database* — the details from step 1. The table prefix can stay `mt_`.
   - *Setting up the database* runs by itself, one step at a time. If it
     stops, reload the page: it carries on where it stopped.
   - *Your site* — the name, colours, language, time zone, and **the first
     administrator's email and password**. Keep that password: this local
     account always works, whatever you set up later.
   - *Services* explains what is set up (local sign-in, files on this host)
     and what is not yet (email, video, text messages).
   - *Almost done* shows a **cron line** — see the next section.

6. **Sign in** with the administrator account. You land on the admin
   dashboard, which lists anything still to do.

## The cron line (scheduled jobs)

Reminders, digests and the nightly clean-up need something to wake them. In
your control panel (cPanel → *Cron Jobs*), add a job that runs **every five
minutes** with the command the installer showed you:

    curl -fsS "https://your-site/cron/run?token=…" >/dev/null

No `curl` on your host? Use `wget -q -O /dev/null "https://your-site/cron/run?token=…"`.
With shell access, `php /path/to/site/bin/cron.php` does the same.

Keep that address private — it is what lets the jobs run. You can see it again
at **Admin → Scheduled jobs**.

If you don't add it, jobs still run, but only when somebody visits the site
(at most once a minute). On a quiet site that can make a reminder late.

## First things to set up after installing

1. **Email** — Admin → Services → Email. Until it is set up, nothing is sent:
   no notifications, no password resets. The quickest is usually *SMTP* with
   the preset *This host's own mail server*. Every choice is tested before it
   is switched on.
2. **Who can sign in** — Admin → Who can sign in. Add the addresses of the
   people who should have access.
3. **Plugins** — Admin → Plugins. Every feature past the library is a plugin.

## nginx

nginx ignores `.htaccess`, so its configuration has to say the same things.
With the document root at `public/`:

```nginx
server {
    root /var/www/marine-team/public;
    index index.php;

    # Everything that isn't a real file goes to the front controller.
    location / {
        try_files $uri /index.php$is_args$args;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    # No other PHP file is ever executed.
    location ~ \.php$ { return 404; }

    # Hidden files are never served.
    location ~ /\. { deny all; }

    # Optional: let nginx send stored files instead of PHP
    # (set 'file_offload' => 'x-accel' in storage/config.php).
    location /protected-storage/ {
        internal;
        alias /var/www/marine-team/storage/;
    }
}
```

If the document root has to be the project folder instead of `public/`, add
`location ~ ^/(app|storage|plugins|themes|install|bin|tests|tools|vendor)/ { deny all; }`
— but pointing the root at `public/` is much better.

## Locked out

**Forgot the administrator password, and email isn't set up?** Create an empty
file named `enable-local-login` inside `storage/` with your file manager. On
the next request, local password sign-in is switched back on for
administrator accounts, whatever sign-in provider is configured, and a banner
says so on every admin page. Sign in, fix what needs fixing, then **delete the
file**.

**Lost the administrator password, and email isn't set up?** With
`storage/enable-local-login` in place, open `/auth/recover` on your site. The
site has written a code to `storage/recovery.key`; paste it in with the
administrator's email and a new password. The code works once. Only somebody
who can open the site's files can read it, which is the same proof the
installer asked for.

**A plugin broke the site?** It shouldn't be able to: a plugin that fails
while loading is switched off automatically, and Admin → Plugins and Admin →
Logs never load third-party plugins, so they are always reachable. If a
plugin is somehow still in the way, delete its folder from `plugins/` with
FTP.

**A theme broke the site?** Same: a theme that fails is switched back to the
default automatically. Deleting its folder from `themes/` also works.

## Updating to a new version

Either way, your settings, uploads, installed plugins and themes are kept, and
the site shows visitors a maintenance page only while the update runs.

**With a release zip (hosts with the zip extension).** Admin → Update →
*Upload a release*. The zip's signature is checked against the key built into
the site before anything is unpacked — a zip that wasn't built by the
maintainers, or was changed after, is refused. Press *Continue*: the files
are unpacked and every one checked, the old files are moved aside and the new
ones moved in, then the database is brought up to date one step at a time.
Until the last step, *Roll back the files* puts the previous version back.

**By FTP.** Admin → Update → *Turn on maintenance mode* (you keep using the
site; visitors see the maintenance page). Unzip the release on your computer
and upload everything in it over the old files — except `storage/`, which is
yours. Then open Admin → Update and press *Finish the update*. Until you do,
visitors keep seeing the maintenance page: the site notices that its files
are newer than its database and waits for you.

If a step fails, the page says why; fix that and press the button again — it
carries on from where it stopped.

## Where things are kept

- `storage/config.php` — the database password and the key every stored
  secret is encrypted with. Back it up somewhere safe; without it, saved
  passwords for email and other services can't be read.
- `storage/uploads/` — uploaded files (when files are stored on this host).
- `storage/logs/` — readable at Admin → Logs.

Backups of the database can be downloaded from Admin → Backup & import.
