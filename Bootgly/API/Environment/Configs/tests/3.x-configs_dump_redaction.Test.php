<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment\Configs\Tests\DumpRedaction;


use function assert;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function putenv;
use function str_contains;
use function var_dump;
use Closure;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environment\Configs;
use Bootgly\API\Projects\Configs as ProjectConfigs;


/**
 * A config loader — the framework's and a project's — holding the `.env` map
 * of a scope, as it does while the scope file runs, dumps through `var_dump`,
 * `print_r`, `debug_zval_dump` and `Dumper` without a value of that map,
 * keeps the map live, still binds the scope from it, and gets its
 * `__debugInfo()` from `Redactable`. The scope tree it binds dumps without
 * a bound value either — a node may hold any `.env` value — while `get()`
 * still reads it.
 */
return new Test(
   description: 'Configs: a loader keeps its .env map out of every debug dump and still binds from it',
   test: function () {
      // ! The scope's keys come from its .env only, never from the process
      putenv('REDACTION_SECRET');
      putenv('REDACTION_NAME');
      $secret = 'SYNTH-ENV-SECRET-0123456789';
      $basedir = __DIR__ . '/fixtures/configs/';

      // ! Each debug channel
      $Dumper = new Dumper('plain');
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

      // ! The loaders, each holding the scope's .env map — read in by the loader itself
      $Inject = Closure::bind(
         static fn (Configs $Configs): bool => $Configs->inject('redaction', "{$Configs->basedir}redaction/.env"),
         null,
         Configs::class
      );
      $Read = Closure::bind(static fn (Configs $Configs): array => $Configs->variables, null, Configs::class);
      $Loaders = [
         'Configs' => new Configs($basedir),
         'Projects\Configs' => new ProjectConfigs($basedir),
      ];
      $injected = true;
      foreach ($Loaders as $Loader) {
         $injected = $injected && $Inject($Loader);
      }

      // @@ Every loader through every channel
      $outputs = [];
      $leaks = [];
      foreach ($Loaders as $label => $Loader) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Loader);
            $outputs["{$label} via {$channel}"] = $output;
            if (str_contains($output, 'SYNTH') === true || str_contains($output, 'visible-name') === true) {
               $leaks[] = "{$label} via {$channel}";
            }
         }
      }
      $leaked = implode(', ', $leaks);
      yield assert(
         assertion: $injected === true && $leaks === [],
         description: "No .env value reaches var_dump, print_r, debug_zval_dump or Dumper of a loader (leaked: [{$leaked}])"
      );
      $missing = [];
      foreach ($outputs as $dump => $output) {
         foreach (['fixtures/configs/', Secret::MASK] as $shown) {
            if (str_contains($output, $shown) === false) {
               $missing[] = "{$dump}: {$shown}";
            }
         }
      }
      $absent = implode(', ', $missing);
      yield assert(
         assertion: $missing === [],
         description: "Each dump still shows the base directory and the mask in place of the .env map (missing: [{$absent}])"
      );

      // @ The map is live — dumping never writes
      $map = ['REDACTION_SECRET' => $secret, 'REDACTION_NAME' => 'visible-name'];
      yield assert(
         assertion: $Read($Loaders['Configs']) === $map && $Read($Loaders['Projects\Configs']) === $map,
         description: 'Every loader still holds its .env map after the dumps'
      );

      // @ The loader still binds the scope from its .env, and drops the map once the scope is loaded
      $Loaded = new Configs($basedir);
      $loaded = $Loaded->load('redaction');
      $Scope = $Loaded->Scopes->get('redaction');
      yield assert(
         assertion: $loaded === true
            && $Scope?->Secret->get() === $secret
            && $Scope->Name->get() === 'visible-name'
            && $Read($Loaded) === [],
         description: 'A scope still binds its .env values, and the loader drops the map after loading it'
      );

      // @@ The loaded scope tree — and the loader holding it — through every channel
      $exposed = [];
      $unmasked = [];
      foreach (['scope' => $Scope, 'loader' => $Loaded] as $label => $Holder) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Holder === null ? '' : $Channel($Holder);
            if (str_contains($output, 'SYNTH') === true || str_contains($output, 'visible-name') === true) {
               $exposed[] = "{$label} via {$channel}";
            }
            if (str_contains($output, Secret::MASK) === false || str_contains($output, 'redaction') === false) {
               $unmasked[] = "{$label} via {$channel}";
            }
         }
      }
      $leakedTree = implode(', ', $exposed);
      $bareTree = implode(', ', $unmasked);
      yield assert(
         assertion: $Scope !== null && $exposed === [] && $unmasked === [],
         description: "No bound value reaches a dump of the scope tree, which still shows its scope and the mask (leaked: [{$leakedTree}], unmasked: [{$bareTree}])"
      );
      yield assert(
         assertion: $Scope?->Secret->get() === $secret && $Scope->Name->get() === 'visible-name',
         description: 'Every bound value still reads back after the dumps'
      );

      // @ One mechanism: the trait's __debugInfo, #[Secret] only on stored instance properties
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $foreign = [];
      $misplaced = [];
      $unmarked = [];
      foreach ($Loaders as $Loader) {
         $Method = new ReflectionMethod($Loader, '__debugInfo');
         if ($Method->getFileName() !== $Trait->getFileName() || $Method->getStartLine() !== $Trait->getStartLine()) {
            $foreign[] = $Loader::class;
         }

         $marked = 0;
         $Class = new ReflectionClass($Loader);
         // @@ The class and every ancestor
         do {
            foreach ($Class->getProperties() as $Property) {
               if ($Property->getAttributes(Secret::class) === []) {
                  continue;
               }
               $marked++;
               if ($Property->isStatic() === true || $Property->isVirtual() === true) {
                  $misplaced[] = "{$Class->name}::\${$Property->name}";
               }
            }
            $Class = $Class->getParentClass();
         } while ($Class !== false);
         if ($marked === 0) {
            $unmarked[] = $Loader::class;
         }
      }
      $others = implode(', ', $foreign);
      $wrong = implode(', ', $misplaced);
      $bare = implode(', ', $unmarked);
      yield assert(
         assertion: $foreign === [] && $misplaced === [] && $unmarked === [],
         description: "Each loader dumps through Redactable::__debugInfo (others: [{$others}]) and marks a stored, non-static property #[Secret] (misplaced: [{$wrong}], unmarked: [{$bare}])"
      );
   }
);
