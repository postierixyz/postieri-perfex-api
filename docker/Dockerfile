# syntax=docker/dockerfile:1.6
#
# Perfex 3.4.1 + Postieri API + Apache.
# Self-contained: module is baked into the image, no host volumes needed.
# Suitable for Coolify (Dockerfile deploy) or docker-compose.

FROM php:8.2-apache

# System dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libicu-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        libonig-dev \
        unzip \
        git \
        cron \
        default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions required by Perfex + Postieri API
RUN docker-php-ext-install -j$(nproc) \
        mysqli \
        pdo_mysql \
        zip \
        intl \
        xml \
        curl \
        bcmath \
        mbstring \
        opcache

# Apache rewrite + headers
RUN a2enmod rewrite headers
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides (Perfex installer / pretty URLs)
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/perfex-overrides.conf \
    && a2enconf perfex-overrides

# PHP production-ish ini
RUN { \
        echo 'memory_limit = 256M'; \
        echo 'upload_max_filesize = 50M'; \
        echo 'post_max_size = 50M'; \
        echo 'max_execution_time = 120'; \
        echo 'date.timezone = UTC'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 128'; \
        echo 'opcache.interned_strings_buffer = 8'; \
    } > /usr/local/etc/php/conf.d/perfex.ini

# Install Perfex 3.4.1
WORKDIR /var/www/html
RUN git clone --depth 1 --branch 3.4.1 https://github.com/PerfexCRM/PerfexCRM.git . \
    && rm -rf .git \
    && mkdir -p application/config \
    && chown -R www-data:www-data /var/www/html

# Install Composer (for module dependencies if any)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Bake the Postieri API module into the image
COPY modules/postieri_api /var/www/html/modules/postieri_api
RUN chown -R www-data:www-data /var/www/html/modules/postieri_api

# Cron for Perfex (subscriptions + webhooks)
COPY docker/crontab /etc/cron.d/perfex-cron
RUN chmod 0644 /etc/cron.d/perfex-cron && crontab -u www-data /etc/cron.d/perfex-cron

# Entrypoint: write DB config, run installer if empty DB
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
