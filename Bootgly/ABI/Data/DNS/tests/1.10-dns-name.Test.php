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


use function array_fill;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function assert;
use function count;
use function hex2bin;
use function implode;
use function pack;
use function sort;
use function str_pad;
use function str_repeat;
use function str_replace;
use function strlen;

use Bootgly\ABI\Data\DNS\Faults;
use Bootgly\ABI\Data\DNS\Name;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `Name::encode()` / `Name::decode()` called directly: the flat wire form,
 * where `$offset` lands (after the root, after the first pointer, unchanged on
 * a fault), the per-message suffix memo, and the `Faults` cases.
 */

return new Test(
   description: 'Name reads and writes one name and moves the offset exactly',
   test: function () {
      $hex = fn (string $hex): string => (string) hex2bin(str_replace(' ', '', $hex));

      // # Flat encode: never compressed
      yield assert(
         assertion: Name::encode('mail.example.com') === $hex('04 6d61696c 07 6578616d706c65 03 636f6d 00')
            && Name::encode('mail.example.com.') === Name::encode('mail.example.com'),
         description: 'encode() writes the flat wire form; a trailing dot changes nothing'
      );
      yield assert(
         assertion: Name::LABEL === 63 && Name::LIMIT === 255 && Name::JUMPS === 128,
         description: 'the bounds are 63-byte labels, 255-byte names and 128 pointer jumps'
      );

      // # A message body: header (12 bytes), `a.example` at 12, `b` + pointer to 14 at 23
      $bytes = str_repeat("\x00", 12) . $hex('01 61 07 6578616d706c65 00 01 62 c00e c00c');

      $offset = 12;
      yield assert(
         assertion: Name::decode($bytes, $offset) === 'a.example' && $offset === 23,
         description: 'a name in place: the offset lands after its root byte'
      );

      $offset = 23;
      yield assert(
         assertion: Name::decode($bytes, $offset) === 'b.example' && $offset === 27,
         description: 'a label and a pointer: the offset lands after the pointer, not where it led'
      );

      $offset = 27;
      yield assert(
         assertion: Name::decode($bytes, $offset) === 'a.example' && $offset === 29,
         description: 'a bare pointer: the offset moves by 2'
      );

      // # A fault leaves the offset where it was
      $faults = [
         'a cut label' => [str_repeat("\x00", 12) . $hex('05 6162'), Faults::Overrun],
         'a reserved label type' => [str_repeat("\x00", 12) . $hex('41'), Faults::Label],
         'a loop' => [str_repeat("\x00", 12) . $hex('01 61 c00c'), Faults::Loop],
         'a forward pointer' => [str_repeat("\x00", 12) . $hex('c00e 00'), Faults::Forward],
         'a pointer into the header' => [str_repeat("\x00", 12) . $hex('c000'), Faults::Pointer],
      ];
      foreach ($faults as $label => [$message, $Fault]) {
         $offset = 12;
         $Result = Name::decode($message, $offset);

         yield assert(
            assertion: $Result === $Fault && $offset === 12,
            description: "{$label}: {$Fault->name}, offset unchanged"
         );
      }

      // # A negative offset is outside the message
      $offset = -1;
      yield assert(
         assertion: Name::decode($bytes, $offset) === Faults::Overrun && $offset === -1,
         description: 'a negative offset: Overrun, offset unchanged'
      );

      // # The memo: the same names with and without it, and what it keeps
      $suffixes = [];
      $read = [];
      foreach ([12, 23, 27] as $at) {
         $offset = $at;
         $read[] = Name::decode($bytes, $offset, $suffixes);
      }
      $kept = array_values(array_filter(array_keys($suffixes), fn (int $key): bool => $key >= 12));
      sort($kept);
      yield assert(
         assertion: $read === ['a.example', 'b.example', 'a.example']
            && $kept === [12, 14, 22, 23, 25, 27],
         description: 'a shared memo reads the same names and keeps every position read: labels, pointers, roots'
      );

      // # A name read below the header is never kept: a pointer can only reach offset 12 on
      $low = str_pad("\x01a\x00", 12, "\x00") . $hex('01 63 00 c00c');   // `a` at 0, `c` at 12, ptr at 15
      $suffixes = [];
      $names = array_map(function (int $at) use ($low, &$suffixes): string|Faults {
         $offset = $at;

         return Name::decode($low, $offset, $suffixes);
      }, [0, 12, 15]);
      yield assert(
         assertion: $names === ['a', 'c', 'c'],
         description: 'a name at offset 0 leaves the memo alone: the pointer to 12 reads `c`'
      );

      // # Past the farthest byte a pointer can reach (0x3FFF + 254), nothing is kept
      $far = str_repeat("\x00", 0x3FFF + 252) . $hex('03 616263 00');      // `abc` at 0x3FFF+252, root at +256
      $suffixes = [];
      $offset = 0x3FFF + 252;
      $abc = Name::decode($far, $offset, $suffixes);
      $kept = array_values(array_filter(array_keys($suffixes), fn (int $key): bool => $key >= 12));
      yield assert(
         assertion: $abc === 'abc' && $kept === [0x3FFF + 252],
         description: 'a name across the reach keeps its label (0x3FFF + 252), not its root (0x3FFF + 256)'
      );

      // # A name reached through its second pointer is kept too: a later pointer to it reads nothing
      $twice = str_repeat("\x00", 12) . $hex('01 61 00 01 62 c00c c00f');   // `a` at 12, `b`+ptr at 15, ptr at 19
      $suffixes = [];
      $offset = 19;
      $first = Name::decode($twice, $offset, $suffixes);
      yield assert(
         assertion: $first === 'b.a' && isset($suffixes[19], $suffixes[15], $suffixes[17], $suffixes[12], $suffixes[14]),
         description: 'every run a name reads through is kept, not only its first pointer target'
      );

      // # A memo entry still counts toward 255 and 128
      $long = str_repeat("\x00", 12) . str_repeat("\x3F" . str_repeat('a', 63), 3) . "\x00";   // 193 bytes at 12
      $suffixes = [];
      $offset = 12;
      Name::decode($long, $offset, $suffixes);
      $over = $long . "\x3E" . str_repeat('b', 62) . "\xC0\x0C";                            // 63 + 193 = 256
      $offset = 205;
      yield assert(
         assertion: Name::decode($over, $offset, $suffixes) === Faults::Name && $offset === 205,
         description: 'a name finished from the memo is still refused past 255 wire bytes'
      );

      // # A position read before, reached by walking labels from an earlier start: its run's
      //   pointer (to 20) lands inside the run being read (from 19), so it is a loop
      $overlap = str_repeat("\x00", 19) . $hex('01 00 01 61 c014 c013');   // 19: label, 21: `a`, 25: ptr
      $suffixes = [];
      $offset = 21;
      $A = Name::decode($overlap, $offset, $suffixes);
      $offset = 25;
      $Memo = Name::decode($overlap, $offset, $suffixes);
      $offset = 25;
      yield assert(
         assertion: $A === 'a' && $Memo === Faults::Loop && Name::decode($overlap, $offset) === Faults::Loop,
         description: 'a position from the memo still refuses a pointer back into the run being read'
      );

      // # What the memo keeps for each position — the pointer that ends its run, where its
      //   suffix starts in the text, its wire size, its jumps — read back as a fresh read would
      $header = str_repeat("\x00", 12);
      $chain = $header . "\x00";
      $nodes = [12];
      for ($index = 0; $index < 120; $index++) {
         $nodes[] = strlen($chain);
         $chain .= pack('n', 0xC000 | $nodes[$index]);
      }
      $top = $nodes[120];
      $previous = $nodes[10];
      for ($index = 0; $index < 30; $index++) {
         $at = strlen($chain);
         $chain .= pack('n', 0xC000 | $previous);
         $previous = $at;
      }
      $wide = $header . str_repeat("\x3F" . str_repeat('a', 63), 3) . "\x00"      // 193 wire bytes at 12
         . "\x01x\xC0\x0C" . "\x3C" . str_repeat('y', 60) . "\xC0\x0C";           // 205: `x`; 209: 60 bytes
      $cases = [
         // 12: a 5-byte label over 13..17, `Q` + root at 13; `b` at 18, `c` at 20, pointers at 22, 24, 26
         'the pointer ending the run of a position read through a memo hit' => [
            $header . "\x05\x01Q\x00xx\x01b\x01c\xC0\x0D\xC0\x12\xC0\x0C", [20, 24, 26], Faults::Loop
         ],
         // 12: `a` + root at 14; 15: `b` + pointer to 12; 19: a pointer to the root at 14
         'the suffix of a root position (empty)' => [$header . "\x01a\x00\x01b\xC0\x0C\xC0\x0E", [15, 19], ''],
         // 120 pointers each to the one before, then 30 from the 10th: 40 jumps, not 150
         'the jumps from a position, not of the whole name' => [$chain, [$top, $previous], ''],
         // 209: 60 bytes + a pointer to 193 wire bytes: 254, not the 196 + 193 of the name at 205
         'the wire size from a position, not of the whole name' => [
            $wide, [205, 209], str_repeat('y', 60) . '.' . implode('.', array_fill(0, 3, str_repeat('a', 63)))
         ],
      ];
      foreach ($cases as $label => [$message, $offsets, $expected]) {
         $suffixes = [];
         $Result = null;
         foreach ($offsets as $at) {
            $offset = $at;
            $Result = Name::decode($message, $offset, $suffixes);
         }
         $offset = $offsets[count($offsets) - 1];
         yield assert(
            assertion: $Result === $expected && Name::decode($message, $offset) === $expected,
            description: "the memo keeps {$label}"
         );
      }

      // # A position a pointer reaches is taken from the memo, never read again: a memo kept
      //   from one message, laid over other bytes, shows which positions were not re-read
      //   (only to observe it: a memo is never shared across messages)
      $place = function (int $at, string $labels) use ($header): string {
         return str_pad($header, $at, "\x00") . $labels . "\x01c" . pack('n', 0xC000 | $at);
      };
      foreach (['a target and a position walked after it' => 12, 'positions past 0x3FFF' => 0x3FFE] as $label => $at) {
         $original = $place($at, "\x01a\x01b\x00");
         $other = $place($at, "\x01x\x01y\x00");
         $suffixes = [];
         $offset = $at + 2;
         Name::decode($original, $offset, $suffixes);       // keeps `b` at $at + 2
         $offset = $at + 5;
         $Over = Name::decode($other, $offset, $suffixes);   // `c`, then $at read fresh, then $at + 2 from the memo
         $offset = $at + 5;
         yield assert(
            assertion: $Over === 'c.x.b' && Name::decode($other, $offset) === 'c.x.y',
            description: "{$label}: a position read before comes from the memo"
         );
      }

      // # The names of one memo add up to BUDGET characters at most
      $name = $header . str_repeat("\x3F" . str_repeat('a', 63), 3) . "\x00";   // 191 characters at 12
      $suffixes = [1 => Name::BUDGET - 191];
      $offset = 12;
      $Fits = Name::decode($name, $offset, $suffixes);
      $offset = 12;
      yield assert(
         assertion: $Fits !== Faults::Size && Name::decode($name, $offset, $suffixes) === Faults::Size
            && $offset === 12 && Name::BUDGET === 1048576,
         description: 'the name that reaches BUDGET characters is read; the next one is refused as Size'
      );

      // # A chain of 100 pointers, then 40 more that end on its start: 140 jumps
      $chain = str_repeat("\x00", 12) . "\x00";
      $previous = 12;
      for ($index = 0; $index < 140; $index++) {
         $at = strlen($chain);
         $chain .= pack('n', 0xC000 | $previous);
         $previous = $at;
         if ($index === 99) {
            $hundred = $at;
         }
      }
      $suffixes = [];
      $offset = $hundred;
      $Hundred = Name::decode($chain, $offset, $suffixes);
      $offset = $previous;
      $Memo = Name::decode($chain, $offset, $suffixes);
      $offset = $previous;
      yield assert(
         assertion: $Hundred === '' && $Memo === Faults::Loop && Name::decode($chain, $offset) === Faults::Loop,
         description: 'a name finished from the memo still counts its jumps: 40 + 100 > 128 is a loop'
      );

      // # Every refusal has a name
      yield assert(
         assertion: array_map(fn (Faults $Fault): string => $Fault->name, Faults::cases()) === [
            'Short', 'Overrun', 'Count', 'Label', 'Name', 'Loop', 'Forward', 'Pointer', 'Data', 'EDNS',
            'Trailing', 'Field', 'Size'
         ],
         description: 'Faults lists its 13 cases'
      );
   }
);
