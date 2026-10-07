FROM php:8.4-cli

# Keep in sync with the schema version pinned at the top of mago.toml.
ARG MAGO_VERSION=1.40.2

RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip \
        git \
        curl \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install opcache

# pcov, not Xdebug: this is coverage-only, and pcov is lighter and faster for that one job.
RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/*

RUN git config --system --add safe.directory '*'

RUN curl -fsSL "https://github.com/carthage-software/mago/releases/download/${MAGO_VERSION}/mago-${MAGO_VERSION}-x86_64-unknown-linux-gnu.tar.gz" \
        | tar -xz -C /usr/local/bin --strip-components=1 "mago-${MAGO_VERSION}-x86_64-unknown-linux-gnu/mago"

COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY pcov.ini /usr/local/etc/php/conf.d/zzz-pcov.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /experiments/switchyard
