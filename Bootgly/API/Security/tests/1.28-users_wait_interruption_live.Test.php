<?php

namespace Bootgly\API\Security\Tests\UsersWaitInterruptionLive;


use const LC_MESSAGES;
use const SIGALRM;
use const SIGKILL;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use const WNOHANG;
use function array_key_exists;
use function array_keys;
use function assert;
use function base64_encode;
use function bin2hex;
use function count;
use function error_clear_last;
use function error_get_last;
use function error_reporting;
use function extension_loaded;
use function fclose;
use function fgets;
use function fsockopen;
use function function_exists;
use function fwrite;
use function getenv;
use function hrtime;
use function implode;
use function is_resource;
use function is_string;
use function json_encode;
use function password_verify;
use function pcntl_alarm;
use function pcntl_async_signals;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function posix_kill;
use function random_bytes;
use function setlocale;
use function str_contains;
use function stream_get_contents;
use function stream_set_timeout;
use function stream_socket_pair;
use function trim;
use function usleep;
use Closure;
use PDO;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\Identity;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Users;


// ! Opt-in live E2E — BOOTGLY_PGSQL_E2E=1 + DB_* environment
$optin = getenv('BOOTGLY_PGSQL_E2E') === '1';
$localeOptin = getenv('BOOTGLY_PGSQL_LOCALE_E2E') === '1';
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
   $Probe = @fsockopen($host, $port, $errorCode, $error, 0.5);
   $reachable = is_resource($Probe);

   if ($reachable) {
      fclose($Probe);
   }
}


