<?php

namespace Bootgly\API\Environment;


use function assert;
use function file_put_contents;
use function getenv;
use function putenv;
use function rmdir;
use function unlink;
use function var_export;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Container::check() answers from BOOTGLY_DOCKER or from a runtime marker file — a blank variable is no signal',
   test: function () {
      $previous = getenv('BOOTGLY_DOCKER');
      // ! Marker files of our own, so the answer does not depend on where
      //   this suite happens to run
      $dir = Temporaries::reserve('container-check');
      $docker = "{$dir}/dockerenv";
      $podman = "{$dir}/containerenv";
      $markers = [$docker, $podman];

      try {
         // @ Nothing: no variable, no marker
         putenv('BOOTGLY_DOCKER');
         yield assert(
            assertion: Container::check($markers) === false,
            description: 'no variable and no marker file: a host'
         );

         // @ The runtime's markers, each on its own
         file_put_contents($docker, '');
         yield assert(
            assertion: Container::check($markers) === true,
            description: 'the Docker marker alone marks a container'
         );
         unlink($docker);
         file_put_contents($podman, '');
         yield assert(
            assertion: Container::check($markers) === true,
            description: 'the Podman marker alone marks a container'
         );
         unlink($podman);

         // @ The image's own variable — set, and blank
         putenv('BOOTGLY_DOCKER=1');
         yield assert(
            assertion: Container::check($markers) === true,
            description: 'BOOTGLY_DOCKER=1 marks a container with no marker file (containerd)'
         );
         putenv('BOOTGLY_DOCKER=');
         yield assert(
            assertion: Container::check($markers) === false,
            description: 'a BLANK variable is no signal on its own'
         );

         // @ Erased from outside, the marker still decides
         file_put_contents($docker, '');
         yield assert(
            assertion: Container::check($markers) === true,
            description: '`-e BOOTGLY_DOCKER=` cannot disarm a container the runtime marked'
         );
         unlink($docker);

         // @ The shipped markers
         yield assert(
            assertion: Container::MARKERS === ['/.dockerenv', '/run/.containerenv'],
            description: 'the markers cover Docker and Podman — ' . var_export(Container::MARKERS, true)
         );
      }
      finally {
         putenv($previous === false ? 'BOOTGLY_DOCKER' : "BOOTGLY_DOCKER={$previous}");
         @unlink($docker);
         @unlink($podman);
         @rmdir($dir);
      }
   }
);
