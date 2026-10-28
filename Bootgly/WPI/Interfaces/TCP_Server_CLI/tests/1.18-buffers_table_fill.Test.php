<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers\Table;
use Random\Engine\Mt19937;
use Random\Randomizer;


/**
 * H-HSC-5 — the table PHP builds for an array of parsed values.
 *
 * An array whose first key is `0` to `7` begins as a list: sparse indexes
 * grow it while it is over half full, and any other key turns it into a map
 * of its slots — twice them when the list is full. Nineteen values keyed
 * `0`..`16`, `63`, `64` so keep a map of 128 slots, where their count
 * suggests 32: a deferred snapshot holding such arrays was charged about
 * five times less than it kept. An index the list already holds only
 * replaces its value: `{"0":0,"0":0,"1":0,…,"16":0,"63":0,"127":0}` keeps
 * the same 128-slot map — read as a hole, that repeat made a map of 8 that
 * a body of 2.9 MB decoded to 183 MB through. `Buffers\Table` follows PHP
 * key by key and `Buffers::tally()` prices such an array by it. Pinned:
 *
 * - (a) 38 hand-traced key sequences — every rule and both sides of each
 *   boundary (growth over half full, conversion doubling when full, a hole
 *   converting without resize, a name, a negative, other first keys); 12
 *   of them repeat a key (a repeat in a list is no value and no hole — past
 *   the first byte of filled slots too; the R6 objects; a repeat in a map
 *   counted as one more key) and 2 more fill a hole past that first byte:
 *   `fill()`, `$list`, `$slots` and `$bytes`;
 * - (b) those sequences plus 3,500 seeded ones — sparse from `0`..`7`,
 *   shuffled, with holes, negatives, names mixed in, long ones (100 to 1,500
 *   keys, up to 4,096 slots), other first keys — and 1,600 seeded with
 *   repeats (earlier indexes repeated in sparse lists closed by a name, a
 *   negative, a far index or a hole; the R6 shape; indexes drawn with
 *   replacement; maps of names and indexes), built as PHP arrays in their
 *   order: each one's real table (its packed flag and the allocator
 *   footprint of its slots: memory held minus the array and its names) is
 *   the one `Table` predicts when fed the same keys, repeats included —
 *   exactly while every repeat lands in a list, never below it once one
 *   lands in a map; `tally()` prices an array begun as a list by the table
 *   of its keys, and covers the real memory of every array within 1.25
 *   times it;
 * - (c) exact `tally()` of the empty array, of lists, of maps begun by a
 *   name and by an index, and of nested arrays (string keys and values at
 *   `weigh()`), nested singletons included;
 * - (d) the running PHP's layout: `weigh()` covers a string cut from a
 *   larger one (PHP 8.4.27 / 8.5.12 give it a 32-byte header), and `tally()`
 *   with `Buffers::NESTED` covers what `parse_str()` builds, within 1.5
 *   times it (its nested levels start as singletons since those releases).
 */
