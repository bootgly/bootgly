<?php

namespace Bootgly\API\Security\Tests\TokensExpiredSupersedeRace;


use function assert;
use function extension_loaded;
use function get_debug_type;
use function is_array;
use function is_numeric;
use function is_string;
use function json_encode;
use Closure;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Modes;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Token;


/**
 * Real SQLite facade with a deterministic scheduling seam immediately before
 * the expired-row DELETE issued by Tokens::redeem().
 */
final class InterleavingSQL extends SQLDatabase
{
   /** One-shot action scheduled after redeem()'s SELECT and before its DELETE. */
   public null|Closure $BeforeDelete = null;
   /** Number of DELETE builders observed after the seam was armed. */
   public int $deletes = 0;

   private bool $intercepted = false;


   /**
    * Run the sibling issuance immediately before the stale purge reaches SQL.
    *
    * @param array<int|string,mixed> $parameters
    */
   public function query (
      string|Builder|Query $query,
      array $parameters = [],
      null|object $Scope = null
   ): Operation
   {
      if ($query instanceof Builder && $query->Mode === Modes::Delete) {
         $this->deletes++;

         if ($this->intercepted === false && $this->BeforeDelete !== null) {
            $this->intercepted = true;
            ($this->BeforeDelete)();
         }
      }

      return parent::query($query, $parameters, $Scope);
   }
}


return new Test(
   description: 'Security/Tokens: an expired stale purge cannot delete a concurrently superseding token',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new InterleavingSQL([
         'driver' => 'sqlite',
         'database' => ':memory:',
      ]);
      $Database->await($Database->query(<<<SQL
      CREATE TABLE tokens (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         selector TEXT NOT NULL UNIQUE,
         verifier TEXT NOT NULL,
         user_id INTEGER NOT NULL,
         purpose TEXT NOT NULL,
         expires INTEGER NOT NULL,
         UNIQUE (user_id, purpose)
      )
      SQL));

      /**
       * Read the sole persisted row for the contested pair.
       *
       * @return null|array{id:int,selector:string,expires:int}
       */
      $Inspect = static function () use ($Database): null|array {
         $Operation = $Database->await($Database->query(
            'SELECT id, selector, expires FROM tokens WHERE user_id = $1 AND purpose = $2',
            ['7', Purposes::Recovery->value]
         ));
         $row = $Operation->rows[0] ?? null;

         if (
            is_array($row) === false
            || is_numeric($row['id'] ?? null) === false
            || is_string($row['selector'] ?? null) === false
            || is_numeric($row['expires'] ?? null) === false
         ) {
            return null;
         }

         return [
            'id' => (int) $row['id'],
            'selector' => $row['selector'],
            'expires' => (int) $row['expires'],
         ];
      };
      $Count = static function () use ($Database): int {
         $Operation = $Database->await($Database->query(
            'SELECT count(*) AS total FROM tokens WHERE user_id = $1 AND purpose = $2',
            ['7', Purposes::Recovery->value]
         ));

         return (int) ($Operation->rows[0]['total'] ?? -1);
      };

      $previousGC = Tokens::$gcProbability;
      Tokens::$gcProbability = [0, 1];
      $Replacement = null;
      $during = null;
      $replacementLiveDuring = null;

      try {
         $Tokens = new Tokens($Database);
         $Tokens->freeze(1_000_000);
         $Expired = $Tokens->mint('7', Purposes::Recovery, ttl: 1);
         $before = $Inspect();

         // ! At this instant redeem() has already selected the expired row.
         //   The sibling mint() upserts that same user-purpose row in place,
         //   retaining its id while replacing selector, verifier and expiry.
         $Tokens->freeze(1_000_001);
         $Database->BeforeDelete = static function () use (
            $Tokens,
            $Inspect,
            &$Replacement,
            &$during,
            &$replacementLiveDuring
         ): void {
            $Replacement = $Tokens->mint('7', Purposes::Recovery, ttl: 3600);
            $during = $Inspect();
            $replacementLiveDuring = $Tokens->check(
               $Replacement->value,
               Purposes::Recovery
            );
         };

         // ! Source to sink: the stale expired snapshot must not authorize a
         //   DELETE of the newer generation installed by the sibling mint().
         $redeemed = $Tokens->redeem($Expired->value, Purposes::Recovery);
         $after = $Inspect();
         $finalCount = $Count();
         $replacementLiveAfter = $Replacement instanceof Token
            ? $Tokens->check($Replacement->value, Purposes::Recovery)
            : false;
         $expiredLiveAfter = $Tokens->check($Expired->value, Purposes::Recovery);
      }
      finally {
         Tokens::$gcProbability = $previousGC;
      }

      $fixture = $before !== null
         && $before['expires'] === 1_000_001
         && $Replacement instanceof Token
         && $during !== null
         && $during['id'] === $before['id']
         && $during['selector'] === $Replacement->selector
         && $during['selector'] !== $before['selector']
         && $during['expires'] === 1_003_601
         && $replacementLiveDuring === true
         && $Database->deletes === 1
         && $redeemed === null;
      $Evidence = [
         'before' => $before,
         'replacement' => get_debug_type($Replacement),
         'during' => $during,
         'replacement_live_during' => $replacementLiveDuring,
         'delete_builders' => $Database->deletes,
         'redeemed' => $redeemed,
         'after' => $after,
         'rows_after' => $finalCount,
         'replacement_live_after' => $replacementLiveAfter,
         'expired_live_after' => $expiredLiveAfter,
      ];

      yield assert(
         assertion: $fixture,
         description: 'SQLite fixture must interleave mint() after redeem() selected the expired '
            . 'generation and prove the replacement reused that row id; evidence='
            . json_encode($Evidence)
      );

      yield assert(
         assertion: $fixture
            && $after !== null
            && $after === $during
            && $finalCount === 1
            && $replacementLiveAfter === true
            && $expiredLiveAfter === false,
         description: 'TOK-13 CONFIRMED: an expired stale purge must not delete the replacement '
            . 'generation installed by a concurrent atomic supersede; evidence='
            . json_encode($Evidence)
      );
   }
);
