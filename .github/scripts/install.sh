#!/bin/sh
# Bootgly CI — provision one container cell of the test matrix.
#
# Installs PHP <php> with the extension baseline that preflight.php asserts,
# the tools the suite and its failure diagnostics call (bash, setsid, timeout,
# ps, ss, git, curl ...), a php.ini drop-in at parity with the runner cells
# (setup-php `ini-file: development` + `disable_functions=""`), and the
# unprivileged user the suite runs as: root would bypass the permission checks
# the tests assert.
#
#    usage: install.sh <distro> <php>
#      distro  debian-13 | alpine-3.24 | fedora-44 | almalinux-9 | docker-image
#              ubuntu-24.04 | ubuntu-26.04 (local stand-ins for the runner cells)
#      php     8.4 | 8.5
#    env    BOOTGLY_CI_UID, BOOTGLY_CI_GID: owner of the bind-mounted checkout
#           (default 1000:1000; the workflow passes the runner's own ids).
#
# Runs as root inside the cell's base image (cell.Dockerfile). POSIX sh: the
# Alpine base has no bash until this script installs it.
set -eu

DISTRO=${1:?usage: install.sh <distro> <php>}
PHP=${2:?usage: install.sh <distro> <php>}
CI_UID=${BOOTGLY_CI_UID:-1000}
CI_GID=${BOOTGLY_CI_GID:-1000}

# ? Guards
case "$PHP" in
   8.4|8.5) ;;
   *) echo "install.sh: unsupported PHP '$PHP' (8.4 or 8.5)" >&2; exit 2 ;;
esac
if [ "$CI_UID" = 0 ]; then
   echo 'install.sh: the CI user must not be root' >&2
   exit 2
fi

# ---

# ! Network steps retry: a mirror hiccup must not read as a Bootgly failure.
retry () {
   attempt=1
   until "$@"; do
      if [ "$attempt" -ge 3 ]; then
         return 1
      fi
      attempt=$((attempt + 1))
      sleep 10
   done
}

# # Debian family (apt)
install_apt () {
   DEBIAN_FRONTEND=noninteractive retry apt-get install -y --no-install-recommends "$@"
}
# ! deb.sury.org: the PHP builds of ppa:ondrej/php, served for Debian and Ubuntu
repo_sury () {
   install_apt ca-certificates curl
   retry curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
   dpkg -i /tmp/debsuryorg-archive-keyring.deb
   rm -f /tmp/debsuryorg-archive-keyring.deb
   echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $1 main" \
      > /etc/apt/sources.list.d/php.list
   retry apt-get update
}
php_deb () {
   # ! php$PHP-common carries ctype, fileinfo, iconv, posix, sockets, sysv*,
   #   tokenizer; -cli carries pcntl; openssl and zlib are built in.
   set -- "php$PHP-cli" "php$PHP-mbstring" "php$PHP-readline" "php$PHP-sqlite3" "php$PHP-xml"
   if [ "$PHP" = 8.4 ]; then
      set -- "$@" "php$PHP-opcache"   # built into PHP 8.5
   fi
   install_apt "$@"
}
tools_deb () {
   install_apt bash ca-certificates curl git iproute2 ncurses-bin openssl procps util-linux
}

# # Red Hat family (dnf)
install_dnf () {
   retry dnf -y install "$@"
}
# ! rpms.remirepo.net module streams: /usr/bin/php, the de-facto PHP on EL
repo_remi () {
   dnf -y module reset php
   dnf -y module enable "php:remi-$PHP"
}
php_rpm () {
   # ! php-common carries ctype, fileinfo, iconv, openssl, sockets, tokenizer,
   #   zlib; -cli pcntl and readline; -process posix and sysv*; -pdo sqlite3.
   set -- php-cli php-mbstring php-pdo php-process php-xml
   if [ "$PHP" = 8.4 ]; then
      set -- "$@" php-opcache   # built into PHP 8.5
   fi
   install_dnf "$@"
}
tools_rpm () {
   # ! The images ship curl or curl-minimal: installing the other conflicts.
   set -- bash git-core iproute ncurses openssl procps-ng shadow-utils util-linux
   if ! command -v curl >/dev/null 2>&1; then
      set -- "$@" curl
   fi
   install_dnf "$@"
}

