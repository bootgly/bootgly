<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\Reactor;


use function array_column;
use function array_filter;
use function array_values;
use function assert;
use function count;
use function json_encode;
use function microtime;
use Fiber;
use RuntimeException;
use Throwable;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\State;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;


return new Test(
   description: 'DNS_Client_CLI resolve() on a worker loop: parked lookups, one shared query, bounded waits',
   test: function () {
      $Harness = Harness::start('dns-reactor');
      $NS = $Harness->Nameserver;
      $Bridge = static fn (mixed $value = null): mixed => Fiber::suspend($value);
      // ! A system resolver that counts its calls and answers nothing
      $calls = 0;
      $Ladder = new Resolver(
         Lookup: static function (string $host) use (&$calls): false {
            $calls++;
            return false;
         },
         List: static function (string $host) use (&$calls): false {
            $calls++;
            return false;
         },
         Query: static function (string $host) use (&$calls): false {
            $calls++;
            return false;
         }
      );
      $address = static fn (string $name, string $IP): array => [
         'answers' => [[$name, DNS::TYPE_A, 60, $IP]], 'delay' => 0.3,
      ];
      $empty = static fn (): array => ['answers' => []];
      $queries = static fn (string $name): int => count(array_filter(
         $NS->log(), static fn (array $Entry): bool => $Entry['name'] === $name
      ));

      try {
         $DNS = new DNS_Client_CLI()->configure($Harness->configure(['Resolver' => $Ladder]));

         // @ Three lookups of one name share one query per type; the loop keeps ticking meanwhile
         $NS->script('shared.test', DNS::TYPE_A, $address('shared.test', '192.0.2.11'));
         $NS->script('shared.test', DNS::TYPE_AAAA, $empty());
         $Results = [];
         $ticks = 0;
         $Task = static function (int $index) use ($DNS, $Bridge, &$Results): \Closure {
            return static function () use ($index, $DNS, $Bridge, &$Results): void {
               $Results[$index] = $DNS->resolve('shared.test', $Bridge);
            };
         };
         Harness::run([$Task(0), $Task(1), $Task(2)], Setup: static function ($Loop) use (&$ticks): void {
            $Loop->repeat(0.01, static function () use (&$ticks): void {
               $ticks++;
            });
         });
         yield assert(
            assertion: $Results === [['192.0.2.11'], ['192.0.2.11'], ['192.0.2.11']] && $queries('shared.test') === 2,
            description: 'three parked lookups get one answer from one A and one AAAA query: ' . json_encode([$Results, $queries('shared.test')])
         );
         yield assert(
            assertion: $ticks >= 10,
            description: "the worker loop kept running while the lookups waited ({$ticks} ticks in ~0.3s)"
         );

         // @ A silent nameserver parks one lookup; another, answered at once, is not held behind it
         $NS->script('silent.test', DNS::TYPE_A, ['drop' => true]);
         $NS->script('silent.test', DNS::TYPE_AAAA, ['drop' => true]);
         $NS->script('quick.test', DNS::TYPE_A, ['answers' => [['quick.test', DNS::TYPE_A, 60, '192.0.2.12']]]);
         $NS->script('quick.test', DNS::TYPE_AAAA, $empty());
         $Silent = null;
         $Quick = null;
         $took = 0.0;
         Harness::run([
            static function () use ($DNS, $Bridge, &$Silent): void {
               $Silent = $DNS->resolve('silent.test', $Bridge);
            },
            static function () use ($DNS, $Bridge, &$Quick, &$took): void {
               $start = microtime(true);
               $Quick = $DNS->resolve('quick.test', $Bridge);
               $took = microtime(true) - $start;
            },
         ]);
         yield assert(
            assertion: $Quick === ['192.0.2.12'] && $took < 0.5 && $Silent === [],
            description: 'a lookup answered at once returns while another waits on a silent nameserver: ' . json_encode([$Quick, $took, $Silent])
         );
         yield assert(
            assertion: $calls === 0,
            description: "an unresolved name is never retried through the system resolver on the loop ({$calls} calls)"
         );

         // @ A bridge that does not suspend: the lookup gives up instead of spinning
         $NS->script('spin.test', DNS::TYPE_A, $address('spin.test', '192.0.2.13'));
         $NS->script('spin.test', DNS::TYPE_AAAA, $empty());
         $Spin = null;
         $spun = 0.0;
         Harness::run([
            static function () use ($DNS, &$Spin, &$spun): void {
               $start = microtime(true);
               $Spin = $DNS->resolve('spin.test', static fn (mixed $value = null): mixed => $value);
               $spun = microtime(true) - $start;
            },
         ]);
         yield assert(
            assertion: $Spin === [] && $spun < 0.25,
            description: 'a wait bridge that never suspends trips the lookup at once: ' . json_encode([$Spin, $spun])
         );

         // @ The first waiter leaves while the query is in flight (its request is cancelled): the
         //   second still gets the answer
         $NS->script('leader.test', DNS::TYPE_A, $address('leader.test', '192.0.2.14'));
         $NS->script('leader.test', DNS::TYPE_AAAA, $empty());
         $Leader = null;
         $Follower = null;
         $left = -1.0;
         $start = microtime(true);
         // ! Parks for one tick, then its request is gone
         $Cancel = static function (mixed $value = null): mixed {
            Fiber::suspend(null);
            throw new RuntimeException('cancelled');
         };
         Harness::run([
            static function () use ($DNS, $Cancel, &$Leader, &$left, $start): void {
               try {
                  $Leader = $DNS->resolve('leader.test', $Cancel);
               }
               catch (Throwable $Throwable) {
                  $Leader = $Throwable->getMessage();
                  $left = microtime(true) - $start;
               }
            },
            static function () use ($DNS, $Bridge, &$Follower): void {
               $Follower = $DNS->resolve('leader.test', $Bridge);
            },
         ]);
         yield assert(
            assertion: $Leader === 'cancelled' && $left < 0.2 && $Follower === ['192.0.2.14'] && $queries('leader.test') === 2,
            description: 'a first waiter cancelled mid-flight does not strand the next one: ' . json_encode([$Leader, $left, $Follower])
         );

         // @ The hosts file wins over DNS, without a query
         $hosts = $Harness->write('hosts', "192.0.2.15 web.test\n");
         $NS->script('web.test', DNS::TYPE_A, ['answers' => [['web.test', DNS::TYPE_A, 60, '192.0.2.99']]]);
         $Hosted = new DNS_Client_CLI()->configure($Harness->configure(['hosts' => $hosts, 'Resolver' => $Ladder]));
         $Web = null;
         Harness::run([
            static function () use ($Hosted, $Bridge, &$Web): void {
               $Web = $Hosted->resolve('web.test', $Bridge);
            },
         ]);
         yield assert(
            assertion: $Web === ['192.0.2.15'] && $queries('web.test') === 0,
            description: 'a hosts-file name resolves from the file, never over DNS: ' . json_encode($Web)
         );

         // @ `.local`: the system resolver only when an mDNS source comes before dns
         $mDNS = $Harness->write('nsswitch.conf', "hosts: files mdns4_minimal [NOTFOUND=return] dns\n");
         $Multicast = new DNS_Client_CLI()->configure($Harness->configure(['nsswitch' => $mDNS, 'Resolver' => $Ladder]));
         $Unicast = new DNS_Client_CLI()->configure($Harness->configure(['Resolver' => $Ladder]));
         $calls = 0;
         Harness::run([
            static function () use ($Multicast, $Bridge): void {
               $Multicast->resolve('printer.local', $Bridge);
            },
         ]);
         $before = $calls;
         Harness::run([
            static function () use ($Unicast, $Bridge): void {
               $Unicast->resolve('printer.local', $Bridge);
            },
         ]);
         yield assert(
            assertion: $before >= 1 && $calls === $before && $queries('printer.local') === 2,
            description: 'a .local name goes to the system resolver with mDNS configured, over DNS without: ' . json_encode([$before, $calls, $queries('printer.local')])
         );

         // @ A state dropped from the process's table (a 17th configuration) still serves the
         //   lookups already using it
         $NS->reset();
         $NS->script('host.s.test', DNS::TYPE_A, ['rcode' => DNS::RCODE_NXDOMAIN, 'delay' => 0.3]);
         $NS->script('host.s.test', DNS::TYPE_AAAA, ['rcode' => DNS::RCODE_NXDOMAIN, 'delay' => 0.3]);
         $NS->script('host', DNS::TYPE_A, ['answers' => [['host', DNS::TYPE_A, 60, '192.0.2.15']]]);
         $NS->script('host', DNS::TYPE_AAAA, $empty());
         $Tenant = new DNS_Client_CLI()->configure($Harness->configure(['search' => ['s.test'], 'Resolver' => $Ladder]));
         $Evicted = null;
         $failure = null;
         Harness::run([
            static function () use ($Tenant, $Bridge, &$Evicted, &$failure): void {
               try {
                  $Evicted = $Tenant->resolve('host', $Bridge);
               }
               catch (Throwable $Throwable) {
                  $failure = $Throwable->getMessage();
               }
            },
            static function () use ($Harness): void {
               for ($index = 0; $index < 16; $index++) {
                  new DNS_Client_CLI()->configure($Harness->configure(['hostname' => "tenant{$index}.example"]))->resolve('localhost');
               }
            },
         ]);
         yield assert(
            assertion: $failure === null && $Evicted === ['192.0.2.15'],
            description: 'a lookup outlives its state leaving the process table: ' . json_encode([$Evicted, $failure])
         );

         // @ At most 64 queries in flight per process: past the cap a lookup is unresolved at once
         $NS->reset();
         $NS->script('*', 0, ['drop' => true]);
         $Flood = [];
         $Last = null;
         $late = 0.0;
         for ($index = 0; $index < State::FLIGHTS; $index++) {
            $Flood[] = static function () use ($DNS, $Bridge, $index): void {
               $DNS->resolve("flood{$index}.test", $Bridge);
            };
         }
         $Flood[] = static function () use ($DNS, $Bridge, &$Last, &$late): void {
            $start = microtime(true);
            $Last = $DNS->resolve('over.test', $Bridge);
            $late = microtime(true) - $start;
         };
         // ! The cap is the process's: another configuration (its own state) is refused too
         $Another = new DNS_Client_CLI()->configure($Harness->configure(['hostname' => 'another.example', 'Resolver' => $Ladder]));
         $Elsewhere = null;
         $Flood[] = static function () use ($Another, $Bridge, &$Elsewhere): void {
            $Elsewhere = $Another->resolve('elsewhere.test', $Bridge);
         };
         Harness::run($Flood, 6.0);
         $asked = array_values(array_filter(
            array_column($NS->log(), 'name'), static fn (string $name): bool => $name === 'over.test' || $name === 'elsewhere.test'
         ));
         yield assert(
            assertion: $Last === [] && $late < 0.25 && $Elsewhere === [] && $asked === [],
            description: 'past 64 queries in flight in the process a lookup is refused at once, in any configuration: '
               . json_encode([$late, $Elsewhere, count($asked)])
         );
      }
      finally {
         $Harness->stop();
      }
   }
);
