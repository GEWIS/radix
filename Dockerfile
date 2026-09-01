#syntax=docker/dockerfile:1
ARG PHP_VERSION=8.5
ARG FRANKENPHP_VERSION=1.12

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS frankenphp_upstream

# The IP databases device recognition and security notices read, baked in so the file is always present: a first
# start never waits on their host being up. The entrypoint seeds the `data` volume from these (the volume mounts
# over /app/data, so they must live outside it) and app:user:update-ip-databases keeps the volume copy fresh, from
# MaxMind's GeoLite editions where a deployment has credentials. Always IPLocate here: GeoLite downloads are capped
# per day and need a key, which a build must not embed. Pinned to a commit so the layer caches; IPLocate rebuilds
# daily, and an unpinned URL would fetch some ninety megabytes on every build. The pin only ages this seed.
ARG IPLOCATE_REVISION=8709782118044da2208506dc8f161af34d6dc7e0
FROM scratch AS radix_geoip
ARG IPLOCATE_REVISION
ADD https://media.githubusercontent.com/media/iplocate/ip-address-databases/${IPLOCATE_REVISION}/ip-to-asn/ip-to-asn.mmdb /ip-to-asn.mmdb
ADD https://media.githubusercontent.com/media/iplocate/ip-address-databases/${IPLOCATE_REVISION}/ip-to-country/ip-to-country.mmdb /ip-to-location.mmdb

# Radix Base Image
FROM frankenphp_upstream AS radix_app_base

# Prevents having to use `set -eux` in every RUN command
SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app
VOLUME /app/data/
VOLUME /app/var/

