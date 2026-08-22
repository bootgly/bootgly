<?php

namespace Bootgly\API\Security\Tests\SecurityTransactionSnapshotSQLite;


use function array_key_exists;
use function array_merge;
use function assert;
use function bin2hex;
use function defined;
use function extension_loaded;
use function gc_collect_cycles;
use function get_class;
use function get_debug_type;
use function hash;
use function implode;
use function is_array;
use function is_file;
use function is_numeric;
use function is_string;
use function json_encode;
use function mkdir;
use function password_verify;
use function random_bytes;
use function rmdir;
use function str_contains;
use function substr;
use function sys_get_temp_dir;
use function unlink;
use RuntimeException;
use SQLite3;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\API\Security\Identity;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Theft;
use Bootgly\API\Security\Tokens\Token;
use Bootgly\API\Security\Tokens\Trust;
use Bootgly\API\Security\Users;


return new Test(
   description: 'Security(SQLite): transaction verdicts fail closed when a WAL snapshot becomes obsolete',
   skip: extension_loaded('sqlite3') === false || defined('PASSWORD_ARGON2ID') === false,
   test: function () {
      $directory = sys_get_temp_dir() . '/bootgly-tok12-sqlite-' . bin2hex(random_bytes(8));

      if (mkdir($directory, 0700) === false) {
         throw new RuntimeException('TOK-12 SQLite fixture could not create its temporary directory.');
      }

      $path = "{$directory}/security.sqlite";
      $clock = 2_000_000_000;
      $oldPassword = 'tok12-sqlite-old-password';
      $currentPassword = 'tok12-sqlite-current-password';
      $ownPassword = 'tok12-sqlite-own-password';
      $email = 'tok12-sqlite-' . bin2hex(random_bytes(5)) . '@bootgly.test';
      $previousTokensGC = Tokens::$gcProbability;
      $previousTrustGC = Trust::$gcProbability;
      $Fixture = null;
      $Databases = [];
      $Transactions = [];
      $fixtureError = null;
      $cleanupError = null;
      $evidence = [
         'setup' => [],
         'snapshots' => [],
         'current' => [],
         'verdicts' => [],
         'guard' => [],
         'own_writes' => [],
         'durable' => [],
         'rollbacks' => [],
      ];

      /**
       * Execute one synchronous SQLite operation and reject recorded errors.
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
       * Open and await one transaction.
       */
      $Begin = static function (SQL $Database): Transaction {
         $Transaction = $Database->begin();
         $Operation = $Transaction->Operation;

         if ($Operation === null) {
            throw new RuntimeException('TOK-12 SQLite fixture did not expose BEGIN.');
         }

         $Transaction->await($Operation);

         if ($Operation->error !== null) {
            throw new RuntimeException($Operation->error);
         }

         return $Transaction;
      };
      /**
       * Roll back one open fixture transaction.
       */
      $Rollback = static function (Transaction $Transaction): null|string {
         if ($Transaction->depth <= 0) {
            return null;
         }

         try {
            $Operation = $Transaction->rollback();
            $Transaction->await($Operation);

            return $Operation->error;
         }
         catch (Throwable $Failure) {
            return get_class($Failure) . ': ' . $Failure->getMessage();
         }
      };
      /**
       * Count trusted devices through the committed-state facade.
       */
      $Count = static function (SQL $Database, string $user) use ($Await): int {
         $Operation = $Await(
            $Database,
            'SELECT count(*) AS total FROM trusts WHERE user_id = ?',
            [$user]
         );
         $total = $Operation->Result?->cell;

         if (is_numeric($total) === false) {
            throw new RuntimeException('TOK-12 SQLite fixture could not count trusted devices.');
         }

         return (int) $total;
      };

      try {
         // # Persist WAL before any framework handle opens. Three independent
         //   readers can then retain an old snapshot while another connection
         //   commits all security-state changes.
         $Fixture = new SQLite3($path);
         $Fixture->enableExceptions(true);
         $journal = $Fixture->querySingle('PRAGMA journal_mode = WAL');
         $Fixture->exec(<<<SQL
         CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            email_verified_at INTEGER DEFAULT NULL
         );
         CREATE TABLE tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            selector TEXT NOT NULL UNIQUE,
            verifier TEXT NOT NULL,
            user_id TEXT NOT NULL,
            purpose TEXT NOT NULL,
            expires INTEGER NOT NULL,
            UNIQUE (user_id, purpose)
         );
         CREATE TABLE trusts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            selector TEXT NOT NULL UNIQUE,
            verifier TEXT NOT NULL,
            previous TEXT DEFAULT NULL,
            rotated INTEGER DEFAULT NULL,
            user_id TEXT NOT NULL,
            expires INTEGER NOT NULL
         );
         CREATE TABLE lock_probe (
            id INTEGER PRIMARY KEY,
            marker TEXT NOT NULL
         );
         INSERT INTO lock_probe (id, marker) VALUES (1, 'initial');
         SQL);
         $Fixture->close();
         $Fixture = null;

         $config = [
            'driver' => 'sqlite',
            'database' => $path,
            'timeout' => 0.05,
            'statements' => 0,
            'pool' => ['min' => 0, 'max' => 1],
         ];
         $ExternalDatabase = new SQL($config);
         $UsersSnapshotDatabase = new SQL($config);
         $TokensSnapshotDatabase = new SQL($config);
         $TrustSnapshotDatabase = new SQL($config);
         $GuardDatabase = new SQL($config);
         $BlockedDatabase = new SQL($config);
         $OwnDatabase = new SQL($config);
         $Databases = [
            $ExternalDatabase,
            $UsersSnapshotDatabase,
            $TokensSnapshotDatabase,
            $TrustSnapshotDatabase,
            $GuardDatabase,
            $BlockedDatabase,
            $OwnDatabase,
         ];

         Tokens::$gcProbability = [0, 1];
         Trust::$gcProbability = [0, 1];
         $Password = new Password(memory: 19456, time: 2, threads: 1);
         $Users = new Users($ExternalDatabase, $Password);
         $Tokens = new Tokens($ExternalDatabase);
         $Trust = new Trust($ExternalDatabase);
         $Tokens->freeze($clock);
         $Trust->freeze($clock);

         // @ Seed one old coherent state through the public stores.
         $user = $Users->enroll($email, $oldPassword);
         if ($user === null) {
            throw new RuntimeException('TOK-12 SQLite fixture could not enroll its user.');
         }
         $IssuedToken = $Tokens->mint($user, Purposes::Recovery, 3600);
         $IssuedTrust = $Trust->issue($user, 3600);
         $Device = $Trust->issue($user, 3600);
         $tokenDigest = hash('sha256', substr($IssuedToken->value, 17));
         $oldTrustDigest = hash('sha256', substr($IssuedTrust->value, 17));
         $initialDevices = $Count($ExternalDatabase, $user);

         $evidence['setup'] = [
            'journal' => $journal,
            'user' => $user,
            'token' => get_debug_type($IssuedToken),
            'trust' => get_debug_type($IssuedTrust),
            'device' => get_debug_type($Device),
            'distinct_device' => $Device->selector !== $IssuedTrust->selector,
            'devices' => $initialDevices,
         ];

         // # Each store owns an independent snapshot. A failure in one store
         //   therefore cannot make the following stores look fail-closed merely
         //   because they inherited an already failed transaction.
         $UsersTransaction = $Begin($UsersSnapshotDatabase);
         $TokensTransaction = $Begin($TokensSnapshotDatabase);
         $TrustTransaction = $Begin($TrustSnapshotDatabase);
         $Transactions = [$UsersTransaction, $TokensTransaction, $TrustTransaction];

         $UsersSnapshot = $Await(
            $UsersTransaction,
            'SELECT password FROM users WHERE id = ?',
            [$user]
         );
         $TokensSnapshot = $Await(
            $TokensTransaction,
            'SELECT verifier FROM tokens WHERE selector = ?',
            [$IssuedToken->selector]
         );
         $TrustSnapshot = $Await(
            $TrustTransaction,
            'SELECT verifier FROM trusts WHERE selector = ?',
            [$IssuedTrust->selector]
         );
         $evidence['snapshots'] = [
            'password' => $UsersSnapshot->rows[0]['password'] ?? null,
            'token' => $TokensSnapshot->rows[0]['verifier'] ?? null,
            'trust' => $TrustSnapshot->rows[0]['verifier'] ?? null,
         ];

         // @ Commit the newer credential, revocation and remember generation
         //   through the independent facade while all three WAL snapshots stay open.
         $passwordRotated = $Users->rotate($user, $currentPassword);
         $revoked = $Tokens->revoke($user, Purposes::Recovery);
         $Winner = $Trust->rotate($IssuedTrust->value, 3600);
         if ($Winner instanceof Token === false) {
            throw new RuntimeException('TOK-12 SQLite fixture could not rotate the trust series.');
         }
         $currentTrustDigest = hash('sha256', substr($Winner->value, 17));
         $CurrentUser = $Await(
            $ExternalDatabase,
            'SELECT password FROM users WHERE id = ?',
            [$user]
         );
         $CurrentToken = $Await(
            $ExternalDatabase,
            'SELECT verifier FROM tokens WHERE selector = ?',
            [$IssuedToken->selector]
         );
         $CurrentTrust = $Await(
            $ExternalDatabase,
            'SELECT verifier FROM trusts WHERE selector = ?',
            [$IssuedTrust->selector]
         );
         $evidence['current'] = [
            'password_rotated' => $passwordRotated,
            'token_rows_revoked' => $revoked,
            'trust_winner' => get_debug_type($Winner),
            'password' => $CurrentUser->rows[0]['password'] ?? null,
            'token_count' => $CurrentToken->Result?->count,
            'trust' => $CurrentTrust->rows[0]['verifier'] ?? null,
            'devices' => $Count($ExternalDatabase, $user),
         ];

         // ! Source to sink. Vulnerable ordinary SELECTs accept the old password
         //   and revoked token and fabricate Theft from the old Trust snapshot.
         //   A secure SQLite current-read guard must instead fail each store closed.
         $TransactionUsers = new Users($UsersTransaction, $Password);
         $TransactionTokens = new Tokens($TokensTransaction);
         $TransactionTrust = new Trust($TrustTransaction);
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
            'users_error' => $UsersTransaction->Operation?->error,
            'revoked_token_accepted' => $revokedAccepted,
            'tokens_error' => $TokensTransaction->Operation?->error,
            'trust_result' => get_debug_type($TransactionWinner),
            'trust_null' => $TransactionWinner === null,
            'trust_theft' => $TransactionWinner instanceof Theft,
            'trust_error' => $TrustTransaction->Operation?->error,
         ];

         foreach (
            [
               'users' => $UsersTransaction,
               'tokens' => $TokensTransaction,
               'trust' => $TrustTransaction,
            ] as $name => $Transaction
         ) {
            $evidence['rollbacks'][$name] = $Rollback($Transaction);
         }
         $evidence['verdicts']['devices'] = $Count($ExternalDatabase, $user);

         // # Fresh-guard control. A decision read must affect zero rows while
         //   acquiring SQLite's database-wide writer claim. An independent
         //   connection must then be unable to modify even an unrelated table.
         $GuardTransaction = $Begin($GuardDatabase);
         $Transactions[] = $GuardTransaction;
         $GuardUsers = new Users($GuardTransaction, $Password);
         $GuardFetched = $GuardUsers->fetch($email);
         $GuardChanges = $Await($GuardTransaction, 'SELECT changes() AS affected');
         $guardAffected = $GuardChanges->Result?->cell;

         $Blocked = $BlockedDatabase->query(
            "UPDATE lock_probe SET marker = 'external-write' WHERE id = 1"
         );
         $blockedThrow = null;
         try {
            $BlockedDatabase->await($Blocked);
         }
         catch (Throwable $Failure) {
            $blockedThrow = get_class($Failure) . ': ' . $Failure->getMessage();
         }
         $GuardMarker = $Await(
            $ExternalDatabase,
            'SELECT marker FROM lock_probe WHERE id = 1'
         );
         $GuardUser = $Await(
            $ExternalDatabase,
            'SELECT password FROM users WHERE id = ?',
            [$user]
         );
         $evidence['guard'] = [
            'fetched' => $GuardFetched instanceof Identity,
            'affected' => $guardAffected,
            'blocked_error' => $Blocked->error,
            'blocked_throw' => $blockedThrow,
            'marker' => $GuardMarker->Result?->cell,
            'password' => $GuardUser->Result?->cell,
            'rollback' => $Rollback($GuardTransaction),
         ];

         // @ Restore the disposable marker after the vulnerable baseline lets
         //   the competing write through; the secure path is already unchanged.
         $Await(
            $ExternalDatabase,
            "UPDATE lock_probe SET marker = 'initial' WHERE id = 1"
         );

         // # Read-your-writes control for all three stores. The current-read
         //   mechanism must stay on the caller's connection and preserve its
         //   own uncommitted credential, token and trust generation.
         $OwnTransaction = $Begin($OwnDatabase);
         $Transactions[] = $OwnTransaction;
         $OwnUsers = new Users($OwnTransaction, $Password);
         $OwnTokens = new Tokens($OwnTransaction);
         $OwnTrust = new Trust($OwnTransaction);
         $OwnTokens->freeze($clock);
         $OwnTrust->freeze($clock);
         $ownPasswordRotated = $OwnUsers->rotate($user, $ownPassword);
         $ownPasswordAccepted = $OwnUsers->check($user, $ownPassword);
         $OwnToken = $OwnTokens->mint($user, Purposes::Verification, 3600);
         $ownTokenAccepted = $OwnTokens->check(
            $OwnToken->value,
            Purposes::Verification
         );
         $OwnTrustToken = $OwnTrust->issue($user, 3600);
         $OwnTrustWinner = $OwnTrust->rotate($OwnTrustToken->value, 3600);
         $evidence['own_writes'] = [
            'password_rotated' => $ownPasswordRotated,
            'password_accepted' => $ownPasswordAccepted,
            'token' => get_debug_type($OwnToken),
            'token_accepted' => $ownTokenAccepted,
            'trust' => get_debug_type($OwnTrustToken),
            'trust_winner' => get_debug_type($OwnTrustWinner),
            'rollback' => $Rollback($OwnTransaction),
         ];

         // @ Rollback must leave only the independently committed current state.
         $DurableUser = $Await(
            $ExternalDatabase,
            'SELECT password FROM users WHERE id = ?',
            [$user]
         );
         $DurableToken = $Await(
            $ExternalDatabase,
            'SELECT count(*) AS total FROM tokens WHERE selector = ?',
            [$OwnToken->selector]
         );
         $DurableTrust = $Await(
            $ExternalDatabase,
            'SELECT count(*) AS total FROM trusts WHERE selector = ?',
            [$OwnTrustToken->selector]
         );
         $evidence['durable'] = [
            'password' => $DurableUser->Result?->cell,
            'own_token_rows' => $DurableToken->Result?->cell,
            'own_trust_rows' => $DurableTrust->Result?->cell,
            'devices' => $Count($ExternalDatabase, $user),
         ];

         $evidence['expected'] = [
            'token' => $tokenDigest,
            'old_trust' => $oldTrustDigest,
            'current_trust' => $currentTrustDigest,
         ];
      }
      catch (Throwable $Failure) {
         $fixtureError = get_class($Failure) . ': ' . $Failure->getMessage();
      }
      finally {
         Tokens::$gcProbability = $previousTokensGC;
         Trust::$gcProbability = $previousTrustGC;
         $cleanupErrors = [];

         foreach ($Transactions as $Transaction) {
            $error = $Rollback($Transaction);

            if ($error !== null) {
               $cleanupErrors[] = $error;
            }
         }

         foreach ($Databases as $Database) {
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

         if ($Fixture instanceof SQLite3) {
            $Fixture->close();
         }

         unset(
            $OwnTrust,
            $OwnTokens,
            $OwnUsers,
            $GuardUsers,
            $TransactionTrust,
            $TransactionTokens,
            $TransactionUsers,
            $Trust,
            $Tokens,
            $Users,
            $Password,
            $Transactions,
            $Databases
         );
         gc_collect_cycles();

         foreach ([$path, "{$path}-wal", "{$path}-shm"] as $file) {
            if (is_file($file) && unlink($file) === false) {
               $cleanupErrors[] = "Could not remove {$file}.";
            }
         }

         if (rmdir($directory) === false) {
            $cleanupErrors[] = "Could not remove {$directory}.";
         }

         if ($cleanupErrors !== []) {
            $cleanupError = implode('; ', $cleanupErrors);
         }
      }

      $expected = $evidence['expected'] ?? null;
      $snapshotPassword = $evidence['snapshots']['password'] ?? null;
      $currentPasswordHash = $evidence['current']['password'] ?? null;
      $durablePasswordHash = $evidence['durable']['password'] ?? null;
      $fixture = $fixtureError === null
         && $cleanupError === null
         && is_array($expected)
         && ($evidence['setup']['journal'] ?? null) === 'wal'
         && is_string($evidence['setup']['user'] ?? null)
         && ($evidence['setup']['token'] ?? null) === Token::class
         && ($evidence['setup']['trust'] ?? null) === Token::class
         && ($evidence['setup']['device'] ?? null) === Token::class
         && ($evidence['setup']['distinct_device'] ?? false) === true
         && ($evidence['setup']['devices'] ?? null) === 2
         && is_string($snapshotPassword)
         && password_verify($oldPassword, $snapshotPassword)
         && ($evidence['snapshots']['token'] ?? null) === $expected['token']
         && ($evidence['snapshots']['trust'] ?? null) === $expected['old_trust']
         && ($evidence['current']['password_rotated'] ?? false) === true
         && ($evidence['current']['token_rows_revoked'] ?? null) === 1
         && ($evidence['current']['trust_winner'] ?? null) === Token::class
         && is_string($currentPasswordHash)
         && password_verify($currentPassword, $currentPasswordHash)
         && password_verify($oldPassword, $currentPasswordHash) === false
         && ($evidence['current']['token_count'] ?? null) === 0
         && ($evidence['current']['trust'] ?? null) === $expected['current_trust']
         && ($evidence['current']['devices'] ?? null) === 2
         && array_key_exists('users', $evidence['rollbacks'])
         && $evidence['rollbacks']['users'] === null
         && array_key_exists('tokens', $evidence['rollbacks'])
         && $evidence['rollbacks']['tokens'] === null
         && array_key_exists('trust', $evidence['rollbacks'])
         && $evidence['rollbacks']['trust'] === null;

      yield assert(
         assertion: $fixture,
         description: 'TOK-12 SQLite fixture: WAL must retain three coherent old snapshots beside '
            . 'the independently committed credential, revocation and trust generation; evidence='
            . json_encode([
               'fixture_error' => $fixtureError,
               'cleanup_error' => $cleanupError,
               'state' => $evidence,
            ])
      );

      $usersError = $evidence['verdicts']['users_error'] ?? null;
      $tokensError = $evidence['verdicts']['tokens_error'] ?? null;
      $trustError = $evidence['verdicts']['trust_error'] ?? null;
      $blockedError = $evidence['guard']['blocked_error'] ?? null;
      $secure = $fixture
         && ($evidence['verdicts']['old_password_accepted'] ?? true) === false
         && ($evidence['verdicts']['current_password_accepted'] ?? true) === false
         && is_string($usersError)
         && str_contains($usersError, 'database is locked')
         && ($evidence['verdicts']['revoked_token_accepted'] ?? true) === false
         && is_string($tokensError)
         && str_contains($tokensError, 'database is locked')
         && ($evidence['verdicts']['trust_null'] ?? false) === true
         && ($evidence['verdicts']['trust_theft'] ?? true) === false
         && is_string($trustError)
         && str_contains($trustError, 'database is locked')
         && ($evidence['verdicts']['devices'] ?? null) === 2
         && ($evidence['guard']['fetched'] ?? false) === true
         && ($evidence['guard']['affected'] ?? null) === 0
         && is_string($blockedError)
         && str_contains($blockedError, 'database is locked')
         && is_string($evidence['guard']['blocked_throw'] ?? null)
         && ($evidence['guard']['marker'] ?? null) === 'initial'
         && ($evidence['guard']['password'] ?? null) === $currentPasswordHash
         && array_key_exists('rollback', $evidence['guard'])
         && $evidence['guard']['rollback'] === null
         && ($evidence['own_writes']['password_rotated'] ?? false) === true
         && ($evidence['own_writes']['password_accepted'] ?? false) === true
         && ($evidence['own_writes']['token'] ?? null) === Token::class
         && ($evidence['own_writes']['token_accepted'] ?? false) === true
         && ($evidence['own_writes']['trust'] ?? null) === Token::class
         && ($evidence['own_writes']['trust_winner'] ?? null) === Token::class
         && array_key_exists('rollback', $evidence['own_writes'])
         && $evidence['own_writes']['rollback'] === null
         && is_string($durablePasswordHash)
         && password_verify($currentPassword, $durablePasswordHash)
         && password_verify($ownPassword, $durablePasswordHash) === false
         && ($evidence['durable']['own_token_rows'] ?? null) === 0
         && ($evidence['durable']['own_trust_rows'] ?? null) === 0
         && ($evidence['durable']['devices'] ?? null) === 2;

      yield assert(
         assertion: $secure,
         description: 'TOK-12 CONFIRMED: obsolete SQLite WAL transaction snapshots must fail Users, '
            . 'Tokens and Trust closed without Theft, while a fresh zero-row guard serializes writers '
            . 'and preserves every store\'s own uncommitted writes; evidence=' . json_encode($evidence)
      );
   }
);
