<?php

namespace Bootgly\API\Environment;


use function assert;
use function file_exists;
use function getenv;
use function putenv;
use function var_export;

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Container::detect() answers from BOOTGLY_DOCKER or from a runtime marker file — a blank variable is no signal',
   test: function () {
      $previous = getenv('BOOTGLY_DOCKER');
      // ! What the runtime says about THIS process, read independently
      $marked = false;
      foreach (Container::MARKERS as $marker) {
         if (file_exists($marker) === true) {
            $marked = true;
         }
      }

      try {
         // @ The image's own variable
         putenv('BOOTGLY_DOCKER=1');
         yield assert(
            assertion: Container::detect() === true,
            description: 'BOOTGLY_DOCKER=1 marks a container wherever the process runs'
         );

         // @ Erased or absent: the marker files decide, so an image that resets
         //   the variable is still recognized by a runtime that leaves one
         putenv('BOOTGLY_DOCKER=');
         yield assert(
            assertion: Container::detect() === $marked,
            description: 'a BLANK variable is no signal — the marker files decide (here: ' . var_export($marked, true) . ')'
         );
         putenv('BOOTGLY_DOCKER');
         yield assert(
            assertion: Container::detect() === $marked,
            description: 'unset, the marker files decide the same way'
         );

         // @ The markers themselves
         yield assert(
            assertion: Container::MARKERS === ['/.dockerenv', '/run/.containerenv'],
            description: 'the markers cover Docker and Podman — ' . var_export(Container::MARKERS, true)
         );
      }
      finally {
         putenv($previous === false ? 'BOOTGLY_DOCKER' : "BOOTGLY_DOCKER={$previous}");
      }
   }
);