# The `pcntl` extension is required for Symfony Messenger to perform graceful shutdowns. Both database stacks are
# present: `pdo_pgsql` for the ledger and `pdo_mysql` for everything else. `gmp` is what `brick/math` detects for the
# big-integer arithmetic behind the external-application RSA signatures; it falls back to a pure-PHP calculator when
# neither `gmp` nor `bcmath` is loaded, which takes seconds per RS512 token and minutes per PS512 one.
RUN <<-EOF
    apt-get update
    apt-get install -y --no-install-recommends \
        ca-certificates \
        file \
        fonts-dejavu-core \
        git \
        libicu-dev \
        libvips42t64 \
        poppler-utils
    install-php-extensions \
        @composer \
        amqp \
        apcu \
        calendar \
        exif \
        ffi \
        gd \
        gmp \
        intl \
        maxminddb \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        redis \
        zip
    rm -rf /var/lib/apt/lists/*
EOF

# Builders for AssetMapper. The binaries are arch-specific, so select them based on the build target (set by BuildKit)
# to avoid pulling linux-x64 binaries that only run through (failing) emulation on arm64 hosts (e.g. Apple Silicon).
ARG TARGETARCH
ARG SASS_VERSION=1.103.1
ARG SWC_VERSION=v1.16.1

RUN <<-EOF
    case "$TARGETARCH" in
        amd64) SASS_ARCH=linux-x64; SWC_ARCH=linux-x64-gnu ;;
        arm64) SASS_ARCH=linux-arm64; SWC_ARCH=linux-arm64-gnu ;;
        *) echo "Unsupported architecture: ${TARGETARCH}" >&2; exit 1 ;;
    esac
    curl -OL --no-progress-meter "https://github.com/sass/dart-sass/releases/download/${SASS_VERSION}/dart-sass-${SASS_VERSION}-${SASS_ARCH}.tar.gz"
    tar -xzf "dart-sass-${SASS_VERSION}-${SASS_ARCH}.tar.gz" -C /usr/local/bin --strip-components=1
    rm -f "dart-sass-${SASS_VERSION}-${SASS_ARCH}.tar.gz"
    curl -OL --no-progress-meter "https://github.com/swc-project/swc/releases/download/${SWC_VERSION}/swc-${SWC_ARCH}"
    mv "swc-${SWC_ARCH}" /usr/local/bin/swc
    chmod +x /usr/local/bin/swc
EOF

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

###> recipes ###
###> doctrine/doctrine-bundle ###
###< doctrine/doctrine-bundle ###
###< recipes ###

COPY --link docker/app/frankenphp/conf.d/10-radix.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 docker/app/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link docker/app/healthcheck.php /usr/local/bin/healthcheck.php
COPY --link docker/app/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

# The timeout allows for both connection timeouts /health may sit through before it reports what is unreachable.
HEALTHCHECK --start-period=300s --interval=30s --timeout=25s CMD php /usr/local/bin/healthcheck.php
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# Radix Development Image (local)
FROM radix_app_base AS radix_app_development

# Match the host user's UID/GID so files written through the bind mount are not root-owned.
ARG USER_UID=1000
ARG USER_GID=1000

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
ENV FRANKENPHP_WORKER_CONFIG=watch

RUN <<-EOF
    mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
    install-php-extensions xdebug
    # On macOS `id -g` returns 20 (staff), which already exists in the base image; reuse the existing group in that case
    # instead of failing on a duplicate GID.
    if ! getent group "$USER_GID" >/dev/null; then
        groupadd -g "$USER_GID" nonroot
    fi
    useradd -m -u "$USER_UID" -g "$USER_GID" -s /bin/bash nonroot
    chown -R "$USER_UID:$USER_GID" /data/caddy /config/caddy
    git config --system --add safe.directory /app
EOF

COPY --link docker/app/frankenphp/conf.d/20-radix.dev.ini $PHP_INI_DIR/app.conf.d/

ARG GIT_COMMIT
ENV GIT_COMMIT=${GIT_COMMIT}

USER nonroot

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# Radix Production Builder
FROM radix_app_base AS radix_app_builder

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --link docker/app/frankenphp/conf.d/20-radix.prod.ini $PHP_INI_DIR/app.conf.d/

COPY --link composer.* symfony.* ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

COPY --link --exclude=docker/ . ./

RUN <<-EOF
    # opcache refuses to start on a `file_cache` directory it cannot stat, so var/opcache is created rather than
    # left to the workers that use it.
    mkdir -p var/cache var/log var/share var/opcache
    composer dump-autoload --classmap-authoritative --no-dev
    composer dump-env prod
    # `post-install-cmd` is inlined rather than run: the `cache:clear` in its `auto-scripts` runs the optional cache
    # warmers, and Doctrine's instantiates both entity managers, which resolves DATABASE_DSN, WEB_DATABASE_DSN and
    # VALKEY_DSN against a build that has none of those services. The entrypoint warms them at startup instead, where
    # the variables do exist.
    php bin/console cache:clear --no-optional-warmers
    php bin/console assets:install public
    composer run-script --no-dev assets:update
    php bin/console sass:build
    php bin/console importmap:install
    php bin/console asset-map:compile
    chmod +x bin/console
    chmod -R g=u var
    sync
EOF

# Collect shared libraries needed by FrankenPHP and PHP extensions
RUN <<-'EOF'
    apt-get update
    apt-get install -y --no-install-recommends libtree
    mkdir -p /tmp/libs
    BINARIES=(frankenphp php file)
    for target in $(printf '%s\n' "${BINARIES[@]}" | xargs -I{} which {}) \
        $(find "$(php -r 'echo ini_get("extension_dir");')" -maxdepth 2 -name "*.so"); do
        libtree -pv "$target" 2>/dev/null | grep -oP '(?:── )\K/\S+(?= \[)' | while IFS= read -r lib; do
            [ -f "$lib" ] && cp -n "$lib" /tmp/libs/
        done
    done
    sed -i 's/opcache.preload_user = root/opcache.preload_user = www-data/' "$PHP_INI_DIR/app.conf.d/20-radix.prod.ini"
    rm -rf /var/lib/apt/lists/*
EOF

# Radix Production Image
FROM debian:13-slim AS radix_app_production

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=prod
ENV PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"
# Set by the FrankenPHP image, which this bare Debian stage copies binaries from but inherits no environment from.
# Disables Go's pointer checks on the cgo boundary, which every call into PHP crosses.
ENV GODEBUG=cgocheck=0

COPY --from=radix_app_builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
COPY --from=radix_app_builder /usr/local/bin/php /usr/local/bin/php
COPY --from=radix_app_builder /usr/local/bin/docker-php-entrypoint /usr/local/bin/docker-php-entrypoint
COPY --from=radix_app_builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=radix_app_builder /tmp/libs /usr/lib

COPY --from=radix_app_builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=radix_app_builder /usr/local/etc/php/php.ini /usr/local/etc/php/php.ini
COPY --from=radix_app_builder /usr/local/etc/php/app.conf.d /usr/local/etc/php/app.conf.d

COPY --from=radix_app_builder /etc/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

COPY --from=radix_geoip / /usr/local/share/radix/geoip/

# CA certificates for TLS, file/libmagic for Symfony MIME type detection
COPY --from=radix_app_builder /usr/share/ca-certificates /usr/share/ca-certificates
COPY --from=radix_app_builder /etc/ssl/certs /etc/ssl/certs
COPY --from=radix_app_builder /etc/ssl/openssl.cnf /etc/ssl/openssl.cnf
COPY --from=radix_app_builder /usr/bin/file /usr/bin/file
COPY --from=radix_app_builder /usr/lib/file/magic.mgc /usr/lib/file/magic.mgc

ENV OPENSSL_CONF=/etc/ssl/openssl.cnf XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt

RUN <<-EOF
    mkdir -p /data/caddy /config/caddy
    chown -R www-data:www-data /data /config
    find / -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null || true
EOF

# php-vips loads libvips through FFI `dlopen` at runtime, so `libtree` (which only follows link-time deps) does not
# collect it into /tmp/libs. Install the runtime library explicitly in this fresh Debian stage. The `ffi` extension .so
# and its enabling ini are already carried over by the COPY --from lines above.
#
# `poppler-utils` provides `pdftoppm`, which rasterizes course documents before they are watermarked. It is invoked as
# a subprocess rather than linked, so it has to be installed here too. `fonts-dejavu-core` supplies the face the
# watermark is drawn in; fontconfig already pulls it in, but the watermark would silently stop rendering if that ever
# changed, so it is named here as well.
RUN <<-EOF
    apt-get update
    apt-get install -y --no-install-recommends fonts-dejavu-core libvips42t64 poppler-utils
    rm -rf /var/lib/apt/lists/*
EOF

COPY --link --exclude=var --from=radix_app_builder /app /app
COPY --chown=www-data:0 --from=radix_app_builder /app/var /app/var
RUN chmod g=u /app/var

# `data/` is excluded by .dockerignore, so nothing above creates this path. Docker would then create the mountpoint
# for the `data` volume itself, as root, and the application could not write the uploads, the private files served
# through X-Accel or the Halite key. Creating it here means a volume seeded from this image is writable; an existing
# one keeps whatever ownership it already has.
RUN mkdir -p /app/data && chown www-data:0 /app/data && chmod g=u /app/data

COPY --link --chmod=755 docker/app/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link docker/app/healthcheck.php /usr/local/bin/healthcheck.php

ARG GIT_COMMIT
ENV GIT_COMMIT=${GIT_COMMIT}

USER www-data

WORKDIR /app

ENTRYPOINT ["docker-entrypoint"]

# The timeout allows for both connection timeouts /health may sit through before it reports what is unreachable.
HEALTHCHECK --start-period=300s --interval=30s --timeout=25s CMD php /usr/local/bin/healthcheck.php
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
