# Aram's Tattoo — PHP 8.2 + Apache, for Railway (or any container host)
FROM php:8.2-apache

# gd     — re-encodes uploads, which is what makes them safe
# pdo_mysql — database access
RUN apt-get update && apt-get install -y --no-install-recommends \
        libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

# The .htaccess files are what block /includes and /database from the web,
# so Apache must be told to honour them.
RUN a2enmod rewrite headers \
    && printf '<Directory /var/www/html>\n\
  Options -Indexes +FollowSymLinks\n\
  AllowOverride All\n\
  Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/aram.conf \
    && a2enconf aram

# Sensible production PHP defaults
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'upload_max_filesize=8M\npost_max_size=10M\nexpose_php=Off\n' \
       > "$PHP_INI_DIR/conf.d/aram.ini"

COPY . /var/www/html/

# Uploads are the only place the app writes.
RUN mkdir -p /var/www/html/uploads/designs /var/www/html/uploads/previews \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 775 /var/www/html/uploads

# Railway injects PORT at runtime; Apache defaults to 80 locally.
CMD ["sh", "-c", "\
  PORT=${PORT:-80}; \
  sed -i \"s/^Listen 80$/Listen ${PORT}/\" /etc/apache2/ports.conf; \
  sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/\" /etc/apache2/sites-available/000-default.conf; \
  exec apache2-foreground"]
