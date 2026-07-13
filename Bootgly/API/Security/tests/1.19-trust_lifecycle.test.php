<?php

namespace Bootgly\API\Security\Tests\TrustLifecycle;


use function assert;
use function extension_loaded;
use function hash;
use function preg_match;
use function substr;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\Tokens\Theft;
use Bootgly\API\Security\Tokens\Token;
use Bootgly\API\Security\Tokens\Trust;


return new Specification(
   description: 'Security: persistent-login (remember-me) trust token lifecycle',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->query(<<<SQL
      CREATE TABLE trusts (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         selector TEXT NOT NULL UNIQUE,
         verifier TEXT NOT NULL,
         user_id INTEGER NOT NULL,
         expires INTEGER NOT NULL,
         created_at TEXT DEFAULT CURRENT_TIMESTAMP
      )
      SQL);

      $count = static function () use ($Database): mixed {
         return $Database->query('SELECT count(*) AS total FROM trusts')->Result?->cell;
      };

      $Trust = new Trust($Database);
      $Trust->freeze(1000000);

      // @ Issue a device series
      $Issued = $Trust->issue('7', ttl: 3600);
      $stored = $Database
         ->query('SELECT verifier FROM trusts WHERE selector = $1', [$Issued->selector])
         ->Result?->cell;

      yield assert(
         assertion: preg_match('/\A[0-9a-f]{16}\.[0-9a-f]{64}\z/', $Issued->value) === 1
            && $Issued->user === '7'
            && $Issued->Purpose === null
            && $Issued->expires === 1000000 + 3600
            && $stored === hash('sha256', substr($Issued->value, 17)),
         description: 'issue() starts a series persisting only the validator digest'
      );

      // @ Rotate: series stable, validator replaced, old value dead
      $Rotated = $Trust->rotate($Issued->value, ttl: 3600);

      yield assert(
         assertion: $Rotated instanceof Token
            && $Rotated->selector === $Issued->selector
            && $Rotated->value !== $Issued->value
            && $Rotated->user === '7'
            && $count() === 1,
         description: 'rotate() keeps the series and replaces the validator'
      );

      // @ Replay of the pre-rotation value = stolen-cookie signature
      $Trust->issue('7', ttl: 3600); // second device of the same user
      $Incident = $Trust->rotate($Issued->value);

      yield assert(
         assertion: $Incident instanceof Theft
            && $Incident->user === '7'
            && $count() === 0,
         description: 'replaying a rotated validator revokes ALL user series and reports Theft'
      );

      // @ Unknown series and malformed tokens are rejected quietly
      yield assert(
         assertion: $Trust->rotate($Issued->value) === null
            && $Trust->rotate('not-a-token') === null
            && $Trust->forget('not-a-token') === false,
         description: 'unknown series and malformed tokens are rejected'
      );

      // @ Expired series is purged on contact
      $Expiring = $Trust->issue('7', ttl: 60);
      $Trust->freeze(1000000 + 61);

      yield assert(
         assertion: $Trust->rotate($Expiring->value) === null
            && $count() === 0,
         description: 'an expired series fails rotate() and is purged'
      );

      // @ forget() drops only the presented device
      $Trust->freeze(1000000);
      $First = $Trust->issue('7');
      $Second = $Trust->issue('7');

      yield assert(
         assertion: $Trust->forget($First->value) === true
            && $Trust->forget($First->value) === false
            && $count() === 1
            && $Trust->rotate($Second->value) instanceof Token,
         description: 'forget() drops the presented series only'
      );

      // @ forget() with a wrong validator does not drop the row
      $Third = $Trust->issue('8');
      $wrong = "{$Third->selector}." . substr($First->value, 17);

      yield assert(
         assertion: $Trust->forget($wrong) === false
            && $Trust->revoke('8') === 1,
         description: 'forget() requires the matching validator; revoke() drops by user'
      );

      // @ sweep() drops only expired series
      $Trust->issue('9', ttl: 60);
      $Trust->freeze(1000000 + 61);

      yield assert(
         assertion: $Trust->sweep() === 1
            && $count() === 1,
         description: 'sweep() drops only expired series'
      );

      // @ Fail closed on database errors
      $Database->query('DROP TABLE trusts');
      $thrown = false;
      try {
         $Trust->issue('7');
      }
      catch (RuntimeException) {
         $thrown = true;
      }

      yield assert(
         assertion: $thrown === true
            && $Trust->rotate($Second->value) === null
            && $Trust->forget($Second->value) === false
            && $Trust->revoke('7') === 0
            && $Trust->sweep() === 0,
         description: 'database errors fail closed (issue throws; reads/writes reject)'
      );
   }
);
