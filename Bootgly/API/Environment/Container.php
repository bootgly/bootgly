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
    * Whether the current process runs inside a container: the image's own
    * `BOOTGLY_DOCKER`, or a marker file the runtime leaves — either one.
    *
    * Each signal fails in its own direction: the variable can be erased from
    * outside (`docker run -e BOOTGLY_DOCKER=`) and the markers are absent
    * under a runtime that leaves none (containerd), so neither is trusted
    * alone. What it gates is what the image relies on — the default demotion
    * of a root launch to the runtime account, the handover of what root
    * writes into a mounted `projects/`, the wording of the `kit` refusal —
    * and every one of those moves toward LESS privilege when armed: arming
    * it from outside costs privilege, and disarming it would need both the
    * variable erased and no marker present. It is never a security verdict
    * on its own — a canary must not read the variable (see the docker-context
    * fixtures of the Environment suite).
    *
    * @param array<int,string> $markers The marker files to look for — the shipped set, or a spec's fixtures.
    *
    * @return bool
    */
   public static function check (array $markers = self::MARKERS): bool
   {
      // ? The image's own variable
      if ((string) getenv('BOOTGLY_DOCKER') !== '') {
         return true;
      }

      // @ The runtime's marker files
      foreach ($markers as $marker) {
         if (file_exists($marker) === true) {
            return true;
         }
      }

      // :
      return false;
   }
}
