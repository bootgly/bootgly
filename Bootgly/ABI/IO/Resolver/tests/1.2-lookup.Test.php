<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\Resolver\Tests\Lookup;


use const AF_INET;
use const AF_INET6;
use function assert;
use Closure;

use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Resolver: getaddrinfo answers are canonical, ordered, unique and final',
   test: function () {
      // ! A getaddrinfo stand-in answering scripted `socket_addrinfo_explain()` shapes
      $asked = [];
      $fallbacks = 0;
      $Answer = static function (false|array $answers) use (&$asked): Closure {
         return static function (string $host) use ($answers, &$asked): false|array {
            $asked[] = $host;

            return $answers;
         };
      };
      $Fallback = static function () use (&$fallbacks): false|array {
         $fallbacks++;

         return ['192.0.2.1'];
      };
      $v4 = static fn (string $IP): array => ['ai_family' => AF_INET, 'ai_addr' => ['sin_addr' => $IP, 'sin_port' => 0]];
      $v6 = static fn (string $IP): array => ['ai_family' => AF_INET6, 'ai_addr' => ['sin6_addr' => $IP, 'sin6_port' => 0]];

      // @ Order and families
      $Resolver = new Resolver($Answer([$v6('2001:db8::1'), $v4('192.0.2.7'), $v6('2001:DB8:0:0:0:0:0:2')]), $Fallback, $Fallback);
      yield assert(
         assertion: $Resolver->resolve('Example.Test.') === ['2001:db8::1', '192.0.2.7', '2001:db8::2']
            && $asked === ['Example.Test.'],
         description: 'IPv4 and IPv6 answers come back canonical in resolver order, for the name as given'
      );

      // @ Duplicates — the same address once, an IPv4-mapped answer as its IPv4 address
      $Resolver = new Resolver($Answer([$v4('10.0.0.1'), $v4('10.0.0.1'), $v6('::ffff:10.0.0.1'), $v6('::1')]), $Fallback, $Fallback);
      yield assert(
         assertion: $Resolver->resolve('twice.test') === ['10.0.0.1', '::1'],
         description: 'an address answered twice (once IPv4-mapped) is returned once, at its first place'
      );

      // @ An entry without an address is skipped, not void
      $Resolver = new Resolver($Answer([['ai_family' => 1, 'ai_addr' => ['sun_path' => '/x']], $v4('192.0.2.9')]), $Fallback, $Fallback);
      yield assert(
         assertion: $Resolver->resolve('mixed.test') === ['192.0.2.9'],
         description: 'an answer entry carrying no IP address is skipped'
      );

      // @ Empty vs void
      $Resolver = new Resolver($Answer(false), $Fallback, $Fallback);
      $empty = $Resolver->resolve('nowhere.test');
      $Resolver = new Resolver($Answer([]), $Fallback, $Fallback);
      $none = $Resolver->resolve('nowhere.test');
      yield assert(
         assertion: $empty === [] && $none === [],
         description: 'a failed or empty lookup resolves to no address'
      );
      $Resolver = new Resolver($Answer([$v4('192.0.2.1'), $v4('not-an-address')]), $Fallback, $Fallback);
      $void = $Resolver->resolve('void.test');
      $Resolver = new Resolver($Answer([$v6('fe80::1%eth0')]), $Fallback, $Fallback);
      $zoned = $Resolver->resolve('void.test');
      yield assert(
         assertion: $void === null && $zoned === null,
         description: 'an answer that is not an IP address voids the whole lookup (null, not [])'
      );

      // @ The answer is final: no fallback after getaddrinfo
      yield assert(
         assertion: $fallbacks === 0,
         description: 'with getaddrinfo, the IPv4 list and the DNS query are never asked — even when it fails'
      );
   }
);
