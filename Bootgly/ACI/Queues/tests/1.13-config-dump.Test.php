<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues;


use function assert;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function str_contains;
use function var_dump;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Drivers\Redis;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Queues\Config: debug dumps mask the Redis password, also through the facade, the driver registry and the Redis driver',
   test: function () {
      // ! SYNTH credential — never a real one
      $password = 'SYNTH-QUEUES-PASSWORD-1.13';
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

      // ! The holder, and the objects that nest it
      $Queues = new Queues([
         'driver' => 'redis',
         'host' => 'redis.example.invalid',
         'password' => $password
      ]);
      $Holders = [
         'Config' => $Queues->Config,
         'Queues' => $Queues,
         'Drivers' => $Queues->Drivers,
         'Drivers\Redis' => new Redis($Queues->Config)
      ];

      // @@ Every holder through every channel
      $leaks = [];
      $missing = [];
      foreach ($Holders as $holder => $Holder) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Holder);

            if (str_contains($output, $password) === true) {
               $leaks[] = "{$holder} via {$channel}";
            }
            foreach (['redis.example.invalid', Secret::MASK] as $shown) {
               if (str_contains($output, $shown) === false) {
                  $missing[] = "{$shown} in {$holder} via {$channel}";
               }
            }
         }
      }
      $leaked = implode(', ', $leaks);
      $absent = implode(', ', $missing);
      yield assert(
         assertion: $leaks === [] && $missing === [],
         description: "The password reaches no var_dump, print_r, debug_zval_dump or Dumper, while the host shows (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ Dumping never writes
      yield assert(
         assertion: $Queues->Config->password === $password,
         description: 'The live password is untouched by the dumps'
      );

      // @ Not configured shows as it is
      $empty = $Dumper->dump(new Config());
      yield assert(
         assertion: str_contains($empty, "+password: ''") === true
            && str_contains($empty, Secret::MASK) === false,
         description: 'An empty password shows as empty — a dump still tells "no AUTH"'
      );

      // @ The one way: the trait's __debugInfo, #[Secret] on the stored property only
      $Own = new ReflectionMethod($Queues->Config, '__debugInfo');
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $marked = [];
      $misplaced = [];
      foreach ((new ReflectionClass(Config::class))->getProperties() as $Property) {
         if ($Property->getAttributes(Secret::class) === []) {
            continue;
         }

         $marked[] = $Property->name;
         if ($Property->isStatic() === true || $Property->isVirtual() === true) {
            $misplaced[] = $Property->name;
         }
      }
      yield assert(
         assertion: $Own->getFileName() === $Trait->getFileName()
            && $Own->getStartLine() === $Trait->getStartLine()
            && $marked === ['password']
            && $misplaced === [],
         description: 'Queues\Config dumps through the Redactable trait, #[Secret] on the stored password only'
      );
   }
);
