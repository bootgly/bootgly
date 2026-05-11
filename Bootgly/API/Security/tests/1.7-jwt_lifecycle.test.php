<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\Replay;
use Bootgly\API\Security\JWT\Token;
use Bootgly\API\Security\JWT\Tokens;
use Bootgly\API\Security\JWT\Usage;
use Bootgly\API\Security\JWT\Vault;


return new Specification(
   description: 'JWT: manage refresh token lifecycle and persistent jti usage',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-jwt-phase5-' . bin2hex(random_bytes(4));
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

      $now = 1700000000;
      $Vault = new Vault($path);
      $Tokens = new Tokens($Vault);
      $Tokens->freeze($now);

      $Issued = $Tokens->mint('user-42', 60, ['role' => 'admin']);

      yield assert(
         assertion: $Issued->subject === 'user-42'
            && $Issued->claims['role'] === 'admin'
            && $Issued->expires === $now + 60
            && $Tokens->check($Issued->refresh) === true,
         description: 'refresh tokens are minted and stored with subject, claims and expiration'
      );

      $Worker = new Tokens(new Vault($path));
      $Worker->freeze($now);

      yield assert(
         assertion: $Worker->check($Issued->refresh) === true,
         description: 'file vault shares refresh token state across token managers'
      );

      $Rotated = $Worker->rotate($Issued->refresh, 60);

      yield assert(
         assertion: $Rotated instanceof Token
            && $Rotated->refresh !== $Issued->refresh
            && $Rotated->subject === 'user-42'
            && $Tokens->check($Issued->refresh) === false
            && $Tokens->check($Rotated->refresh) === true,
         description: 'refresh token rotation consumes the old token and stores the new one'
      );

      $Replay = $Tokens->rotate($Issued->refresh, 60);

      yield assert(
         assertion: $Replay instanceof Replay
            && $Replay->subject === 'user-42'
            && $Replay->claims['role'] === 'admin'
            && $Tokens->check($Rotated->refresh) === false,
         description: 'refresh token replay returns an incident and revokes the token family across workers'
      );

      $Logout = $Tokens->mint('user-99', 60);

      yield assert(
         assertion: $Tokens->revoke($Logout->refresh) === true
            && $Tokens->check($Logout->refresh) === false,
         description: 'refresh token revocation invalidates active families'
      );

      $BadVault = new Vault($path);
      $bad = $path . '/jwt_' . hash('sha256', 'bad-record');
      $temp = $path . '/jwt_.tmp.dead';
      file_put_contents($bad, 'bad');
      file_put_contents($temp, 'temp');
      $Read = $BadVault->read('bad-record');
      $Purged = $BadVault->purge();

      yield assert(
         assertion: $Read === null
            && is_file($bad) === false
            && is_file($temp) === false
            && $Purged === true,
         description: 'vault reads invalid records safely and purge removes invalid records plus temp files'
      );

      $secret = 'bootgly-test-secret-32-bytes-long';
      $Usage = new Usage(new Vault($path));
      $Verifier = new JWT($secret);
      $Verifier->freeze($now);
      $Verifier->track($Usage);
      $token = $Verifier->sign([
         'sub' => 'user-42',
         'jti' => 'access-1',
         'exp' => $now + 60,
      ]);

      yield assert(
         assertion: $Verifier->inspect($token)->valid === true,
         description: 'JWT usage guard allows non-revoked identifiers'
      );

      $Usage->block('access-1', 60);
      $Revoked = $Verifier->inspect($token);

      yield assert(
         assertion: $Revoked->valid === false && $Revoked->failure === Failures::Revoked,
         description: 'JWT usage guard rejects revoked jti values'
      );

      $Single = new Usage(new Vault($path), true);
      $Single->freeze($now);
      $Once = new JWT($secret);
      $Once->freeze($now);
      $Once->track($Single);
      $once = $Once->sign([
         'sub' => 'user-42',
         'jti' => 'access-once',
         'exp' => $now + 60,
      ]);
      $First = $Once->inspect($once);
      $Second = $Once->inspect($once);

      yield assert(
         assertion: $First->valid === true
            && $Second->valid === false
            && $Second->failure === Failures::Replay,
         description: 'single-use JWT usage guard rejects replayed jti values'
      );

      $Missing = new JWT($secret);
      $Missing->freeze($now);
      $Missing->track(new Usage(new Vault($path)));
      $missing = $Missing->sign([
         'sub' => 'user-42',
         'exp' => $now + 60,
      ]);

      yield assert(
         assertion: $Missing->inspect($missing)->failure === Failures::Identifier,
         description: 'JWT usage guard requires jti by default even outside single-use mode'
      );

      $Optional = new JWT($secret);
      $Optional->freeze($now);
      $Optional->track(new Usage(new Vault($path), required: false));

      yield assert(
         assertion: $Optional->inspect($missing)->valid === true,
         description: 'JWT usage guard can explicitly allow tokens without jti'
      );

      $clean($path);
   }
);
