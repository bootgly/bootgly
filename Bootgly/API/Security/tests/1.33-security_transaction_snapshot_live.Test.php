<?php

namespace Bootgly\API\Security\Tests\SecurityTransactionSnapshotLive;


use function array_key_exists;
use function assert;
use function bin2hex;
use function defined;
use function get_class;
use function get_debug_type;
use function getenv;
use function hash;
use function is_array;
use function is_numeric;
use function is_string;
use function json_encode;
use function password_verify;
use function random_bytes;
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


// ! Opt-in real-driver E2E. Once explicitly enabled, connection or fixture
//   failures are assertions rather than skips so they cannot resemble safety.
$optin = getenv('BOOTGLY_TOK12_E2E') === '1';


return new Test(
   description: 'Security(live): transaction-backed verdicts bypass an old MySQL repeatable-read snapshot (requires BOOTGLY_TOK12_E2E=1)',
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
      $prefix = 'bootgly_tok12_' . bin2hex(random_bytes(6));
      $usersTable = "{$prefix}_users";
      $tokensTable = "{$prefix}_tokens";
      $trustsTable = "{$prefix}_trusts";
      $usersSQL = "`{$usersTable}`";
      $tokensSQL = "`{$tokensTable}`";
      $trustsSQL = "`{$trustsTable}`";
      $oldPassword = 'tok12-old-password';
      $currentPassword = 'tok12-current-password';
      $transactionPassword = 'tok12-transaction-password';
      $email = 'tok12-' . bin2hex(random_bytes(5)) . '@bootgly.test';
      $clock = 2_000_000_000;
      $previousTokensGC = Tokens::$gcProbability;
      $previousTrustGC = Trust::$gcProbability;
      $ExternalDatabase = null;
      $SnapshotDatabase = null;
      $Transaction = null;
      $ControlTransaction = null;
      $fixtureError = null;
      $cleanupError = null;
      $evidence = [
         'setup' => [],
         'connections' => [],
         'initial_snapshot' => null,
         'old_snapshot' => null,
         'current_state' => null,
         'mutations' => [],
         'verdicts' => [],
         'post_commit' => [],
         'read_your_writes' => [],
         'rollback_state' => [],
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
       * Read all three security facts through one coherent MySQL snapshot.
       *
       * @return array<string,mixed>
       */
      $Read = static function (
         SQL|Transaction $Database,
         string $usersSQL,
         string $tokensSQL,
         string $trustsSQL,
         string $user,
         string $tokenSelector,
         string $trustSelector
      ) use ($Await): array {
         $Operation = $Await(
            $Database,
            'SELECT CONNECTION_ID() AS connection_id, '
               . '@@transaction_isolation AS isolation_level, '
               . "(SELECT password FROM {$usersSQL} WHERE id = ?) AS password_hash, "
               . "(SELECT verifier FROM {$tokensSQL} WHERE selector = ?) AS token_verifier, "
               . "(SELECT verifier FROM {$trustsSQL} WHERE selector = ?) AS trust_verifier",
            [$user, $tokenSelector, $trustSelector]
         );

         return $Operation->rows[0] ?? [];
      };
      /**
       * Count the user's persisted trusted-device series.
       */
      $Count = static function (
         SQL|Transaction $Database,
         string $trustsSQL,
         string $user
      ) use ($Await): int {
         $Operation = $Await(
            $Database,
            "SELECT count(*) AS total FROM {$trustsSQL} WHERE user_id = ?",
            [$user]
         );
         $total = $Operation->Result?->cell;

         if (is_numeric($total) === false) {
            throw new RuntimeException('TOK-12 fixture could not count trusted devices.');
         }

         return (int) $total;
      };

      try {
         if (defined('PASSWORD_ARGON2ID') === false) {
            throw new RuntimeException('TOK-12 fixture requires Argon2id support.');
         }

         // # Two independent facades guarantee that the mutations commit on a
         //   different server session from the deliberately old snapshot.
         $ExternalDatabase = new SQL($config);
         $SnapshotDatabase = new SQL($config);
         $ExternalWarm = $Await($ExternalDatabase, 'SELECT CONNECTION_ID() AS connection_id');
         $Await($SnapshotDatabase, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

         $Await(
            $ExternalDatabase,
            "CREATE TABLE {$usersSQL} ("
               . 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
               . 'email VARCHAR(191) NOT NULL UNIQUE, '
               . 'password VARCHAR(255) NOT NULL, '
               . 'email_verified_at BIGINT NULL'
               . ') ENGINE=InnoDB'
         );
         $Await(
            $ExternalDatabase,
            "CREATE TABLE {$tokensSQL} ("
               . 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
               . 'selector VARCHAR(16) NOT NULL UNIQUE, '
               . 'verifier VARCHAR(64) NOT NULL, '
               . 'user_id VARCHAR(191) NOT NULL, '
               . 'purpose VARCHAR(32) NOT NULL, '
               . 'expires BIGINT NOT NULL'
               . ') ENGINE=InnoDB'
         );
         $Await(
            $ExternalDatabase,
            "CREATE TABLE {$trustsSQL} ("
               . 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
               . 'selector VARCHAR(16) NOT NULL UNIQUE, '
               . 'verifier VARCHAR(64) NOT NULL, '
               . 'previous VARCHAR(64) NULL, '
               . 'rotated BIGINT NULL, '
               . 'user_id VARCHAR(191) NOT NULL, '
               . 'expires BIGINT NOT NULL'
               . ') ENGINE=InnoDB'
         );

         Tokens::$gcProbability = [0, 1];
         Trust::$gcProbability = [0, 1];
         $Password = new Password(memory: 19456, time: 2, threads: 1);
         $Users = new Users($ExternalDatabase, $Password, $usersTable);
         $Tokens = new Tokens($ExternalDatabase, $tokensTable);
         $Trust = new Trust($ExternalDatabase, $trustsTable);
         $Tokens->freeze($clock);
         $Trust->freeze($clock);

         // @ Build every initial fact through the same public stores that an
         //   application uses; the second trust row is the collateral device.
         $user = $Users->enroll($email, $oldPassword);
         if ($user === null) {
            throw new RuntimeException('TOK-12 fixture could not enroll its user.');
         }
         $IssuedToken = $Tokens->mint($user, Purposes::Recovery, 3600);
         $IssuedTrust = $Trust->issue($user, 3600);
         $Device = $Trust->issue($user, 3600);
         $tokenDigest = hash('sha256', substr($IssuedToken->value, 17));
         $oldTrustDigest = hash('sha256', substr($IssuedTrust->value, 17));
         $initialCount = $Count($ExternalDatabase, $trustsSQL, $user);
         $evidence['setup'] = [
            'user' => $user,
            'token' => get_debug_type($IssuedToken),
            'trust' => get_debug_type($IssuedTrust),
            'device' => get_debug_type($Device),
            'distinct_device' => $Device->selector !== $IssuedTrust->selector,
            'device_count' => $initialCount,
         ];

         // # Establish the repeatable-read snapshot before any independent
         //   mutation. This first table read fixes one coherent old view for
         //   every later ordinary SELECT in the transaction.
         $Transaction = $SnapshotDatabase->begin();
         $Begin = $Transaction->Operation;
         if ($Begin === null) {
            throw new RuntimeException('TOK-12 fixture did not expose BEGIN.');
         }
         $Transaction->await($Begin);
         if ($Begin->error !== null) {
            throw new RuntimeException($Begin->error);
         }

         $initialSnapshot = $Read(
            $Transaction,
            $usersSQL,
            $tokensSQL,
            $trustsSQL,
            $user,
            $IssuedToken->selector,
            $IssuedTrust->selector
         );
         $externalConnection = $ExternalWarm->rows[0]['connection_id'] ?? null;
         $snapshotConnection = $initialSnapshot['connection_id'] ?? null;
         $evidence['connections'] = [
            'external' => $externalConnection,
            'snapshot' => $snapshotConnection,
            'distinct' => is_numeric($externalConnection)
               && is_numeric($snapshotConnection)
               && (string) $externalConnection !== (string) $snapshotConnection,
         ];
         $evidence['initial_snapshot'] = $initialSnapshot;

         // @ Commit all three security changes through the other facade.
         //   The original transaction remains open on its earlier snapshot.
         $passwordRotated = $Users->rotate($user, $currentPassword);
         $revoked = $Tokens->revoke($user, Purposes::Recovery);
         $Winner = $Trust->rotate($IssuedTrust->value, 3600);
         if ($Winner instanceof Token === false) {
            throw new RuntimeException('TOK-12 fixture could not rotate the current trust series.');
         }
         $currentTrustDigest = hash('sha256', substr($Winner->value, 17));
         $beforeVerdictsCount = $Count($ExternalDatabase, $trustsSQL, $user);
         $evidence['mutations'] = [
            'password_rotated' => $passwordRotated,
            'token_rows_revoked' => $revoked,
            'trust_winner' => get_debug_type($Winner),
            'device_count' => $beforeVerdictsCount,
         ];

         // @ Paired direct controls prove that the transaction is genuinely
         //   old while the independent session already observes every commit.
         $oldSnapshot = $Read(
            $Transaction,
            $usersSQL,
            $tokensSQL,
            $trustsSQL,
            $user,
            $IssuedToken->selector,
            $IssuedTrust->selector
         );
         $currentState = $Read(
            $ExternalDatabase,
            $usersSQL,
            $tokensSQL,
            $trustsSQL,
            $user,
            $IssuedToken->selector,
            $IssuedTrust->selector
         );
         $evidence['old_snapshot'] = $oldSnapshot;
         $evidence['current_state'] = $currentState;

         // ! Source to sink: the public stores must make current security
         //   decisions even though their explicit Transaction already owns an
         //   older consistent snapshot. Gather every verdict before asserting.
         $TransactionUsers = new Users($Transaction, $Password, $usersTable);
         $TransactionTokens = new Tokens($Transaction, $tokensTable);
         $TransactionTrust = new Trust($Transaction, $trustsTable);
         $TransactionTokens->freeze($clock);
         $TransactionTrust->freeze($clock);
         $oldAccepted = $TransactionUsers->check($user, $oldPassword);
         $currentAccepted = $TransactionUsers->check($user, $currentPassword);
         $revokedAccepted = $TransactionTokens->check(
            $IssuedToken->value,
            Purposes::Recovery
         );
         $TransactionWinner = $TransactionTrust->rotate($Winner->value, 3600);
         $evidence['verdicts'] = [
            'old_password_accepted' => $oldAccepted,
            'current_password_accepted' => $currentAccepted,
            'revoked_token_accepted' => $revokedAccepted,
            'trust_result' => get_debug_type($TransactionWinner),
            'trust_token' => $TransactionWinner instanceof Token,
            'trust_theft' => $TransactionWinner instanceof Theft,
         ];

         $Commit = $Transaction->commit();
         $Transaction->await($Commit);
         if ($Commit->error !== null) {
            throw new RuntimeException($Commit->error);
         }

         $postCommitCount = $Count($ExternalDatabase, $trustsSQL, $user);
         $Followup = $TransactionWinner instanceof Token
            ? $Trust->rotate($TransactionWinner->value, 3600)
            : null;
         $followupCount = $Count($ExternalDatabase, $trustsSQL, $user);
         $evidence['post_commit'] = [
            'commit_finished' => $Commit->finished,
            'commit_error' => $Commit->error,
            'device_count' => $postCommitCount,
            'followup' => get_debug_type($Followup),
            'followup_token' => $Followup instanceof Token,
            'followup_device_count' => $followupCount,
         ];

         // # A freshness fix must not bypass the explicit transaction. Prove
         //   that a new transaction sees its own uncommitted writes in every
         //   store, then rolls all of them back as one unit.
         $controlBeforeCount = $followupCount;
         $ControlTransaction = $SnapshotDatabase->begin();
         $ControlBegin = $ControlTransaction->Operation;
         if ($ControlBegin === null) {
            throw new RuntimeException('TOK-12 read-your-writes control did not expose BEGIN.');
         }
         $ControlTransaction->await($ControlBegin);
         if ($ControlBegin->error !== null) {
            throw new RuntimeException($ControlBegin->error);
         }

         $ControlUsers = new Users($ControlTransaction, $Password, $usersTable);
         $ControlTokens = new Tokens($ControlTransaction, $tokensTable);
         $ControlTrust = new Trust($ControlTransaction, $trustsTable);
         $ControlTokens->freeze($clock);
         $ControlTrust->freeze($clock);
         $controlPasswordRotated = $ControlUsers->rotate($user, $transactionPassword);
         $controlPasswordAccepted = $ControlUsers->check($user, $transactionPassword);
         $currentPasswordAccepted = $ControlUsers->check($user, $currentPassword);
         $ControlToken = $ControlTokens->mint($user, Purposes::Verification, 3600);
         $controlTokenAccepted = $ControlTokens->check(
            $ControlToken->value,
            Purposes::Verification
         );
         $ControlTrustIssued = $ControlTrust->issue($user, 3600);
         $ControlTrustWinner = $ControlTrust->rotate($ControlTrustIssued->value, 3600);
         $controlInsideCount = $Count($ControlTransaction, $trustsSQL, $user);
         $evidence['read_your_writes'] = [
            'begin_finished' => $ControlBegin->finished,
            'password_rotated' => $controlPasswordRotated,
            'transaction_password_accepted' => $controlPasswordAccepted,
            'current_password_accepted' => $currentPasswordAccepted,
            'token' => get_debug_type($ControlToken),
            'token_accepted' => $controlTokenAccepted,
            'trust' => get_debug_type($ControlTrustIssued),
            'trust_winner' => get_debug_type($ControlTrustWinner),
            'trust_rotated' => $ControlTrustWinner instanceof Token,
            'device_count_before' => $controlBeforeCount,
            'device_count_inside' => $controlInsideCount,
         ];

         $ControlRollback = $ControlTransaction->rollback();
         $ControlTransaction->await($ControlRollback);
         if ($ControlRollback->error !== null) {
            throw new RuntimeException($ControlRollback->error);
         }

         // @ The external facade must see none of those rolled-back writes,
         //   while the current durable remember token remains usable.
         $externalCurrentAccepted = $Users->check($user, $currentPassword);
         $externalTransactionAccepted = $Users->check($user, $transactionPassword);
         $externalTokenAccepted = $Tokens->check(
            $ControlToken->value,
            Purposes::Verification
         );
         $ExternalControlTrust = $Trust->rotate($ControlTrustIssued->value, 3600);
         $Durable = $Followup instanceof Token
            ? $Trust->rotate($Followup->value, 3600)
            : null;
         $controlAfterCount = $Count($ExternalDatabase, $trustsSQL, $user);
         $evidence['rollback_state'] = [
            'rollback_finished' => $ControlRollback->finished,
            'rollback_error' => $ControlRollback->error,
            'current_password_accepted' => $externalCurrentAccepted,
            'transaction_password_accepted' => $externalTransactionAccepted,
            'rolled_back_token_accepted' => $externalTokenAccepted,
            'rolled_back_trust' => get_debug_type($ExternalControlTrust),
            'durable_trust' => get_debug_type($Durable),
            'durable_trust_token' => $Durable instanceof Token,
            'device_count_before' => $controlBeforeCount,
            'device_count_after' => $controlAfterCount,
         ];

         // @ Expected values stay in evidence so a vulnerable failure is
         //   reproducible without inferring which generation each hash names.
         $evidence['expected'] = [
            'token_digest' => $tokenDigest,
            'old_trust_digest' => $oldTrustDigest,
            'current_trust_digest' => $currentTrustDigest,
         ];
      }
      catch (Throwable $Failure) {
         $fixtureError = get_class($Failure) . ': ' . $Failure->getMessage();
      }
      finally {
         Tokens::$gcProbability = $previousTokensGC;
         Trust::$gcProbability = $previousTrustGC;

         foreach ([$ControlTransaction, $Transaction] as $ActiveTransaction) {
            if ($ActiveTransaction instanceof Transaction && $ActiveTransaction->depth > 0) {
               try {
                  $Rollback = $ActiveTransaction->rollback();
                  $ActiveTransaction->await($Rollback);

                  if ($Rollback->error !== null) {
                     throw new RuntimeException($Rollback->error);
                  }
               }
               catch (Throwable $Failure) {
                  $rollbackError = get_class($Failure) . ': ' . $Failure->getMessage();
                  $cleanupError = $cleanupError === null
                     ? $rollbackError
                     : "{$cleanupError}; {$rollbackError}";
               }
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

         foreach ([$SnapshotDatabase, $ExternalDatabase] as $Database) {
            if ($Database instanceof SQL) {
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
      }

      $initialSnapshot = $evidence['initial_snapshot'];
      $oldSnapshot = $evidence['old_snapshot'];
      $currentState = $evidence['current_state'];
      $expected = $evidence['expected'] ?? null;
      $fixture = $fixtureError === null
         && $cleanupError === null
         && is_array($initialSnapshot)
         && is_array($oldSnapshot)
         && is_array($currentState)
         && is_array($expected)
         && ($evidence['connections']['distinct'] ?? false) === true
         && ($evidence['setup']['token'] ?? null) === Token::class
         && ($evidence['setup']['trust'] ?? null) === Token::class
         && ($evidence['setup']['device'] ?? null) === Token::class
         && ($evidence['setup']['distinct_device'] ?? false) === true
         && ($evidence['setup']['device_count'] ?? null) === 2
         && is_string($initialSnapshot['isolation_level'] ?? null)
         && $initialSnapshot['isolation_level'] === 'REPEATABLE-READ'
         && is_string($initialSnapshot['password_hash'] ?? null)
         && password_verify($oldPassword, $initialSnapshot['password_hash'])
         && ($initialSnapshot['token_verifier'] ?? null) === $expected['token_digest']
         && ($initialSnapshot['trust_verifier'] ?? null) === $expected['old_trust_digest']
         && ($evidence['mutations']['password_rotated'] ?? false) === true
         && ($evidence['mutations']['token_rows_revoked'] ?? null) === 1
         && ($evidence['mutations']['trust_winner'] ?? null) === Token::class
         && ($evidence['mutations']['device_count'] ?? null) === 2
         && ($oldSnapshot['password_hash'] ?? null) === $initialSnapshot['password_hash']
         && ($oldSnapshot['token_verifier'] ?? null) === $expected['token_digest']
         && ($oldSnapshot['trust_verifier'] ?? null) === $expected['old_trust_digest']
         && is_string($currentState['password_hash'] ?? null)
         && $currentState['password_hash'] !== $initialSnapshot['password_hash']
         && password_verify($currentPassword, $currentState['password_hash'])
         && password_verify($oldPassword, $currentState['password_hash']) === false
         && array_key_exists('token_verifier', $currentState)
         && $currentState['token_verifier'] === null
         && ($currentState['trust_verifier'] ?? null) === $expected['current_trust_digest']
         && ($evidence['post_commit']['commit_finished'] ?? false) === true
         && ($evidence['read_your_writes']['begin_finished'] ?? false) === true
         && ($evidence['rollback_state']['rollback_finished'] ?? false) === true;

      yield assert(
         assertion: $fixture,
         description: 'TOK-12 fixture: two MySQL sessions must expose an old repeatable-read snapshot '
            . 'beside the independently committed security state and clean all unique tables; evidence='
            . json_encode([
               'fixture_error' => $fixtureError,
               'cleanup_error' => $cleanupError,
               'state' => $evidence,
            ])
      );

      $secure = $fixture
         && ($evidence['verdicts']['old_password_accepted'] ?? true) === false
         && ($evidence['verdicts']['current_password_accepted'] ?? false) === true
         && ($evidence['verdicts']['revoked_token_accepted'] ?? true) === false
         && ($evidence['verdicts']['trust_token'] ?? false) === true
         && ($evidence['verdicts']['trust_theft'] ?? true) === false
         && ($evidence['post_commit']['device_count'] ?? null) === 2
         && ($evidence['post_commit']['followup_token'] ?? false) === true
         && ($evidence['post_commit']['followup_device_count'] ?? null) === 2
         && ($evidence['read_your_writes']['password_rotated'] ?? false) === true
         && ($evidence['read_your_writes']['transaction_password_accepted'] ?? false) === true
         && ($evidence['read_your_writes']['current_password_accepted'] ?? true) === false
         && ($evidence['read_your_writes']['token'] ?? null) === Token::class
         && ($evidence['read_your_writes']['token_accepted'] ?? false) === true
         && ($evidence['read_your_writes']['trust'] ?? null) === Token::class
         && ($evidence['read_your_writes']['trust_rotated'] ?? false) === true
         && ($evidence['read_your_writes']['device_count_before'] ?? null) === 2
         && ($evidence['read_your_writes']['device_count_inside'] ?? null) === 3
         && ($evidence['rollback_state']['current_password_accepted'] ?? false) === true
         && ($evidence['rollback_state']['transaction_password_accepted'] ?? true) === false
         && ($evidence['rollback_state']['rolled_back_token_accepted'] ?? true) === false
         && ($evidence['rollback_state']['rolled_back_trust'] ?? false) === 'null'
         && ($evidence['rollback_state']['durable_trust_token'] ?? false) === true
         && ($evidence['rollback_state']['device_count_before'] ?? null) === 2
         && ($evidence['rollback_state']['device_count_after'] ?? null) === 2;

      yield assert(
         assertion: $secure,
         description: 'TOK-12 CONFIRMED: transaction-backed Users, Tokens and Trust must not derive '
            . 'security verdicts from an older repeatable-read snapshot; evidence='
            . json_encode($evidence)
      );
   }
);
