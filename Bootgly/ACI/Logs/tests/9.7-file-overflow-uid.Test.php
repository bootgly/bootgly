<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Logs\Handlers\File;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * Which uid a privileged writer may treat as root's, by uid map.
 *
 * Outside a user namespace, none. Inside one, the overflow uid stands for an
 * owner with no identity here — unless the map covers it, as a rootless
 * container's does: then 65534 is somebody, and a foreign identity.
 */
return new Test(
   description: 'File::exempt() treats the overflow uid as root\'s only inside a namespace whose map is readable and does not cover it',
   test: function () {
      $cases = [
         'host identity map' => ["         0          0 4294967295\n", -1],
         'no map at all' => ['', -1],
         'narrow map: only root mapped' => ["         0       1000          1\n", 65534],
         'the suite\'s map: 1..65536 covers 65534' => ["         0       1000          1\n         1     100000      65536\n", -1],
         'rootless podman: 1..65536 covers 65534' => ["         0       1000          1\n         1     524288      65536\n", -1],
         'a map short of 65534' => ["         0       1000          1\n         1     100000       1000\n", 65534],
         'the far boundary: 1..65534 covers 65534' => ["         0       1000          1\n         1     100000      65534\n", -1],
         'one short of it: 1..65533 does not' => ["         0       1000          1\n         1     100000      65533\n", 65534],
         'a zero-length range maps nothing' => ["         0       1000          0\n", 65534],
         'a map this parser cannot read exempts nobody' => ["         0          0 4294967295 extra\n", -1],
      ];
      foreach ($cases as $name => [$map, $expected]) {
         $exempt = File::exempt($map, 65534);

         yield assert(
            assertion: $exempt === $expected,
            description: "$name → " . var_export($exempt, true) . ' (expected ' . var_export($expected, true) . ')'
         );
      }
   }
);