# # Alpine (apk, musl)
php_apk () {
   p="php$(echo "$PHP" | tr -d .)"
   # ! readline (libedit) and zlib are built into the php8x binary; session is
   #   NOT (Debian, Fedora and the php images build it in): Alpine splits it out.
   set -- "$p" "$p-ctype" "$p-dom" "$p-fileinfo" "$p-iconv" "$p-mbstring" "$p-openssl" \
      "$p-pcntl" "$p-pdo_sqlite" "$p-posix" "$p-session" "$p-shmop" "$p-simplexml" "$p-sockets" \
      "$p-sqlite3" "$p-sysvsem" "$p-sysvshm" "$p-tokenizer" "$p-xml"
   if [ "$PHP" = 8.4 ]; then
      set -- "$@" "$p-opcache"   # built into PHP 8.5 (no php85-opcache package)
   fi
   retry apk add --no-cache "$@"
   # ! Only the newest php8x package owns /usr/bin/php.
   if [ ! -e /usr/bin/php ]; then
      ln -s "/usr/bin/$p" /usr/bin/php
   fi
}
tools_apk () {
   # ! The GNU/util-linux forms: BusyBox setsid has no --wait and BusyBox
   #   timeout no --kill-after; BusyBox ps has no auxf.
   retry apk add --no-cache bash coreutils curl git iproute2 ncurses openssl procps-ng util-linux-misc
}

# ---

# @ PHP + tools
case "$DISTRO" in
   debian-13)
      retry apt-get update
      tools_deb
      if [ "$PHP" = 8.5 ]; then
         repo_sury trixie   # trixie ships PHP 8.4 only
      fi
      php_deb
      ;;
   ubuntu-24.04|ubuntu-26.04)
      # ! Local stand-ins for the setup-php runner cells (same Sury builds).
      retry apt-get update
      tools_deb
      if [ "$DISTRO" = ubuntu-24.04 ]; then
         repo_sury noble
      else
         repo_sury resolute
      fi
      php_deb
      ;;
   docker-image)
      # ! The repo Dockerfile's `base` stage: its PHP stays exactly as shipped.
      retry apt-get update
      tools_deb
      ;;
   fedora-44)
      tools_rpm
      if [ "$PHP" = 8.4 ]; then
         install_dnf https://rpms.remirepo.net/fedora/remi-release-44.rpm
         repo_remi   # Fedora 44 ships PHP 8.5 only
      fi
      php_rpm
      ;;
   almalinux-9)
      tools_rpm
      install_dnf dnf-plugins-core epel-release
      dnf config-manager --set-enabled crb
      install_dnf https://rpms.remirepo.net/enterprise/remi-release-9.rpm
      repo_remi   # EL9 AppStream stops at PHP 8.3
      php_rpm
      ;;
   alpine-3.24)
      tools_apk
      php_apk
      ;;
   *)
      echo "install.sh: unknown distro '$DISTRO'" >&2
      exit 2
      ;;
esac

# @ php.ini parity with the runner cells, loaded last (conf.d/zzz-*)
INI_DIR=$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')
if [ -z "$INI_DIR" ]; then
   echo 'install.sh: this PHP scans no conf.d directory' >&2
   exit 1
fi
mkdir -p "$INI_DIR"
{
   cat <<'INI'
; Bootgly CI: parity with the runner cells' setup-php profile.
;
; * php.ini-development over php.ini-production (the 7 values that differ)
error_reporting = E_ALL
display_errors = On
display_startup_errors = On
zend.assertions = 1
zend.exception_ignore_args = Off
zend.exception_string_param_max_len = 15
mysqlnd.collect_memory_statistics = On

; * setup-php: its appended defaults and the workflow's ini-values
date.timezone = UTC
memory_limit = -1
disable_functions =
INI
   # ! The shipped image keeps its own opcache/JIT tuning (zz-bootgly.ini):
   #   that cell exists to run the suite on the runtime users get.
   if [ "$DISTRO" != docker-image ]; then
      cat <<'INI'

; * setup-php's JIT block, inert on the CLI exactly as on the runner:
;   opcache.enable_cli keeps its default 0 there, but distro packages
;   (Fedora, Remi) turn it on in their own opcache.ini.
opcache.enable = 1
opcache.enable_cli = 0
opcache.jit = 1235
opcache.jit_buffer_size = 256M
INI
   fi
} > "$INI_DIR/zzz-bootgly-ci.ini"

# @ The unprivileged CI user, owning the same ids as the checkout
if command -v useradd >/dev/null 2>&1; then
   getent group "$CI_GID" >/dev/null || groupadd --gid "$CI_GID" bootgly
   getent passwd "$CI_UID" >/dev/null || useradd --uid "$CI_UID" --gid "$CI_GID" --create-home --shell /bin/bash bootgly
else
   # ! BusyBox (Alpine)
   getent group "$CI_GID" >/dev/null || addgroup -g "$CI_GID" bootgly
   getent passwd "$CI_UID" >/dev/null || adduser -D -u "$CI_UID" -G "$(getent group "$CI_GID" | cut -d: -f1)" -s /bin/bash bootgly
fi
CI_HOME=$(getent passwd "$CI_UID" | cut -d: -f6)
mkdir -p "$CI_HOME"
chown "$CI_UID:$CI_GID" "$CI_HOME"

# ---

# : The provisioned runtime, for the build log
php -v
echo "install.sh: $DISTRO php $PHP ready for uid $CI_UID ($(getent passwd "$CI_UID" | cut -d: -f1))"
