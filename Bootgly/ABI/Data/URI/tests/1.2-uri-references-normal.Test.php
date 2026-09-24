<?php

namespace Bootgly\ABI\Data\URI\Tests;

use function assert;
use function var_export;

use Bootgly\ABI\Data\URI;
use Bootgly\ACI\Tests\Suite\Test;

/**
 * RFC 3986 §5.4.1 — every normal reference-resolution example, against the
 * base `http://a/b/c/d;p?q`. Two documented deviations: `g:h` (opaque) is
 * refused, and fragments are dropped.
 */

return new Test(
   description: 'URI resolves every RFC 3986 §5.4.1 normal example',
   test: function () {
      $Base = new URI('http://a/b/c/d;p?q');

      $examples = [
         'g:h'     => null, // ! deviation: opaque — nothing to dial
         'g'       => 'http://a/b/c/g',
         './g'     => 'http://a/b/c/g',
         'g/'      => 'http://a/b/c/g/',
         '/g'      => 'http://a/g',
         '//g'     => 'http://g',
         '?y'      => 'http://a/b/c/d;p?y',
         'g?y'     => 'http://a/b/c/g?y',
         '#s'      => 'http://a/b/c/d;p?q', // ! deviation: fragment dropped
         'g#s'     => 'http://a/b/c/g',
         'g?y#s'   => 'http://a/b/c/g?y',
         ';x'      => 'http://a/b/c/;x',
         'g;x'     => 'http://a/b/c/g;x',
         'g;x?y#s' => 'http://a/b/c/g;x?y',
         ''        => 'http://a/b/c/d;p?q',
         '.'       => 'http://a/b/c/',
         './'      => 'http://a/b/c/',
         '..'      => 'http://a/b/',
         '../'     => 'http://a/b/',
         '../g'    => 'http://a/b/g',
         '../..'   => 'http://a/',
         '../../'  => 'http://a/',
         '../../g' => 'http://a/g',
      ];

      foreach ($examples as $reference => $expected) {
         $Target = $Base->resolve((string) $reference);
         $actual = $Target === null ? null : (string) $Target;

         yield assert(
            assertion: $actual === $expected,
            description: var_export((string) $reference, true) . ' resolves to ' . var_export($actual, true)
         );
      }
   }
);
