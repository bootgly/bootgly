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
   description: 'PostgreSQL(live): a half-written batch past its deadline never wedges the connection (requires BOOTGLY_PGSQL_E2E=1)',
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
         'secure' => [
            'mode' => getenv('DB_SSLMODE') !== false ? (string) getenv('DB_SSLMODE') : 'disable',
         ],
         'pool' => ['min' => 0, 'max' => 1],
      ]);
      $wait = static function (float $seconds): void {
         $started = microtime(true);
         while (microtime(true) - $started < $seconds) { /* busy — a sleep can be cut short */ }
      };

      $Database->await($Database->query('SELECT 1 AS warm'));

      // ! Reads whole on the wire with a comfortable deadline: the backend
      //   answers them while the stale batch below still stalls the stream —
      //   the collateral a socketpair alone can never produce.
      $Reads = [];

      for ($value = 1; $value <= 4; $value++) {
         $Read = $Database->query("SELECT {$value} AS n");
         $Database->advance($Read);
         $Reads[] = $Read;
      }

      // ! A batch far larger than the socket send buffer, on a deadline its
      //   caller lets pass without ever advancing it again.
      $Database->Pool->Config->timeout = 0.05;
      $Stale = $Database->query('SELECT length($1) AS n', [str_repeat('x', 6 * 1024 * 1024)]);
      $Database->advance($Stale);
      $stalled = strlen($Stale->write);
      $Database->Pool->Config->timeout = 5.0;

      $wait(0.08);

      // @ A later caller trips the guard, finishes the stale flush and runs.
      $Late = $Database->query('SELECT 99 AS n');
      $late = null;

      try {
         $Database->await($Late);
      }
      catch (Throwable $Throwable) {
         $late = $Throwable->getMessage();
      }

      yield assert(
         assertion: $stalled > 0 && $late === null && $Late->Result?->cell === 99,
         description: 'A caller behind a stale half-written batch completes instead of timing out, found: '
            . json_encode([$stalled, $late])
      );

      $answers = [];

      foreach ($Reads as $Read) {
         try {
            $Database->await($Read);
            $answers[] = $Read->Result?->cell;
         }
         catch (Throwable $Throwable) {
            $answers[] = $Throwable->getMessage();
         }
      }

      yield assert(
         assertion: $answers === [1, 2, 3, 4],
         description: 'Answers the backend had already sent all survive the takeover, found: ' . json_encode($answers)
      );

      yield assert(
         assertion: $Stale->finished && $Stale->error !== null && str_contains($Stale->error, 'timed out')
            && $Stale->revoked === true,
         description: 'The stale batch fails with its own deadline and is revoked — it ran with an unknown outcome'
      );

      yield assert(
         assertion: $Database->Connection->connected,
         description: 'The connection the pool holds is alive and was never dropped'
      );

      // ? The wedge the entry filed: three ordinary follow-ups on the same
      //   connection — each timed out for good before this fix.
      $followups = [];

      for ($attempt = 0; $attempt < 3; $attempt++) {
         $Next = $Database->query('SELECT 7 AS n');

         try {
            $Database->await($Next);
            $followups[] = $Next->Result?->cell;
         }
         catch (Throwable $Throwable) {
            $followups[] = $Throwable->getMessage();
         }
      }

      yield assert(
         assertion: $followups === [7, 7, 7],
         description: 'Follow-up queries are served immediately on the reconciled connection, found: '
            . json_encode($followups)
      );
   }
);
