<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\Resolver\Tests\Refusals;


use function array_fill;
use function assert;
use function count;
use function str_repeat;
use Closure;

use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Resolver: hosts no resolver should be asked about resolve to nothing, unasked',
   test: function () {
      // ! Sources that record what they are asked
      $asked = [];
      $Record = static function (string $source) use (&$asked): Closure {
         return static function (string $host) use ($source, &$asked): false {
            $asked[] = "{$source}:{$host}";

            return false;
         };
      };
      $Resolver = new Resolver($Record('lookup'), $Record('list'), $Record('query'));
      $Fallback = new Resolver(false, $Record('list'), $Record('query'));

      // ! A 253-character name: four 63-character labels and the dots between them
      $label = str_repeat('a', 63);
      $longest = "{$label}.{$label}.{$label}." . str_repeat('b', 61);
      $refused = [
         '',
         "localhost\0.example.test",
         "example.test\0",
         'fe80::1%eth0',
         'name%25.test',
         '[::1]',
         '[example.test]',
         '[example.test',
         'example.test]',
         "{$longest}c",
         "{$longest}c.",
         "{$longest}c..",
         "{$longest}..",
      ];

      // @ Refused without asking any source
      $results = [];
      foreach ($refused as $host) {
         $results[] = $Resolver->resolve($host);
         $results[] = $Fallback->resolve($host);
      }
      yield assert(
         assertion: $results === array_fill(0, count($refused) * 2, [])
            && $asked === [],
         description: 'an empty name, a NUL byte, a zone or `%`, either bracket and a name over 253 characters (terminal dots aside but one) resolve to [] unasked'
      );

      // @ The longest name is still asked
      $asked = [];
      $Resolver->resolve($longest);
      $Resolver->resolve("{$longest}.");
      $Fallback->resolve("{$longest}.");
      yield assert(
         assertion: $asked === ["lookup:{$longest}", "lookup:{$longest}.", "list:{$longest}.", "query:{$longest}."],
         description: 'a 253-character name, with or without its terminal dot, is asked'
      );

      // @ The root name: no DNS query for an empty name
      $asked = [];
      $root = $Fallback->resolve('.');
      yield assert(
         assertion: $root === [] && $asked === ['list:.'],
         description: 'the root name "." never reaches DNS'
      );
   }
);
