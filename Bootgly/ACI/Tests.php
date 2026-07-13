<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_WORKING_DIR;
use RuntimeException;

use const Bootgly\ABI\BOOTSTRAP_FILENAME;
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
         ? include BOOTGLY_ROOT_DIR . '/tests/' . BOOTSTRAP_FILENAME
         // Consumer (User) Test Suites
         : include BOOTGLY_WORKING_DIR . '/tests/' . BOOTSTRAP_FILENAME;

      // ?
      if ($Suites instanceof Suites === false) {
         throw new RuntimeException('Invalid Test Suites Specification');
      }

      // @
      $this->Suites = $Suites;
   }
}
