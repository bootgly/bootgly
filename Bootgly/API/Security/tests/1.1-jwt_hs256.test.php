<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\JWT;


return new Specification(
   description: 'JWT: sign and verify HS256 tokens',
   test: function () {
      // @ Short secrets are rejected.
      $failed = false;
      try {
         new JWT('short-secret');
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed === true,
         description: 'short HS256 secret is rejected'
      );

      // ! JWT signer.
      $secret = 'bootgly-test-secret-32-bytes-long';
      $JWT = new JWT($secret);

      // @ JSON encoding failures are explicit.
      $failed = false;
      $Resource = fopen('php://memory', 'r');
      try {
         $JWT->sign(['resource' => $Resource]);
      }
      catch (RuntimeException) {
         $failed = true;
      }
      finally {
         if (is_resource($Resource)) {
            fclose($Resource);
         }
      }

      yield assert(
         assertion: $failed === true,
         description: 'JSON encoding failure throws RuntimeException'
      );

      // @ Valid token.
      $token = $JWT->sign([
         'sub' => 'user-1',
         'scope' => 'read',
         'exp' => time() + 60,
      ]);
      $claims = $JWT->verify($token);

      yield assert(
         assertion: $claims['sub'] === 'user-1',
         description: 'valid token returns claims'
      );

      yield assert(
         assertion: $claims['scope'] === 'read',
         description: 'valid token preserves custom claims'
      );

      // @ Tampered token.
      yield assert(
         assertion: $JWT->verify("{$token}x") === null,
         description: 'tampered token is rejected'
      );

      // @ Unsupported typ header.
      $pack = static function (string $value): string {
         return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
      };
      $header = $pack(json_encode(['typ' => 'JOSE', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
      $payload = $pack(json_encode(['sub' => 'user-1'], JSON_THROW_ON_ERROR));
      $data = "{$header}.{$payload}";
      $signature = $pack(hash_hmac('sha256', $data, $secret, true));

      yield assert(
         assertion: $JWT->verify("{$data}.{$signature}") === null,
         description: 'unsupported typ header is rejected'
      );

      // @ Expired token.
      $expired = $JWT->sign([
         'sub' => 'user-1',
         'exp' => time() - 1,
      ]);

      yield assert(
         assertion: $JWT->verify($expired) === null,
         description: 'expired token is rejected'
      );

      // @ Configured leeway accepts near clock skew.
      $LeewayJWT = new JWT($secret);
      $LeewayJWT->leeway = 5;
      $nearExpired = $LeewayJWT->sign([
         'sub' => 'user-1',
         'exp' => time() - 1,
      ]);
      $nearClaims = $LeewayJWT->verify($nearExpired);

      yield assert(
         assertion: $nearClaims['sub'] === 'user-1',
         description: 'configured leeway accepts near-expired token'
      );

      // @ Future nbf token.
      $future = $JWT->sign([
         'sub' => 'user-1',
         'nbf' => time() + 60,
      ]);

      yield assert(
         assertion: $JWT->verify($future) === null,
         description: 'future not-before token is rejected'
      );
   }
);
