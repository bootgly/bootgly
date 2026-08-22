<?php

namespace Bootgly\API\Security\Tests\TokensUpsertMySQLLive;


use function assert;
use function bin2hex;
use function get_class;
use function get_debug_type;
use function getenv;
use function is_array;
use function is_numeric;
use function json_encode;
use function random_bytes;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Token;


// ! Opt-in real-driver E2E. Once enabled, connection or fixture failures are
//   assertions rather than skips so infrastructure cannot resemble safety.
$optin = getenv('BOOTGLY_TOK39_MYSQL_E2E') === '1';


return new Test(
   description: 'Security/Tokens(live): MySQL selector collisions cannot overwrite another user-purpose token (requires BOOTGLY_TOK39_MYSQL_E2E=1)',
   skip: $optin === false,
   test: function () {
      $host = getenv('DB_HOST');
      $port = getenv('DB_PORT');
      $database = getenv('DB_NAME');
      $username = getenv('DB_USER');
      $DBPassword = getenv('DB_PASSWORD');
      $legacyDBPassword = getenv('DB_PASS');
      $SSLMode = getenv('DB_SSLMODE');
      $serverKey = getenv('DB_SERVER_PUBLIC_KEY');
      $config = [
         'driver' => 'mysql',
         'host' => $host === false ? '127.0.0.1' : $host,
         'port' => $port === false ? 3306 : (int) $port,
         'database' => $database === false ? 'bootgly' : $database,
         'username' => $username === false ? 'root' : $username,
         'password' => $DBPassword !== false
            ? $DBPassword
            : ($legacyDBPassword === false ? '' : $legacyDBPassword),
         'timeout' => 8.0,
         'secure' => [
            'mode' => $SSLMode === false ? 'disable' : $SSLMode,
            'key' => $serverKey === false ? '' : $serverKey,
         ],
         'pool' => ['min' => 0, 'max' => 1],
      ];
      $suffix = bin2hex(random_bytes(6));
      $table = "bootgly_tok39_guard_{$suffix}";
      $trigger = "bootgly_tok39_collision_{$suffix}";
      $tableSQL = "`{$table}`";
      $triggerSQL = "`{$trigger}`";
      $userA = "tok39-a-{$suffix}";
      $userB = "tok39-b-{$suffix}";
      $clock = 2_000_000_000;
      $previousGC = Tokens::$gcProbability;
      $Database = null;
      $RollbackTransaction = null;
      $CommitTransaction = null;
      $fixtureError = null;
      $cleanupError = null;
      $collisionError = null;
      $beforeA = null;
      $afterCollisionA = null;
      $afterNormalA = null;
      $collisionALive = null;
      $collisionBCount = null;
      $normalBCount = null;
      $normalFirstLive = null;
      $normalSecondLive = null;
      $rollbackInsideCount = null;
      $rollbackInsidePreviousLive = null;
      $rollbackInsideCandidateLive = null;
      $rollbackAfterCount = null;
      $rollbackAfterPreviousLive = null;
      $rollbackAfterCandidateLive = null;
      $commitAfterCount = null;
      $commitAfterPreviousLive = null;
      $commitAfterCandidateLive = null;
      $SeedA = null;
      $FirstB = null;
      $SecondB = null;
      $RollbackCandidate = null;
      $CommitCandidate = null;

      /**
       * Execute and await one real MySQL operation.
       *
       * @param array<int|string,mixed> $parameters
       */
      $Await = static function (
         SQL|Transaction $Database,
         string $SQL,
         array $parameters = []
      ): Operation {
         $Operation = $Database->query($SQL, $parameters);
         $Database->await($Operation);

         if ($Operation->error !== null) {
            throw new RuntimeException($Operation->error);
         }

         return $Operation;
      };
      /**
       * Read the complete security payload for one user-purpose pair.
       *
       * @return null|array<string,mixed>
       */
      $Read = static function (
         SQL|Transaction $Database,
         string $tableSQL,
         string $user,
         Purposes $Purpose
      ) use ($Await): null|array {
         $Operation = $Await(
            $Database,
            "SELECT selector, verifier, user_id, purpose, expires FROM {$tableSQL} "
               . 'WHERE user_id = ? AND purpose = ?',
            [$user, $Purpose->value]
         );
         $row = $Operation->rows[0] ?? null;

         return is_array($row) ? $row : null;
      };
      /**
       * Count rows for one user-purpose pair.
       */
      $Count = static function (
         SQL|Transaction $Database,
         string $tableSQL,
         string $user,
         Purposes $Purpose
      ) use ($Await): int {
         $Operation = $Await(
            $Database,
            "SELECT count(*) AS total FROM {$tableSQL} WHERE user_id = ? AND purpose = ?",
            [$user, $Purpose->value]
         );
         $total = $Operation->rows[0]['total'] ?? null;

         if (is_numeric($total) === false) {
            throw new RuntimeException('TOK-9 MySQL fixture could not count token rows.');
         }

         return (int) $total;
      };
      /**
       * Await the BEGIN operation exposed by a transaction.
       */
      $Begin = static function (Transaction $Transaction): void {
         $Operation = $Transaction->Operation;
         if ($Operation === null) {
            throw new RuntimeException('TOK-9 MySQL fixture did not expose BEGIN.');
         }

         $Transaction->await($Operation);
         if ($Operation->error !== null) {
            throw new RuntimeException($Operation->error);
         }
      };

      Tokens::$gcProbability = [0, 1];

      try {
         $Database = new SQL($config);
         $Await(
            $Database,
            "CREATE TABLE {$tableSQL} ("
               . 'id BIGINT AUTO_INCREMENT PRIMARY KEY, '
               . 'selector VARCHAR(16) NOT NULL UNIQUE, '
               . 'verifier VARCHAR(64) NOT NULL, '
               . 'user_id VARCHAR(191) NOT NULL, '
               . 'purpose VARCHAR(32) NOT NULL, '
               . 'expires BIGINT NOT NULL, '
               . 'UNIQUE (user_id, purpose)'
               . ') ENGINE=InnoDB'
         );

         $Tokens = new Tokens($Database, $table);
         $Tokens->freeze($clock);

         // # Seed the victim before installing the collision trigger, then
         //   preserve every persisted field for a strict post-attempt compare.
         $SeedA = $Tokens->mint($userA, Purposes::Recovery, 3600);
         $beforeA = $Read($Database, $tableSQL, $userA, Purposes::Recovery);

         // ! MySQL chooses ON DUPLICATE KEY UPDATE for any unique key. Force
         //   user B's otherwise unrelated INSERT onto A's selector unique key.
         $Await(
            $Database,
            "CREATE TRIGGER {$triggerSQL} BEFORE INSERT ON {$tableSQL} FOR EACH ROW "
               . "SET NEW.selector = IF(NEW.user_id = '{$userB}', '{$SeedA->selector}', NEW.selector)"
         );

         try {
            $Tokens->mint($userB, Purposes::Verification, 3600);
         }
         catch (RuntimeException $Failure) {
            $collisionError = get_class($Failure) . ': ' . $Failure->getMessage();
         }

         $afterCollisionA = $Read($Database, $tableSQL, $userA, Purposes::Recovery);
         $collisionALive = $Tokens->check($SeedA->value, Purposes::Recovery);
         $collisionBCount = $Count(
            $Database,
            $tableSQL,
            $userB,
            Purposes::Verification
         );

         $Await($Database, "DROP TRIGGER {$triggerSQL}");

         // @ Ordinary sequential upsert: one pair row remains and only the
         //   newest returned credential authenticates.
         $FirstB = $Tokens->mint($userB, Purposes::Verification, 3600);
         $SecondB = $Tokens->mint($userB, Purposes::Verification, 3600);
         $normalBCount = $Count(
            $Database,
            $tableSQL,
            $userB,
            Purposes::Verification
         );
         $normalFirstLive = $Tokens->check($FirstB->value, Purposes::Verification);
         $normalSecondLive = $Tokens->check($SecondB->value, Purposes::Verification);
         $afterNormalA = $Read($Database, $tableSQL, $userA, Purposes::Recovery);

         // @ The custom MySQL Query must retain Transaction semantics. First
         //   prove a supersede is visible inside its transaction and vanishes
         //   completely after rollback.
         $RollbackTransaction = $Database->begin();
         $Begin($RollbackTransaction);
         $RollbackTokens = new Tokens($RollbackTransaction, $table);
         $RollbackTokens->freeze($clock);
         $RollbackCandidate = $RollbackTokens->mint(
            $userB,
            Purposes::Verification,
            3600
         );
         $rollbackInsideCount = $Count(
            $RollbackTransaction,
            $tableSQL,
            $userB,
            Purposes::Verification
         );
         $rollbackInsidePreviousLive = $RollbackTokens->check(
            $SecondB->value,
            Purposes::Verification
         );
         $rollbackInsideCandidateLive = $RollbackTokens->check(
            $RollbackCandidate->value,
            Purposes::Verification
         );
         $Rollback = $RollbackTransaction->rollback();
         $RollbackTransaction->await($Rollback);
         if ($Rollback->error !== null) {
            throw new RuntimeException($Rollback->error);
         }
         $RollbackTransaction = null;

         $rollbackAfterCount = $Count(
            $Database,
            $tableSQL,
            $userB,
            Purposes::Verification
         );
         $rollbackAfterPreviousLive = $Tokens->check(
            $SecondB->value,
            Purposes::Verification
         );
         $rollbackAfterCandidateLive = $Tokens->check(
            $RollbackCandidate->value,
            Purposes::Verification
         );

         // @ A committed supersede is the inverse control: exactly the
         //   transaction's candidate remains live outside the transaction.
         $CommitTransaction = $Database->begin();
         $Begin($CommitTransaction);
         $CommitTokens = new Tokens($CommitTransaction, $table);
         $CommitTokens->freeze($clock);
         $CommitCandidate = $CommitTokens->mint(
            $userB,
            Purposes::Verification,
            3600
         );
         $Commit = $CommitTransaction->commit();
         $CommitTransaction->await($Commit);
         if ($Commit->error !== null) {
            throw new RuntimeException($Commit->error);
         }
         $CommitTransaction = null;

         $commitAfterCount = $Count(
            $Database,
            $tableSQL,
            $userB,
            Purposes::Verification
         );
         $commitAfterPreviousLive = $Tokens->check(
            $SecondB->value,
            Purposes::Verification
         );
         $commitAfterCandidateLive = $Tokens->check(
            $CommitCandidate->value,
            Purposes::Verification
         );
      }
      catch (Throwable $Failure) {
         $fixtureError = get_class($Failure) . ': ' . $Failure->getMessage();
      }
      finally {
         foreach ([$RollbackTransaction, $CommitTransaction] as $Transaction) {
            if ($Transaction instanceof Transaction && $Transaction->depth > 0) {
               try {
                  $Rollback = $Transaction->rollback();
                  $Transaction->await($Rollback);

                  if ($Rollback->error !== null) {
                     throw new RuntimeException($Rollback->error);
                  }
               }
               catch (Throwable $Failure) {
                  $message = get_class($Failure) . ': ' . $Failure->getMessage();
                  $cleanupError = $cleanupError === null
                     ? $message
                     : "{$cleanupError}; {$message}";
               }
            }
         }

         try {
            $Cleanup = $Database ?? new SQL($config);
            foreach ([
               "DROP TRIGGER IF EXISTS {$triggerSQL}",
               "DROP TABLE IF EXISTS {$tableSQL}",
            ] as $SQL) {
               $Operation = $Cleanup->query($SQL);
               $Cleanup->await($Operation);

               if ($Operation->error !== null) {
                  throw new RuntimeException($Operation->error);
               }
            }

            $Cleanup->Connection->disconnect();
         }
         catch (Throwable $Failure) {
            $message = get_class($Failure) . ': ' . $Failure->getMessage();
            $cleanupError = $cleanupError === null
               ? $message
               : "{$cleanupError}; {$message}";
         }

         Tokens::$gcProbability = $previousGC;
      }

      $evidence = [
         'fixture_error' => $fixtureError,
         'cleanup_error' => $cleanupError,
         'seed_a' => get_debug_type($SeedA),
         'a_before' => $beforeA,
         'collision_error' => $collisionError,
         'a_after_collision' => $afterCollisionA,
         'a_live_after_collision' => $collisionALive,
         'b_rows_after_collision' => $collisionBCount,
         'first_b' => get_debug_type($FirstB),
         'second_b' => get_debug_type($SecondB),
         'b_rows_after_normal_supersede' => $normalBCount,
         'first_b_live' => $normalFirstLive,
         'second_b_live' => $normalSecondLive,
         'a_after_normal_supersede' => $afterNormalA,
         'rollback_candidate' => get_debug_type($RollbackCandidate),
         'rollback_inside' => [
            'rows' => $rollbackInsideCount,
            'previous_live' => $rollbackInsidePreviousLive,
            'candidate_live' => $rollbackInsideCandidateLive,
         ],
         'rollback_after' => [
            'rows' => $rollbackAfterCount,
            'previous_live' => $rollbackAfterPreviousLive,
            'candidate_live' => $rollbackAfterCandidateLive,
         ],
         'commit_candidate' => get_debug_type($CommitCandidate),
         'commit_after' => [
            'rows' => $commitAfterCount,
            'previous_live' => $commitAfterPreviousLive,
            'candidate_live' => $commitAfterCandidateLive,
         ],
      ];
      $fixture = $fixtureError === null
         && $cleanupError === null
         && $SeedA instanceof Token
         && is_array($beforeA)
         && $FirstB instanceof Token
         && $SecondB instanceof Token
         && $RollbackCandidate instanceof Token
         && $CommitCandidate instanceof Token;

      yield assert(
         assertion: $fixture,
         description: 'TOK-9 MySQL fixture must create, exercise and clean its exclusive table and trigger; evidence='
            . json_encode($evidence)
      );

      $secure = $fixture
         && $collisionError === RuntimeException::class . ': Token could not be stored.'
         && $afterCollisionA === $beforeA
         && $collisionALive === true
         && $collisionBCount === 0
         && $normalBCount === 1
         && $normalFirstLive === false
         && $normalSecondLive === true
         && $afterNormalA === $beforeA
         && $rollbackInsideCount === 1
         && $rollbackInsidePreviousLive === false
         && $rollbackInsideCandidateLive === true
         && $rollbackAfterCount === 1
         && $rollbackAfterPreviousLive === true
         && $rollbackAfterCandidateLive === false
         && $commitAfterCount === 1
         && $commitAfterPreviousLive === false
         && $commitAfterCandidateLive === true;

      yield assert(
         assertion: $secure,
         description: 'TOK-9: a selector-key collision must fail closed without changing another '
            . 'user-purpose row, while normal and transactional supersedes remain atomic; evidence='
            . json_encode($evidence)
      );
   }
);
