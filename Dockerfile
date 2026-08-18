# syntax=docker/dockerfile:1.7

# FrankenPHP 2 doesn't exist yet -- latest stable is on the 1.x line. Floating on
# 1 still gets patch releases on the current PHP 8.5 build.
ARG FRANKENPHP_VERSION=1
ARG PHP_VERSION=8.5

# ---- base: OS packages + PHP extensions FrankenPHP needs to run this app.
# Shared by both stages below and cached as one layer across deploys -- it only
# rebuilds when this file changes, not when application code changes. That is what
# makes the 3-5 minute cold build a one-time cost rather than a per-deploy tax.
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS base

WORKDIR /app

# Derived by scanning composer.lock for every `ext-*` required by ANY package, not
# just mediary's own composer.json require block -- that is always an undercount
# (mediary declares 8, dependencies pull in 18 more). Everything omitted here is
# already compiled into the base image (ctype, dom, filter, iconv, json, libxml,
# mbstring, pcre, pdo, phar, simplexml, tokenizer, xml, xmlwriter, zlib).
#
# Two that are easy to get wrong:
#   redis  - mediary does NOT use Redis (cache is APCu, transports are doctrine://),
#            but a dependency declares `ext-redis`, so composer install fails on
#            platform requirements without it. Required to build, not to run.
#   imagick- the expensive one in this list, ahead of intl. mediary is an image
#            pipeline; this is not optional.
RUN install-php-extensions \
        apcu \
        exif \
        gd \
        imagick \
        intl \
        opcache \
        pdo_pgsql \
        pdo_sqlite \
        redis \
        zip

COPY Caddyfile /etc/caddy/Caddyfile
COPY docker/php.ini $PHP_INI_DIR/conf.d/app.ini

# ---- build: composer + asset-mapper. Needs the full toolchain (composer, dev deps
# for autoload discovery) but none of it ships in the final image. No Node/npm stage
# -- asset-mapper compiles importmap-managed assets directly, unlike Encore.
FROM base AS build

RUN install-php-extensions @composer

# Everything in this stage runs as root (no USER switch). Composer detects that and
# silently disables all plugins in --no-interaction mode unless told otherwise --
# including symfony/runtime's plugin, which generates the bootstrap glue bin/console
# needs. Without this, bin/console fails with "Symfony Runtime is missing" right
# after dump-autoload, even though `composer install` itself reports success.
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=prod APP_DEBUG=0

COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.cache/composer \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

# Order matters, and two of these are silent when missing:
#
#   cache:clear BEFORE asset-map:compile -- survos/js-twig-bundle's
#   FosRoutingCacheWarmer generates var/js_twig_bundle/generated/fos_routes.js, and
#   asset-map:compile fails without it already on disk.
#
#   assets:install is NOT optional. composer's auto-scripts normally run it, but
#   --no-scripts above skips them, and public/bundles/ is gitignored so it is absent
#   from the build context dokku pushes. Without this the image ships no
#   public/bundles/ at all and every bundle asset 404s -- EasyAdmin's, api-platform's,
#   tabler's. AssetMapper output (public/assets/) is unaffected, which is what makes
#   it confusing: /assets/* serves fine while /bundles/* is gone.
#
#   memory_limit=-1 on these, or the deploy OOMs during container warmup.
RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
    && php -d memory_limit=-1 bin/console cache:clear --env=prod --no-debug \
    && php -d memory_limit=-1 bin/console assets:install public --env=prod \
    && php -d memory_limit=-1 bin/console importmap:install --env=prod \
    && php -d memory_limit=-1 bin/console asset-map:compile --env=prod

# ---- prod: base image + the built app. No composer, no .git, no build-time cache.
FROM base AS prod

ENV APP_ENV=prod APP_DEBUG=0

# --chown during the copy (one pass) instead of a separate RUN chown -R walking the
# whole tree afterward (two passes) -- meaningfully faster with var/cache warmed.
COPY --from=build --chown=www-data:www-data /app /app

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
