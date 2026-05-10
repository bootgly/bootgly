<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\Key;
use Bootgly\API\Security\JWT\KeySet;


return new Specification(
   description: 'JWT: typed verification and key id resolution',
   test: function () {
      $secret = 'bootgly-test-secret-32-bytes-long';
      $pack = static function (string $value): string {
         return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
      };

      // @ inspect() returns typed trusted headers and claims.
      $JWT = new JWT($secret);
      $token = $JWT->sign([
         'sub' => 'user-1',
         'exp' => time() + 60,
      ]);
      $Verification = $JWT->inspect($token);

      yield assert(
         assertion: $Verification->valid === true,
         description: 'inspect returns a valid verification result'
      );

      yield assert(
         assertion: $Verification->failure === null,
         description: 'valid verification has no failure reason'
      );

      yield assert(
         assertion: $Verification->claims['sub'] === 'user-1',
         description: 'inspect exposes trusted claims'
      );

      yield assert(
         assertion: $Verification->Header?->algorithm === 'HS256',
         description: 'inspect exposes verified header algorithm'
      );

      $contentToken = $JWT->sign([
         'sub' => 'user-1',
         'exp' => time() + 60,
      ], ['cty' => 'application/json']);
      $Content = $JWT->inspect($contentToken);

      yield assert(
         assertion: $Content->Header?->content === 'application/json',
         description: 'inspect exposes verified header content type'
      );

      // @ kid="0" is a valid key id.
      $Keyed = new JWT($secret);
      $Keyed->select(new Key($secret, 'HS256', '0'));
      $keyedToken = $Keyed->sign(['sub' => 'user-0', 'exp' => time() + 60]);
      $KeyedVerification = $Keyed->inspect($keyedToken);

      yield assert(
         assertion: $KeyedVerification->valid === true && $KeyedVerification->Header?->id === '0',
         description: 'kid "0" is accepted and exposed'
      );

      // @ Missing kid becomes ambiguous when more than one key can verify.
      $Ambiguous = new JWT($secret);
      $ambiguousToken = $Ambiguous->sign(['sub' => 'user-1', 'exp' => time() + 60]);
      $Ambiguous->add(new Key('bootgly-second-secret-32-bytes-ok', 'HS256', 'second'));
      $AmbiguousVerification = $Ambiguous->inspect($ambiguousToken);

      yield assert(
         assertion: $AmbiguousVerification->valid === false && $AmbiguousVerification->failure === Failures::Key,
         description: 'missing kid is rejected when key resolution is ambiguous'
      );

      // @ A second default key must fail loudly instead of being ignored.
      $failed = false;
      try {
         (new KeySet(new Key($secret)))->add(new Key('bootgly-third-secret-32-bytes-long'));
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed === true,
         description: 'duplicate default keys are rejected'
      );

      // @ Invalid kid fails before signature trust.
      $Signer = new JWT($secret);
      $Signer->select(new Key($secret, 'HS256', 'bad'));
      $Verifier = new JWT($secret);
      $Verifier->select(new Key($secret, 'HS256', 'good'));
      $InvalidKid = $Verifier->inspect($Signer->sign(['sub' => 'user-1', 'exp' => time() + 60]));

      yield assert(
         assertion: $InvalidKid->valid === false && $InvalidKid->failure === Failures::Key,
         description: 'unknown kid is rejected'
      );

      // @ alg=none is never supported.
      $header = $pack(json_encode(['typ' => 'JWT', 'alg' => 'none'], JSON_THROW_ON_ERROR));
      $payload = $pack(json_encode(['sub' => 'user-1'], JSON_THROW_ON_ERROR));
      $None = $JWT->inspect("{$header}.{$payload}.signature");

      yield assert(
         assertion: $None->valid === false && $None->failure === Failures::Algorithm,
         description: 'alg none is rejected'
      );

      // @ JSON syntax errors have their own internal failure category.
      $JSONHeader = $pack('{');
      $JSON = $JWT->inspect("{$JSONHeader}.{$payload}.signature");

      yield assert(
         assertion: $JSON->valid === false && $JSON->failure === Failures::JSON,
         description: 'invalid JWT JSON is categorized'
      );

      // @ Algorithm confusion is rejected because the key algorithm must match.
      $header = $pack(json_encode(['typ' => 'JWT', 'alg' => 'RS256'], JSON_THROW_ON_ERROR));
      $payload = $pack(json_encode(['sub' => 'user-1'], JSON_THROW_ON_ERROR));
      $data = "{$header}.{$payload}";
      $signature = $pack(hash_hmac('sha256', $data, $secret, true));
      $Confused = $JWT->inspect("{$data}.{$signature}");

      yield assert(
         assertion: $Confused->valid === false && $Confused->failure === Failures::Key,
         description: 'algorithm confusion is rejected by key resolution'
      );
   }
);
