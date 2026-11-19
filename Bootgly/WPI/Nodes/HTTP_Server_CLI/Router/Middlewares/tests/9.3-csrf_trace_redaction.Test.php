<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Tests;


use function array_slice;
use function assert;
use function count;
use function implode;
use function ini_get;
use function ini_set;
use function print_r;
use function str_contains;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;
use TypeError;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CSRF;


/**
 * The CSRF token is a per-session secret: `CSRF::mask()` receives it to render
 * a masked copy, `CSRF::unmask()` receives the submitted value to recover it.
 * With `zend.exception_ignore_args=0` (the development and CI setting) a trace
 * holds every argument of every frame, so neither frame may show the token.
 *
 * No input of the declared types throws from under either method (the hex
 * decoding is silenced, the XOR cannot fail). The spec pins the attributes by
 * reflection and throws through each frame itself — a TypeError raised at
 * the marked parameter.
 */
return new Test(
   description: 'CSRF middleware: the token stays out of the mask() and unmask() stack traces',
   test: function () {
      // ! SYNTH token — never a real one
      $token = 'SYNTH-CSRF-TOKEN-9.3';
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
      // ! The channels a trace shows the token in
      $Find = static function (null|Throwable $Throwable) use ($Channels, $token): array {
         $found = [];
         foreach ($Channels as $channel => $Channel) {
            $output = $Throwable === null ? '' : $Channel($Throwable);

            if (str_contains($output, $token) === true) {
               $found[] = $channel;
            }
         }

         return $found;
      };

      // @ Both methods mark the token they receive
      $unmarked = [];
      foreach (['mask' => 'token', 'unmask' => 'masked'] as $method => $parameter) {
         $Parameter = new ReflectionParameter([CSRF::class, $method], $parameter);
         if ($Parameter->getAttributes(SensitiveParameter::class) === []) {
            $unmarked[] = "CSRF::{$method}() \${$parameter}";
         }
      }
      $listed = implode(', ', $unmarked);
      yield assert(
         assertion: $unmarked === [],
         description: "CSRF::mask() and CSRF::unmask() mark the token #[SensitiveParameter] (unmarked: [{$listed}])"
      );

      // @ Marking changes no behaviour: a masked token still unmasks to itself
      yield assert(
         assertion: CSRF::unmask(CSRF::mask($token)) === $token,
         description: 'unmask(mask(token)) still recovers the token'
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

         // @ Positive control: an unmarked frame shows the token in every channel
         $Unmarked = static function (mixed ...$values): never {
            throw new RuntimeException('unmarked');
         };
         $Control = null;
         try {
            $Unmarked([$token], $token);
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $shown = $Find($Control);
         $listed = implode(', ', $shown);
         yield assert(
            assertion: count($shown) === count($Channels),
            description: "An unmarked frame shows the token in each channel — the trace settings took effect (shown: [{$listed}])"
         );

         // @ mask(): the throw goes through the marked parameter itself
         $Refused = null;
         try {
            // @phpstan-ignore argument.type (a deliberate TypeError raised at the marked parameter)
            CSRF::mask([$token]);
         }
         catch (TypeError $Error) {
            $Refused = $Error;
         }
         $Frame = $Refused?->getTrace()[0] ?? null;
         $leaked = implode(', ', $Find($Refused));
         yield assert(
            assertion: $Frame !== null
               && ($Frame['class'] ?? '') === CSRF::class
               && $Frame['function'] === 'mask'
               && ($Frame['args'][0] ?? null) instanceof SensitiveParameterValue
               && $leaked === '',
            description: "A refusal from under CSRF::mask() keeps the token out of the trace (leaked: [{$leaked}])"
         );

         // @ unmask(): the throw goes through the marked parameter itself
         $Refused = null;
         try {
            // @phpstan-ignore argument.type (a deliberate TypeError raised at the marked parameter)
            CSRF::unmask([$token]);
         }
         catch (TypeError $Error) {
            $Refused = $Error;
         }
         $Frame = $Refused?->getTrace()[0] ?? null;
         $leaked = implode(', ', $Find($Refused));
         yield assert(
            assertion: $Frame !== null
               && ($Frame['class'] ?? '') === CSRF::class
               && $Frame['function'] === 'unmask'
               && ($Frame['args'][0] ?? null) instanceof SensitiveParameterValue
               && $leaked === '',
            description: "A refusal from under CSRF::unmask() keeps the token out of the trace (leaked: [{$leaked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
