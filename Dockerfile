# syntax=docker/dockerfile:1
# ============================================================================
# Bootgly PHP Framework — multi-stage image
#
#   base   → PHP 8.4 + required/recommended extensions + opcache/JIT tuning
#   vendor → composer install (dev) to provide phpstan for `bootgly lint`
#   slim   → base + framework source. Run servers, deploy, use in your project.
#   full   → slim + benchmark cases + dev vendor + bench tools. Test & benchmark.
#
# Build context is the PARENT of this file (so the sibling bootgly_benchmarks/
# is reachable). From the bootgly repo root:
#   docker build -f Dockerfile --target slim -t bootgly:slim ..
#   docker build -f Dockerfile --target full -t bootgly:full ..
# ============================================================================

ARG PHP_IMAGE=php:8.4-cli-bookworm
ARG BOOTGLY_VERSION=0.23.0-beta


# ============================================================================
# Stage: base
# ============================================================================
FROM ${PHP_IMAGE} AS base

# ! Build + enable native extensions, then drop build-only libs.
#   Bundled & enabled already in the official image: openssl, posix, readline.
#   libonig-dev is needed to build mbstring; its runtime lib (libonig5) is kept.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libonig-dev; \
    docker-php-ext-install -j"$(nproc)" pcntl sockets shmop opcache mbstring; \
    apt-get purge -y libonig-dev; \
    rm -rf /var/lib/apt/lists/*

# ! opcache + JIT tuning (wins over defaults via conf.d/zz-*)
COPY bootgly/@/__php__/zz-bootgly.ini /usr/local/etc/php/conf.d/zz-bootgly.ini

WORKDIR /bootgly


# ============================================================================
# Stage: vendor — composer install (dev) → phpstan for `bootgly lint`
# ============================================================================
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ! Composer needs git + unzip to fetch/extract packages (not in the base image)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip; \
    rm -rf /var/lib/apt/lists/*

# ? Only the manifests are needed to resolve+install dependencies
# ? composer.lock is gitignored — the `*` glob keeps the COPY valid when it is
# ? absent (CI checkout), while still pinning versions when it exists (local)
COPY bootgly/composer.json bootgly/composer.lock* ./
RUN set -eux; \
    composer install --no-interaction --no-progress --no-scripts --prefer-dist; \
    rm -rf /root/.composer/cache


# ============================================================================
# Stage: slim — run servers / deploy / use in your project
# ============================================================================
FROM base AS slim

ARG BOOTGLY_VERSION
LABEL org.opencontainers.image.title="Bootgly" \
      org.opencontainers.image.description="The Bootgly PHP Framework (slim runtime)" \
      org.opencontainers.image.version="${BOOTGLY_VERSION}" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.source="https://github.com/bootgly/bootgly"

# ! Framework source (vendor/storage/tmp excluded by .dockerignore)
COPY bootgly/ /bootgly/

# ! Make `bootgly` global. __DIR__ resolves the symlink → working base stays /bootgly.
RUN ln -s /bootgly/bootgly /usr/local/bin/bootgly

# # Server ports: HTTP 8082 · HTTPS 443 · TCP 8080 · Benchmark 8083/8084 · UDP 9999
EXPOSE 8082 443 8080 8083 8084 9999/udp

ENTRYPOINT ["bootgly"]
CMD ["help"]


# ============================================================================
# Stage: full — test + internal benchmark (default target)
# ============================================================================
FROM slim AS full

ARG BOOTGLY_VERSION
LABEL org.opencontainers.image.description="The Bootgly PHP Framework (full: test + benchmark)" \
      org.opencontainers.image.version="${BOOTGLY_VERSION}"

# ! Tools used by the internal benchmark runner (port probing / readiness / nproc)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends curl lsof procps; \
    rm -rf /var/lib/apt/lists/*

# ! Benchmark cases — sibling layout satisfies BOOTGLY_WORKING_DIR . '../bootgly_benchmarks/'
COPY bootgly_benchmarks/ /bootgly_benchmarks/

# ! Dev vendor (phpstan) so `bootgly lint` works
COPY --from=vendor /bootgly/vendor /bootgly/vendor
