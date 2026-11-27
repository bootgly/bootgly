<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\Search;


use const STREAM_SERVER_BIND;
use function array_column;
use function array_map;
use function array_unique;
use function array_values;
use function assert;
use function fclose;
use function json_encode;
use function microtime;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use Fiber;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Query;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;


return new Test(
   description: 'DNS_Client_CLI resolve() on a worker loop: the search list, the way glibc walks it',
   test: function () {
      $Harness = Harness::start('dns-search');
      $NS = $Harness->Nameserver;
      $Bridge = static fn (mixed $value = null): mixed => Fiber::suspend($value);
      $Silent = new Resolver(
         Lookup: static fn (string $host): false => false,
         List: static fn (string $host): false => false,
         Query: static fn (string $host): false => false
      );
      $DNS = new DNS_Client_CLI()->configure($Harness->configure([
         // ! A second per try: a reply slowed by a busy runner still lands inside it
         'search' => ['a.test', 'b.test'], 'ndots' => 1, 'timeout' => 1, 'Resolver' => $Silent,
      ]));
      $lookup = static function (DNS_Client_CLI $Client, string $name) use ($Bridge): array {
         $Result = null;
         Harness::run([
            static function () use ($Client, $name, $Bridge, &$Result): void {
               $Result = $Client->resolve($name, $Bridge);
            },
         ]);

         return $Result ?? ['(unfinished)'];
      };
      // ! The candidates asked, in order (A and AAAA count once)
      $asked = static fn (): array => array_values(array_unique(array_column($NS->log(), 'name')));
      $A = static fn (string $name, string $IP): array => ['answers' => [[$name, DNS::TYPE_A, 60, $IP]]];

      try {
         // @ Fewer dots than ndots: the search list first; the first answer wins
         $NS->script('host.b.test', DNS::TYPE_A, $A('host.b.test', '192.0.2.21'));
         $Host = $lookup($DNS, 'host');
         yield assert(
            assertion: $Host === ['192.0.2.21'] && $asked() === ['host.a.test', 'host.b.test'],
            description: 'a short name walks the search list until a domain answers: ' . json_encode([$Host, $asked()])
         );

         // @ Enough dots: as-is first
         $NS->reset();
         $NS->script('www.example', DNS::TYPE_A, $A('www.example', '192.0.2.22'));
         $Dotted = $lookup($DNS, 'www.example');
         yield assert(
            assertion: $Dotted === ['192.0.2.22'] && $asked() === ['www.example'],
            description: 'a name with ndots dots is asked as-is first: ' . json_encode($asked())
         );

         // @ As-is first and unresolved: the search list, and never as-is again
         $NS->reset();
         $Deep = $lookup($DNS, 'deep.name');
         yield assert(
            assertion: $Deep === [] && $asked() === ['deep.name', 'deep.name.a.test', 'deep.name.b.test'],
            description: 'an unresolved dotted name tries the search list once, as-is once: ' . json_encode($asked())
         );

         // @ An absolute name: only itself
         $NS->reset();
         $Absolute = $lookup($DNS, 'abs.');
         yield assert(
            assertion: $Absolute === [] && $asked() === ['abs'],
            description: 'a trailing dot skips the search list: ' . json_encode($asked())
         );

         // @ SERVFAIL and NODATA move on to the next domain
         $NS->reset();
         $NS->script('sv.a.test', DNS::TYPE_A, ['rcode' => DNS::RCODE_SERVFAIL]);
         $NS->script('sv.a.test', DNS::TYPE_AAAA, ['rcode' => DNS::RCODE_SERVFAIL]);
         $NS->script('sv.b.test', DNS::TYPE_A, $A('sv.b.test', '192.0.2.23'));
         $NS->script('nd.a.test', DNS::TYPE_A, ['answers' => []]);
         $NS->script('nd.a.test', DNS::TYPE_AAAA, ['answers' => []]);
         $NS->script('nd.b.test', DNS::TYPE_A, $A('nd.b.test', '192.0.2.24'));
         $Failing = $lookup($DNS, 'sv');
         $Empty = $lookup($DNS, 'nd');
         // ! Again: a SERVFAIL is not cached as a failure that would stop the list
         $Again = $lookup($DNS, 'sv');
         yield assert(
            assertion: $Failing === ['192.0.2.23'] && $Empty === ['192.0.2.24'] && $Again === ['192.0.2.23'],
            description: 'SERVFAIL and NODATA at one domain try the next: ' . json_encode([$Failing, $Empty, $Again])
         );

         // @ REFUSED stops the search list too (glibc: only SERVFAIL moves on); then as-is
         $NS->reset();
         $NS->script('rf.a.test', DNS::TYPE_A, ['rcode' => DNS::RCODE_REFUSED]);
         $NS->script('rf.a.test', DNS::TYPE_AAAA, ['rcode' => DNS::RCODE_REFUSED]);
         $NS->script('rf', DNS::TYPE_A, $A('rf', '192.0.2.26'));
         $Refused = $lookup($DNS, 'rf');
         yield assert(
            assertion: $Refused === ['192.0.2.26'] && $asked() === ['rf.a.test', 'rf'],
            description: 'a refusal ends the search list, then the name is asked as-is: ' . json_encode([$Refused, $asked()])
         );

         // @ A timeout stops the search list; the name is still asked as-is
         $NS->reset();
         $NS->script('to.a.test', DNS::TYPE_A, ['drop' => true]);
         $NS->script('to.a.test', DNS::TYPE_AAAA, ['drop' => true]);
         $NS->script('to', DNS::TYPE_A, $A('to', '192.0.2.25'));
         $start = microtime(true);
         $Timeout = $lookup($DNS, 'to');
         $took = microtime(true) - $start;
         yield assert(
            assertion: $Timeout === ['192.0.2.25'] && $asked() === ['to.a.test', 'to'],
            description: 'a timeout ends the search list, then the name is asked as-is: ' . json_encode([
               $Timeout, $took, array_map(static fn (array $Entry): string => "{$Entry['name']}/{$Entry['type']}", $NS->log()),
            ])
         );

         // @ No nameserver listens: give up at once
         $Probe = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
         $local = (string) stream_socket_get_name($Probe, false);
         fclose($Probe);
         $Closed = new DNS_Client_CLI()->configure($Harness->configure([
            'port' => (int) substr($local, (int) strrpos($local, ':') + 1),
            'search' => ['a.test', 'b.test'], 'timeout' => 1, 'Resolver' => $Silent,
         ]));
         $start = microtime(true);
         $Unreachable = $lookup($Closed, 'nobody');
         $took = microtime(true) - $start;
         // ! What was asked shows in the cache: an unreachable outcome is kept 5 seconds
         $State = Harness::state($Closed);
         $kept = [];
         foreach (['nobody.a.test', 'nobody.b.test', 'nobody'] as $candidate) {
            $Kept = $State->Cache->find("{$State->scope}" . DNS::TYPE_A . ":{$candidate}", Query::clock());
            $kept[$candidate] = $Kept?->Outcome->name;
         }
         yield assert(
            assertion: $Unreachable === [] && $took < 0.9
               && $kept === ['nobody.a.test' => 'Failed', 'nobody.b.test' => null, 'nobody' => null],
            description: 'a refused port ends the lookup at the first domain: ' . json_encode([$Unreachable, $took, $kept])
         );
      }
      finally {
         $Harness->stop();
      }
   }
);
