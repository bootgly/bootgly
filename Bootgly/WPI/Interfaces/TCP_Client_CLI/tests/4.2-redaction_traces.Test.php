<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI;


use function array_slice;
use function assert;
use function count;
use function implode;
use function ini_get;
use function ini_set;
use function is_array;
use function print_r;
use function str_contains;
use ArgumentCountError;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * A Configs the client refuses throws from under its constructor, the frame
 * that receives the TLS context. With `zend.exception_ignore_args=0` (the
 * development and CI setting) a trace holds every argument of every frame, so
 * that frame may not show the context: it carries the key's passphrase.
 */
return new Test(
   description: 'TCP_Client_CLI: a refused Configs keeps the TLS passphrase out of the stack trace',
   test: function () {
      // ! SYNTH credentials — never real ones
      $passphrase = 'SYNTH-TCP-CLIENT-PASSPHRASE-4.2';
      $secure = [
         'local_cert' => '/nonexistent/visible-certificate-4.2.pem',
         'passphrase' => $passphrase
      ];
      $Dumper = new Dumper('plain');
      // ! No truncation may hide a leak
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
      // ! The channels a trace shows the passphrase in
      $Find = static function (null|Throwable $Throwable) use ($Channels, $passphrase): array {
         $found = [];
         foreach ($Channels as $channel => $Channel) {
            if ($Throwable !== null && str_contains($Channel($Throwable), $passphrase) === true) {
               $found[] = $channel;
            }
         }

         return $found;
      };

      // @ The constructor marks the context (attributes are not inherited — the frame is pinned)
      $Parameter = new ReflectionParameter([Configs::class, '__construct'], 'secure');
      yield assert(
         assertion: $Parameter->getAttributes(SensitiveParameter::class) !== [],
         description: 'TCP_Client_CLI\Configs->__construct() marks the context #[SensitiveParameter]'
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
            $Unmarked($secure, $passphrase);
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $shown = $Find($Control);
         $listed = implode(', ', $shown);
         yield assert(
            assertion: count($shown) === count($Channels),
            description: "An unmarked frame shows the passphrase in each channel — the trace settings took effect (shown: [{$listed}])"
         );

         // @ The real chain: a Configs without its host is refused under the constructor
         $Refused = null;
         try {
            new Configs(port: 1, secure: $secure);
         }
         catch (ArgumentCountError $Error) {
            $Refused = $Error;
         }
         $Argument = null;
         foreach ($Refused?->getTrace() ?? [] as $Frame) {
            if (($Frame['class'] ?? '') === Configs::class && $Frame['function'] === '__construct') {
               $Argument = $Frame['args'][$Parameter->getPosition()] ?? null;
               break;
            }
         }
         $context = $Argument instanceof SensitiveParameterValue ? $Argument->getValue() : null;
         yield assert(
            assertion: is_array($context) === true && ($context['passphrase'] ?? null) === $passphrase,
            description: 'The refusal comes from under Configs->__construct(), its argument a SensitiveParameterValue holding the context'
         );
         $leaked = implode(', ', $Find($Refused));
         yield assert(
            assertion: $Refused !== null && $leaked === '',
            description: "No channel shows the passphrase (leaked: [{$leaked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
