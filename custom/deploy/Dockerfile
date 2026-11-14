FROM composer:2 AS composer

FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Asia/Ho_Chi_Minh

RUN apt-get update && apt-get install -y --no-install-recommends \
    apache2 \
    libapache2-mod-php8.3 \
    php8.3-cli \
    php8.3-sqlite3 \
    php8.3-gd \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-curl \
    php8.3-zip \
    python3 \
    python3-venv \
    sqlite3 \
    gphoto2 \
    cups-client \
    ca-certificates \
    curl \
    unzip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV PATH="/opt/photobooth-python/bin:${PATH}"

WORKDIR /var/www/photobooth

COPY custom/composer.json custom/composer.lock /var/www/photobooth/custom/
RUN composer install --working-dir=custom --no-dev --no-interaction --prefer-dist --no-scripts
COPY custom/requirements.lock /tmp/photobooth-requirements.lock
RUN python3 -m venv /opt/photobooth-python \
    && pip install --no-cache-dir -r /tmp/photobooth-requirements.lock

COPY . /var/www/photobooth

RUN mkdir -p /var/lib/photobooth-operator \
    && chown -R www-data:www-data /var/lib/photobooth-operator /var/www/photobooth/custom

COPY custom/deploy/apache-operator.conf /etc/apache2/sites-available/000-default.conf
COPY custom/deploy/apache-guest.conf /etc/apache2/sites-available/guest.conf

RUN a2ensite guest \
    && apache2ctl configtest

ENTRYPOINT ["bash", "/var/www/photobooth/custom/deploy/entrypoint.sh"]
EXPOSE 8080 8081

CMD ["apache2ctl", "-D", "FOREGROUND"]

