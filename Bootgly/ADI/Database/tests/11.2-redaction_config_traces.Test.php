<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database\Tests\RedactionConfigTraces;


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

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database;
use Bootgly\ADI\Database\Config as DatabaseConfig;
use Bootgly\ADI\Databases;
use Bootgly\ADI\Databases\KV;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Config;


/**
 * A configuration refused at construction throws from under the frames that
 * carry it raw — the paradigm factory, the facades, the configuration classes
 * and their private helpers that read the replicas. With
 * `zend.exception_ignore_args=0` (development and CI) a trace holds every
 * argument of every frame, so each of those frames marks its configuration
 * `#[SensitiveParameter]`: the password inside never reaches a trace.
 *
 * Each construction is called inline: a frame that took the closure as an
 * argument would show the secret the closure captured, in `print_r`.
 */
return new Test(
   description: 'Database: a refused configuration keeps its password out of the exception trace, frame by frame',
   test: function () {
      $secret = 'SYNTH-TRACE-CONFIG-PASSWORD';
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

         // # Positive control — an unmarked frame shows the same secret, as deep as a replica's
         /** @param array<string,mixed> $config */
         $Unmarked = static function (string $password, array $config): never {
            throw new InvalidArgumentException('An unmarked frame refused its configuration.');
         };
         $shown = [];
         try {
            $Unmarked($secret, ['replicas' => [['password' => $secret]]]);
         }
         catch (InvalidArgumentException $Exception) {
            $shown = $channels($Exception);
         }
         yield assert(
            assertion: $shown === ['print_r', 'getTraceAsString', 'Dumper'],
            description: 'Control: an unmarked frame shows the secret in print_r, getTraceAsString and Dumper'
         );

         // # Chains — label => [construction, refusal, marked frames as [class, function, argument]]
         $chains = [
            'Databases->create() of a MySQL configuration with session parameters' => [
               static fn () => (new Databases)->create('sql', [
                  'driver' => 'mysql',
                  'password' => $secret,
                  'parameters' => ['search_path' => 'app'],
               ]),
               'Database `parameters` are PostgreSQL only: the mysql driver has no startup session parameters, so set the session after connecting instead.',
               [[Databases::class, 'create', 1], [SQL::class, '__construct', 0], [Config::class, '__construct', 0]],
            ],
            'SQL with a cafile it never verifies' => [
               static fn () => new SQL([
                  'password' => $secret,
                  'secure' => ['mode' => 'prefer', 'cafile' => '/nonexistent/ca.pem'],
               ]),
               'Database TLS cafile requires certificate verification: use mode `verify-ca` or `verify-full`, or set `verify` to true.',
               [[DatabaseConfig::class, '__construct', 0], [Config::class, '__construct', 0], [SQL::class, '__construct', 0]],
            ],
            'SQL\Config with a replica cafile it never verifies (normalize)' => [
               static fn () => new Config([
                  'replicas' => [[
                     'host' => 'replica.invalid',
                     'password' => $secret,
                     'secure' => ['mode' => 'prefer', 'cafile' => '/nonexistent/ca.pem'],
                  ]],
               ]),
               'Database TLS cafile requires certificate verification: use mode `verify-ca` or `verify-full`, or set `verify` to true (replica replica.invalid).',
               [[DatabaseConfig::class, 'normalize', 0], [DatabaseConfig::class, '__construct', 0], [Config::class, '__construct', 0]],
            ],
            'SQL\Config with a replica session parameter it cannot send (complete)' => [
               static fn () => new Config([
                  'replicas' => [[
                     'host' => 'replica.invalid',
                     'password' => $secret,
                     'parameters' => ['bad name' => 'app'],
                  ]],
               ]),
               'Database parameter `bad name` is not a valid name: use letters, digits, `_` and `.`, starting with a letter or `_` (replica replica.invalid).',
               [[Config::class, 'complete', 0], [Config::class, '__construct', 0]],
            ],
            'KV with an unknown TLS mode' => [
               static fn () => new KV([
                  'driver' => 'redis',
                  'password' => $secret,
                  'secure' => ['mode' => 'bogus'],
               ]),
               'Unsupported database TLS mode: bogus.',
               [[DatabaseConfig::class, '__construct', 0], [Database::class, '__construct', 0], [KV::class, '__construct', 0]],
            ],
            'Database\Config with a replica flag that is no boolean (normalize)' => [
               static fn () => new DatabaseConfig([
                  'password' => $secret,
                  'replicas' => [['host' => 'replica.invalid', 'password' => $secret, 'secure' => ['verify' => 'maybe']]],
               ]),
               'Database TLS `verify` must be a boolean (replica replica.invalid).',
               [[DatabaseConfig::class, 'normalize', 0], [DatabaseConfig::class, '__construct', 0]],
            ],
         ];

         // @@ Each chain: the refusal, each marked frame redacted, no channel showing the secret
         $paradigm = null;
         foreach ($chains as $chain => [$Action, $refusal, $frames]) {
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
                     if ($function === 'create') {
                        $paradigm = $frame['args'][0] ?? null;
                     }
                     break;
                  }
               }
               if ($redacted === false) {
                  $unredacted[] = "{$class}::{$function}()";
               }
            }
            $shown = $Thrown === null ? [] : $channels($Thrown);
            $missed = implode(', ', $unredacted);
            $leaked = implode(', ', $shown);
            yield assert(
               assertion: $Thrown instanceof InvalidArgumentException
                  && $message === $refusal
                  && $unredacted === []
                  && $shown === [],
               description: "{$chain}: refused ({$message}), every marked frame redacted (not: [{$missed}]), "
                  . "no channel shows the password (shown in: [{$leaked}])"
            );
         }

         yield assert(
            assertion: $paradigm === 'sql',
            description: 'The paradigm, beside the marked configuration, still shows in the Databases->create() frame'
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignored);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }

      // @ Every frame that carries the raw configuration marks it — accept() included,
      //   which nothing throws under: the chains above throw through normalize() and
      //   complete(), its only callers
      $frames = [
         [Databases::class, 'create', 'config'],
         [SQL::class, '__construct', 'config'],
         [KV::class, '__construct', 'config'],
         [Database::class, '__construct', 'config'],
         [DatabaseConfig::class, '__construct', 'config'],
         [DatabaseConfig::class, 'normalize', 'replicas'],
         [DatabaseConfig::class, 'accept', 'replica'],
         [Config::class, '__construct', 'config'],
         [Config::class, 'complete', 'replicas'],
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
         description: "Every configuration frame marks its parameter #[SensitiveParameter] (unmarked: [{$missing}])"
      );
   }
);
