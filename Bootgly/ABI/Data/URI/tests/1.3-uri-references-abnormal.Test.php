<?php

namespace Bootgly\ABI\Data\URI\Tests;

use function assert;
use function var_export;

use Bootgly\ABI\Data\URI;
use Bootgly\ACI\Tests\Suite\Test;

/**
 * RFC 3986 §5.4.2 — every abnormal reference-resolution example, against the
 * base `http://a/b/c/d;p?q`. `http:g` is refused (strict parser: a scheme
 * without an authority), and fragments are dropped.
 */

return new Test(
   description: 'URI resolves every RFC 3986 §5.4.2 abnormal example',
   test: function () {
      $Base = new URI('http://a/b/c/d;p?q');

      $examples = [
         '../../../g'    => 'http://a/g',
         '../../../../g' => 'http://a/g',
         '/./g'          => 'http://a/g',
         '/../g'         => 'http://a/g',
         'g.'            => 'http://a/b/c/g.',
         '.g'            => 'http://a/b/c/.g',
         'g..'           => 'http://a/b/c/g..',
         '..g'           => 'http://a/b/c/..g',
         './../g'        => 'http://a/b/g',
         './g/.'         => 'http://a/b/c/g/',
         'g/./h'         => 'http://a/b/c/g/h',
         'g/../h'        => 'http://a/b/c/h',
         'g;x=1/./y'     => 'http://a/b/c/g;x=1/y',
         'g;x=1/../y'    => 'http://a/b/c/y',
         'g?y/./x'       => 'http://a/b/c/g?y/./x',
         'g?y/../x'      => 'http://a/b/c/g?y/../x',
         'g#s/./x'       => 'http://a/b/c/g',
         'g#s/../x'      => 'http://a/b/c/g',
         'http:g'        => null, // ! strict: a scheme without an authority
      ];

      foreach ($examples as $reference => $expected) {
         $Target = $Base->resolve($reference);
         $actual = $Target === null ? null : (string) $Target;

         yield assert(
            assertion: $actual === $expected,
            description: var_export($reference, true) . ' resolves to ' . var_export($actual, true)
         );
      }
   }
);
