<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Tests;


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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Authentications\Basic;


/**
 * The Basic credential receives the decoded password in its constructor. With
 * `zend.exception_ignore_args=0` (the development and CI setting) a trace holds
 * every argument of every frame, so that frame must never show the password.
 *
 * Basic is a value object: no input of the declared types throws from under
 * its constructor. The spec pins the attribute by reflection and throws
 * through the frame itself — a TypeError raised at the marked parameter.
 */
return new Test(
   description: 'Request Basic credential: the password stays out of the stack trace',
   test: function () {
      // ! SYNTH credential — never a real one
      $password = 'SYNTH-REQUEST-BASIC-PASSWORD-1.12';
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

      // @ The constructor marks the password
      $Parameter = new ReflectionParameter([Basic::class, '__construct'], 'password');
      yield assert(
         assertion: $Parameter->getAttributes(SensitiveParameter::class) !== [],
         description: 'Basic::__construct() marks the password #[SensitiveParameter]'
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
            $Unmarked([$password], $password);
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

         // @ The throw goes through the marked parameter itself
         $Refused = null;
         try {
            // @phpstan-ignore argument.type (a deliberate TypeError raised at the marked parameter)
            new Basic('visible-user', [$password]);
         }
         catch (TypeError $Error) {
            $Refused = $Error;
         }
         $Frame = $Refused?->getTrace()[0] ?? null;
         yield assert(
            assertion: $Frame !== null
               && ($Frame['class'] ?? '') === Basic::class
               && $Frame['function'] === '__construct'
               && ($Frame['args'][1] ?? null) instanceof SensitiveParameterValue,
            description: 'The refusal comes from under Basic->__construct(), its password a SensitiveParameterValue'
         );
         $leaked = implode(', ', $Find($Refused));
         yield assert(
            assertion: $leaked === '',
            description: "The password reaches neither print_r(getTrace()), getTraceAsString() nor Dumper (leaked: [{$leaked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
