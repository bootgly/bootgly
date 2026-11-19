<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI;


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
use InvalidArgumentException;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


/**
 * The raw header block reaches the last textual-wire boundary, the encoder,
 * which refuses an unsafe request line set past `Request->__invoke()`; the
 * header fields reach the HTTP/2 Session, whose `open()` refuses the same; the
 * TLS context reaches the Configs constructors, which refuse a Configs without
 * its host. With `zend.exception_ignore_args=0` (the development and CI
 * setting) a trace holds every argument of every frame, so none of them may
 * show the `Authorization` or `Cookie` line, nor the key's passphrase.
 *
 * `test()` forks the mock origin and `testing()` drives the running suite's
 * end-to-end harness, so no in-process throw reaches them here: their marks
 * are pinned by reflection, and the Configs constructor they hand the context
 * to carries the real chain.
 */
return new Test(
   description: 'HTTP_Client_CLI: a refused request line or Configs keeps the credentials out of the stack trace',
   test: function () {
      // ! SYNTH credentials — never real ones
      $passphrase = 'SYNTH-HTTP-CLIENT-PASSPHRASE-12.2';
      $bearer = 'SYNTH-HTTP-CLIENT-BEARER-12.2';
      $session = 'SYNTH-HTTP-CLIENT-SESSION-12.2';
      $secrets = [$passphrase, $bearer, $session];
      $secure = [
         'local_cert' => '/nonexistent/visible-certificate-12.2.pem',
         'passphrase' => $passphrase
      ];
      $headerRaw = "Authorization: Bearer {$bearer}\r\nCookie: sid={$session}\r\nAccept: text/plain\r\n";
      $fields = [
         'Authorization' => "Bearer {$bearer}",
         'Cookie' => "sid={$session}",
         'Accept' => 'text/plain'
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
      // ! The credentials a trace shows, channel by channel
      $Find = static function (null|Throwable $Throwable) use ($Channels, $secrets): array {
         $found = [];
         foreach ($Channels as $channel => $Channel) {
            $output = $Throwable === null ? '' : $Channel($Throwable);

            foreach ($secrets as $secret) {
               if (str_contains($output, $secret) === true) {
                  $found[] = "{$secret} via {$channel}";
               }
            }
         }

         return $found;
      };
      // ! The argument a frame running $class::$function received as $parameter
      $Argument = static function (null|Throwable $Throwable, string $class, string $function, string $parameter): mixed {
         $position = new ReflectionParameter([$class, $function], $parameter)->getPosition();
         foreach ($Throwable?->getTrace() ?? [] as $Frame) {
            if (($Frame['class'] ?? '') === $class && $Frame['function'] === $function) {
               return $Frame['args'][$position] ?? null;
            }
         }

         return null;
      };
      // ! Whether a frame redacted the TLS context
      $Context = static function (mixed $Argument) use ($passphrase): bool {
         $context = $Argument instanceof SensitiveParameterValue ? $Argument->getValue() : null;

         return is_array($context) === true && ($context['passphrase'] ?? null) === $passphrase;
      };

      // @ Every frame that receives the credentials marks them (attributes are not inherited — each frame is pinned)
      $Marks = [
         [Request\Encoders\Encoder_::class, 'encode', 'headerRaw'],
         [Session::class, 'open', 'fields'],
         [Configs::class, '__construct', 'secure'],
         [HTTP_Client_CLI::class, 'test', 'secure'],
         [HTTP_Client_CLI::class, 'testing', 'secure'],
      ];
      $unmarked = [];
      foreach ($Marks as [$class, $method, $parameter]) {
         $Parameter = new ReflectionParameter([$class, $method], $parameter);
         if ($Parameter->getAttributes(SensitiveParameter::class) === []) {
            $unmarked[] = "{$class}::{$method}(\${$parameter})";
         }
      }
      $missed = implode(', ', $unmarked);
      yield assert(
         assertion: $unmarked === [],
         description: "Encoder_::encode(), Session->open(), the Configs constructor, test() and testing() mark the credentials #[SensitiveParameter] (unmarked: [{$missed}])"
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
            $Unmarked($secure, $headerRaw, ...$secrets);
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

         // @ The encoder: an unsafe request line is refused with the header block in hand
         $Refused = null;
         try {
            Request\Encoders\Encoder_::encode('GET', '/a b', 'HTTP/1.1', $headerRaw);
         }
         catch (InvalidArgumentException $Exception) {
            $Refused = $Exception;
         }
         $Block = $Argument($Refused, Request\Encoders\Encoder_::class, 'encode', 'headerRaw');
         $leaked = implode(', ', $Find($Refused));
         yield assert(
            assertion: $Block instanceof SensitiveParameterValue
               && $Block->getValue() === $headerRaw
               && $leaked === '',
            description: "Encoder_::encode(): the refused request line throws with the header block a SensitiveParameterValue, and no channel shows a credential (leaked: [{$leaked}])"
         );

         // @ The HTTP/2 Session: an unsafe request line is refused with the header fields in hand
         $Refused = null;
         try {
            new Session()->open('GET', 'https', 'example.invalid', '/a b', $fields);
         }
         catch (InvalidArgumentException $Exception) {
            $Refused = $Exception;
         }
         $Fields = $Argument($Refused, Session::class, 'open', 'fields');
         $leaked = implode(', ', $Find($Refused));
         yield assert(
            assertion: $Fields instanceof SensitiveParameterValue
               && $Fields->getValue() === $fields
               && $leaked === '',
            description: "Session->open(): the refused request line throws with the header fields a SensitiveParameterValue, and no channel shows a credential (leaked: [{$leaked}])"
         );

         // @ The Configs: a Configs without its host is refused under both constructors
         $Refused = null;
         try {
            new Configs(port: 1, secure: $secure);
         }
         catch (ArgumentCountError $Error) {
            $Refused = $Error;
         }
         $leaked = implode(', ', $Find($Refused));
         yield assert(
            assertion: $Context($Argument($Refused, Configs::class, '__construct', 'secure')) === true
               && $Context($Argument($Refused, TCP_Client_CLI\Configs::class, '__construct', 'secure')) === true
               && $leaked === '',
            description: "Configs->__construct(): the refusal comes from under the HTTP and the TCP constructors, each context a SensitiveParameterValue, and no channel shows the passphrase (leaked: [{$leaked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
