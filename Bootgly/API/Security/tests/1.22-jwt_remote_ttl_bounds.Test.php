<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Key;
use Bootgly\API\Security\JWT\KeySet;
use Bootgly\API\Security\JWT\Remote;
use Bootgly\API\Security\JWT\Remote\Response;
use Bootgly\API\Security\JWT\Vault;


$supported = function_exists('openssl_pkey_get_details')
   && function_exists('openssl_pkey_get_private')
   && function_exists('openssl_pkey_get_public');


return new Test(
   description: 'JWT: bound local and remote JWKS cache TTLs',
   skip: $supported === false,
   test: function () {
      /** @var array{
       *    first: array{private:string,public:string,jwk:array<string,string>,body:string},
       *    second: array{private:string,public:string,jwk:array<string,string>,body:string}
       * } $fixtures
       */
      $fixtures = require __DIR__ . '/fixtures/jwt_rs256.php';
      $first = $fixtures['first'];

      // @ Valid public mutation remains part of Remote's configuration API.
      $Mutable = new Remote(
         'https://issuer.example/mutable-ttl',
         static fn (): string => $first['body'],
         ttl: 60
      );
      $validMutation = false;
      $validMutationFailure = '';
      try {
         $Mutable->TTL = 7;
         $validMutation = $Mutable->TTL === 7;
      }
      catch (Throwable $Exception) {
         $validMutationFailure = $Exception::class . ': ' . $Exception->getMessage();
      }

      $maximumAccepted = false;
      $maximumFailure = '';
      try {
         $Maximum = new Remote(
            'https://issuer.example/maximum-ttl',
            static fn (): string => $first['body'],
            ttl: 31_536_000
         );
         $maximumAccepted = $Maximum->TTL === 31_536_000;
      }
      catch (Throwable $Exception) {
         $maximumFailure = $Exception::class . ': ' . $Exception->getMessage();
      }

      $ceilingRejected = false;
      $ceilingFailure = '';
      try {
         new Remote(
            'https://issuer.example/above-maximum-ttl',
            static fn (): string => $first['body'],
            ttl: 31_536_001
         );
      }
      catch (Throwable $Exception) {
         $ceilingRejected = $Exception instanceof InvalidArgumentException;
         $ceilingFailure = $Exception::class . ': ' . $Exception->getMessage();
      }

      // @ Neither construction nor later mutation may install a TTL whose
      //   absolute expiry would overflow PHP's integer range.
      $constructorRejected = false;
      $constructorFailure = '';
      try {
         new Remote(
            'https://issuer.example/constructor-overflow',
            static fn (): string => $first['body'],
            ttl: PHP_INT_MAX
         );
      }
      catch (Throwable $Exception) {
         $constructorRejected = $Exception instanceof InvalidArgumentException;
         $constructorFailure = $Exception::class . ': ' . $Exception->getMessage();
      }

      $mutationRejected = false;
      $mutationFailure = '';
      try {
         $Mutable->TTL = PHP_INT_MAX;
      }
      catch (Throwable $Exception) {
         $mutationRejected = $Exception instanceof InvalidArgumentException;
         $mutationFailure = $Exception::class . ': ' . $Exception->getMessage();
      }
      $validMutationPreserved = $Mutable->TTL === 7;

      $legacyNameRejected = false;
      $legacyNameFailure = '';
      $legacyName = 'ttl';
      try {
         $Mutable->{$legacyName} = 8;
      }
      catch (Throwable $Exception) {
         $legacyNameRejected = $Exception instanceof Error;
         $legacyNameFailure = $Exception::class . ': ' . $Exception->getMessage();
      }

      // @ A hostile remote max-age is clamped before both the process-local
      //   expiry and Vault's expiry are calculated. Exercise JWT::inspect(),
      //   not only Remote::fetch(), so the public declared return path is pinned.
      $path = sys_get_temp_dir() . '/bootgly-jwt2-' . bin2hex(random_bytes(8));
      $Clean = static function (string $path): void {
         if (is_dir($path) === false) {
            return;
         }

         $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
         );
         foreach ($Iterator as $Info) {
            $Info->isDir() ? rmdir($Info->getPathname()) : unlink($Info->getPathname());
         }
         rmdir($path);
      };

      $calls = 0;
      $failure = '';
      $Verification = null;
      $Cached = null;
      $inspectionSecure = false;
      $vaultReused = false;

      try {
         $Remote = new Remote(
            'https://issuer.example/overflowing-header',
            static function () use (&$calls, $first): Response {
               $calls++;

               return new Response(200, $first['body'], [
                  'Cache-Control' => 'public, max-age=9223372036854775807',
               ]);
            },
            ttl: 5
         );
         $Remote->cache(new Vault($path));

         $Signer = new JWT($first['private'], 'RS256');
         $Signer->select(new Key($first['private'], 'RS256', $first['jwk']['kid']));
         $token = $Signer->sign([
            'sub' => 'jwt-2-control',
            'exp' => time() + 60,
         ]);

         try {
            $Verifier = new JWT($Remote, 'RS256');
            $Verification = $Verifier->inspect($token);
         }
         catch (Throwable $Exception) {
            $failure = $Exception::class . ': ' . $Exception->getMessage();
         }

         $Peer = new Remote(
            'https://issuer.example/overflowing-header',
            static function () use (&$calls): string {
               $calls++;

               return '{"keys":[]}';
            },
            ttl: 5
         );
         $Peer->cache(new Vault($path));
         try {
            $Cached = $Peer->fetch();
         }
         catch (Throwable $Exception) {
            $failure .= ($failure === '' ? '' : ' | ')
               . $Exception::class . ': ' . $Exception->getMessage();
         }

         $inspectionSecure = $failure === ''
            && $Verification !== null
            && $Verification->valid === true
            && $Remote->expires - $Remote->fetched === 5;
         $vaultReused = $Cached instanceof KeySet && $calls === 1;
      }
      catch (Throwable $Exception) {
         $failure .= ($failure === '' ? '' : ' | ')
            . $Exception::class . ': ' . $Exception->getMessage();
      }
      finally {
         $Clean($path);
      }

      // @ Emit only after every JWT-2 path has run and Vault cleanup completed.
      yield assert(
         assertion: $constructorRejected
            && $mutationRejected
            && $validMutationPreserved
            && $maximumAccepted
            && $ceilingRejected
            && $legacyNameRejected
            && $inspectionSecure
            && $vaultReused,
         description: 'JWT-2 CONFIRMED: caller and remote TTLs must be bounded before expiry/cache arithmetic; evidence='
            . json_encode([
               'constructor' => $constructorFailure,
               'maximum' => $maximumFailure,
               'ceiling' => $ceilingFailure,
               'mutation' => $mutationFailure,
               'legacy_name' => $legacyNameFailure,
               'remote' => $failure,
               'calls' => $calls,
            ])
      );

      yield assert(
         assertion: $validMutation,
         description: 'JWT-2: a finite valid TTL mutation must remain supported'
            . ($validMutationFailure === '' ? '' : " ({$validMutationFailure})")
      );

      yield assert(
         assertion: $maximumAccepted && $ceilingRejected,
         description: 'JWT-2: the stable public TTL ceiling is exactly one year'
            . ($maximumFailure === '' && $ceilingFailure === ''
               ? ''
               : " ({$maximumFailure} | {$ceilingFailure})")
      );

      yield assert(
         assertion: $constructorRejected,
         description: 'JWT-2: a caller-supplied overflowing TTL must be rejected at construction'
            . ($constructorFailure === '' ? '' : " ({$constructorFailure})")
      );

      yield assert(
         assertion: $mutationRejected && $validMutationPreserved,
         description: 'JWT-2: an overflowing public TTL mutation must be rejected without replacing the valid value'
            . ($mutationFailure === '' ? '' : " ({$mutationFailure})")
      );

      yield assert(
         assertion: $legacyNameRejected,
         description: 'JWT-2: the former lowercase public ttl property is no longer externally writable'
            . ($legacyNameFailure === '' ? '' : " ({$legacyNameFailure})")
      );

      yield assert(
         assertion: $inspectionSecure,
         description: 'JWT-2: remote max-age must be clamped before JWT inspection and expiry arithmetic'
            . ($failure === '' ? '' : " ({$failure})")
      );

      yield assert(
         assertion: $vaultReused,
         description: 'JWT-2: the clamped JWKS must be persisted and reused through a real Vault'
      );
   }
);
