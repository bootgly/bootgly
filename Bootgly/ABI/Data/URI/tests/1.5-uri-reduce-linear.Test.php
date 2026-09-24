<?php

namespace Bootgly\ABI\Data\URI\Tests;

use function assert;
use function hrtime;
use function sprintf;
use function str_repeat;
use function strlen;

use Bootgly\ABI\Data\URI;
use Bootgly\ACI\Tests\Suite\Test;

/**
 * Dot-segment removal runs in linear time — a hostile `Location` costs
 * milliseconds whatever its size, never seconds.
 */

return new Test(
   description: 'URI resolves a huge hostile reference in linear time',
   test: function () {
      $Base = new URI('http://a/b/c');

      // ! 256 KiB each — four times the largest response head: a quadratic pass
      //   costs seconds here, a linear one a few milliseconds
      $references = [
         'empty segments' => 'x' . str_repeat('/', 262144),
         'dot segments' => str_repeat('/.', 131072),
         'climbing segments' => str_repeat('/a/..', 52428),
      ];
      foreach ($references as $shape => $reference) {
         $started = hrtime(true);
         $Target = $Base->resolve($reference);
         $elapsed = (hrtime(true) - $started) / 1e6;

         yield assert(
            assertion: $Target !== null && $elapsed < 250,
            description: sprintf('%s (%d bytes) resolved in %.1f ms', $shape, strlen($reference), $elapsed)
         );
      }
   }
);
