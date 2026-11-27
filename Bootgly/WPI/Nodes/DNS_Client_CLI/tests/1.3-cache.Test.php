<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\Cache;


use function assert;
use function json_encode;
use function str_repeat;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Message\Record;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer\Outcomes;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Cache;


return new Test(
   description: 'DNS_Client_CLI Cache: TTL, serve-stale on request, entry and byte bounds',
   test: function () {
      $A = static fn (string $address, int $TTL = 60): Answer => new Answer(
         Outcomes::Answered, 'a.example', [new Record('a.example', DNS::TYPE_A, $TTL, $address)], $TTL
      );

      // @ Fresh, then expired; the remaining TTL
      $Cache = new Cache();
      $Cache->store('1:a.example', $A('192.0.2.1', 60), 1000.0);
      $fresh = $Cache->find('1:a.example', 1030.2);
      $expired = $Cache->find('1:a.example', 1060.0);
      yield assert(
         assertion: $fresh !== null && $fresh->TTL === 30 && $fresh->Records[0]->data === '192.0.2.1' && $expired === null,
         description: 'a fresh hit carries its remaining TTL; an expired entry is a miss'
      );

      // @ Serve-stale only on request, inside the window
      $stale = $Cache->find('1:a.example', 1200.0, stale: true);
      $late = $Cache->find('1:a.example', 1361.0, stale: true);
      yield assert(
         assertion: $stale !== null && $stale->TTL === 0 && $late === null,
         description: 'an expired positive answer is served stale (TTL 0) for 300 s, only when asked'
      );

      // @ A failure keeps the positive answer it replaces as stale
      $Cache = new Cache();
      $Cache->store('1:a.example', $A('192.0.2.1', 60), 1000.0);
      $Cache->store('1:a.example', new Answer(Outcomes::Failed, 'a.example', [], 5), 1070.0);
      $failed = $Cache->find('1:a.example', 1071.0);
      $kept = $Cache->find('1:a.example', 1071.0, stale: true);
      $Cache->store('1:a.example', new Answer(Outcomes::Absent, 'a.example', [], 30), 1072.0);
      $gone = $Cache->find('1:a.example', 1073.0, stale: true);
      yield assert(
         assertion: $failed?->Outcome === Outcomes::Failed && $kept?->Records[0]->data === '192.0.2.1' && $gone === null,
         description: 'a failure entry keeps the stale payload; a negative answer replaces it'
      );

      // @ Entry bound: LRU, a hit refreshes recency
      $Cache = new Cache(capacity: 3);
      foreach (['a', 'b', 'c'] as $name) {
         $Cache->store("1:{$name}", $A('192.0.2.1'), 1000.0);
      }
      $Cache->find('1:a', 1000.0);
      $Cache->store('1:d', $A('192.0.2.1'), 1000.0);
      yield assert(
         assertion: $Cache->entries === 3 && $Cache->find('1:b', 1000.0) === null
            && $Cache->find('1:a', 1000.0) !== null && $Cache->find('1:d', 1000.0) !== null,
         description: 'past the entry bound the least recently used goes'
      );

      // @ Byte bound: 64 KiB answers never pile past the budget
      $Cache = new Cache(limit: 70_000);
      for ($i = 0; $i < 200; $i++) {
         $TXT = new Answer(Outcomes::Answered, "t{$i}.example", [new Record("t{$i}.example", DNS::TYPE_TXT, 60, [str_repeat('x', 65_000)])], 60);
         $Cache->store("16:t{$i}.example", $TXT, 1000.0);
      }
      yield assert(
         assertion: $Cache->bytes <= 4_194_304 && $Cache->entries < 70 && $Cache->entries > 0,
         description: 'the byte budget bounds the cache: ' . json_encode([$Cache->bytes, $Cache->entries])
      );

      // @ Past 8 KiB an answer is returned, never held
      $Default = new Cache();
      $Big = new Answer(Outcomes::Answered, 'big', [new Record('big', DNS::TYPE_TXT, 60, [str_repeat('z', 9000)])], 60);
      $Fits = new Answer(Outcomes::Answered, 'fits', [new Record('fits', DNS::TYPE_TXT, 60, [str_repeat('z', 7000)])], 60);
      yield assert(
         assertion: $Default->store('16:big', $Big, 1000.0) === false && $Default->store('16:fits', $Fits, 1000.0) === true
            && $Default->entries === 1,
         description: 'an answer over the 8 KiB per-answer limit is not cached; one under it is'
      );

      // @ An answer larger than the budget is not stored
      $Small = new Cache(budget: 1000);
      $stored = $Small->store('16:x', new Answer(Outcomes::Answered, 'x', [new Record('x', DNS::TYPE_TXT, 60, [str_repeat('y', 2000)])], 60), 1000.0);
      yield assert(
         assertion: $stored === false && $Small->entries === 0 && $Small->bytes === 0,
         description: 'an answer over the whole budget is refused'
      );
   }
);
