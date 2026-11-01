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
use function fclose;
use function is_array;
use function json_decode;
use function json_encode;
use function proc_close;
use function proc_open;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * M7 (keys): with a prefix and a suffix, `Environment::del()` composes the
 * name like `get()` and `put()` (ENV-5) — it used to unset the foreign,
 * unprefixed variable and leave the app's own exported to every later child.
 * `put()` and `del()` refuse a name `putenv()` would misread — empty, holding
 * `=` (`del('NAME=value')` SETS it) or NUL (the name is cut there: another
 * variable is unset) — and `put()` a value it would cut at NUL (ENV-9).
 *
 * The calls change the process environment, so they run in a child whose
 * environment is synthetic.
 */
return new Test(
   description: 'M7: Environment::del() composes the prefix and suffix; put() and del() refuse names and values putenv() would misread',
   test: function () {
      // ! Fixture
      $code = <<<'PHP'
      [, $autoboot] = $argv;
      require $autoboot;
      error_reporting(E_ALL);
      $Environment = Bootgly\API\Environment::class;
      $Environment::$prefix = 'APP_';
      $Environment::$suffix = '_V1';
      $Call = static function (Closure $Callback): mixed {
         try {
            return $Callback();
         }
         catch (Throwable $Throwable) {
            return $Throwable::class;
         }
      };
      $Later = static function (): mixed {
         $Process = proc_open([PHP_BINARY, '-r', 'echo json_encode([getenv("APP_TOKEN_V1"), getenv("TOKEN")]);'], [1 => ['pipe', 'w']], $Pipes);
         $output = (string) stream_get_contents($Pipes[1]);
         fclose($Pipes[1]);
         proc_close($Process);
         return json_decode($output, true);
      };
      $Keep = static function (): void {
         putenv('M7_KEEP=kept');
         putenv('APP_M7_KEEP=kept');
      };
      $results = [];
      // @ ENV-5: the name put() wrote is the name del() removes
      $results['put'] = [$Call(fn () => $Environment::put('TOKEN', 'app-token-synthetic')), $Environment::get('TOKEN'), getenv('APP_TOKEN_V1')];
      $results['del'] = [$Call(fn () => $Environment::del('TOKEN')), getenv('APP_TOKEN_V1'), getenv('TOKEN'), $Later()];
      // @ ENV-9: names putenv() would misread, a value it would cut
      $results['del='] = [$Call(fn () => $Environment::del('M7_FOO=bar')), getenv('M7_FOO'), getenv('APP_M7_FOO')];
      $results['put='] = [$Call(fn () => $Environment::put('M7_A=B', 'c')), getenv('M7_A'), getenv('APP_M7_A')];
      $results['put empty'] = [$Call(fn () => $Environment::put('', 'x')), getenv('APP__V1')];
      $results['del empty'] = [$Call(fn () => $Environment::del(''))];
      $Keep();
      $results['put NUL'] = [$Call(fn () => $Environment::put("M7_KEEP\0X", 'v')), getenv('M7_KEEP'), getenv('APP_M7_KEEP')];
      $Keep();
      $results['del NUL'] = [$Call(fn () => $Environment::del("M7_KEEP\0X")), getenv('M7_KEEP'), getenv('APP_M7_KEEP')];
      $results['put NUL value'] = [$Call(fn () => $Environment::put('M7_NUL', "a\0b")), getenv('APP_M7_NUL_V1')];
      // @ ENV-9: the whole composed name is judged — a prefix holding `=` would retarget too
      $Environment::$prefix = 'M7_P=Q';
      $results['put prefix='] = [$Call(fn () => $Environment::put('K', 'v')), getenv('M7_P')];
      putenv('M7_P=kept');
      $results['del prefix='] = [$Call(fn () => $Environment::del('K')), getenv('M7_P')];
      echo json_encode($results);
      PHP;
      $Process = proc_open([PHP_BINARY, '-r', $code, '--', BOOTGLY_ROOT_DIR . 'autoboot.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $Pipes, null, [
         'PATH' => '/usr/bin:/bin',
         'LANG' => 'C',
         'TOKEN' => 'foreign-token-synthetic',
      ]);
      $output = (string) stream_get_contents($Pipes[1]);
      fclose($Pipes[1]);
      fclose($Pipes[2]);
      proc_close($Process);
      $results = json_decode($output, true);
      // ? A child that died prints no JSON: its output surfaces in the first leg
      $results = is_array($results) ? $results : ['put' => ["unparsable: {$output}"]];
      /** One call's results, padded so a missing entry reads as null. */
      $Pick = static function (string $call) use ($results): array {
         $entry = $results[$call] ?? [];
         return (is_array($entry) ? $entry : []) + [null, null, null, null];
      };

      // @ ENV-5: del() composes like get() and put()
      [$put, $got, $written] = $Pick('put');
      yield assert(
         assertion: $put === true && $got === 'app-token-synthetic' && $written === 'app-token-synthetic',
         description: 'control: put() writes APP_TOKEN_V1 and get() reads it back (got ' . json_encode([$put, $got, $written]) . ')'
      );
      [$deleted, $composed, $foreign, $later] = $Pick('del');
      yield assert(
         assertion: $deleted === true && $composed === false,
         description: "del('TOKEN') removes APP_TOKEN_V1, the name put() wrote (got " . json_encode([$deleted, $composed]) . ')'
      );
      yield assert(
         assertion: $foreign === 'foreign-token-synthetic',
         description: "del('TOKEN') keeps the foreign, unprefixed TOKEN (got " . json_encode($foreign) . ')'
      );
      yield assert(
         assertion: $later === [false, 'foreign-token-synthetic'],
         description: 'a later child sees no APP_TOKEN_V1 and still sees TOKEN (got ' . json_encode($later) . ')'
      );

      // @ ENV-9: a name holding `=` sets instead of clearing or retargets
      [$result, $plain, $prefixed] = $Pick('del=');
      yield assert(
         assertion: $result === false && $plain === false && $prefixed === false,
         description: "del('M7_FOO=bar') returns false and sets nothing (got " . json_encode([$result, $plain, $prefixed]) . ')'
      );
      [$result, $plain, $prefixed] = $Pick('put=');
      yield assert(
         assertion: $result === false && $plain === false && $prefixed === false,
         description: "put('M7_A=B', 'c') returns false and sets nothing (got " . json_encode([$result, $plain, $prefixed]) . ')'
      );

      // @ ENV-9: an empty name
      [$result, $prefixed] = $Pick('put empty');
      yield assert(
         assertion: $result === false && $prefixed === false,
         description: "put('', 'x') returns false and sets nothing (got " . json_encode([$result, $prefixed]) . ')'
      );
      [$result] = $Pick('del empty');
      yield assert(
         assertion: $result === false,
         description: "del('') returns false instead of throwing (got " . json_encode($result) . ')'
      );

      // @ ENV-9: a NUL cuts the name — another variable would be unset
      [$result, $plain, $prefixed] = $Pick('put NUL');
      yield assert(
         assertion: $result === false && $plain === 'kept' && $prefixed === 'kept',
         description: 'put() with a NUL in the name returns false and unsets nothing (got ' . json_encode([$result, $plain, $prefixed]) . ')'
      );
      [$result, $plain, $prefixed] = $Pick('del NUL');
      yield assert(
         assertion: $result === false && $plain === 'kept' && $prefixed === 'kept',
         description: 'del() with a NUL in the name returns false and unsets nothing (got ' . json_encode([$result, $plain, $prefixed]) . ')'
      );

      // @ ENV-9: a NUL cuts the value
      [$result, $prefixed] = $Pick('put NUL value');
      yield assert(
         assertion: $result === false && $prefixed === false,
         description: 'put() with a NUL in the value returns false and stores nothing (got ' . json_encode([$result, $prefixed]) . ')'
      );

      // @ ENV-9: a prefix holding `=` is judged with the key
      [$result, $target] = $Pick('put prefix=');
      yield assert(
         assertion: $result === false && $target === false,
         description: "put('K') under the prefix 'M7_P=Q' returns false and sets nothing (got " . json_encode([$result, $target]) . ')'
      );
      [$result, $target] = $Pick('del prefix=');
      yield assert(
         assertion: $result === false && $target === 'kept',
         description: "del('K') under the prefix 'M7_P=Q' returns false and leaves M7_P alone (got " . json_encode([$result, $target]) . ')'
      );
   }
);
