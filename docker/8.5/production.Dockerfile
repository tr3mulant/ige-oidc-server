FROM ubuntu:24.04 AS build

ARG NODE_VERSION=24

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC

RUN apt-get update \
    && apt-get install -y --no-install-recommends gnupg curl ca-certificates git unzip \
    && mkdir -p /etc/apt/keyrings \
    && curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb \
    && dpkg -i /tmp/debsuryorg-archive-keyring.deb \
    && echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ noble main" \
       > /etc/apt/sources.list.d/php.list \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
       | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_$NODE_VERSION.x nodistro main" \
       > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.5-cli php8.5-pgsql php8.5-mbstring php8.5-xml php8.5-zip \
        php8.5-bcmath php8.5-curl php8.5-intl php8.5-gd php8.5-readline \
        nodejs \
    && curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/*

WORKDIR /src

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan package:discover --ansi

RUN npm run build

RUN rm -rf node_modules storage/logs/* storage/framework/cache/data/*

FROM ubuntu:24.04 AS runtime

ARG WWWUSER=1000
ARG WWWGROUP=1000
ARG USERNAME=ige-oidc

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV USERNAME=${USERNAME}

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apt-get update \
    && apt-get install -y --no-install-recommends gnupg curl ca-certificates unzip \
       supervisor rsync gosu apache2 cron \
    && mkdir -p /etc/apt/keyrings \
    && curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb \
    && dpkg -i /tmp/debsuryorg-archive-keyring.deb \
    && echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ noble main" \
       > /etc/apt/sources.list.d/php.list \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
       | gpg --dearmor -o /etc/apt/keyrings/pgdg.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/pgdg.gpg] https://apt.postgresql.org/pub/repos/apt noble-pgdg main" \
       > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.5-cli \
        php8.5-fpm \
        php8.5-pgsql php8.5-mbstring php8.5-xml php8.5-zip \
        php8.5-bcmath php8.5-curl php8.5-intl php8.5-gd php8.5-readline \
        postgresql-client-18 \
    && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN if getent passwd ${WWWUSER} >/dev/null; then \
        userdel -r "$(getent passwd ${WWWUSER} | cut -d: -f1)" 2>/dev/null || true; \
    fi \
    && if getent group ${WWWGROUP} >/dev/null; then \
        groupdel "$(getent group ${WWWGROUP} | cut -d: -f1)" 2>/dev/null || true; \
    fi \
    && groupadd --force -g ${WWWGROUP} ${USERNAME} \
    && useradd -ms /bin/bash --no-user-group -g ${WWWGROUP} -u ${WWWUSER} ${USERNAME} \
    && usermod -a -G ${WWWGROUP} www-data

RUN rm -rf /var/www/html

COPY --from=build --chown=${WWWUSER}:${WWWGROUP} /src /var/www/html

WORKDIR /var/www/html

RUN mkdir -p \
        /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/framework/testing \
        /var/www/html/storage/logs \
        /var/www/html/storage/app/public \
        /var/www/html/storage/app/private \
        /var/www/html/bootstrap/cache

RUN mkdir -p /opt/ige-oidc \
    && cp -a /var/www/html/storage /opt/ige-oidc/storage

COPY docker/apache/production.vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/8.5/production.php.ini /etc/php/8.5/fpm/conf.d/99-ige-oidc.ini
COPY docker/8.5/production.php.ini /etc/php/8.5/cli/conf.d/99-ige-oidc.ini
COPY docker/8.5/production.supervisord.conf /etc/supervisor/conf.d/supervisord.conf

COPY docker/8.5/production.fpm.conf /etc/php/8.5/fpm/php-fpm.conf
RUN rm -f /etc/php/8.5/fpm/pool.d/www.conf \
    && sed -i "s/@APP_USER@/${USERNAME}/g" /etc/php/8.5/fpm/php-fpm.conf \
    && grep -E '^(user|group)[[:space:]]*=' /etc/php/8.5/fpm/php-fpm.conf \
    && mkdir -p /run/php

RUN printf '%s\n' \
        'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' \
        '' \
        "* * * * * ${USERNAME} cd /var/www/html && /usr/bin/php artisan schedule:run >> /var/www/html/storage/logs/schedule.log 2>&1" \
        > /etc/cron.d/ige-oidc-scheduler \
    && chmod 0644 /etc/cron.d/ige-oidc-scheduler \
    && chown root:root /etc/cron.d/ige-oidc-scheduler

COPY --chmod=0755 docker/8.5/production.start-container /usr/local/bin/start-container
COPY --chmod=0755 docker/8.5/production.healthcheck.sh /usr/local/bin/healthcheck

RUN a2enmod rewrite headers expires setenvif proxy proxy_fcgi \
    && mkdir -p /var/log/supervisor \
    && chown -R ${WWWUSER}:${WWWGROUP} /var/www/html/storage /var/www/html/bootstrap/cache \
    && chown -R ${WWWUSER}:${WWWGROUP} /opt/ige-oidc \
    && chmod -R g+rwX /var/www/html/storage /var/www/html/bootstrap/cache /opt/ige-oidc/storage

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD /usr/local/bin/healthcheck || exit 1

ENTRYPOINT ["start-container"]
