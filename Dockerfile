FROM php:8.4-apache-trixie

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libavif-dev libjpeg62-turbo-dev libpng-dev libpq-dev libwebp-dev libzip-dev \
        mariadb-client postgresql-client-17 \
    && docker-php-ext-configure gd --with-avif --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql pdo_pgsql zip \
    && a2enmod rewrite \
    && printf '%s\n' 'upload_max_filesize=50M' 'post_max_size=64M' > "$PHP_INI_DIR/conf.d/kraftink.ini" \
    && rm -rf /var/lib/apt/lists/*

COPY docker/site.conf /etc/apache2/sites-available/000-default.conf
COPY --chown=www-data:www-data . /var/www/html

WORKDIR /var/www/html

RUN test -f vendor/autoload.php \
    && test -f public/build/manifest.json \
    && test -f public/vendor/tinymce/tinymce.min.js \
    && install -d -o www-data -g www-data \
        bootstrap/cache database extensions/installed extensions/installed/games extensions/installed/plugins \
        extensions/installed/themes storage storage/app storage/app/private storage/framework storage/framework/cache \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown www-data:www-data /var/www/html \
    && chmod 0755 /var/www/html \
    && apache2ctl configtest
