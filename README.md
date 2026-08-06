# Aram's Tattoo — website

Front end is plain HTML/CSS/JS. Back end is PHP 8 + MySQL with no framework and
no Composer — it runs on any cheap shared host.

## Setting up the back end

**1 · Create the database**

```bash
mysql -u root -p -e "CREATE DATABASE arams_tattoo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p arams_tattoo < database/schema.sql
```

**2 · Add your settings**

```bash
cp includes/config.local.example.php includes/config.local.php
```

Open that file and fill in the database details, `APP_URL`, `STUDIO_EMAIL` and a
strong `SEED_ADMIN_PASSWORD`. It is git-ignored and blocked from the web.

**3 · Create your admin login**

```bash
php database/seed.php
```

Then clear `SEED_ADMIN_PASSWORD` from the config. Log in at `/admin/login.php`.

**4 · Make the uploads folder writable**

```bash
chmod -R 755 uploads
```

**5 · Optional but recommended — nightly housekeeping**

```
0 3 * * * php /path/to/aram-tattoo/database/cleanup.php
```

Deletes camera previews nobody attached to a booking, and old login records.

### Going live — do not skip

- Set `APP_DEBUG => false`. Leaving it on shows database errors to visitors.
- Serve over **HTTPS**. The Virtual Camera cannot open a camera without it, and
  the admin session cookie is only marked secure on HTTPS.
- Point `APP_URL` at your real domain.
- Make sure `.htaccess` files are respected (Apache `AllowOverride All`). On
  nginx, add equivalent rules denying `/includes`, `/database`, and script
  execution inside `/uploads`.

## Running it locally

```bash
php -S localhost:8790
```

Opening `index.html` as a plain file also works, but the booking form, gallery
and camera upload need PHP, so use the server above.

## Adding your background photo

1. Drop the image into `assets/img/` (e.g. `hero.jpg`).
2. Open `assets/css/style.css` and change one line near the top:

```css
--hero-photo:url("../img/hero.jpg");
```

Until you do, a generated placeholder (`hero-placeholder.svg`) is used. The hero
already sits under a dark navy veil and a gradient fallback, so a bright or busy
photo will still keep the white text readable.

## Colours

All in `:root` at the top of `style.css` — navy `--navy-*`, baby blue `--baby-*`.
Change those variables and the whole site follows.

## Structure

- `index.html` — main page, sections `#home #designs #camera #book #contact`
- `camera.html` — the Virtual Camera tool
- `assets/css/style.css` — shared styling · `camera.css` — camera page only
- `assets/js/main.js` — navbar, mobile menu, scroll reveal, counters, particles, forms
- `assets/js/camera.js` — the camera tool

Asset URLs carry a `?v=2` query. Bump it when you edit the CSS or JS so browsers
pick the change up instead of serving a cached copy.

## Body map

On the main page, under **Put it where it goes**. The client picks a spot on a
front/back figure, uploads a design, and sees it sitting on that part.

**The figure is an SVG built from paths in `index.html`.** Each body part is
defined once in `<defs>` and mirrored with a transform, so there is one copy of
each shape to edit rather than two. Parts overlap and carry no stroke — outlining
them individually draws every internal seam and turns the body into a jointed
mannequin. An outline appears only on the part being pointed at.

**The design clips to the chosen part.** Selecting a region clones it into
`<clipPath id="inkClip">`, and the design image is clipped to it and multiplied
onto the skin, so ink cannot spill past the arm you picked. It uses the same
white-paper removal as the Virtual Camera — both call `assets/js/ink.js`.

**Adding or renaming a region:** each part is a `<use>` with `data-region`
(a stable key) and `data-label` (what the client and the artist see). Sides are
labelled anatomically — on the front view the figure's own right arm is on the
viewer's left, and the back view swaps them.

**Sending it:** "Send with my booking" flattens the SVG to a PNG, uploads it
through the same endpoint the camera uses, and carries the body part across in
`sessionStorage`. The booking then stores it in `bookings.placement`, and it
shows in the admin list, the detail page and the studio email.

> If you change the body colours in `bodymap.css`, change them in the inlined
> `<style>` inside `renderToPng()` in `bodymap.js` too. External CSS does not
> travel with a serialised SVG, so the export has its own copy.

## Virtual Camera

