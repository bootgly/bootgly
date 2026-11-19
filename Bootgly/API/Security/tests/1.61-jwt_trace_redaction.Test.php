<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\JWTTraceRedaction;


use function array_diff;
use function array_slice;
use function assert;
use function base64_encode;
use function hash;
use function implode;
use function ini_get;
use function ini_set;
use function json_encode;
use function print_r;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strtr;
use function time;
use Closure;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Cache;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\Key;
use Bootgly\API\Security\JWT\KeyResolver;
use Bootgly\API\Security\JWT\Token;
use Bootgly\API\Security\JWT\Tokens;
use Bootgly\API\Security\JWT\Usage;
use Bootgly\API\Security\JWT\Vault;


/**
 * An in-memory JWT cache whose methods listed in `$fail` throw for the keys
 * that start with the listed prefix (`''` matches every key) — a store that
 * becomes unreachable at a chosen step.
 */
final class FlakyCache implements Cache
{
   // * Config
   /** @var array<string,string> Method name => key prefix that makes it throw. */
   public array $fail;

   // * Data
   /** @var array<string,string> */
   public array $store = [];


   /**
    * @param array<string,string> $fail Method name => key prefix that makes it throw.
    */
   public function __construct (array $fail = [])
   {
      // * Config
      $this->fail = $fail;
   }

   public function lock (Closure $Closure): mixed
   {
      return $Closure();
   }

   public function read (string $key): null|string
   {
      $this->trip('read', $key);

      return $this->store[$key] ?? null;
   }

   public function write (string $key, string $value, int $TTL): bool
   {
      $this->trip('write', $key);
      $this->store[$key] = $value;

      return true;
   }

   public function claim (string $key, string $value, int $TTL): bool
   {
      $this->trip('claim', $key);
      if (isSet($this->store[$key]) === true) {
         return false;
      }
      $this->store[$key] = $value;

      return true;
   }

   public function take (string $key): null|string
   {
      $this->trip('take', $key);
      $value = $this->store[$key] ?? null;
      unset($this->store[$key]);

      return $value;
   }

   public function delete (string $key): bool
   {
      unset($this->store[$key]);

      return true;
   }

   public function purge (): bool
   {
      return true;
   }

   /**
    * Throw when the method is set to fail for this key.
    */
   private function trip (string $method, string $key): void
   {
      $prefix = $this->fail[$method] ?? null;
      if ($prefix !== null && str_starts_with($key, $prefix) === true) {
         throw new RuntimeException("JWT cache backend unreachable ({$method}).");
      }
   }
}

/**
 * A key resolver whose key store is unreachable.
 */
final class DownResolver implements KeyResolver
{
   public function resolve (null|string $id, string $algorithm): null|Key
   {
      throw new RuntimeException('JWT key store unreachable.');
   }

   public function fail (): null|Failures
   {
      return null;
   }
}


/**
 * Each JWT throw chain that carries a secret — an access token through
 * `inspect()`/`verify()`, a short signing secret or key material, a short
 * Vault secret, a refresh token through `JWT\Tokens` and its private helpers,
 * a refresh-token snapshot — is triggered for real with a SYNTH secret while
 * traces hold every argument, uncapped. The marked frames hold a
 * `SensitiveParameterValue`, and no renderer of the trace shows the secret;
 * an unmarked frame given the same secret shows it in every renderer.
 *
 * `rotate()` and `revoke()` hand `Cache::lock()` a Closure, and a Closure
 * argument dumps what it captured: the token enters it sealed. Its control is
 * a Closure that captures the same secret raw.
 *
 * `JWT\Token::__construct()` throws only on its own parameter types, so its
 * chain is a non-integer expiry. `JWT\Tokens::cast()` and `::index()` cannot
 * throw: their attribute is pinned by reflection, and the chains through
 * their callers run for real.
 */
