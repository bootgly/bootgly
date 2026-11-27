<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\DNS\Tests;


use function assert;
use function chr;
use function count;
use function hrtime;
use function intdiv;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function pack;
use function round;
use function str_repeat;
use function strlen;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Faults;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `DNS::decode()` — resource bounds on hostile input: nothing is allocated
 * from a declared count before it is checked, the names of one message add up
 * to `Name::BUDGET` characters at most, and a message made of compression
 * pointers costs its length, not its length per pointer (peak memory measured
 * with `memory_reset_peak_usage()`).
 */

return new Test(
   description: 'DNS decodes hostile messages within bounded memory and time',
   test: function () {
      // # Declared counts: refused before any allocation
      $counts = [
         'AN 65535' => pack('nnnnnn', 1, 0x8000, 0, 0xFFFF, 0, 0),
         'QD 65535 (non-QUERY opcode)' => pack('nnnnnn', 1, 0x1000, 0xFFFF, 0, 0, 0),
         'every count 65535' => pack('nnnnnn', 1, 0x1000, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF),
      ];
      // ! Load the codec's classes first: autoloading is not the decode's memory
      DNS::decode(pack('nnnnnn', 1, 0x8000, 0, 0xFFFF, 0, 0));

      foreach ($counts as $label => $bytes) {
         memory_reset_peak_usage();
         $base = memory_get_usage();
         $Result = DNS::decode($bytes);
         $peak = memory_get_peak_usage() - $base;

         yield assert(
            assertion: $Result === Faults::Count && $peak < 65536,
            description: "{$label} in a 12-byte message: refused as Count, peak +{$peak} bytes"
         );
      }

      // ! Decode once, measuring the decode's own peak memory and time
      $measure = function (string $bytes): array {
         memory_reset_peak_usage();
         $base = memory_get_usage();
         $started = hrtime(true);
         $Result = DNS::decode($bytes);
         $elapsed = (hrtime(true) - $started) / 1e6;

         return [$Result, memory_get_peak_usage() - $base, $elapsed];
      };
      $within = fn (int $peak, float $elapsed): string
         => 'peak +' . round($peak / 1048576, 1) . ' MiB (< 8), ' . round($elapsed, 1) . ' ms (< 1000)';

      // # 64 KiB of pointers into one 255-byte name of escaped labels (634 characters each):
      //   millions of characters of names from 64 KiB, refused once they pass the budget
      $long = str_repeat("\x01\xFF", 127) . "\x00";
      $build = function (bool $questions) use ($long): string {
         $body = "{$long}\x00\x01\x00\x01";
         $count = 1;
         while (true) {
            $pointer = pack('n', 0xC000 | (12 + 2 * ($count % 127)));
            $item = $questions ? "{$pointer}\x00\x01\x00\x01" : "{$pointer}\xFF\x00\x00\x01\x00\x00\x00\x00\x00\x00";
            if (12 + strlen($body) + strlen($item) > 65535) {
               break;
            }
            $body .= $item;
            $count++;
         }

         return $questions
            ? pack('nnnnnn', 1, 0x1000, $count, 0, 0, 0) . $body
            : pack('nnnnnn', 1, 0x8000, 1, $count - 1, 0, 0) . $body;
      };

      foreach (['questions' => true, 'records' => false] as $label => $questions) {
         [$Result, $peak, $elapsed] = $measure($build($questions));

         yield assert(
            assertion: $Result === Faults::Size && $peak < 8 * 1048576 && $elapsed < 1000,
            description: "a 64 KiB message of {$label} pointing into a 255-byte escaped name: refused as Size, "
               . $within($peak, $elapsed)
         );
      }

      // # Owners, each through its own pointer to a pointer into one name that sits inside
      //   raw RDATA: the name is reached only through a second pointer
      $broom = function (string $name, int $count): string {
         $carrier = 12 + 5 + 1 + 10;
         $nodes = $carrier + strlen($name);
         $RDATA = $name . str_repeat(pack('n', 0xC000 | $carrier), $count);
         $owners = '';
         for ($index = 0; $index < $count; $index++) {
            $owners .= pack('n', 0xC000 | ($nodes + 2 * $index)) . pack('nnNn', 0xFF00, 1, 0, 0);
         }

         return pack('nnnnnn', 1, 0x8000, 1, 1 + $count, 0, 0) . "\x00\x00\x01\x00\x01"
            . "\x00" . pack('nnNn', 0xFF00, 1, 0, strlen($RDATA)) . $RDATA . $owners;
      };

      [$Result, $peak, $elapsed] = $measure($broom($long, intdiv(65535 - 28 - strlen($long), 14)));
      yield assert(
         assertion: $Result === Faults::Size && $peak < 8 * 1048576 && $elapsed < 1000,
         description: 'a 64 KiB message of owners reaching an escaped name through a second pointer: refused as Size, '
            . $within($peak, $elapsed)
      );

      // # The budget's edge: 4144 names of 253 characters fit in 1 MiB, 4145 do not
      $plain = str_repeat("\x01a", 127) . "\x00";
      [$Result, $peak, $elapsed] = $measure($broom($plain, 4144));
      $names = $Result instanceof Message ? strlen($Result->Answers[4144]->name) : 0;
      unset($Result);
      yield assert(
         assertion: $names === 253 && $peak < 8 * 1048576 && $elapsed < 1000,
         description: '4144 owners of a 253-character name (1,048,432 characters) are read, '
            . $within($peak, $elapsed)
      );
      yield assert(
         assertion: DNS::decode($broom($plain, 4145)) === Faults::Size,
         description: '4145 owners of a 253-character name (1,048,685 characters) are refused as Size'
      );

      // # 64 KiB of names without a single pointer: the memo keeps only what a pointer could reach
      $body = '';
      $count = 0;
      while (true) {
         $name = '';
         for ($index = 0; $index < 127; $index++) {
            $name .= "\x01" . chr(0x61 + (($count + $index) % 26));
         }
         $item = "{$name}\x00\x00\x01\x00\x01";
         if (12 + strlen($body) + strlen($item) > 65535) {
            break;
         }
         $body .= $item;
         $count++;
      }
      [$Result, $peak, $elapsed] = $measure(pack('nnnnnn', 1, 0x1000, $count, 0, 0, 0) . $body);
      $read = $Result instanceof Message ? count($Result->Questions) : 0;
      unset($Result);
      yield assert(
         assertion: $read === $count && $peak < 2 * 1048576 && $elapsed < 1000,
         description: "{$count} questions of 127 one-byte labels and no pointer: peak +"
            . round($peak / 1048576, 1) . ' MiB (< 2), ' . round($elapsed, 1) . ' ms (< 1000)'
      );
   }
);
