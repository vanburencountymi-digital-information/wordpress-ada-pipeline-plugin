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

# uid 1000 matches the default first-user uid on most Linux dev hosts (including this
# WSL2 setup) — running as this user instead of root means composer install's writes
# into the bind-mounted vendor/ land back on the host already owned by you, no --user
# override needed on every `docker compose run` (see dice-document-pipeline-api's
# Dockerfile for the same reasoning, applied there to its app user).
RUN addgroup -g 1000 app && adduser -D -u 1000 -G app app
USER app
ENV COMPOSER_HOME=/home/app/.composer

WORKDIR /app
