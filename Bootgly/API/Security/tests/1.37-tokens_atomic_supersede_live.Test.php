<?php

namespace Bootgly\API\Security\Tests\TokensAtomicSupersedeLive;


use const SIGKILL;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function abs;
use function array_filter;
use function array_map;
use function assert;
use function bin2hex;
use function count;
use function fclose;
use function fgets;
use function function_exists;
use function fwrite;
use function get_class;
use function get_debug_type;
use function getenv;
use function getmypid;
use function is_array;
use function is_numeric;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function microtime;
use function min;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function posix_kill;
use function random_bytes;
use function stream_set_timeout;
use function stream_socket_pair;
use function usleep;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Token;


// ! Opt-in real-driver E2E. Once enabled, missing process primitives and
//   connection failures are fixture failures rather than safety-like skips.
$optin = getenv('BOOTGLY_TOK9_E2E') === '1';


return new Test(
   description: 'Security/Tokens(live): concurrent PostgreSQL mint() calls atomically supersede one user-purpose token (requires BOOTGLY_TOK9_E2E=1)',
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
         'driver' => 'pgsql',
         'host' => $host === false ? '127.0.0.1' : $host,
         'port' => $port === false ? 5432 : (int) $port,
         'database' => $database === false ? 'postgres' : $database,
         'username' => $username === false ? 'postgres' : $username,
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
      $table = 'bootgly_tok9_' . bin2hex(random_bytes(6));
      $tableSQL = "\"{$table}\"";
      $user = 'tok9-user-' . bin2hex(random_bytes(4));
      $clock = 2_000_000_000;
      $previousGC = Tokens::$gcProbability;
      $Admin = null;
      $Observer = null;
      /** @var array<int,array{0:resource,1:resource}> $Pairs */
      $Pairs = [];
      /** @var array<int,int> $Children */
      $Children = [];
      /** @var array<int,array<string,mixed>> $Readies */
      $Readies = [];
      /** @var array<int,array<string,mixed>> $Results */
      $Results = [];
      /** @var array<int,array<string,mixed>> $Statuses */
      $Statuses = [];
      $fixtureError = null;
      $cleanupError = null;
      $initialCount = null;
      $pairUniqueIndexes = null;
      $seedLive = null;
      $concurrentCount = null;
      $returnedCount = 0;
      $validCount = 0;
      $seedAfter = null;
      $postFollowupCount = null;
      $followupLive = null;
      $previousValidAfter = [];
      $Followup = null;

      /**
       * Execute and await one real PostgreSQL operation.
       *
       * @param array<int|string,mixed> $parameters
       */
      $Await = static function (
         SQL $Database,
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
       * Count rows for the contested user-purpose pair.
       */
      $Count = static function (
         SQL $Database,
         string $tableSQL,
         string $user
      ) use ($Await): int {
         $Operation = $Await(
            $Database,
            "SELECT count(*) AS total FROM {$tableSQL} WHERE user_id = \$1 AND purpose = \$2",
            [$user, Purposes::Verification->value]
         );
         $total = $Operation->rows[0]['total'] ?? null;

         if (is_numeric($total) === false) {
            throw new RuntimeException('TOK-9 fixture could not count token rows.');
         }

         return (int) $total;
      };

      Tokens::$gcProbability = [0, 1];

      try {
         foreach ([
            'pcntl_fork',
            'pcntl_waitpid',
            'posix_kill',
            'stream_socket_pair',
         ] as $function) {
            if (function_exists($function) === false) {
               throw new RuntimeException("TOK-9 fixture requires {$function}().");
            }
         }

         // # The retained regression uses the secure schema contract: both
         //   the public selector and the user-purpose identity are unique.
         $Admin = new SQL($config);
         $Await(
            $Admin,
            "CREATE TABLE {$tableSQL} ("
               . 'id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, '
               . 'selector VARCHAR(16) NOT NULL UNIQUE, '
               . 'verifier VARCHAR(64) NOT NULL, '
               . 'user_id TEXT NOT NULL, '
               . 'purpose VARCHAR(32) NOT NULL, '
               . 'expires BIGINT NOT NULL, '
               . 'UNIQUE (user_id, purpose)'
               . ')'
         );
         $AdminTokens = new Tokens($Admin, $table);
         $AdminTokens->freeze($clock);
         $Seed = $AdminTokens->mint($user, Purposes::Verification, 3600);
         $initialCount = $Count($Admin, $tableSQL, $user);
         $seedLive = $AdminTokens->check($Seed->value, Purposes::Verification);
         $Index = $Await(
            $Admin,
            <<<'SQL'
            SELECT count(*) AS total
            FROM pg_indexes
            WHERE schemaname = current_schema()
              AND tablename = $1
              AND indexdef ~ 'UNIQUE.*\(user_id, purpose\)'
            SQL,
            [$table]
         );
         $pairUniqueIndexes = (int) ($Index->rows[0]['total'] ?? -1);

         // @ No live database handle is inherited by either child. Each child
         //   must authenticate and own a distinct server session itself.
         $Admin->Connection->disconnect();
         $Admin = null;
         unset($AdminTokens);

         for ($index = 0; $index < 2; $index++) {
            $Pair = stream_socket_pair(
               STREAM_PF_UNIX,
               STREAM_SOCK_STREAM,
               STREAM_IPPROTO_IP
            );
            if ($Pair === false) {
               throw new RuntimeException('TOK-9 fixture could not create a control socket.');
            }

            stream_set_timeout($Pair[0], 15);
            stream_set_timeout($Pair[1], 15);
            $Pairs[$index] = $Pair;
         }

         for ($index = 0; $index < 2; $index++) {
            $PID = pcntl_fork();
            if ($PID === -1) {
               throw new RuntimeException('TOK-9 fixture could not fork a mint worker.');
            }

            if ($PID === 0) {
               $Socket = $Pairs[$index][1];
               foreach ($Pairs as $pairIndex => $Pair) {
                  foreach ($Pair as $side => $Stream) {
                     if ($pairIndex === $index && $side === 1) {
                        continue;
                     }
                     if (is_resource($Stream)) {
                        fclose($Stream);
                     }
                  }
               }

               $WorkerDatabase = null;
               $report = [
                  'worker' => $index,
                  'process_pid' => getmypid(),
                  'backend_pid' => null,
                  'scheduled' => null,
                  'started' => null,
                  'finished' => null,
                  'result' => null,
                  'token' => null,
                  'selector' => null,
                  'error' => null,
               ];

               try {
                  $WorkerDatabase = new SQL($config);
                  $Warm = $Await($WorkerDatabase, 'SELECT pg_backend_pid() AS pid');
                  $report['backend_pid'] = $Warm->rows[0]['pid'] ?? null;
                  $ready = json_encode([
                     'worker' => $index,
                     'process_pid' => $report['process_pid'],
                     'backend_pid' => $report['backend_pid'],
                  ]);
                  fwrite($Socket, (is_string($ready) ? $ready : '{}') . "\n");

                  $line = fgets($Socket);
                  $target = is_string($line) ? (float) $line : 0.0;
                  if ($target <= microtime(true)) {
                     throw new RuntimeException('TOK-9 worker missed its synchronized release.');
                  }
                  $report['scheduled'] = $target;

                  // @ Sleep most of the interval, then use a sub-millisecond
                  //   spin so the two already-warmed processes enter mint()
                  //   within one database statement round trip.
                  while (($remaining = $target - microtime(true)) > 0.0) {
                     if ($remaining > 0.002) {
                        usleep((int) (($remaining - 0.001) * 1_000_000));
                     }
                  }

                  $WorkerTokens = new Tokens($WorkerDatabase, $table);
                  $WorkerTokens->freeze($clock);
                  $report['started'] = microtime(true);
                  $Token = $WorkerTokens->mint($user, Purposes::Verification, 3600);
                  $report['finished'] = microtime(true);
                  $report['result'] = get_class($Token);
                  $report['token'] = $Token->value;
                  $report['selector'] = $Token->selector;
               }
               catch (Throwable $Failure) {
                  $report['finished'] = microtime(true);
                  $report['error'] = get_class($Failure) . ': ' . $Failure->getMessage();
               }
               finally {
                  $encoded = json_encode($report);
                  fwrite($Socket, (is_string($encoded) ? $encoded : '{}') . "\n");

                  try {
                     $WorkerDatabase?->Connection->disconnect();
                  }
                  catch (Throwable) {
                     // The parent reports the operation outcome and owns the
                     // durable-state control; disconnect is best-effort here.
                  }

                  fclose($Socket);
               }

               exit($report['error'] === null ? 0 : 1);
            }

            $Children[$PID] = $index;
         }

         // @ The parent retains only its ends after every fork, ensuring no
         //   sibling can manufacture EOF or consume another worker's release.
         foreach ($Pairs as $Pair) {
            fclose($Pair[1]);
         }

         foreach ($Pairs as $index => $Pair) {
            $line = fgets($Pair[0]);
            $Ready = is_string($line) ? json_decode($line, true) : null;
            if (is_array($Ready) === false) {
               throw new RuntimeException("TOK-9 worker {$index} did not report READY.");
            }
            $Readies[$index] = $Ready;
         }

         // # Both independent sessions are ready before either is released.
         //   A common wall-clock target removes sequential pipe-write skew.
         $target = microtime(true) + 0.250;
         foreach ($Pairs as $Pair) {
            fwrite($Pair[0], "{$target}\n");
         }

         foreach ($Pairs as $index => $Pair) {
            $line = fgets($Pair[0]);
            $Result = is_string($line) ? json_decode($line, true) : null;
            if (is_array($Result) === false) {
               throw new RuntimeException("TOK-9 worker {$index} did not report a result.");
            }
            $Results[$index] = $Result;
         }

         foreach ($Children as $PID => $index) {
            $waited = pcntl_waitpid($PID, $status);
            $Statuses[$index] = [
               'waited' => $waited,
               'exited' => pcntl_wifexited($status),
               'status' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
            ];
            unset($Children[$PID]);
         }

         // ! Source to sink: re-read through a third session and validate both
         //   raw values through Tokens::check(), not direct digest inspection.
         $Observer = new SQL($config);
         $ObserverTokens = new Tokens($Observer, $table);
         $ObserverTokens->freeze($clock);
         $concurrentCount = $Count($Observer, $tableSQL, $user);
         $seedAfter = $ObserverTokens->check($Seed->value, Purposes::Verification);
         $Values = array_map(
            static fn (array $Result): mixed => $Result['token'] ?? null,
            $Results
         );
         $returnedCount = count(array_filter(
            $Values,
            static fn (mixed $value): bool => is_string($value)
         ));
         $Validity = array_map(
            static fn (mixed $value): bool => is_string($value)
               && $ObserverTokens->check($value, Purposes::Verification),
            $Values
         );
         $validCount = count(array_filter($Validity));

         // @ Sequential control: an ordinary later mint must supersede every
         //   concurrent result and leave exactly its own credential live.
         $Followup = $ObserverTokens->mint($user, Purposes::Verification, 3600);
         $postFollowupCount = $Count($Observer, $tableSQL, $user);
         $previousValidAfter = array_map(
            static fn (mixed $value): bool => is_string($value)
               && $ObserverTokens->check($value, Purposes::Verification),
            $Values
         );
         $followupLive = $ObserverTokens->check(
            $Followup->value,
            Purposes::Verification
         );
      }
      catch (Throwable $Failure) {
         $fixtureError = get_class($Failure) . ': ' . $Failure->getMessage();
      }
      finally {
         foreach ($Children as $PID => $index) {
            posix_kill($PID, SIGKILL);
            pcntl_waitpid($PID, $status);
            $Statuses[$index] = [
               'waited' => $PID,
               'exited' => pcntl_wifexited($status),
               'status' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
               'killed' => true,
            ];
         }

         foreach ($Pairs as $Pair) {
            foreach ($Pair as $Stream) {
               if (is_resource($Stream)) {
                  fclose($Stream);
               }
            }
         }

         try {
            $CleanupDatabase = new SQL($config);
            $Await($CleanupDatabase, "DROP TABLE IF EXISTS {$tableSQL}");
            $CleanupDatabase->Connection->disconnect();
         }
         catch (Throwable $Failure) {
            $cleanupError = get_class($Failure) . ': ' . $Failure->getMessage();
         }

         try {
            $Admin?->Connection->disconnect();
            $Observer?->Connection->disconnect();
         }
         catch (Throwable $Failure) {
            $disconnectError = get_class($Failure) . ': ' . $Failure->getMessage();
            $cleanupError = $cleanupError === null
               ? $disconnectError
               : "{$cleanupError}; {$disconnectError}";
         }

         Tokens::$gcProbability = $previousGC;
      }

      $processPIDs = array_map(
         static fn (array $Ready): mixed => $Ready['process_pid'] ?? null,
         $Readies
      );
      $backendPIDs = array_map(
         static fn (array $Ready): mixed => $Ready['backend_pid'] ?? null,
         $Readies
      );
      $starts = array_map(
         static fn (array $Result): float => (float) ($Result['started'] ?? 0.0),
         $Results
      );
      $finishes = array_map(
         static fn (array $Result): float => (float) ($Result['finished'] ?? 0.0),
         $Results
      );
      $fixture = $fixtureError === null
         && $cleanupError === null
         && $initialCount === 1
         && $pairUniqueIndexes === 1
         && $seedLive === true
         && count($Readies) === 2
         && count($Results) === 2
         && count($Statuses) === 2
         && is_numeric($processPIDs[0] ?? null)
         && is_numeric($processPIDs[1] ?? null)
         && (string) $processPIDs[0] !== (string) $processPIDs[1]
         && is_numeric($backendPIDs[0] ?? null)
         && is_numeric($backendPIDs[1] ?? null)
         && (string) $backendPIDs[0] !== (string) $backendPIDs[1]
         && ($Statuses[0]['exited'] ?? false) === true
         && ($Statuses[0]['status'] ?? null) === 0
         && ($Statuses[1]['exited'] ?? false) === true
         && ($Statuses[1]['status'] ?? null) === 0
         && ($Results[0]['error'] ?? null) === null
         && ($Results[1]['error'] ?? null) === null
         && ($Results[0]['result'] ?? null) === Token::class
         && ($Results[1]['result'] ?? null) === Token::class
         && count($starts) === 2
         && count($finishes) === 2
         && min($starts) > 0.0
         && max($starts) <= min($finishes)
         && abs($starts[0] - $starts[1]) < 0.050;
      $Evidence = [
         'fixture_error' => $fixtureError,
         'cleanup_error' => $cleanupError,
         'initial_rows' => $initialCount,
         'pair_unique_indexes' => $pairUniqueIndexes,
         'seed_live_before' => $seedLive,
         'ready' => $Readies,
         'results' => $Results,
         'statuses' => $Statuses,
         'start_skew_seconds' => count($starts) === 2 ? abs($starts[0] - $starts[1]) : null,
         'intervals_overlap' => count($starts) === 2
            && count($finishes) === 2
            && max($starts) <= min($finishes),
         'concurrent_rows' => $concurrentCount,
         'returned_tokens' => $returnedCount,
         'valid_returned_tokens' => $validCount,
         'seed_live_after' => $seedAfter,
         'followup' => get_debug_type($Followup),
         'rows_after_followup' => $postFollowupCount,
         'prior_live_after_followup' => $previousValidAfter,
         'followup_live' => $followupLive,
      ];

      yield assert(
         assertion: $fixture,
         description: 'TOK-9 PostgreSQL fixture uses two synchronized processes and distinct '
            . 'sessions against a single user-purpose UNIQUE key; evidence='
            . json_encode($Evidence)
      );

      $secure = $fixture
         && $concurrentCount === 1
         && $returnedCount === 2
         && $validCount === 1
         && $seedAfter === false
         && $Followup instanceof Token
         && $postFollowupCount === 1
         && $previousValidAfter === [false, false]
         && $followupLive === true;

      yield assert(
         assertion: $secure,
         description: 'TOK-9 CONFIRMED: concurrent mint() calls must leave one user-purpose row '
            . 'and exactly one returned token live, while a follow-up supersedes it; evidence='
            . json_encode($Evidence)
      );
   }
);
