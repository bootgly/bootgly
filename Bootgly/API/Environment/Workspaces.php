<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment;


use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_WORKING_BASE;
use const BOOTGLY_WORKING_DIR;
use function constant;
use function defined;


/**
 * The checkout the running launcher works in.
 */
enum Workspaces
{
   /** The framework checkout itself — the working base IS the framework. */
   case Author;
   /** A platform checkout (bootgly-console / bootgly-web) developing itself. */
   case Platform;
   /** A kit — the framework and the platforms are pinned submodules of the working base. */
   case Kit;


   /**
    * Detect the workspace from the working base the launcher defined.
    *
    * @return self
    */
   public static function detect (): self
   {
      // ?: The working base is the framework root
      if (BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR) {
         return self::Author;
      }

      // ?: A platform's own root is the working base
      foreach (['CONSOLE_ROOT_BASE', 'WEB_ROOT_BASE'] as $root) {
         if (defined($root) === true && constant($root) === BOOTGLY_WORKING_BASE) {
            return self::Platform;
         }
      }

      // :
      return self::Kit;
   }
}
