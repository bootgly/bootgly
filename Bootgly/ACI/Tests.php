<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


use const BOOTGLY_ROOT_DIR;
use RuntimeException;

use const Bootgly\ABI\BOOTSTRAP_FILENAME;
use Bootgly\ABI\Resources;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suites;


class Tests
{
   use Resources;


   public Suites $Suites;

   // * Metadata
   // # Scope — recorded for suites that re-exec the runner (E2E children):
   // the registry a suite belongs to and the selector that picks it are facts
   // of the RUN, not of the working directory the child would guess from.
   /** The registry file the current run included — absolute path; empty
    *  before a run and on merged multi-project runs (no single registry). */
   public static string $registry = '';
   /** The scope selector the run used — a platform flag (`--web`), a project
    *  prefix (`projects/App/`), `projects/` (merged) or empty (context). */
   public static string $scope = '';


   /**
    * @param null|string $registry Registry file override (`null` = the author context registry).
    * @param string $prefix Directory prefix for nested registries (e.g. a platform or project run from a kit).
    */
   public function __construct (null|string $registry = null, string $prefix = '')
   {
      $registry ??= BOOTGLY_ROOT_DIR . 'tests/' . BOOTSTRAP_FILENAME;
      self::$registry = $registry;

      // !
      /** @var Suite|Suites|false $Suites */
      $Suites = include $registry;

      // ? A registry LISTS suites; it is never one. A registry that returned a
      //   Suite had to be evaluated twice — once here, and again as the
      //   bootstrap of the `tests/` directory it stood for — which makes a
      //   `class`, `function` or `define()` inside it fatal and runs every
      //   `pretest()` twice. One file, one meaning.
      if ($Suites instanceof Suite === true) {
         throw new RuntimeException(
            "Test registry must return Suites, not a Suite: {$registry}"
            . ' — move the suite into a directory of its own'
            . " (e.g. `tests/example/" . BOOTSTRAP_FILENAME . '`) and return'
            . " `new Suites(directories: ['tests/example/'])` here."
         );
      }
      // ?
      if ($Suites instanceof Suites === false) {
         throw new RuntimeException("Invalid Test Suites Specification: {$registry}");
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
