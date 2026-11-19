<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail;


use function array_slice;
use function array_values;
use function assert;
use function count;
use function implode;
use function ini_get;
use function ini_set;
use function is_array;
use function print_r;
use function str_contains;
use InvalidArgumentException;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Mail;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * A config the Mail\Config constructor refuses throws from under the two frames
 * that receive the config array — `Mail->__construct()` and
 * `Config->__construct()`. With `zend.exception_ignore_args=0` (the
 * development and CI setting) a trace holds every argument of every frame, so
 * neither may show the array: it carries the password and the token.
 */
return new Test(
   description: 'Mail\Config: a refused config keeps the password and the token out of the stack trace',
   test: function () {
      // ! SYNTH credentials — never real ones
      $secrets = [
         'password' => 'SYNTH-MAIL-PASSWORD-1.3',
         'token' => 'SYNTH-MAIL-TOKEN-1.3'
      ];
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
      // ! The credentials a trace shows, channel by channel
      $Find = static function (null|Throwable $Throwable) use ($Channels, $secrets): array {
         $found = [];
         foreach ($Channels as $channel => $Channel) {
            $output = $Throwable === null ? '' : $Channel($Throwable);

            foreach ($secrets as $name => $secret) {
               if (str_contains($output, $secret) === true) {
                  $found[] = "{$name} via {$channel}";
               }
            }
         }

         return $found;
      };
      // ! The first argument of the first frame that runs $class::$function
      $Argument = static function (null|Throwable $Throwable, string $class, string $function): mixed {
         foreach ($Throwable?->getTrace() ?? [] as $Frame) {
            if (($Frame['class'] ?? '') === $class && $Frame['function'] === $function) {
               return $Frame['args'][0] ?? null;
            }
         }

         return null;
      };
      // ! Whether a frame argument was redacted, and redacted the config with the password
      $Redacted = static function (mixed $Argument) use ($secrets): bool {
         $config = $Argument instanceof SensitiveParameterValue ? $Argument->getValue() : null;

         return is_array($config) === true && ($config['password'] ?? null) === $secrets['password'];
      };

      // @ The constructors mark the config array (attributes are not inherited — each frame is pinned)
      $Marked = static fn (string $class): bool => (new ReflectionParameter([$class, '__construct'], 'config'))
         ->getAttributes(SensitiveParameter::class) !== [];
      yield assert(
         assertion: $Marked(Mail::class) === true && $Marked(Config::class) === true,
         description: 'Mail and Mail\Config constructors mark the config array #[SensitiveParameter]'
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
         $Unmarked = static function (mixed ...$values): never {
            throw new RuntimeException('unmarked');
         };
         $Control = null;
         try {
            $Unmarked($secrets, ...array_values($secrets));
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $shown = $Find($Control);
         $listed = implode(', ', $shown);
         yield assert(
            assertion: count($shown) === count($secrets) * count($Channels),
            description: "An unmarked frame shows each credential in each channel — the trace settings took effect (shown: [{$listed}])"
         );

         // @ The real chain: an invalid port is refused under both constructors
         $Refused = null;
         try {
            new Mail([
               'host' => '127.0.0.1',
               'port' => 0,
               'username' => 'visible-user@example.invalid',
               'password' => $secrets['password'],
               'token' => $secrets['token']
            ]);
         }
         catch (InvalidArgumentException $Exception) {
            $Refused = $Exception;
         }
         yield assert(
            assertion: $Refused?->getMessage() === 'Invalid Mail `port` `0`: expected 1-65535.'
               && $Redacted($Argument($Refused, Config::class, '__construct')) === true
               && $Redacted($Argument($Refused, Mail::class, '__construct')) === true,
            description: 'The refusal comes from under Mail->__construct() and Config->__construct(), each argument a SensitiveParameterValue holding the config'
         );
         $leaked = implode(', ', $Find($Refused));
         yield assert(
            assertion: $leaked === '',
            description: "Neither credential reaches print_r(getTrace()), getTraceAsString() or Dumper (leaked: [{$leaked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
