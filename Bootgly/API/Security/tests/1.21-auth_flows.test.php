<?php

namespace Bootgly\API\Security\Tests\AuthFlows;


use function assert;
use function defined;
use function extension_loaded;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\Identity;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Token;
use Bootgly\API\Security\Tokens\Trust;
use Bootgly\API\Security\Users;


return new Specification(
   description: 'Security: e-mail verification and password recovery flows end-to-end',
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

      $Password = new Password(memory: 19456, time: 2, threads: 1);
      $Users = new Users($Database, $Password);
      $Tokens = new Tokens($Database);
      $Trust = new Trust($Database);

      // @ Registration → e-mail verification
      $user = $Users->enroll('ana@bootgly.com', 'secret-one');

      yield assert(
         assertion: $user === '1',
         description: 'registration enrolls the account'
      );

      $Verification = $Tokens->mint($user, Purposes::Verification, ttl: 86400);

      yield assert(
         assertion: $Tokens->redeem($Verification->value, Purposes::Verification) === $user
            && $Users->confirm($user) === true
            && $Users->fetch('ana@bootgly.com')?->claims['verified'] === true
            && $Tokens->redeem($Verification->value, Purposes::Verification) === null,
         description: 'the verification link redeems once and stamps the account'
      );

      // @ Login + trusted device (remember-me)
      $Login = $Users->verify('ana@bootgly.com', 'secret-one');
      $Device = $Trust->issue((string) $Login?->id);

      yield assert(
         assertion: $Login instanceof Identity
            && $Trust->rotate($Device->value) instanceof Token,
         description: 'login verifies credentials and the trusted device rotates'
      );

      // @ Password recovery
      $Recovery = $Tokens->mint($user, Purposes::Recovery, ttl: 3600);
      $Pending = $Tokens->mint($user, Purposes::Verification); // unconsumed leftover

      yield assert(
         assertion: $Tokens->check($Recovery->value, Purposes::Recovery) === true
            && $Tokens->redeem($Recovery->value, Purposes::Recovery) === $user,
         description: 'the recovery link peeks valid and redeems to the owner'
      );

      // @ Rotation + full invalidation (the documented orchestration contract)
      $rotated = $Users->rotate($user, 'secret-two');
      $Tokens->revoke($user);
      $Trust->revoke($user);

      yield assert(
         assertion: $rotated === true
            && $Users->verify('ana@bootgly.com', 'secret-one') === null
            && $Users->verify('ana@bootgly.com', 'secret-two') instanceof Identity
            && $Tokens->redeem($Pending->value, Purposes::Verification) === null
            && $Trust->rotate($Device->value) === null,
         description: 'password rotation kills old credentials, pending tokens and trusted devices'
      );
   }
);
