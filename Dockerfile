# Dev/CI only — this image never gets deployed. Real installs just drop the plugin's
# PHP files into wp-content/plugins on a site that already has its own PHP runtime.
# This exists purely to pin the interpreter version tests run against.
#
# Pinned to 8.3 to match county prod (8.3.8) — the actual first deployment target —
# rather than composer.json's ">=7.4" floor, which is a broader compatibility claim,
# not a tested one.
FROM php:8.3-cli-alpine

# xml/dom aren't compiled into the base image by default; mbstring needs oniguruma.
# All three are required by phpunit/phpunit itself, not by anything plugin-specific.
# Runtime libs (libxml2, oniguruma) are installed as their own top-level packages so
# the final `apk del` — which drops the -dev/build toolchain only — doesn't pull them
# out from under the extensions as orphaned dependencies.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libxml2-dev oniguruma-dev \
    && docker-php-ext-install mbstring xml dom \
    && apk add --no-cache libxml2 oniguruma \
    && apk del .build-deps

# Composer's own image ships a known-good build — copying its binary avoids running
# the upstream install script inside our image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# No baked-in user/uid here on purpose: a fixed uid (e.g. 1000) matches a dev's own
# host but not CI's runner uid, which broke `composer install` in the bind-mounted
# /app ("vendor does not exist and could not be created") the first time this ran on
# GitHub Actions. Instead, the Makefile passes --user "$(id -u):$(id -g)" on every
# `docker compose run`, so the container always runs as whoever actually invoked
# `make` — dev or CI — and writes into the bind mount land back correctly owned
# either way. COMPOSER_HOME is set to a location any uid can write to, since an
# arbitrary --user has no /etc/passwd entry (and so no HOME) to derive one from.
ENV COMPOSER_HOME=/tmp/composer-home

WORKDIR /app
