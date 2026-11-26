<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\Resolver\Tests\Literals;


use function assert;
use Closure;

use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Resolver: IP literals are canonical and need no lookup',
   test: function () {
      // ! Sources that record every call
      $calls = 0;
      $Record = static function () use (&$calls): Closure {
         return static function () use (&$calls): false {
            $calls++;

            return false;
         };
      };
      $Resolver = new Resolver($Record(), $Record(), $Record());
      $Fallback = new Resolver(false, $Record(), $Record());

      // @ normalize()
      yield assert(
         assertion: Resolver::normalize('93.184.216.34') === '93.184.216.34'
            && Resolver::normalize('2606:2800:0220:0001:0248:1893:25C8:1946') === '2606:2800:220:1:248:1893:25c8:1946'
            && Resolver::normalize('::1') === '::1',
         description: 'normalize() keeps IPv4 dotted quads and shortens IPv6 literals'
      );
      yield assert(
         assertion: Resolver::normalize('::ffff:127.0.0.1') === '127.0.0.1'
            && Resolver::normalize('::FFFF:5DB8:D822') === '93.184.216.34'
            && Resolver::normalize('0:0:0:0:0:ffff:a00:1') === '10.0.0.1',
         description: 'normalize() turns an IPv4-mapped IPv6 address into its IPv4 address'
      );
      yield assert(
         assertion: Resolver::normalize('::127.0.0.1') === '::127.0.0.1'
            && Resolver::normalize('64:ff9b::7f00:1') === '64:ff9b::7f00:1',
         description: 'normalize() keeps IPv4-compatible and NAT64 addresses as IPv6'
      );
      yield assert(
         assertion: Resolver::normalize('localhost') === null
            && Resolver::normalize('010.1.1.1') === null
            && Resolver::normalize('2130706433') === null
            && Resolver::normalize('[::1]') === null
            && Resolver::normalize('fe80::1%lo') === null
            && Resolver::normalize('') === null,
         description: 'normalize() refuses names, legacy numeric forms, brackets, zones and the empty string'
      );
      yield assert(
         assertion: Resolver::normalize("127.0.0.1\0") === null
            && Resolver::normalize("::1\0x") === null,
         description: 'normalize() refuses a literal holding a NUL byte instead of throwing'
      );

      // @ resolve() of a literal
      yield assert(
         assertion: $Resolver->resolve('::ffff:93.184.216.34') === ['93.184.216.34']
            && $Resolver->resolve('2606:2800:0220:0001:0248:1893:25C8:1946') === ['2606:2800:220:1:248:1893:25c8:1946']
            && $Fallback->resolve('127.0.0.1') === ['127.0.0.1']
            && $Fallback->resolve('::1') === ['::1'],
         description: 'resolve() returns one canonical address for an IP literal'
      );
      yield assert(
         assertion: $calls === 0,
         description: 'resolve() of an IP literal asks no source'
      );
   }
);
