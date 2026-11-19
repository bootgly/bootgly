<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\CredentialsTraceRedaction;


use function array_diff;
use function array_slice;
use function assert;
use function base64_encode;
use function implode;
use function ini_get;
use function ini_set;
use function print_r;
use function str_contains;
use function str_repeat;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\API\Security\Encrypter;
use Bootgly\API\Security\Encrypter\Key;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Token;
use Bootgly\API\Security\Tokens\Trust;
use Bootgly\API\Security\Users;


/**
 * A connection-free SQL facade whose credential store is unreachable: every
 * query throws before an Operation exists.
 */
final class DownSQL extends SQL
{
   public function __construct ()
   {
      parent::__construct([
         'driver' => 'pgsql',
         'pool' => ['min' => 0, 'max' => 0],
      ]);
   }

   /**
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      throw new RuntimeException('Credential store unreachable.');
   }
}


/**
 * Each credential throw chain — an opaque or trust token through `Tokens` and
 * `Trust`, a password through `Users`, raw or encoded key material and a
 * plaintext through `Encrypter` and its `Key`, a token snapshot — is
 * triggered for real with a synthetic secret while traces hold every
 * argument, uncapped. The marked frames hold a `SensitiveParameterValue`, and
 * no renderer of the trace shows the secret; an unmarked frame given the same
 * secret shows it in every renderer.
 *
 * A token reaches its store only in the `selector.verifier` hex format, so its
 * secret is the synthetic verifier `deadbeef…`, not a SYNTH string. The
 * constructor of `Tokens\Token` and the `AAD` of `encrypt()`/`seal()` throw
 * only on their own parameter types, so those chains pass a wrong type. The
 * private `split()` of `Tokens` and `Trust` never throws: its attribute is
 * pinned by reflection, and the chains through its callers run for real.
 */
return new Test(
   description: 'Credentials: opaque and trust tokens, passwords, key material and plaintexts stay out of exception traces',
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

         // ! The secrets and the stores
         $verifier = str_repeat('deadbeef', 8);
         $opaque = "a1a1a1a1a1a1a1a1.{$verifier}";
         $imported = 'SYNTH-SHORT-IMPORTED-KEY';
         $encoded = base64_encode($imported);
         $Down = new DownSQL;
         $Password = new Password(memory: 19456, time: 2);
         $Cipher = new Encrypter(new Key('SYNTH-ENCRYPTER-KEY-0123456789ab'));
         $Sealer = new Key('SYNTH-ENCRYPTER-KEY-0123456789ab');

         // ! The chains: label => [action, the secrets, the frames that must redact them]
         $Tokens = Tokens::class;
         $Trust = Trust::class;
         $Token = Token::class;
         $Users = Users::class;
         $Encrypter = Encrypter::class;
         $Key = Key::class;
         $Cases = [
            'Tokens::redeem() with its store down' => [
               static fn () => (new Tokens($Down))->redeem($opaque, Purposes::Recovery), [$verifier], ["{$Tokens}->redeem"]
            ],
            'Tokens::check() with its store down' => [
               static fn () => (new Tokens($Down))->check($opaque, Purposes::Recovery), [$verifier], ["{$Tokens}->check"]
            ],
            'Trust::rotate() with its store down' => [
               static fn () => (new Trust($Down))->rotate($opaque), [$verifier], ["{$Trust}->rotate"]
            ],
            'Trust::forget() with its store down' => [
               static fn () => (new Trust($Down))->forget($opaque), [$verifier], ["{$Trust}->forget"]
            ],
            'Tokens\Token::__construct() with a non-integer expiry' => [
               // @phpstan-ignore argument.type (the constructor's own type check is the throw under test)
               static fn () => new Token('SYNTH-OPAQUE-TOKEN', 'a1a1a1a1a1a1a1a1', 'user-1', null, 'not-an-int'),
               ['SYNTH-OPAQUE-TOKEN'], ["{$Token}->__construct"]
            ],
            'Users::enroll() with its store down' => [
               static fn () => (new Users($Down, $Password))->enroll('user@example.invalid', 'SYNTH-PASSWORD-ENROLL'),
               ['SYNTH-PASSWORD-ENROLL'], ["{$Users}->enroll"]
            ],
            'Users::verify() with its store down' => [
               static fn () => (new Users($Down, $Password))->verify('user@example.invalid', 'SYNTH-PASSWORD-VERIFY'),
               ['SYNTH-PASSWORD-VERIFY'], ["{$Users}->verify"]
            ],
            'Users::check() with its store down' => [
               static fn () => (new Users($Down, $Password))->check('user-1', 'SYNTH-PASSWORD-CHECK'),
               ['SYNTH-PASSWORD-CHECK'], ["{$Users}->check"]
            ],
            'Users::rotate() with its store down' => [
               static fn () => (new Users($Down, $Password))->rotate('user-1', 'SYNTH-PASSWORD-ROTATE'),
               ['SYNTH-PASSWORD-ROTATE'], ["{$Users}->rotate"]
            ],
            'Encrypter::__construct() with a short key' => [
               static fn () => new Encrypter('SYNTH-SHORT-ENCRYPTER-KEY'), ['SYNTH-SHORT-ENCRYPTER-KEY'],
               ["{$Key}->__construct", "{$Encrypter}->__construct"]
            ],
            'Encrypter::encrypt() with a non-string AAD' => [
               // @phpstan-ignore argument.type (the method's own type check is the throw under test)
               static fn () => $Cipher->encrypt('SYNTH-PLAINTEXT-ENCRYPT', []), ['SYNTH-PLAINTEXT-ENCRYPT'],
               ["{$Encrypter}->encrypt"]
            ],
            'Encrypter\Key::import() with invalid base64' => [
               static fn () => Key::import('SYNTH-NOT-BASE64!'), ['SYNTH-NOT-BASE64!'], ["{$Key}::import"]
            ],
            'Encrypter\Key::import() with short material' => [
               static fn () => Key::import($encoded), [$imported, $encoded], ["{$Key}->__construct", "{$Key}::import"]
            ],
            'Encrypter\Key::seal() with a non-string AAD' => [
               // @phpstan-ignore argument.type (the method's own type check is the throw under test)
               static fn () => $Sealer->seal('SYNTH-PLAINTEXT-SEAL', []), ['SYNTH-PLAINTEXT-SEAL'], ["{$Key}->seal"]
            ],
         ];

         // @@ Each chain — the action runs inline: a helper frame would hold its Closure
         foreach ($Cases as $label => [$Action, $secrets, $Frames]) {
            // ! Positive control: an unmarked frame given the same secret shows it in every renderer
            $blind = [];
            foreach ($secrets as $secret) {
               $Control = null;
               try {
                  (static function (string $secret): never {
                     throw new RuntimeException('Unmarked control.');
                  })($secret);
               }
               catch (RuntimeException $Exception) {
                  $Control = $Exception;
               }
               foreach ($Renderers as $renderer => $Renderer) {
                  if (str_contains($Renderer($Control), $secret) === false) {
                     $blind[] = $renderer;
                  }
               }
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
            if ($Thrown !== null) {
               foreach ($Thrown->getTrace() as $frame) {
                  $class = $frame['class'] ?? '';
                  $type = $frame['type'] ?? '';
                  $function = "{$class}{$type}{$frame['function']}";
                  foreach ($frame['args'] ?? [] as $argument) {
                     if ($argument instanceof SensitiveParameterValue) {
                        $redacted[] = $function;
                     }
                  }
               }
               foreach ($Renderers as $renderer => $Renderer) {
                  $trace = $Renderer($Thrown);
                  foreach ($secrets as $secret) {
                     if (str_contains($trace, $secret) === true) {
                        $leaks[] = $renderer;
                     }
                  }
               }
            }
            $Unmarked = array_diff($Frames, $redacted);
            $unmarked = implode(', ', $Unmarked);
            $leaked = implode(', ', $leaks);
            $unseen = implode(', ', $blind);
            $thrown = $Thrown === null ? 'nothing' : $Thrown::class;
            yield assert(
               assertion: $blind === [] && $Thrown !== null && $Unmarked === [] && $leaks === [],
               description: "{$label}: threw {$thrown}; marked frames hold a SensitiveParameterValue (unmarked: [{$unmarked}]); no renderer shows the secret (leaked: [{$leaked}]); the control shows it (blind: [{$unseen}])"
            );
         }

         // @ The frames no in-process chain can throw through — and every other one — carry the attribute
         $Parameters = [
            [Tokens::class, 'redeem', 'token'],
            [Tokens::class, 'check', 'token'],
            [Tokens::class, 'split', 'token'],
            [Trust::class, 'rotate', 'token'],
            [Trust::class, 'forget', 'token'],
            [Trust::class, 'split', 'token'],
            [Token::class, '__construct', 'value'],
            [Users::class, 'enroll', 'password'],
            [Users::class, 'verify', 'password'],
            [Users::class, 'check', 'password'],
            [Users::class, 'rotate', 'password'],
            [Encrypter::class, '__construct', 'key'],
            [Encrypter::class, 'encrypt', 'plaintext'],
            [Key::class, '__construct', 'material'],
            [Key::class, 'import', 'encoded'],
            [Key::class, 'seal', 'plaintext'],
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
            description: "Every credential frame that receives a token, a password, key material or a plaintext marks it #[SensitiveParameter] (unmarked: [{$unmarked}])"
         );
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
      }
   }
);
