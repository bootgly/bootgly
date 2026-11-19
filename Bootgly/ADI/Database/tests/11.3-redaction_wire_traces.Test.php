<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database\Tests\RedactionWireTraces;


use function array_slice;
use function assert;
use function implode;
use function ini_get;
use function ini_set;
use function print_r;
use function str_contains;
use InvalidArgumentException;
use ReflectionParameter;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;
use TypeError;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Authentication as MySQLAuthentication;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Encoder as MySQLEncoder;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder as PostgreSQLEncoder;


/**
 * The frames a password, the response derived from it or the PostgreSQL
 * cancellation key passes through on its way to the wire keep it out of a
 * stack trace. The encoders' own refusals are real throws under those frames;
 * the frames that never throw on their own — MySQL `Encoder::frame()` and
 * `Authentication::mix()`, PostgreSQL `identify()` — are driven by the
 * `TypeError` PHP raises inside a frame for a wrong argument, and read by
 * reflection too.
 *
 * Each call is made inline: a frame that took a closure as an argument would
 * show the secret the closure captured, in `print_r`.
 */
return new Test(
   description: 'Database: the wire frames keep the password, its responses and the cancellation key out of exception traces',
   test: function () {
      $secret = 'SYNTH-TRACE-WIRE-SECRET';
      $Dumper = new Dumper('plain');
      // ! The channels of a trace that show the secret
      $channels = static function (Throwable $Throwable) use ($secret, $Dumper): array {
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
         $shown = [];
         if (str_contains(print_r($Frames($Throwable), true), $secret) === true) {
            $shown[] = 'print_r';
         }
         if (str_contains($Throwable->getTraceAsString(), $secret) === true) {
            $shown[] = 'getTraceAsString';
         }
         if (str_contains($Dumper->dump($Frames($Throwable)), $secret) === true) {
            $shown[] = 'Dumper';
         }

         return $shown;
      };
      $PostgreSQLEncoder = new PostgreSQLEncoder;
      $MySQLEncoder = new MySQLEncoder;
      // # A config changed after it was built: the handshake response refuses a NUL username
      $Config = new Config(['driver' => 'mysql', 'password' => $secret]);
      $Config->username = "root\0";
      $MySQLAuthentication = new MySQLAuthentication(new Config(['driver' => 'mysql']));
      $Driver = new PostgreSQL(new Config, new Connection(new Config));

      // @ Traces with every argument, strings in full — the development and CI shape
      $ignored = ini_get('zend.exception_ignore_args');
      $length = ini_get('zend.exception_string_param_max_len');
      $arguments = ini_set('zend.exception_ignore_args', '0');
      $strings = ini_set('zend.exception_string_param_max_len', '1000000');
      try {
         yield assert(
            assertion: $arguments !== false && $strings !== false,
            description: 'The trace settings change at runtime: every argument, strings in full'
         );

         // # Positive control — an unmarked frame shows the same secret
         $Unmarked = static function (string $payload): never {
            throw new InvalidArgumentException('An unmarked frame refused its payload.');
         };
         $shown = [];
         try {
            $Unmarked($secret);
         }
         catch (InvalidArgumentException $Exception) {
            $shown = $channels($Exception);
         }
         yield assert(
            assertion: $shown === ['print_r', 'getTraceAsString', 'Dumper'],
            description: 'Control: an unmarked frame shows the secret in print_r, getTraceAsString and Dumper'
         );

         // # Frames — label => [call, thrown class, message part, marked frames as [class, function, argument]]
         $chains = [
            'PostgreSQL PasswordMessage' => [
               static fn () => $PostgreSQLEncoder->encode(PostgreSQLEncoder::PASSWORD, [$secret]),
               InvalidArgumentException::class,
               'PostgreSQL password payload must be a string.',
               [[PostgreSQLEncoder::class, 'authenticate', 0], [PostgreSQLEncoder::class, 'encode', 1]],
            ],
            'PostgreSQL SASLResponse (the SCRAM proof)' => [
               static fn () => $PostgreSQLEncoder->encode(PostgreSQLEncoder::RESPONSE, ["c=biws,r=nonce,p={$secret}"]),
               InvalidArgumentException::class,
               'PostgreSQL SASL response payload must be a string.',
               [[PostgreSQLEncoder::class, 'reply', 0], [PostgreSQLEncoder::class, 'encode', 1]],
            ],
            'PostgreSQL CancelRequest' => [
               static fn () => $PostgreSQLEncoder->encode(PostgreSQLEncoder::CANCEL, ['process' => 4242, 'secret' => $secret]),
               InvalidArgumentException::class,
               'PostgreSQL CancelRequest payload must contain process and secret integers.',
               [[PostgreSQLEncoder::class, 'cancel', 0], [PostgreSQLEncoder::class, 'encode', 1]],
            ],
            'MySQL authentication continuation' => [
               static fn () => $MySQLEncoder->encode(MySQLEncoder::AUTH, [$secret], 3),
               InvalidArgumentException::class,
               'MySQL authentication payload must be a string.',
               [[MySQLEncoder::class, 'reply', 0], [MySQLEncoder::class, 'encode', 1]],
            ],
            'MySQL HandshakeResponse41 (the scramble)' => [
               static fn () => $MySQLEncoder->encode(MySQLEncoder::RESPONSE, [
                  'capabilities' => 0,
                  'auth' => $secret,
                  'plugin' => MySQLAuthentication::NATIVE,
                  'config' => $Config,
               ], 1),
               InvalidArgumentException::class,
               'MySQL handshake username and database must not contain a NUL byte.',
               [[MySQLEncoder::class, 'respond', 0], [MySQLEncoder::class, 'encode', 1]],
            ],
            'MySQL Encoder->frame()' => [
               static fn () => $MySQLEncoder->frame($secret, 'not-a-sequence'), // @phpstan-ignore argument.type (the TypeError raised inside the frame is the throw under test)
               TypeError::class,
               'Argument #2 ($sequence) must be of type int',
               [[MySQLEncoder::class, 'frame', 0]],
            ],
            'MySQL Authentication->mix()' => [
               static fn () => (fn () => $this->mix($secret, []))->call($MySQLAuthentication), // @phpstan-ignore argument.type (the TypeError raised inside the private frame is the throw under test)
               TypeError::class,
               'Argument #2 ($mask) must be of type string',
               [[MySQLAuthentication::class, 'mix', 0], [MySQLAuthentication::class, 'mix', 1]],
            ],
            'PostgreSQL->identify()' => [
               static fn () => $Driver->identify(4242, $secret), // @phpstan-ignore argument.type (the TypeError raised inside the frame is the throw under test)
               TypeError::class,
               'Argument #2 ($secret) must be of type int',
               [[PostgreSQL::class, 'identify', 1]],
            ],
         ];

         // @@ Each frame: the throw, each marked argument redacted, no channel showing the secret
         $process = null;
         foreach ($chains as $chain => [$Action, $thrown, $part, $frames]) {
            $Thrown = null;
            try {
               $Action();
            }
            catch (Throwable $Throwable) {
               $Thrown = $Throwable;
            }
            $message = $Thrown?->getMessage() ?? '(no throw)';
            $Trace = $Thrown?->getTrace() ?? [];

            $unredacted = [];
            foreach ($frames as [$class, $function, $index]) {
               $redacted = false;
               foreach ($Trace as $frame) {
                  if (($frame['class'] ?? '') === $class && $frame['function'] === $function) {
                     $redacted = ($frame['args'][$index] ?? null) instanceof SensitiveParameterValue;
                     // ? An unmarked argument beside the marked one still shows
                     if ($function === 'identify') {
                        $process = $frame['args'][0] ?? null;
                     }
                     break;
                  }
               }
               if ($redacted === false) {
                  $unredacted[] = "{$class}::{$function}() argument {$index}";
               }
            }
            $shown = $Thrown === null ? [] : $channels($Thrown);
            $missed = implode(', ', $unredacted);
            $leaked = implode(', ', $shown);
            yield assert(
               assertion: $Thrown instanceof $thrown
                  && str_contains($message, $part) === true
                  && $unredacted === []
                  && $shown === [],
               description: "{$chain}: thrown ({$message}), every marked argument redacted (not: [{$missed}]), "
                  . "no channel shows the secret (shown in: [{$leaked}])"
            );
         }

         yield assert(
            assertion: $process === 4242,
            description: 'The backend process id, beside the marked cancellation key, still shows in the identify() frame'
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignored);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }

      // @ Every wire frame that carries a secret marks it
      $frames = [
         [PostgreSQLEncoder::class, 'encode', 'payload'],
         [PostgreSQLEncoder::class, 'authenticate', 'payload'],
         [PostgreSQLEncoder::class, 'reply', 'payload'],
         [PostgreSQLEncoder::class, 'cancel', 'payload'],
         [MySQLEncoder::class, 'encode', 'payload'],
         [MySQLEncoder::class, 'respond', 'payload'],
         [MySQLEncoder::class, 'reply', 'payload'],
         [MySQLEncoder::class, 'frame', 'payload'],
         [MySQLAuthentication::class, 'mix', 'hashed'],
         [MySQLAuthentication::class, 'mix', 'mask'],
         [PostgreSQL::class, 'identify', 'secret'],
      ];
      $unmarked = [];
      foreach ($frames as [$class, $method, $parameter]) {
         $Parameter = new ReflectionParameter([$class, $method], $parameter);
         if ($Parameter->getAttributes(SensitiveParameter::class) === []) {
            $unmarked[] = "{$class}::{$method}(\${$parameter})";
         }
      }
      $missing = implode(', ', $unmarked);
      yield assert(
         assertion: $unmarked === [],
         description: "Every wire frame marks its secret #[SensitiveParameter] (unmarked: [{$missing}])"
      );
   }
);
