<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Operation;


// ! Opt-in live TLS E2E — BOOTGLY_PGSQL_TLS_E2E=1 + DB_* environment
$optin = getenv('BOOTGLY_PGSQL_TLS_E2E') === '1';
$host = getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : '127.0.0.1';
$port = getenv('DB_PORT') !== false ? (int) getenv('DB_PORT') : 5432;
$mode = getenv('DB_SSLMODE') !== false ? (string) getenv('DB_SSLMODE') : 'verify-full';
$CAFile = getenv('DB_SSLCAFILE') !== false ? (string) getenv('DB_SSLCAFILE') : '';
$peer = getenv('DB_SSLPEER') !== false ? (string) getenv('DB_SSLPEER') : '';
$reachable = false;

if ($optin) {
   $Probe = @fsockopen($host, $port, $errno, $error, 0.5);
   $reachable = is_resource($Probe);

   if ($reachable) {
      fclose($Probe);
   }
}


return new Test(
   description: 'PostgreSQL(live TLS): a zero-return SSL write keeps its pending batch until the backend can read it (requires BOOTGLY_PGSQL_TLS_E2E=1)',
   skip: $optin === false,
   test: function () use ($host, $port, $mode, $CAFile, $peer, $reachable) {
      $Target = null;
      $Control = null;
      $Sleeper = null;
      $targetPID = 0;
      $sleepCancelled = false;

      try {
         $configured = $reachable
            && $mode === 'verify-full'
            && $CAFile !== ''
            && is_file($CAFile)
            && $peer !== '';

         yield assert(
            assertion: $configured,
            description: 'PG-15: the opted-in fixture requires a reachable PostgreSQL server, '
               . 'DB_SSLMODE=verify-full, a readable DB_SSLCAFILE and a non-empty DB_SSLPEER; found: '
               . json_encode([
                  'reachable' => $reachable,
                  'mode' => $mode,
                  'cafile' => $CAFile,
                  'cafile_readable' => $CAFile !== '' && is_file($CAFile),
                  'peer' => $peer,
               ])
         );

         if ($configured === false) {
            return;
         }

         $database = getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : 'postgres';
         $username = getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'postgres';
         $password = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
         $Open = static function () use (
            $host,
            $port,
            $database,
            $username,
            $password,
            $mode,
            $CAFile,
            $peer
         ): SQL {
            return new SQL([
               'driver' => 'pgsql',
               'host' => $host,
               'port' => $port,
               'database' => $database,
               'username' => $username,
               'password' => $password,
               'timeout' => 40.0,
               'secure' => [
                  'mode' => $mode,
                  'cafile' => $CAFile,
                  'peer' => $peer,
               ],
               'pool' => ['min' => 0, 'max' => 1],
            ]);
         };
         $Inspect = static function (SQL $Database): Operation {
            $Operation = $Database->query(
               'SELECT pg_backend_pid()::int AS pid, '
               . 'CASE WHEN ssl THEN 1 ELSE 0 END::int AS tls, version, cipher '
               . 'FROM pg_stat_ssl WHERE pid = pg_backend_pid()'
            );
            $Database->await($Operation);

            return $Operation;
         };

         $Target = $Open();
         $Control = $Open();
         $TargetTLS = $Inspect($Target);
         $ControlTLS = $Inspect($Control);
         $targetRow = $TargetTLS->Result?->row ?? [];
         $controlRow = $ControlTLS->Result?->row ?? [];
         $targetPID = is_int($targetRow['pid'] ?? null) ? $targetRow['pid'] : 0;
         $controlPID = is_int($controlRow['pid'] ?? null) ? $controlRow['pid'] : 0;
         $targetSocket = $TargetTLS->Connection?->socket;
         $controlSocket = $ControlTLS->Connection?->socket;
         $targetMetadata = is_resource($targetSocket) ? stream_get_meta_data($targetSocket) : [];
         $controlMetadata = is_resource($controlSocket) ? stream_get_meta_data($controlSocket) : [];
         $Protocol = $TargetTLS->Protocol;
         $TLSReady = $Protocol instanceof PostgreSQL
            && $targetPID > 0
            && $controlPID > 0
            && $targetPID !== $controlPID
            && ($targetRow['tls'] ?? null) === 1
            && ($controlRow['tls'] ?? null) === 1
            && is_array($targetMetadata['crypto'] ?? null)
            && is_array($controlMetadata['crypto'] ?? null);

         yield assert(
            assertion: $TLSReady,
            description: 'PG-15: two distinct verified TLS PostgreSQL sessions must be established; found: '
               . json_encode([
                  'target' => $targetRow,
                  'control' => $controlRow,
                  'target_crypto' => $targetMetadata['crypto'] ?? null,
                  'control_crypto' => $controlMetadata['crypto'] ?? null,
                  'protocol' => $Protocol === null ? null : $Protocol::class,
               ])
         );

         if ($TLSReady === false || $Protocol instanceof PostgreSQL === false) {
            return;
         }

         // ! PostgreSQL processes one session in one backend. Once this query is
         //   executing, that backend does not consume later frontend messages;
         //   the separate verified session remains available to cancel the sleep.
         $Sleeper = $Target->query('SELECT pg_sleep(30)');
         $Target->advance($Sleeper);
         $sleeping = false;

         for ($attempt = 0; $attempt < 40; $attempt++) {
            $Check = $Control->query(
               "SELECT count(*)::int AS sleeping FROM pg_stat_activity "
               . "WHERE pid = {$targetPID} AND wait_event = 'PgSleep'"
            );
            $Control->await($Check);

            if ($Check->Result?->cell === 1) {
               $sleeping = true;

               break;
            }

            usleep(25_000);
         }

         yield assert(
            assertion: $sleeping && $Sleeper->finished === false,
            description: 'PG-15: the target backend must be inside pg_sleep before saturation begins'
         );

         if ($sleeping === false || $Sleeper->finished) {
            return;
         }

         // ! Encoder::query() adds Q + int32 length + trailing NUL (six bytes).
         //   Padding every SQL payload to 16,378 bytes therefore makes every
         //   SSL_write input exactly one 16 KiB TLS plaintext record. OpenSSL's
         //   all-or-retry contract then gives this loop whole-frame writes until
         //   the first zero return, instead of an arbitrary partial boundary.
         $frameSize = 16 * 1024;
         $SQLSize = $frameSize - 6;
         $Frame = static function (int $marker) use ($SQLSize): string {
            $SQL = "SELECT {$marker}::int AS marker";

            if (strlen($SQL) > $SQLSize) {
               throw new RuntimeException('PG-15 marker exceeded the fixed simple-query frame.');
            }

            return $SQL . str_repeat(' ', $SQLSize - strlen($SQL));
         };
         $Writing = new ReflectionProperty(PostgreSQL::class, 'writing');
         $Wrote = new ReflectionProperty(PostgreSQL::class, 'wrote');
         $Target->Pool->Config->timeout = 0.08;
         $Holder = null;
         $transportError = null;
         $partial = null;
         $frames = 0;
         $maxFrames = 1024;

         for ($attempt = 1; $attempt <= $maxFrames; $attempt++) {
            $Filler = $Target->query($Frame(10_000 + $attempt));

            if (strlen($Filler->write) !== $frameSize) {
               throw new RuntimeException(
                  'PG-15 fixture composed a non-16-KiB Simple Query frame: ' . strlen($Filler->write)
               );
            }

            $Target->advance($Filler);
            $frames = $attempt;
            $Owner = $Writing->getValue($Protocol);
            $written = $Wrote->getValue($Protocol);

            if ($Owner === $Filler && $written === 0 && strlen($Filler->write) === $frameSize) {
               $Holder = $Filler;

               break;
            }

            if ($Owner === $Filler && $written > 0) {
               $partial = [
                  'frame' => $attempt,
                  'written' => $written,
                  'remaining' => strlen($Filler->write),
               ];

               break;
            }

            if ($Filler->finished) {
               $transportError = $Filler->error;

               break;
            }

            if ($Owner !== null) {
               $partial = [
                  'frame' => $attempt,
                  'owner' => spl_object_id($Owner),
                  'candidate' => spl_object_id($Filler),
                  'written' => $written,
               ];

               break;
            }
         }

         $precondition = $Holder instanceof Operation
            && $Writing->getValue($Protocol) === $Holder
            && $Wrote->getValue($Protocol) === 0
            && strlen($Holder->write) === $frameSize
            && is_resource($Holder->Connection?->socket);

         yield assert(
            assertion: $precondition,
            description: 'PG-15: a live encrypted session must retain the first exact 16-KiB batch whose '
               . 'SSL write returned zero; a transport error here means PG-16 intercepted that trigger, '
               . 'while exhausting the bound means the fixture did not saturate; found: '
               . json_encode([
                  'frames' => $frames,
                  'limit' => $maxFrames,
                  'transport_error' => $transportError,
                  'partial' => $partial,
                  'connected' => $Target->Connection->connected,
                  'socket' => is_resource($Target->Connection->socket),
               ])
         );

         if ($precondition === false || $Holder instanceof Operation === false) {
            return;
         }

         // @ Let only the zero-return holder expire. The next distinct 16-KiB
         //   frame is the forbidden buffer substitution: on the vulnerable
         //   path withdraw() frees the holder while OpenSSL still owns its
         //   pending record, so the follower is credited with the holder's row.
         $deadline = $Holder->deadline + 0.02;

         while (microtime(true) < $deadline) {
            usleep(1_000);
         }

         $Target->Pool->Config->timeout = 20.0;
         $nextMarker = 900_000_001;
         $Next = $Target->query($Frame($nextMarker));

         yield assert(
            assertion: strlen($Next->write) === $frameSize,
            description: 'PG-15: the distinct follower must also be one exact TLS plaintext record'
         );

         $Target->advance($Next);

         $Cancel = $Control->query(
            "SELECT CASE WHEN pg_cancel_backend({$targetPID}) THEN 1 ELSE 0 END::int AS cancelled"
         );
         $Control->await($Cancel);
         $sleepCancelled = $Cancel->Result?->cell === 1;

         yield assert(
            assertion: $sleepCancelled,
            description: 'PG-15: the control TLS session must release the sleeping target backend'
         );

         $nextError = null;

         try {
            $Target->await($Next);
         }
         catch (Throwable $Throwable) {
            $nextError = $Throwable->getMessage();
         }

         yield assert(
            assertion: $nextError === null
               && $Next->error === null
               && $Next->Result?->cell === $nextMarker
               && $Holder->finished
               && $Holder->error !== null
               && str_contains($Holder->error, 'timed out')
               && $Holder->revoked,
            description: 'PG-15: after backpressure clears, the holder is drained as unknown-outcome work '
               . 'and the follower receives only its own marker; found: '
               . json_encode([
                  'next_error' => $nextError ?? $Next->error,
                  'next_marker' => $Next->Result?->cell,
                  'expected_marker' => $nextMarker,
                  'holder_error' => $Holder->error,
                  'holder_revoked' => $Holder->revoked,
               ])
         );

         $Follow = $Target->query('SELECT pg_backend_pid()::int AS pid');
         $followError = null;

         try {
            $Target->await($Follow);
         }
         catch (Throwable $Throwable) {
            $followError = $Throwable->getMessage();
         }

         yield assert(
            assertion: $followError === null
               && $Follow->error === null
               && $Follow->Result?->cell === $targetPID
               && $Target->Connection->connected,
            description: 'PG-15: the same TLS PostgreSQL session remains usable after the pending record drains; found: '
               . json_encode([
                  'error' => $followError ?? $Follow->error,
                  'pid' => $Follow->Result?->cell,
                  'expected_pid' => $targetPID,
                  'connected' => $Target->Connection->connected,
               ])
         );
      }
      catch (AssertionError $Assertion) {
         throw $Assertion;
      }
      catch (Throwable $Throwable) {
         yield assert(
            assertion: false,
            description: 'PG-15 live TLS fixture raised unexpectedly: ' . $Throwable->getMessage()
         );
      }
      finally {
         // ! Bound cleanup even when an assertion stops generator consumption:
         //   release pg_sleep from the independent session, then close both
         //   pools' only sockets. The fallback CancelRequest covers a control
         //   query failure without waiting for the 30-second sleep naturally.
         if ($targetPID > 0 && $sleepCancelled === false) {
            $cancelled = false;

            if ($Control instanceof SQL) {
               try {
                  $Control->Pool->Config->timeout = 2.0;
                  $Cleanup = $Control->query(
                     "SELECT CASE WHEN pg_cancel_backend({$targetPID}) THEN 1 ELSE 0 END::int AS cancelled"
                  );
                  $Control->await($Cleanup);
                  $cancelled = $Cleanup->Result?->cell === 1;
               }
               catch (Throwable) {
                  $cancelled = false;
               }
            }

            if (
               $cancelled === false
               && $Sleeper instanceof Operation
               && $Sleeper->finished === false
               && $Target instanceof SQL
            ) {
               try {
                  $Target->Pool->Config->timeout = 2.0;
                  $Target->cancel($Sleeper);
               }
               catch (Throwable) {
                  // @ Disconnect below is the final bounded cleanup action.
               }
            }
         }

         $Target instanceof SQL && $Target->Connection->disconnect();
         $Control instanceof SQL && $Control->Connection->disconnect();
      }
   }
);
