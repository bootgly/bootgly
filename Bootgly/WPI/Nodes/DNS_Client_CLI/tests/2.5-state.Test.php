<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\State;


use function abs;
use function array_column;
use function array_map;
use function assert;
use function count;
use function hrtime;
use function json_encode;
use function usleep;
use Fiber;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Message\Record;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer\Outcomes;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Query;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;


return new Test(
   description: 'DNS_Client_CLI state: serve-stale, one cache per process, detached records, special names, monotonic deadlines',
   test: function () {
      $Harness = Harness::start('dns-state');
      $NS = $Harness->Nameserver;
      $Bridge = static fn (mixed $value = null): mixed => Fiber::suspend($value);
      $data = static fn (Answer $Answer): array => array_map(static fn (Record $Record): mixed => $Record->data, $Answer->Records);

      try {
         $DNS = new DNS_Client_CLI()->configure($Harness->configure(['timeout' => 0.3]));
         $State = Harness::state($DNS);
         // ! An expired positive answer, as a lookup 10 seconds ago left it
         $plant = static function (string $name, string $IP) use ($State): void {
            $State->Cache->store(
               "{$State->scope}" . DNS::TYPE_A . ":{$name}",
               new Answer(Outcomes::Answered, $name, [new Record($name, DNS::TYPE_A, 1, $IP)], 1),
               Query::clock() - 10.0
            );
         };

         // @ query(): the failed lookup serves the stale answer, and so does the cached failure after it
         $plant('api.test', '192.0.2.40');
         $NS->script('api.test', DNS::TYPE_A, ['drop' => true]);
         $First = $DNS->query('api.test', DNS::TYPE_A);
         $Second = $DNS->query('api.test', DNS::TYPE_A);
         yield assert(
            assertion: $data($First) === ['192.0.2.40'] && $First->TTL === 0
               && $data($Second) === ['192.0.2.40'] && $Second->TTL === 0 && count($NS->log()) === 1,
            description: 'serve-stale outlives the cached failure (query): '
               . json_encode([$First->Outcome->name, $Second->Outcome->name, count($NS->log())])
         );

         // @ resolve(): the same, on a worker loop
         $NS->reset();
         $plant('web.test', '192.0.2.41');
         $NS->script('web.test', DNS::TYPE_A, ['drop' => true]);
         $NS->script('web.test', DNS::TYPE_AAAA, ['drop' => true]);
         $Results = [];
         Harness::run([
            static function () use ($DNS, $Bridge, &$Results): void {
               $Results[] = $DNS->resolve('web.test.', $Bridge);
               $Results[] = $DNS->resolve('web.test.', $Bridge);
            },
         ]);
         yield assert(
            assertion: $Results === [['192.0.2.41'], ['192.0.2.41']] && count($NS->log()) === 2,
            description: 'serve-stale outlives the cached failure (resolve): ' . json_encode([$Results, count($NS->log())])
         );

         // @ One cache per process; each configuration keeps its own answers in it
         $NS->reset();
         $Other = new DNS_Client_CLI()->configure($Harness->configure(['timeout' => 0.3, 'hostname' => 'other.example']));
         $Elsewhere = Harness::state($Other);
         $NS->script('iso.test', DNS::TYPE_A, ['answers' => [['iso.test', DNS::TYPE_A, 60, '192.0.2.42']]]);
         $Mine = $DNS->query('iso.test', DNS::TYPE_A);
         $Theirs = $Other->query('iso.test', DNS::TYPE_A);
         yield assert(
            assertion: $Elsewhere->Cache === $State->Cache && $Elsewhere->scope !== $State->scope
               && $data($Mine) === ['192.0.2.42'] && $data($Theirs) === ['192.0.2.42'] && count($NS->log()) === 2,
            description: 'configurations share the process cache, never each other\'s answers: ' . json_encode(count($NS->log()))
         );

         // @ A caller changing a returned record never changes the cached one
         $Records = $Mine->Records;
         $Records[0]->data = '203.0.113.99';
         $Again = $DNS->query('iso.test', DNS::TYPE_A);
         // ! A cache hit hands out copies too
         $Hit = $Again->Records;
         $Hit[0]->data = '203.0.113.98';
         $Third = $DNS->query('iso.test', DNS::TYPE_A);
         yield assert(
            assertion: $data($Again) === ['203.0.113.98'] && $data($Third) === ['192.0.2.42'] && count($NS->log()) === 2,
            description: 'returned records are copies: ' . json_encode([$data($Again), $data($Third)])
         );

         // @ `*.invalid` never resolves, not even from the hosts file; query() never asks it
         $NS->reset();
         $hosts = $Harness->write('hosts', "192.0.2.80 svc.invalid\n192.0.2.81 svc.example\n");
         $Files = new DNS_Client_CLI()->configure($Harness->configure(['hosts' => $hosts]));
         $Invalid = $Files->query('x.invalid', DNS::TYPE_MX);
         yield assert(
            assertion: $Files->resolve('svc.invalid') === [] && $Files->resolve('svc.example') === ['192.0.2.81']
               && $Invalid->Outcome === Outcomes::Absent && $NS->log() === [],
            description: 'invalid names resolve to nothing, without a query: ' . json_encode([$Invalid->Outcome->name, count($NS->log())])
         );

         // @ query() asks a `.local` name over unicast DNS only when no mDNS source comes first
         $nsswitch = $Harness->write('nsswitch.conf', "hosts: files mdns4_minimal [NOTFOUND=return] dns\n");
         $Multicast = new DNS_Client_CLI()->configure($Harness->configure(['nsswitch' => $nsswitch]));
         $Withheld = $Multicast->query('printer.local', DNS::TYPE_A);
         $withheld = count($NS->log());
         $Asked = $DNS->query('printer.local', DNS::TYPE_A);
         yield assert(
            assertion: $Withheld->Outcome === Outcomes::Failed && $withheld === 0
               && $Asked->Outcome === Outcomes::Absent && array_column($NS->log(), 'name') === ['printer.local'],
            description: 'a .local query stays off unicast DNS with mDNS configured: ' . json_encode([$withheld, count($NS->log())])
         );

         // @ A query whose driver never came back (its loop never ran again) is dropped once past
         //   its deadline: the next lookup of the name starts a fresh one
         $NS->reset();
         Harness::loop();
         $Orphan = new Fiber(static function () use ($DNS, $Bridge): void {
            $DNS->resolve('orphan.test', $Bridge);
         });
         $Orphan->start();
         usleep(700_000);
         $NS->reset();
         $NS->script('orphan.test', DNS::TYPE_A, ['answers' => [['orphan.test', DNS::TYPE_A, 60, '192.0.2.43']]]);
         $NS->script('orphan.test', DNS::TYPE_AAAA, ['answers' => []]);
         $Fresh = null;
         Harness::run([
            static function () use ($DNS, $Bridge, &$Fresh): void {
               $Fresh = $DNS->resolve('orphan.test', $Bridge);
            },
         ]);
         yield assert(
            assertion: $Fresh === ['192.0.2.43'] && $Orphan->isSuspended(),
            description: 'an expired query in flight is replaced by a fresh one: ' . json_encode([$Fresh, $Orphan->isSuspended()])
         );

         // @ Deadlines run on the monotonic clock
         $Query = $State->build('clock.test', [DNS::TYPE_A]);
         $expected = hrtime(true) / 1_000_000_000 + 0.3 * 2;
         yield assert(
            assertion: abs($Query->deadline - $expected) < 0.2 && $Query->expired === false,
            description: 'a query\'s deadline is monotonic: ' . json_encode([$Query->deadline, $expected])
         );
      }
      finally {
         $Harness->stop();
      }
   }
);
