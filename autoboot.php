<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
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

define('BOOTGLY_VERSION', '0.6.0-beta');

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
   $paths = explode('\\', $class);
   $file = implode('/', $paths) . '.php';

   $included = @include(BOOTGLY_WORKING_DIR . $file);

   if ($included === false && BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
      @include(BOOTGLY_ROOT_DIR . $file);
   }
});

// ! Resources ([a-z])
// ...

// @
/**
 * @var Bootgly Bootgly
 */
const Bootgly = new Bootgly;
Bootgly->autoboot();
