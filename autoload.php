<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

#@include __DIR__ . '/@imports/autoload.php'; // composer

if (defined('BOOTGLY_BASE') === true) {
   return;
}

define('BOOTGLY_BASE', __DIR__);
define('BOOTGLY_DIR', __DIR__ . DIRECTORY_SEPARATOR);

// ? Bootgly
// ! Bootables ([0-9]) || (-[a-z]) || ([0-9]-[a-z])
// -- nothing --

// ! Classes ([A-Z])
// 1 - ABI (Abstract Bootable Interface)
// 2 - ACI (Abstract Core Interface)
// 3 - ADI (Abstract Data Interface)

// 4 - API (Application Programming Interface) -> Bootgly (platform)

// 5 - CLI (Command Line Interface)            -> Console (platform)
// 6 - Web (as interface)                      -> Web (as platform)
spl_autoload_register (function (string $class) {
   $paths = explode('\\', $class);
   $file = implode('/', $paths) . '.php';

   $included = @include BOOTGLY_WORKING_DIR . $file;

   if ($included === false && BOOTGLY_DIR !== BOOTGLY_WORKING_DIR) {
      @include BOOTGLY_DIR . $file;
   }
});

// ! Resources ([a-z])
use Bootgly\ACI\Debugger;


if (function_exists('debug') === false) {
   function debug (...$vars)
   {
      if (Debugger::$trace === null) {
         Debugger::$trace = debug_backtrace();
      }

      $Debugger = new Debugger(...$vars);

      if (Debugger::$trace !== false) {
         Debugger::$trace = null;
      }

      return $Debugger;
   }
}

// ? Workables
// composer?
$installed = BOOTGLY_BASE . '/../../composer/installed.php';
if ( is_file($installed) ) {
   $installed = @include $installed;

   $root = $installed['root']['install_path'] ?? null;
   if ($root) {
      $root = realpath($root);
   }

   define('BOOTGLY_WORKABLES_BASE', $root ?? BOOTGLY_BASE);
} else {
   define('BOOTGLY_WORKABLES_BASE', BOOTGLY_BASE);
}
define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKABLES_BASE . DIRECTORY_SEPARATOR);

new Bootgly;
