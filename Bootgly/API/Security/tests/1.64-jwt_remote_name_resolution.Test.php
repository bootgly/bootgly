<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\JWTRemoteNameResolution;


use function array_map;
use function array_unique;
use function assert;
use function count;
use function in_array;
use function serialize;
use function str_contains;
use function unserialize;
use Closure;
use ReflectionMethod;
use ReflectionProperty;

use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\JWT\Remote;


return new Test(
   description: 'JWT Remote name resolution: literals, loopback names and empty answers',
   test: function () {
      $Remote = new Remote('https://keys.example.test/jwks');
      $Discover = new ReflectionMethod($Remote, 'discover');

      // @ Literals — no lookup, canonical form
      yield assert(
         assertion: $Discover->invoke($Remote, '::ffff:93.184.216.34') === ['93.184.216.34']
            && $Discover->invoke($Remote, '::FFFF:5DB8:D822') === ['93.184.216.34'],
         description: 'an IPv4-mapped IPv6 literal resolves to the IPv4 address it carries'
      );
      yield assert(
         assertion: $Discover->invoke($Remote, '2606:2800:0220:0001:0248:1893:25C8:1946')
            === ['2606:2800:220:1:248:1893:25c8:1946'],
         description: 'a non-canonical IPv6 literal resolves to its canonical form'
      );

      // @ Names — the system resolver, read through the loopback name only
      $loopback = $Discover->invoke($Remote, 'localhost');
      $loopbacks = true;
      foreach ($loopback as $address) {
         $loopbacks = $loopbacks && in_array($address, ['::1', '127.0.0.1'], true);
      }
      yield assert(
         assertion: $loopback !== [] && $loopbacks && count($loopback) === count(array_unique($loopback)),
         description: 'localhost resolves to its loopback addresses, each once'
      );
      yield assert(
         assertion: $Discover->invoke($Remote, '') === [],
         description: 'a name with no answer resolves to no address'
      );

      // @ A Remote of the system's resolver still serializes
      $Copy = unserialize(serialize($Remote));
      yield assert(
         assertion: $Copy instanceof Remote
            && (new ReflectionMethod($Copy, 'discover'))->invoke($Copy, '::ffff:93.184.216.34') === ['93.184.216.34'],
         description: 'a Remote serializes and its copy still resolves'
      );

      // @ The answers of the system resolver, through the ABI resolver's sources
      $Seam = new ReflectionProperty(Remote::class, 'Resolver');
      $Answer = static fn (false|array $answers): Closure => static fn (string $host): false|array => $answers === false
         ? false
         : array_map(
            static fn (string $IP): array => ['ai_addr' => str_contains($IP, ':') ? ['sin6_addr' => $IP] : ['sin_addr' => $IP]],
            $answers
         );

      $Seam->setValue($Remote, new Resolver($Answer(['2001:DB8::1', '192.0.2.7', '::ffff:192.0.2.7'])));
      yield assert(
         assertion: $Discover->invoke($Remote, 'keys.example.test') === ['2001:db8::1', '192.0.2.7'],
         description: 'a name resolves to its canonical answers in resolver order, each once'
      );
      $Seam->setValue($Remote, new Resolver($Answer(['192.0.2.7', 'not-an-address'])));
      $void = $Discover->invoke($Remote, 'keys.example.test');
      $Seam->setValue($Remote, new Resolver($Answer(false)));
      $empty = $Discover->invoke($Remote, 'keys.example.test');
      yield assert(
         assertion: $void === [] && $empty === [],
         description: 'an answer that is not an address voids the lookup; a failed one resolves to nothing'
      );

      // @ Without ext-sockets: the IPv4 answers, then the absolute name's AAAA records
      $queried = [];
      $Seam->setValue($Remote, new Resolver(
         false,
         static fn (string $host): array => ['192.0.2.7'],
         static function (string $name) use (&$queried): array {
            $queried[] = $name;

            return [['ipv6' => '2001:db8::7']];
         },
      ));
      yield assert(
         assertion: $Discover->invoke($Remote, 'Keys.Example.Test') === ['192.0.2.7', '2001:db8::7']
            && $Discover->invoke($Remote, 'localhost') === ['192.0.2.7']
            && $queried === ['keys.example.test.'],
         description: 'without ext-sockets, the absolute name is queried for AAAA records, localhost never'
      );
   }
);
