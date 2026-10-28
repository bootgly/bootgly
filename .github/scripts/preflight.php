<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

// Bootgly CI — preflight of one test-matrix cell.
//
// Every cell must run the suite on the same footing: the PHP it claims, the
// same extension baseline, php.ini at parity with setup-php's development
// profile, the Xdebug state it declares, an unprivileged user, a reaping PID 1
// and an IPv6 loopback. A gap here would surface later as silent skips, or as
// failures blamed on the code, so the preflight fails loudly first and prints
// what the cell runs on.

// ! Baseline: what the framework and its suite call (ext-* checked by name)
$extensions = [
   'ctype', 'fileinfo', 'iconv', 'mbstring', 'openssl', 'pcntl', 'posix', 'readline',
   'session', 'simplexml', 'sockets', 'sqlite3', 'sysvsem', 'sysvshm', 'tokenizer', 'zlib',
];
$failures = [];

// ---

// ? PHP: the version this cell claims
$claimed = (string) getenv('BOOTGLY_CI_PHP');
$major = PHP_MAJOR_VERSION;
$minor = PHP_MINOR_VERSION;
$running = "{$major}.{$minor}";
if ($claimed !== '' && $claimed !== $running) {
   $failures[] = "the cell claims PHP {$claimed} but runs PHP {$running}";
}

// ? Extensions and build features
foreach ($extensions as $extension) {
   if (extension_loaded($extension) === false) {
      $failures[] = "ext-{$extension} is not loaded";
   }
}
if (defined('PASSWORD_ARGON2ID') === false) {
   $failures[] = 'PASSWORD_ARGON2ID is undefined (PHP built without Argon2)';
}

// ? php.ini parity with setup-php `ini-file: development` + `disable_functions=""`
if (error_reporting() !== E_ALL) {
   $failures[] = 'error_reporting is not E_ALL';
}
if (ini_get('zend.assertions') !== '1') {
   $failures[] = 'zend.assertions is not 1';
}
if (ini_get('disable_functions') !== '') {
   $failures[] = 'disable_functions is not empty';
}
if (in_array(ini_get('display_errors'), ['', '0', 'Off', 'off'], true)) {
   $failures[] = 'display_errors is off';
}
if (ini_get('memory_limit') !== '-1') {
   $failures[] = 'memory_limit is not -1';
}

// ? Xdebug: what the cell declares (BOOTGLY_CI_XDEBUG=1: loaded in coverage
//   mode; 0 or unset: absent). It slows every case and switches branches of
//   the suite, so an image must not add or drop it unnoticed.
$declared = (string) getenv('BOOTGLY_CI_XDEBUG');
$modes = function_exists('xdebug_info') ? xdebug_info('mode') : [];
$modes = is_string($modes) ? explode(',', $modes) : (array) $modes;
if (in_array($declared, ['', '0', '1'], true) === false) {
   $failures[] = "BOOTGLY_CI_XDEBUG is '{$declared}', not 0 or 1";
}
else if ($declared === '1' && in_array('coverage', $modes, true) === false) {
   $failures[] = 'the cell declares Xdebug in coverage mode, but it is not loaded in that mode';
}
else if ($declared !== '1' && extension_loaded('xdebug')) {
   $failures[] = 'Xdebug is loaded, but the cell declares none (BOOTGLY_CI_XDEBUG=1 keeps it)';
}

// ? Process: unprivileged, under a PID 1 that reaps orphans (TEST-11)
if (posix_geteuid() === 0) {
   $failures[] = 'running as root: the suite must run unprivileged';
}
$init = trim((string) @file_get_contents('/proc/1/comm'));
if (in_array($init, ['systemd', 'init', 'docker-init', 'tini', 'dumb-init', 'catatonit'], true) === false) {
   $failures[] = "PID 1 is '{$init}', not an init: orphans would never be reaped";
}

// ? Network: IPv6 loopback, TCP and UDP
foreach (['tcp', 'udp'] as $transport) {
   $flags = $transport === 'udp'
      ? STREAM_SERVER_BIND
      : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
   $socket = @stream_socket_server("{$transport}://[::1]:0", $code, $message, $flags);
   if ($socket === false) {
      $failures[] = "cannot bind {$transport}://[::1]:0 ({$message})";
      continue;
   }
   fclose($socket);
}

// ---

// @ Report what the cell runs on
$release = @parse_ini_file('/etc/os-release');
$system = is_array($release) ? ($release['PRETTY_NAME'] ?? 'unknown') : 'unknown';
$libc = glob('/lib/ld-musl-*.so.1') !== [] ? 'musl' : 'glibc';
$kernel = php_uname('r');
$machine = php_uname('m');
$version = PHP_VERSION;
$user = posix_getpwuid(posix_geteuid());
$account = is_array($user) ? $user['name'] : 'NO PASSWD ENTRY';
$UID = posix_geteuid();
$limits = posix_getrlimit();
$files = is_array($limits) ? $limits['soft openfiles'] : 'unknown';
$INI = php_ini_loaded_file() ?: '(none)';
$scanned = str_replace(["\n", ' '], '', (string) php_ini_scanned_files());
$CLI = ini_get('opcache.enable_cli') ?: '0';
$JIT = ini_get('opcache.jit') ?: 'unset';
$enabled = implode(',', $modes) ?: 'off';
$xdebug = extension_loaded('xdebug') ? $enabled : 'absent';
$readline = defined('READLINE_LIB') ? READLINE_LIB : 'unknown';
$iconv = defined('ICONV_IMPL') ? ICONV_IMPL : 'unknown';

echo <<<REPORT
preflight: {$system} · {$libc} · kernel {$kernel} · {$machine}
preflight: PHP {$version} · user {$account} ({$UID}) · PID 1 {$init} · nofile {$files}
preflight: php.ini {$INI} · scanned {$scanned}
preflight: opcache.enable_cli={$CLI} opcache.jit={$JIT} · xdebug {$xdebug} · readline {$readline} · iconv {$iconv}

REPORT;

// ---

// ?: Fail loudly, naming every gap at once
if ($failures !== []) {
   foreach ($failures as $failure) {
      echo "::error::preflight: {$failure}\n";
   }

   exit(1);
}

// :
echo "preflight: OK\n";
