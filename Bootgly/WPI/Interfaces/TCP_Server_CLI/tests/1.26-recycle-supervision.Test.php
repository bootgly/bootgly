<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * A recycle is proved, not claimed — live, on a one-worker Foreground HTTP
 * server with `maxRequests: 1` (every answered request recycles its worker)
 * whose workers exit with status 75 (`RECYCLE`) at boot while a counter file
 * says so. Every verdict below is read per worker PID, so it holds whichever
 * legitimate path the master takes — the overlap (`recycling, forking its
 * replacement`) or a recycle whose exit it reaps before reading the beacon
 * (`recycled, reforking`):
 *
 * - Four workers in a row that exit 75 before serving are crashes: the first
 *   two are reforked at once, the next ones after the crash-loop backoff, and
 *   none is logged as a recycle.
 * - A recycle ends the crash streak of its slot: after the worker that served
 *   recycles, the next two boot deaths are reforked at once again — never
 *   counted on top of the earlier streak.
 * - The reap-before-read path, driven on purpose: the master is stopped while
 *   a worker serves, announces its recycle and exits; once the master
 *   continues it reaps that exit before it reads the beacon, and still logs a
 *   recycle, never a crash. That worker — a replacement, forked while its
 *   slot's previous worker drained — is driven only once that one is gone and
 *   the master's go has reached it: before, it would hold its recycle.
 * - A worker that served and then exits 75 from a handler, without announcing
 *   a recycle, is a crash.
 */
