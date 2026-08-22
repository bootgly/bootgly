<?php

namespace Bootgly\API\Security\Tests\TokensSupersedeFailure;


use function assert;
use function extension_loaded;
use function get_debug_type;
use function hash;
use function json_encode;
use function substr;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Token;
use Bootgly\API\Security\Tokens\Trust;


return new Test(
   description: 'Security: atomically supersede action tokens and preserve revocation failures',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->await($Database->query(<<<SQL
      CREATE TABLE tokens (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         selector TEXT NOT NULL UNIQUE,
         verifier TEXT NOT NULL,
         user_id INTEGER NOT NULL,
         purpose TEXT NOT NULL,
         expires INTEGER NOT NULL,
         created_at TEXT DEFAULT CURRENT_TIMESTAMP,
         UNIQUE (user_id, purpose)
      )
      SQL));
      $Database->await($Database->query(<<<SQL
      CREATE TABLE trusts (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         selector TEXT NOT NULL UNIQUE,
         verifier TEXT NOT NULL,
         previous TEXT DEFAULT NULL,
         rotated INTEGER DEFAULT NULL,
         user_id INTEGER NOT NULL,
         expires INTEGER NOT NULL,
         created_at TEXT DEFAULT CURRENT_TIMESTAMP
      )
      SQL));

      $Count = static function () use ($Database): int {
         $Operation = $Database->await($Database->query(
            'SELECT count(*) AS total FROM tokens WHERE user_id = $1 AND purpose = $2',
            ['7', Purposes::Recovery->value]
         ));

         return (int) ($Operation->rows[0]['total'] ?? -1);
      };
      $CountTrust = static function () use ($Database): int {
         $Operation = $Database->await($Database->query(
            'SELECT count(*) AS total FROM trusts WHERE user_id = $1',
            ['9']
         ));

         return (int) ($Operation->rows[0]['total'] ?? -1);
      };

      $previousGC = Tokens::$gcProbability;
      $previousTrustGC = Trust::$gcProbability;
      Tokens::$gcProbability = [0, 1];
      Trust::$gcProbability = [0, 1];

      try {
         $Tokens = new Tokens($Database);
         $Tokens->freeze(1_000_000);
         $Original = $Tokens->mint('7', Purposes::Recovery, ttl: 3600);

         $Database->await($Database->query(<<<SQL
         CREATE TRIGGER tokens_delete_failure
         BEFORE DELETE ON tokens
         WHEN OLD.user_id = 7 AND OLD.purpose = 'recovery'
         BEGIN
            SELECT RAISE(ABORT, 'injected token supersede failure');
         END
         SQL));

         // @ Fixture control: the targeted DELETE records the injected error
         //   and preserves the original live token.
         $Blocked = $Database->query(
            'DELETE FROM tokens WHERE user_id = $1 AND purpose = $2',
            ['7', Purposes::Recovery->value]
         );
         if ($Blocked->error === null) {
            $Blocked = $Database->await($Blocked);
         }
         $blockedCount = $Count();

         // @ Fixture control: INSERT remains available after the failed DELETE,
         //   so a second row proves the Tokens source-to-sink rather than a dead
         //   database connection. The unrelated control row is removed again.
         $Inserted = $Database->await($Database->query(
            <<<'SQL'
            INSERT INTO tokens (selector, verifier, user_id, purpose, expires)
            VALUES ($1, $2, $3, $4, $5)
            SQL,
            [
               '0000000000000000',
               hash('sha256', 'fixture-validator'),
               '8',
               Purposes::Verification->value,
               1_003_600,
            ]
         ));
         $Cleaned = $Database->await($Database->query(
            'DELETE FROM tokens WHERE selector = $1',
            ['0000000000000000']
         ));

         // @ The public revocation result must distinguish a database error
         //   from a successful DELETE that legitimately matched zero rows.
         $tokensRevoked = $Tokens->revoke('7', Purposes::Recovery);

         // ! Retained TOK-3 source-to-sink: mint no longer executes the blocked
         //   DELETE. Its unique-key upsert replaces the one user-purpose row
         //   atomically, so the old wire token dies as the replacement becomes
         //   live without creating a duplicate interval.
         $Replacement = null;
         $failure = null;
         try {
            $Replacement = $Tokens->mint('7', Purposes::Recovery, ttl: 3600);
         }
         catch (RuntimeException $Failure) {
            $failure = $Failure->getMessage();
         }

         $finalCount = $Count();
         $originalLive = $Tokens->check($Original->value, Purposes::Recovery);
         $replacementLive = $Replacement instanceof Token
            ? $Tokens->check($Replacement->value, Purposes::Recovery)
            : false;

         // @ Trust shares the public revoke-result contract. More importantly,
         //   rotate() must not report Theft unless the attempted all-device
         //   revocation actually succeeds.
         $Trust = new Trust($Database);
         $Trust->freeze(2_000_000);
         $Trusted = $Trust->issue('9', ttl: 3600);
         $Database->await($Database->query(<<<SQL
         CREATE TRIGGER trusts_delete_failure
         BEFORE DELETE ON trusts
         WHEN OLD.user_id = 9
         BEGIN
            SELECT RAISE(ABORT, 'injected trust revocation failure');
         END
         SQL));
         $trustRevoked = $Trust->revoke('9');
         $validator = substr($Trusted->value, 17);
         $wrong = $Trusted->selector . '.'
            . ($validator[0] === 'a' ? 'b' : 'a')
            . substr($validator, 1);
         $Incident = $Trust->rotate($wrong);
         $trustCount = $CountTrust();

         // @ The same one-statement upsert honors an explicit Transaction:
         //   rollback restores the prior credential, while commit publishes
         //   only the replacement and read-your-writes holds before either.
         $Committed = $Tokens->mint('11', Purposes::Verification, ttl: 3600);
         $Rollback = $Database->begin();
         if ($Rollback->Operation === null) {
            throw new RuntimeException('TOK-9 transaction control did not expose BEGIN.');
         }
         $Rollback->await($Rollback->Operation);
         $RollbackTokens = new Tokens($Rollback);
         $RollbackTokens->freeze(1_000_000);
         $Tentative = $RollbackTokens->mint('11', Purposes::Verification, ttl: 3600);
         $rollbackVisible = $RollbackTokens->check(
            $Tentative->value,
            Purposes::Verification
         );
         $rollbackOldVisible = $RollbackTokens->check(
            $Committed->value,
            Purposes::Verification
         );
         $RollbackOperation = $Rollback->rollback();
         $Rollback->await($RollbackOperation);
         $rollbackRestored = $Tokens->check($Committed->value, Purposes::Verification);
         $rollbackDiscarded = $Tokens->check($Tentative->value, Purposes::Verification);

         $Commit = $Database->begin();
         if ($Commit->Operation === null) {
            throw new RuntimeException('TOK-9 transaction control did not expose BEGIN.');
         }
         $Commit->await($Commit->Operation);
         $CommitTokens = new Tokens($Commit);
         $CommitTokens->freeze(1_000_000);
         $Published = $CommitTokens->mint('11', Purposes::Verification, ttl: 3600);
         $commitVisible = $CommitTokens->check($Published->value, Purposes::Verification);
         $CommitOperation = $Commit->commit();
         $Commit->await($CommitOperation);
         $commitPublished = $Tokens->check($Published->value, Purposes::Verification);
         $commitSuperseded = $Tokens->check($Committed->value, Purposes::Verification);
      }
      finally {
         Tokens::$gcProbability = $previousGC;
         Trust::$gcProbability = $previousTrustGC;
      }

      yield assert(
         assertion: $Blocked->error !== null
            && $Blocked->affected === 0
            && $blockedCount === 1
            && $Inserted->error === null
            && $Inserted->affected === 1
            && $Cleaned->error === null
            && $Cleaned->affected === 1,
         description: 'SQLite trigger blocks only the target supersede DELETE while INSERT remains usable; '
            . json_encode([
               'delete_error' => $Blocked->error,
               'rows_after_delete' => $blockedCount,
               'insert_error' => $Inserted->error,
               'insert_affected' => $Inserted->affected,
               'cleanup_error' => $Cleaned->error,
               'cleanup_affected' => $Cleaned->affected,
            ])
      );

      yield assert(
         assertion: $tokensRevoked === null,
         description: 'Tokens::revoke() distinguishes a recorded DELETE failure from zero rows; '
            . json_encode([
               'result' => $tokensRevoked,
               'delete_error' => $Blocked->error,
            ])
      );

      yield assert(
         assertion: $failure === null
            && $Replacement instanceof Token
            && $finalCount === 1
            && $originalLive === false
            && $replacementLive === true,
         description: 'TOK-3/TOK-9: mint() atomically supersedes one unique user-purpose row '
            . 'without depending on the blocked DELETE; evidence=' . json_encode([
               'exception' => $failure,
               'replacement' => $Replacement instanceof Token,
               'rows' => $finalCount,
               'original_live' => $originalLive,
               'replacement_live' => $replacementLive,
            ])
      );

      yield assert(
         assertion: $trustRevoked === null
            && $Incident === null
            && $trustCount === 1,
         description: 'Trust revocation errors are distinguishable and rotate() never fabricates '
            . 'a Theft incident when all-device revocation fails; evidence=' . json_encode([
               'revoke' => $trustRevoked,
               'incident' => get_debug_type($Incident),
               'rows' => $trustCount,
            ])
      );

      yield assert(
         assertion: $rollbackVisible === true
            && $rollbackOldVisible === false
            && $RollbackOperation->error === null
            && $rollbackRestored === true
            && $rollbackDiscarded === false
            && $commitVisible === true
            && $CommitOperation->error === null
            && $commitPublished === true
            && $commitSuperseded === false,
         description: 'Token upsert preserves Transaction read-your-writes, rollback and commit; '
            . json_encode([
               'rollback_visible' => $rollbackVisible,
               'rollback_old_visible' => $rollbackOldVisible,
               'rollback_error' => $RollbackOperation->error,
               'rollback_restored' => $rollbackRestored,
               'rollback_discarded' => $rollbackDiscarded,
               'commit_visible' => $commitVisible,
               'commit_error' => $CommitOperation->error,
               'commit_published' => $commitPublished,
               'commit_superseded' => $commitSuperseded,
            ])
      );
   }
);
