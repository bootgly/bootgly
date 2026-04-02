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


use function posix_getgid;
use function posix_getgrgid;
use function posix_getgrnam;
use function posix_initgroups;
use function posix_setgid;


class Group
{
   // * Config
   // ...

   // * Data
   public string $current {
      get {
         $info = posix_getgrgid(posix_getgid());

         return $info !== false ? $info['name'] : '';
      }
   }

   // * Metadata
   public int $id {
      get => posix_getgid();
   }


   /**
    * Set the process group by name.
    *
    * @param string $name The group name to switch to.
    *
    * @return bool
    */
   public function set (string $name): bool
   {
      $info = posix_getgrnam($name);

      if ($info === false) {
         return false;
      }

      return posix_setgid($info['gid']);
   }

   /**
    * Initialize supplementary groups for a user.
    *
    * @param string $user The username.
    * @param int $gid The base group ID.
    *
    * @return bool
    */
   public function init (string $user, int $gid): bool
   {
      return posix_initgroups($user, $gid);
   }

   /**
    * Get group info by name.
    *
    * @param string $name The group name to look up.
    *
    * @return array<string,mixed>|false
    */
   public function info (string $name): array|false
   {
      return posix_getgrnam($name);
   }
}
