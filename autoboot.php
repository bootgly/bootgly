<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
if (defined('BOOTGLY_STORAGE_BASE') === false) {
   define('BOOTGLY_STORAGE_BASE', BOOTGLY_WORKING_DIR . 'storage');
}
if (defined('BOOTGLY_STORAGE_DIR') === false) {
   define('BOOTGLY_STORAGE_DIR', BOOTGLY_STORAGE_BASE . DIRECTORY_SEPARATOR);
}

define('BOOTGLY_VERSION', '0.23.0-beta');

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
         $Coverage = new Bootgly\ACI\Tests\Coverage(
            new Bootgly\ACI\Tests\Coverage\Drivers\Native(explicit: true, mode: $mode)
         );
         $Coverage->start();

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
