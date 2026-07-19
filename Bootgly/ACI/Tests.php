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


   /**
    * @param null|string $registry Registry file override (`null` = the context registry).
    * @param string $prefix Directory prefix for nested registries (e.g. a platform run from a kit).
    */
   public function __construct (null|string $registry = null, string $prefix = '')
   {
      // !
      /** @var Suites|false $Suites */
      $Suites = match (true) {
         $registry !== null
            // Explicit registry (e.g. a platform registry from a kit)
            => include $registry,
         BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR
            // Author (Bootgly) Test Suites
            => include BOOTGLY_ROOT_DIR . '/tests/' . BOOTSTRAP_FILENAME,
         // Consumer (User) Test Suites
         default => include BOOTGLY_WORKING_DIR . '/tests/' . BOOTSTRAP_FILENAME
      };

      // ?
      if ($Suites instanceof Suites === false) {
         throw new RuntimeException('Invalid Test Suites Specification');
      }

      // ? Nested registries resolve their directories behind the given folder
      if ($prefix !== '') {
         foreach ($Suites->directories as $index => $directory) {
            $Suites->directories[$index] = $prefix . $directory;
         }
      }

      // @
      $this->Suites = $Suites;
   }
}
