<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

#namespace Bootgly\ACI;


use Bootgly\ACI\Debugger;
use Bootgly\ACI\Tests\Assertions\Assertion;


// Debugger
if (function_exists('debug') === false) {
   function debug (...$vars)
   {
      Debugger::debug(...$vars);
   }
}
if (function_exists('dd') === false) {
   function dd (...$vars)
   {
      Debugger::$exit = true;
      Debugger::$debug = true;
      Debugger::debug(...$vars);
   }
}

// Tests
// @ Set PHP assert options
// 1
assert_options(ASSERT_ACTIVE, 1);
// 2
assert_options(ASSERT_CALLBACK, function (string $file, int $line, ? string $message) {
   Assertion::$fallback = $message;
});
// 3
assert_options(ASSERT_BAIL, 0);
// 4
assert_options(ASSERT_WARNING, 0);
// 5
assert_options(ASSERT_EXCEPTION, 0);
