<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\Key;
use Bootgly\API\Security\JWT\KeySet;
use Bootgly\API\Security\JWT\Remote;
use Bootgly\API\Security\JWT\Remote\Response;
use Bootgly\API\Security\JWT\Vault;


return new Specification(
   description: 'JWT: fetch remote JWKS key sets with cache and refresh',
   test: function () {
      if (! function_exists('openssl_pkey_new')) {
         yield true;
         return;
      }

      $pack = static function (string $value): string {
         return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
      };
      $encode = static function (array $jwks): string {
         return json_encode($jwks, JSON_THROW_ON_ERROR);
      };
      $createRSA = static function (string $id) use ($pack): array {
         $Private = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
         ]);
         if ($Private === false) {
            throw new RuntimeException('Could not generate RSA test key.');
         }

         $private = '';
         if (openssl_pkey_export($Private, $private) === false) {
            throw new RuntimeException('Could not export RSA test key.');
         }

         $details = openssl_pkey_get_details($Private);
         if (
            is_array($details) === false
            || is_string($details['key'] ?? null) === false
            || is_array($details['rsa'] ?? null) === false
            || is_string($details['rsa']['n'] ?? null) === false
            || is_string($details['rsa']['e'] ?? null) === false
         ) {
            throw new RuntimeException('Could not inspect RSA test key.');
         }

         $jwk = [
            'kty' => 'RSA',
            'kid' => $id,
            'n' => $pack($details['rsa']['n']),
            'e' => $pack($details['rsa']['e']),
         ];

         return [$private, $details['key'], $jwk];
      };

      [$private1, , $jwk1] = $createRSA('rsa1');
      [$private2, , $jwk2] = $createRSA('rsa2');

      $documents = [
         ['keys' => [$jwk1]],
         ['keys' => [$jwk1, $jwk2]],
      ];
      $calls = 0;
      $Remote = new Remote(
         'https://issuer.example/jwks',
         static function (string $URI) use (&$calls, $documents, $encode): string {
            $calls++;
            return $encode($documents[$calls === 1 ? 0 : 1]);
         },
         cooldown: 0
      );

      $Signer = new JWT($private1, 'RS256');
      $Signer->select(new Key($private1, 'RS256', 'rsa1'));
      $Verifier = new JWT($Remote, 'RS256');

      $failed = false;
      try {
         $Verifier->sign(['sub' => 'remote-user']);
      }
      catch (RuntimeException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed === true,
         description: 'remote resolver JWT instances are verifier-only until a signing key is selected'
      );

      $token = $Signer->sign([
         'sub' => 'remote-user',
         'exp' => time() + 60,
      ]);
      $Verification = $Verifier->inspect($token);

      yield assert(
         assertion: $Verification->valid === true && $Verification->claims['sub'] === 'remote-user',
         description: 'remote JWKS verifies an RS256 token'
      );

      $Cached = $Verifier->inspect($token);

      yield assert(
         assertion: $Cached->valid === true && $calls === 1,
         description: 'remote JWKS cache avoids a second fetch'
      );

      $path = sys_get_temp_dir() . '/bootgly-jwks-cache-' . bin2hex(random_bytes(4));
      $clean = static function (string $path): void {
         foreach (glob($path . '/*') ?: [] as $file) {
            if (is_file($file)) {
               unlink($file);
            }
         }
         foreach (glob($path . '/.*') ?: [] as $file) {
            if (basename($file) !== '.' && basename($file) !== '..' && is_file($file)) {
               unlink($file);
            }
         }
         if (is_dir($path)) {
            rmdir($path);
         }
      };
      $sharedCalls = 0;
      $SharedA = new Remote(
         'https://issuer.example/shared',
         static function () use (&$sharedCalls, $documents, $encode): string {
            $sharedCalls++;
            return $encode($documents[0]);
         }
      );
      $SharedA->cache(new Vault($path));
      $SharedB = new Remote(
         'https://issuer.example/shared',
         static function () use (&$sharedCalls): string {
            $sharedCalls++;
            return '{"keys":[]}';
         }
      );
      $SharedB->cache(new Vault($path));
      $SharedFetch = $SharedA->fetch();
      $SharedCached = $SharedB->fetch();

      yield assert(
         assertion: $SharedFetch instanceof KeySet
            && $SharedCached instanceof KeySet
            && $sharedCalls === 1,
         description: 'remote JWKS shared cache avoids a cross-worker refetch'
      );

      $clean($path);

      $Rotated = new JWT($private2, 'RS256');
      $Rotated->select(new Key($private2, 'RS256', 'rsa2'));
      $rotatedToken = $Rotated->sign([
         'sub' => 'rotated-user',
         'exp' => time() + 60,
      ]);
      $RotatedVerification = $Verifier->inspect($rotatedToken);

      yield assert(
         assertion: $RotatedVerification->valid === true
            && $RotatedVerification->claims['sub'] === 'rotated-user'
            && $calls === 2,
         description: 'unknown kid refreshes remote JWKS once and verifies rotated keys'
      );

      $Refreshed = $Remote->refresh();

      yield assert(
         assertion: $Refreshed instanceof KeySet && $calls === 3,
         description: 'remote JWKS can be refreshed explicitly'
      );

      $Status = new Remote(
         'https://issuer.example/status',
         static fn (): Response => new Response(500, '{}')
      );
      $StatusVerifier = new JWT($Status, 'RS256');
      $StatusVerification = $StatusVerifier->inspect($token);

      yield assert(
         assertion: $StatusVerification->valid === false
            && $StatusVerification->failure === Failures::Status
            && $Status->status === 500,
         description: 'remote JWKS HTTP status failures are exposed internally'
      );

      $InvalidJSON = new Remote(
         'https://issuer.example/json',
         static fn (): string => '{'
      );

      yield assert(
         assertion: $InvalidJSON->fetch() === Failures::JSON
            && $InvalidJSON->failure === Failures::JSON,
         description: 'remote JWKS invalid JSON is categorized'
      );

      $InvalidJWKS = new Remote(
         'https://issuer.example/invalid',
         static fn (): string => '{"keys":[]}'
      );

      yield assert(
         assertion: $InvalidJWKS->fetch() === Failures::JWKS
            && $InvalidJWKS->failure === Failures::JWKS,
         description: 'remote JWKS structural failures are categorized'
      );

      $Duplicate = new Remote(
         'https://issuer.example/duplicate',
         static fn (): string => $encode(['keys' => [$jwk1, $jwk1]])
      );

      yield assert(
         assertion: $Duplicate->fetch() === Failures::JWKS,
         description: 'remote JWKS duplicate kid is rejected'
      );

      $Large = new Remote(
         'https://issuer.example/large',
         static fn (): string => str_repeat('x', 32),
         size: 8
      );

      yield assert(
         assertion: $Large->fetch() === Failures::JWKS,
         description: 'remote JWKS body size is bounded'
      );

      $blocked = false;
      try {
         new Remote('http://issuer.example/jwks', static fn (): string => '{}');
      }
      catch (InvalidArgumentException) {
         $blocked = true;
      }

      yield assert(
         assertion: $blocked === true,
         description: 'remote JWKS requires HTTPS by default'
      );

      $Allowed = new Remote(
         'http://issuer.example/jwks',
         static fn (): string => $encode(['keys' => [$jwk1]]),
         insecure: true
      );

      yield assert(
         assertion: $Allowed->fetch() instanceof KeySet,
         description: 'remote JWKS can explicitly allow insecure HTTP for tests'
      );
   }
);
