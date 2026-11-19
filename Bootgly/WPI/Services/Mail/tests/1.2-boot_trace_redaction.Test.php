<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Services\Mail\Tests;


use function array_slice;
use function assert;
use function count;
use function implode;
use function ini_get;
use function ini_set;
use function print_r;
use function str_contains;
use InvalidArgumentException;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Mail as Mailer;
use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Services\Mail;
use Bootgly\WPI\Services\Mail\Messenger;


/**
 * The SMTP configuration carries the password and the OAuth token. It passes
 * through `Mail::boot()` and `Messenger::__construct()` on its way to the ACI
 * mailer and its Config. With `zend.exception_ignore_args=0` (the development
 * and CI setting) a trace holds every argument of every frame, so a refused
 * configuration must never show either credential.
 *
 * Real throws: an out-of-range port, refused by the Config under the whole
 * chain — from `Mail::boot()` and from `new Messenger()`.
 */
return new Test(
   description: 'WPI Mail service: SMTP credentials stay out of the stack trace of a refused boot',
   test: function () {
      // ! SYNTH credentials — never real ones
      $password = 'SYNTH-MAIL-PASSWORD-1.2';
      $token = 'SYNTH-MAIL-TOKEN-1.2';
      // ! A configuration the Config refuses: port 0
      $config = [
         'host' => 'smtp.example.invalid',
         'port' => 0,
         'secure' => 'none',
         'username' => 'visible-user',
         'password' => $password,
         'token' => $token,
      ];
      $secrets = ['password' => $password, 'token' => $token];
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
       * ! What a chain got wrong: an unmarked frame argument, a credential a channel shows
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

      // @ Both WPI frames mark the configuration (attributes are not inherited — each frame is pinned)
      $unmarked = [];
      foreach ([[Mail::class, 'boot'], [Messenger::class, '__construct']] as [$class, $method]) {
         $Parameter = new ReflectionParameter([$class, $method], 'config');
         if ($Parameter->getAttributes(SensitiveParameter::class) === []) {
            $unmarked[] = "{$class}::{$method}() \$config";
         }
      }
      $listed = implode(', ', $unmarked);
      yield assert(
         assertion: $unmarked === [],
         description: "Mail::boot() and Messenger::__construct() mark the configuration #[SensitiveParameter] (unmarked: [{$listed}])"
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

         // @ Positive control: an unmarked frame shows each credential in every channel
         $Unmarked = static function (mixed ...$values): never {
            throw new RuntimeException('unmarked');
         };
         $Control = null;
         try {
            $Unmarked($config, $password, $token);
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $shown = $Check($Control, [], $secrets);
         $listed = implode(', ', $shown);
         yield assert(
            assertion: count($shown) === count($secrets) * count($Channels),
            description: "An unmarked frame shows each credential in each channel — the trace settings took effect (shown: [{$listed}])"
         );

         // @ Mail::boot() → Messenger → ACI Mail → Config: the port is refused under the whole chain
         $Thrown = null;
         try {
            Mail::boot($config);
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }
         $wrong = $Check($Thrown, [
            [Mail::class, 'boot', 0],
            [Messenger::class, '__construct', 0],
            [Mailer::class, '__construct', 0],
            [Config::class, '__construct', 0],
         ], $secrets);
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $Thrown instanceof InvalidArgumentException
               && $Thrown->getMessage() === 'Invalid Mail `port` `0`: expected 1-65535.'
               && $wrong === [],
            description: "A refused Mail::boot() keeps the credentials out of the trace (wrong: [{$listed}])"
         );

         // @ new Messenger() → ACI Mail → Config: the same refusal, without the static sugar
         $Thrown = null;
         try {
            new Messenger($config);
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }
         $wrong = $Check($Thrown, [
            [Messenger::class, '__construct', 0],
            [Mailer::class, '__construct', 0],
            [Config::class, '__construct', 0],
         ], $secrets);
         $listed = implode(', ', $wrong);
         yield assert(
            assertion: $Thrown instanceof InvalidArgumentException
               && $Thrown->getMessage() === 'Invalid Mail `port` `0`: expected 1-65535.'
               && $wrong === [],
            description: "A refused new Messenger() keeps the credentials out of the trace (wrong: [{$listed}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
