<?php

namespace Bootgly\ABI\Data\URI\Tests;

use function assert;
use function var_export;

use Bootgly\ABI\Data\URI;
use Bootgly\ACI\Tests\Suite\Test;

/**
 * Hostile `Location` values — the shapes a redirect follower must not
 * misread: scheme case, network-path references, opaque and authority-less
 * schemes, control bytes, backslashes, userinfo, dot-segments.
 */

return new Test(
   description: 'URI resolves hostile references into exactly one dialable target, or refuses them',
   test: function () {
      $Base = new URI('https://a/b/c/d;p?q');

      $examples = [
         // # Scheme case never downgrades
         'HTTPS://EXAMPLE.com/X'        => 'https://example.com/X',
         'HTTP://Example.com/'          => 'http://example.com/',
         // # A network-path reference takes the named authority (HCLI-20)
         '//evil.test:8443/x'           => 'https://evil.test:8443/x',
         // # Opaque and authority-less schemes resolve to nothing dialable
         'javascript:alert(1)'          => null,
         'data:text/html,x'             => null,
         'mailto:a@b'                   => null,
         'https:foo'                    => null,
         'https:/etc/passwd'            => null,
         'file:///etc/passwd'           => null,
         // # Any other scheme with an authority is valid — its policy is the caller's
         'gopher://h:70/x'              => 'gopher://h:70/x',
         // # Control bytes, spaces and backslashes are refused
         "/x\r\nSet-Cookie: a=b"        => null,
         "/x\tY"                        => null,
         "/x\x00"                       => null,
         "/x\x7F"                       => null,
         // ! Even inside a fragment that resolution would drop
         "g#frag\x01"                   => null,
         '/x y'                         => null,
         '/\\evil.test'                 => null,
         '\\\\evil.test'                => null,
         'https://good.test\\@evil.test/' => null,
         // # The authority ends at the first `/`: userinfo, not host
         'https://good.test@evil.test/' => 'https://good.test@evil.test/',
         // # Dot-segments are removed on every resolution
         'https://g/a/../keys'          => 'https://g/keys',
         '//g/a/../keys'                => 'https://g/keys',
         // # ...while empty segments and encoded dots survive
         '/a//b'                        => 'https://a/a//b',
         'x//y'                         => 'https://a/b/c/x//y',
         '/a/%2e%2e/b'                  => 'https://a/a/%2e%2e/b',
         // # Query-only and empty references
         '?'                            => 'https://a/b/c/d;p?',
         '#'                            => 'https://a/b/c/d;p?q',
         // # IPv6 targets are canonical
         '//[0:0::1]:8443/v6'           => 'https://[::1]:8443/v6',
         // # A malformed authority is refused
         '//h:0/x'                      => null,
         '//[::1%25eth0]/x'             => null,
      ];

      foreach ($examples as $reference => $expected) {
         $Target = $Base->resolve($reference);
         $actual = $Target === null ? null : (string) $Target;

         yield assert(
            assertion: $actual === $expected,
            description: var_export($reference, true) . ' resolves to ' . var_export($actual, true)
         );
      }

      // # Resolution never changes the base
      yield assert(
         assertion: (string) $Base === 'https://a/b/c/d;p?q',
         description: "the base URI is unchanged after every resolution: {$Base}"
      );
   }
);
