<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Platform;


use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use const T_FUNCTION;
use const T_OBJECT_OPERATOR;
use const T_STRING;
use const T_VARIABLE;
use function assert;
use function count;
use function fclose;
use function file_get_contents;
use function is_array;
use function json_decode;
use function json_encode;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function token_get_all;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * A platform's `autoboot.php` runs before Bootgly's (constants and autoloader,
 * no Bootgly dependency), so its root — which implements `Bootgly\API\Platform`
 * — cannot be created there: `Bootgly::autoboot()` creates it once Bootgly's
 * autoloader exists, defines the global constant and boots it, before CLI
 * routes a command. Each scenario runs in a child: the boot is once per process.
 */
return new Test(
   description: 'Platform: Bootgly mounts each installed platform root once its own autoloader exists',
   test: function () {
      // ! A child that plays a platform `autoboot.php` (scenario in argv) around Bootgly's
      $code = <<<'PHP'
      [, $scenario, $autoboot] = $argv;
      [$platform, $prefix] = $scenario === 'console' ? ['Console', 'CONSOLE'] : ['Web', 'WEB'];
      $Install = static function () use ($scenario, $platform, $prefix): void {
         define("{$prefix}_ROOT_BASE", sys_get_temp_dir());
         if ($scenario === 'unloadable') {
            return;
         }
         spl_autoload_register(function (string $class) use ($scenario, $platform): void {
            if ($class !== $platform) {
               return;
            }
            $contract = $scenario === 'foreign' ? '' : 'implements Bootgly\API\Platform';
            eval("class {$platform} {$contract} { public static int \$boots = 0; public static bool \$named = false; public function autoboot (): void { self::\$boots++; self::\$named = defined('{$platform}'); } }");
         });
      };
      // @ The platform's autoboot.php before Bootgly's (the launchers)
      if ($scenario !== 'absent' && $scenario !== 'late') {
         $Install();
      }
      if ($scenario === 'legacy') {
         define('Web', 'legacy');
      }
      $error = null;
      try {
         require $autoboot;
         // @ ... or after it (Composer's `files` order): it mounts its own root
         if ($scenario === 'late') {
            $Install();
            Bootgly::mount('Web');
         }
         if ($scenario === 'unknown') {
            Bootgly::mount('Mobile');
         }
      }
      catch (Throwable $Throwable) {
         $error = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      $loaded = class_exists($platform, false);
      echo json_encode([
         'error' => $error,
         'defined' => defined($platform),
         'platform' => defined($platform) && constant($platform) instanceof Bootgly\API\Platform,
         'loaded' => $loaded,
         'boots' => $loaded ? $platform::$boots : 0,
         'named' => $loaded && $platform::$named,
         'legacy' => defined('Web') && constant('Web') === 'legacy'
      ]);
      PHP;
      $Run = static function (string $scenario) use ($code): array {
         $Process = proc_open(
            [PHP_BINARY, '-r', $code, '--', $scenario, BOOTGLY_ROOT_DIR . 'autoboot.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $Pipes,
            null,
            ['PATH' => '/usr/bin:/bin', 'LANG' => 'C']
         );
         $output = (string) stream_get_contents($Pipes[1]);
         fclose($Pipes[1]);
         fclose($Pipes[2]);
         proc_close($Process);
         $result = json_decode($output, true);

         // ? A child that died prints no JSON
         return is_array($result) ? $result : ['error' => "unparsable: {$output}"];
      };

      // @@ Each scenario: what it must end with
      $scenarios = [
         // # Included before Bootgly: mounted by `Bootgly::autoboot()`, booted once
         'installed' => ['error' => null, 'defined' => true, 'platform' => true, 'boots' => 1, 'named' => true],
         'console' => ['error' => null, 'defined' => true, 'platform' => true, 'boots' => 1, 'named' => true],
         // # Included after Bootgly: mounted by its own `Bootgly::mount()` call
         'late' => ['error' => null, 'defined' => true, 'platform' => true, 'boots' => 1, 'named' => true],
         // # Not installed: nothing created, the class never loaded
         'absent' => ['error' => null, 'defined' => false, 'loaded' => false],
         // # A release that still creates its own root keeps it (until 2.0.0)
         'legacy' => ['error' => null, 'legacy' => true, 'loaded' => false],
         // # Refusals, by name
         'foreign' => ['error' => 'Exception: The Web platform root does not implement Bootgly\API\Platform.', 'defined' => false],
         'unloadable' => ['error' => 'Exception: The Web platform root class cannot be loaded.', 'defined' => false],
         'unknown' => ['error' => "InvalidArgumentException: Unknown platform 'Mobile'."]
      ];
      foreach ($scenarios as $scenario => $expected) {
         $Result = $Run($scenario);
         $matched = true;
         foreach ($expected as $key => $value) {
            $matched = $matched && ($Result[$key] ?? null) === $value;
         }
         $JSON = json_encode($Result);

         yield assert(
            assertion: $matched,
            description: "{$scenario}: the platform root ends as expected (got {$JSON})"
         );
      }

      // @ Order: `autoboot()` mounts the platforms before CLI routes a command
      $Tokens = token_get_all((string) file_get_contents(BOOTGLY_ROOT_DIR . 'Bootgly.php'));
      $method = null;
      $mount = null;
      $route = null;
      $total = count($Tokens);
      for ($index = 0; $index < $total; $index++) {
         $Token = $Tokens[$index];
         // ? Track which method the token belongs to
         if (is_array($Token) && $Token[0] === T_FUNCTION) {
            $method = $Tokens[$index + 2][1] ?? null;
            continue;
         }
         if ($method !== 'autoboot' || is_array($Token) === false) {
            continue;
         }
         if ($mount === null && $Token[0] === T_STRING && $Token[1] === 'mount') {
            $mount = $index;
         }
         if (
            $route === null && $Token[0] === T_VARIABLE && $Token[1] === '$CLI'
            && ($Tokens[$index + 1][0] ?? null) === T_OBJECT_OPERATOR
            && ($Tokens[$index + 2][1] ?? null) === 'autoboot'
         ) {
            $route = $index;
         }
      }

      yield assert(
         assertion: $mount !== null && $route !== null && $mount < $route,
         description: 'Bootgly::autoboot() mounts the platforms before `$CLI->autoboot()`'
      );
   }
);
