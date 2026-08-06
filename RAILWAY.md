# Deploying to Railway

Railway suits this well: it builds the included `Dockerfile`, gives you a MySQL
database, and puts the site on an **https://** URL automatically — which the
Virtual Camera needs, since browsers refuse camera access over plain http.

Everything is prepared. You run the commands, because logging in and paying
are yours to do.

---

## One thing to know before you start

**Railway containers have a disposable filesystem.** Anything written inside
them is wiped on every redeploy. This app writes uploaded designs and camera
previews into `uploads/`, so without a volume, every client photo disappears
the next time you deploy.

Fix it in one step, in the Railway dashboard:

> Your service → **Variables/Settings → Volumes → New Volume**
> Mount path: `/var/www/html/uploads`

Do this before you take real bookings. For a throwaway test URL you can skip it
and accept that uploads vanish on redeploy.

---

## Steps

**1 · Log in** (opens your browser — this bit has to be you)

```bash
railway login
```

**2 · Create the project** from inside `aram-tattoo/`

```bash
railway init
```

**3 · Add MySQL** — in the dashboard, **New → Database → MySQL**.
Railway sets `MYSQLHOST`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD` and
`MYSQLPORT` for you. The app already reads those, so there is no config file
to write.

**4 · Set your own variables** — dashboard → Variables, or:

```bash
railway variables --set APP_DEBUG=false --set APP_TIMEZONE=Europe/Istanbul
railway variables --set STUDIO_EMAIL=you@yourdomain.com
railway variables --set SEED_ADMIN_EMAIL=you@yourdomain.com
railway variables --set SEED_ADMIN_PASSWORD='pick-a-long-one'
```

Leave `APP_URL` unset at first; set it once Railway gives you the domain:

```bash
railway variables --set APP_URL=https://your-app.up.railway.app
```

**5 · Deploy**

```bash
railway up
```

**6 · Create the tables and your login**

```bash
railway run php database/migrate.php
```

**7 · Clear the seed password** now the account exists

```bash
railway variables --set SEED_ADMIN_PASSWORD=
```

**8 · Open it**

```bash
railway domain     # generates the public URL if you have not got one
```

---

## Check it worked

- Front page over `https://`
- `/admin/login.php` — sign in with the email and password from step 4
- Submit a test booking, confirm it lands in the admin
- Open the site on your **phone** and try the Virtual Camera
- `/includes/config.local.php` should give **403**, never your password
- `/database/schema.sql` should give **403**

---

## Cost

Railway no longer has a genuinely free always-on tier. New accounts get trial
credit; after that the Hobby plan is around **$5/month** including usage, and
this app is small enough to sit inside that. The MySQL service counts toward
the same usage.

If you would rather not pay yet, **InfinityFree** and **000webhost** run
PHP + MySQL for free with ads and throttling — fine for testing on a phone,
not for real clients. They also give HTTPS, so the camera will work.

---

## Still to do regardless of host

The site currently shows the sample contact details I wrote —
`+1 (555) 123-4567`, `hello@aramstattoo.com`, `24 Ink Street, Downtown`, and six
social links pointing nowhere. Replace those in `index.html` before you send the
link to anyone real. `DEPLOY.md` lists the exact line numbers.
