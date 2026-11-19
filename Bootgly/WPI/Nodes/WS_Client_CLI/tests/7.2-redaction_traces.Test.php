<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Client_CLI;


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
use Closure;
use InvalidArgumentException;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI;


/**
 * The extra headers given to `connect()` or `open()` reach the frame that
 * encodes the upgrade request, `build()`, which refuses an unsafe URI, header
 * or subprotocol before any dial; the TLS context reaches the Configs
 * constructors, which refuse a Configs without its host. With
 * `zend.exception_ignore_args=0` (the development and CI setting) a trace
 * holds every argument of every frame, so none of those frames may show the
 * `Authorization` or `Cookie` header, nor the key's passphrase.
 */
return new Test(
   description: 'WS_Client_CLI: a refused upgrade request or Configs keeps the credentials out of the stack trace',
   test: function () {
      // ! SYNTH credentials — never real ones
      $passphrase = 'SYNTH-WS-CLIENT-PASSPHRASE-7.2';
      $bearer = 'SYNTH-WS-CLIENT-BEARER-7.2';
      $session = 'SYNTH-WS-CLIENT-SESSION-7.2';
      $secrets = [$passphrase, $bearer, $session];
      $secure = [
         'local_cert' => '/nonexistent/visible-certificate-7.2.pem',
         'passphrase' => $passphrase
      ];
      $headers = [
         'Authorization' => "Bearer {$bearer}",
         'Cookie' => "sid={$session}"
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
      // ! Whether a frame redacted the headers given to connect()/open()
      $Headers = static fn (mixed $Argument): bool => $Argument instanceof SensitiveParameterValue
         && $Argument->getValue() === $headers;
      // ! Whether a frame redacted the TLS context
      $Context = static function (mixed $Argument) use ($passphrase): bool {
         $context = $Argument instanceof SensitiveParameterValue ? $Argument->getValue() : null;

         return is_array($context) === true && ($context['passphrase'] ?? null) === $passphrase;
      };

      // @ Every frame that receives the credentials marks them (attributes are not inherited — each frame is pinned)
      $Marks = [
         [WS_Client_CLI::class, 'connect', 'headers'],
         [WS_Client_CLI::class, 'open', 'headers'],
         [WS_Client_CLI::class, 'build', 'headers'],
         [Configs::class, '__construct', 'secure'],
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
         description: "connect(), open(), build() and the Configs constructor mark the credentials #[SensitiveParameter] (unmarked: [{$missed}])"
      );

      // ! Traces with arguments, at full length — the development and CI setting
      $ignore = ini_get('zend.exception_ignore_args');
      $length = ini_get('zend.exception_string_param_max_len');
      $ignoring = ini_set('zend.exception_ignore_args', '0');
      $lengthening = ini_set('zend.exception_string_param_max_len', '1000000');
      // ! open() pins the shared-loop leader before it dials: snapshot the concurrency latch
      $Latch = Closure::bind(static fn (): array => [
         WS_Client_CLI::$multi,
         WS_Client_CLI::$open,
         WS_Client_CLI::$Leader
      ], null, WS_Client_CLI::class);
      $latch = $Latch();
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
            $Unmarked($secure, $headers, ...$secrets);
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

         // @@ connect() and open(): an unsafe URI is refused by build() before the dial
         foreach (['connect', 'open'] as $method) {
            $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
            $Client->configure(new Configs(host: '127.0.0.1', port: 9));

            $Refused = null;
            try {
               $Client->{$method}('/a b', $headers);
            }
            catch (InvalidArgumentException $Exception) {
               $Refused = $Exception;
            }
            $leaked = implode(', ', $Find($Refused));
            yield assert(
               assertion: $Headers($Argument($Refused, WS_Client_CLI::class, 'build', 'headers')) === true
                  && $Headers($Argument($Refused, WS_Client_CLI::class, $method, 'headers')) === true
                  && $leaked === '',
               description: "{$method}() -> build(): the refused URI throws from under both frames, each headers argument a SensitiveParameterValue, and no channel shows a credential (leaked: [{$leaked}])"
            );
         }

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
            description: "Configs->__construct(): the refusal comes from under the WebSocket and the TCP constructors, each context a SensitiveParameterValue, and no channel shows the passphrase (leaked: [{$leaked}])"
         );
      }
      finally {
         Closure::bind(static function () use ($latch): void {
            [WS_Client_CLI::$multi, WS_Client_CLI::$open, WS_Client_CLI::$Leader] = $latch;
         }, null, WS_Client_CLI::class)();
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
