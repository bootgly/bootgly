<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\CNAME;


use function array_column;
use function array_map;
use function assert;
use function json_encode;

use Bootgly\ABI\Data\DNS;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer\Outcomes;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;


return new Test(
   description: 'DNS_Client_CLI query(): the CNAME chain is followed, bounded, and never leaves DNS',
   test: function () {
      $Harness = Harness::start('dns-cname');
      $NS = $Harness->Nameserver;
      $DNS = new DNS_Client_CLI()->configure($Harness->configure());
      $names = static fn (): array => array_column($NS->log(), 'name');
      $chain = static function (string $prefix, int $hops, bool $final = true): array {
         $rows = [];
         for ($i = 0; $i < $hops; $i++) {
            $rows[] = ["{$prefix}{$i}.test", DNS::TYPE_CNAME, 300 - $i, "{$prefix}" . ($i + 1) . '.test'];
         }
         if ($final) {
            $rows[] = ["{$prefix}{$hops}.test", DNS::TYPE_A, 600, '192.0.2.20'];
         }

         return $rows;
      };

      try {
         // @ A chain: the canonical name, the smallest TTL on it
         $NS->script('c0.test', DNS::TYPE_A, ['answers' => $chain('c', 3)]);
         $Chain = $DNS->query('c0.test', DNS::TYPE_A);
         yield assert(
            assertion: $Chain->Outcome === Outcomes::Answered && $Chain->name === 'c3.test' && $Chain->TTL === 298
               && array_map(static fn ($R) => $R->data, $Chain->Records) === ['192.0.2.20'],
            description: 'a 3-hop chain answers at its end with the chain\'s smallest TTL: ' . json_encode([$Chain->name, $Chain->TTL])
         );

         // @ 8 hops are followed, 9 are not
         $NS->script('e0.test', DNS::TYPE_A, ['answers' => $chain('e', 8)]);
         $NS->script('n0.test', DNS::TYPE_A, ['answers' => $chain('n', 9)]);
         $Eight = $DNS->query('e0.test', DNS::TYPE_A);
         $Nine = $DNS->query('n0.test', DNS::TYPE_A);
         yield assert(
            assertion: $Eight->Outcome === Outcomes::Answered && $Nine->Outcome === Outcomes::Failed,
            description: 'the chase stops past 8 hops: ' . json_encode([$Eight->Outcome->name, $Nine->Outcome->name])
         );

         // @ A loop, and two CNAMEs at one owner, are broken chains
         $NS->script('loop.test', DNS::TYPE_A, ['answers' => [
            ['loop.test', DNS::TYPE_CNAME, 60, 'back.test'], ['back.test', DNS::TYPE_CNAME, 60, 'loop.test'],
         ]]);
         $NS->script('fork.test', DNS::TYPE_A, ['answers' => [
            ['fork.test', DNS::TYPE_CNAME, 60, 'x.test'], ['fork.test', DNS::TYPE_CNAME, 60, 'y.test'],
            ['x.test', DNS::TYPE_A, 60, '192.0.2.1'], ['y.test', DNS::TYPE_A, 60, '192.0.2.2'],
         ]]);
         $Loop = $DNS->query('loop.test', DNS::TYPE_A);
         $Fork = $DNS->query('fork.test', DNS::TYPE_A);
         yield assert(
            assertion: $Loop->Outcome === Outcomes::Failed && $Fork->Outcome === Outcomes::Failed,
            description: 'a CNAME loop and two CNAMEs at one owner fail'
         );

         // @ A chain that ends without data and without proof: its end is asked, over DNS only
         $NS->reset();
         $NS->script('half.test', DNS::TYPE_A, ['answers' => [['half.test', DNS::TYPE_CNAME, 60, 'end.test']]]);
         $NS->script('end.test', DNS::TYPE_A, ['answers' => [['end.test', DNS::TYPE_A, 60, '192.0.2.30']]]);
         $Half = $DNS->query('half.test', DNS::TYPE_A);
         yield assert(
            assertion: $Half->Outcome === Outcomes::Answered && $Half->name === 'end.test' && $names() === ['half.test', 'end.test'],
            description: 'the end of an unproven chain is asked once: ' . json_encode($names())
         );

         // @ A chain whose end is proven empty (SOA): no re-query
         $NS->reset();
         $NS->script('proven.test', DNS::TYPE_A, [
            'answers' => [['proven.test', DNS::TYPE_CNAME, 60, 'void.test']],
            'authorities' => [['test', DNS::TYPE_SOA, 60, ['mname' => 'ns.test', 'rname' => 'a.test', 'serial' => 1, 'refresh' => 1, 'retry' => 1, 'expire' => 1, 'minimum' => 20]]],
         ]);
         $Proven = $DNS->query('proven.test', DNS::TYPE_A);
         yield assert(
            assertion: $Proven->Outcome === Outcomes::Empty && $Proven->name === 'void.test' && $Proven->TTL === 20 && $names() === ['proven.test'],
            description: 'a chain ending in NODATA with its SOA is Empty, asked once: ' . json_encode([$Proven->Outcome->name, $names()])
         );

         // @ A CNAME to an address-looking name stays a DNS name
         $NS->reset();
         $NS->script('trick.test', DNS::TYPE_A, ['answers' => [['trick.test', DNS::TYPE_CNAME, 60, '127.0.0.1.']]]);
         $Trick = $DNS->query('trick.test', DNS::TYPE_A);
         yield assert(
            assertion: $Trick->Outcome === Outcomes::Absent && $Trick->Records === [] && $names() === ['trick.test', '127.0.0.1'],
            description: 'a CNAME target that looks like a literal is asked over DNS, never returned as an address: ' . json_encode([$Trick->Outcome->name, $names()])
         );
      }
      finally {
         $Harness->stop();
      }
   }
);
