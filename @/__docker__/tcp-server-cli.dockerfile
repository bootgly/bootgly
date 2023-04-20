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
RUN apt-get install -y git php-pear php8.2-dev php8.2-cli php8.2-xml php8.2-readline
# Configure PHP with JIT
COPY ../__php__/php-jit.ini /etc/php/8.2/cli/php.ini

# Install Bootgly
COPY ./ /bootgly/
WORKDIR /bootgly

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
# Run composer install
RUN composer install --optimize-autoloader --classmap-authoritative --no-dev -vvv

# Prepare Bootgly TCP Server
ENV PORT=8080
EXPOSE $PORT

COPY projects/cli.tcp-server.api.php.example projects/cli.tcp-server.api.php

CMD ["sh", "-c", "php /bootgly/@/scripts/http-server-cli.php -- --port=$PORT"]