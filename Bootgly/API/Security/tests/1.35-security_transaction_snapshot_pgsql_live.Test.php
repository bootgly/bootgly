<?php

namespace Bootgly\API\Security\Tests\SecurityTransactionSnapshotPostgreSQLLive;


use function array_key_exists;
use function array_reverse;
use function assert;
use function bin2hex;
use function defined;
use function get_class;
use function get_debug_type;
use function getenv;
use function hash;
use function is_numeric;
use function is_string;
use function json_encode;
use function password_verify;
use function random_bytes;
use function str_contains;
use function str_ends_with;
use function strtolower;
use function substr;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Theft;
use Bootgly\API\Security\Tokens\Token;
use Bootgly\API\Security\Tokens\Trust;
use Bootgly\API\Security\Users;


// ! Opt-in real-driver E2E. Once enabled, connection and fixture failures are
//   assertions rather than skips so infrastructure cannot resemble fail-closed.
$optin = getenv('BOOTGLY_TOK12_PGSQL_E2E') === '1';


return new Test(
   description: 'Security(live): PostgreSQL transaction verdicts reject an obsolete repeatable-read snapshot (requires BOOTGLY_TOK12_PGSQL_E2E=1)',
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
            'key' => $serverKey === false ? '' : $serverKey,
         ],
         'pool' => ['min' => 0, 'max' => 1],
      ];
      $prefix = 'bootgly_tok12_pg_' . bin2hex(random_bytes(6));
      $usersTable = "{$prefix}_users";
      $tokensTable = "{$prefix}_tokens";
      $trustsTable = "{$prefix}_trusts";
      $usersSQL = "\"{$usersTable}\"";
      $tokensSQL = "\"{$tokensTable}\"";
      $trustsSQL = "\"{$trustsTable}\"";
      $oldPassword = 'tok12-pg-old-password';
      $currentPassword = 'tok12-pg-current-password';
      $ownPassword = 'tok12-pg-own-password';
      $email = 'tok12-pg-' . bin2hex(random_bytes(5)) . '@bootgly.test';
      $clock = 2_000_000_000;
      $previousTokensGC = Tokens::$gcProbability;
      $previousTrustGC = Trust::$gcProbability;
      $ExternalDatabase = null;
      $SnapshotDatabase = null;
      /** @var array<int,Transaction> $Transactions */
      $Transactions = [];
      /** @var array<int,SQL> $Databases */
      $Databases = [];
      $fixtureError = null;
      $cleanupError = null;
      $evidence = [
         'connections' => [],
         'setup' => [],
         'users' => [],
         'tokens' => [],
         'trust' => [],
         'fresh' => [],
         'post' => [],
      ];

      /**
       * Execute and await one real SQL operation.
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
       * Open a repeatable-read transaction before its first data statement.
       */
      $Begin = static function (SQL $Database) use ($Await, &$Transactions): Transaction {
         $Transaction = $Database->begin();
         $Transactions[] = $Transaction;
         $Operation = $Transaction->Operation;

         if ($Operation === null) {
            throw new RuntimeException('TOK-12 PostgreSQL fixture did not expose BEGIN.');
         }

         $Transaction->await($Operation);

         if ($Operation->error !== null) {
            throw new RuntimeException($Operation->error);
         }

         $Await($Transaction, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

         return $Transaction;
      };
      /**
       * Roll back one fixture transaction, including an expected aborted block.
       */
      $Rollback = static function (Transaction $Transaction): void {
         if ($Transaction->depth <= 0) {
            return;
         }

         $Operation = $Transaction->rollback();
         $Transaction->await($Operation);

         if ($Operation->error !== null) {
            throw new RuntimeException($Operation->error);
         }
      };
      /**
       * Count persisted trusted-device rows for the fixture user.
       */
      $Count = static function (SQL $Database, string $trustsSQL, string $user) use ($Await): int {
         $Operation = $Await(
            $Database,
            "SELECT count(*) AS total FROM {$trustsSQL} WHERE user_id = \$1",
            [$user]
         );
         $total = $Operation->Result?->cell;

         if (is_numeric($total) === false) {
            throw new RuntimeException('TOK-12 PostgreSQL fixture could not count devices.');
         }

         return (int) $total;
      };
      /**
       * Capture the last transaction statement after a public store verdict.
       *
       * @return array{sql:null|string,error:null|string}
       */
      $Inspect = static function (Transaction $Transaction): array {
         return [
            'sql' => $Transaction->Operation?->SQL,
            'error' => $Transaction->Operation?->error,
         ];
      };

      try {
         if (defined('PASSWORD_ARGON2ID') === false) {
            throw new RuntimeException('TOK-12 PostgreSQL fixture requires Argon2id support.');
         }

         $ExternalDatabase = new SQL($config);
         $SnapshotDatabase = new SQL($config);
         $Databases = [$ExternalDatabase, $SnapshotDatabase];
         $ExternalPID = $Await($ExternalDatabase, 'SELECT pg_backend_pid() AS pid')->Result?->cell;
         $SnapshotPID = $Await($SnapshotDatabase, 'SELECT pg_backend_pid() AS pid')->Result?->cell;
         $evidence['connections'] = [
            'external' => $ExternalPID,
            'snapshot' => $SnapshotPID,
            'distinct' => is_numeric($ExternalPID)
               && is_numeric($SnapshotPID)
               && (string) $ExternalPID !== (string) $SnapshotPID,
         ];

         $Await(
            $ExternalDatabase,
            "CREATE TABLE {$usersSQL} ("
               . 'id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, '
               . 'email TEXT NOT NULL UNIQUE, '
               . 'password TEXT NOT NULL, '
               . 'email_verified_at BIGINT NULL'
               . ')'
         );
         $Await(
            $ExternalDatabase,
            "CREATE TABLE {$tokensSQL} ("
               . 'id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, '
               . 'selector VARCHAR(16) NOT NULL UNIQUE, '
               . 'verifier VARCHAR(64) NOT NULL, '
               . 'user_id TEXT NOT NULL, '
               . 'purpose VARCHAR(32) NOT NULL, '
               . 'expires BIGINT NOT NULL'
               . ')'
         );
         $Await(
            $ExternalDatabase,
            "CREATE TABLE {$trustsSQL} ("
               . 'id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, '
               . 'selector VARCHAR(16) NOT NULL UNIQUE, '
               . 'verifier VARCHAR(64) NOT NULL, '
               . 'previous VARCHAR(64) NULL, '
               . 'rotated BIGINT NULL, '
               . 'user_id TEXT NOT NULL, '
               . 'expires BIGINT NOT NULL'
               . ')'
         );

         Tokens::$gcProbability = [0, 1];
         Trust::$gcProbability = [0, 1];
         $Password = new Password(memory: 19456, time: 2, threads: 1);
         $Users = new Users($ExternalDatabase, $Password, $usersTable);
         $Tokens = new Tokens($ExternalDatabase, $tokensTable);
         $Trust = new Trust($ExternalDatabase, $trustsTable);
         $Tokens->freeze($clock);
         $Trust->freeze($clock);
         $user = $Users->enroll($email, $oldPassword);

         if ($user === null) {
            throw new RuntimeException('TOK-12 PostgreSQL fixture could not enroll its user.');
         }

         $IssuedToken = $Tokens->mint($user, Purposes::Recovery, 3600);
         $IssuedTrust = $Trust->issue($user, 3600);
         $Device = $Trust->issue($user, 3600);
         $tokenDigest = hash('sha256', substr($IssuedToken->value, 17));
         $oldTrustDigest = hash('sha256', substr($IssuedTrust->value, 17));
         $evidence['setup'] = [
            'user' => $user,
            'token' => get_debug_type($IssuedToken),
            'trust' => get_debug_type($IssuedTrust),
            'device' => get_debug_type($Device),
            'distinct_device' => $Device->selector !== $IssuedTrust->selector,
            'device_count' => $Count($ExternalDatabase, $trustsSQL, $user),
         ];

         // # Users: fix one old snapshot, commit a password rotation elsewhere,
         //   then require the public check to fail before using the old hash.
         $UsersTransaction = $Begin($SnapshotDatabase);
         $UserSnapshot = $Await(
            $UsersTransaction,
            "SELECT password FROM {$usersSQL} WHERE id = \$1",
            [$user]
         )->Result?->cell;
         $passwordRotated = $Users->rotate($user, $currentPassword);
         $TransactionUsers = new Users($UsersTransaction, $Password, $usersTable);
         $oldAccepted = $TransactionUsers->check($user, $oldPassword);
         $evidence['users'] = [
            'snapshot_old' => is_string($UserSnapshot)
               && password_verify($oldPassword, $UserSnapshot),
            'external_rotated' => $passwordRotated,
            'old_accepted' => $oldAccepted,
            'operation' => $Inspect($UsersTransaction),
         ];
         $Rollback($UsersTransaction);

         // # Tokens: an obsolete snapshot still contains the token deleted by
         //   the independent session. A locked current read must fail closed.
         $TokensTransaction = $Begin($SnapshotDatabase);
         $TokenSnapshot = $Await(
            $TokensTransaction,
            "SELECT verifier FROM {$tokensSQL} WHERE selector = \$1",
            [$IssuedToken->selector]
         )->Result?->cell;
         $revoked = $Tokens->revoke($user, Purposes::Recovery);
         $TransactionTokens = new Tokens($TokensTransaction, $tokensTable);
         $TransactionTokens->freeze($clock);
         $revokedAccepted = $TransactionTokens->check(
            $IssuedToken->value,
            Purposes::Recovery
         );
         $evidence['tokens'] = [
            'snapshot_digest' => $TokenSnapshot,
            'external_revoked' => $revoked,
            'revoked_accepted' => $revokedAccepted,
            'operation' => $Inspect($TokensTransaction),
         ];
         $Rollback($TokensTransaction);

         // # Trust: the winner commits after this snapshot. The current cookie
         //   must never become Theft, and neither device may be deleted.
         $TrustTransaction = $Begin($SnapshotDatabase);
         $TrustSnapshot = $Await(
            $TrustTransaction,
            "SELECT verifier FROM {$trustsSQL} WHERE selector = \$1",
            [$IssuedTrust->selector]
         )->Result?->cell;
         $Winner = $Trust->rotate($IssuedTrust->value, 3600);

         if ($Winner instanceof Token === false) {
            throw new RuntimeException('TOK-12 PostgreSQL fixture could not rotate its trust winner.');
         }

         $beforeTrustVerdict = $Count($ExternalDatabase, $trustsSQL, $user);
         $TransactionTrust = new Trust($TrustTransaction, $trustsTable);
         $TransactionTrust->freeze($clock);
         $StaleTrust = $TransactionTrust->rotate($Winner->value, 3600);
         $evidence['trust'] = [
            'snapshot_digest' => $TrustSnapshot,
            'external_winner' => get_debug_type($Winner),
            'result' => get_debug_type($StaleTrust),
            'theft' => $StaleTrust instanceof Theft,
            'operation' => $Inspect($TrustTransaction),
            'before_devices' => $beforeTrustVerdict,
         ];
         $Rollback($TrustTransaction);
         $evidence['trust']['after_devices'] = $Count($ExternalDatabase, $trustsSQL, $user);

         // # A fresh repeatable-read transaction starts after every external
         //   commit. It sees current state and all of its own later writes.
         $FreshTransaction = $Begin($SnapshotDatabase);
         $FreshUsers = new Users($FreshTransaction, $Password, $usersTable);
         $FreshTokens = new Tokens($FreshTransaction, $tokensTable);
         $FreshTrust = new Trust($FreshTransaction, $trustsTable);
         $FreshTokens->freeze($clock);
         $FreshTrust->freeze($clock);
         $currentAccepted = $FreshUsers->check($user, $currentPassword);
         $oldRejected = $FreshUsers->check($user, $oldPassword) === false;
         $revokedRejected = $FreshTokens->check(
            $IssuedToken->value,
            Purposes::Recovery
         ) === false;
         $FreshWinner = $FreshTrust->rotate($Winner->value, 3600);
         $ownRotated = $FreshUsers->rotate($user, $ownPassword);
         $ownAccepted = $FreshUsers->check($user, $ownPassword);
         $currentRejectedAfterOwnWrite = $FreshUsers->check($user, $currentPassword) === false;
         $OwnToken = $FreshTokens->mint($user, Purposes::Recovery, 3600);
         $ownTokenAccepted = $FreshTokens->check($OwnToken->value, Purposes::Recovery);
         $OwnTrust = $FreshTrust->issue($user, 3600);
         $OwnTrustWinner = $FreshTrust->rotate($OwnTrust->value, 3600);
         $evidence['fresh'] = [
            'current_password' => $currentAccepted,
            'old_password_rejected' => $oldRejected,
            'revoked_token_rejected' => $revokedRejected,
            'current_trust' => get_debug_type($FreshWinner),
            'own_password_rotated' => $ownRotated,
            'own_password' => $ownAccepted,
            'previous_password_rejected' => $currentRejectedAfterOwnWrite,
            'own_token' => get_debug_type($OwnToken),
            'own_token_accepted' => $ownTokenAccepted,
            'own_trust' => get_debug_type($OwnTrust),
            'own_trust_rotated' => get_debug_type($OwnTrustWinner),
            'operation_error' => $FreshTransaction->Operation?->error,
         ];
         $Rollback($FreshTransaction);

         $PersistedTrust = $Await(
            $ExternalDatabase,
            "SELECT verifier FROM {$trustsSQL} WHERE selector = \$1",
            [$Winner->selector]
         )->Result?->cell;
         $evidence['post'] = [
            'current_password' => $Users->check($user, $currentPassword),
            'old_password_rejected' => $Users->check($user, $oldPassword) === false,
            'revoked_token_rejected' => $Tokens->check(
               $IssuedToken->value,
               Purposes::Recovery
            ) === false,
            'trust_digest' => $PersistedTrust,
            'expected_trust_digest' => hash('sha256', substr($Winner->value, 17)),
            'device_count' => $Count($ExternalDatabase, $trustsSQL, $user),
         ];
      }
      catch (Throwable $Failure) {
         $fixtureError = get_class($Failure) . ': ' . $Failure->getMessage();
      }
      finally {
         Tokens::$gcProbability = $previousTokensGC;
         Trust::$gcProbability = $previousTrustGC;

         foreach (array_reverse($Transactions) as $Transaction) {
            if ($Transaction->depth <= 0) {
               continue;
            }

            try {
               $Rollback($Transaction);
            }
            catch (Throwable $Failure) {
               $rollbackError = get_class($Failure) . ': ' . $Failure->getMessage();
               $cleanupError = $cleanupError === null
                  ? $rollbackError
                  : "{$cleanupError}; {$rollbackError}";
            }
         }

         $CleanupDatabase = $ExternalDatabase ?? $SnapshotDatabase;

         if ($CleanupDatabase instanceof SQL) {
            foreach ([$trustsSQL, $tokensSQL, $usersSQL] as $tableSQL) {
               try {
                  $Cleanup = $CleanupDatabase->query("DROP TABLE IF EXISTS {$tableSQL}");
                  $CleanupDatabase->await($Cleanup);

                  if ($Cleanup->error !== null) {
                     throw new RuntimeException($Cleanup->error);
                  }
               }
               catch (Throwable $Failure) {
                  $dropError = get_class($Failure) . ': ' . $Failure->getMessage();
                  $cleanupError = $cleanupError === null
                     ? $dropError
                     : "{$cleanupError}; {$dropError}";
               }
            }
         }

         foreach ($Databases as $Database) {
            try {
               $Database->Connection->disconnect();
            }
            catch (Throwable $Failure) {
               $disconnectError = get_class($Failure) . ': ' . $Failure->getMessage();
               $cleanupError = $cleanupError === null
                  ? $disconnectError
                  : "{$cleanupError}; {$disconnectError}";
            }
         }
      }

      $fixture = $fixtureError === null
         && $cleanupError === null
         && ($evidence['connections']['distinct'] ?? false) === true
         && ($evidence['setup']['token'] ?? null) === Token::class
         && ($evidence['setup']['trust'] ?? null) === Token::class
         && ($evidence['setup']['device'] ?? null) === Token::class
         && ($evidence['setup']['distinct_device'] ?? false) === true
         && ($evidence['setup']['device_count'] ?? null) === 2
         && ($evidence['users']['snapshot_old'] ?? false) === true
         && ($evidence['users']['external_rotated'] ?? false) === true
         && ($evidence['tokens']['snapshot_digest'] ?? null) === ($tokenDigest ?? null)
         && ($evidence['tokens']['external_revoked'] ?? null) === 1
         && ($evidence['trust']['snapshot_digest'] ?? null) === ($oldTrustDigest ?? null)
         && ($evidence['trust']['external_winner'] ?? null) === Token::class
         && ($evidence['trust']['before_devices'] ?? null) === 2;

      yield assert(
         assertion: $fixture,
         description: 'TOK-12 PostgreSQL fixture: distinct sessions must expose one old RR snapshot per store, commit each external mutation and clean every unique table; evidence='
            . json_encode([
               'fixture_error' => $fixtureError,
               'cleanup_error' => $cleanupError,
               'state' => $evidence,
            ])
      );

      $UsersOperation = $evidence['users']['operation'] ?? [];
      $TokensOperation = $evidence['tokens']['operation'] ?? [];
      $TrustOperation = $evidence['trust']['operation'] ?? [];
      $Serializes = static function (mixed $error): bool {
         return is_string($error)
            && str_contains(strtolower($error), 'could not serialize access due to concurrent update');
      };
      $Locked = static function (mixed $SQL): bool {
         return is_string($SQL) && str_ends_with(strtolower($SQL), ' for update');
      };
      $secure = $fixture
         && ($evidence['users']['old_accepted'] ?? true) === false
         && $Serializes($UsersOperation['error'] ?? null)
         && $Locked($UsersOperation['sql'] ?? null)
         && ($evidence['tokens']['revoked_accepted'] ?? true) === false
         && $Serializes($TokensOperation['error'] ?? null)
         && $Locked($TokensOperation['sql'] ?? null)
         && ($evidence['trust']['result'] ?? null) === 'null'
         && ($evidence['trust']['theft'] ?? true) === false
         && $Serializes($TrustOperation['error'] ?? null)
         && $Locked($TrustOperation['sql'] ?? null)
         && ($evidence['trust']['after_devices'] ?? null) === 2
         && ($evidence['fresh']['current_password'] ?? false) === true
         && ($evidence['fresh']['old_password_rejected'] ?? false) === true
         && ($evidence['fresh']['revoked_token_rejected'] ?? false) === true
         && ($evidence['fresh']['current_trust'] ?? null) === Token::class
         && ($evidence['fresh']['own_password_rotated'] ?? false) === true
         && ($evidence['fresh']['own_password'] ?? false) === true
         && ($evidence['fresh']['previous_password_rejected'] ?? false) === true
         && ($evidence['fresh']['own_token'] ?? null) === Token::class
         && ($evidence['fresh']['own_token_accepted'] ?? false) === true
         && ($evidence['fresh']['own_trust'] ?? null) === Token::class
         && ($evidence['fresh']['own_trust_rotated'] ?? null) === Token::class
         && array_key_exists('operation_error', $evidence['fresh'])
         && $evidence['fresh']['operation_error'] === null
         && ($evidence['post']['current_password'] ?? false) === true
         && ($evidence['post']['old_password_rejected'] ?? false) === true
         && ($evidence['post']['revoked_token_rejected'] ?? false) === true
         && ($evidence['post']['trust_digest'] ?? null)
            === ($evidence['post']['expected_trust_digest'] ?? null)
         && ($evidence['post']['device_count'] ?? null) === 2;

      yield assert(
         assertion: $secure,
         description: 'TOK-12: PostgreSQL stale repeatable-read verdicts must serialize and fail closed without Theft, while a fresh transaction sees current state and its own writes; evidence='
            . json_encode($evidence)
      );
   }
);
