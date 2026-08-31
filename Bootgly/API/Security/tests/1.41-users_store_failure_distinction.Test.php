<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\UsersStoreFailureDistinction;


use const PASSWORD_BCRYPT;
use function assert;
use function count;
use function defined;
use function extension_loaded;
use function password_hash;
use function str_contains;
use RuntimeException;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Events;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\API\Security\Identity;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Users;


return new Test(
   description: 'Security: database failures are distinguishable from credential verdicts (USR-6/USR-4/USR-8)',
   skip: defined('PASSWORD_ARGON2ID') === false || extension_loaded('sqlite3') === false,
   test: function () {
      $SCHEMA = <<<SQL
      CREATE TABLE users (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         email TEXT NOT NULL UNIQUE CHECK (email <> 'refused@bootgly.com'),
         password TEXT NOT NULL,
         email_verified_at INTEGER DEFAULT NULL
      )
      SQL;
      $Password = new Password(memory: 19456, time: 2, threads: 1);

      // # A) An outage answers as an outage, never as a credential verdict
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->query($SCHEMA);
      $Users = new Users($Database, $Password);
      $id = $Users->enroll('ana@bootgly.com', 'secret-one');

      yield assert(
         assertion: $id !== null
            && $Users->check((string) $id, 'secret-one') === true
            && $Users->check((string) $id, 'wrong-guess') === false,
         description: 'control: genuine credential facts still answer as verdicts'
      );

      $Database->query('DROP TABLE users');

      $raised = 0;
      $verdicts = [];
      $calls = [
         fn () => $Users->check((string) $id, 'secret-one'),
         fn () => $Users->verify('ana@bootgly.com', 'secret-one'),
         fn () => $Users->fetch('ana@bootgly.com'),
         fn () => $Users->rotate((string) $id, 'secret-two'),
         fn () => $Users->confirm((string) $id),
         fn () => $Users->enroll('eve@bootgly.com', 'secret-three'),
      ];
      foreach ($calls as $call) {
         try {
            $verdicts[] = $call();
         }
         catch (RuntimeException $Exception) {
            if (str_contains($Exception->getMessage(), 'no such table')) {
               $raised++;
            }
         }
      }

      yield assert(
         assertion: $raised === 6 && $verdicts === [],
         description: 'an outage raises its real cause from every credential method — "wrong password" is never the answer to a database failure'
      );

      // # B) Only a unique-key collision is the documented duplicate answer
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->query($SCHEMA);
      $Users = new Users($Database, $Password);
      $first = $Users->enroll('dup@bootgly.com', 'secret-one');
      $second = $Users->enroll('dup@bootgly.com', 'secret-two');

      $checkRefused = null;
      try {
         $Users->enroll('refused@bootgly.com', 'secret-three');
      }
      catch (RuntimeException $Exception) {
         $checkRefused = $Exception->getMessage();
      }

      yield assert(
         assertion: $first !== null
            && $second === null
            && $checkRefused !== null
            && str_contains($checkRefused, 'CHECK constraint failed'),
         description: 'enroll() answers null only for a violated unique index — a sibling constraint class raises instead of reporting "already registered"'
      );

      // # C) USR-8 — the anticipated duplicate is fenced by a savepoint, so it
      //   cannot destroy the caller's unit of work, and the fence is dropped
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->query($SCHEMA);
      $Seed = new Users($Database, $Password);
      $taken = $Seed->enroll('taken@bootgly.com', 'taken-secret');

      $Transaction = $Database->begin();
      $Database->await($Transaction->Operation);
      $Invites = new Users($Transaction, $Password);

      $free1 = $Invites->enroll('inv-1@bootgly.com', 'invite-one');
      $depth1 = $Transaction->depth;
      $duplicate = $Invites->enroll('taken@bootgly.com', 'invite-two');
      $depth2 = $Transaction->depth;
      $free2 = $Invites->enroll('inv-2@bootgly.com', 'invite-three');
      $Inside = $Invites->verify('taken@bootgly.com', 'taken-secret');
      $insideCheck = $Invites->check((string) $taken, 'taken-secret');

      $Commit = $Database->await($Transaction->commit());
      $Count = $Database->await(
         $Database->query("SELECT count(*) AS n FROM users WHERE email LIKE 'inv-%'")
      );

      yield assert(
         assertion: $taken !== null
            && $free1 !== null
            && $duplicate === null
            && $depth1 === 1
            && $depth2 === 1
            && $free2 !== null
            && $Inside instanceof Identity
            && $insideCheck === true
            && $Commit->error === null
            && $Transaction->depth === 0
            && ($Count->rows[0]['n'] ?? null) === 2,
         description: 'a duplicate inside a Transaction stays a duplicate: the unit survives it, later verdicts stay honest, the savepoint fence is dropped and the commit is real'
      );

      // # D) USR-4 — the rehash-on-verify write is best-effort but observable
      // ! The Emitter instance is process-wide: observe on a private instance
      //   and restore the previous one whatever happens below.
      $PreviousEmitter = Emitter::$Instance;
      Emitter::$Instance = new Emitter;

      $observed = [];
      Emitter::$Instance->listen(Events::Failed, function (object $Emission) use (&$observed): void {
         $Operation = $Emission->payload[0] ?? null;

         if ($Operation instanceof Operation) {
            $observed[] = [$Operation->code, $Operation->error, $Operation->affected];
         }
      });

      try {
         // ! A recorded driver error on the upgrade write — announced by the
         //   driver's own failure path
         $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
         $Database->query($SCHEMA);
         $bcrypt = password_hash('legacy-secret', PASSWORD_BCRYPT);
         $Database->query(
            'INSERT INTO users (email, password) VALUES (?, ?)',
            ['legacy@bootgly.com', $bcrypt]
         );
         $Database->query(<<<SQL
         CREATE TRIGGER refuse_upgrade BEFORE UPDATE OF password ON users
         BEGIN
            SELECT RAISE(ABORT, 'password column is frozen');
         END
         SQL);
         $Legacy = new Users($Database, $Password);
         $Identity = $Legacy->verify('legacy@bootgly.com', 'legacy-secret');
         $stored = $Database->await(
            $Database->query('SELECT password FROM users WHERE email = ?', ['legacy@bootgly.com'])
         )->rows[0]['password'] ?? null;

         yield assert(
            assertion: $Identity instanceof Identity
               && $stored === $bcrypt
               && count($observed) === 1
               && $observed[0][1] !== null
               && str_contains((string) $observed[0][1], 'password column is frozen'),
            description: 'a failing policy upgrade never fails the login — and is announced through Events::Failed with its cause'
         );

         // ! A write that lands on no row carries no error — announced by the
         //   store itself
         $observed = [];
         $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
         $Database->query($SCHEMA);
         $Database->query(
            'INSERT INTO users (email, password) VALUES (?, ?)',
            ['quiet@bootgly.com', $bcrypt]
         );
         $Database->query(<<<SQL
         CREATE TRIGGER keep_password BEFORE UPDATE OF password ON users
         BEGIN
            SELECT RAISE(IGNORE);
         END
         SQL);
         $Quiet = new Users($Database, $Password);
         $Identity = $Quiet->verify('quiet@bootgly.com', 'legacy-secret');

         yield assert(
            assertion: $Identity instanceof Identity
               && count($observed) === 1
               && $observed[0][1] === null
               && $observed[0][2] === 0,
            description: 'an upgrade write that lands on no row is announced by the store even though the driver recorded no error'
         );
      }
      finally {
         Emitter::$Instance = $PreviousEmitter;
      }

      // # E) Events::Failed announces one failure once, with code and message
      //   paired even across a re-fail
      $PreviousEmitter = Emitter::$Instance;
      Emitter::$Instance = new Emitter;

      $announced = 0;
      Emitter::$Instance->listen(Events::Failed, function () use (&$announced): void {
         $announced++;
      });

      try {
         $Operation = new Operation(null, 'SELECT 1');
         $Operation->fail('first cause', '23505');
         $firstCode = $Operation->code;
         $Operation->fail('second cause');
         $refails = $announced;
         $refailCode = $Operation->code;
         $Operation->fail('third cause', '2067');
         $thirdCode = $Operation->code;
         // ! A fallback retry restarts from a clean pending state — a stale
         //   code on a later successful attempt would be a lie — and a failure
         //   of the NEW attempt is a new episode, announced again.
         $Operation->retry();
         $retriedCode = $Operation->code;
         $retriedError = $Operation->error;
         $Operation->fail('retried cause', '1062');

         yield assert(
            assertion: $announced === 2
               && $refails === 1
               && $firstCode === '23505'
               && $refailCode === null
               && $thirdCode === '2067'
               && $retriedCode === null
               && $retriedError === null
               && $Operation->code === '1062',
            description: 'one failure episode is announced once, the code always pairs with the current cause, a retry clears both, and the next episode announces again'
         );
      }
      finally {
         Emitter::$Instance = $PreviousEmitter;
      }
   }
);
