<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


use Bootgly\ABI\Debugging\Data\Throwables;


if (\PHP_SAPI === 'cli') {
   // @ Get Throwables Verbosity
   $verbosity = Throwables::$verbosity;
   // @ Set Throwables Verbosity
   Throwables::$verbosity = 1;


   // Tests
   // @ Check zend.assertions configuration
   if (\function_exists('ini_get') && \ini_get('zend.assertions') !== '1') {
      throw new \Exception(
         message: 'Please, set `zend.assertions` to `1` in php.ini [Assertion].'
      );
   }


   // @ Restore Throwables Verbosity
   Throwables::$verbosity = $verbosity;
}

namespace Bootgly\ACI\Tests\Assertion\Comparators;


use Bootgly\ACI\Tests\Assertion\Comparators\Identical;


// constants
const Identical = new Identical();
