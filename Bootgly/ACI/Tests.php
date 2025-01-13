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


use RuntimeException;

use Bootgly\ABI\Resources;
use Bootgly\ACI\Tests\Suites;


class Tests
{
   use Resources;


   public Suites $Suites;


   public function __construct()
   {
      // !
      /** @var Suites|false $Suites */
      $Suites = BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR
         // Author (Bootgly) Test Suites
         ? include BOOTGLY_ROOT_DIR . '/tests/' . self::BOOTSTRAP_FILENAME
         // Consumer (User) Test Suites
         : include BOOTGLY_WORKING_DIR . '/tests/' . self::BOOTSTRAP_FILENAME;

      // ?
      if ($Suites instanceof Suites === false) {
         throw new RuntimeException('Invalid Test Suites Specification');
      }

      // @
      $this->Suites = $Suites;
   }
}