return new Test(
   description: 'H-HSC-5: Buffers\Table follows the table PHP builds key by key, and tally() prices by it',
   test: new Assertions(Case: function (): Generator {
      // ! The table a sequence of keys builds from `$slots`: [list, slots, bytes]
      $Trace = static function (array $keys, int $slots = 8): array {
         $Table = new Table($slots);
         foreach ($keys as $key) {
            $Table->fill($key);
         }

         // :
         return [$Table->list, $Table->slots, $Table->bytes];
      };
      $names = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'];

      // # (a) Hand-traced sequences: expected [list, slots, bytes] — a list
      //   spends 16 bytes a slot plus 8, a map 40
      $Traces = [
         // ? No key yet: no list, an 8-slot map's bytes
         'no key' => [[], [null, 8, 320]],
         'list 0..7' => [range(0, 7), [true, 8, 136]],
         // ? An index past the slots grows a list over half full …
         'list 0..8' => [range(0, 8), [true, 16, 264]],
         'list 0..4, 8 (5 values)' => [[...range(0, 4), 8], [true, 16, 264]],
         // ? … and converts one at half or less, not full: no doubling
         'list 0..3, 8 (4 values)' => [[...range(0, 3), 8], [false, 8, 320]],
         'list 0, 15' => [[0, 15], [false, 8, 320]],
         // ? An index past twice the slots converts even over half full
         'list 0..4, 16' => [[...range(0, 4), 16], [false, 8, 320]],
         // ? A full list (last index 7) of 3 values converts and doubles
         'list 5..7, 8' => [[5, 6, 7, 8], [false, 16, 640]],
         'list 0..7, 100' => [[...range(0, 7), 100], [false, 16, 640]],
         'list 0..7, -1' => [[...range(0, 7), -1], [false, 16, 640]],
         // ? The R5 object: the list grows to 64 slots, then 64 converts
         //   the full list and doubles it — 19 values in 128 slots
         'list 0..16, 63' => [[...range(0, 16), 63], [true, 64, 1_032]],
         'list 0..16, 63, 64' => [[...range(0, 16), 63, 64], [false, 128, 5_120]],
         // ? A hole converts without resize — even a full list
         'list 0, 2, hole 1' => [[0, 2, 1], [false, 8, 320]],
         'list 0..5, 7, hole 6' => [[...range(0, 5), 7, 6], [false, 8, 320]],
         // ? A name or a negative index converts a list that is not full
         'list 0, name' => [[0, 'a'], [false, 8, 320]],
         'list 0, -1' => [[0, -1], [false, 8, 320]],
         'list 7, name' => [[7, 'a'], [false, 8, 320]],
         // ? A name converts a full list: the map doubles when full
         'list 0..7, name' => [[...range(0, 7), 'a'], [false, 16, 640]],
         // ? Any other first key starts a map: 8 slots, doubling when full
         'map name' => [['a'], [false, 8, 320]],
         'map 8' => [[8], [false, 8, 320]],
         'map -1' => [[-1], [false, 8, 320]],
         'map 1700000000' => [[1_700_000_000], [false, 8, 320]],
         'map 8..16' => [range(8, 16), [false, 16, 640]],
         'map 9 names' => [$names, [false, 16, 640]],
         // ? A repeat in a list replaces its value: no value, no hole —
         //   first slot, last slot, a full list
         'list 0, repeat 0' => [[0, 0], [true, 8, 136]],
         'list 7, repeat 7' => [[7, 7], [true, 8, 136]],
         'list 0..7, repeat 3' => [[...range(0, 7), 3], [true, 8, 136]],
         // ? A repeat is no value: a list of 4 or 1 still converts at 8
         //   without growing …
         'list 0..3, repeat 3, 8' => [[...range(0, 3), 3, 8], [false, 8, 320]],
         'list 0, repeat 0 x4, 8' => [[0, 0, 0, 0, 0, 8], [false, 8, 320]],
         // ? … and past the first byte of filled slots a repeat stays one,
         //   a hole stays one
         'list 0..8, repeat 8' => [[...range(0, 8), 8], [true, 16, 264]],
         'list 0..8, 10, hole 9' => [[...range(0, 8), 10, 9], [false, 16, 640]],
         'list 0..16, 63, repeat 63' => [[...range(0, 16), 63, 63], [true, 64, 1_032]],
         'list 0..16, 63, hole 62' => [[...range(0, 16), 63, 62], [false, 64, 2_560]],
         // ? A repeat, then a hole: the hole converts
         'list 0, 2, repeat 2, hole 1' => [[0, 2, 2, 1], [false, 8, 320]],
         // ? The R6 objects: a repeated `0` leaves the list growing to 64
         //   slots — then 127 or 64 converts the full list, doubling it
         'list 0, repeat 0, 1..16, 63, 127' => [[0, 0, ...range(1, 16), 63, 127], [false, 128, 5_120]],
         'list 0..16, repeat 0, 63, 64' => [[...range(0, 16), 0, 63, 64], [false, 128, 5_120]],
         // ? A repeat in a map counts as one more key: it can only overstate
         //   (PHP keeps 7 values in 8 slots, and 8 names in 8)
         'list 0..3, 8 converts, 9, 10, repeat 9, 10' => [[...range(0, 3), 8, 9, 10, 9, 10], [false, 16, 640]],
         'map 8 names, repeat a' => [[...array_slice($names, 0, 8), 'a'], [false, 16, 640]],
      ];
      // ! Singletons (a nested level `parse_str()` builds since PHP 8.4.27
      //   and 8.5.12), traced against its real tables: a list of 1 slot
      //   doubling, a map of its slots — twice them when the list is full
      $Singletons = [
         'singleton 0' => [[0], [true, 1, 24]],
         'singleton 0, 1' => [[0, 1], [true, 2, 40]],
         'singleton 0, hole 2' => [[0, 2], [false, 2, 80]],
         'singleton 0, 1, 3' => [[0, 1, 3], [true, 4, 72]],
         'singleton 0..4' => [range(0, 4), [true, 8, 136]],
         'singleton 0, a name' => [[0, 'a'], [false, 2, 80]],
         'singleton 0, a negative' => [[0, -1], [false, 2, 80]],
         'singleton 0, 2, hole 1' => [[0, 2, 1], [false, 4, 160]],
         'singleton 0, repeat 0' => [[0, 0], [true, 1, 24]],
      ];
      $traced = [];
      $expected = [];
      foreach ($Traces as $shape => [$keys, $table]) {
         $traced[$shape] = $Trace($keys);
         $expected[$shape] = $table;
      }
      foreach ($Singletons as $shape => [$keys, $table]) {
         $traced[$shape] = $Trace($keys, 1);
         $expected[$shape] = $table;
      }
      $hand = count($traced);

      yield new Assertion(
         description: "(a) fill() builds the list, slots and bytes PHP builds for each of {$hand} hand-traced sequences"
      )
         ->expect($traced, Op::Identical, $expected)
         ->assert();

      // # (b) Seeded sequences, built as PHP arrays in their order
      // ! The allocator footprint of `$bytes`: a small bin, or whole pages
      $Footprint = static fn (int $bytes): int => $bytes <= 3_072
         ? Buffers::weigh(max(1, $bytes - 32))
         : intdiv($bytes + 4_095, 4_096) * 4_096;
      // ! What a name of `$bytes` built by interpolation spends: its 24-byte
      //   header and the NUL, 8-aligned, in the bin that fits — `weigh()`
      //   prices 7 bytes more (a 32-byte header), and under 8 bytes the
      //   name takes the 32-byte bin
      $Name = static fn (int $bytes): int => $bytes < 8 ? 32 : Buffers::weigh($bytes - 7);
      // ! An array of `$keys` built in their order: an int is an index, a
      //   string is a name built fresh — with its own allocation
      $Build = static function (array $keys): array {
         $built = [];
         foreach ($keys as $key) {
            if (is_string($key)) {
               $built["k{$key}"] = null;
            }
            else {
               $built[$key] = null;
            }
         }

         // :
         return $built;
      };
      // ! Whether PHP keeps `$built` packed — from its dump's first line,
      //   `array(N) [packed ]refcount(R){` or `array(0) interned {`; `null`
      //   when that line is not understood (never guessed)
      $Packed = static function (array $built): null|bool {
         ob_start();
         debug_zval_dump($built);
         $dump = (string) ob_get_clean();
         $head = [];
         if (
            preg_match('/\Aarray\((\d++)\) (packed )?(?:interned |refcount\(\d++\))\{\n/', $dump, $head) !== 1
            || (int) $head[1] !== count($built)
         ) {
            return null;
         }

         // :
         return ($head[2] ?? '') !== '';
      };
      $Randomizer = new Randomizer(new Mt19937(1_818));
      $sequences = array_column($Traces, 0);
      $kinds = ['sparse', 'shuffled', 'holes', 'negatives', 'names', 'long', 'starts'];
      for ($index = 0; $index < 3_500; $index++) {
         $keys = [];
         $key = $Randomizer->getInt(0, 7);
         switch ($kinds[$index % 7]) {
            // @ Ascending from `0`..`7`, by gaps up to 1, 2, 4, 16 or 64
            case 'sparse':
               $gap = [1, 2, 4, 16, 64][$Randomizer->getInt(0, 4)];
               $length = $Randomizer->getInt(1, 70);
               for ($at = 0; $at < $length; $at++) {
                  $keys[] = $key;
                  $key += $Randomizer->getInt(1, $gap);
               }
               break;
            // @ A shuffled subset of `0`..`N` — half of them first keyed `0`..`7`
            case 'shuffled':
               $keys = $Randomizer->shuffleArray(range(0, $Randomizer->getInt(1, 40)));
               $keys = array_slice($keys, 0, $Randomizer->getInt(1, count($keys)));
               if (intdiv($index, 7) % 2 === 0) {
                  $keys = array_values(array_unique([$key, ...$keys]));
               }
               break;
            // @ Ascending, with indexes skipped and filled later
            case 'holes':
               $length = $Randomizer->getInt(2, 50);
               $ascending = [];
               for ($at = 0; $at < $length; $at++) {
                  $ascending[] = $key;
                  $key += $Randomizer->getInt(1, 6);
               }
               $missing = array_values(array_diff(range(0, $key), $ascending));
               foreach ($ascending as $next) {
                  $keys[] = $next;
                  if ($missing !== [] && $missing[0] < $next && $Randomizer->getInt(0, 4) === 0) {
                     $keys[] = array_shift($missing);
                  }
               }
               break;
            // @ Ascending, with negative indexes mixed in
            case 'negatives':
               $length = $Randomizer->getInt(1, 50);
               $negative = -1;
               for ($at = 0; $at < $length; $at++) {
                  $keys[] = $key;
                  $key += $Randomizer->getInt(1, 8);
                  if ($Randomizer->getInt(0, 5) === 0) {
                     $keys[] = $negative;
                     $negative -= $Randomizer->getInt(1, 1_000);
                  }
               }
               break;
            // @ Ascending, with names mixed in — sometimes first
            case 'names':
               $length = $Randomizer->getInt(1, 60);
               $name = 0;
               if ($Randomizer->getInt(0, 4) === 0) {
                  $keys[] = "s{$name}";
                  $name++;
               }
               for ($at = 0; $at < $length; $at++) {
                  $keys[] = $key;
                  $key += $Randomizer->getInt(1, 20);
                  if ($Randomizer->getInt(0, 6) === 0) {
                     $keys[] = "s{$name}";
                     $name++;
                  }
               }
               break;
            // @ Long, from `0`..`7` by gaps up to 1, 2 or 3 — closed by a name,
            //   a negative index, a hole or nothing
            case 'long':
               $gap = $Randomizer->getInt(1, 3);
               $length = $Randomizer->getInt(100, 1_500);
               for ($at = 0; $at < $length; $at++) {
                  $keys[] = $key;
                  $key += $Randomizer->getInt(1, $gap);
               }
               $missing = array_values(array_diff(range(0, end($keys)), $keys));
               $tail = [['tail'], [-1], array_slice($missing, 0, 1), []][intdiv($index, 7) % 4];
               $keys = [...$keys, ...$tail];
               break;
            // @ Ascending from another first key: `8`..`100`, a timestamp,
            //   or `0`..`7` by one (a list)
            default:
               $key = [$Randomizer->getInt(8, 100), $key, 1_700_000_000 + $key][$Randomizer->getInt(0, 2)];
               $length = $Randomizer->getInt(1, 40);
               for ($at = 0; $at < $length; $at++) {
                  $keys[] = $key;
                  $key += $Randomizer->getInt(1, intdiv($index, 7) % 2 === 0 ? 1 : 3);
               }
         }
         $sequences[] = $keys;
      }

      // ! Seeded with repeats — a key the array already holds, again
      $Randomizer = new Randomizer(new Mt19937(1_819));
      $kinds = ['repeated', 'reprised', 'drawn', 'mapped'];
      for ($index = 0; $index < 1_600; $index++) {
         $keys = [];
         $key = $Randomizer->getInt(0, 7);
         switch ($kinds[$index % 4]) {
            // @ Ascending from `0`..`7` by gaps up to 1, 2, 4 or 16, an
            //   earlier index repeated now and then — closed by nothing, a
            //   name, a negative index, an index far past the last or a hole
            case 'repeated':
               $gap = [1, 2, 4, 16][$Randomizer->getInt(0, 3)];
               $length = $Randomizer->getInt(1, 70);
               for ($at = 0; $at < $length; $at++) {
                  $keys[] = $key;
                  if ($Randomizer->getInt(0, 3) === 0) {
                     $keys[] = $keys[$Randomizer->getInt(0, count($keys) - 1)];
                  }
                  $key += $Randomizer->getInt(1, $gap);
               }
               $missing = array_values(array_diff(range(0, max($keys)), $keys));
               $tail = [[], ['tail'], [-1], [4 * $key + 64], array_slice($missing, 0, 1)][intdiv($index, 4) % 5];
               $keys = [...$keys, ...$tail];
               break;
            // @ The R6 shape: `0`..`N`, small indexes repeated, then indexes
            //   one short of doubling powers of two — a repeat after each
            //   now and then
            case 'reprised':
               $top = $Randomizer->getInt(0, 20);
               for ($at = 0; $at <= $top; $at++) {
                  $keys[] = $at;
                  if ($Randomizer->getInt(0, 3) === 0) {
                     $keys[] = $Randomizer->getInt(0, $at);
                  }
               }
               $power = 16;
               while ($power <= $top + 1) {
                  $power <<= 1;
               }
               $steps = $Randomizer->getInt(1, 4);
               for ($at = 0; $at < $steps; $at++) {
                  $keys[] = $power - 1;
                  if ($Randomizer->getInt(0, 2) === 0) {
                     $keys[] = $keys[$Randomizer->getInt(0, count($keys) - 1)];
                  }
                  $power <<= $Randomizer->getInt(0, 1);
               }
               break;
            // @ Drawn with replacement from `0`..`N`, the first from
            //   `0`..`7`: repeats and holes in any order
            case 'drawn':
               $top = $Randomizer->getInt(1, 40);
               $length = $Randomizer->getInt(2, 60);
               $keys[] = $key;
               for ($at = 1; $at < $length; $at++) {
                  $keys[] = $Randomizer->getInt(0, $top);
               }
               break;
            // @ A map from its first key — a name, a negative index or one
            //   past `7` — of names, indexes and negatives drawn with
            //   replacement
            default:
               $keys[] = ['s0', -1 - $key, 8 + $key][$Randomizer->getInt(0, 2)];
               $length = $Randomizer->getInt(2, 60);
               for ($at = 1; $at < $length; $at++) {
                  $keys[] = match ($Randomizer->getInt(0, 2)) {
                     0 => "s{$Randomizer->getInt(0, 9)}",
                     1 => $Randomizer->getInt(0, 30),
                     default => -$Randomizer->getInt(1, 10),
                  };
               }
         }
         $sequences[] = $keys;
      }

      $mismatched = [];
      $under = [];
      $short = [];
      $over = [];
      $untabled = [];
      $unparsed = [];
      $converted = 0;
      $lists = 0;
      $named = 0;
      $widest = 0;
      $ratio = 0.0;
      $listed = 0;
      $reconverted = 0;
      $mapped = 0;
      $overstated = 0;
      foreach ($sequences as $number => $keys) {
         // @ What the array really holds: its table, and the names built
         //   with it — nothing else is freed or allocated in between (the
         //   last name read is dropped first; a repeated name's fresh copy
         //   is freed as it is found)
         unset($key);
         $before = memory_get_usage();
         $built = [];
         foreach ($keys as $key) {
            if (is_string($key)) {
               $built["k{$key}"] = null;
            }
            else {
               $built[$key] = null;
            }
         }
         $real = memory_get_usage() - $before;
         $sample = json_encode([$number, array_slice($keys, 0, 24), count($keys)]);
         // ! The table PHP built: packed or not, and its slots' footprint
         $packed = $Packed($built);
         if ($packed === null) {
            $unparsed[] = $sample;
            unset($built);

            continue;
         }
         // ! The table Table predicts from the same keys, repeats included —
         //   and from the keys the array holds, as `tally()` reads them
         $Table = new Table;
         foreach ($keys as $key) {
            $Table->fill(is_string($key) ? "k{$key}" : $key);
         }
         // ! The names: what they spend and what `tally()` weighs them at
         $Kept = new Table;
         $strings = 0;
         $weighed = 0;
         foreach (array_keys($built) as $key) {
            $Kept->fill($key);
            if (is_string($key)) {
               $strings += $Name(strlen($key));
               $weighed += Buffers::weigh(strlen($key));
            }
         }
         $table = $real - ($built === [] ? 0 : 56) - $strings;
         $tally = Buffers::tally($built);
         $first = array_key_first($built);
         $begun = array_is_list($built) === false && is_int($first) && $first >= 0 && $first < 8;

         // ! Where every repeat landed: in a list, or in the map PHP turned
         //   it into — the shortest prefix no longer packed (a map never
         //   turns back into a list)
         $repeats = [];
         $held = [];
         foreach ($keys as $at => $key) {
            $name = is_string($key) ? "k{$key}" : $key;
            if (isSet($held[$name])) {
               $repeats[] = $at;
            }
            $held[$name] = true;
         }
         $length = count($keys) + 1;
         if ($repeats !== [] && $packed === false) {
            $low = 1;
            $high = count($keys);
            // @@ The prefix that converts
            while ($low < $high) {
               $middle = intdiv($low + $high, 2);
               $prefixed = $Packed($Build(array_slice($keys, 0, $middle)));
               if ($prefixed === null) {
                  $unparsed[] = "{$sample} prefix {$middle}";
                  break;
               }
               if ($prefixed) {
                  $low = $middle + 1;
               }
               else {
                  $high = $middle;
               }
            }
            $length = $low;
         }
         $inList = 0;
         $inMap = 0;
         foreach ($repeats as $at) {
            if ($at + 1 < $length) {
               $inList++;
            }
            else {
               $inMap++;
            }
         }

         // @ Evidence of the shapes the corpus reaches
         $converted += $begun ? 1 : 0;
         $lists += $packed ? 1 : 0;
         $named += $strings > 0 ? 1 : 0;
         $widest = max($widest, $Table->slots);
         $ratio = max($ratio, $real > 0 ? $tally / $real : 0.0);
         $listed += $inList > 0 && $inMap === 0 ? 1 : 0;
         $reconverted += $inList > 0 && $inMap === 0 && $packed === false ? 1 : 0;
         $mapped += $inMap > 0 ? 1 : 0;
         $overstated += $inMap > 0 && $Footprint($Table->bytes) > $table ? 1 : 0;

         $predicted = json_encode(['list' => $Table->list, 'slots' => $Table->slots, 'bytes' => $Table->bytes]);
         $spent = json_encode(['packed' => $packed, 'table' => $table, 'repeats in list' => $inList, 'in map' => $inMap]);
         // ? The table Table predicts is the table PHP built — exactly while
         //   every repeat landed in a list …
         if ($built !== [] && $inMap === 0 && ($Table->list !== $packed || $Footprint($Table->bytes) !== $table)) {
            $mismatched[] = "{$sample} predicted {$predicted} real {$spent}";
         }
         // ? … and never below it once a repeat landed in a map
         if ($inMap > 0 && ($Table->list !== $packed || $Footprint($Table->bytes) < $table)) {
            $under[] = "{$sample} predicted {$predicted} real {$spent}";
         }
         // ? tally() covers what the array holds, within 1.25 times it
         if ($tally < $real) {
            $short[] = "{$sample} tally {$tally} < real {$real}";
         }
         if ($tally > 1.25 * $real) {
            $over[] = "{$sample} tally {$tally} > 1.25 x real {$real}";
         }
         // ? An array begun as a list is priced by the table of its keys
         if ($begun && $tally !== 56 + Buffers::weigh($Kept->bytes) + $weighed) {
            $untabled[] = "{$sample} tally {$tally} != table {$Kept->bytes}";
         }
         unset($built);
      }
      $count = count($sequences);
      $evidence = json_encode([
         'sequences' => $count, 'begun as lists, converted' => $converted, 'packed' => $lists,
         'with names' => $named, 'widest' => $widest, 'repeated in a list' => $listed,
         'repeated in a list, then converted' => $reconverted, 'repeated in a map' => $mapped,
         'overstated' => $overstated, 'worst tally/real' => round($ratio, 3),
      ]);

      yield new Assertion(
         description: "(b) every sequence's real table is the one Table predicts (never below it past a repeat in a map), and tally() covers it within 1.25x: {$evidence}"
      )
         ->expect(
            [
               'failing' => [
                  'unparsed' => count($unparsed), 'mismatched' => count($mismatched), 'under' => count($under),
                  'short' => count($short), 'over' => count($over), 'untabled' => count($untabled),
               ],
               'unparsed' => array_slice($unparsed, 0, 5),
               'mismatched' => array_slice($mismatched, 0, 5),
               'under' => array_slice($under, 0, 5),
               'short' => array_slice($short, 0, 5),
               'over' => array_slice($over, 0, 5),
               'untabled' => array_slice($untabled, 0, 5),
            ],
            Op::Identical,
            [
               'failing' => [
                  'unparsed' => 0, 'mismatched' => 0, 'under' => 0,
                  'short' => 0, 'over' => 0, 'untabled' => 0,
               ],
               'unparsed' => [], 'mismatched' => [], 'under' => [], 'short' => [], 'over' => [], 'untabled' => [],
            ]
         )
         ->assert();

      // ? The corpus reaches every shape: never vacuous (each count capped
      //   at the least it must reach)
      $minimums = [
         'sequences' => 5_138, 'begun as lists, converted' => 1_500, 'packed' => 150,
         'with names' => 300, 'widest' => 2_048, 'repeated in a list' => 300,
         'repeated in a list, then converted' => 100, 'repeated in a map' => 600,
         'overstated' => 50,
      ];
      $reached = [
         'sequences' => min($count, $minimums['sequences']),
         'begun as lists, converted' => min($converted, $minimums['begun as lists, converted']),
         'packed' => min($lists, $minimums['packed']),
         'with names' => min($named, $minimums['with names']),
         'widest' => min($widest, $minimums['widest']),
         'repeated in a list' => min($listed, $minimums['repeated in a list']),
         'repeated in a list, then converted' => min($reconverted, $minimums['repeated in a list, then converted']),
         'repeated in a map' => min($mapped, $minimums['repeated in a map']),
         'overstated' => min($overstated, $minimums['overstated']),
      ];

      yield new Assertion(
         description: "(b) the corpus reaches every shape — lists converted to maps, packed lists, names, wide tables, repeats in lists and in maps: {$evidence}"
      )
         ->expect($reached, Op::Identical, $minimums)
         ->assert();

      // # (c) tally(): 56 bytes an array, its table at weigh(), and every
      //   string key and value at weigh() — nested arrays in full
      $sparse = array_fill_keys([...range(0, 16), 63, 64], 0);
      $tallies = [
         // ? The empty array is shared
         'empty' => Buffers::tally([]),
         // ? A list of 3 ints: 56 + weigh(8 x 16 = 128) = 56 + 160
         'int list' => Buffers::tally([1, 2, 3]),
         // ? 9 strings of 100 bytes: 56 + weigh(16 x 16) 320 + 9 x 160
         'string list' => Buffers::tally(array_fill(0, 9, str_repeat('v', 100))),
         // ? Begun by a name — 9 names: 56 + weigh(16 x 40) 768 + 9 x
         //   (name 40 + value 40)
         'map by name' => Buffers::tally(array_fill_keys($names, 'x')),
         // ? The R5 object: 56 + weigh(128 x 40 = 5,120) — two pages, 254
         //   to a chunk — 8,257
         'map by index' => Buffers::tally($sparse),
         // ? Nested — the outer map of 3 names: 56 + weigh(8 x 40) 384 +
         //   3 x 40 = 560; user, a map of 2 names and a value: 560; tags, a
         //   list of 2: 56 + 160 + 2 x 40 = 296; grid [5..8], begun as a full
         //   list: a map of 16, 56 + 768 + 4 x 40 = 984; held [0, 2, 1], a
         //   hole: a map of 8, 56 + 384 + 3 x 40 = 560
         'nested' => Buffers::tally([
            'user' => ['name' => 'alice', 'tags' => ['a', 'b']],
            'grid' => [5 => 'p', 6 => 'q', 7 => 'r', 8 => 's'],
            'held' => [0 => 'x', 2 => 'y', 1 => 'z'],
         ]),
         // ? Nested singletons (`parse_str()` since PHP 8.4.27 / 8.5.12) —
         //   the outer map of 1 name: 56 + 384 + 40 = 480; its level, a map
         //   of 1 slot: 56 + weigh(1 x 40) 80 + name 40 + value 40 = 216
         'singleton nested' => Buffers::tally(['a' => ['b' => 'c']], 1),
         // ? A nested singleton begun as a list, then a hole: the outer
         //   480; its level a map of 2 slots, 56 + weigh(2 x 40) 112 + 2 x
         //   40 = 248
         'singleton list, hole' => Buffers::tally(['a' => [0 => 'x', 2 => 'y']], 1),
      ];

      yield new Assertion(
         description: '(c) tally() of lists, maps begun by a name or an index and nested arrays is exact'
      )
         ->expect($tallies, Op::Identical, [
            'empty' => 0,
            'int list' => 216,
            'string list' => 1_816,
            'map by name' => 1_544,
            'map by index' => 8_313,
            'nested' => 2_960,
            'singleton nested' => 696,
            'singleton list, hole' => 728,
         ])
         ->assert();

      // # (d) This runtime's layout: a string cut from a larger one (substr(),
      //   explode(), preg_* captures) spends at most weigh() — 24 + its
      //   bytes + 1 before PHP 8.4.27 / 8.5.12, its bytes + 32 since — and
      //   what parse_str() builds is covered by tally() with
      //   `Buffers::NESTED`, within 1.5 times it
      //   — one string at a time (nothing else allocates beside it), and
      //   the parse by dropping it, as the snapshots of 1.9 are (what the
      //   engine allocates around a parse varies with the run: under a
      //   child runner, 160 KiB more)
      $source = str_repeat('abcdefgh', 200);
      $cuts = [];
      $short = [];
      // @@ Strings of 3 to 1,000 bytes
      foreach ([3, 10, 100, 1_000] as $length) {
         $before = memory_get_usage();
         $cut = substr($source, 1, $length);
         $cuts[$length] = [memory_get_usage() - $before, Buffers::weigh($length)];
         if ($cuts[$length][0] > $cuts[$length][1]) {
            $short[] = $length;
         }
         unset($cut);
      }
      $query = implode('&', array_map(static fn (int $at): string => "a{$at}%5Bb{$at}%5D=v{$at}", range(1, 120)));
      parse_str($query, $parsed);
      $nested = Buffers::tally($parsed, Buffers::NESTED);
      $held = memory_get_usage();
      unset($parsed);
      $parse = $held - memory_get_usage();
      $layout = json_encode([
         'cut (spent, weighed)' => $cuts, 'parsed' => $parse, 'tallied' => $nested, 'NESTED' => Buffers::NESTED,
      ]);

      yield new Assertion(
         description: "(d) weigh() covers the strings this runtime cuts, and tally() with Buffers::NESTED what its parse_str() builds, within 1.5x: {$layout}"
      )
         ->expect(
            [$short, $nested >= $parse, $nested <= 1.5 * $parse],
            Op::Identical,
            [[], true, true]
         )
         ->assert();
   }),
);
