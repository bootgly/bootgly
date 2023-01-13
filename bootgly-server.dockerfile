FROM ubuntu:22.04

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update -yqq && apt-get install -yqq software-properties-common > /dev/null
RUN LC_ALL=C.UTF-8 add-apt-repository ppa:ondrej/php > /dev/null
RUN apt-get update -yqq > /dev/null && apt-get upgrade -yqq > /dev/null

RUN apt-get install -yqq php8.2-cli php8.2-pgsql php8.2-xml php8.2-readline > /dev/null

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN apt-get install -y php-pear php8.2-dev git > /dev/null

COPY /@/php-jit.ini /etc/php/8.2/cli/php.ini

ADD ./ /bootgly
WORKDIR /bootgly

RUN composer install --optimize-autoloader --classmap-authoritative --no-dev --quiet

EXPOSE 8080

CMD php /bootgly/server.http.php