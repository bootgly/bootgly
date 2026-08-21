<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


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
   description: 'PostgreSQL(live): reused transactions and retired drivers preserve session ownership (requires BOOTGLY_PGSQL_E2E=1)',
   skip: $optin === false || $reachable === false,
   test: function () use ($host, $port) {
      $Open = static function () use ($host, $port): SQL {
         return new SQL([
            'driver' => 'pgsql',
            'host' => $host,
            'port' => $port,
            'database' => getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : 'postgres',
            'username' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'postgres',
            'password' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
            'timeout' => 5.0,
            'secure' => [
               'mode' => getenv('DB_SSLMODE') !== false ? (string) getenv('DB_SSLMODE') : 'disable',
            ],
            'pool' => ['min' => 0, 'max' => 1],
         ]);
      };
      $Reserve = static function (SQL $Database): int {
         $Property = new ReflectionProperty($Database->Pool, 'locked');

         return count($Property->getValue($Database->Pool));
      };

      // # POOL-29 — a completed Transaction object must not recover the
      //   connection carried by its last teardown while another caller uses it.
      $Database = $Open();
      $Database->await($Database->query('SELECT 1 AS warm'));

      $Transaction = $Database->begin();
      $Opened = $Transaction->Operation;

      if ($Opened === null) {
         throw new RuntimeException('POOL-29: transaction creation did not expose BEGIN.');
      }

      $Database->await($Opened);
      $Database->await($Transaction->commit());

      $Reader = $Database->query('SELECT 111 AS v FROM pg_sleep(0.20)');
      $Database->advance($Reader);

      $reading = $Reader->finished === false
         && $Reader->state->name === 'Reading'
         && $Reader->Connection !== null;
      $Reopened = $Transaction->begin();
      $parked = $reading
         && $Reopened->state->name === 'Pending'
         && $Reopened->Connection === null
         && $Transaction->Connection === null
         && count($Database->Pool->pending) === 1
         && $Database->Pool->pending[0] === $Reopened
         && $Reserve($Database) === 0;

      $ReaderConnection = $Reader->Connection;
      $Database->await($Reader);

      $promoted = $Reader->error === null
         && $Reader->Result?->cell === 111
         && $Reopened->finished === false
         && $Reopened->state->name !== 'Pending'
         && $Reopened->Connection === $ReaderConnection
         && $Database->Pool->pending === []
         && $Reserve($Database) === 1;

      $Database->await($Reopened);
      $Rolled = $Transaction->rollback();
      $Database->await($Rolled);

      $completed = $Reopened->error === null
         && $Rolled->error === null
         && $Transaction->depth === 0
         && $Reserve($Database) === 0;

      $Database->Connection->disconnect();

      // # POOL-31 — a Queued operation is not in the old driver's pipeline or
      //   write holder. Retiring that session must nevertheless retire the
      //   driver before the same Connection object is rebuilt for another one.
      $Database = $Open();
      $First = $Database->query('SELECT pg_backend_pid() AS pid');
      $Database->await($First);

      $Connection1 = $First->Connection;
      $Driver1 = $First->Protocol;
      $PID1 = $First->Result?->cell;

      if ($Connection1 === null || $Driver1 === null || is_int($PID1) === false) {
         throw new RuntimeException('POOL-31: the first PostgreSQL session did not expose its ownership.');
      }

      // ! Slow owns the read FIFO; Quiet is composed but never advanced, so it
      //   is deliberately invisible to the driver's pipeline and write holder.
      $Slow = $Database->query('SELECT pg_sleep(4)');
      $Database->advance($Slow);
      $Quiet = $Database->query('SELECT 222 AS marker');

      // ! Model a transaction teardown that never reaches the server. cancel()
      //   severs the session through the driver and rebuilds the pool lazily.
      $Teardown = $Database->query('COMMIT');
      $Teardown->unlock = true;
      $Database->Pool->assign($Teardown);

      $triggered = $Slow->finished === false
         && $Slow->state->name === 'Reading'
         && $Quiet->state->name === 'Queued'
         && $Quiet->Connection === $Connection1
         && $Quiet->Protocol === $Driver1
         && $Teardown->state->name === 'Queued'
         && $Teardown->Connection === $Connection1
         && $Teardown->Protocol === $Driver1;

      yield assert(
         assertion: $triggered,
         description: 'POOL-31: the live fixture must leave SELECT 222 quiet on the session being severed'
      );

      $Database->cancel($Teardown);

      $Next = $Database->query('SELECT pg_backend_pid() AS pid');
      $Database->await($Next);

      $Driver2 = $Next->Protocol;
      $PID2 = $Next->Result?->cell;

      yield assert(
         assertion: $Slow->finished
            && $Slow->error !== null
            && $Teardown->finished
            && $Connection1 === $Next->Connection
            && $Driver2 !== null
            && $Driver2 !== $Driver1
            && is_int($PID2)
            && $PID2 !== $PID1,
         description: 'POOL-31: teardown rebuilds the same Connection object with a different driver and backend session'
      );

      // @ On the vulnerable path Driver1 now writes SELECT 222 onto Driver2's
      //   socket. Driver2 then attributes that first row to Fresh (SELECT 333).
      $queuedBytes = strlen($Quiet->write);
      $Database->advance($Quiet);

      $retired = $queuedBytes > 0
         && $Quiet->finished
         && $Quiet->quarantine
         && $Quiet->write === ''
         && $Quiet->error === 'PostgreSQL connection was torn down before the query was sent.';

      $Fresh = $Database->query('SELECT 333 AS v');
      $Database->await($Fresh);

      yield assert(
         assertion: $Fresh->error === null
            && $Fresh->Result?->cell === 333
            && $Fresh->Result?->row === ['v' => 333],
         description: 'POOL-31: SELECT 333 receives its own row, never the retired driver\'s SELECT 222 row; found: '
            . json_encode([$Fresh->error, $Fresh->Result?->row])
      );

      yield assert(
         assertion: $retired,
         description: 'POOL-31: advancing quiet work through the retired driver fails it without writing to the rebuilt session'
      );

      yield assert(
         assertion: $parked,
         description: 'POOL-29: reused BEGIN stays unpinned and unreserved while the reader owns the only connection'
      );

      yield assert(
         assertion: $promoted,
         description: 'POOL-29: releasing the reader promotes BEGIN and only then reserves its connection'
      );

      yield assert(
         assertion: $completed,
         description: 'POOL-29: the promoted transaction opens and rolls back normally'
      );

      $Database->Connection->disconnect();
   }
);
