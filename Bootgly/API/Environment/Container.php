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


use function file_exists;
use function getenv;


class Container
{
   // * Data
   /**
    * Files a container runtime leaves behind — Docker, then Podman. The one
    * source for every "am I inside a container?" question the framework asks.
    *
    * @var array<int,string>
    */
   public const array MARKERS = ['/.dockerenv', '/run/.containerenv'];


   /**
    * Detect whether the current process runs inside a container: the image's
    * own `BOOTGLY_DOCKER`, or a runtime marker file — either one, so an image
    * that erases the variable and a runtime that leaves no marker (containerd)
    * are both still recognized. A hint about where the process runs, never a
    * security verdict on its own.
    *
    * @return bool
    */
   public static function detect (): bool
   {
      // ? The image's own variable
      if ((string) getenv('BOOTGLY_DOCKER') !== '') {
         return true;
      }

      // @ The runtime's marker files
      foreach (self::MARKERS as $marker) {
         if (file_exists($marker) === true) {
            return true;
         }
      }

      // :
      return false;
   }
}
