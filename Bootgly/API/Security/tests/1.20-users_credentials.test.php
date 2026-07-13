<?php

namespace Bootgly\API\Security\Tests\UsersCredentials;


use const PASSWORD_BCRYPT;
use function assert;
use function defined;
use function extension_loaded;
use function password_hash;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\Identity;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Users;


return new Specification(
   description: 'Security: credential store (enroll, verify, rotate, confirm)',
   skip: defined('PASSWORD_ARGON2ID') === false || extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->query(<<<SQL
      CREATE TABLE users (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         email TEXT NOT NULL UNIQUE,
         password TEXT NOT NULL,
         email_verified_at INTEGER DEFAULT NULL,
         created_at TEXT DEFAULT CURRENT_TIMESTAMP
      )
      SQL);

      // ! OWASP floor keeps the argon2id work factor test-friendly.
      $Password = new Password(memory: 19456, time: 2, threads: 1);
      $Users = new Users($Database, $Password);
      $Users->freeze(1000000);

      // @ Enrollment
      $user = $Users->enroll('ana@bootgly.com', 'secret-one');

      yield assert(
         assertion: $user === '1'
            && $Users->enroll('ana@bootgly.com', 'other') === null
            && $Users->enroll('', 'secret') === null
            && $Users->enroll('bob@bootgly.com', '') === null,
         description: 'enroll() returns the new id; duplicates and empty inputs fail'
      );

      // @ Verification
      $Identity = $Users->verify('ana@bootgly.com', 'secret-one');

      yield assert(
         assertion: $Identity instanceof Identity
            && $Identity->id === '1'
            && $Identity->claims['email'] === 'ana@bootgly.com'
            && $Identity->claims['verified'] === false,
         description: 'verify() returns an Identity with email and verified claims'
      );

      yield assert(
         assertion: $Users->verify('ana@bootgly.com', 'wrong') === null
            && $Users->verify('ghost@bootgly.com', 'secret-one') === null
            && $Users->verify('', 'secret-one') === null,
         description: 'wrong passwords and unknown identifiers fail uniformly'
      );

      // @ Legacy hash upgrade (rehash-on-verify)
      $legacy = password_hash('legacy-pass', PASSWORD_BCRYPT, ['cost' => 4]);
      $Database->query(
         'INSERT INTO users (email, password) VALUES ($1, $2)',
         ['bob@bootgly.com', $legacy]
      );
      $Upgraded = $Users->verify('bob@bootgly.com', 'legacy-pass');
      $stored = $Database
         ->query('SELECT password FROM users WHERE email = $1', ['bob@bootgly.com'])
         ->Result?->cell;

      yield assert(
         assertion: $Upgraded instanceof Identity
            && $stored !== $legacy
            && $Password->check((string) $stored) === true,
         description: 'a legacy bcrypt hash verifies and upgrades to the current policy'
      );

      // @ Current-password gate by id
      yield assert(
         assertion: $Users->check('1', 'secret-one') === true
            && $Users->check('1', 'wrong') === false
            && $Users->check('999', 'secret-one') === false,
         description: 'check() gates by account id and current password'
      );

      // @ fetch() looks up without credentials
      $Fetched = $Users->fetch('ana@bootgly.com');

      yield assert(
         assertion: $Fetched instanceof Identity
            && $Fetched->id === '1'
            && $Users->fetch('ghost@bootgly.com') === null,
         description: 'fetch() resolves an account by identifier without credentials'
      );

      // @ Password rotation
      yield assert(
         assertion: $Users->rotate('1', 'secret-two') === true
            && $Users->verify('ana@bootgly.com', 'secret-one') === null
            && $Users->verify('ana@bootgly.com', 'secret-two') instanceof Identity
            && $Users->rotate('999', 'whatever') === false,
         description: 'rotate() replaces the stored hash; the old password dies'
      );

      // @ E-mail confirmation stamp (idempotent, frozen clock)
      $confirmed = $Users->confirm('1');
      $stamp = $Database
         ->query('SELECT email_verified_at FROM users WHERE id = 1')
         ->Result?->cell;
      $Verified = $Users->verify('ana@bootgly.com', 'secret-two');

      yield assert(
         assertion: $confirmed === true
            && (int) $stamp === 1000000
            && $Users->confirm('1') === true
            && $Verified instanceof Identity
            && $Verified->claims['verified'] === true
            && $Users->confirm('999') === false,
         description: 'confirm() stamps the frozen epoch once and stays idempotent'
      );

      // @ Fail closed on database errors
      $Database->query('DROP TABLE users');

      yield assert(
         assertion: $Users->enroll('eve@bootgly.com', 'secret') === null
            && $Users->verify('ana@bootgly.com', 'secret-two') === null
            && $Users->check('1', 'secret-two') === false
            && $Users->fetch('ana@bootgly.com') === null
            && $Users->rotate('1', 'secret-three') === false
            && $Users->confirm('1') === false,
         description: 'database errors fail closed across the credential surface'
      );
   }
);
