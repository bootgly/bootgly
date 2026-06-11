<?php

use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(File): hit/miss/evict events dispatch through Emitter::$Instance with payload',
   test: function () {
      // ! Fresh bus — Cache uses the shared Emitter::$Instance, isolate the suite
      Emitter::$Instance = new Emitter();
      $Emitter = Emitter::$Instance;

      $hits = [];
      $misses = [];
      $evicts = [];
      $Emitter->listen(Events::Hit, function (Emission $Emission) use (&$hits) {
         $hits[] = $Emission->payload;
      });
      $Emitter->listen(Events::Miss, function (Emission $Emission) use (&$misses) {
         $misses[] = $Emission->payload;
      });
      $Emitter->listen(Events::Evict, function (Emission $Emission) use (&$evicts) {
         $evicts[] = $Emission->payload;
      });

      $dir = sys_get_temp_dir() . '/bootgly-cache-events-' . uniqid();
      $Cache = new Cache(['driver' => 'file', 'path' => $dir, 'prefix' => 'e:']);

      // @ Hit
      $Cache->store('k', 'v');
      $value = $Cache->fetch('k');
      yield assert(
         assertion: $value === 'v',
         description: 'fetch() returns the stored value'
      );
      yield assert(
         assertion: $hits === [['k', 'v']],
         description: 'Cache.Hit fired once with [key, value]'
      );

      // @ Miss
      $Cache->fetch('absent');
      yield assert(
         assertion: $misses === [['absent']],
         description: 'Cache.Miss fired once with [key]'
      );

      // @ Evict
      $deleted = $Cache->delete('k');
      yield assert(
         assertion: $deleted === true && $evicts === [['k', true]],
         description: 'Cache.Evict fired once with [key, deleted]'
      );

      // ? Event isolation: hit listener not re-triggered by miss/evict
      yield assert(
         assertion: $hits === [['k', 'v']],
         description: 'Cache.Hit not re-fired by a later miss or evict'
      );

      // ! Restore a clean bus for any later suite using the shared instance
      Emitter::$Instance = new Emitter();
   }
);
