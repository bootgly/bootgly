<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use function array_diff;
use function array_slice;
use function assert;
use function implode;
use function ini_get;
use function ini_set;
use function print_r;
use function str_contains;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use stdClass;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Resources\Storage\Drivers\Local;
use Bootgly\ABI\Resources\Storage\Drivers\Memory;
use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Signer;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The other frames a disk's credentials pass through. A driver constructor takes the disk
 * options, and nothing under it throws today but its own parameter types — so each one is
 * thrown through that check. `Signer::presign()` adds the session token to the query it
 * was given before it encodes it, so its frame and the encoder's carry the token when an
 * encoding fails. Each throw runs while traces hold every argument, uncapped: the marked
 * frames hold a `SensitiveParameterValue` and no renderer of the trace shows a secret; an
 * unmarked frame given the same secret shows it in every renderer.
 */
return new Test(
   description: 'Storage: the driver constructors and the S3 signer keep the credentials out of a stack trace',
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
         $secret = 'SYNTH-S3-SECRET-0123456789';
         $token = 'SYNTH-S3-TOKEN-0123456789';

         // # Positive control: an unmarked frame given each secret shows it everywhere
         $blind = [];
         foreach ([$secret, $token] as $value) {
            $Control = null;
            try {
               (static function (string $secret): never {
                  throw new RuntimeException('Unmarked control.');
               })($value);
            }
            catch (RuntimeException $Exception) {
               $Control = $Exception;
            }
            foreach ($Renderers as $renderer => $Renderer) {
               if (str_contains($Renderer($Control), $value) === false) {
                  $blind[] = $renderer;
               }
            }
         }
         $unseen = implode(', ', $blind);
         yield assert(
            assertion: $blind === [],
            description: "An unmarked frame shows the secret and the token in every renderer (blind: [{$unseen}])"
         );

         // ! The chains: label => [action, the frames that must redact the credentials]
         $options = ['bucket' => 'examplebucket', 'key' => 'AKIAEXAMPLE', 'secret' => $secret, 'token' => $token];
         $Signer = new Signer('AKIAEXAMPLE', $secret, 'eu-west-1', 's3', $token);
         $Cases = [
            'Driver::__construct() (the Memory driver), refused for its root' => [
               // @phpstan-ignore argument.type (the constructor's own type check is the throw under test)
               static fn () => new Memory(null, $options),
               [Driver::class . '->__construct']
            ],
            'Local::__construct(), refused for its root' => [
               // @phpstan-ignore argument.type (the constructor's own type check is the throw under test)
               static fn () => new Local(null, $options),
               [Local::class . '->__construct']
            ],
            'S3::__construct(), refused for its root' => [
               // @phpstan-ignore argument.type (the constructor's own type check is the throw under test)
               static fn () => new S3(null, $options),
               [S3::class . '->__construct']
            ],
            'Signer::presign() with a query value it cannot encode' => [
               // @phpstan-ignore argument.type (an object value is the throw under test)
               static fn () => $Signer->presign('GET', 'examplebucket.s3.amazonaws.com', '/k', ['response-content-type' => new stdClass], 60),
               [Signer::class . '::encode', Signer::class . '->presign']
            ],
         ];

         // @@ Each chain — the action runs inline: a helper frame would hold its Closure
         foreach ($Cases as $label => [$Action, $Frames]) {
            $Thrown = null;
            try {
               $Action();
            }
            catch (Throwable $Throwable) {
               $Thrown = $Throwable;
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
                  $trace = $Renderer($Thrown);
                  if (str_contains($trace, $secret) === true || str_contains($trace, $token) === true) {
                     $leaks[] = $renderer;
                  }
               }
            }
            $unmarked = implode(', ', array_diff($Frames, $redacted));
            $leaked = implode(', ', $leaks);
            yield assert(
               assertion: $Thrown !== null && $unmarked === '' && $leaks === [],
               description: "{$label}: it throws, its frames redact the credentials (unmarked: [{$unmarked}]) "
                  . "and no renderer shows them (leaked: [{$leaked}])"
            );
         }
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }

      // # The attributes, pinned
      $sensitive = static fn (string $class, string $method, string $parameter): bool => (new ReflectionParameter([$class, $method], $parameter))
         ->getAttributes(SensitiveParameter::class) !== [];
      yield assert(
         assertion: $sensitive(Driver::class, '__construct', 'options') === true
            && $sensitive(Local::class, '__construct', 'options') === true
            && $sensitive(S3::class, '__construct', 'options') === true
            && $sensitive(Signer::class, 'presign', 'query') === true
            && $sensitive(Signer::class, 'encode', 'query') === true,
         description: 'The driver constructors, Signer::presign() and Signer::encode() mark what carries the credentials as sensitive'
      );
   }
);
