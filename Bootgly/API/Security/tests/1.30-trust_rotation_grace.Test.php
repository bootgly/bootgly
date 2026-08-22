<?php

namespace Bootgly\API\Security\Tests\TrustRotationGrace;


use function assert;
use function extension_loaded;
use function get_debug_type;
use function hash;
use function json_encode;
use function substr;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\Tokens\Theft;
use Bootgly\API\Security\Tokens\Token;
use Bootgly\API\Security\Tokens\Trust;


return new Test(
   description: 'Security: tolerate one immediately previous trust validator during rotation grace',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->query(<<<SQL
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
      SQL);

      $Count = static function (string $user) use ($Database): int {
         $Operation = $Database->await($Database->query(
            'SELECT count(*) AS total FROM trusts WHERE user_id = $1',
            [$user]
         ));

         return (int) ($Operation->rows[0]['total'] ?? -1);
      };
      /** @return null|array<string,mixed> */
      $Fetch = static function (string $selector) use ($Database): null|array {
         $Operation = $Database->await($Database->query(
            'SELECT verifier, previous, rotated, expires FROM trusts WHERE selector = $1',
            [$selector]
         ));

         return $Operation->rows[0] ?? null;
      };
      $Corrupt = static function (Token $Old, Token $Current): string {
         $old = substr($Old->value, 17);
         $current = substr($Current->value, 17);

         // ! Three candidates guarantee one valid hex nibble that differs
         //   from both real validator generations.
         foreach (['0', '1', '2'] as $candidate) {
            if ($candidate !== $old[0] && $candidate !== $current[0]) {
               return $Old->selector . '.' . $candidate . substr($old, 1);
            }
         }

         throw new RuntimeException('Trust corruption fixture could not choose a validator.');
      };

      $previousGC = Trust::$gcProbability;
      Trust::$gcProbability = [0, 1];

      try {
         // ! TOK-2 source-to-sink: the sibling arrives only after the first
         //   autocommit rotation has completed. It therefore observes the new
         //   verifier and must be recognized as the immediately prior request,
         //   not as theft that revokes every device belonging to the user.
         $Trust = new Trust($Database);
         $Trust->freeze(1_000_000);
         $Old = $Trust->issue('101', ttl: 3600);
         $Trust->issue('101', ttl: 3600);
         $Winner = $Trust->rotate($Old->value, ttl: 3600);
         $Duplicate = $Trust->rotate($Old->value, ttl: 3600);
         $duplicateRow = $Fetch($Old->selector);
         $duplicateCount = $Count('101');

         // @ The grace limit is inclusive: an exact previous generation at
         //   five seconds is still declined benignly and changes no state.
         $BoundaryTrust = new Trust($Database);
         $BoundaryTrust->freeze(2_000_000);
         $BoundaryOld = $BoundaryTrust->issue('202', ttl: 3600);
         $BoundaryTrust->issue('202', ttl: 3600);
         $BoundaryWinner = $BoundaryTrust->rotate($BoundaryOld->value, ttl: 3600);
         $BoundaryTrust->freeze(2_000_005);
         $BoundaryDuplicate = $BoundaryTrust->rotate($BoundaryOld->value, ttl: 3600);
         $boundaryRow = $Fetch($BoundaryOld->selector);
         $boundaryCount = $Count('202');

         // @ The same previous validator is a theft signal immediately after
         //   the grace window and must still revoke every device for its user.
         $LateTrust = new Trust($Database);
         $LateTrust->freeze(3_000_000);
         $LateOld = $LateTrust->issue('303', ttl: 3600);
         $LateTrust->issue('303', ttl: 3600);
         $LateWinner = $LateTrust->rotate($LateOld->value, ttl: 3600);
         $LateTrust->freeze(3_000_006);
         $Late = $LateTrust->rotate($LateOld->value, ttl: 3600);
         $lateCount = $Count('303');

         // @ Grace applies only to the exact prior digest. An arbitrary valid
         //   validator remains an immediate theft signal even inside the window.
         $WrongTrust = new Trust($Database);
         $WrongTrust->freeze(4_000_000);
         $WrongOld = $WrongTrust->issue('404', ttl: 3600);
         $WrongTrust->issue('404', ttl: 3600);
         $WrongWinner = $WrongTrust->rotate($WrongOld->value, ttl: 3600);
         if ($WrongWinner instanceof Token === false) {
            throw new RuntimeException('Trust arbitrary-validator control did not rotate.');
         }
         $WrongTrust->freeze(4_000_001);
         $wrong = $Corrupt($WrongOld, $WrongWinner);
         $Wrong = $WrongTrust->rotate($wrong, ttl: 3600);
         $wrongCount = $Count('404');

         // @ Only one generation is remembered. Once generation one rotates
         //   to generation two, generation zero is theft even though all three
         //   requests occurred inside five seconds.
         $GenerationTrust = new Trust($Database);
         $GenerationTrust->freeze(5_000_000);
         $GenerationZero = $GenerationTrust->issue('505', ttl: 3600);
         $GenerationTrust->issue('505', ttl: 3600);
         $GenerationOne = $GenerationTrust->rotate($GenerationZero->value, ttl: 3600);
         if ($GenerationOne instanceof Token === false) {
            throw new RuntimeException('Trust generation control did not reach generation one.');
         }
         $GenerationTrust->freeze(5_000_001);
         $GenerationTwo = $GenerationTrust->rotate($GenerationOne->value, ttl: 3600);
         $generationRow = $Fetch($GenerationZero->selector);
         $GenerationTrust->freeze(5_000_002);
         $GenerationIncident = $GenerationTrust->rotate($GenerationZero->value, ttl: 3600);
         $generationCount = $Count('505');

         // @ Transaction control: Trust must keep using the transaction-pinned
         //   Builder path while applying the same rotation-grace contract.
         $Transaction = $Database->begin();
         $Begin = $Transaction->Operation;
         if ($Begin === null) {
            throw new RuntimeException('Trust transaction fixture did not expose BEGIN.');
         }
         $Database->await($Begin);

         $TransactionalTrust = new Trust($Transaction);
         $TransactionalTrust->freeze(6_000_000);
         $TransactionalOld = $TransactionalTrust->issue('606', ttl: 3600);
         $TransactionalTrust->issue('606', ttl: 3600);
         $TransactionalWinner = $TransactionalTrust->rotate($TransactionalOld->value, ttl: 3600);
         $TransactionalDuplicate = $TransactionalTrust->rotate($TransactionalOld->value, ttl: 3600);
         $Inspect = $Transaction->await($Transaction->query(
            'SELECT verifier, previous, rotated, expires FROM trusts WHERE selector = $1',
            [$TransactionalOld->selector]
         ));
         $transactionRow = $Inspect->rows[0] ?? null;
         $Commit = $Transaction->commit();
         $Database->await($Commit);
         $transactionCount = $Count('606');
      }
      finally {
         Trust::$gcProbability = $previousGC;
      }

      yield assert(
         assertion: $Winner instanceof Token
            && $Duplicate === null
            && $duplicateCount === 2
            && $duplicateRow !== null
            && $duplicateRow['verifier'] === hash('sha256', substr($Winner->value, 17))
            && $duplicateRow['previous'] === hash('sha256', substr($Old->value, 17))
            && (int) $duplicateRow['rotated'] === 1_000_000
            && (int) $duplicateRow['expires'] === $Winner->expires
            && $duplicateRow['verifier'] !== substr($Winner->value, 17)
            && $duplicateRow['previous'] !== substr($Old->value, 17),
         description: 'TOK-2 CONFIRMED: a delayed duplicate of the immediately previous trust '
            . 'validator must be declined without authenticating or revoking sibling devices; evidence='
            . json_encode([
               'winner' => get_debug_type($Winner),
               'duplicate' => get_debug_type($Duplicate),
               'rows' => $duplicateCount,
               'stored' => $duplicateRow,
            ])
      );

      yield assert(
         assertion: $BoundaryWinner instanceof Token
            && $BoundaryDuplicate === null
            && $boundaryCount === 2
            && $boundaryRow !== null
            && $boundaryRow['verifier'] === hash('sha256', substr($BoundaryWinner->value, 17))
            && $boundaryRow['previous'] === hash('sha256', substr($BoundaryOld->value, 17))
            && (int) $boundaryRow['rotated'] === 2_000_000
            && (int) $boundaryRow['expires'] === $BoundaryWinner->expires,
         description: 'TOK-2 CONFIRMED: the exact previous validator remains benign at the inclusive '
            . 'five-second boundary; evidence=' . json_encode([
               'duplicate' => get_debug_type($BoundaryDuplicate),
               'rows' => $boundaryCount,
               'stored' => $boundaryRow,
            ])
      );

      yield assert(
         assertion: $LateWinner instanceof Token
            && $Late instanceof Theft
            && $Late->user === '303'
            && $lateCount === 0,
         description: 'The exact previous validator becomes Theft after six seconds and revokes every device; '
            . json_encode([
               'incident' => get_debug_type($Late),
               'rows' => $lateCount,
            ])
      );

      yield assert(
         assertion: $WrongWinner instanceof Token
            && $Wrong instanceof Theft
            && $Wrong->user === '404'
            && $wrongCount === 0,
         description: 'An arbitrary validator remains immediate Theft inside rotation grace; '
            . json_encode([
               'incident' => get_debug_type($Wrong),
               'rows' => $wrongCount,
            ])
      );

      yield assert(
         assertion: $GenerationOne instanceof Token
            && $GenerationTwo instanceof Token
            && $generationRow !== null
            && $generationRow['verifier'] === hash('sha256', substr($GenerationTwo->value, 17))
            && $generationRow['previous'] === hash('sha256', substr($GenerationOne->value, 17))
            && (int) $generationRow['rotated'] === 5_000_001
            && $GenerationIncident instanceof Theft
            && $GenerationIncident->user === '505'
            && $generationCount === 0,
         description: 'Rotation grace remembers exactly one previous generation; '
            . json_encode([
               'incident' => get_debug_type($GenerationIncident),
               'rows' => $generationCount,
               'stored_before_incident' => $generationRow,
            ])
      );

      yield assert(
         assertion: $Begin->finished === true
            && $TransactionalWinner instanceof Token
            && $TransactionalDuplicate === null
            && $transactionRow !== null
            && $transactionRow['verifier'] === hash('sha256', substr($TransactionalWinner->value, 17))
            && $transactionRow['previous'] === hash('sha256', substr($TransactionalOld->value, 17))
            && (int) $transactionRow['rotated'] === 6_000_000
            && (int) $transactionRow['expires'] === $TransactionalWinner->expires
            && $Commit->finished === true
            && $Commit->error === null
            && $transactionCount === 2,
         description: 'TOK-2 CONFIRMED: a transaction-pinned Trust preserves the same benign duplicate contract; '
            . json_encode([
               'duplicate' => get_debug_type($TransactionalDuplicate),
               'rows' => $transactionCount,
               'stored' => $transactionRow,
               'commit_error' => $Commit->error,
            ])
      );
   }
);
