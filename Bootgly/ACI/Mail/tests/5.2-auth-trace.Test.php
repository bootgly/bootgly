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


use const SIGKILL;
use function array_slice;
use function array_values;
use function assert;
use function base64_encode;
use function count;
use function fclose;
use function fgets;
use function function_exists;
use function fwrite;
use function implode;
use function ini_get;
use function ini_set;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_kill;
use function print_r;
use function str_contains;
use function str_starts_with;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use InvalidArgumentException;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Mail\Exceptions\ConnectionException;
use Bootgly\ACI\Mail\SMTP_Client\Encoder;
use Bootgly\ACI\Mail\SMTP_Client\Mechanisms;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The AUTH credential travels as a string argument: `Mechanisms->encode()`
 * builds the blob, `Encoder->encode()` the command line, `exchange()` writes it
 * and waits for the reply, `write()` puts it on the wire. A server that hangs
 * up at AUTH makes `read()` throw with `exchange()` on the stack; a trace hook
 * that fails makes the throw come from under `write()`. With
 * `zend.exception_ignore_args=0` (the development and CI setting) a trace holds
 * every argument of every frame, so none of them may show the credential.
 */
return new Test(
   description: 'SMTP AUTH: the credential stays out of the stack trace — PLAIN, LOGIN, XOAUTH2 and a failing trace hook',
   skip: function_exists('pcntl_fork') === false || function_exists('posix_kill') === false,
   test: function () {
      // ! SYNTH credentials — never real ones — and what each mechanism puts on the wire
      $username = 'visible-user@example.invalid';
      $password = 'SYNTH-SMTP-PASSWORD-5.2';
      $token = 'SYNTH-SMTP-TOKEN-5.2';
      $plain = base64_encode("\0{$username}\0{$password}");
      $login = base64_encode($password);
      $bearer = base64_encode("user={$username}\x01auth=Bearer {$token}\x01\x01");
      $secrets = [
         'password' => $password,
         'token' => $token,
         'PLAIN blob' => $plain,
         'LOGIN blob' => $login,
         'XOAUTH2 blob' => $bearer
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
      // ! The argument at $index of the first frame that runs $class::$function
      $Argument = static function (null|Throwable $Throwable, string $class, string $function, int $index = 0): mixed {
         foreach ($Throwable?->getTrace() ?? [] as $Frame) {
            if (($Frame['class'] ?? '') === $class && $Frame['function'] === $function) {
               return $Frame['args'][$index] ?? null;
            }
         }

         return null;
      };
      // ! Whether a frame argument was redacted — and held exactly $value
      $Redacted = static fn (mixed $Argument, string $value): bool => $Argument instanceof SensitiveParameterValue
         && $Argument->getValue() === $value;
      // ! A fake SMTP server forked on an ephemeral port: it greets, advertises
      //   $mechanisms, answers 334 to every later line and hangs up at the
      //   $after-th one — read() then throws with the AUTH line on the stack
      $Serve = static function (string $mechanisms, int $after): array {
         $Listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
         if ($Listener === false) {
            return [0, -1];
         }
         $address = (string) stream_socket_get_name($Listener, false);
         $port = (int) substr($address, (int) strrpos($address, ':') + 1);

         $PID = pcntl_fork();
         if ($PID === 0) {
            $Peer = @stream_socket_accept($Listener, 5.0);
            if ($Peer !== false) {
               stream_set_timeout($Peer, 5);
               @fwrite($Peer, "220 fake.invalid ESMTP\r\n");

               $lines = 0;
               // @@ One command line at a time
               while (($line = @fgets($Peer)) !== false) {
                  if (str_starts_with($line, 'EHLO') === true) {
                     @fwrite($Peer, "250-fake.invalid\r\n250 AUTH {$mechanisms}\r\n");
                     continue;
                  }

                  // ? Hang up instead of answering
                  $lines++;
                  if ($lines >= $after) {
                     break;
                  }

                  @fwrite($Peer, "334 VXNlcm5hbWU6\r\n");
               }

               @fclose($Peer);
            }

            // ! Hard exit — no shutdown handlers, no inherited output flush
            posix_kill(posix_getpid(), SIGKILL);
            exit(0);
         }
         @fclose($Listener);

         return [$port, $PID];
      };
      // ! A trace sink that fails at the AUTH line — before its bytes reach the wire
      $Hook = static function (string $direction, string $line): void {
         if (str_starts_with($line, 'AUTH') === true) {
            throw new RuntimeException('trace sink failed');
         }
      };

      // @ Every frame the credential passes through marks it (attributes are not inherited)
      $Marked = static fn (string $class, string $method, string $parameter): bool => (new ReflectionParameter([$class, $method], $parameter))
         ->getAttributes(SensitiveParameter::class) !== [];
      yield assert(
         assertion: $Marked(SMTP_Client::class, 'exchange', 'command') === true
            && $Marked(SMTP_Client::class, 'write', 'bytes') === true
            && $Marked(Encoder::class, 'encode', 'argument') === true
            && $Marked(Mechanisms::class, 'encode', 'secret') === true,
         description: 'exchange(), write(), Encoder->encode() and Mechanisms->encode() mark the credential #[SensitiveParameter] — Mechanisms->encode() cannot throw, its mark is pinned here'
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
         $Unmarked = static function (string ...$values): never {
            throw new RuntimeException('unmarked');
         };
         $Control = null;
         try {
            $Unmarked(...array_values($secrets));
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

         // @@ The real chain, per mechanism: [advertised, hang-up line, credential, marked frame, its argument, thrown]
         $Scenarios = [
            'AUTH PLAIN' => ['PLAIN', 1, ['password' => $password], 'exchange', "AUTH PLAIN {$plain}\r\n", ConnectionException::class],
            'AUTH LOGIN' => ['LOGIN', 3, ['password' => $password], 'exchange', "{$login}\r\n", ConnectionException::class],
            'AUTH XOAUTH2' => ['XOAUTH2', 1, ['token' => $token], 'exchange', "AUTH XOAUTH2 {$bearer}\r\n", ConnectionException::class],
            'a failing trace hook' => ['PLAIN', 9, ['password' => $password, 'trace' => $Hook], 'write', "AUTH PLAIN {$plain}\r\n", RuntimeException::class]
         ];
         foreach ($Scenarios as $scenario => [$mechanisms, $after, $credentials, $frame, $wire, $class]) {
            [$port, $PID] = $Serve($mechanisms, $after);
            yield assert(
               assertion: $PID > 0 && $port > 0,
               description: "The fake SMTP server is up for {$scenario}"
            );

            $Client = new SMTP_Client(new Config([
               'host' => '127.0.0.1',
               'port' => $port,
               'secure' => Config::SECURE_NONE,
               'insecure' => true,
               'domain' => 'client.invalid',
               'username' => $username,
               'timeout' => 2.0,
               'wait' => 2.0
            ] + $credentials));
            $Thrown = null;
            try {
               $Client->connect();
            }
            catch (Throwable $Throwable) {
               $Thrown = $Throwable;
            }
            // ! Reap the fake server (only a child this test forked)
            if ($PID > 0) {
               pcntl_waitpid($PID, $status);
            }

            yield assert(
               assertion: $Thrown instanceof $class
                  && $Redacted($Argument($Thrown, SMTP_Client::class, $frame), $wire) === true
                  && $Redacted($Argument($Thrown, SMTP_Client::class, 'exchange'), $wire) === true,
               description: "{$scenario}: the throw comes from under {$frame}(), its argument a SensitiveParameterValue holding the credential line"
            );
            $leaked = implode(', ', $Find($Thrown));
            yield assert(
               assertion: $leaked === '',
               description: "{$scenario}: no credential reaches print_r(getTrace()), getTraceAsString() or Dumper (leaked: [{$leaked}])"
            );
         }

         // @ The CRLF guard of the command encoder throws with the AUTH argument on its frame
         $Guarded = null;
         try {
            (new Encoder)->encode('AUTH', "PLAIN {$plain}\r\n");
         }
         catch (InvalidArgumentException $Exception) {
            $Guarded = $Exception;
         }
         yield assert(
            assertion: $Guarded !== null
               && $Redacted($Argument($Guarded, Encoder::class, 'encode', 1), "PLAIN {$plain}\r\n") === true,
            description: 'Encoder->encode() refuses CR/LF with its argument a SensitiveParameterValue'
         );
         $leaked = implode(', ', $Find($Guarded));
         yield assert(
            assertion: $leaked === '',
            description: "Encoder->encode(): no credential reaches print_r(getTrace()), getTraceAsString() or Dumper (leaked: [{$leaked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
