<?php

namespace Bootgly\API\Security\Tests\TokensLifecycle;


use function assert;
use function extension_loaded;
use function hash;
use function preg_match;
use function substr;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;


return new Specification(
   description: 'Security: single-use action tokens (selector + verifier) lifecycle',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->query(<<<SQL
      CREATE TABLE tokens (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         selector TEXT NOT NULL UNIQUE,
         verifier TEXT NOT NULL,
         user_id INTEGER NOT NULL,
         purpose TEXT NOT NULL,
         expires INTEGER NOT NULL,
         created_at TEXT DEFAULT CURRENT_TIMESTAMP
      )
      SQL);

      $count = static function () use ($Database): mixed {
         return $Database->query('SELECT count(*) AS total FROM tokens')->Result?->cell;
      };
      $tamper = static function (string $value): string {
         return substr($value, 0, -1) . ($value[-1] === 'a' ? 'b' : 'a');
      };

      $Tokens = new Tokens($Database);
      $Tokens->freeze(1000000);

      // @ Wire format + storage at rest
      $Token = $Tokens->mint('7', Purposes::Recovery, ttl: 3600);

      yield assert(
         assertion: preg_match('/\A[0-9a-f]{16}\.[0-9a-f]{64}\z/', $Token->value) === 1
            && $Token->value === "{$Token->selector}." . substr($Token->value, 17)
            && $Token->user === '7'
            && $Token->Purpose === Purposes::Recovery
            && $Token->expires === 1000000 + 3600,
         description: 'mint() returns a 16-hex selector + 64-hex verifier snapshot'
      );

      $stored = $Database
         ->query('SELECT verifier FROM tokens WHERE selector = $1', [$Token->selector])
         ->Result?->cell;

      yield assert(
         assertion: $stored === hash('sha256', substr($Token->value, 17)),
         description: 'only the sha256 digest of the verifier is persisted'
      );

      // @ Tampered verifier never consumes the row
      yield assert(
         assertion: $Tokens->redeem($tamper($Token->value), Purposes::Recovery) === null
            && $count() === 1,
         description: 'tampered verifier is rejected and the row survives'
      );

      // @ Wrong purpose is rejected
      yield assert(
         assertion: $Tokens->redeem($Token->value, Purposes::Verification) === null,
         description: 'a recovery token does not redeem as verification'
      );

      // @ check() peeks without consuming
      yield assert(
         assertion: $Tokens->check($Token->value, Purposes::Recovery) === true
            && $Tokens->check($Token->value, Purposes::Recovery) === true
            && $count() === 1,
         description: 'check() validates without consuming'
      );

      // @ Single use
      yield assert(
         assertion: $Tokens->redeem($Token->value, Purposes::Recovery) === '7'
            && $count() === 0,
         description: 'redeem() returns the owner and consumes the row'
      );

      yield assert(
         assertion: $Tokens->redeem($Token->value, Purposes::Recovery) === null,
         description: 'a second redeem of the same token fails'
      );

      // @ Malformed tokens are rejected without SQL errors
      yield assert(
         assertion: $Tokens->redeem('not-a-token', Purposes::Recovery) === null
            && $Tokens->check('aaaa.bbbb', Purposes::Recovery) === false
            && $Tokens->redeem('', Purposes::Recovery) === null,
         description: 'malformed tokens are rejected'
      );

      // @ Unknown selector (valid format) is rejected
      $unknown = $tamper($Token->selector) . '.' . substr($Token->value, 17);

      yield assert(
         assertion: $Tokens->redeem($unknown, Purposes::Recovery) === null,
         description: 'an unknown selector is rejected through the decoy compare'
      );

      // @ Minting supersedes the previous token of the same user + purpose
      $First = $Tokens->mint('7', Purposes::Verification);
      $Second = $Tokens->mint('7', Purposes::Verification);

      yield assert(
         assertion: $count() === 1
            && $Tokens->redeem($First->value, Purposes::Verification) === null
            && $Tokens->redeem($Second->value, Purposes::Verification) === '7',
         description: 'mint() supersedes any live token of the same user + purpose'
      );

      // @ Frozen-clock expiration purges on contact
      $Expiring = $Tokens->mint('7', Purposes::Recovery, ttl: 60);
      $Tokens->freeze(1000000 + 61);

      yield assert(
         assertion: $Tokens->check($Expiring->value, Purposes::Recovery) === false
            && $Tokens->redeem($Expiring->value, Purposes::Recovery) === null
            && $count() === 0,
         description: 'expired tokens fail check(), fail redeem() and are purged'
      );

      // @ revoke() by user and by user + purpose
      $Tokens->freeze(1000000);
      $Tokens->mint('7', Purposes::Recovery);
      $Tokens->mint('7', Purposes::Verification);
      $Tokens->mint('8', Purposes::Recovery);

      yield assert(
         assertion: $Tokens->revoke('7', Purposes::Recovery) === 1
            && $Tokens->revoke('7') === 1
            && $count() === 1,
         description: 'revoke() drops tokens by user and by user + purpose'
      );

      // @ sweep() drops only expired rows
      $Tokens->mint('9', Purposes::Recovery, ttl: 60);
      $Tokens->freeze(1000000 + 61);

      yield assert(
         assertion: $Tokens->sweep() === 1
            && $count() === 1,
         description: 'sweep() drops only expired rows'
      );

      // @ Fail closed on database errors
      $Database->query('DROP TABLE tokens');
      $Valid = "{$Token->selector}." . substr($Token->value, 17);
      $thrown = false;
      try {
         $Tokens->mint('7', Purposes::Recovery);
      }
      catch (RuntimeException) {
         $thrown = true;
      }

      yield assert(
         assertion: $thrown === true
            && $Tokens->redeem($Valid, Purposes::Recovery) === null
            && $Tokens->check($Valid, Purposes::Recovery) === false
            && $Tokens->revoke('7') === 0
            && $Tokens->sweep() === 0,
         description: 'database errors fail closed (mint throws; reads/writes reject)'
      );
   }
);
