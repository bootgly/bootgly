<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\Resolver\Tests\Fallback;


use function assert;
use Closure;

use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Resolver: without ext-sockets, IPv4 answers then the absolute name\'s AAAA records',
   test: function () {
      // ! Stand-ins for gethostbynamel() and dns_get_record(DNS_AAAA) that record what they are asked
      $listed = [];
      $queried = [];
      $List = static function (false|array $answers) use (&$listed): Closure {
         return static function (string $host) use ($answers, &$listed): false|array {
            $listed[] = $host;

            return $answers;
         };
      };
      $Query = static function (false|array $records) use (&$queried): Closure {
         return static function (string $name) use ($records, &$queried): false|array {
            $queried[] = $name;

            return $records;
         };
      };
      $AAAA = static fn (string $IP): array => ['host' => 'x', 'class' => 'IN', 'ttl' => 60, 'type' => 'AAAA', 'ipv6' => $IP];

      // @ Order: IPv4 answers first, then AAAA records
      $Resolver = new Resolver(false, $List(['192.0.2.1', '192.0.2.2']), $Query([$AAAA('2001:DB8::1'), $AAAA('2001:db8::2')]));
      yield assert(
         assertion: $Resolver->resolve('Example.Test') === ['192.0.2.1', '192.0.2.2', '2001:db8::1', '2001:db8::2']
            && $listed === ['Example.Test']
            && $queried === ['example.test.'],
         description: 'the IPv4 list sees the name as given; the AAAA query the lowercased absolute name'
      );

      // @ The absolute name — no search list, whatever the input's trailing dots
      $listed = $queried = [];
      $Resolver = new Resolver(false, $List(false), $Query([$AAAA('2001:db8::3')]));
      $one = $Resolver->resolve('ca.example.test.');
      $two = $Resolver->resolve('ca.example.test..');
      yield assert(
         assertion: $one === ['2001:db8::3'] && $two === ['2001:db8::3']
            && $listed === ['ca.example.test.', 'ca.example.test..']
            && $queried === ['ca.example.test.', 'ca.example.test.'],
         description: 'a name with one or more terminal dots is queried once-dotted'
      );

      // @ Never a DNS query for localhost
      $listed = $queried = [];
      $Resolver = new Resolver(false, $List(['127.0.0.1']), $Query([$AAAA('2001:db8::66')]));
      $loopbacks = [];
      foreach (['localhost', 'LOCALHOST', 'localhost.', 'localhost..', 'app.localhost', 'App.LocalHost.'] as $name) {
         $loopbacks[] = $Resolver->resolve($name);
      }
      yield assert(
         assertion: $queried === []
            && $loopbacks === [['127.0.0.1'], ['127.0.0.1'], ['127.0.0.1'], ['127.0.0.1'], ['127.0.0.1'], ['127.0.0.1']]
            && $listed === ['localhost', 'LOCALHOST', 'localhost.', 'localhost..', 'app.localhost', 'App.LocalHost.'],
         description: 'localhost and *.localhost, in any case and with terminal dots, never reach DNS'
      );
      $queried = [];
      $Resolver->resolve('localhost.example.test');
      $Resolver->resolve('notlocalhost');
      yield assert(
         assertion: $queried === ['localhost.example.test.', 'notlocalhost.'],
         description: 'a name that only contains "localhost" is still queried'
      );

      // @ Duplicates and absent answers
      $Resolver = new Resolver(false, $List(['192.0.2.1', '192.0.2.1']), $Query([$AAAA('::ffff:192.0.2.1'), ['type' => 'AAAA'], $AAAA('::1')]));
      yield assert(
         assertion: $Resolver->resolve('dup.test') === ['192.0.2.1', '::1'],
         description: 'an address answered twice is returned once; a record without an address is skipped'
      );
      $Resolver = new Resolver(false, $List(false), $Query(false));
      yield assert(
         assertion: $Resolver->resolve('nowhere.test') === [],
         description: 'no IPv4 answer and no AAAA record resolve to no address'
      );

      // @ Void
      $Resolver = new Resolver(false, $List(['192.0.2.1', 'bogus']), $Query(false));
      $listVoid = $Resolver->resolve('void.test');
      $Resolver = new Resolver(false, $List(['192.0.2.1']), $Query([$AAAA('not-an-address')]));
      $queryVoid = $Resolver->resolve('void.test');
      $Resolver = new Resolver(false, $List([42]), $Query(false));
      $typeVoid = $Resolver->resolve('void.test');
      yield assert(
         assertion: $listVoid === null && $queryVoid === null && $typeVoid === null,
         description: 'an IPv4 answer or an AAAA record that is not an address voids the lookup (null, not [])'
      );
   }
);
