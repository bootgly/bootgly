<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Token;
use Bootgly\API\Security\Tokens\Trust;


// ! Opt-in live E2E — BOOTGLY_PGSQL_E2E=1 + DB_* environment.
$optin = getenv('BOOTGLY_PGSQL_E2E') === '1';
$host = getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : '127.0.0.1';
$port = getenv('DB_PORT') !== false ? (int) getenv('DB_PORT') : 5432;
$capable = function_exists('pcntl_alarm')
   && function_exists('pcntl_async_signals')
   && function_exists('pcntl_fork')
   && function_exists('pcntl_signal')
   && function_exists('pcntl_signal_get_handler')
   && function_exists('pcntl_waitpid')
   && function_exists('pcntl_wifexited')
   && function_exists('pcntl_wexitstatus')
   && function_exists('posix_kill')
   && function_exists('stream_socket_pair');
$reachable = false;

if ($optin && $capable) {
   $Probe = @fsockopen($host, $port, $errno, $error, 0.5);
   $reachable = is_resource($Probe);

   if ($reachable) {
      fclose($Probe);
   }
}


return new Test(
   description: 'Security(live): token stores never report an EINTR-interrupted write before PostgreSQL commits it (requires BOOTGLY_PGSQL_E2E=1)',
   skip: $optin === false || $capable === false || $reachable === false,
   test: function () use ($host, $port) {
      $Open = static function (int $max = 1) use ($host, $port): SQL {
         return new SQL([
            'driver' => 'pgsql',
            'host' => $host,
            'port' => $port,
            'database' => getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : 'postgres',
            'username' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'postgres',
            'password' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
            'timeout' => 8.0,
            'secure' => [
               'mode' => getenv('DB_SSLMODE') !== false ? (string) getenv('DB_SSLMODE') : 'disable',
            ],
            'pool' => ['min' => 0, 'max' => $max],
         ]);
      };
      $Count = static function (SQL $Database, string $table): int {
         $Operation = $Database->query('SELECT count(*) AS total FROM "' . $table . '"');

         try {
            $Database->await($Operation);
         }
         catch (Throwable) {
            return -1;
         }

         $cell = $Operation->Result?->cell;

         return is_numeric($cell) ? (int) $cell : -1;
      };
      $Await = static function (SQL $Database, string $table) use ($Count): int {
         $deadline = microtime(true) + 3.0;

         do {
            $count = $Count($Database, $table);
            if ($count !== 0 || microtime(true) >= $deadline) {
               return $count;
            }

            usleep(50_000);
         } while (true);
      };

      $suffix = getmypid() . '_' . bin2hex(random_bytes(4));
      $users = "bootgly_tok1_users_{$suffix}";
      $tokens = "bootgly_tok1_tokens_{$suffix}";
      $trusts = "bootgly_tok1_trusts_{$suffix}";
      $user = "parent-{$suffix}";
      $tables = [$trusts, $tokens, $users];
      $created = [];
      $children = [];
      $results = [];
      $fixtureError = null;
      $cleanupErrors = [];

      $Admin = null;
      $Observer = null;
      $TokenDatabase = null;
      $TrustDatabase = null;
      $PreviousErrors = null;
      $errorsInstalled = false;
      $previousReporting = error_reporting();
      $PreviousSignal = pcntl_signal_get_handler(SIGALRM);
      $previousAlarm = pcntl_alarm(0);
      $previousAsync = pcntl_async_signals(true);
      $previousTokensGC = Tokens::$gcProbability;
      $previousTrustGC = Trust::$gcProbability;
      $alarms = 0;
      $interruptions = 0;

      try {
         // ! Sentinel handler: fixed Pool::wait() consumes EINTR inside its
         //   own narrowly scoped handler, so this counter stays zero. If EINTR
         //   leaks outward, count and swallow it so the fixture can report the
         //   regression after collecting both durable-state outcomes.
         $PreviousErrors = set_error_handler(
            static function (
               int $severity,
               string $message,
               string $file,
               int $line
            ) use (&$PreviousErrors, &$interruptions): bool {
               if (
                  $severity === E_WARNING
                  && str_contains($message, 'stream_select()')
                  && (
                     str_contains($message, 'Interrupted system call')
                     || str_contains($message, 'Unable to select [4]')
                  )
               ) {
                  $interruptions++;

                  return true;
               }

               if (is_callable($PreviousErrors)) {
                  return (bool) $PreviousErrors($severity, $message, $file, $line);
               }

               return false;
            }
         );
         $errorsInstalled = true;

         pcntl_signal(
            SIGALRM,
            static function () use (&$alarms): void {
               $alarms++;
            },
            false
         );

         // ! Remove every query before the write under test. Both stores may
         //   probabilistically sweep; GC must not consume the alarm or hide
         //   the target token upsert / trust insert.
         Tokens::$gcProbability = [0, 1];
         Trust::$gcProbability = [0, 1];

         $Admin = $Open(2);
         $Observer = $Open(1);
         $TokenDatabase = $Open(1);
         $TrustDatabase = $Open(1);

         foreach ([
            $users => 'CREATE TABLE "' . $users . '" ('
               . 'id TEXT PRIMARY KEY'
               . ')',
            $tokens => 'CREATE TABLE "' . $tokens . '" ('
               . 'id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, '
               . 'selector VARCHAR(16) NOT NULL UNIQUE, '
               . 'verifier VARCHAR(64) NOT NULL, '
               . 'user_id TEXT NOT NULL REFERENCES "' . $users . '" (id), '
               . 'purpose VARCHAR(32) NOT NULL, '
               . 'expires BIGINT NOT NULL, '
               . 'UNIQUE (user_id, purpose)'
               . ')',
            $trusts => 'CREATE TABLE "' . $trusts . '" ('
               . 'id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, '
               . 'selector VARCHAR(16) NOT NULL UNIQUE, '
               . 'verifier VARCHAR(64) NOT NULL, '
               . 'previous VARCHAR(64) DEFAULT NULL, '
               . 'rotated BIGINT DEFAULT NULL, '
               . 'user_id TEXT NOT NULL REFERENCES "' . $users . '" (id), '
               . 'expires BIGINT NOT NULL'
               . ')',
         ] as $table => $SQL) {
            $Admin->await($Admin->query($SQL));
            $created[] = $table;
         }
         $Admin->await($Admin->query(
            'INSERT INTO "' . $users . '" (id) VALUES ($1)',
            [$user]
         ));

         // @ Authenticate and warm the exact sessions used by the stores so
         //   the one-second alarm cannot land in connection setup.
         $TokenDatabase->await($TokenDatabase->query('SELECT 1 AS warm'));
         $TrustDatabase->await($TrustDatabase->query('SELECT 1 AS warm'));
         $Observer->await($Observer->query('SELECT 1 AS warm'));

         $Tokens = new Tokens($TokenDatabase, $tokens);
         $Trust = new Trust($TrustDatabase, $trusts);

         /**
          * Hold the FK parent row in another PostgreSQL session, invoke one
          * store write under SIGALRM, then observe it before and after the
          * child commits. The child releases itself after three seconds so a
          * fixed Pool::wait() can keep waiting without deadlocking this case.
          *
          * @return array<string,mixed>
          */
         $Exercise = static function (
            string $label,
            string $table,
            Closure $Invoke
         ) use (
            $Open,
            $Count,
            $Await,
            $Observer,
            $users,
            $user,
            &$alarms,
            &$interruptions,
            &$children
         ): array {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               return ['fixture_error' => "{$label}: control socket pair failed"];
            }
            [$Parent, $Child] = $Pair;
            stream_set_timeout($Parent, 10);
            stream_set_timeout($Child, 10);

            $PID = pcntl_fork();
            if ($PID === -1) {
               fclose($Parent);
               fclose($Child);

               return ['fixture_error' => "{$label}: locker child could not fork"];
            }

            if ($PID === 0) {
               fclose($Parent);
               $Locker = null;
               $report = ['committed' => false, 'error' => null];

               try {
                  $Locker = $Open(1);
                  $Transaction = $Locker->begin();
                  $Begin = $Transaction->Operation;

                  if ($Begin === null) {
                     throw new RuntimeException('locker transaction did not expose BEGIN');
                  }
                  $Locker->await($Begin);
                  $Transaction->await($Transaction->query(
                     'SELECT id FROM "' . $users . '" WHERE id = $1 FOR UPDATE',
                     [$user]
                  ));

                  fwrite($Child, "READY\n");
                  usleep(3_000_000);
                  $Transaction->await($Transaction->commit());
                  $report['committed'] = true;
               }
               catch (Throwable $Throwable) {
                  $report['error'] = get_class($Throwable) . ': ' . $Throwable->getMessage();
               }
               finally {
                  if ($report['committed'] === false && $report['error'] !== null) {
                     fwrite($Child, 'ERROR ' . $report['error'] . "\n");
                  }

                  $encoded = json_encode($report);
                  fwrite($Child, (is_string($encoded) ? $encoded : '{}') . "\n");
                  $Locker?->Connection->disconnect();
                  fclose($Child);
               }

               exit($report['committed'] ? 0 : 1);
            }

            $children[$PID] = true;
            fclose($Child);
            $ready = fgets($Parent);
            $ready = is_string($ready) ? trim($ready) : '';

            if ($ready !== 'READY') {
               posix_kill($PID, SIGKILL);
               pcntl_waitpid($PID, $status);
               unset($children[$PID]);
               fclose($Parent);

               return ['fixture_error' => "{$label}: locker did not report READY; got {$ready}"];
            }

            $alarmBefore = $alarms;
            $interruptionsBefore = $interruptions;
            $Result = null;
            $failure = null;
            $started = microtime(true);
            pcntl_alarm(1);

            try {
               $Result = $Invoke();
            }
            catch (Throwable $Throwable) {
               $failure = get_class($Throwable) . ': ' . $Throwable->getMessage();
            }
            finally {
               pcntl_alarm(0);
            }

            $elapsed = microtime(true) - $started;
            $read = [$Parent];
            $write = [];
            $except = [];
            $childReported = stream_select($read, $write, $except, 0, 0) > 0;
            $atReturn = $Count($Observer, $table);
            $reportLine = fgets($Parent);
            $report = is_string($reportLine) ? json_decode(trim($reportLine), true) : null;
            // @ COMMIT releases the FK lock before PostgreSQL necessarily
            //   schedules the blocked INSERT session. Poll through independent
            //   autocommit snapshots; never advance the abandoned operation.
            $afterCommit = $Await($Observer, $table);
            $waited = pcntl_waitpid($PID, $status);
            unset($children[$PID]);
            fclose($Parent);

            return [
               'fixture_error' => null,
               'ready' => true,
               'returned' => $Result instanceof Token,
               'result_class' => is_object($Result) ? get_class($Result) : get_debug_type($Result),
               'failure' => $failure,
               'elapsed' => $elapsed,
               'alarms' => $alarms - $alarmBefore,
               'interruptions' => $interruptions - $interruptionsBefore,
               'child_reported_at_return' => $childReported,
               'rows_at_return' => $atReturn,
               'rows_after_commit' => $afterCommit,
               'child_report' => $report,
               'child_waited' => $waited === $PID,
               'child_exit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1,
            ];
         };

         $results['tokens'] = $Exercise(
            'Tokens::mint',
            $tokens,
            static fn (): Token => $Tokens->mint($user, Purposes::Recovery, 3600)
         );
         $results['trust'] = $Exercise(
            'Trust::issue',
            $trusts,
            static fn (): Token => $Trust->issue($user, 3600)
         );
      }
      catch (Throwable $Throwable) {
         $fixtureError = get_class($Throwable) . ': ' . $Throwable->getMessage();
      }
      finally {
         pcntl_alarm(0);

         foreach (array_keys($children) as $PID) {
            posix_kill($PID, SIGKILL);
            pcntl_waitpid($PID, $status);
         }

         $TokenDatabase?->Connection->disconnect();
         $TrustDatabase?->Connection->disconnect();
         $Observer?->Connection->disconnect();

         if ($Admin !== null) {
            foreach ($tables as $table) {
               if (in_array($table, $created, true) === false) {
                  continue;
               }

               try {
                  $Admin->await($Admin->query('DROP TABLE IF EXISTS "' . $table . '" CASCADE'));
               }
               catch (Throwable $CleanupFailure) {
                  $cleanupErrors[] = get_class($CleanupFailure) . ': ' . $CleanupFailure->getMessage();
               }
            }
            $Admin->Connection->disconnect();
         }

         Tokens::$gcProbability = $previousTokensGC;
         Trust::$gcProbability = $previousTrustGC;

         if ($errorsInstalled) {
            restore_error_handler();
         }
         error_reporting($previousReporting);

         pcntl_signal(SIGALRM, $PreviousSignal === false ? SIG_DFL : $PreviousSignal);
         pcntl_async_signals($previousAsync);
         if ($previousAlarm > 0) {
            pcntl_alarm($previousAlarm);
         }
      }

      $fixture = $fixtureError === null
         && $cleanupErrors === []
         && isset($results['tokens'], $results['trust'])
         && array_key_exists('fixture_error', $results['tokens'])
         && array_key_exists('fixture_error', $results['trust'])
         && $results['tokens']['fixture_error'] === null
         && $results['trust']['fixture_error'] === null
         && ($results['tokens']['ready'] ?? false)
         && ($results['trust']['ready'] ?? false)
         && ($results['tokens']['alarms'] ?? 0) === 1
         && ($results['trust']['alarms'] ?? 0) === 1
         && ($results['tokens']['interruptions'] ?? -1) === 0
         && ($results['trust']['interruptions'] ?? -1) === 0
         && ($results['tokens']['child_report']['committed'] ?? false) === true
         && ($results['trust']['child_report']['committed'] ?? false) === true
         && ($results['tokens']['child_report']['error'] ?? null) === null
         && ($results['trust']['child_report']['error'] ?? null) === null
         && ($results['tokens']['child_waited'] ?? false)
         && ($results['trust']['child_waited'] ?? false)
         && ($results['tokens']['child_exit'] ?? -1) === 0
         && ($results['trust']['child_exit'] ?? -1) === 0
         && ($results['tokens']['rows_after_commit'] ?? -1) === 1
         && ($results['trust']['rows_after_commit'] ?? -1) === 1;

      yield assert(
         assertion: $fixture,
         description: 'TOK-1: the live fixture must deliver one EINTR to each FK-blocked INSERT, '
            . 'commit both child transactions and leave each insert durable; found: '
            . json_encode([
               'fixture_error' => $fixtureError,
               'cleanup_errors' => $cleanupErrors,
               'results' => $results,
            ])
      );

      $tokenSecure = ($results['tokens']['returned'] ?? false)
         && ($results['tokens']['failure'] ?? null) === null
         && ($results['tokens']['elapsed'] ?? 0.0) >= 2.0
         && ($results['tokens']['child_reported_at_return'] ?? false) === true
         && ($results['tokens']['rows_at_return'] ?? -1) === 1
         && ($results['tokens']['rows_after_commit'] ?? -1) === 1;
      $trustSecure = ($results['trust']['returned'] ?? false)
         && ($results['trust']['failure'] ?? null) === null
         && ($results['trust']['elapsed'] ?? 0.0) >= 2.0
         && ($results['trust']['child_reported_at_return'] ?? false) === true
         && ($results['trust']['rows_at_return'] ?? -1) === 1
         && ($results['trust']['rows_after_commit'] ?? -1) === 1;

      yield assert(
         assertion: $tokenSecure && $trustSecure,
         description: 'TOK-1 CONFIRMED: Tokens::mint() and Trust::issue() must not return live-looking '
            . 'tokens while their EINTR-interrupted INSERTs are still blocked and invisible, then let '
            . 'those writes commit later; found: ' . json_encode($results)
      );
   }
);
