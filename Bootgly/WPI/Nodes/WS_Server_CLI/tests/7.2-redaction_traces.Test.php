<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function array_slice;
use function assert;
use function count;
use function fclose;
use function implode;
use function ini_get;
use function ini_set;
use function is_array;
use function is_resource;
use function print_r;
use function str_contains;
use function stream_socket_pair;
use function strlen;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Guard;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders\Decoder_;


/**
 * The handshake guards, the upgrade predicate and the HTTP fallback all run
 * under the frames that received the upgrade request: the decoder's
 * `decode()` (the raw head) and the handshake's `authenticate()` and
 * `permit()` (its header map). A guard or a predicate that throws — a
 * failing token lookup, a broken allowlist — is reported with its trace, and
 * with `zend.exception_ignore_args=0` (the development and CI setting) a trace
 * holds every argument of every frame: none of them may show the
 * `Authorization` or the `Cookie` header.
 */
return new Test(
   description: 'WS_Server_CLI handshake: a throwing guard or predicate keeps the credential headers out of the stack trace',
   test: function () {
      // ! SYNTH credentials — never real ones
      $bearer = 'SYNTH-WS-SERVER-BEARER-7.2';
      $session = 'SYNTH-WS-SERVER-SESSION-7.2';
      $secrets = [$bearer, $session];
      $fields = [
         'host' => 'ws.visible-host-7.2.invalid',
         'authorization' => "Bearer {$bearer}",
         'cookie' => "sid={$session}"
      ];
      $head = "GET /chat HTTP/1.1\r\n"
         . "Host: ws.visible-host-7.2.invalid\r\n"
         . "Upgrade: websocket\r\n"
         . "Connection: Upgrade\r\n"
         . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
         . "Sec-WebSocket-Version: 13\r\n"
         . "Authorization: Bearer {$bearer}\r\n"
         . "Cookie: sid={$session}\r\n"
         . "\r\n";
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
      // ! Whether a frame redacted the header map that carries the Authorization header
      $Map = static function (mixed $Argument) use ($bearer): bool {
         $map = $Argument instanceof SensitiveParameterValue ? $Argument->getValue() : null;

         return is_array($map) === true && ($map['authorization'] ?? null) === "Bearer {$bearer}";
      };
      // ! Whether a frame redacted the raw head
      $Head = static fn (mixed $Argument): bool => $Argument instanceof SensitiveParameterValue
         && $Argument->getValue() === $head;
      // ! A guard whose credential lookup fails
      $Guard = new class extends Guard {
         public function authenticate (object $Request): bool
         {
            throw new RuntimeException('Guard lookup failed.');
         }

         public function challenge (object $Response): object
         {
            return $Response;
         }
      };
      // ! A predicate whose allowlist fails
      $Predicate = static function (object $Request): bool {
         throw new RuntimeException('Predicate failed.');
      };

      // @ Every frame that receives the request marks it (attributes are not inherited — each frame is pinned)
      $Marks = [
         [Handshake::class, 'authenticate', 'fields'],
         [Handshake::class, 'permit', 'fields'],
         [Decoder_::class, 'decode', 'buffer'],
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
         description: "Handshake::authenticate(), Handshake::permit() and Decoder_->decode() mark the request #[SensitiveParameter] (unmarked: [{$missed}])"
      );

      // ! Traces with arguments, at full length — the development and CI setting
      $ignore = ini_get('zend.exception_ignore_args');
      $length = ini_get('zend.exception_string_param_max_len');
      $ignoring = ini_set('zend.exception_ignore_args', '0');
      $lengthening = ini_set('zend.exception_string_param_max_len', '1000000');
      // ! The handshake policy and the transport knobs this case changes
      $OldGuards = Handshake::$Guards;
      $OldPredicate = Handshake::$predicate;
      $OldFallback = Handshake::$fallback;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $Pairs = [];
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
            $Unmarked($fields, $head, ...$secrets);
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

         // @ authenticate(): a guard that throws is reported from under the header map
         $Failed = null;
         try {
            Handshake::authenticate($fields, [$Guard]);
         }
         catch (RuntimeException $Exception) {
            $Failed = $Exception;
         }
         $leaked = implode(', ', $Find($Failed));
         yield assert(
            assertion: $Map($Argument($Failed, Handshake::class, 'authenticate', 'fields')) === true
               && $leaked === '',
            description: "Handshake::authenticate(): a throwing guard leaves the header map a SensitiveParameterValue, and no channel shows a credential (leaked: [{$leaked}])"
         );

         // @ permit(): a predicate that throws is reported from under the header map
         Handshake::$predicate = $Predicate;
         $Failed = null;
         try {
            Handshake::permit($fields);
         }
         catch (RuntimeException $Exception) {
            $Failed = $Exception;
         }
         $leaked = implode(', ', $Find($Failed));
         yield assert(
            assertion: $Map($Argument($Failed, Handshake::class, 'permit', 'fields')) === true
               && $leaked === '',
            description: "Handshake::permit(): a throwing predicate leaves the header map a SensitiveParameterValue, and no channel shows a credential (leaked: [{$leaked}])"
         );

         // @ decode(): the raw head reaches a throwing guard, then a throwing
         //   predicate, through the real upgrade path
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         TCP_Server_CLI::$context = [];
         Handshake::$fallback = null;
         $Paths = [
            'guard' => [[$Guard], null, 'authenticate'],
            'predicate' => [[], $Predicate, 'permit']
         ];
         foreach ($Paths as $path => [$Guards, $Permit, $function]) {
            Handshake::$Guards = $Guards;
            Handshake::$predicate = $Permit;
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('could not create a socket pair');
            }
            $Pairs[] = $Pair;
            $Connection = new Connection($Pair[0], '127.0.0.1', 47200 + count($Pairs));

            $Failed = null;
            try {
               new Decoder_()->decode($Connection, $head, strlen($head));
            }
            catch (RuntimeException $Exception) {
               $Failed = $Exception;
            }
            $leaked = implode(', ', $Find($Failed));
            yield assert(
               assertion: $Head($Argument($Failed, Decoder_::class, 'decode', 'buffer')) === true
                  && $Map($Argument($Failed, Handshake::class, $function, 'fields')) === true
                  && $leaked === '',
               description: "Decoder_->decode() -> Handshake::{$function}(): a throwing {$path} leaves the raw head and the header map SensitiveParameterValues, and no channel shows a credential (leaked: [{$leaked}])"
            );
         }
      }
      finally {
         Handshake::$Guards = $OldGuards;
         Handshake::$predicate = $OldPredicate;
         Handshake::$fallback = $OldFallback;
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         foreach ($Pairs as $Pair) {
            foreach ($Pair as $Stream) {
               if (is_resource($Stream) === true) {
                  fclose($Stream);
               }
            }
         }
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