return new Test(
   description: 'JWT: access and refresh tokens, keys and the Vault secret stay out of exception traces',
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

         // ! The secrets
         $refresh = 'SYNTH-REFRESH-TOKEN-0123456789';
         $header = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '=');
         $forged = "{$header}.SYNTH-PAYLOAD.SYNTH-SIGNATURE";
         $Signer = new JWT('SYNTH-JWT-SIGNING-SECRET-0123456789');
         $access = $Signer->sign(['jti' => 'j1', 'exp' => time() + 600]);
         // # Stores that fail at a chosen step
         $Signer->track(new Usage(new FlakyCache(['claim' => '']), single: true));
         $Resolver = new JWT(new DownResolver);
         $digest = hash('sha256', $refresh);
         $active = "jwt:refresh:active:{$digest}";
         $record = (string) json_encode([
            'subject' => 'subject-1',
            'family' => 'family-1',
            'claims' => [],
            'issued' => time(),
            'expires' => time() + 600,
         ]);
         $Stocked = static function (array $fail) use ($active, $record): Tokens {
            $Cache = new FlakyCache($fail);
            $Cache->store[$active] = $record;

            return new Tokens($Cache);
         };

         // ! The chains: label => [action, secret, the frames that must redact it, the lock's Closure]
         $JWT = JWT::class;
         $Key = Key::class;
         $Vault = Vault::class;
         $Token = Token::class;
         $Tokens = Tokens::class;
         $Cases = [
            'JWT::verify() with its usage store down' => [
               static fn () => $Signer->verify($access), $access, ["{$JWT}->inspect", "{$JWT}->verify"], 'none'
            ],
            'JWT::inspect() with its usage store down' => [
               static fn () => $Signer->inspect($access), $access, ["{$JWT}->inspect"], 'none'
            ],
            'JWT::verify() with its key store down' => [
               static fn () => $Resolver->verify($forged), 'SYNTH-SIGNATURE', ["{$JWT}->inspect", "{$JWT}->verify"], 'none'
            ],
            'JWT::__construct() with a short secret' => [
               static fn () => new JWT('SYNTH-SHORT-JWT-SECRET'), 'SYNTH-SHORT-JWT-SECRET',
               ["{$Key}->__construct", "{$JWT}->__construct"], 'none'
            ],
            'JWT\Key::__construct() with short material' => [
               static fn () => new Key('SYNTH-SHORT-KEY-MATERIAL'), 'SYNTH-SHORT-KEY-MATERIAL', ["{$Key}->__construct"], 'none'
            ],
            'JWT\Vault::__construct() with a short secret' => [
               static fn () => new Vault(null, 'jwt_', 'SYNTH-SHORT-VAULT-SECRET'), 'SYNTH-SHORT-VAULT-SECRET',
               ["{$Vault}->__construct"], 'none'
            ],
            'JWT\Token::__construct() with a non-integer expiry' => [
               // @phpstan-ignore argument.type (the constructor's own type check is the throw under test)
               static fn () => new Token($refresh, 'subject-1', 'family-1', [], 1, 'not-an-int'), $refresh,
               ["{$Token}->__construct"], 'none'
            ],
            'JWT\Tokens::inspect() with its store down' => [
               static fn () => (new Tokens(new FlakyCache(['read' => ''])))->inspect($refresh), $refresh,
               ["{$Tokens}->read", "{$Tokens}->inspect"], 'none'
            ],
            'JWT\Tokens::check() with its store down' => [
               static fn () => (new Tokens(new FlakyCache(['read' => ''])))->check($refresh), $refresh,
               ["{$Tokens}->read", "{$Tokens}->inspect", "{$Tokens}->check"], 'none'
            ],
            'JWT\Tokens::rotate() with its store down' => [
               static fn () => (new Tokens(new FlakyCache(['read' => ''])))->rotate($refresh, 60), $refresh,
               ["{$Tokens}->read", "{$Tokens}->rotate"], 'sealed'
            ],
            'JWT\Tokens::revoke() with its store down' => [
               static fn () => (new Tokens(new FlakyCache(['read' => ''])))->revoke($refresh), $refresh,
               ["{$Tokens}->read", "{$Tokens}->revoke"], 'sealed'
            ],
            'JWT\Tokens::rotate() with its tombstones down' => [
               static fn () => (new Tokens(new FlakyCache(['read' => 'jwt:refresh:spent:'])))->rotate($refresh, 60), $refresh,
               ["{$Tokens}->detect", "{$Tokens}->rotate"], 'sealed'
            ],
            'JWT\Tokens::revoke() with its tombstones down' => [
               static fn () => (new Tokens(new FlakyCache(['read' => 'jwt:refresh:spent:'])))->revoke($refresh), $refresh,
               ["{$Tokens}->detect", "{$Tokens}->revoke"], 'sealed'
            ],
            'JWT\Tokens::rotate() with its take down' => [
               static fn () => $Stocked(['take' => ''])->rotate($refresh, 60), $refresh,
               ["{$Tokens}->take", "{$Tokens}->rotate"], 'sealed'
            ],
            'JWT\Tokens::revoke() with its take down' => [
               static fn () => $Stocked(['take' => ''])->revoke($refresh), $refresh,
               ["{$Tokens}->take", "{$Tokens}->revoke"], 'sealed'
            ],
         ];

         // @@ Each chain — the action runs inline: a helper frame would hold its Closure
         foreach ($Cases as $label => [$Action, $secret, $Frames, $sealing]) {
            // ! Positive controls: an unmarked frame given the same secret shows it in every renderer,
            //   and a Closure that captures it raw shows it in its own dump
            $Control = null;
            try {
               (static function (string $secret): never {
                  throw new RuntimeException('Unmarked control.');
               })($secret);
            }
            catch (RuntimeException $Exception) {
               $Control = $Exception;
            }
            $blind = [];
            foreach ($Renderers as $renderer => $Renderer) {
               if (str_contains($Renderer($Control), $secret) === false) {
                  $blind[] = $renderer;
               }
            }
            $Capture = static function () use ($secret): string {
               return $secret;
            };
            if (str_contains(print_r($Capture, true), $secret) === false) {
               $blind[] = 'a raw Closure capture';
            }

            // @ The real chain
            $Thrown = null;
            try {
               $Action();
            }
            catch (Throwable $Throwable) {
               $Thrown = $Throwable;
            }
            $redacted = [];
            $leaks = [];
            $lock = 'none';
            if ($Thrown !== null) {
               foreach ($Thrown->getTrace() as $frame) {
                  $class = $frame['class'] ?? '';
                  $type = $frame['type'] ?? '';
                  $function = "{$class}{$type}{$frame['function']}";
                  foreach ($frame['args'] ?? [] as $argument) {
                     if ($argument instanceof SensitiveParameterValue) {
                        $redacted[] = $function;
                     }
                     // # The lock's Closure: its capture is sealed, not absent
                     if ($frame['function'] === 'lock' && $argument instanceof Closure) {
                        $closure = print_r($argument, true);
                        $sealed = str_contains($closure, 'SensitiveParameterValue') === true
                           && str_contains($closure, $secret) === false;
                        $lock = $sealed === true ? 'sealed' : 'open';
                     }
                  }
               }
               foreach ($Renderers as $renderer => $Renderer) {
                  if (str_contains($Renderer($Thrown), $secret) === true) {
                     $leaks[] = $renderer;
                  }
               }
            }
            $Unmarked = array_diff($Frames, $redacted);
            $unmarked = implode(', ', $Unmarked);
            $leaked = implode(', ', $leaks);
            $unseen = implode(', ', $blind);
            $thrown = $Thrown === null ? 'nothing' : $Thrown::class;
            yield assert(
               assertion: $blind === [] && $Thrown !== null && $Unmarked === [] && $leaks === [] && $lock === $sealing,
               description: "{$label}: threw {$thrown}; marked frames hold a SensitiveParameterValue (unmarked: [{$unmarked}]); no renderer shows the secret (leaked: [{$leaked}]); lock Closure: {$lock}, expected {$sealing}; controls show it (blind: [{$unseen}])"
            );
         }

         // @ The frames no in-process chain can throw through — and every other one — carry the attribute
         $Parameters = [
            [JWT::class, '__construct', 'secret'],
            [JWT::class, 'inspect', 'token'],
            [JWT::class, 'verify', 'token'],
            [Key::class, '__construct', 'material'],
            [Vault::class, '__construct', 'secret'],
            [Token::class, '__construct', 'refresh'],
            [Tokens::class, 'inspect', 'refresh'],
            [Tokens::class, 'check', 'refresh'],
            [Tokens::class, 'rotate', 'refresh'],
            [Tokens::class, 'revoke', 'refresh'],
            [Tokens::class, 'read', 'refresh'],
            [Tokens::class, 'take', 'refresh'],
            [Tokens::class, 'detect', 'refresh'],
            [Tokens::class, 'cast', 'refresh'],
            [Tokens::class, 'index', 'value'],
         ];
         $bare = [];
         foreach ($Parameters as [$class, $method, $parameter]) {
            $Parameter = new ReflectionParameter([$class, $method], $parameter);
            if ($Parameter->getAttributes(SensitiveParameter::class) === []) {
               $bare[] = "{$class}::{$method}(\${$parameter})";
            }
         }
         $unmarked = implode(', ', $bare);
         yield assert(
            assertion: $bare === [],
            description: "Every JWT frame that receives a token or a secret marks it #[SensitiveParameter] (unmarked: [{$unmarked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
