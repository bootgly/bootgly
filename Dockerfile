# syntax=docker/dockerfile:1
# ============================================================================
# Bootgly PHP Framework — multi-stage image
#
#   base      → PHP 8.4 + required/recommended extensions + opcache/JIT tuning
#   framework → base + the framework source.
#
# This is the FRAMEWORK image: the ingredient, not the product. It is what the
# benchmark harness (bootgly/bootgly_benchmarks) builds on, and what you build
# on to embed Bootgly in an image of your own.
#
# A user installs the KIT — framework + Console + Web + the kit entry — which is
# a separate image built by the bootgly.kit repository: bootgly/bootgly.kit.
#
# Build context is THIS repository. The build context excludes `.git`, so inject
# the canonical source tuple:
#
#   docker build \
#     $(php Bootgly/ACI/Tests/Benchmark/provenance.php . ../bootgly_benchmarks --docker-build-args) \
#     -f Dockerfile --target framework -t bootgly:framework .
# ============================================================================

ARG PHP_IMAGE=php:8.4-cli-bookworm
ARG BOOTGLY_VERSION=1.0.0-rc.1
ARG BOOTGLY_FRAMEWORK_SHA=unknown
ARG BOOTGLY_FRAMEWORK_DIRTY=unknown
ARG BOOTGLY_FRAMEWORK_TRACKED_DIFF_SHA256=unknown
ARG BOOTGLY_FRAMEWORK_UNTRACKED_MANIFEST_SHA256=unknown


# ============================================================================
# Stage: base
# ============================================================================
FROM ${PHP_IMAGE} AS base

# ! Install Git, build + enable native extensions, then drop build-only libs.
#   Bundled & enabled already in the official image: openssl, posix, readline.
#   libonig-dev is needed to build mbstring; its runtime lib (libonig5) is kept.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git libonig-dev; \
    docker-php-ext-install -j"$(nproc)" pcntl sockets shmop sysvshm sysvsem opcache mbstring; \
    apt-get purge -y libonig-dev; \
    rm -rf /var/lib/apt/lists/*

# ! opcache + JIT tuning (wins over defaults via conf.d/zz-*)
COPY @/__php__/zz-bootgly.ini /usr/local/etc/php/conf.d/zz-bootgly.ini

WORKDIR /bootgly


# ============================================================================
# Stage: framework — the ingredient: run servers, or build your own image on it
# ============================================================================
FROM base AS framework

ARG BOOTGLY_VERSION
ARG BOOTGLY_FRAMEWORK_SHA
ARG BOOTGLY_FRAMEWORK_DIRTY
ARG BOOTGLY_FRAMEWORK_TRACKED_DIFF_SHA256
ARG BOOTGLY_FRAMEWORK_UNTRACKED_MANIFEST_SHA256
ENV BOOTGLY_FRAMEWORK_SHA="${BOOTGLY_FRAMEWORK_SHA}" \
    BOOTGLY_FRAMEWORK_DIRTY="${BOOTGLY_FRAMEWORK_DIRTY}" \
    BOOTGLY_FRAMEWORK_TRACKED_DIFF_SHA256="${BOOTGLY_FRAMEWORK_TRACKED_DIFF_SHA256}" \
    BOOTGLY_FRAMEWORK_UNTRACKED_MANIFEST_SHA256="${BOOTGLY_FRAMEWORK_UNTRACKED_MANIFEST_SHA256}"
# ! Tells the CLI it is inside an image — it words a `kit` refusal by it. The
#   framework-vs-kit distinction is NOT taken from here: it is structural (the
#   framework is the working base in this image, nested under `Bootgly/` in a
#   kit), so no `docker run -e` can make a test or a refusal lie.
ENV BOOTGLY_DOCKER=1
LABEL org.opencontainers.image.title="Bootgly Framework" \
      org.opencontainers.image.description="The Bootgly PHP Framework alone — the ingredient. The product is bootgly/bootgly.kit" \
      org.opencontainers.image.version="${BOOTGLY_VERSION}" \
      org.opencontainers.image.revision="${BOOTGLY_FRAMEWORK_SHA}" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.vendor="Bootgly" \
      org.opencontainers.image.url="https://bootgly.com" \
      org.opencontainers.image.documentation="https://docs.bootgly.com" \
      org.opencontainers.image.source="https://github.com/bootgly/bootgly"

# ! Framework source (vendor/storage/tmp excluded by .dockerignore)
COPY . /bootgly/

# ! Make `bootgly` global. __DIR__ resolves the symlink → working base stays /bootgly.
RUN ln -s /bootgly/bootgly /usr/local/bin/bootgly && \
    chmod +x /bootgly/@/__docker__/entrypoint.sh

# # Server ports: HTTP 8082 · HTTPS 443 · TCP 8080 · Benchmark 8083/8084 · UDP 9999
EXPOSE 8082 443 8080 8083 8084 9999/udp

# ! The server stops on SIGTERM (graceful drain); make the contract explicit
STOPSIGNAL SIGTERM

# ! No wizard here: this image is the ingredient, and a project scaffolded in it
#   would have no Console, no Web and no volume to survive the container. A bare
#   run prints the help; the kit image is the one that installs projects.
ENTRYPOINT ["/bootgly/@/__docker__/entrypoint.sh"]
CMD ["help"]
