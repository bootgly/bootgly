<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\Policies;


return new Specification(
   description: 'JWT: enforce registered claim policies',
   test: function () {
      $secret = 'bootgly-test-secret-32-bytes-long';
      $now = 1700000000;
      $JWT = new JWT($secret);
      $JWT->freeze($now);
      $Policies = new Policies(
         issuers: 'https://issuer.bootgly.dev',
         audiences: ['api://bootgly-demo'],
         subject: true,
         identifier: true
      );

      // @ A token with all required policy claims verifies.
      $token = $JWT->sign([
         'iss' => 'https://issuer.bootgly.dev',
         'aud' => 'api://bootgly-demo',
         'sub' => 'user-42',
         'jti' => 'token-1',
         'exp' => $now + 60,
      ]);
      $Verification = $JWT->inspect($token, $Policies);

      yield assert(
         assertion: $Verification->valid === true && $Verification->claims['sub'] === 'user-42',
         description: 'valid policy claims pass verification'
      );

      yield assert(
         assertion: $JWT->verify($token)['sub'] === 'user-42',
         description: 'verify remains compatible without explicit policies'
      );

      // @ Audience arrays are accepted when one configured audience matches.
      $token = $JWT->sign([
         'iss' => 'https://issuer.bootgly.dev',
         'aud' => ['api://other', 'api://bootgly-demo'],
         'sub' => 'user-42',
         'jti' => 'token-2',
         'exp' => $now + 60,
      ]);
      $Audience = $JWT->inspect($token, $Policies);

      yield assert(
         assertion: $Audience->valid === true,
         description: 'audience array with matching value is accepted'
      );

      // @ Invalid issuer fails policy validation.
      $token = $JWT->sign([
         'iss' => 'https://issuer.other',
         'aud' => 'api://bootgly-demo',
         'sub' => 'user-42',
         'jti' => 'token-3',
         'exp' => $now + 60,
      ]);
      $Issuer = $JWT->inspect($token, $Policies);

      yield assert(
         assertion: $Issuer->valid === false && $Issuer->failure === Failures::Issuer,
         description: 'invalid issuer is rejected'
      );

      // @ Missing audience fails policy validation.
      $token = $JWT->sign([
         'iss' => 'https://issuer.bootgly.dev',
         'sub' => 'user-42',
         'jti' => 'token-4',
         'exp' => $now + 60,
      ]);
      $Audience = $JWT->inspect($token, $Policies);

      yield assert(
         assertion: $Audience->valid === false && $Audience->failure === Failures::Audience,
         description: 'missing audience is rejected'
      );

      // @ Invalid audience member types fail closed.
      $token = $JWT->sign([
         'iss' => 'https://issuer.bootgly.dev',
         'aud' => ['api://bootgly-demo', 42],
         'sub' => 'user-42',
         'jti' => 'token-5',
         'exp' => $now + 60,
      ]);
      $Audience = $JWT->inspect($token, $Policies);

      yield assert(
         assertion: $Audience->valid === false && $Audience->failure === Failures::Audience,
         description: 'invalid audience array members are rejected'
      );

      // @ Required subject must be a non-empty string.
      $token = $JWT->sign([
         'iss' => 'https://issuer.bootgly.dev',
         'aud' => 'api://bootgly-demo',
         'sub' => '',
         'jti' => 'token-6',
         'exp' => $now + 60,
      ]);
      $Subject = $JWT->inspect($token, $Policies);

      yield assert(
         assertion: $Subject->valid === false && $Subject->failure === Failures::Subject,
         description: 'empty subject is rejected'
      );

      // @ Required jti must be a non-empty string.
      $token = $JWT->sign([
         'iss' => 'https://issuer.bootgly.dev',
         'aud' => 'api://bootgly-demo',
         'sub' => 'user-42',
         'exp' => $now + 60,
      ]);
      $Identifier = $JWT->inspect($token, $Policies);

      yield assert(
         assertion: $Identifier->valid === false && $Identifier->failure === Failures::Identifier,
         description: 'missing jti is rejected'
      );

      // @ Temporal failures still happen before claim policies.
      $token = $JWT->sign([
         'iss' => 'https://issuer.other',
         'aud' => 'api://other',
         'sub' => 'user-42',
         'jti' => 'token-7',
         'exp' => $now - 1,
      ]);
      $Expired = $JWT->inspect($token, $Policies);

      yield assert(
         assertion: $Expired->valid === false && $Expired->failure === Failures::Expired,
         description: 'expiration is checked before claim policies'
      );

      // @ Timestamp override makes leeway deterministic.
      $Leeway = new JWT($secret);
      $Leeway->freeze($now);
      $Leeway->leeway = 5;
      $token = $Leeway->sign([
         'iss' => 'https://issuer.bootgly.dev',
         'aud' => 'api://bootgly-demo',
         'sub' => 'user-42',
         'jti' => 'token-8',
         'exp' => $now - 3,
      ]);

      yield assert(
         assertion: $Leeway->inspect($token, $Policies)->valid === true,
         description: 'timestamp override supports deterministic leeway checks'
      );

      $Leeway->resume();

      yield assert(
         assertion: $Leeway->timestamp === null,
         description: 'timestamp override can resume wall-clock checks'
      );

      // @ Invalid policy configuration fails early.
      $failed = false;
      try {
         new Policies(issuers: ['']);
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed === true,
         description: 'empty policy values are rejected'
      );
   }
);
