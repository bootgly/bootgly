<?php

use Bootgly\ABI\Clock as SystemClock;
use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Config;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Cache(Config): the `clock` option takes a Clock; a legacy closure reads the same',
   test: function () {
      // # Config: what each `clock` option becomes
      $Fake = new Clock(1_000);
      $Closure = static fn (): float => $Fake->now;

      $Config = new Config(['clock' => $Fake]);
      yield assert(
         assertion: $Config->Clock === $Fake && $Config->clock === null,
         description: 'a Clock instance is the Config Clock'
      );
      $Config = new Config(['clock' => $Closure]);
      yield assert(
         assertion: ($Config->Clock instanceof SystemClock && $Config->Clock::class !== SystemClock::class) && $Config->clock === $Closure,
         description: 'a closure (deprecated) is wrapped into an adapted Clock and kept in $clock'
      );
      $Config = new Config();
      yield assert(
         assertion: $Config->Clock::class === SystemClock::class && $Config->clock === null,
         description: 'no clock option reads the system Clock'
      );
      $Config = new Config(['clock' => 'not a clock']);
      yield assert(
         assertion: $Config->Clock::class === SystemClock::class && $Config->clock === null,
         description: 'any other value falls back to the system Clock, as before'
      );

      // # Drivers: one scenario, driven by a Clock and by a closure
      // ! APCu and Redis are left out on purpose: they expire entries on their
      //   own (server) clock, which no Config Clock can move
      $drivers = ['memory', 'file'];
      if (extension_loaded('sysvshm') && extension_loaded('sysvsem')) {
         $drivers[] = 'shared';
      }

      // ! Removes a reserved temporary tree (the cache files under it)
      $purge = static function (string $path) use (&$purge): void {
         if (is_dir($path) === true && is_link($path) === false) {
            foreach (scandir($path) ?: [] as $child) {
               if ($child !== '.' && $child !== '..') {
                  $purge("{$path}/{$child}");
               }
            }
            @rmdir($path);

            return;
         }
         @unlink($path);
      };
      $run = static function (string $driver, bool $instance) use ($purge): array {
         // ! Fractional start: whole seconds are read truncated, `(int) $now`
         $Clock = new Clock(2_000_000.9);
         $id = uniqid('', true);
         $directory = null;
         $options = [
            'driver' => $driver,
            'prefix' => "clock:{$id}:",
            'clock' => $instance ? $Clock : static fn (): float => $Clock->now,
         ];
         if ($driver === 'file') {
            $directory = Temporaries::reserve('cache-clock');
            $options['path'] = $directory;
         }
         if ($driver === 'shared') {
            $options['segment'] = random_int(200_000, 9_000_000);
            $options['size'] = 262_144;
         }
         $Cache = new Cache($options);
         try {
            $Cache->clear();

            // @
            $trace = [];
            $Cache->store('k', 'v', 5);
            // ! `c` is only ever checked: check() reads the Clock on its own,
            //   before any fetch() of that key could expire it
            $Cache->store('c', 'v', 5);
            $trace[] = $Cache->remain('k');
            $trace[] = $Cache->check('c');
            $Clock->advance(4);
            $trace[] = $Cache->fetch('k');
            $trace[] = $Cache->remain('k');
            $Clock->advance(1);
            $trace[] = $Cache->check('c');
            $trace[] = $Cache->fetch('k');
            $trace[] = $Cache->check('k');

            $Cache->store('w', 'x', 10);
            $Clock->freeze(2_000_000.0);
            $trace[] = $Cache->remain('w');
         }
         finally {
            // ! Release
            $Cache->clear();
            $Driver = $Cache->Driver;
            if ($Driver instanceof Shared) {
               $Driver->destroy();
            }
            if ($directory !== null) {
               $purge($directory);
            }
         }

         return $trace;
      };

      foreach ($drivers as $driver) {
         $Instance = $run($driver, true);
         $Legacy = $run($driver, false);

         yield assert(
            assertion: $Instance === [5, true, 'v', 1, false, null, false, 15],
            description: "{$driver}: a Clock instance drives the TTL in whole seconds"
         );
         yield assert(
            assertion: $Legacy === $Instance,
            description: "{$driver}: a legacy closure gives identical results"
         );
      }
   }
);
