<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

if (defined('BOOTGLY_ROOT_BASE') === true) {
   return;
}

define('BOOTGLY_ROOT_BASE', __DIR__);
define('BOOTGLY_ROOT_DIR', __DIR__ . DIRECTORY_SEPARATOR);

if (defined('BOOTGLY_WORKING_BASE') === false) {
   define('BOOTGLY_WORKING_BASE', BOOTGLY_ROOT_BASE);
   define('BOOTGLY_WORKING_DIR', BOOTGLY_ROOT_DIR);
}

@include(__DIR__ . '/@imports/autoload.php'); // composer

// ? Bootgly
// ! Bootables ([0-9]) || (-[a-z]) || ([0-9]-[a-z])
// -- nothing --

// ! Classes ([A-Z])
// 1 - ABI (Abstract Bootable Interface)
// 2 - ACI (Abstract Common Interface)
// 3 - ADI (Abstract Data Interface)

// 4 - API (Application Programming Interface) -> Bootgly (platform)

// 5 - CLI (Command Line Interface)            -> Console (platform)
// 6 - WPI (Web Programming Interface)         -> Web (platform)
spl_autoload_register (function (string $class) {
   $paths = explode('\\', $class);
   $file = implode('/', $paths) . '.php';

   $included = @include(BOOTGLY_WORKING_DIR . $file);

   if ($included === false && BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
      @include(BOOTGLY_ROOT_DIR . $file);
   }
});

// ! Resources ([a-z])
require(BOOTGLY_ROOT_DIR . 'Bootgly/ABI/autoload.php');
require(BOOTGLY_ROOT_DIR . 'Bootgly/ACI/autoload.php');
require(BOOTGLY_ROOT_DIR . 'Bootgly/CLI/autoload.php');

// @
new Bootgly;
