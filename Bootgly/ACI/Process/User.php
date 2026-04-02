<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Process;


use function posix_getpwnam;
use function posix_getpwuid;
use function posix_getuid;
use function posix_setuid;


class User
{
   // * Config
   // ...

   // * Data
   public string $current {
      get {
         $info = posix_getpwuid(posix_getuid());

         return $info !== false ? $info['name'] : '';
      }
   }

   // * Metadata
   public int $id {
      get => posix_getuid();
   }


   /**
    * Set the process user by name.
    *
    * @param string $name The username to switch to.
    *
    * @return bool
    */
   public function set (string $name): bool
   {
      $info = posix_getpwnam($name);

      if ($info === false) {
         return false;
      }

      return posix_setuid($info['uid']);
   }

   /**
    * Get user info by name.
    *
    * @param string $name The username to look up.
    *
    * @return array<string,mixed>|false
    */
   public function info (string $name): array|false
   {
      return posix_getpwnam($name);
   }
}