return new Test(
   description: 'Security/Users(live): interrupted database waits never become credential verdicts (requires BOOTGLY_PGSQL_E2E=1)',
   skip: $optin === false
      || $reachable === false
      || extension_loaded('pdo_pgsql') === false
      || $capable === false,
   test: function () use ($host, $port, $localeOptin) {
      $database = getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : 'postgres';
      $username = getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'postgres';
      $password = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
      $SSLMode = getenv('DB_SSLMODE') !== false ? (string) getenv('DB_SSLMODE') : 'disable';
      $table = 'bootgly_usr_wait_' . bin2hex(random_bytes(6));
      $email = "known-{$table}@bootgly.test";
      $lateEmail = "late-{$table}@bootgly.test";
      $DSN = "pgsql:host={$host};port={$port};dbname={$database};sslmode={$SSLMode}";
      $PIDs = [];
      $Streams = [];
      $Databases = [];
      $fixtureError = null;
      $cleanupError = null;
      $Evidence = [];
      $PreviousLocale = setlocale(LC_MESSAGES, '0');
      $localized = false;

      /** Open one independent PostgreSQL observer/locker connection. */
      $OpenPDO = static function () use ($DSN, $username, $password): PDO {
         return new PDO($DSN, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         ]);
      };
      /** Open one fresh one-connection Bootgly pool. */
      $OpenSQL = static function () use (
         $host,
         $port,
         $database,
         $username,
         $password,
         $SSLMode
      ): SQL {
         return new SQL([
            'driver' => 'pgsql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'timeout' => 5.0,
            'secure' => ['mode' => $SSLMode],
            'pool' => ['min' => 0, 'max' => 1],
         ]);
      };
      /** Capture a public call without preventing the remaining evidence from being collected. */
      $Capture = static function (Closure $Callback): array {
         try {
            return [
               'result' => $Callback(),
               'error' => null,
            ];
         }
         catch (Throwable $Failure) {
            return [
               'result' => null,
               'error' => $Failure::class . ': ' . $Failure->getMessage(),
            ];
         }
      };
      /**
       * Take an ACCESS EXCLUSIVE lock in a child and retain it long enough for
       * SIGALRM to interrupt the parent's Pool::wait() stream_select().
       *
       * @return array{pid:int,stream:resource,ready:string}
       */
      $Lock = static function () use ($OpenPDO, $table, &$PIDs, &$Streams): array {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

         if ($Pair === false) {
            throw new RuntimeException('USR-3 fixture could not create its lock IPC pair.');
         }

         $PID = pcntl_fork();

         if ($PID === -1) {
            fclose($Pair[0]);
            fclose($Pair[1]);

            throw new RuntimeException('USR-3 fixture could not fork its PostgreSQL locker.');
         }

         if ($PID === 0) {
            fclose($Pair[0]);
            pcntl_alarm(0);

            try {
               $PDO = $OpenPDO();
               $PDO->exec('BEGIN');
               $PDO->exec("LOCK TABLE \"{$table}\" IN ACCESS EXCLUSIVE MODE");
               fwrite($Pair[1], "locked\n");

               // ! Pool::wait() selects for one second. The parent arms its
               //   alarm first and enters the call 250 ms later, so the signal
               //   lands well inside that select rather than on its boundary.
               usleep(2_500_000);

               $PDO->exec('COMMIT');
               fwrite($Pair[1], "released\n");
               fclose($Pair[1]);
               exit(0);
            }
            catch (Throwable $Failure) {
               fwrite($Pair[1], 'error:' . base64_encode($Failure->getMessage()) . "\n");
               fclose($Pair[1]);
               exit(1);
            }
         }

         fclose($Pair[1]);
         stream_set_timeout($Pair[0], 5);
         $ready = fgets($Pair[0]);
         $ready = is_string($ready) ? trim($ready) : '';
         $PIDs[$PID] = true;
         $Streams[$PID] = $Pair[0];

         if ($ready !== 'locked') {
            throw new RuntimeException("USR-3 fixture locker failed before the lock: {$ready}");
         }

         return [
            'pid' => $PID,
            'stream' => $Pair[0],
            'ready' => $ready,
         ];
      };
      /** Reap one locker and collect its post-lock outcome. */
      $Reap = static function (array $Child) use (&$PIDs, &$Streams): array {
         $PID = $Child['pid'];
         $status = 0;
         $waited = pcntl_waitpid($PID, $status);
         $tail = stream_get_contents($Child['stream']);

         if (is_resource($Child['stream'])) {
            fclose($Child['stream']);
         }

         unset($PIDs[$PID], $Streams[$PID]);

         return [
            'waited' => $waited,
            'exited' => pcntl_wifexited($status),
            'status' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
            'tail' => is_string($tail) ? trim($tail) : '',
         ];
      };
      /**
       * Run one public Users call across a deterministic SIGALRM interruption.
       * Error reporting is temporarily suppressed to model the WPI worker's
       * restored default handler without leaking the expected EINTR warning.
       */
      $Interrupt = static function (Closure $Callback): array {
         $PreviousSignal = pcntl_signal_get_handler(SIGALRM);
         $previousAsync = pcntl_async_signals();
         $previousAlarm = pcntl_alarm(0);
         $previousReporting = error_reporting(0);
         $signals = 0;
         $Result = null;
         $error = null;
         $LastError = null;

         error_clear_last();
         pcntl_async_signals(true);
         pcntl_signal(SIGALRM, static function () use (&$signals): void {
            $signals++;
         }, false);
         pcntl_alarm(1);
         usleep(250_000);
         $started = hrtime(true);

         try {
            $Result = $Callback();
         }
         catch (Throwable $Failure) {
            $error = $Failure::class . ': ' . $Failure->getMessage();
         }
         finally {
            $elapsed = (hrtime(true) - $started) / 1_000_000_000;
            $LastError = error_get_last();
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $PreviousSignal, false);
            pcntl_async_signals($previousAsync);
            error_reporting($previousReporting);

            if ($previousAlarm > 0) {
               pcntl_alarm($previousAlarm);
            }
         }

         return [
            'result' => $Result,
            'error' => $error,
            'elapsed' => $elapsed,
            'signals' => $signals,
            'warning' => is_string($LastError['message'] ?? null)
               ? $LastError['message']
               : null,
         ];
      };

      try {
         // @ A unique table keeps this live opt-in isolated from parallel runs.
         $Admin = $OpenPDO();
         $Admin->exec(<<<SQL
         CREATE TABLE "{$table}" (
            id BIGSERIAL PRIMARY KEY,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            email_verified_at BIGINT DEFAULT NULL
         )
         SQL);
         $Admin = null;

         $Password = new Password(memory: 19456, time: 2, threads: 1);

         // # USR-2 control — the public store returns an id string. PostgreSQL
         //   must infer its BIGINT target when that string is rebound by every
         //   id-keyed read/write.
         $ControlDatabase = $OpenSQL();
         $Databases[] = $ControlDatabase;
         $ControlUsers = new Users($ControlDatabase, $Password, table: $table);
         $ControlUsers->freeze(1_000_000);
         $Enroll = $Capture(static fn () => $ControlUsers->enroll($email, 'initial-secret'));
         $ID = is_string($Enroll['result']) ? $Enroll['result'] : '';
         $Check = $Capture(static fn () => $ControlUsers->check($ID, 'initial-secret'));
         $RotateControl = $Capture(static fn () => $ControlUsers->rotate($ID, 'ready-secret'));
         $Confirm = $Capture(static fn () => $ControlUsers->confirm($ID));
         $Verify = $Capture(static fn () => $ControlUsers->verify($email, 'ready-secret'));
         $ControlDatabase->Connection->disconnect();

         $Evidence['usr2'] = [
            'enroll' => $Enroll,
            'id' => $ID,
            'check' => $Check,
            'rotate' => $RotateControl,
            'confirm' => $Confirm,
            'verify' => [
               'error' => $Verify['error'],
               'id' => $Verify['result'] instanceof Identity ? $Verify['result']->id : null,
               'verified' => $Verify['result'] instanceof Identity
                  ? ($Verify['result']->claims['verified'] ?? null)
                  : null,
            ],
         ];

         // # USR-3 — a read is on PostgreSQL's wire when SIGALRM interrupts the
         //   readiness wait. It must resume and return the real row, never turn
         //   that infrastructure event into "no account".
         $FetchChild = $Lock();
         $FetchDatabase = $OpenSQL();
         $Databases[] = $FetchDatabase;
         $FetchUsers = new Users($FetchDatabase, $Password, table: $table);
         $localized = setlocale(LC_MESSAGES, 'pt_BR.UTF-8', 'pt_BR.utf8');
         if ($localeOptin && $localized === false) {
            throw new RuntimeException('USR-3 localized EINTR gate requires a pt_BR locale.');
         }
         $Fetch = $Interrupt(static fn () => $FetchUsers->fetch($email));
         if (is_string($PreviousLocale)) {
            setlocale(LC_MESSAGES, $PreviousLocale);
         }
         $FetchPool = [
            'pending' => count($FetchDatabase->Pool->pending),
            'busy' => count($FetchDatabase->Pool->busy),
            'idle' => count($FetchDatabase->Pool->idle),
         ];
         $FetchChildResult = $Reap($FetchChild);
         $Observer = $OpenPDO();
         $Statement = $Observer->prepare("SELECT count(*) FROM \"{$table}\" WHERE email = ?");
         $Statement->execute([$email]);
         $knownRows = (int) $Statement->fetchColumn();
         $Observer = null;
         $FetchDatabase->Connection->disconnect();

         $Evidence['usr3'] = [
            'call' => [
               'error' => $Fetch['error'],
               'id' => $Fetch['result'] instanceof Identity ? $Fetch['result']->id : null,
               'elapsed' => $Fetch['elapsed'],
               'signals' => $Fetch['signals'],
               'warning' => $Fetch['warning'],
               'locale' => $localized,
            ],
            'pool' => $FetchPool,
            'child' => ['ready' => $FetchChild['ready']] + $FetchChildResult,
            'known_rows' => $knownRows,
         ];

         // # USR-5 — the same interruption while UPDATE waits on the lock must
         //   not report false after PostgreSQL commits the new password hash.
         $RotateChild = $Lock();
         $RotateDatabase = $OpenSQL();
         $Databases[] = $RotateDatabase;
         $RotateUsers = new Users($RotateDatabase, $Password, table: $table);
         $Rotate = $Interrupt(static fn () => $RotateUsers->rotate($ID, 'interrupted-secret'));
         $RotatePool = [
            'pending' => count($RotateDatabase->Pool->pending),
            'busy' => count($RotateDatabase->Pool->busy),
            'idle' => count($RotateDatabase->Pool->idle),
         ];
         $RotateChildResult = $Reap($RotateChild);

         // @ The vulnerable caller returned before PostgreSQL could answer. Poll
         //   independently so scheduling after the child COMMIT cannot make a
         //   committed UPDATE look absent.
         $Observer = $OpenPDO();
         $Statement = $Observer->prepare("SELECT password FROM \"{$table}\" WHERE id = ?");
         $stored = '';

         for ($attempt = 0; $attempt < 40; $attempt++) {
            $Statement->execute([$ID]);
            $value = $Statement->fetchColumn();
            $stored = is_string($value) ? $value : '';

            if (password_verify('interrupted-secret', $stored)) {
               break;
            }

            usleep(50_000);
         }

         $Observer = null;
         $RotateDatabase->Connection->disconnect();

         $Evidence['usr5'] = [
            'call' => $Rotate,
            'pool' => $RotatePool,
            'child' => ['ready' => $RotateChild['ready']] + $RotateChildResult,
            'old_password' => password_verify('ready-secret', $stored),
            'new_password' => password_verify('interrupted-secret', $stored),
         ];

         // # USR-1 control — POOL-5 already changed the historical saturation
         //   arm. Refused work must be removed before the transaction releases
         //   capacity, so no INSERT can arrive later in autocommit.
         $SaturationDatabase = $OpenSQL();
         $Databases[] = $SaturationDatabase;
         $SaturationUsers = new Users($SaturationDatabase, $Password, table: $table);
         $Transaction = $SaturationDatabase->begin();
         $Begin = $Transaction->Operation;

         if ($Begin === null) {
            throw new RuntimeException('USR-1 control did not expose its BEGIN operation.');
         }

         $BeginOutcome = $Capture(static fn () => $SaturationDatabase->await($Begin));
         $Late = $Capture(static fn () => $SaturationUsers->enroll($lateEmail, 'late-secret'));
         $pending = count($SaturationDatabase->Pool->pending);
         $Rollback = $Capture(static fn () => $SaturationDatabase->await($Transaction->rollback()));
         $Followup = $Capture(static fn () => $SaturationDatabase->await(
            $SaturationDatabase->query('SELECT 1 AS drained')
         ));
         $Observer = $OpenPDO();
         $Statement = $Observer->prepare("SELECT count(*) FROM \"{$table}\" WHERE email = ?");
         $Statement->execute([$lateEmail]);
         $lateRows = (int) $Statement->fetchColumn();
         $Observer = null;
         $SaturationDatabase->Connection->disconnect();

         $Evidence['usr1'] = [
            'begin' => [
               'error' => $BeginOutcome['error'],
               'finished' => $BeginOutcome['result']?->finished,
            ],
            'enroll' => $Late,
            'pending' => $pending,
            'rollback' => [
               'error' => $Rollback['error'],
               'finished' => $Rollback['result']?->finished,
            ],
            'followup' => [
               'error' => $Followup['error'],
               'finished' => $Followup['result']?->finished,
            ],
            'late_rows' => $lateRows,
         ];
      }
      catch (Throwable $Failure) {
         $fixtureError = $Failure::class . ': ' . $Failure->getMessage();
      }
      finally {
         if (is_string($PreviousLocale)) {
            setlocale(LC_MESSAGES, $PreviousLocale);
         }

         foreach (array_keys($PIDs) as $PID) {
            $status = 0;
            $reaped = pcntl_waitpid($PID, $status, WNOHANG);

            if ($reaped === 0) {
               posix_kill($PID, SIGKILL);
               pcntl_waitpid($PID, $status);
            }
         }

         foreach ($Streams as $Stream) {
            if (is_resource($Stream)) {
               fclose($Stream);
            }
         }

         foreach ($Databases as $Database) {
            $Database->Connection->disconnect();
         }

         try {
            $Admin = $OpenPDO();
            $Admin->exec("DROP TABLE IF EXISTS \"{$table}\"");
            $Admin = null;
         }
         catch (Throwable $Failure) {
            $cleanupError = $Failure::class . ': ' . $Failure->getMessage();
         }
      }

      // ! Everything above is collected before the first assertion: a failing
      //   security verdict cannot skip child reaping, table cleanup or controls.
      yield assert(
         assertion: $fixtureError === null && $cleanupError === null,
         description: 'USR-3 fixture and cleanup complete without error; found: '
            . json_encode([$fixtureError, $cleanupError])
      );

      $USR2 = $Evidence['usr2'] ?? [];
      yield assert(
         assertion: is_string($USR2['enroll']['result'] ?? null)
            && ($USR2['enroll']['error'] ?? null) === null
            && ($USR2['check']['result'] ?? null) === true
            && ($USR2['check']['error'] ?? null) === null
            && ($USR2['rotate']['result'] ?? null) === true
            && ($USR2['rotate']['error'] ?? null) === null
            && ($USR2['confirm']['result'] ?? null) === true
            && ($USR2['confirm']['error'] ?? null) === null
            && ($USR2['verify']['id'] ?? null) === $USR2['id']
            && ($USR2['verify']['verified'] ?? null) === true,
         description: 'USR-2 control: a string account id remains valid against the PostgreSQL BIGSERIAL key; found: '
            . json_encode($USR2)
      );

      $USR1 = $Evidence['usr1'] ?? [];
      yield assert(
         assertion: ($USR1['begin']['error'] ?? null) === null
            && array_key_exists('result', $USR1['enroll'] ?? [])
            && $USR1['enroll']['result'] === null
            && ($USR1['enroll']['error'] ?? null) === null
            && $USR1['pending'] === 0
            && ($USR1['rollback']['error'] ?? null) === null
            && ($USR1['followup']['error'] ?? null) === null
            && $USR1['late_rows'] === 0,
         description: 'USR-1 control: a saturated enrollment is removed from pending and never commits after capacity returns; found: '
            . json_encode($USR1)
      );

      $USR3 = $Evidence['usr3'] ?? [];
      $USR5 = $Evidence['usr5'] ?? [];
      $fetchInterrupted = str_contains(
         (string) ($USR3['call']['warning'] ?? ''),
         'Interrupted system call'
      ) || $USR3['call']['elapsed'] >= 1.5;
      $rotateInterrupted = str_contains(
         (string) ($USR5['call']['warning'] ?? ''),
         'Interrupted system call'
      ) || $USR5['call']['elapsed'] >= 1.5;
      $triggered = $USR3['call']['signals'] === 1
         && ($localeOptin === false || is_string($USR3['call']['locale'] ?? null))
         && $fetchInterrupted
         && $USR3['known_rows'] === 1
         && $USR3['child']['tail'] === 'released'
         && ($USR3['child']['status'] ?? null) === 0
         && $USR5['call']['signals'] === 1
         && $rotateInterrupted
         && $USR5['child']['tail'] === 'released'
         && ($USR5['child']['status'] ?? null) === 0
         && $USR5['old_password'] === false
         && $USR5['new_password'] === true;

      yield assert(
         assertion: $triggered,
         description: 'USR-3/USR-5 fixture reaches real PostgreSQL work interrupted inside Pool::wait(), with state proven independently; found: '
            . json_encode(['usr3' => $USR3, 'usr5' => $USR5])
      );

      $USR3Pool = $USR3['pool'] ?? ['pending' => -1, 'busy' => -1, 'idle' => -1];
      $USR5Pool = $USR5['pool'] ?? ['pending' => -1, 'busy' => -1, 'idle' => -1];
      $secureFetch = ($USR3['call']['error'] ?? null) === null
         && ($USR3['call']['id'] ?? null) === ($USR2['id'] ?? null)
         && $USR3Pool['pending'] === 0
         && $USR3Pool['busy'] === 0
         && $USR3Pool['idle'] === 1;
      $secureRotate = ($USR5['call']['error'] ?? null) === null
         && ($USR5['call']['result'] ?? null) === true
         && $USR5Pool['pending'] === 0
         && $USR5Pool['busy'] === 0
         && $USR5Pool['idle'] === 1;
      $diagnostics = [];

      if ($secureFetch === false) {
         $diagnostics[] = 'USR-3 CONFIRMED: fetch() converted an interrupted live account read into no account';
      }

      if ($secureRotate === false) {
         $diagnostics[] = 'USR-5 CONFIRMED: rotate() reported failure although PostgreSQL committed the new hash';
      }

      yield assert(
         assertion: $secureFetch && $secureRotate,
         description: ($diagnostics !== []
            ? implode('; ', $diagnostics)
            : 'Interrupted Users operations resume to their real result')
            . '; evidence: ' . json_encode(['usr3' => $USR3, 'usr5' => $USR5])
      );
   }
);
