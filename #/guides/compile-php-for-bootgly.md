# PHP Compile for Bootgly - Guide

## First compile

```bash
sudo apt update
sudo apt install build-essential autoconf libtool bison re2c pkg-config
sudo apt install libssl-dev libxml2-dev libsqlite3-dev libreadline-dev zlib1g-dev libpcre3-dev

git clone https://github.com/php/php-src.git --branch=php-8.2.1 --depth=1

cd php-src

./buildconf --force
./configure --enable-zts --with-openssl --enable-pcntl --with-readline \
   --with-config-file-path=/home/$USER/bootgly/bootgly-php-framework/@

make -j $(nproc)
sudo make install
```

## Routines

```bash
cd php-src

git pull --rebase

./buildconf --force
./config.nice

make -j $(nproc)

sudo make install
```
