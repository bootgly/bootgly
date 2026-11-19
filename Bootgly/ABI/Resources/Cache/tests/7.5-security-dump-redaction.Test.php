<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache;


use function array_keys;
use function assert;
use function debug_zval_dump;
use function function_exists;
use function implode;
use function ob_get_clean;
use function ob_start;
use function posix_geteuid;
use function print_r;
use function str_contains;
use function var_dump;
use Closure;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Drivers\Redis;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The Redis password lives in `Cache\Config::$password`; the facade and the Redis driver
 * only hold that Config. Every debug channel shows it masked, the live value is untouched,
 * and no dump reads the `path` hook — it locates (and makes) the cache directory.
 */
return new Test(
   description: 'Cache: a debug dump of the configuration, the facade and the Redis driver masks the password',
   test: function () {
      $password = 'SYNTH-CACHE-PASSWORD-0123456789';
      $Dumper = new Dumper('plain');
      // ! Each debug channel
      $Channels = [
         'var_dump' => static function (object $Object): string {
            ob_start();
            var_dump($Object);

            return (string) ob_get_clean();
         },
         'print_r' => static fn (object $Object): string => print_r($Object, true),
         'debug_zval_dump' => static function (object $Object): string {
            ob_start();
            debug_zval_dump($Object);

            return (string) ob_get_clean();
         },
         'Dumper' => static fn (object $Object): string => $Dumper->dump($Object),
      ];

      // ! No located directory yet: a read of the `path` hook would locate it again
      Config::forget();
      // ! A Redis cache that never connects (the connection is lazy)
      $Config = new Config(['driver' => 'redis', 'host' => '127.0.0.1', 'password' => $password]);
      $Cache = new Cache(['driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379, 'password' => $password]);
      $Holders = [
         'Cache\Config' => $Config,
         'Cache' => $Cache,
         'Cache\Drivers\Redis' => $Cache->Driver,
      ];

      // @ Every holder through every channel
      $leaks = [];
      $missing = [];
      foreach ($Holders as $holder => $Holder) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Holder);
            if (str_contains($output, $password) === true) {
               $leaks[] = "{$holder} {$channel}";
            }
            foreach (['127.0.0.1', Secret::MASK] as $shown) {
               if (str_contains($output, $shown) === false) {
                  $missing[] = "{$holder} {$channel}: {$shown}";
               }
            }
         }
      }
      $leaked = implode(', ', $leaks);
      $absent = implode(', ', $missing);
      yield assert(
         assertion: $leaks === [] && $missing === [],
         description: 'No debug channel shows the password of the configuration, the facade or the Redis driver, '
            . "while the host shows (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ The live values are untouched
      yield assert(
         assertion: $Config->password === $password
            && $Cache->Config->password === $password
            && $Cache->Driver instanceof Redis
            && $Cache->Driver->Config->password === $password,
         description: 'Dumping never writes: the configuration the Redis driver authenticates with keeps the password'
      );

      // @ No dump read the `path` hook (it would have located the directory again)
      $Directories = Closure::bind(static fn (): array => Config::$directories, null, Config::class);
      $located = function_exists('posix_geteuid') === true
         && isSet($Directories()[posix_geteuid()]) === true;
      yield assert(
         assertion: $located === false
            && str_contains($Channels['print_r']($Config), "[path] => \n") === true
            && $Config->located === true,
         description: 'No channel runs the `path` hook: the located configuration dumps its empty stored path'
      );

      // @ The trait is the dump of the holder; the facade and the driver add none of their own
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $Method = new ReflectionMethod($Config, '__debugInfo');
      yield assert(
         assertion: $Method->getFileName() === $Trait->getFileName()
            && $Method->getStartLine() === $Trait->getStartLine()
            && (new ReflectionClass($Cache))->hasMethod('__debugInfo') === false
            && (new ReflectionClass($Cache->Driver))->hasMethod('__debugInfo') === false,
         description: 'Cache\Config dumps through the Redactable trait; the facade and the driver write no dump of their own'
      );

      // @ The marked properties store their value: none is static or virtual
      $marked = [];
      $misplaced = [];
      foreach ((new ReflectionClass(Config::class))->getProperties() as $Property) {
         if ($Property->getAttributes(Secret::class) === []) {
            continue;
         }
         $marked[$Property->name] = true;
         if ($Property->isStatic() === true || $Property->isVirtual() === true) {
            $misplaced[] = $Property->name;
         }
      }
      $names = implode(', ', array_keys($marked));
      yield assert(
         assertion: array_keys($marked) === ['password'] && $misplaced === [],
         description: "Cache\Config marks only the stored password (marked: [{$names}])"
      );
   }
);
