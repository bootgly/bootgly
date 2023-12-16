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


// Tests
// @ Check zend.assertions configuration
if (\function_exists('ini_get') && \ini_get('zend.assertions') !== '1') {
   throw new \Exception(
      message: 'Please, set `zend.assertions` to `1` in php.ini [Assertion].'
   );
}
// @ Check assert.exception configuration
if (\function_exists('ini_get') && \ini_get('assert.exception') !== '') {
   throw new \Exception(
      message: 'Please, set `assert.exception` to `Off` in php.ini [Assertion].'
   );
}
