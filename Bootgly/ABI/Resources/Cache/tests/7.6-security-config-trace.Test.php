<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache;


use function array_diff;
use function array_slice;
use function assert;
use function implode;
use function ini_get;
use function ini_set;
use function print_r;
use function str_contains;
use InvalidArgumentException;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * A cache configuration refused while it is built throws from under the frames that carry
 * it — the facade and Cache\Config — with the Redis password in the array. Each chain is
 * triggered for real while traces hold every argument, uncapped: the marked frames hold a
 * `SensitiveParameterValue` and no renderer of the trace shows the password; an unmarked
 * frame given the same password shows it in every renderer. A driver constructor takes
 * the Config object unmarked: the object dumps masked inside a trace too.
 */
return new Test(
   description: 'Cache: the frames that build a cache keep its configuration (the Redis password) out of a stack trace',
   test: function () {
      // ! Traces at their fullest — every argument, no string cap
      $ignore = ini_get('zend.exception_ignore_args');
      $length = ini_get('zend.exception_string_param_max_len');
      $ignored = ini_set('zend.exception_ignore_args', '0');
      $capped = ini_set('zend.exception_string_param_max_len', '1000000');
      try {
         yield assert(
            assertion: $ignored !== false && $capped !== false,
            description: 'This spec raises traces to every argument, uncapped'
         );

         // ! The renderers of a caught trace
         $Dumper = new Dumper('plain');
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
         $Renderers = [
            'print_r' => static fn (Throwable $Throwable): string => print_r($Frames($Throwable), true),
            'getTraceAsString' => static fn (Throwable $Throwable): string => $Throwable->getTraceAsString(),
            'Dumper' => static fn (Throwable $Throwable): string => $Dumper->dump($Frames($Throwable)),
         ];
         $password = 'SYNTH-CACHE-PASSWORD-0123456789';

         // # Positive control: an unmarked frame given the password shows it everywhere
         $Control = null;
         try {
            (static function (string $secret): never {
               throw new RuntimeException('Unmarked control.');
            })($password);
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $blind = [];
         foreach ($Renderers as $renderer => $Renderer) {
            if (str_contains($Renderer($Control), $password) === false) {
               $blind[] = $renderer;
            }
         }
         $unseen = implode(', ', $blind);
         yield assert(
            assertion: $blind === [],
            description: "An unmarked frame shows the password in every renderer (blind: [{$unseen}])"
         );

         // ! The chains: label => [action, message, the frames that must redact the password]
         $Facade = Cache::class . '->__construct';
         $Configuration = Config::class . '->__construct';
         $Cases = [
            'an invalid TTL, through the facade' => [
               static fn () => new Cache(['driver' => 'memory', 'password' => $password, 'TTL' => -5]),
               "Invalid cache option 'TTL'",
               [$Configuration, $Facade]
            ],
            'an unknown option, through the facade' => [
               static fn () => new Cache(['driver' => 'memory', 'password' => $password, 'pasword' => $password]),
               "Unknown cache option 'pasword'",
               [$Configuration, $Facade]
            ],
            'an unregistered driver, through the facade' => [
               static fn () => new Cache(['driver' => 'unregistered', 'password' => $password]),
               'Cache driver is not registered: unregistered.',
               [$Facade]
            ],
            'an invalid TTL, through the configuration' => [
               static fn () => new Config(['driver' => 'redis', 'password' => $password, 'TTL' => '1h']),
               "Invalid cache option 'TTL'",
               [$Configuration]
            ],
         ];

         // @@ Each chain — the action runs inline: a helper frame would hold its Closure
         foreach ($Cases as $label => [$Action, $message, $Frames]) {
            $Thrown = null;
            try {
               $Action();
            }
            catch (InvalidArgumentException $Exception) {
               $Thrown = $Exception;
            }

            $redacted = [];
            $leaks = [];
            if ($Thrown !== null) {
               foreach ($Thrown->getTrace() as $frame) {
                  foreach ($frame['args'] ?? [] as $argument) {
                     if ($argument instanceof SensitiveParameterValue) {
                        $redacted[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
                     }
                  }
               }
               foreach ($Renderers as $renderer => $Renderer) {
                  if (str_contains($Renderer($Thrown), $password) === true) {
                     $leaks[] = $renderer;
                  }
               }
            }
            $unmarked = implode(', ', array_diff($Frames, $redacted));
            $leaked = implode(', ', $leaks);
            yield assert(
               assertion: $Thrown !== null
                  && str_contains($Thrown->getMessage(), $message) === true
                  && $unmarked === ''
                  && $leaks === [],
               description: "{$label}: the refusal is thrown, its frames redact the configuration "
                  . "(unmarked: [{$unmarked}]) and no renderer shows the password (leaked: [{$leaked}])"
            );
         }

         // # The Config object as an argument (every driver constructor takes it unmarked)
         $Holder = null;
         try {
            (static function (Config $Config): never {
               throw new RuntimeException('Unmarked holder.');
            })(new Config(['driver' => 'redis', 'password' => $password]));
         }
         catch (RuntimeException $Exception) {
            $Holder = $Exception;
         }
         $leaks = [];
         foreach ($Renderers as $renderer => $Renderer) {
            if (str_contains($Renderer($Holder), $password) === true) {
               $leaks[] = $renderer;
            }
         }
         $leaked = implode(', ', $leaks);
         yield assert(
            assertion: $leaks === []
               && str_contains($Renderers['print_r']($Holder), Secret::MASK) === true,
            description: "A frame given the Config object shows it masked in every renderer (leaked: [{$leaked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }

      // # The attributes, pinned
      $sensitive = static fn (string $class, string $parameter): bool => (new ReflectionParameter([$class, '__construct'], $parameter))
         ->getAttributes(SensitiveParameter::class) !== [];
      yield assert(
         assertion: $sensitive(Cache::class, 'config') === true && $sensitive(Config::class, 'config') === true,
         description: 'The Cache and Cache\Config constructors mark the configuration as sensitive'
      );
   }
);
