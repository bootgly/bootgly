<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\UsersEnrollTransactionPostgreSQLLive;


use function array_key_exists;
use function assert;
use function bin2hex;
use function defined;
use function getenv;
use function json_encode;
use function random_bytes;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\API\Security\Identity;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Users;


// ! Opt-in real-driver E2E. Once enabled, connection and fixture failures are
//   assertions rather than skips so infrastructure cannot resemble a verdict.
$optin = getenv('BOOTGLY_USR8_PGSQL_E2E') === '1';

return new Test(
   description: 'Security(live): a duplicate enrollment inside a PostgreSQL transaction cannot destroy the unit of work (requires BOOTGLY_USR8_PGSQL_E2E=1)',
   skip: $optin === false || defined('PASSWORD_ARGON2ID') === false,
   test: function () {
      $host = getenv('DB_HOST');
      $port = getenv('DB_PORT');
      $database = getenv('DB_NAME');
      $username = getenv('DB_USER');
      $DBPassword = getenv('DB_PASSWORD');
      $legacyDBPassword = getenv('DB_PASS');
      $SSLMode = getenv('DB_SSLMODE');
      $config = [
         'driver' => 'pgsql',
         'host' => $host === false ? '127.0.0.1' : $host,
         'port' => $port === false ? 5432 : (int) $port,
         'database' => $database === false ? 'postgres' : $database,
         'username' => $username === false ? 'postgres' : $username,
         'password' => $DBPassword !== false
            ? $DBPassword
            : ($legacyDBPassword === false ? '' : $legacyDBPassword),
         'timeout' => 8.0,
         'secure' => [
            'mode' => $SSLMode === false ? 'disable' : $SSLMode,
            'key' => '',
         ],
         'pool' => ['min' => 0, 'max' => 1],
      ];
      $prefix = 'bootgly_usr8_pg_' . bin2hex(random_bytes(6));
      $table = "{$prefix}_users";
      $tableSQL = "\"{$table}\"";

      $Await = static function (SQL $Database, string $SQL, array $parameters = []) {
         $Operation = $Database->query($SQL, $parameters);
         $Database->await($Operation);

         if ($Operation->error !== null) {
            throw new RuntimeException($Operation->error);
         }

         return $Operation;
      };

      $evidence = [];
      $fixtureError = null;
      $cleanupError = null;
      $External = null;
      $Transaction = null;

      try {
         $External = new SQL($config);
         $Unit = new SQL($config);
         $Await($External, <<<SQL
         CREATE TABLE {$tableSQL} (
            id TEXT PRIMARY KEY DEFAULT (gen_random_uuid()::text),
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            email_verified_at BIGINT DEFAULT NULL
         )
         SQL);

         $Password = new Password(memory: 19456, time: 2, threads: 1);
         $Seed = new Users($External, $Password, table: $table);
         $taken = $Seed->enroll('taken@bootgly.test', 'taken-secret');

         // @ The honest invite unit, exactly as the store documents it:
         //   enroll every address, skip the duplicates, keep the rest.
         $Transaction = $Unit->begin();
         $Unit->await($Transaction->Operation);
         $Invites = new Users($Transaction, $Password, table: $table);

         $free1 = $Invites->enroll('inv-1@bootgly.test', 'invite-one');
         $duplicate = $Invites->enroll('taken@bootgly.test', 'invite-two');
         $depthAfterDuplicate = $Transaction->depth;
         $free2 = $Invites->enroll('inv-2@bootgly.test', 'invite-three');
         $Inside = $Invites->verify('taken@bootgly.test', 'taken-secret');
         $insideCheck = $Invites->check((string) $taken, 'taken-secret');

         $Commit = $Unit->await($Transaction->commit());

         // @ Out-of-band truth — a connection the unit never owned
         $Count = $Await(
            $External,
            "SELECT count(*) AS n FROM {$tableSQL} WHERE email LIKE 'inv-%'"
         );

         $evidence = [
            'seed' => $taken !== null,
            'free1' => $free1 !== null,
            'duplicate' => $duplicate,
            'depth_after_duplicate' => $depthAfterDuplicate,
            'free2' => $free2 !== null,
            'inside_verify' => $Inside instanceof Identity,
            'inside_check' => $insideCheck,
            'commit_error' => $Commit->error,
            'commit_status' => $Commit->status,
            'depth_after_commit' => $Transaction->depth,
            'invited_rows' => (int) ($Count->rows[0]['n'] ?? -1),
         ];
      }
      catch (Throwable $Failure) {
         $fixtureError = $Failure::class . ': ' . $Failure->getMessage();
      }
      finally {
         try {
            if ($Transaction instanceof Transaction && $Transaction->depth > 0) {
               $Unit->await($Transaction->rollback());
            }
         }
         catch (Throwable $Failure) {
            $cleanupError = $Failure::class . ': ' . $Failure->getMessage();
         }

         try {
            if ($External instanceof SQL) {
               $Await($External, "DROP TABLE IF EXISTS {$tableSQL}");
            }
         }
         catch (Throwable $Failure) {
            $dropError = $Failure::class . ': ' . $Failure->getMessage();
            $cleanupError = $cleanupError === null
               ? $dropError
               : "{$cleanupError}; {$dropError}";
         }
      }

      yield assert(
         assertion: $fixtureError === null && $cleanupError === null,
         description: 'USR-8 fixture and cleanup complete without error; found: '
            . json_encode([$fixtureError, $cleanupError])
      );

      yield assert(
         assertion: ($evidence['seed'] ?? false) === true
            && ($evidence['free1'] ?? false) === true
            && array_key_exists('duplicate', $evidence)
            && $evidence['duplicate'] === null
            && ($evidence['depth_after_duplicate'] ?? 0) === 1
            && ($evidence['free2'] ?? false) === true,
         description: 'USR-8: the fenced duplicate answers null, keeps the transaction at its entry depth, and the next free address still enrolls; found: '
            . json_encode($evidence)
      );

      yield assert(
         assertion: ($evidence['inside_verify'] ?? false) === true
            && ($evidence['inside_check'] ?? false) === true,
         description: 'USR-8: verdicts issued after the duplicate stay honest — the correct password answers as correct inside the unit; found: '
            . json_encode($evidence)
      );

      yield assert(
         assertion: array_key_exists('commit_error', $evidence)
            && $evidence['commit_error'] === null
            && ($evidence['commit_status'] ?? null) === 'COMMIT'
            && ($evidence['depth_after_commit'] ?? -1) === 0
            && ($evidence['invited_rows'] ?? -1) === 2,
         description: 'USR-8: the commit is a real COMMIT and both invited accounts are durable on an independent connection; found: '
            . json_encode($evidence)
      );
   }
);
