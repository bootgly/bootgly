<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use function array_slice;
use function assert;
use function count;
use function implode;
use function ini_get;
use function ini_set;
use function is_array;
use function print_r;
use function str_contains;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;
use ValueError;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI;


/**
 * A TLS context reaches a stack trace through every frame that receives it:
 * the transport and node Configs constructors, and the server's hot swap —
 * `swap()` → `seal()`, then `persist()` and `probe()` for the sealed copy.
 * With `zend.exception_ignore_args=0` (the development and CI setting) a trace
 * holds every argument of every frame, so none of them may show the context:
 * it carries the key's passphrase.
 *
 * `persist()` and `probe()` run only after a real key pair is verified and its
 * private copy is written under the system temporary directory, so no
 * in-process throw reaches them: their marks are pinned by reflection, and the
 * outermost frames that do throw in-process — `swap()` and `seal()` — carry
 * the real chain.
 */
return new Test(
   description: 'TCP_Server_CLI: a refused or unreadable TLS context keeps its passphrase out of the stack trace',
   test: function () {
      // ! SYNTH credentials — never real ones
      $passphrase = 'SYNTH-TCP-SERVER-PASSPHRASE-1.37';
      $secure = [
         'local_cert' => '/nonexistent/visible-certificate-1.37.pem',
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
      // ! Whether every frame that runs $class::$function received the context
      //   as a SensitiveParameterValue — and there is one
      $Redacted = static function (null|Throwable $Throwable, string $class, string $function) use ($passphrase): bool {
         $position = new ReflectionParameter([$class, $function], 'secure')->getPosition();
         $frames = 0;
         foreach ($Throwable?->getTrace() ?? [] as $Frame) {
            if (($Frame['class'] ?? '') !== $class || $Frame['function'] !== $function) {
               continue;
            }

            $frames++;
            $Argument = $Frame['args'][$position] ?? null;
            $context = $Argument instanceof SensitiveParameterValue ? $Argument->getValue() : null;
            if (is_array($context) === false || ($context['passphrase'] ?? null) !== $passphrase) {
               return false;
            }
         }

         return $frames > 0;
      };

      // @ Every frame that receives the context marks it (attributes are not inherited — each frame is pinned)
      $Marks = [
         [Configs::class, '__construct', 'secure'],
         [HTTP_Server_CLI\Configs::class, '__construct', 'secure'],
         [WS_Server_CLI\Configs::class, '__construct', 'secure'],
         [TCP_Server_CLI::class, 'swap', 'secure'],
         [TCP_Server_CLI::class, 'seal', 'secure'],
         [TCP_Server_CLI::class, 'persist', 'contents'],
         [TCP_Server_CLI::class, 'probe', 'secure'],
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
         description: "The Configs constructors, swap(), seal(), persist() and probe() mark the context (or the key bytes) #[SensitiveParameter] (unmarked: [{$missed}])"
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

         // @@ The Configs: a refused recycling option is thrown from under
         //    every constructor that received the context
         $Chains = [
            'TCP_Server_CLI\Configs' => [
               static fn () => new Configs(host: '127.0.0.1', port: 0, workers: 1, secure: $secure, maxRequests: -1),
               [Configs::class]
            ],
            'HTTP_Server_CLI\Configs' => [
               static fn () => new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1, secure: $secure, maxRequests: -1),
               [HTTP_Server_CLI\Configs::class, Configs::class]
            ],
            'WS_Server_CLI\Configs' => [
               static fn () => new WS_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1, secure: $secure, jitter: 2.0),
               [WS_Server_CLI\Configs::class, Configs::class]
            ]
         ];
         foreach ($Chains as $chain => [$Construct, $classes]) {
            $Refused = null;
            try {
               $Construct();
            }
            catch (InvalidArgumentException $Exception) {
               $Refused = $Exception;
            }

            $redacted = $Refused !== null;
            foreach ($classes as $class) {
               $redacted = $redacted && $Redacted($Refused, $class, '__construct');
            }
            $leaked = implode(', ', $Find($Refused));
            yield assert(
               assertion: $redacted === true && $leaked === '',
               description: "{$chain}: the refusal comes from under every constructor that received the context, each argument a SensitiveParameterValue holding it, and no channel shows the passphrase (leaked: [{$leaked}])"
            );
         }

         // @ The hot swap: a certificate path the filesystem refuses (a NUL
         //   byte) is thrown from under seal(), called by swap() — the server
         //   is built without its constructor, which boots the process
         $Server = new ReflectionClass(TCP_Server_CLI::class)->newInstanceWithoutConstructor();
         $Unreadable = null;
         try {
            $Server->swap([
               'local_cert' => "/nonexistent/certificate\0.pem",
               'local_pk' => '/nonexistent/private-key.pem',
               'passphrase' => $passphrase
            ]);
         }
         catch (ValueError $Error) {
            $Unreadable = $Error;
         }
         $leaked = implode(', ', $Find($Unreadable));
         yield assert(
            assertion: $Redacted($Unreadable, TCP_Server_CLI::class, 'swap') === true
               && $Redacted($Unreadable, TCP_Server_CLI::class, 'seal') === true
               && $leaked === '',
            description: "swap() -> seal(): the refused certificate path throws from under both frames, each argument a SensitiveParameterValue holding the context, and no channel shows the passphrase (leaked: [{$leaked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
