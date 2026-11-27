<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\Bounds;


use const PHP_BINARY;
use function array_filter;
use function assert;
use function count;
use function escapeshellarg;
use function hrtime;
use function json_decode;
use function json_encode;
use function microtime;
use function shell_exec;
use Fiber;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Query;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;


return new Test(
   description: 'DNS_Client_CLI resolve() bounds: the caller\'s deadline, the caller\'s loop, no kept failure off a worker',
   test: function () {
      $Harness = Harness::start('dns-bounds');
      $NS = $Harness->Nameserver;
      $Bridge = static fn (mixed $value = null): mixed => Fiber::suspend($value);
      // ! A system resolver that records its calls and answers what `$ladder` holds
      $calls = [];
      $ladder = [];
      $Answer = static function (string $host) use (&$calls, &$ladder): array|false {
         $calls[] = $host;
         $addresses = $ladder[$host] ?? [];

         return $addresses === [] ? false : $addresses;
      };
      $Ladder = new Resolver(
         Lookup: static function (string $host) use ($Answer): array|false {
            $addresses = $Answer($host);
            if ($addresses === false) {
               return false;
            }

            $infos = [];
            foreach ($addresses as $address) {
               $infos[] = ['ai_addr' => ['sin_addr' => $address]];
            }

            return $infos;
         },
         List: static fn (string $host): array|false => $Answer($host),
         Query: static fn (string $host): false => false
      );
      $asked = static fn (string $name): int => count(array_filter(
         $NS->log(), static fn (array $Entry): bool => $Entry['name'] === $name
      ));

      try {
         $DNS = new DNS_Client_CLI()->configure($Harness->configure(['Resolver' => $Ladder, 'timeout' => 2, 'hostname' => 'bounds.example']));

         // @ A parked lookup ends at the caller's deadline, not at the resolver's timeout
         $NS->script('late.test', DNS::TYPE_A, ['drop' => true]);
         $NS->script('late.test', DNS::TYPE_AAAA, ['drop' => true]);
         $Late = null;
         $took = 0.0;
         Harness::run([
            static function () use ($DNS, $Bridge, &$Late, &$took): void {
               $start = microtime(true);
               $Late = $DNS->resolve('late.test', $Bridge, (int) hrtime(true) + 300_000_000);
               $took = microtime(true) - $start;
            },
         ]);
         yield assert(
            assertion: $Late === [] && $took >= 0.25 && $took < 0.6,
            description: 'a parked lookup returns unresolved at the caller\'s deadline: ' . json_encode([$Late, $took])
         );

         // @ A deadline already past: no query, no system resolver
         $calls = [];
         $Past = null;
         Harness::run([
            static function () use ($DNS, $Bridge, &$Past): void {
               $Past = $DNS->resolve('past.test', $Bridge, (int) hrtime(true) - 1);
            },
         ]);
         yield assert(
            assertion: $Past === [] && $asked('past.test') === 0 && $calls === [],
            description: 'a lookup past its deadline asks nothing: ' . json_encode([$Past, $asked('past.test'), $calls])
         );

         // @ A caller on another loop than the worker's goes to the system resolver, never parks
         $NS->script('other.test', DNS::TYPE_A, ['answers' => [['other.test', DNS::TYPE_A, 60, '192.0.2.41']]]);
         $NS->script('other.test', DNS::TYPE_AAAA, ['answers' => []]);
         $ladder['other.test'] = ['192.0.2.42'];
         $calls = [];
         $Foreign = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST)->Event;
         $Other = null;
         $Own = null;
         Harness::run([
            static function () use ($DNS, $Bridge, $Foreign, &$Other): void {
               $Other = $DNS->resolve('other.test', $Bridge, null, $Foreign);
            },
         ]);
         yield assert(
            assertion: $Other === ['192.0.2.42'] && $asked('other.test') === 0 && $calls === ['other.test'],
            description: 'a lookup from another loop uses the system resolver: ' . json_encode([$Other, $asked('other.test'), $calls])
         );

         // ! The worker's own loop parks (a new configuration: the ladder answer above is cached)
         $Worker = new DNS_Client_CLI()->configure($Harness->configure(['Resolver' => $Ladder, 'hostname' => 'worker.example']));
         $calls = [];
         Harness::run([
            static function () use ($Worker, $Bridge, &$Own): void {
               $Own = $Worker->resolve('other.test', $Bridge, null, $Worker->Reactor);
            },
         ]);
         yield assert(
            assertion: $Own === ['192.0.2.41'] && $asked('other.test') === 2 && $calls === [],
            description: 'a lookup from the worker\'s loop parks on the wire: ' . json_encode([$Own, $asked('other.test'), $calls])
         );

         // @ The system resolver's failure is kept on a worker only
         $State = Harness::state($DNS);
         $calls = [];
         $now = Query::clock();
         $State->climb('gone.test', $now, negative: false);
         $State->climb('gone.test', $now, negative: false);
         $ladder['gone.test'] = ['192.0.2.43'];
         $Back = $State->climb('gone.test', $now, negative: false);
         yield assert(
            assertion: $calls === ['gone.test', 'gone.test', 'gone.test'] && $Back === ['192.0.2.43'],
            description: 'off a worker an unresolved name is asked again: ' . json_encode([$calls, $Back])
         );

         unset($ladder['gone.test']);
         $calls = [];
         $State->climb('kept.test', $now);
         $State->climb('kept.test', $now);
         yield assert(
            assertion: $calls === ['kept.test'],
            description: 'on a worker an unresolved name is kept: ' . json_encode($calls)
         );

         // ! Through resolve(): a worker exists (the harness loop), no wait bridge — the failure is kept
         $calls = [];
         $DNS->resolve('unwaited.test');
         $DNS->resolve('unwaited.test');
         yield assert(
            assertion: $calls === ['unwaited.test'],
            description: 'a lookup that cannot park on a worker keeps the failure: ' . json_encode($calls)
         );

         // @ A deadline already past asks nothing on the system resolver's path either
         $ladder['ladder-past.test'] = ['192.0.2.44'];
         $calls = [];
         $Gone = $DNS->resolve('ladder-past.test', null, (int) hrtime(true) - 1);
         yield assert(
            assertion: $Gone === [] && $calls === [],
            description: 'a lookup past its deadline never reaches the system resolver: ' . json_encode([$Gone, $calls])
         );
      }
      finally {
         $Harness->stop();
      }

      // @ Off a worker — a fresh process: a server loop, once installed, stays — resolve() keeps no failure
      $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/offworker.php'));
      $Off = json_decode($output, true);
      yield assert(
         assertion: $Off === [true, [], [], ['192.0.2.45'], ['offworker.test', 'offworker.test', 'offworker.test']],
         description: 'off a worker an unresolved name is asked again through resolve(): ' . $output
      );
   }
);
