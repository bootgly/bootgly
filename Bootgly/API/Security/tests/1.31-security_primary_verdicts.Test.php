<?php

namespace Bootgly\API\Security\Tests\SecurityPrimaryVerdicts;


use const JSON_THROW_ON_ERROR;
use const PASSWORD_BCRYPT;
use const SQLITE3_INTEGER;
use const SQLITE3_TEXT;
use function array_merge;
use function assert;
use function bin2hex;
use function copy;
use function defined;
use function extension_loaded;
use function gc_collect_cycles;
use function glob;
use function hash;
use function is_file;
use function is_int;
use function json_encode;
use function mkdir;
use function password_hash;
use function random_bytes;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;
use RuntimeException;
use SQLite3;
use SQLite3Result;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\Identity;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Theft;
use Bootgly\API\Security\Tokens\Token;
use Bootgly\API\Security\Tokens\Trust;
use Bootgly\API\Security\Users;


return new Test(
   description: 'Security: credential and token verdicts bypass stale SQL replicas',
   skip: extension_loaded('sqlite3') === false || defined('PASSWORD_ARGON2ID') === false,
   test: function () {
      $directory = sys_get_temp_dir() . '/bootgly-tok8-' . bin2hex(random_bytes(8));

      if (mkdir($directory, 0700) === false) {
         throw new RuntimeException('TOK-8 fixture could not create its temporary directory.');
      }

      $primary = "{$directory}/primary.sqlite";
      $replica = "{$directory}/replica.sqlite";
      $Database = null;
      $Fixture = null;
      $Observer = null;
      $Evidence = [];

      try {
         $email = 'primary-verdict@bootgly.test';
         $currentPassword = 'current-password';
         $stalePassword = 'stale-password';
         $currentHash = password_hash($currentPassword, PASSWORD_BCRYPT, ['cost' => 4]);
         $staleHash = password_hash($stalePassword, PASSWORD_BCRYPT, ['cost' => 4]);
         $trustSelector = '1111111111111111';
         $deviceSelector = '2222222222222222';
         $transactionSelector = '3333333333333333';
         $tokenSelector = '4444444444444444';
         $primaryTokenSelector = '5555555555555555';
         $oldTrustValidator = str_repeat('a', 64);
         $currentTrustValidator = str_repeat('b', 64);
         $deviceValidator = str_repeat('c', 64);
         $transactionValidator = str_repeat('d', 64);
         $tokenValidator = str_repeat('e', 64);
         $primaryTokenValidator = str_repeat('6', 64);
         $unknownToken = str_repeat('f', 16) . '.' . str_repeat('f', 64);

         /**
          * Execute one fixture statement with explicitly typed parameters.
          *
          * @param array<string,int|string> $parameters
          */
         $Execute = static function (SQLite3 $Database, string $SQL, array $parameters): void {
            $Statement = $Database->prepare($SQL);

            if ($Statement === false) {
               throw new RuntimeException('TOK-8 fixture could not prepare a SQLite statement.');
            }

            foreach ($parameters as $name => $value) {
               $bound = $Statement->bindValue(
                  $name,
                  $value,
                  is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT
               );

               if ($bound === false) {
                  $Statement->close();

                  throw new RuntimeException('TOK-8 fixture could not bind a SQLite value.');
               }
            }

            $Result = $Statement->execute();

            if ($Result === false) {
               $Statement->close();

               throw new RuntimeException('TOK-8 fixture could not execute a SQLite statement.');
            }

            if ($Result instanceof SQLite3Result) {
               $Result->finalize();
            }

            $Statement->close();
         };

         // @ Build the state that a replica captured before the primary
         //   accepted a password change, revocation and trust rotation.
         $Fixture = new SQLite3($primary);
         $Fixture->enableExceptions(true);
         $Fixture->exec(<<<SQL
         CREATE TABLE routing_probe (
            marker TEXT NOT NULL
         );
         CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            email_verified_at INTEGER DEFAULT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
         );
         CREATE TABLE tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            selector TEXT NOT NULL UNIQUE,
            verifier TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            purpose TEXT NOT NULL,
            expires INTEGER NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
         );
         CREATE TABLE trusts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            selector TEXT NOT NULL UNIQUE,
            verifier TEXT NOT NULL,
            previous TEXT DEFAULT NULL,
            rotated INTEGER DEFAULT NULL,
            user_id INTEGER NOT NULL,
            expires INTEGER NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
         );
         SQL);
         $Execute($Fixture, 'INSERT INTO routing_probe (marker) VALUES (:marker)', [
            ':marker' => 'replica',
         ]);
         $Execute(
            $Fixture,
            'INSERT INTO users (id, email, password) VALUES (:id, :email, :password)',
            [
               ':id' => 1,
               ':email' => $email,
               ':password' => $staleHash,
            ]
         );
         $Execute(
            $Fixture,
            'INSERT INTO tokens (id, selector, verifier, user_id, purpose, expires) '
               . 'VALUES (:id, :selector, :verifier, :user, :purpose, :expires)',
            [
               ':id' => 1,
               ':selector' => $tokenSelector,
               ':verifier' => hash('sha256', $tokenValidator),
               ':user' => 7,
               ':purpose' => Purposes::Recovery->value,
               ':expires' => 1003600,
            ]
         );
         $Execute(
            $Fixture,
            'INSERT INTO trusts (id, selector, verifier, user_id, expires) '
               . 'VALUES (:id, :selector, :verifier, :user, :expires)',
            [
               ':id' => 1,
               ':selector' => $trustSelector,
               ':verifier' => hash('sha256', $oldTrustValidator),
               ':user' => 7,
               ':expires' => 1003600,
            ]
         );
         $Execute(
            $Fixture,
            'INSERT INTO trusts (id, selector, verifier, user_id, expires) '
               . 'VALUES (:id, :selector, :verifier, :user, :expires)',
            [
               ':id' => 2,
               ':selector' => $deviceSelector,
               ':verifier' => hash('sha256', $deviceValidator),
               ':user' => 7,
               ':expires' => 1003600,
            ]
         );
         $Execute(
            $Fixture,
            'INSERT INTO trusts (id, selector, verifier, user_id, expires) '
               . 'VALUES (:id, :selector, :verifier, :user, :expires)',
            [
               ':id' => 3,
               ':selector' => $transactionSelector,
               ':verifier' => hash('sha256', $transactionValidator),
               ':user' => 8,
               ':expires' => 1003600,
            ]
         );
         $Fixture->close();
         $Fixture = null;

         if (copy($primary, $replica) === false) {
            throw new RuntimeException('TOK-8 fixture could not snapshot the stale replica.');
         }

         // @ Advance only the primary. The copied database remains a coherent
         //   but stale view, rather than an artificial malformed row fixture.
         $Fixture = new SQLite3($primary);
         $Fixture->enableExceptions(true);
         $Execute($Fixture, 'UPDATE routing_probe SET marker = :marker', [
            ':marker' => 'primary',
         ]);
         $Execute(
            $Fixture,
            'UPDATE users SET password = :password, email_verified_at = :verified WHERE id = :id',
            [
               ':password' => $currentHash,
               ':verified' => 1000000,
               ':id' => 1,
            ]
         );
         $Execute($Fixture, 'DELETE FROM tokens WHERE id = :id', [':id' => 1]);
         $Execute(
            $Fixture,
            'INSERT INTO tokens (id, selector, verifier, user_id, purpose, expires) '
               . 'VALUES (:id, :selector, :verifier, :user, :purpose, :expires)',
            [
               ':id' => 2,
               ':selector' => $primaryTokenSelector,
               ':verifier' => hash('sha256', $primaryTokenValidator),
               ':user' => 7,
               ':purpose' => Purposes::Recovery->value,
               ':expires' => 1003600,
            ]
         );
         $Execute(
            $Fixture,
            'UPDATE trusts SET verifier = :verifier, previous = :previous, rotated = :rotated '
               . 'WHERE id = :id',
            [
               ':verifier' => hash('sha256', $currentTrustValidator),
               ':previous' => hash('sha256', $oldTrustValidator),
               ':rotated' => 1000000,
               ':id' => 1,
            ]
         );
         $Fixture->close();
         $Fixture = null;

         $Observer = new SQLite3($primary);
         $Observer->enableExceptions(true);
         $Database = new class ([
            'driver' => 'sqlite',
            'database' => $primary,
            'timeout' => 1.0,
            'statements' => 0,
            'pool' => ['min' => 0, 'max' => 1],
            'routing' => ['sticky' => 30.0],
            'replicas' => [[
               'driver' => 'sqlite',
               'host' => 'stale-replica.local',
               'database' => $replica,
               'pool' => ['min' => 0, 'max' => 1],
            ]],
         ]) extends SQL {
            public int $touches = 0;

            public function touch (null|object $Scope = null): void
            {
               $this->touches++;
               parent::touch($Scope);
            }
         };
         $Password = new Password(memory: 19456, time: 2, threads: 1);
         $Users = new Users($Database, $Password);
         $Tokens = new Tokens($Database);
         $Trust = new Trust($Database);
         $Tokens->freeze(1000001);
         $Trust->freeze(1000001);

         // @ Positive routing control: an ordinary safe read must really reach
         //   the replica and expose its deliberately stale marker.
         $Ordinary = $Database->query('SELECT marker FROM routing_probe');
         $Evidence['ordinary'] = [
            'replica_pool' => $Ordinary->Pool === $Database->ReplicaPools[0],
            'marker' => $Ordinary->Result?->cell,
         ];

         // @ Unknown public selectors are negative controls: choosing the
         //   primary must not turn a miss into a write or a theft incident.
         $beforeUnknown = (int) $Observer->querySingle('SELECT count(*) FROM trusts');
         $Unknown = $Trust->rotate($unknownToken);
         $afterUnknown = (int) $Observer->querySingle('SELECT count(*) FROM trusts');
         $Evidence['unknown'] = [
            'null' => $Unknown === null,
            'before' => $beforeUnknown,
            'after' => $afterUnknown,
         ];

         // ! The replica still authorizes the old password and token, while
         //   the primary contains the current credential and the revocation.
         $Fetched = $Users->fetch($email);
         $Evidence['users'] = [
            'fetched' => $Fetched instanceof Identity,
            'verified' => $Fetched instanceof Identity ? $Fetched->claims['verified'] : null,
            'current_password' => $Users->check('1', $currentPassword),
            'stale_password' => $Users->check('1', $stalePassword),
         ];
         $Evidence['tokens'] = [
            'stale_token_accepted' => $Tokens->check(
               "{$tokenSelector}.{$tokenValidator}",
               Purposes::Recovery
            ),
            'primary_token_accepted' => $Tokens->check(
               "{$primaryTokenSelector}.{$primaryTokenValidator}",
               Purposes::Recovery
            ),
            'primary_rows' => (int) $Observer->querySingle('SELECT count(*) FROM tokens'),
         ];

         // @ A transaction already owns its primary connection and database
         //   isolation snapshot. Its read-only calls must remain valid without
         //   being reclassified as writes on the outer SQL routing scope.
         $touches = $Database->touches;
         $Transaction = $Database->begin();
         $Begin = $Transaction->Operation;
         if ($Begin === null) {
            throw new RuntimeException('TOK-8 transaction fixture did not expose BEGIN.');
         }
         $Database->await($Begin);
         $TransactionUsers = new Users($Transaction, $Password);
         $TransactionTokens = new Tokens($Transaction);
         $TransactionTrust = new Trust($Transaction);
         $TransactionTokens->freeze(1000001);
         $TransactionTrust->freeze(1000001);
         $TransactionFetched = $TransactionUsers->fetch($email);
         $transactionToken = $TransactionTokens->check(
            "{$tokenSelector}.{$tokenValidator}",
            Purposes::Recovery
         );
         $transactionPrimaryToken = $TransactionTokens->check(
            "{$primaryTokenSelector}.{$primaryTokenValidator}",
            Purposes::Recovery
         );
         $transactionTrust = $TransactionTrust->rotate($unknownToken);
         $Commit = $Transaction->commit();
         $Database->await($Commit);
         $Evidence['transaction'] = [
            'begin_finished' => $Begin->finished,
            'fetched_primary' => $TransactionFetched instanceof Identity
               && $TransactionFetched->claims['verified'] === true,
            'revoked_token_rejected' => $transactionToken === false,
            'primary_token_accepted' => $transactionPrimaryToken === true,
            'unknown_trust_rejected' => $transactionTrust === null,
            'touches' => $Database->touches - $touches,
            'commit_error' => $Commit->error,
         ];

         // @ The primary-forcing reads above use private scopes. They must not
         //   poison the caller's sticky scope: an ordinary follow-up remains
         //   replica eligible and sees the stale marker again.
         $AfterDecisions = $Database->query('SELECT marker FROM routing_probe');
         $Evidence['after_decisions'] = [
            'replica_pool' => $AfterDecisions->Pool === $Database->ReplicaPools[0],
            'marker' => $AfterDecisions->Result?->cell,
         ];

         // ! Decisive TOK-8 source-to-sink: the current cookie is valid on the
         //   primary, but the replica's preceding verifier makes vulnerable
         //   code report Theft and delete every device belonging to user 7.
         $Rotated = $Trust->rotate("{$trustSelector}.{$currentTrustValidator}");
         $Evidence['trust'] = [
            'token' => $Rotated instanceof Token,
            'theft' => $Rotated instanceof Theft,
            'user' => $Rotated instanceof Token || $Rotated instanceof Theft
               ? $Rotated->user
               : null,
            'user_7_rows' => (int) $Observer->querySingle(
               'SELECT count(*) FROM trusts WHERE user_id = 7'
            ),
            'user_8_rows' => (int) $Observer->querySingle(
               'SELECT count(*) FROM trusts WHERE user_id = 8'
            ),
         ];
      }
      finally {
         if ($Observer instanceof SQLite3) {
            $Observer->close();
         }
         if ($Fixture instanceof SQLite3) {
            $Fixture->close();
         }

         if ($Database instanceof SQL) {
            $Pools = array_merge([$Database->Pool], $Database->ReplicaPools);

            foreach ($Pools as $Pool) {
               $Pool->Connection->disconnect();

               foreach ($Pool->idle as $Connection) {
                  $Connection->disconnect();
               }
               foreach ($Pool->busy as $Connection) {
                  $Connection->disconnect();
               }
            }
         }

         unset(
            $TransactionTrust,
            $TransactionTokens,
            $TransactionUsers,
            $Transaction,
            $Trust,
            $Tokens,
            $Users,
            $Password,
            $Database
         );
         gc_collect_cycles();

         foreach (glob("{$directory}/*") ?: [] as $file) {
            if (is_file($file)) {
               unlink($file);
            }
         }

         rmdir($directory);
      }

      // @ All paths above are collected before the first assertion so a
      //   failure cannot prevent another store or control from being observed.
      yield assert(
         assertion: $Evidence['ordinary'] === [
            'replica_pool' => true,
            'marker' => 'replica',
         ]
            && $Evidence['unknown'] === [
               'null' => true,
               'before' => 3,
               'after' => 3,
            ]
            && $Evidence['transaction'] === [
               'begin_finished' => true,
               'fetched_primary' => true,
               'revoked_token_rejected' => true,
               'primary_token_accepted' => true,
               'unknown_trust_rejected' => true,
               'touches' => 0,
               'commit_error' => null,
            ],
         description: 'Fixture controls prove a stale replica, a no-write selector miss and a primary-pinned transaction; evidence='
            . json_encode($Evidence, JSON_THROW_ON_ERROR)
      );

      yield assert(
         assertion: $Evidence['users'] === [
            'fetched' => true,
            'verified' => true,
            'current_password' => true,
            'stale_password' => false,
         ]
            && $Evidence['tokens'] === [
               'stale_token_accepted' => false,
               'primary_token_accepted' => true,
               'primary_rows' => 1,
            ]
            && $Evidence['after_decisions'] === [
               'replica_pool' => true,
               'marker' => 'replica',
            ]
            && $Evidence['trust'] === [
               'token' => true,
               'theft' => false,
               'user' => '7',
               'user_7_rows' => 2,
               'user_8_rows' => 1,
            ],
         description: 'TOK-8 CONFIRMED: security verdict reads must use the primary while ordinary reads remain replica-eligible; evidence='
            . json_encode($Evidence, JSON_THROW_ON_ERROR)
      );
   }
);
