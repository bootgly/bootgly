# Install PHP 8 on WSL 2 (Debian) - Guide

## Install
```bash
sudo apt update
sudo apt -y upgrade
sudo reboot
sudo apt install -y lsb-release ca-certificates apt-transport-https software-properties-common
echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/sury-php.list
wget -qO - https://packages.sury.org/php/apt.gpg | sudo apt-key add -
sudo apt update
sudo apt install php8.0 -y
sudo apt install php8.0-{mysql,cli,common,imap,ldap,xml,fpm,curl,mbstring,zip}
php -v
```

## Start FPM
sudo service php8.2-fpm start

### Configure FPM
sudo nano /etc/php/8.2/fpm/pool.d/www.conf