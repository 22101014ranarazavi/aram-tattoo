#!/usr/bin/env bash
#
# One-shot LAMP setup for Aram's Tattoo on a fresh Ubuntu 22.04/24.04 server
# (a DigitalOcean droplet, or any VPS).
#
#   ssh root@YOUR_IP
#   bash provision.sh yourdomain.com you@youremail.com
#
# Leave the arguments off for an IP-only setup with no HTTPS — fine for a
# first look, but the Virtual Camera will not work without HTTPS.
#
set -euo pipefail

DOMAIN="${1:-}"
EMAIL="${2:-}"
APP_DIR=/var/www/arams-tattoo
DB_NAME=arams_tattoo
DB_USER=arams

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

[[ $EUID -eq 0 ]] || { echo "Run as root."; exit 1; }

say "Installing Apache, PHP 8 and MySQL"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
    apache2 mysql-server \
    php php-mysql php-gd php-mbstring php-xml libapache2-mod-php \
    unzip curl >/dev/null

say "PHP $(php -r 'echo PHP_VERSION;') installed"
php -r 'exit(version_compare(PHP_VERSION, "8.1", ">=") ? 0 : 1);' \
    || { echo "PHP 8.1+ required — this app uses the 'never' return type."; exit 1; }

say "Enabling the Apache modules the app needs"
a2enmod rewrite headers >/dev/null

say "Creating the database"
DB_PASS="$(openssl rand -hex 16)"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

say "Setting up the site at ${APP_DIR}"
mkdir -p "$APP_DIR"
if [[ ! -f "$APP_DIR/index.html" ]]; then
    echo "Now upload the site into ${APP_DIR}, e.g. from your Mac:"
    echo "  rsync -av --exclude includes/config.local.php \\"
    echo "        ~/Desktop/turkish/aram-tattoo/ root@SERVER:${APP_DIR}/"
fi

cat > /etc/apache2/sites-available/arams.conf <<CONF
<VirtualHost *:80>
    ServerName ${DOMAIN:-_default_}
    DocumentRoot ${APP_DIR}

    <Directory ${APP_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/arams-error.log
    CustomLog \${APACHE_LOG_DIR}/arams-access.log combined
</VirtualHost>
CONF

a2dissite 000-default >/dev/null 2>&1 || true
a2ensite arams >/dev/null
systemctl reload apache2

say "Writing the config"
APP_URL="http://${DOMAIN:-$(curl -s -4 ifconfig.me || echo localhost)}"
[[ -n "$DOMAIN" ]] && APP_URL="https://${DOMAIN}"

install -d -m 750 "$APP_DIR/includes"
cat > "$APP_DIR/includes/config.local.php" <<PHPCONF
<?php
return [
    'APP_URL'      => '${APP_URL}',
    'APP_DEBUG'    => false,
    'APP_TIMEZONE' => 'Europe/Istanbul',

    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => '${DB_NAME}',
    'DB_USER' => '${DB_USER}',
    'DB_PASS' => '${DB_PASS}',

    'STUDIO_EMAIL' => '${EMAIL:-you@example.com}',

    // Set these once you have an SMTP account (Brevo's free tier works).
    // Server mail() usually lands in spam.
    'MAIL_TRANSPORT' => 'mail',
    'SMTP_HOST' => '', 'SMTP_PORT' => 587,
    'SMTP_USER' => '', 'SMTP_PASS' => '',

    // Used once by migrate.php, then blank it.
    'SEED_ADMIN_EMAIL'    => '${EMAIL:-you@example.com}',
    'SEED_ADMIN_PASSWORD' => '$(openssl rand -hex 9)',
    'SEED_ADMIN_NAME'     => 'Aram',
];
PHPCONF

say "Permissions"
chown -R www-data:www-data "$APP_DIR"
chmod 640 "$APP_DIR/includes/config.local.php"
mkdir -p "$APP_DIR/uploads/designs" "$APP_DIR/uploads/previews"
chmod -R 775 "$APP_DIR/uploads"

if [[ -f "$APP_DIR/database/migrate.php" ]]; then
    say "Creating tables and the admin account"
    (cd "$APP_DIR" && php database/migrate.php)
fi

say "Firewall"
ufw allow OpenSSH >/dev/null 2>&1 || true
ufw allow 'Apache Full' >/dev/null 2>&1 || true
ufw --force enable >/dev/null 2>&1 || true

if [[ -n "$DOMAIN" && -n "$EMAIL" ]]; then
    say "HTTPS via Let's Encrypt (required for the Virtual Camera)"
    apt-get install -y -qq certbot python3-certbot-apache >/dev/null
    certbot --apache -d "$DOMAIN" -m "$EMAIL" --agree-tos -n --redirect \
        || echo "certbot failed — check the domain's DNS points at this server, then rerun it."
fi

say "Done"
echo "  Site:  ${APP_URL}"
echo "  Admin: ${APP_URL}/admin/login.php"
echo
echo "  The admin password is in ${APP_DIR}/includes/config.local.php"
echo "  (SEED_ADMIN_PASSWORD). Sign in, then blank that line."
echo
[[ -z "$DOMAIN" ]] && echo "  NOTE: no domain given, so there is no HTTPS yet and the"
[[ -z "$DOMAIN" ]] && echo "        Virtual Camera will refuse to open a camera."