The client uploads a design, points the camera at the spot, and sizes it on their skin.

**How it works.** The camera frame and the design are drawn into one `<canvas>`,
so the photo they save is exactly what they saw. Line art is usually black on
white paper, so the tool converts brightness into transparency — the paper
disappears, the lines stay — then tints what's left with the chosen ink and
blends it onto the skin with `multiply` (`screen` for white ink).

**Controls.** Drag to move, pinch or scroll to resize, two fingers to rotate;
sliders for size, rotation and strength; five ink colours; front/rear camera;
shutter, and a save/share sheet.

**Real sizes.** "Set real size in cm" asks them to hold a bank card against the
spot and match an on-screen outline. A card is a fixed 8.56 cm, so that one
match converts screen pixels to centimetres and the size readout switches from
a percentage to cm. It's accurate at the distance they calibrated at.

**Camera access needs HTTPS.** Browsers only hand over a camera on `https://` or
`localhost`. On a plain `http://` domain the tool says so and offers the fallback
below — so put the site behind HTTPS before launch.

**No camera? No problem.** "Use a photo instead" loads a still photo of the spot
and everything else works identically. Handy on desktop, and the fallback when
permission is denied.

Nothing leaves the device — there is no upload, no recording, no server call.

## Back end map

| Path | What it does |
|---|---|
| `api/book.php` | Receives the booking form, saves it, emails you and the client |
| `api/subscribe.php` | Newsletter sign-ups |
| `api/designs.php` | Published portfolio, read by the main page |
| `api/upload-preview.php` | Stores a Virtual Camera snapshot, returns a token |
| `admin/index.php` | Bookings list, search, status |
| `admin/booking.php` | One booking: detail, private notes, camera preview |
| `admin/designs.php` | Add, edit, hide, reorder and delete portfolio pieces |
| `admin/subscribers.php` | Mailing list, with CSV export |
| `includes/` | config, database, helpers, auth, mailer — blocked from the web |
| `database/` | `schema.sql`, `seed.php`, `cleanup.php` |

### How the camera preview reaches a booking

The client is on the camera page before the booking exists, so the snapshot is
uploaded first and comes back with a random token. That token is held in
`sessionStorage`, the booking form sends it along, and the server links the two.
A token only works once and only while unclaimed, so nobody can attach someone
else's photo to their own booking.

### Sending email

`MAIL_TRANSPORT => 'mail'` uses PHP's built-in `mail()`. It works on most shared
hosting but often lands in spam. For delivery that actually arrives, switch to
`'smtp'` and fill in the SMTP block — the built-in client handles STARTTLS and
works with Gmail, Brevo, Mailgun and most host SMTP.

Mail failures are logged and never lose a booking: the request is saved first,
and the emails are sent afterwards inside their own error handling.

### Security notes

- Every query uses prepared statements with `EMULATE_PREPARES` off, so values
  are never interpolated into SQL.
- All output is escaped with `e()`.
- Admin forms carry CSRF tokens; the session cookie is `HttpOnly` + `SameSite=Lax`.
- Login is throttled to 8 failed attempts per IP per 15 minutes, and compares a
  hash even for unknown accounts so timing can't reveal which emails exist.
- Bookings are rate limited to 5 per IP per hour; both forms have a honeypot.
- **Uploads are decoded and re-encoded rather than stored as sent.** A file with
  code appended to a valid image loses it — only real pixels survive. Uploads
  are also stored under random names and the folder refuses to execute anything.

## Not wired up yet

- Contact details, prices, hours and social links in `index.html` are still
  sample content — edit them directly.
- The newsletter stores addresses but nothing sends to that list yet; export the
  CSV and use your mail tool.
- There is no password-reset flow. To change the admin password, set a new
  `SEED_ADMIN_PASSWORD` and re-run `php database/seed.php`.

## Notes

- Responsive down to ~320px; the navbar collapses to a slide-in menu at 860px.
- Honours `prefers-reduced-motion` — all animation is disabled for users who ask.
- `backdrop-filter` is applied only to the sticky navbar and only behind an
  `@supports` guard; it has a solid fallback background. Stacking more blurred
  layers caused compositing artifacts and hurts scrolling on phones.
- Scroll-reveal is gated on the `js` class, so the page still shows all its
  content if JavaScript fails to load.
