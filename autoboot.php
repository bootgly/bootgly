<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

// ?
if (defined('BOOTGLY_ROOT_BASE') === true) {
   return;
}

// !
define('BOOTGLY_ROOT_BASE', __DIR__);
define('BOOTGLY_ROOT_DIR', __DIR__ . DIRECTORY_SEPARATOR);
if (defined('BOOTGLY_WORKING_BASE') === false) {
   define('BOOTGLY_WORKING_BASE', BOOTGLY_ROOT_BASE);
   define('BOOTGLY_WORKING_DIR', BOOTGLY_ROOT_DIR);
}
// ? `BOOTGLY_STORAGE_DIR` in the process environment moves the storage directory when neither
//   constant was pre-defined (a pre-defined constant wins and the variable is not read).
//   A privileged launch writes its process state there and loads compiled templates from it, so
//   the directory is validated here and an unsafe one stops the boot — it never falls back to
//   the default directory: an absolute, canonical path to an existing real directory (no link),
//   owned by the effective user or root and not writable by its group or by others, under
//   ancestors that only the effective user or root can rename it in.
if (
   defined('BOOTGLY_STORAGE_BASE') === false
   && defined('BOOTGLY_STORAGE_DIR') === false
   && getenv('BOOTGLY_STORAGE_DIR') !== false
) {
   (static function (string $value): void {
      // ! One trailing separator is accepted: the constant it names ends with one
      $base = strlen($value) > 1 && str_ends_with($value, '/')
         ? substr($value, 0, -1)
         : $value;

      // @ The first check that fails names itself and the path
      $refusal = (static function () use ($value, $base): null|string {
         // ? Absolute and lexically canonical
         if ($value === '') {
            return 'empty value';
         }
         if ($base[0] !== '/') {
            return "not an absolute path: {$value}";
         }
         if ($base === '/') {
            return "the filesystem root: {$value}";
         }
         foreach (explode('/', substr($base, 1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
               return "not canonical (an empty, `.` or `..` segment): {$value}";
            }
         }
         if (function_exists('posix_geteuid') === false) {
            return "its owner cannot be verified (the posix extension is missing): {$base}";
         }

         // ? An existing real directory, reached through no link
         $state = @lstat($base);
         if (is_array($state) === false) {
            return "does not exist or cannot be inspected: {$base}";
         }
         if (((int) $state['mode'] & 0170000) === 0120000) {
            return "symbolic link: {$base}";
         }
         if (((int) $state['mode'] & 0170000) !== 0040000) {
            return "not a directory: {$base}";
         }
         $real = realpath($base);
         if ($real !== $base) {
            $target = $real === false ? 'nothing' : $real;
            return "not canonical (resolves to {$target}): {$base}";
         }

         // ? Owned by the effective user or root, writable by its owner only
         $EUID = posix_geteuid();
         if ((int) $state['uid'] !== 0 && (int) $state['uid'] !== $EUID) {
            return "owner {$state['uid']} is neither the effective user ({$EUID}) nor root: {$base}";
         }
         if (((int) $state['mode'] & 0022) !== 0) {
            $mode = decoct((int) $state['mode'] & 07777);
            return "group/world-writable (0{$mode}): {$base}";
         }

         // ? No ancestor lets another user rename the checked directory away afterwards:
         //   each is owned by the effective user or root, and writable by its owner only
         //   unless it is sticky (there, only the owner of an entry renames it). Root is
         //   uid 0 alone: the owner of `/` in a user namespace is the overflow uid, and so
         //   is every other account the namespace does not map
         $ancestor = $base;
         do {
            $ancestor = dirname($ancestor);
            $state = @lstat($ancestor);
            if (is_array($state) === false) {
               return "ancestor cannot be inspected: {$ancestor}";
            }
            if ((int) $state['uid'] !== 0 && (int) $state['uid'] !== $EUID) {
               return "ancestor owner {$state['uid']} is neither the effective user ({$EUID}) nor root: {$ancestor}";
            }
            if (((int) $state['mode'] & 0022) !== 0 && ((int) $state['mode'] & 01000) === 0) {
               $mode = decoct((int) $state['mode'] & 07777);
               return "ancestor group/world-writable without the sticky bit (0{$mode}): {$ancestor}";
            }
         }
         while ($ancestor !== '/');

         // :
         return null;
      })();

      // ?: One message, then no boot
      if ($refusal !== null) {
         // ! The value is quoted as given: its control bytes are written escaped
         $refusal = addcslashes($refusal, "\0..\37\177");
         $message = "Bootgly: BOOTGLY_STORAGE_DIR from the environment is refused: {$refusal}";
         if (defined('STDERR') === true) {
            fwrite(STDERR, $message . PHP_EOL);
         }
         else {
            error_log($message);
         }

         exit(1);
      }

      // @
      define('BOOTGLY_STORAGE_BASE', $base);
      define('BOOTGLY_STORAGE_DIR', $base . DIRECTORY_SEPARATOR);
   })(getenv('BOOTGLY_STORAGE_DIR'));
}
if (defined('BOOTGLY_STORAGE_BASE') === false) {
   define('BOOTGLY_STORAGE_BASE', BOOTGLY_WORKING_DIR . 'storage');
}
if (defined('BOOTGLY_STORAGE_DIR') === false) {
   define('BOOTGLY_STORAGE_DIR', BOOTGLY_STORAGE_BASE . DIRECTORY_SEPARATOR);
}
// ? Where the HTTP server streams multipart uploads (`tmp_name`) — one source for the decoder
//   and the validators. Derived, never pre-defined: the server owns this folder and deletes its
//   files at every start and worker spawn (move it with `BOOTGLY_STORAGE_DIR`)
if (defined('BOOTGLY_UPLOADS_DIR') === true) {
   throw new LogicException('BOOTGLY_UPLOADS_DIR cannot be pre-defined: it derives from BOOTGLY_STORAGE_DIR, and the server deletes the files in it.');
}
define('BOOTGLY_UPLOADS_DIR', BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/');

define('BOOTGLY_VERSION', '1.3.0-dev');

// ? Platform interface override for embedded runtimes (e.g. WASM) that behave as a console.
// BOOTGLY_SAPI answers "which platform interface"; PHP_SAPI stays for "what can this process actually do".
define('BOOTGLY_SAPI', getenv('BOOTGLY_SAPI') ?: PHP_SAPI);
// ? Interactivity override for emulated terminals (e.g. xterm.js feeding a WASM runtime).
// BOOTGLY_TTY answers "is there an interactive terminal attached"; stream capabilities stay per stream.
define('BOOTGLY_TTY', match (getenv('BOOTGLY_TTY')) {
   '1' => true,
   '0' => false,
   default => defined('STDIN') && function_exists('stream_isatty') && stream_isatty(STDIN)
});
// ? Deployment environment for failure exposure (error pages, debugging surfaces).
// BOOTGLY_ENVIRONMENT answers "how should failures be exposed"; unrecognized or unset values fail safe to 'production'.
define('BOOTGLY_ENVIRONMENT', match (getenv('BOOTGLY_ENVIRONMENT')) {
   'development' => 'development',
   'staging' => 'staging',
   'test' => 'test',
   default => 'production'
});

@include(__DIR__ . '/vendor/autoload.php'); // composer

// ! Bootables ([0-9]) || (-[a-z]) || ([0-9]-[a-z])
// -- nothing --

// ! Classes ([A-Z])
// ABI (Abstract Bootable Interface)
// ACI (Abstract Common Interface)
// ADI (Abstract Data Interface)

// API (Application Programming Interface) -> Bootgly (platform)

// CLI (Command Line Interface)            -> Console (platform)
// WPI (Web Programming Interface)         -> Web (platform)
spl_autoload_register (function (string $class) {
   $path = str_replace('\\', '/', $class) . '.php';
   $file = BOOTGLY_WORKING_DIR . $path;

   if (is_file($file) === false && BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
      $file = BOOTGLY_ROOT_DIR . $path;
   }

   if (is_file($file) === false) {
      return;
   }

   if (
      class_exists(Bootgly\ACI\Tests\Coverage\Drivers\Native::class, false)
      && Bootgly\ACI\Tests\Coverage\Drivers\Native::route($file)
   ) {
      return;
   }

   include $file;
});

// ! Projects ([A-Z] — dedicated autoload, bare namespace without the `projects/` prefix)
// @ Primary: resolve relative to the booted project's absolute path (BOOTGLY_PROJECT->path)
// @ so an isolated-repo project cloned anywhere still loads; fallback maps the bare FQCN into
// @ <projects/> under the active environment root (WEB_ROOT_DIR on Web, BOOTGLY_WORKING_DIR on
// @ Console, BOOTGLY_ROOT_DIR as the monorepo author fallback).
spl_autoload_register (function (string $class) {
   $file = '';

   // ? Primary — anchored on the booted project (defined once per process at Project::boot())
   if (defined('BOOTGLY_PROJECT')) {
      $root = str_replace('/', '\\', BOOTGLY_PROJECT->folder);

      if (str_starts_with($class, $root . '\\')) {
         $sub = substr($class, strlen($root) + 1);
         $file = BOOTGLY_PROJECT->path . str_replace('\\', '/', $sub) . '.php';

         if (is_file($file) === false) {
            $file = '';
         }
      }
   }

   // ? Fallback — map the bare FQCN into <projects/> under the active environment root
   if ($file === '') {
      $path = str_replace('\\', '/', $class) . '.php';

      foreach ([
         defined('WEB_ROOT_DIR') ? WEB_ROOT_DIR : null,
         BOOTGLY_WORKING_DIR,
         BOOTGLY_ROOT_DIR,
      ] as $base) {
         if ($base === null) {
            continue;
         }

         $candidate = $base . 'projects/' . $path;
         if (is_file($candidate)) {
            $file = $candidate;
            break;
         }
      }
   }

   // ? Nothing matched — let other autoloaders try
   if ($file === '') {
      return;
   }

   if (
      class_exists(Bootgly\ACI\Tests\Coverage\Drivers\Native::class, false)
      && Bootgly\ACI\Tests\Coverage\Drivers\Native::route($file)
   ) {
      return;
   }

   include $file;
});

// ! Resources ([a-z])
// ...

// @ Native coverage must start before CLI boot when the SUT is part of the
// command bootstrap itself (e.g. Bootgly\CLI\Commands). TestCommand reuses
// this session and only applies suite filters/reporting after execution.
if (BOOTGLY_SAPI === 'cli') {
   /** @var array<int, string> $argv */
   $argv = (array) ($_SERVER['argv'] ?? []);

   if (in_array('test', $argv, true)) {
      $native = false;
      $mode = Bootgly\ACI\Tests\Coverage\Drivers\Native::MODE_STRICT;

      foreach ($argv as $index => $argument) {
         if ($argument === '--coverage-driver=native') {
            $native = true;
            continue;
         }

         if (
            $argument === '--coverage-driver'
            && strtolower($argv[$index + 1] ?? '') === 'native'
         ) {
            $native = true;
            continue;
         }

         $driver = '--coverage-driver=';
         if (str_starts_with($argument, $driver)) {
            $native = strtolower(substr($argument, strlen($driver))) === 'native';
            continue;
         }

         if ($argument === '--coverage-native-mode') {
            $mode = strtolower($argv[$index + 1] ?? $mode);
            continue;
         }

         $profile = '--coverage-native-mode=';
         if (str_starts_with($argument, $profile)) {
            $mode = strtolower(substr($argument, strlen($profile)));
         }
      }

      if ($native) {
         // ? A Native driver that cannot run (opcache on, unknown mode)
         //   stops the run with its reason, never a stack trace
         try {
            $Coverage = new Bootgly\ACI\Tests\Coverage(
               new Bootgly\ACI\Tests\Coverage\Drivers\Native(explicit: true, mode: $mode)
            );
            $Coverage->start();
         }
         catch (LogicException $Exception) {
            fwrite(STDERR, "[test] Coverage: {$Exception->getMessage()}" . PHP_EOL);
            exit(1);
         }

         $GLOBALS['BOOTGLY_COVERAGE'] = $Coverage;
      }
   }
}

// @
/**
 * @var Bootgly Bootgly
 */
const Bootgly = new Bootgly;
Bootgly->autoboot();
