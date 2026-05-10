<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\Key;


return new Specification(
   description: 'JWT: sign and verify RS256 tokens',
   test: function () {
      if (! function_exists('openssl_pkey_new')) {
         yield true;
         return;
      }

      $createRSA = static function (int $bits = 2048): array {
         $Private = openssl_pkey_new([
            'private_key_bits' => $bits,
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
         if (is_array($details) === false || is_string($details['key'] ?? null) === false) {
            throw new RuntimeException('Could not inspect RSA test key.');
         }

         return [$private, $details['key']];
      };

      [$private, $public] = $createRSA();
      [$otherPrivate, $otherPublic] = $createRSA();

      // @ Valid RS256 token round-trip.
      $Signer = new JWT($private, 'RS256');
      $Signer->select(new Key($private, 'RS256', 'rsa1'));
      $Verifier = new JWT($public, 'RS256');
      $Verifier->select(new Key($public, 'RS256', 'rsa1'));

      yield assert(
         assertion: $Signer->secret === null && $Verifier->secret === null,
         description: 'RS256 JWT instances do not expose a shared secret mirror'
      );

      $token = $Signer->sign([
         'sub' => 'rsa-user',
         'exp' => time() + 60,
      ]);
      $Verification = $Verifier->inspect($token);

      yield assert(
         assertion: $Verification->valid === true,
         description: 'valid RS256 token verifies'
      );

      yield assert(
         assertion: $Verification->claims['sub'] === 'rsa-user',
         description: 'valid RS256 token returns claims'
      );

      yield assert(
         assertion: $Verification->Header?->id === 'rsa1',
         description: 'valid RS256 token exposes kid'
      );

      // @ Signature mismatch.
      $OtherVerifier = new JWT($otherPublic, 'RS256');
      $OtherVerifier->select(new Key($otherPublic, 'RS256', 'rsa1'));
      $Mismatch = $OtherVerifier->inspect($token);

      yield assert(
         assertion: $Mismatch->valid === false && $Mismatch->failure === Failures::Signature,
         description: 'RS256 public key mismatch is rejected'
      );

      // @ RS256 kid mismatch fails before signature trust.
      $WrongKid = new JWT($public, 'RS256');
      $WrongKid->select(new Key($public, 'RS256', 'rsa2'));
      $KidMismatch = $WrongKid->inspect($token);

      yield assert(
         assertion: $KidMismatch->valid === false && $KidMismatch->failure === Failures::Key,
         description: 'RS256 kid mismatch is rejected'
      );

      // @ Tampered token.
      $Tampered = $Verifier->inspect($token . 'x');

      yield assert(
         assertion: $Tampered->valid === false && $Tampered->failure === Failures::Signature,
         description: 'tampered RS256 token is rejected'
      );

      // @ RSA keys below 2048 bits are rejected.
      [$shortPrivate] = $createRSA(1024);
      $failed = false;
      try {
         new Key($shortPrivate, 'RS256', 'short');
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed === true,
         description: 'short RSA keys are rejected'
      );

      // @ Public keys cannot sign.
      $failed = false;
      try {
         (new JWT($public, 'RS256'))->sign(['sub' => 'invalid']);
      }
      catch (RuntimeException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed === true,
         description: 'RS256 signing requires private key material'
      );

      unset($otherPrivate);
   }
);
