<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\DumpRedaction;


use function assert;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function str_contains;
use function var_dump;
use Closure;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\Encrypter;
use Bootgly\API\Security\Encrypter\Key as EncrypterKey;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Key as JWTKey;
use Bootgly\API\Security\JWT\Remote;
use Bootgly\API\Security\JWT\Token as RefreshToken;
use Bootgly\API\Security\JWT\Vault;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Token as OpaqueToken;


/**
 * Every Security holder of a secret — the JWT signer, its keys, the Vault, the
 * refresh and opaque token snapshots, the remote JWKS resolver, the Encrypter
 * key — dumps through `var_dump`, `print_r`, `debug_zval_dump` and `Dumper`
 * without it, keeps it live, and gets its `__debugInfo()` from `Redactable`.
 */
return new Test(
   description: 'Security holders keep their secrets out of every debug dump and keep them live',
   test: function () {
      // ! Each debug channel
      $Dumper = new Dumper('plain');
      $Channels = [
         'var_dump' => static function (object $Object): string {
            ob_start();
            var_dump($Object);

            return (string) ob_get_clean();
         },
         'print_r' => static fn (object $Object): string => print_r($Object, true),
         'debug_zval_dump' => static function (object $Object): string {
            ob_start();
            debug_zval_dump($Object);

            return (string) ob_get_clean();
         },
         'Dumper' => static fn (object $Object): string => $Dumper->dump($Object),
      ];

      // ! The holders, built with SYNTH secrets — every one says SYNTH, nothing else does
      $secret = 'SYNTH-JWT-SECRET-0123456789abcdef0';
      $material = 'SYNTH-JWT-KEY-MATERIAL-0123456789';
      $vaulted = 'SYNTH-VAULT-SECRET-0123456789abcd';
      $refresh = 'SYNTH-REFRESH-TOKEN-SNAPSHOT';
      $URI = 'https://SYNTH-USER:SYNTH-PASS@idp.invalid/jwks.json';
      $opaque = 'a1a1a1a1a1a1a1a1.SYNTH-VERIFIER';
      $raw = 'SYNTH-ENCRYPTER-KEY-0123456789ab';
      $JWT = new JWT($secret);
      $Key = new JWTKey($material, id: 'k-visible');
      $Vault = new Vault(new Cache(['driver' => 'memory']), 'redaction_', $vaulted);
      $Refresh = new RefreshToken($refresh, 'subject-visible', 'family-visible', ['role' => 'reader'], 1, 2);
      $Remote = new Remote($URI);
      $Opaque = new OpaqueToken($opaque, 'a1a1a1a1a1a1a1a1', 'user-visible', Purposes::Recovery, 3);
      $Encryption = new EncrypterKey($raw, 'k-visible');
      $Encrypter = new Encrypter(new EncrypterKey('SYNTH-ENCRYPTER-RAW-0123456789ab', 'k-visible'));
      $Holders = [
         'JWT' => $JWT,
         'JWT\Key' => $Key,
         'JWT\Vault' => $Vault,
         'JWT\Token' => $Refresh,
         'JWT\Remote' => $Remote,
         'Tokens\Token' => $Opaque,
         'Encrypter\Key' => $Encryption,
         'Encrypter' => $Encrypter,
      ];

      // @@ Every holder through every channel
      $outputs = [];
      $leaks = [];
      foreach ($Holders as $label => $Holder) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Holder);
            $outputs[$label][$channel] = $output;
            if (str_contains($output, 'SYNTH') === true) {
               $leaks[] = "{$label} via {$channel}";
            }
         }
      }
      $leaked = implode(', ', $leaks);
      yield assert(
         assertion: $leaks === [],
         description: "No SYNTH secret reaches var_dump, print_r, debug_zval_dump or Dumper of a Security holder (leaked: [{$leaked}])"
      );

      // @@ What each dump must still show: a plain value, the mask or the URL without its userinfo
      $visible = [
         'JWT' => ['HS256', Secret::MASK],
         'JWT\Key' => ['k-visible', Secret::MASK],
         'JWT\Vault' => ['redaction_', Secret::MASK],
         'JWT\Token' => ['subject-visible', 'reader', Secret::MASK],
         'JWT\Remote' => ['https://idp.invalid/jwks.json', 'RS256'],
         'Tokens\Token' => ['user-visible', 'a1a1a1a1a1a1a1a1', Secret::MASK],
         'Encrypter\Key' => ['k-visible', Secret::MASK],
         'Encrypter' => ['k-visible', Secret::MASK],
      ];
      $missing = [];
      foreach ($visible as $label => $values) {
         foreach ($outputs[$label] as $channel => $output) {
            foreach ($values as $shown) {
               if (str_contains($output, $shown) === false) {
                  $missing[] = "{$label} via {$channel}: {$shown}";
               }
            }
         }
      }
      $absent = implode(', ', $missing);
      yield assert(
         assertion: $missing === [],
         description: "Each dump still shows the holder's plain values and the mask in place of the secret (missing: [{$absent}])"
      );

      // @ The live values are untouched — public reads and a bound read of the private ones
      $Read = Closure::bind(static fn (Vault $Vault): string => $Vault->secret, null, Vault::class);
      yield assert(
         assertion: $JWT->secret === $secret
            && $Key->Material === $material
            && $Read($Vault) === $vaulted
            && $Refresh->refresh === $refresh
            && $Remote->URI === $URI
            && $Opaque->value === $opaque,
         description: 'Dumping never writes: every holder still holds its secret'
      );

      // @ The keys still work — the sealed Encrypter material through a seal/open round trip
      $signed = $JWT->sign(['sub' => 'subject-visible', 'exp' => 4102444800]);
      $sealed = $Encryption->seal('payload', 'aad');
      yield assert(
         assertion: $JWT->verify($signed) === ['sub' => 'subject-visible', 'exp' => 4102444800]
            && (new EncrypterKey($raw))->open($sealed, 'aad') === 'payload'
            && $Encrypter->decrypt($Encrypter->encrypt('payload')) === 'payload',
         description: 'The JWT still signs and verifies, and the Encrypter key still seals what its material opens'
      );

      // @ One mechanism: the trait's __debugInfo, #[Secret] only on stored instance properties
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $foreign = [];
      $misplaced = [];
      $unmarked = [];
      foreach ([$JWT, $Key, $Vault, $Refresh, $Remote, $Opaque, $Encryption] as $Holder) {
         $Method = new ReflectionMethod($Holder, '__debugInfo');
         if ($Method->getFileName() !== $Trait->getFileName() || $Method->getStartLine() !== $Trait->getStartLine()) {
            $foreign[] = $Holder::class;
         }

         $marked = 0;
         $Class = new ReflectionClass($Holder);
         // @@ The class and every ancestor
         do {
            foreach ($Class->getProperties() as $Property) {
               if ($Property->getAttributes(Secret::class) === []) {
                  continue;
               }
               $marked++;
               if ($Property->isStatic() === true || $Property->isVirtual() === true) {
                  $misplaced[] = "{$Class->name}::\${$Property->name}";
               }
            }
            $Class = $Class->getParentClass();
         } while ($Class !== false);
         if ($marked === 0) {
            $unmarked[] = $Holder::class;
         }
      }
      $others = implode(', ', $foreign);
      $wrong = implode(', ', $misplaced);
      $bare = implode(', ', $unmarked);
      yield assert(
         assertion: $foreign === [] && $misplaced === [] && $unmarked === [],
         description: "Each holder dumps through Redactable::__debugInfo (others: [{$others}]) and marks a stored, "
            . "non-static property #[Secret] (misplaced: [{$wrong}], unmarked: [{$bare}])"
      );
   }
);
