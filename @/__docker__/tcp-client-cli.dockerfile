FROM ubuntu:22.04
LABEL org.opencontainers.image.source https://github.com/bootgly/bootgly

ARG DEBIAN_FRONTEND=noninteractive

# Prepare system
RUN apt-get update -y && \
    apt-get install -y software-properties-common apt-utils

# Install PHP apt repository
RUN LC_ALL=C.UTF-8 add-apt-repository ppa:ondrej/php
RUN apt-get update -y && \
    apt-get upgrade -y
# Install PHP in system
RUN apt-get install -y git php8.4-cli php8.4-readline
# Configure PHP Opcache with JIT
COPY /@/__php__/php-opcache.ini /etc/php/8.4/cli/conf.d/10-opcache.ini

# Install Bootgly
COPY ./ /bootgly/
WORKDIR /bootgly

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
# Run composer install
RUN composer install --optimize-autoloader --classmap-authoritative --no-dev -vvv

# Prepare Bootgly TCP Client
ENV PORT=8080
EXPOSE $PORT

CMD ["sh", "-c", "php /bootgly/scripts/tcp-client-cli -- --port=$PORT"]