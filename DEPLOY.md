# Putting Aram Tattoo online

Everything here is plain PHP 8 + MySQL, so any normal shared host works —
Hostinger, Namecheap, SiteGround, cPanel, a VPS. No Node, no build step,
nothing to compile.

You need three things from your host: **FTP or file-manager access**, a
**MySQL database**, and **HTTPS** (nearly all give free Let's Encrypt).

---

## Before you upload — five things that must change

These are not optional. The first three would embarrass you in front of a real
client; the last two are security.

### 1 · Replace the fake contact details

`index.html` still carries the sample details I wrote:

| Currently says | Lines |
|---|---|
| `+1 (555) 123-4567` | 481, 546 |
| `hello@aramstattoo.com` | 483, 485 |
| `24 Ink Street, Downtown` | 493, 546 |
| `@arams.tattoo` | 489 |

Also the studio hours in the footer, the prices on the style cards, and six
social links still pointing at `href="#"`.

### 2 · Add your background photo

Drop it in `assets/img/`, then change one line near the top of
`assets/css/style.css`:

```css
--hero-photo:url("../img/hero.jpg");
```

### 3 · Turn debug off

In your live `includes/config.local.php`:

```php
'APP_DEBUG' => false,
```

Left on, a database error prints its details to whoever hit the page.

### 4 · Pick a real admin password

`studio-dev-2026` was a throwaway for local testing. Use something long, set it
as `SEED_ADMIN_PASSWORD`, run the seed once, then blank that line.

### 5 · Never upload your local config

`includes/config.local.php` on your Mac points at XAMPP's socket and holds the
dev password. The live server needs its own, written from
`config.local.example.php`.

---

## Uploading

**1 · Upload the files** to your web root (usually `public_html`).
The prepared zip already leaves out the files that must not travel.

**2 · Create the database** in your host's panel, then import `database/schema.sql`
through phpMyAdmin (Import tab) or:

```bash
mysql -u YOURUSER -p YOURDB < database/schema.sql
```

**3 · Write the config.** Copy `includes/config.local.example.php` to
`includes/config.local.php` and fill in the database name, user and password
your host gave you, plus:

```php
'APP_URL'   => 'https://yourdomain.com',
'APP_DEBUG' => false,
```

**4 · Create your admin login.** If your host gives SSH:

```bash
php database/seed.php
```

No SSH? Temporarily move `seed.php` into the web root, visit it once in the
browser, then delete it — it refuses to run over the web by default, so you'll
need to comment out the `PHP_SAPI !== 'cli'` guard at the top, and put it back
afterwards.

**5 · Make uploads writable.**

```bash
chmod -R 755 uploads
```

**6 · Turn on HTTPS** in your host's panel and force redirects. The Virtual
Camera will not open a camera without it — browsers refuse on plain http.

**7 · Set up mail.** `mail()` often lands in spam. Get an SMTP account (Brevo
has a free tier) and fill in the SMTP block in your config.

**8 · Nightly cleanup**, if your host has cron:

```
0 3 * * * php /home/YOU/public_html/database/cleanup.php
```

---

## After it's live — check these

- Front page loads over `https://`
- Submit a test booking, confirm it appears in `/admin/`
- Confirm the notification email arrives (check spam)
- Open the Virtual Camera on a **phone** and allow the camera
- Add one design in the admin, confirm it shows on the front page
- Visit `yourdomain.com/includes/config.local.php` — you should get
  **403 or a blank page**, never your database password
- Visit `yourdomain.com/database/schema.sql` — should be 403

That last pair matters. The `.htaccess` files block them on Apache. If your host
uses nginx, ask support to deny `/includes` and `/database` and to refuse
executing PHP inside `/uploads`.

---

## What I could not do for you

I have no hosting account or domain, and I will not create accounts or enter
payment details on your behalf — that has to be you. Once you have a host and
its FTP/database details, everything above is mechanical.
