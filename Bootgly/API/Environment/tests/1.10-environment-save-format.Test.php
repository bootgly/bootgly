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
use function array_keys;
use function array_values;
use function assert;
use function count;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function json_decode;
use function json_encode;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * M7 (format): `Environment::save()` writes each variable as the bare
 * `KEY=value` line it always wrote, quoted (`KEY="value"`) only when the bare
 * line would not read back — a `;`, edge spaces, a value wrapped in quotes —
 * and verifies that the line reads back identically through `load()`. A
 * variable no line can carry — a CR or LF splits the record (a planted
 * variable), a name INI cannot carry (an exported shell function, `NULL`) —
 * makes `save()` return `false` and leaves the file as it was.
 *
 * `save()` dumps the WHOLE environment, so it runs in a child whose
 * environment is synthetic, and `load()` in another, clean one: the runner's
 * own variables (CI tokens) never reach a fixture file or an assertion message.
 */
return new Test(
   description: 'M7: Environment::save() writes lines load() reads back unchanged — bare unless quotes are needed — and returns false for a variable no line can carry',
   test: function () {
      // ! Fixture
      $dir = Temporaries::reserve('environment-save-format');
      $sentinel = "untouched\n";
      // # One line carries each: bare as written, or quoted for `;`, edge spaces (around a number too:
      //   the bare line reads back `42`, which only a loose comparison takes for ` 42 `) and wrapping quotes
      $values = [
         'M7_DSN' => 'pgsql:host=db;port=5432',
         'M7_PADDED' => '  padded  ',
         'M7_NUMBER' => ' 42 ',
         'M7_QUOTE' => 'pa"ss',
         'M7_HASH' => 'abc#def',
         'M7_EQUALS' => 'a=b=c',
         'M7_DOLLAR' => '${HOME}',
         'M7_EMPTY' => '',
         'M7_WORD' => 'off',
         'M7_BRACKET' => '[x]',
         'M7_WRAPPED' => '"wrapped"',
         'M7_JSON' => '{"a":"b;c"}',
         'M7_EDGE' => ' x" ',
      ];
      // # No line carries these: a break splits the record, INI cannot carry the name — [name, value]
      $refused = [
         'a value holding LF' => ['M7_NOTE', "harmless\nM7_INJECTED=lf"],
         'a value holding CR' => ['M7_NOTE', "harmless\rM7_INJECTED=cr"],
         'a value holding CRLF' => ['M7_NOTE', "harmless\r\nM7_INJECTED=crlf"],
         'an exported shell function (BASH_FUNC_m7%%, a multi-line body)' => ['BASH_FUNC_m7%%', "() {  echo synthetic\n}"],
         'the name NULL (an INI keyword)' => ['NULL', 'x'],
      ];
      // # One variable alone in the environment, and the exact bytes of its dump — [name, value, bytes]
      $bytes = [
         'a plain value is written bare, byte for byte as before' => ['M7_PLAIN', 'abc', "M7_PLAIN=abc\n"],
         'a value holding `;` is quoted' => ['M7_DSN', 'pgsql:host=db;port=5432', "M7_DSN=\"pgsql:host=db;port=5432\"\n"],
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
      // # Export the values (putenv: proc_open() drops an empty one), then save() on each
      //   path [path, name, value, alone]: the name, when given, is set for that call only —
      //   alone, as the one variable of the environment
      $save = <<<'PHP'
      [, $autoboot, $variables, $saves] = $argv;
      require $autoboot;
      error_reporting(E_ALL);
      foreach (json_decode($variables, true) as $name => $value) {
         putenv("{$name}={$value}");
      }
      $results = [];
      foreach (json_decode($saves, true) as [$path, $name, $value, $alone]) {
         $kept = $alone ? getenv() : [];
         foreach ($kept as $key => $ignored) {
            putenv((string) $key);
         }
         if ($name !== null) {
            putenv("{$name}={$value}");
         }
         try {
            $results[] = Bootgly\API\Environment::save($path);
         }
         catch (Throwable $Throwable) {
            $results[] = $Throwable::class;
         }
         if ($name !== null) {
            putenv($name);
         }
         foreach ($kept as $key => $restored) {
            putenv("{$key}={$restored}");
         }
      }
      echo json_encode($results);
      PHP;
      // # load() one file, then read the named variables back
      $load = <<<'PHP'
      [, $autoboot, $file, $names] = $argv;
      require $autoboot;
      error_reporting(E_ALL);
      try {
         $loaded = Bootgly\API\Environment::load($file);
      }
      catch (Throwable $Throwable) {
         $loaded = $Throwable::class;
      }
      $values = [];
      foreach (json_decode($names, true) as $name) {
         $values[$name] = getenv($name);
      }
      echo json_encode([$loaded, $values]);
      PHP;

      try {
         // @ One child saves: every value at once, each refused value on its own, then each
         //   byte-checked variable alone
         $saves = [["{$dir}/format.env", null, null, false]];
         foreach (array_values($refused) as [$name, $value]) {
            $index = count($saves);
            file_put_contents("{$dir}/refused-{$index}.env", $sentinel);
            $saves[] = ["{$dir}/refused-{$index}.env", $name, $value, false];
         }
         foreach (array_values($bytes) as $offset => [$name, $value]) {
            $saves[] = ["{$dir}/bytes-{$offset}.env", $name, $value, true];
         }
         $results = $Run($save, [(string) json_encode($values), (string) json_encode($saves)]);
         // @ A clean child loads the dump back
         $loading = $Run($load, ["{$dir}/format.env", (string) json_encode(array_keys($values))]);
         $loaded = $loading[0] ?? null;
         $read = is_array($loading[1] ?? null) ? $loading[1] : [];

         yield assert(
            assertion: ($results[0] ?? null) === true && $loaded === true,
            description: 'the dump is saved, then loaded by a clean process (save => ' . json_encode($results[0] ?? null) . ', load => ' . json_encode($loaded) . ')'
         );
         foreach ($values as $name => $value) {
            yield assert(
               assertion: ($read[$name] ?? null) === $value,
               description: "{$name} reads back unchanged: " . json_encode($value) . ' (got ' . json_encode($read[$name] ?? null) . ')'
            );
         }

         // @ A variable no line can carry: false, and the destination keeps its bytes
         $index = 1;
         foreach (array_keys($refused) as $label) {
            $result = $results[$index] ?? null;
            yield assert(
               assertion: $result === false && file_get_contents("{$dir}/refused-{$index}.env") === $sentinel,
               description: "{$label} has no one-line form: save() returns false and the file keeps its bytes (got " . json_encode($result) . ')'
            );
            $index++;
         }

         // @ The bytes a plain `KEY=value` reader (`docker --env-file`) gets
         $offset = 0;
         foreach ($bytes as $label => [, , $expected]) {
            $result = $results[$index] ?? null;
            $written = @file_get_contents("{$dir}/bytes-{$offset}.env");
            yield assert(
               assertion: $result === true && $written === $expected,
               description: "{$label}: " . json_encode($expected) . ' (save => ' . json_encode($result) . ', wrote ' . json_encode($written) . ')'
            );
            $index++;
            $offset++;
         }
      }
      finally {
         foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
               @unlink("{$dir}/{$entry}");
            }
         }
         @rmdir($dir);
      }
   }
);
