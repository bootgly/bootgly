<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\JWKS;
use Bootgly\API\Security\JWT\Key;


return new Specification(
   description: 'JWT: parse RSA JWKS key sets',
   test: function () {
      if (! function_exists('openssl_pkey_new')) {
         yield true;
         return;
      }

      $pack = static function (string $value): string {
         return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
      };
      $createRSA = static function () use ($pack): array {
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
            'alg' => 'RS256',
            'kid' => 'rsa1',
            'n' => $pack($details['rsa']['n']),
            'e' => $pack($details['rsa']['e']),
         ];

         return [$private, $details['key'], $jwk];
      };

      [$private, $public, $jwk] = $createRSA();

      // @ JWKS parses into a KeySet that verifies matching RS256 tokens.
      $Keys = JWKS::parse(['keys' => [$jwk]]);
      $Signer = new JWT($private, 'RS256');
      $Signer->select(new Key($private, 'RS256', 'rsa1'));
      $Verifier = new JWT($public, 'RS256');
      $Verifier->trust($Keys);

      $token = $Signer->sign([
         'sub' => 'jwks-user',
         'exp' => time() + 60,
      ]);
      $Verification = $Verifier->inspect($token);

      yield assert(
         assertion: $Verification->valid === true && $Verification->claims['sub'] === 'jwks-user',
         description: 'JWKS RSA key verifies RS256 token'
      );

      // @ Missing alg can use an explicit default.
      $NoAlg = $jwk;
      unset($NoAlg['alg']);
      $DefaultKeys = JWKS::parse(['keys' => [$NoAlg]], 'RS256');
      $DefaultVerifier = new JWT($public, 'RS256');
      $DefaultVerifier->trust($DefaultKeys);

      yield assert(
         assertion: $DefaultVerifier->inspect($token)->valid === true,
         description: 'JWKS key without alg uses explicit default algorithm'
      );

      // @ Missing alg without default is rejected.
      $failed = false;
      try {
         JWKS::parse(['keys' => [$NoAlg]]);
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed === true,
         description: 'JWKS key without alg and default is rejected'
      );

      // @ Private JWK fields are rejected.
      $PrivateJWK = $jwk;
      $PrivateJWK['d'] = 'private';
      $failed = false;
      try {
         JWKS::parse(['keys' => [$PrivateJWK]]);
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed === true,
         description: 'private RSA JWK is rejected'
      );

      // @ Duplicate kid is rejected.
      $failed = false;
      try {
         JWKS::parse(['keys' => [$jwk, $jwk]]);
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed === true,
         description: 'duplicate JWKS kid is rejected'
      );

      // @ Empty JWKS is rejected.
      $failed = false;
      try {
         JWKS::parse(['keys' => []]);
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed === true,
         description: 'empty JWKS is rejected'
      );
   }
);
