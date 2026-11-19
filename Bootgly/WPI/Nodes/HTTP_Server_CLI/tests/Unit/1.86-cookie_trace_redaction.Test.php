<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Unit;


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
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header\Cookie;


/**
 * A response Cookie receives its value — a session ID, a remember token — in
 * its constructor. With `zend.exception_ignore_args=0` (the development and CI
 * setting) a trace holds every argument of every frame, so that frame must
 * never show the value.
 *
 * Cookie is a value object: no input of the declared types throws from under
 * its constructor. The spec pins the attribute by reflection and throws
 * through the frame itself — a TypeError raised at the marked parameter.
 */
return new Test(
   description: 'Response Cookie: the cookie value stays out of the stack trace',
   test: function () {
      // ! SYNTH cookie value — never a real one
      $value = 'SYNTH-RESPONSE-COOKIE-VALUE-1.86';
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
      // ! The channels a trace shows the value in
      $Find = static function (null|Throwable $Throwable) use ($Channels, $value): array {
         $found = [];
         foreach ($Channels as $channel => $Channel) {
            $output = $Throwable === null ? '' : $Channel($Throwable);

            if (str_contains($output, $value) === true) {
               $found[] = $channel;
            }
         }

         return $found;
      };

      // @ The constructor marks the value
      $Parameter = new ReflectionParameter([Cookie::class, '__construct'], 'value');
      yield assert(
         assertion: $Parameter->getAttributes(SensitiveParameter::class) !== [],
         description: 'Cookie::__construct() marks the value #[SensitiveParameter]'
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

         // @ Positive control: an unmarked frame shows the value in every channel
         $Unmarked = static function (mixed ...$values): never {
            throw new RuntimeException('unmarked');
         };
         $Control = null;
         try {
            $Unmarked([$value], $value);
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $shown = $Find($Control);
         $listed = implode(', ', $shown);
         yield assert(
            assertion: count($shown) === count($Channels),
            description: "An unmarked frame shows the value in each channel — the trace settings took effect (shown: [{$listed}])"
         );

         // @ The throw goes through the marked parameter itself
         $Refused = null;
         try {
            // @phpstan-ignore argument.type (a deliberate TypeError raised at the marked parameter)
            new Cookie('PHPSID', [$value]);
         }
         catch (TypeError $Error) {
            $Refused = $Error;
         }
         $Frame = $Refused?->getTrace()[0] ?? null;
         yield assert(
            assertion: $Frame !== null
               && ($Frame['class'] ?? '') === Cookie::class
               && $Frame['function'] === '__construct'
               && ($Frame['args'][1] ?? null) instanceof SensitiveParameterValue,
            description: 'The refusal comes from under Cookie->__construct(), its value a SensitiveParameterValue'
         );
         $leaked = implode(', ', $Find($Refused));
         yield assert(
            assertion: $leaked === '',
            description: "The value reaches neither print_r(getTrace()), getTraceAsString() nor Dumper (leaked: [{$leaked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
