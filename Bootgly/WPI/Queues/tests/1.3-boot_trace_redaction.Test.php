<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Queues\Tests;


use const NAN;
use const PHP_VERSION_ID;
use function array_slice;
use function assert;
use function count;
use function implode;
use function ini_get;
use function ini_set;
use function print_r;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;
use ArrayObject;
use ErrorException;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;
use TypeError;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Queues as Manager;
use Bootgly\ACI\Queues\Config;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Queues;
use Bootgly\WPI\Queues\Messenger;


/**
 * The queue store configuration carries the Redis password. It passes through
 * `Queues::boot()` and `Messenger::__construct()` on its way to the ACI queue
 * manager and its Config. With `zend.exception_ignore_args=0` (the development
 * and CI setting) a trace holds every argument of every frame, so no frame of
 * that chain may show the password.
 *
 * The Config refuses nothing: it casts every option. Both WPI frames are pinned
 * by reflection and by a TypeError raised at each marked parameter. The real
 * chain throws on PHP 8.5, which warns when the Config casts a NAN port to an
 * int: escalated, as the Bootgly error handler does, the warning throws under
 * every frame. PHP 8.4 casts it silently, so there the spec asserts that
 * nothing throws.
 */
return new Test(
   description: 'WPI Queues: the queue store password stays out of every stack trace of a boot',
   test: function () {
      // ! SYNTH credential — never a real one
      $password = 'SYNTH-QUEUES-PASSWORD-1.3';
      $secrets = ['password' => $password];
      // ! A Redis store whose port the Config casts from NAN
      $config = [
         'driver' => 'redis',
         'host' => 'queues.example.invalid',
         'port' => NAN,
         'password' => $password,
      ];
      $Dumper = new Dumper('plain');
      // ! No limit may hide a leak
      $Dumper->depth = 32;
      $Dumper->strings = 1_000_000;
      $Dumper->items = 10_000;
      // ! The trace's own frames: every frame up to the last call made from this file — the
      //   runner's frames below it hold the suite state, which a full run makes unbounded
      $Frames = static function (Throwable $Throwable): array {
         $Trace = $Throwable->getTrace();
         $last = 0;
         foreach ($Trace as $index => $Frame) {
            if (($Frame['file'] ?? '') === __FILE__) {
               $last = $index;
            }
         }

         return array_slice($Trace, 0, $last + 1);
      };
      // ! Each channel a trace reaches
      $Channels = [
         'print_r' => static fn (Throwable $Throwable): string => print_r($Frames($Throwable), true),
         'getTraceAsString' => static fn (Throwable $Throwable): string => $Throwable->getTraceAsString(),
         'Dumper' => static fn (Throwable $Throwable): string => $Dumper->dump($Frames($Throwable)),
      ];
      /**
       * ! What a chain got wrong: an unmarked frame argument, a secret a channel shows
       *
       * @param array<int,array{0:class-string,1:string,2:int}> $Marked
       * @param array<string,string> $secrets
       *
       * @return array<int,string>
       */
      $Check = static function (null|Throwable $Thrown, array $Marked, array $secrets) use ($Channels): array {
         // ?
         if ($Thrown === null) {
            return ['nothing was thrown'];
         }

         $wrong = [];
         // @@ Each frame the configuration passes through
         foreach ($Marked as [$class, $function, $position]) {
            $redacted = false;
            foreach ($Thrown->getTrace() as $Frame) {
               if (($Frame['class'] ?? '') === $class && $Frame['function'] === $function) {
                  $redacted = ($Frame['args'][$position] ?? null) instanceof SensitiveParameterValue;
                  break;
               }
            }
            if ($redacted === false) {
               $wrong[] = "{$class}::{$function}() argument #{$position} is not a SensitiveParameterValue";
            }
         }
         // @@ Each channel
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Thrown);

            foreach ($secrets as $name => $secret) {
               if (str_contains($output, $secret) === true) {
                  $wrong[] = "the {$name} via {$channel}";
               }
            }
         }

         return $wrong;
      };
      // ! The warning escalated into an exception, as the Bootgly error handler does
      $Escalate = static function (int $level, string $message): never {
         throw new ErrorException($message, 0, $level);
      };

      // @ Both WPI frames mark the configuration (attributes are not inherited — each frame is pinned)
      $unmarked = [];
      foreach ([[Queues::class, 'boot'], [Messenger::class, '__construct']] as [$class, $method]) {
         $Parameter = new ReflectionParameter([$class, $method], 'config');
         if ($Parameter->getAttributes(SensitiveParameter::class) === []) {
            $unmarked[] = "{$class}::{$method}() \$config";
         }
      }
      $listed = implode(', ', $unmarked);
      yield assert(
         assertion: $unmarked === [],
         description: "Queues::boot() and Messenger::__construct() mark the configuration #[SensitiveParameter] (unmarked: [{$listed}])"
      );

      // ! Traces with arguments, at full length — the development and CI setting
      $ignore = ini_get('zend.exception_ignore_args');
      $length = ini_get('zend.exception_string_param_max_len');
      $ignoring = ini_set('zend.exception_ignore_args', '0');
      $lengthening = ini_set('zend.exception_string_param_max_len', '1000000');
      try {
         yield assert(
            assertion: $ignoring !== false && $lengthening !== false,
            description: 'The run takes traces with arguments at full length'
         );

         // @ Positive control: an unmarked frame shows the password in every channel
         $Unmarked = static function (mixed ...$values): never {
            throw new RuntimeException('unmarked');
         };
         $Control = null;
         try {
            $Unmarked($config, $password);
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $shown = $Check($Control, [], $secrets);
         $listed = implode(', ', $shown);
         yield assert(
            assertion: count($shown) === count($Channels),
            description: "An unmarked frame shows the password in each channel — the trace settings took effect (shown: [{$listed}])"
         );

         // @ Queues::boot(): the throw goes through the marked parameter itself
         $Refused = null;
         try {
            // @phpstan-ignore argument.type (a deliberate TypeError raised at the marked parameter)
            Queues::boot(new ArrayObject($config));
         }
         catch (TypeError $Error) {
            $Refused = $Error;
         }
         $wrong = $Check($Refused, [[Queues::class, 'boot', 0]], $secrets);
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $wrong === [],
            description: "A refusal from under Queues::boot() keeps the password out of the trace (wrong: [{$listed}])"
         );

         // @ Messenger::__construct(): the throw goes through the marked parameter itself
         $Refused = null;
         try {
            // @phpstan-ignore argument.type (a deliberate TypeError raised at the marked parameter)
            new Messenger(new ArrayObject($config));
         }
         catch (TypeError $Error) {
            $Refused = $Error;
         }
         $wrong = $Check($Refused, [[Messenger::class, '__construct', 0]], $secrets);
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $wrong === [],
            description: "A refusal from under Messenger::__construct() keeps the password out of the trace (wrong: [{$listed}])"
         );

         // @ new Messenger() → ACI Queues → Config: the NAN port cast, its warning escalated
         $Thrown = null;
         set_error_handler($Escalate);
         try {
            new Messenger($config);
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }
         finally {
            restore_error_handler();
         }

         if (PHP_VERSION_ID < 80500) {
            // ?: PHP 8.4 casts a NAN silently: no input throws under the chain there
            yield assert(
               assertion: $Thrown === null,
               description: 'PHP 8.4 casts a NAN port silently: nothing throws under the chain, the TypeErrors pin both WPI frames'
            );
         }
         else {
            $wrong = $Check($Thrown, [
               [Messenger::class, '__construct', 0],
               [Manager::class, '__construct', 0],
               [Config::class, '__construct', 0],
            ], $secrets);
            $listed = implode(', ', $wrong);
            yield assert(
               assertion: $Thrown instanceof ErrorException
                  && str_contains($Thrown->getMessage(), 'not representable as an int')
                  && $wrong === [],
               description: "A refused new Messenger() keeps the password out of the trace (wrong: [{$listed}])"
            );

            // ? The facade only once the chain throws: a boot that succeeds would replace the shared messenger
            if ($Thrown !== null) {
               // @ Queues::boot() → Messenger → ACI Queues → Config: the same refusal under the static facade
               $Thrown = null;
               set_error_handler($Escalate);
               try {
                  Queues::boot($config);
               }
               catch (Throwable $Throwable) {
                  $Thrown = $Throwable;
               }
               finally {
                  restore_error_handler();
               }
               $wrong = $Check($Thrown, [
                  [Queues::class, 'boot', 0],
                  [Messenger::class, '__construct', 0],
                  [Manager::class, '__construct', 0],
                  [Config::class, '__construct', 0],
               ], $secrets);
               $listed = implode(', ', $wrong);
               yield assert(
                  assertion: $Thrown instanceof ErrorException && $wrong === [],
                  description: "A refused Queues::boot() keeps the password out of the trace (wrong: [{$listed}])"
               );
            }
         }
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
