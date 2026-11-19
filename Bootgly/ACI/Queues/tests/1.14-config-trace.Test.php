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


use const PHP_VERSION_ID;
use function array_slice;
use function assert;
use function count;
use function implode;
use function ini_get;
use function ini_set;
use function is_array;
use function print_r;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;
use ErrorException;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Queues;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * Queues\Config has no throw of its own: what throws under its constructor is a
 * warning the framework escalates (ABI's error handler turns every reported
 * warning into an ErrorException). On PHP 8.5+ casting a float that int cannot
 * hold warns, so a `port` of `1e100` throws from under `Queues->__construct()`
 * and `Config->__construct()` — the frames that receive the config array, and
 * with it the Redis password. Older PHP raises nothing there: the marks are
 * pinned by their attributes.
 */
return new Test(
   description: 'Queues\Config: the config array keeps the Redis password out of the stack trace',
   test: function () {
      // ! SYNTH credential — never a real one
      $password = 'SYNTH-QUEUES-PASSWORD-1.14';
      $Dumper = new Dumper('plain');
      // ! No truncation may hide a leak
      $Dumper->strings = 1_000_000;
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
      // ! The channels a trace shows the password in
      $Find = static function (null|Throwable $Throwable) use ($Channels, $password): array {
         $found = [];
         foreach ($Channels as $channel => $Channel) {
            $output = $Throwable === null ? '' : $Channel($Throwable);

            if (str_contains($output, $password) === true) {
               $found[] = $channel;
            }
         }

         return $found;
      };
      // ! Whether the first argument of the first frame that runs $class::__construct was redacted — and held the password
      $Redacted = static function (null|Throwable $Throwable, string $class) use ($password): bool {
         foreach ($Throwable?->getTrace() ?? [] as $Frame) {
            if (($Frame['class'] ?? '') === $class && $Frame['function'] === '__construct') {
               $Argument = $Frame['args'][0] ?? null;
               $config = $Argument instanceof SensitiveParameterValue ? $Argument->getValue() : null;

               return is_array($config) === true && ($config['password'] ?? null) === $password;
            }
         }

         return false;
      };

      // @ The constructors mark the config array (attributes are not inherited — each frame is pinned)
      $Marked = static fn (string $class): bool => (new ReflectionParameter([$class, '__construct'], 'config'))
         ->getAttributes(SensitiveParameter::class) !== [];
      yield assert(
         assertion: $Marked(Queues::class) === true && $Marked(Config::class) === true,
         description: 'Queues and Queues\Config constructors mark the config array #[SensitiveParameter]'
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

         // @ Positive control: an unmarked frame shows the same values in every channel
         $Unmarked = static function (array $config, string $secret): never {
            throw new RuntimeException('unmarked');
         };
         $Control = null;
         try {
            $Unmarked(['driver' => 'redis', 'password' => $password], $password);
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $shown = $Find($Control);
         $listed = implode(', ', $shown);
         yield assert(
            assertion: count($shown) === count($Channels),
            description: "An unmarked frame shows the password in each channel — the trace settings took effect (shown: [{$listed}])"
         );

         // ? PHP < 8.5 warns about nothing under the constructors
         if (PHP_VERSION_ID < 80500) {
            yield (new Assertion(description: 'PHP < 8.5: nothing throws under the Queues constructors — their marks are pinned by attribute only'))->skip();

            return;
         }

         // @ The real chain: the escalated cast warning throws from under both constructors
         set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $level, $file, $line);
         });
         $Refused = null;
         try {
            new Queues(['driver' => 'redis', 'password' => $password, 'port' => 1e100]);
         }
         catch (ErrorException $Exception) {
            $Refused = $Exception;
         }
         finally {
            restore_error_handler();
         }
         yield assert(
            assertion: $Refused !== null
               && $Redacted($Refused, Config::class) === true
               && $Redacted($Refused, Queues::class) === true,
            description: 'The escalated warning comes from under Queues->__construct() and Config->__construct(), each argument a SensitiveParameterValue holding the config'
         );
         $leaked = implode(', ', $Find($Refused));
         yield assert(
            assertion: $leaked === '',
            description: "The password reaches no print_r(getTrace()), getTraceAsString() or Dumper (leaked via: [{$leaked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
