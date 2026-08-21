<?php


use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Operation;


// ! Opt-in live E2E — BOOTGLY_PGSQL_E2E=1 + DB_* environment
$optin = getenv('BOOTGLY_PGSQL_E2E') === '1';
$host = getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : '127.0.0.1';
$port = getenv('DB_PORT') !== false ? (int) getenv('DB_PORT') : 5432;
$reachable = false;

if ($optin) {
   $Probe = @fsockopen($host, $port, $errno, $error, 0.5);
   $reachable = is_resource($Probe);
   if ($reachable) {
      fclose($Probe);
   }
}


return new Test(
   description: 'PostgreSQL(live): an in-flight Parse cannot be decapitated by a queued Close (requires BOOTGLY_PGSQL_E2E=1)',
   skip: $optin === false || $reachable === false,
   test: function () use ($host, $port) {
      $Database = new SQL([
         'driver' => 'pgsql',
         'host' => $host,
         'port' => $port,
         'database' => getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : 'postgres',
         'username' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'postgres',
         'password' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
         'timeout' => 5.0,
         'statements' => 1,
         'secure' => [
            'mode' => getenv('DB_SSLMODE') !== false ? (string) getenv('DB_SSLMODE') : 'disable',
         ],
         'pool' => ['min' => 0, 'max' => 1],
      ]);

      // @ Authenticate once, then drive the protocol directly so each flush
      //   and read boundary below stays under the test's control.
      $Database->await($Database->query('SELECT 1 AS warm'));
      $Protocol = $Database->Connection->Protocol;

      yield assert(
         assertion: $Protocol instanceof PostgreSQL,
         description: 'PG-11: the live fixture must select the PostgreSQL protocol'
      );

      if ($Protocol instanceof PostgreSQL === false) {
         return;
      }

      $PostgreSQL = $Protocol;
      $Select = static function (Operation $Operation): void {
         $Readiness = $Operation->Readiness;

         if ($Readiness === null) {
            throw new RuntimeException('PG-11: the direct PostgreSQL operation did not provide readiness.');
         }

         $reads = $Readiness->flag === Scheduler::SCHEDULE_READ ? [$Readiness->socket] : [];
         $writes = $Readiness->flag === Scheduler::SCHEDULE_WRITE ? [$Readiness->socket] : [];
         $except = [];

         if (stream_select($reads, $writes, $except, 1, 0) === false) {
            throw new RuntimeException('PG-11: waiting for PostgreSQL readiness failed.');
         }

         if ($Operation->deadline > 0.0 && microtime(true) >= $Operation->deadline) {
            throw new RuntimeException('PG-11: the live protocol sequence exceeded its deadline.');
         }
      };
      $Settle = static function (PostgreSQL $PostgreSQL, Operation $Operation) use ($Select): void {
         while ($Operation->finished === false) {
            $PostgreSQL->advance($Operation);

            if ($Operation->finished === false) {
               $Select($Operation);
            }
         }
      };

      $A = "SELECT (10 / \$1)::int AS v, repeat('a', 9000) AS pad";
      $B = "SELECT \$1::int AS v, repeat('b', 9000) AS pad";

      // # O warms A into the one-entry statement cache.
      $O = $PostgreSQL->query($A, [2]);
      $Settle($PostgreSQL, $O);

      yield assert(
         assertion: $O->error === null && $O->Result?->cell === 5,
         description: 'PG-11: the warm-up statement A must be registered on the backend'
      );

      // # W is a warm Bind whose intended runtime error is left unread.
      $W = $PostgreSQL->query($A, [0]);
      $PostgreSQL->advance($W);

      // # Q composes B and evicts A. P then re-Parses A, carrying that first
      //   Close ahead of its own Parse while W's ErrorResponse is still unread.
      $Q = $PostgreSQL->query($B, [4]);
      $P = $PostgreSQL->query($A, [5]);
      $PostgreSQL->advance($P);

      // @ Reading W queues a second Close(A). Q attempts to flush while P's
      //   Parse is in flight, but must leave that Close queued until the driver
      //   reads P's ParseComplete and knows which registration survived.
      $Settle($PostgreSQL, $W);
      $Ledger = new ReflectionProperty(PostgreSQL::class, 'preparing');
      $Closes = new ReflectionProperty(PostgreSQL::class, 'closing');
      $preparing = $Ledger->getValue($PostgreSQL);
      $closing = $Closes->getValue($PostgreSQL);

      yield assert(
         assertion: ($preparing[$P->statement] ?? null) === true
            && isset($closing[$P->statement]),
         description: 'PG-11: W leaves P sent and unanswered with its stale Close still queued'
      );

      $PostgreSQL->advance($Q);
      $closing = $Closes->getValue($PostgreSQL);

      yield assert(
         assertion: isset($closing[$P->statement]),
         description: 'PG-11: Q cannot carry Close(A) ahead of the unanswered Parse(A)'
      );

      while ($P->prepared === false && $P->finished === false) {
         $Select($P);
         $PostgreSQL->advance($P);
      }

      // # ParseComplete reasserts A in the cache. R must therefore be warm —
      //   this is the poisoned cache/backend state that exposed PG-11.
      $R = $PostgreSQL->query($A, [4]);
      $triggered = $W->finished
         && $W->error !== null
         && str_contains($W->error, 'division by zero')
         && $Q->prepared === false
         && $P->prepared
         && $P->finished === false
         && $R->prepared;

      yield assert(
         assertion: $triggered,
         description: 'PG-11: the intended ErrorResponse → Close → ParseComplete → warm Bind trigger must be reached'
      );

      $PostgreSQL->advance($R);
      $Settle($PostgreSQL, $P);
      $Settle($PostgreSQL, $Q);
      $Settle($PostgreSQL, $R);

      yield assert(
         assertion: $P->error === null && $P->Result?->cell === 2
            && $Q->error === null && $Q->Result?->cell === 4,
         description: 'PG-11: the Parse owner and intervening LRU query must both succeed, found: '
            . json_encode([$P->error, $P->Result?->cell, $Q->error, $Q->Result?->cell])
      );

      yield assert(
         assertion: $R->error === null && $R->Result?->row['v'] === 2,
         description: 'PG-11: R must Bind the still-registered A statement, found: '
            . json_encode([$R->error, $R->Result?->row['v'] ?? null])
      );
   }
);
