<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment;


use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use function assert;
use function chmod;
use function clearstatcache;
use function fclose;
use function file_put_contents;
use function is_array;
use function is_readable;
use function json_decode;
use function json_encode;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function stream_get_contents;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * M7 (load): `Environment::load()` returns `false` and applies nothing when
 * the file does not parse, cannot be read, is a directory, or holds an array
 * key (`KEY[]=`). It used to throw instead: an ErrorException from the
 * framework's error handler, or a TypeError after the earlier pairs were
 * already exported. Every pair goes through `put()`, so the prefix and suffix
 * compose its name — and a prefix or suffix `put()` refuses (one holding `=`
 * or NUL) makes `load()` return `false` instead of a `true` that applied
 * nothing.
 *
 * `load()` exports every pair into the process environment, so it runs in a
 * child whose environment is synthetic, under the framework's error handler
 * at E_ALL — as a case runs.
 */
return new Test(
   description: 'M7: Environment::load() returns false and applies nothing for a file it cannot read or carry — it never throws',
   test: function () {
      // ! Fixture
      $dir = Temporaries::reserve('environment-load-failure');
      file_put_contents("{$dir}/syntax.env", "[broken\n");
      file_put_contents("{$dir}/unreadable.env", "M7_UNREADABLE=1\n");
      chmod("{$dir}/unreadable.env", 0o000);
      mkdir("{$dir}/adir");
      file_put_contents("{$dir}/array.env", "M7_BEFORE_ARRAY=applied\nM7_ARRAY[]=x\nM7_AFTER_ARRAY=never\n");
      file_put_contents("{$dir}/valid.env", "M7_LOADED=yes\n");
      file_put_contents("{$dir}/nul-value.env", "M7_BEFORE_NUL=applied\nM7_NUL_VALUE=a\0b\nM7_AFTER_NUL=applied\n");
      file_put_contents("{$dir}/nul-key.env", "M7_BEFORE_KEY=applied\nM7_NUL\0KEY=x\nM7_AFTER_KEY=applied\n");
      $names = [
         'M7_UNREADABLE', 'M7_BEFORE_ARRAY', 'M7_AFTER_ARRAY', 'M7_LOADED',
         'M7_BEFORE_NUL', 'M7_AFTER_NUL', 'M7_BEFORE_KEY', 'M7_AFTER_KEY',
      ];
      /** Run PHP code in a child whose environment is only PATH, HOME and LANG. */
      $Run = static function (string $code, array $arguments) use ($dir): array {
         $command = [PHP_BINARY, '-r', $code, '--', BOOTGLY_ROOT_DIR . 'autoboot.php', ...$arguments];
         $Process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $Pipes, $dir, [
            'PATH' => '/usr/bin:/bin',
            'HOME' => $dir,
            'LANG' => 'C',
         ]);
         $output = (string) stream_get_contents($Pipes[1]);
         fclose($Pipes[1]);
         fclose($Pipes[2]);
         proc_close($Process);
         $results = json_decode($output, true);

         return is_array($results) ? $results : ["unparsable: {$output}"];
      };
      // # load() each path [path, prefix, suffix], then read the named variables back: [result, applied]
      $load = <<<'PHP'
      [, $autoboot, $paths, $names] = $argv;
      require $autoboot;
      error_reporting(E_ALL);
      $results = [];
      foreach (json_decode($paths, true) as [$path, $prefix, $suffix]) {
         Bootgly\API\Environment::$prefix = $prefix;
         Bootgly\API\Environment::$suffix = $suffix;
         try {
            $result = Bootgly\API\Environment::load($path);
         }
         catch (Throwable $Throwable) {
            $result = $Throwable::class;
         }
         $applied = [];
         foreach (json_decode($names, true) as $name) {
            $applied[$name] = getenv($name);
         }
         $results[] = [$result, $applied];
      }
      echo json_encode($results);
      PHP;
      /** One call's [result, applied], whatever the child printed. */
      $Pick = static function (mixed $entry): array {
         return is_array($entry) ? $entry + [null, []] : [$entry, []];
      };

      try {
         // @ No prefix, no suffix
         $paths = [
            "{$dir}/missing.env", "{$dir}/nul\0path", "{$dir}/syntax.env", "{$dir}/unreadable.env",
            "{$dir}/adir", "{$dir}/array.env", "{$dir}/valid.env", "{$dir}/nul-value.env",
            "{$dir}/nul-key.env",
         ];
         $plain = [];
         foreach ($paths as $path) {
            $plain[] = [$path, '', ''];
         }
         $results = $Run($load, [(string) json_encode($plain), (string) json_encode($names)]);
         // @ A fresh child: a prefix, then a suffix, put() refuses — then the prefix and suffix put() composes
         $composed = $Run($load, [
            (string) json_encode([["{$dir}/valid.env", 'APP=', ''], ["{$dir}/valid.env", '', "_V1\0"], ["{$dir}/valid.env", 'APP_', '_V1']]),
            (string) json_encode(['APP', 'M7_LOADED', 'M7_LOADED_V1', 'APP_M7_LOADED_V1']),
         ]);
         [$missing] = $Pick($results[0] ?? null);
         [$nul] = $Pick($results[1] ?? null);
         [$syntax] = $Pick($results[2] ?? null);
         [$unreadable, $unread] = $Pick($results[3] ?? null);
         [$directory] = $Pick($results[4] ?? null);
         [$array, $partial] = $Pick($results[5] ?? null);
         [$valid, $loaded] = $Pick($results[6] ?? null);
         [$nulValue, $around] = $Pick($results[7] ?? null);
         [$nulKey, $beside] = $Pick($results[8] ?? null);

         yield assert(
            assertion: $missing === false,
            description: 'a missing file returns false (control; got ' . json_encode($missing) . ')'
         );
         yield assert(
            assertion: $nul === false,
            description: 'a NUL byte in the path returns false instead of throwing (control; got ' . json_encode($nul) . ')'
         );
         yield assert(
            assertion: $syntax === false,
            description: 'a file that does not parse returns false instead of throwing (got ' . json_encode($syntax) . ')'
         );

         // ? A runner that reads a 0000 file (root, CAP_DAC_OVERRIDE) cannot prove the unreadable leg
         clearstatcache();
         if (is_readable("{$dir}/unreadable.env")) {
            yield assert(
               assertion: true,
               description: 'Skipped: this runner reads a 0000 file, so the unreadable leg proves nothing here'
            );
         }
         else {
            yield assert(
               assertion: $unreadable === false && ($unread['M7_UNREADABLE'] ?? null) === false,
               description: 'an unreadable file returns false instead of throwing (got ' . json_encode($unreadable) . ')'
            );
         }

         yield assert(
            assertion: $directory === false,
            description: 'a directory returns false instead of throwing (got ' . json_encode($directory) . ')'
         );
         yield assert(
            assertion: $array === false
               && ($partial['M7_BEFORE_ARRAY'] ?? null) === false
               && ($partial['M7_AFTER_ARRAY'] ?? null) === false,
            description: 'an array key (KEY[]=) returns false with nothing applied (got ' . json_encode($array)
               . ', M7_BEFORE_ARRAY => ' . json_encode($partial['M7_BEFORE_ARRAY'] ?? null) . ')'
         );
         yield assert(
            assertion: $valid === true && ($loaded['M7_LOADED'] ?? null) === 'yes',
            description: 'a valid file returns true and exports its pair as written (control; got '
               . json_encode([$valid, $loaded['M7_LOADED'] ?? null]) . ')'
         );
         yield assert(
            assertion: $nulValue === false
               && ($around['M7_BEFORE_NUL'] ?? null) === false
               && ($around['M7_AFTER_NUL'] ?? null) === false,
            description: 'a NUL inside a value (put() refuses it) returns false with nothing applied (got ' . json_encode($nulValue)
               . ', M7_BEFORE_NUL => ' . json_encode($around['M7_BEFORE_NUL'] ?? null) . ')'
         );
         yield assert(
            assertion: $nulKey === false
               && ($beside['M7_BEFORE_KEY'] ?? null) === false
               && ($beside['M7_AFTER_KEY'] ?? null) === false,
            description: 'a NUL inside a key (the INI parse would stop there) returns false with nothing applied (got '
               . json_encode($nulKey) . ', M7_BEFORE_KEY => ' . json_encode($beside['M7_BEFORE_KEY'] ?? null) . ')'
         );

         // @ The prefix and suffix compose every name load() exports — through put()
         [$refusing, $refused] = $Pick($composed[0] ?? null);
         [$cutting, $cut] = $Pick($composed[1] ?? null);
         [$prefixed, $exported] = $Pick($composed[2] ?? null);
         yield assert(
            assertion: $refusing === false
               && ($refused['APP'] ?? null) === false
               && ($refused['M7_LOADED'] ?? null) === false,
            description: "under the prefix 'APP=' (put() refuses every name) load() returns false with nothing applied (got "
               . json_encode($refusing) . ', APP => ' . json_encode($refused['APP'] ?? null) . ')'
         );
         yield assert(
            assertion: $cutting === false
               && ($cut['M7_LOADED'] ?? null) === false
               && ($cut['M7_LOADED_V1'] ?? null) === false,
            description: 'under a suffix holding NUL (put() refuses every name) load() returns false with nothing applied (got '
               . json_encode($cutting) . ', M7_LOADED_V1 => ' . json_encode($cut['M7_LOADED_V1'] ?? null) . ')'
         );
         yield assert(
            assertion: $prefixed === true
               && ($exported['APP_M7_LOADED_V1'] ?? null) === 'yes'
               && ($exported['M7_LOADED'] ?? null) === false,
            description: "under the prefix 'APP_' and the suffix '_V1', load() exports APP_M7_LOADED_V1, never the bare M7_LOADED (got "
               . json_encode([$prefixed, $exported['APP_M7_LOADED_V1'] ?? null, $exported['M7_LOADED'] ?? null]) . ')'
         );
      }
      finally {
         foreach (['syntax.env', 'unreadable.env', 'array.env', 'valid.env', 'nul-value.env', 'nul-key.env'] as $name) {
            @unlink("{$dir}/{$name}");
         }
         @rmdir("{$dir}/adir");
         @rmdir($dir);
      }
   }
);
