<?php


use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test;


class CacheEntity_7_2
{
   public string $name = '';
   public mixed $Self = null;
}


return new Test(
   description: 'Cache(File): objects are refused by default and round-trip only when declared',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-cache-optin-' . uniqid('', true);

      // # Default — nothing but the driver's own record wrapper is reconstructed
      $Closed = new Cache(['driver' => 'file', 'path' => $dir, 'prefix' => 'c:']);

      $Entity = new CacheEntity_7_2;
      $Entity->name = 'bootgly';
      $Closed->store('entity', $Entity);
      $Closed->store('nested', ['Entity' => $Entity]);
      $Closed->store('scalar', 'plain');
      $Closed->store('arr', ['a' => 1, 'b' => [2, 3]]);

      yield assert(
         assertion: $Closed->fetch('entity') === null && $Closed->check('entity') === false,
         description: 'An undeclared class reads as a miss, never as a half-built object'
      );
      // ? unserialize() downgrades a refused class WHEREVER it sits, so a
      //   top-level check alone would answer this read with a live hit whose
      //   array holds an inert placeholder — worse than a miss, because the
      //   caller never recomputes and the placeholder raises on first use
      yield assert(
         assertion: $Closed->fetch('nested') === null && $Closed->check('nested') === false,
         description: 'An undeclared class nested inside an array reads as a miss too'
      );

      $Closed->store('inside', (object) ['held' => new CacheEntity_7_2]);
      yield assert(
         assertion: $Closed->fetch('inside') === null && $Closed->check('inside') === false,
         description: 'An undeclared class held by a declared one reads as a miss'
      );

      yield assert(
         assertion: $Closed->fetch('scalar') === 'plain'
            && $Closed->fetch('arr') === ['a' => 1, 'b' => [2, 3]],
         description: 'Scalars and arrays are unaffected by the allow-list'
      );

      // ? The walk that finds a nested placeholder must not mistake a graph
      //   that loops back on itself for one that is endlessly deep
      $Cyclic = new CacheEntity_7_2;
      $Cyclic->name = 'cyclic';
      $Cyclic->Self = $Cyclic;
      $Open2 = new Cache([
         'driver' => 'file',
         'path' => $dir,
         'prefix' => 'c:',
         'classes' => [CacheEntity_7_2::class],
      ]);
      $Open2->store('cyclic', $Cyclic);
      $Back = $Open2->fetch('cyclic');

      $recursive = ['v' => 1];
      $recursive['me'] = &$recursive;
      $Open2->store('recursive', $recursive);

      yield assert(
         assertion: $Back instanceof CacheEntity_7_2
            && $Back->Self === $Back
            && $Open2->fetch('recursive') !== null,
         description: 'A self-referencing object and a recursive array still round-trip'
      );

      // # Opt-in — the same on-disk store, read by a cache that declares the class
      $Open = new Cache([
         'driver' => 'file',
         'path' => $dir,
         'prefix' => 'c:',
         'classes' => [CacheEntity_7_2::class],
      ]);

      $Fetched = $Open->fetch('entity');
      yield assert(
         assertion: $Fetched instanceof CacheEntity_7_2 && $Fetched->name === 'bootgly',
         description: 'A declared class round-trips'
      );

      $Nested = $Open->fetch('nested');
      yield assert(
         assertion: is_array($Nested) && ($Nested['Entity'] ?? null) instanceof CacheEntity_7_2,
         description: 'A declared class round-trips nested inside a value'
      );

      // # Config normalization — a malformed entry never widens the allow-list
      $Filtered = new Cache(['classes' => [CacheEntity_7_2::class, '', 42, null]]);
      yield assert(
         assertion: $Filtered->Config->classes === [CacheEntity_7_2::class]
            && new Cache()->Config->classes === []
            && new Cache(['classes' => 'nope'])->Config->classes === [],
         description: 'Config keeps only non-empty class-name strings and defaults to none'
      );

      $Closed->clear();
   }
);