return new Test(
   description: 'Recycling: an unannounced exit 75 is a crash, a recycle on any path is a recycle, and a recycle ends the crash streak',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('SUPERVISION_AUTOBOOT');

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Process\Events as Worker;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

// # Each boot records itself; while the counter is positive it exits 75
$counter = (string) getenv('SUPERVISION_COUNTER');
$boots = (string) getenv('SUPERVISION_BOOTS');
Emitter::$Instance->listen(Worker::Boot, static function () use ($counter, $boots): void {
   clearstatcache();
   $left = is_file($counter) ? (int) file_get_contents($counter) : 0;
   $PID = getmypid();
   if ($left > 0) {
      file_put_contents($counter, (string) ($left - 1));
      file_put_contents($boots, "{$PID}:died\n", FILE_APPEND);
      exit(75);
   }
   file_put_contents($boots, "{$PID}:ok\n", FILE_APPEND);
});

$Server = new HTTP_Server_CLI(Modes::Foreground);
$Server->configure(new HTTP_Server_CLI\Configs(
   host: '127.0.0.1',
   port: (int) getenv('SUPERVISION_PORT'),
   workers: 1,
   maxRequests: 1,
   jitter: 0.0,
));
$Server->on(Events::RequestReceived, static function (Request $Request, Response $Response): Response {
   // # Served, then gone with status 75 — no recycle announced
   if ($Request->URI === '/exit') {
      exit(75);
   }

   return $Response(body: (string) getmypid());
});
$Server->start();
PHP;

      // @ Reserve a free loopback port.
      $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }
      $counter = BOOTGLY_STORAGE_DIR . "recycle-supervision-{$port}.counter";
      $boots = BOOTGLY_STORAGE_DIR . "recycle-supervision-{$port}.boots";
      @unlink($counter);
      @unlink($boots);

      $Environment = (array) getenv();
      $Environment['SUPERVISION_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
      $Environment['SUPERVISION_PORT'] = (string) $port;
      $Environment['SUPERVISION_COUNTER'] = $counter;
      $Environment['SUPERVISION_BOOTS'] = $boots;
      $Process = proc_open(
         [PHP_BINARY, '-d', 'opcache.jit=0', '-r', $Script],
         [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
         $Pipes,
         BOOTGLY_ROOT_BASE,
         $Environment,
      );
      $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
      stream_set_blocking($Pipes[1], false);
      stream_set_blocking($Pipes[2], false);
      $output = '';
      $Read = static function () use ($Pipes, &$output): void {
         $output .= (string) stream_get_contents($Pipes[1]) . (string) stream_get_contents($Pipes[2]);
      };
      /**
       * The boots recorded so far.
       *
       * @return array<int,array{int,string}> [PID, 'ok'|'died'] per boot, in order.
       */
      $Boots = static function () use ($boots): array {
         clearstatcache();
         $Records = [];
         foreach (explode("\n", trim((string) @file_get_contents($boots))) as $line) {
            if ($line === '') {
               continue;
            }
            [$PID, $outcome] = explode(':', $line, 2);
            $Records[] = [(int) $PID, $outcome];
         }

         return $Records;
      };
      /** Wait until $count boots are recorded; the last one or null. */
      $Await = static function (int $count, float $seconds) use ($Boots, $Read): null|array {
         $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
         do {
            $Read();
            $Records = $Boots();
            if (count($Records) >= $count) {
               return $Records[$count - 1];
            }
            usleep(20_000);
         } while (hrtime(true) < $deadline);

         return null;
      };
      /** One request on a fresh connection; the answering worker PID or 0. */
      $Ask = static function (string $path = '/') use ($port, $Read): int {
         $Read();
         $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
         if ($Client === false) {
            return 0;
         }
         stream_set_timeout($Client, 8);
         @fwrite($Client, "GET {$path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
         $answer = (string) stream_get_contents($Client);
         fclose($Client);
         $body = substr($answer, (int) strpos($answer, "\r\n\r\n") + 4);

         return str_starts_with($answer, 'HTTP/1.1 200') ? (int) $body : 0;
      };

      $Observed = [];
      $clean = false;
      $racer = null;
      $exited = 0;
      $Records = [];
      try {
         // @ The first worker serves
         $first = $Await(1, 8.0);
         $Observed['the first worker booted'] = $first !== null && $first[1] === 'ok';

         // @ Four boots in a row exit 75: crashes, the last two backed off
         file_put_contents($counter, '4');
         if ($first !== null) {
            posix_kill($first[0], SIGKILL);
         }
         $fifth = $Await(6, 8.0);
         $Observed['four boot exits, then a worker serves'] = $fifth !== null
            && $fifth[1] === 'ok'
            && array_column(array_slice($Boots(), 1, 4), 1) === ['died', 'died', 'died', 'died'];

         // @ The worker that served recycles: its replacement boots and serves
         $served = $Ask();
         $replacement = $Await(7, 4.0);
         $Observed['the served worker answers and recycles'] = $fifth !== null && $served === $fifth[0]
            && $replacement !== null && $replacement[1] === 'ok';

         // @ Two boot exits after a recycle: reforked at once, the streak
         //   restarted — the replacement recycles first
         file_put_contents($counter, '2');
         $again = $Ask();
         $recovered = $Await(10, 4.0);
         $Observed['after a recycle, two boot exits and a worker serves'] = $replacement !== null
            && $again === $replacement[0]
            && $recovered !== null
            && $recovered[1] === 'ok'
            && array_column(array_slice($Boots(), 7, 2), 1) === ['died', 'died'];
         $Observed['and it answers'] = $recovered !== null && $Ask() === $recovered[0];

         // @ The master stopped while a worker serves, recycles and exits:
         //   once it continues, it reaps that exit before reading the beacon
         $racer = $Await(11, 4.0);
         // ! The racer was forked while its slot's previous worker drained: it
         //   may recycle once that one is gone and the go has reached it —
         //   read once a second — or it would hold its recycle through the stop
         $previous = $recovered[0] ?? 0;
         $deadline = hrtime(true) + 4_000_000_000;
         while ($previous > 0 && posix_kill($previous, 0) && hrtime(true) < $deadline) {
            usleep(20_000);
         }
         usleep(1_200_000);
         $raced = 0;
         if ($racer !== null && $master > 0) {
            posix_kill($master, SIGSTOP);
            $raced = $Ask();
            // ! Its exit lands while the master is stopped
            usleep(300_000);
            posix_kill($master, SIGCONT);
         }
         $after = $Await(12, 4.0);
         $Observed['a recycle reaped before its beacon was read'] = $racer !== null
            && $raced === $racer[0]
            && $after !== null
            && $after[1] === 'ok';

         // @ A worker that served exits 75 from a handler: no recycle announced
         $exited = 0;
         if ($after !== null) {
            $Ask('/exit');
            $exited = $after[0];
         }
         $last = $Await(13, 4.0);
         $Observed['after an exit 75 from a handler, a worker serves'] = $last !== null
            && $last[1] === 'ok'
            && $Ask() === $last[0];
         $Observed['master alive'] = is_resource($Process) && proc_get_status($Process)['running'];

         $settle = hrtime(true) + 500_000_000;
         while (hrtime(true) < $settle) {
            $Read();
            usleep(50_000);
         }
         // ! Before the file goes with the cleanup
         $Records = $Boots();
      }
      finally {
         // ! The whole session group; never PID 0/-1 (this runner's group).
         if ($master > 0) {
            posix_kill(-$master, SIGKILL);
         }
         $Read();
         foreach ($Pipes as $Pipe) {
            @fclose($Pipe);
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         $cleanup = hrtime(true) + 1_000_000_000;
         while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }
         $clean = $master > 0 && posix_kill(-$master, 0) === false;
         @unlink($counter);
         @unlink($boots);
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "HTTP_Server_CLI.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      }

      // ! Per worker PID, from the boots file: whichever path a recycle took,
      //   the verdict on each worker is the same
      $Logged = static function (int $PID, string $line) use ($output): bool {
         return str_contains($output, "(PID: {$PID}) {$line}");
      };
      $Recycled = static fn (int $PID): bool => $Logged($PID, 'recycling, forking its replacement')
         || $Logged($PID, 'recycled, reforking');
      $Died = [];
      foreach ($Records as [$PID, $outcome]) {
         if ($outcome === 'died') {
            $Died[] = $PID;
         }
      }
      $first = $Records[0][0] ?? 0;
      $streaked = array_slice($Died, 2, 2);
      $Observed['the killed worker and the first two boot exits crash at once'] = $first > 0
         && $Logged($first, 'crashed, reforking')
         && count($Died) === 6
         && $Logged($Died[0], 'crashed, reforking')
         && $Logged($Died[1], 'crashed, reforking');
      $Observed['the boot streak backed off at 3 and 4'] = count($streaked) === 2
         && $Logged($streaked[0], 'died before serving 3 times in a row')
         && $Logged($streaked[1], 'died before serving 4 times in a row');
      $Observed['the two boot exits after a recycle crash at once'] = count($Died) === 6
         && $Logged($Died[4], 'crashed, reforking')
         && $Logged($Died[5], 'crashed, reforking');
      $Observed['the streak never outlived a recycle'] = substr_count($output, 'times in a row') === 2;
      $Observed['no boot exit taken for a recycle'] = count($Died) === 6
         && array_filter(
            $Died,
            static fn (int $PID): bool => $Logged($PID, 'recycl')
         ) === [];
      $Observed['every worker that served recycled'] = count($Records) >= 11
         && $Recycled($Records[5][0])
         && $Recycled($Records[6][0])
         && $Recycled($Records[9][0]);
      $Observed['the raced recycle is logged as one, never as a crash'] = $racer !== null
         && $Recycled($racer[0])
         && $Logged($racer[0], 'crashed') === false;
      $Observed['the exit 75 from a handler is a crash, never a recycle'] = $exited > 0
         && $Logged($exited, 'crashed, reforking')
         && $Logged($exited, 'recycl') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'an exit 75 at boot or from a handler is a crash; a recycle — reaped before its beacon is read or not — is a recycle, and restarts the slot\'s streak')
         ->expect($Observed, Op::Identical, [
            'the first worker booted' => true,
            'four boot exits, then a worker serves' => true,
            'the served worker answers and recycles' => true,
            'after a recycle, two boot exits and a worker serves' => true,
            'and it answers' => true,
            'a recycle reaped before its beacon was read' => true,
            'after an exit 75 from a handler, a worker serves' => true,
            'master alive' => true,
            'the killed worker and the first two boot exits crash at once' => true,
            'the boot streak backed off at 3 and 4' => true,
            'the two boot exits after a recycle crash at once' => true,
            'the streak never outlived a recycle' => true,
            'no boot exit taken for a recycle' => true,
            'every worker that served recycled' => true,
            'the raced recycle is logged as one, never as a crash' => true,
            'the exit 75 from a handler is a crash, never a recycle' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
