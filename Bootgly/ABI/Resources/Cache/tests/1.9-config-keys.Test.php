<?php

use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Config;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * Every config key is spelled like the property it fills, and only those keys.
 *
 * `TTL` is an acronym, so the key is `TTL` — the same name the property, the
 * route cache options (`array{TTL: int}`) and the Toasts component already
 * use. A lowercase `ttl` is not an alias: it is refused by name, the way
 * `Router` already refuses an unknown route cache option, because the silent
 * fallback to the default is what a cache that never expires looks like from
 * the outside.
 */
return new Test(
   description: 'Cache Config reads every option by the name of the property it fills',
   test: function () {
      // ! Each option, a value that cannot be confused with its default
      $Clock = static fn (): int => 1_700_000_000;
      $options = [
         'driver' => ['memory', 'memory'],
         'prefix' => ['p:', 'p:'],
         'TTL' => [60, 60],
         'classes' => [['\\App\\Product'], ['App\\Product']],
         'path' => ['/tmp/bootgly-cache/', '/tmp/bootgly-cache'],
         'segment' => [4_242, 4_242],
         'size' => [1_024, 1_024],
         'permissions' => [0644, 0644],
         'host' => ['10.0.0.9', '10.0.0.9'],
         'port' => [6_380, 6_380],
         'password' => ['s3cr3t', 's3cr3t'],
         'database' => [3, 3],
         'timeout' => [7, 7.0],
         'secure' => [true, true],
         'persistent' => [true, true],
         'clock' => [$Clock, $Clock]
      ];

      // @@ The key fills the property of the same name — all 16, none by luck
      foreach ($options as $option => [$value, $expected]) {
         $Config = new Config([$option => $value]);

         yield assert(
            assertion: $Config->{$option} === $expected,
            description: "`{$option}` fills the property of the same name"
         );
      }

      // ? Every option is covered above — a new one must be pinned here too
      yield assert(
         assertion: array_keys($options) === Config::OPTIONS,
         description: 'the pinned options are exactly the options the Config accepts'
      );

      // @ A misspelled key is refused by name, never silently defaulted
      $refused = '';
      try {
         new Config(['ttl' => 60]);
      }
      catch (InvalidArgumentException $Exception) {
         $refused = $Exception->getMessage();
      }

      yield assert(
         assertion: str_contains($refused, "Unknown cache option 'ttl'"),
         description: 'a lowercase `ttl` is refused by name — it never falls back to the default'
      );

      // @ A TTL that would coerce into a wrong window is refused too
      $coerced = '';
      try {
         new Config(['TTL' => '1h']);
      }
      catch (InvalidArgumentException $Exception) {
         $coerced = $Exception->getMessage();
      }

      yield assert(
         assertion: str_contains($coerced, "Invalid cache option 'TTL'"),
         description: '`1h` is refused instead of coercing into 1 second'
      );

      // @ A fraction truncates to 0, which is this schema's `forever`
      $fraction = '';
      try {
         new Config(['TTL' => 0.5]);
      }
      catch (InvalidArgumentException $Exception) {
         $fraction = $Exception->getMessage();
      }

      yield assert(
         assertion: str_contains($fraction, "Invalid cache option 'TTL'"),
         description: '`0.5` is refused instead of truncating into `forever`'
      );

      // @ An explicit null is a misconfiguration, not an omission; the float
      //   2^63 is what `(float) PHP_INT_MAX` rounds up to, right past the cast
      $refusals = 0;
      foreach ([null, 9223372036854775808.0, '9223372036854775808'] as $value) {
         try {
            new Config(['TTL' => $value]);
         }
         catch (InvalidArgumentException $Exception) {
            $refusals += (int) str_contains($Exception->getMessage(), "Invalid cache option 'TTL'");
         }
      }

      yield assert(
         assertion: $refusals === 3,
         description: '`null`, the float 2^63 and the 19-digit string past it are refused by the guard, not by the cast'
      );

      yield assert(
         assertion: new Config(['TTL' => '9223372036854775807'])->TTL === PHP_INT_MAX,
         description: 'the digit string of PHP_INT_MAX is still whole seconds an int can hold'
      );

      // @ The configured default reaches the counters, not only the writes
      $Cache = new Cache(['driver' => 'memory', 'TTL' => 60, 'clock' => $Clock]);
      $Cache->increment('quota:config-keys');

      yield assert(
         assertion: $Cache->remain('quota:config-keys') === 60,
         description: 'increment() without an explicit TTL opens the window with the configured default'
      );

      $Cache->decrement('credits:config-keys');

      yield assert(
         assertion: $Cache->remain('credits:config-keys') === 60,
         description: 'decrement() opens the window with the configured default too'
      );
   }
);
