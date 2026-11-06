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
 * Recycling never multiplies the processes of a server, live, on one-worker
 * Foreground HTTP servers (`jitter: 0`):
 *
 * - One retiree per slot. A client that opens a connection it never speaks
 *   on before every recycle pins the first retiree; its replacement passes
 *   its limit while that retiree still drains and holds its recycle — it
 *   answers every later request, still accepting, and never recycles, nothing
 *   is killed — so across twelve rounds the server session never holds more
 *   than 2 × workers + 1 processes (the master, the serving worker, one
 *   retiree). The replacement forks as soon as the recycle is announced — the
 *   Foreground master wakes on the beacon. The published state lists the
 *   retiree apart from the serving worker. Once the pinned retiree is gone
 *   the master's go lets the replacement recycle, within a second or so, and
 *   the state no longer lists the one that left; a stop counts the new
 *   retiree and ends it with the rest.
 * - A deadline per retiree. A retiree whose handler blocks — so its own
 *   drain timer never fires — is killed by the master `drainTimeout` + 5 s
 *   after it began to recycle (`drainTimeout: 3` here: 8 s); its replacement,
 *   past its limit meanwhile, recycles right after that kill — never before.
 * - A memory ceiling no worker can honor is refused at a first `start()`:
 *   one not above the memory a worker starts with, or one not below
 *   `memory_limit` — the server exits 1 and forks no worker. A reload whose
 *   fresh image could not honor it keeps serving with the ceiling off, and
 *   says so. A worker its own boot took past the ceiling serves on with its
 *   memory ceiling off, and says so: no fork per dispatch. One born so while
 *   its slot's previous worker drained has nothing to recycle by: the
 *   master's go, once that worker is gone, changes nothing.
 */
return new Test(
   description: 'Recycling: one retiree per slot (the next recycle holds), a deadline per retiree, and a memory ceiling no worker can honor refused',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false
      || is_dir('/proc/self') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
// ! A reload re-runs this script in the same process: the session is ours already
if (posix_getsid(0) !== getmypid()) {
   $Session = posix_setsid();
   if ($Session === false || $Session === -1) {
      exit(125);
   }
}

// ! An embedded fixture, not a Bootgly CLI script — when it runs from a file
$_SERVER['SCRIPT_FILENAME'] = '';
require getenv('BOUND_AUTOBOOT');

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Process\Events as Worker;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

TCP_Server_CLI::$drainTimeout = (int) getenv('BOUND_DRAIN');

// # A worker whose boot keeps memory — every one, or once the flag file exists
$born = (int) getenv('BOUND_BORN');
$heavy = (string) getenv('BOUND_HEAVY');
if ($born > 0) {
   Emitter::$Instance->listen(Worker::Boot, static function () use ($born, $heavy): void {
      clearstatcache();
      if ($heavy === '' || is_file($heavy)) {
         $GLOBALS['BOUND_KEPT'] = str_repeat('k', $born);
      }
   });
}
// # A master image that boots heavy, once the flag file exists (a reload)
$ballast = (string) getenv('BOUND_BALLAST');
if ($ballast !== '' && is_file($ballast)) {
   $GLOBALS['BOUND_BALLAST'] = str_repeat('b', 48 * 1024 * 1024);
}
$memory = (string) getenv('BOUND_MEMORY');

$Server = new HTTP_Server_CLI(Modes::Foreground);
$Server->configure(new HTTP_Server_CLI\Configs(
   host: '127.0.0.1',
   port: (int) getenv('BOUND_PORT'),
   workers: 1,
   maxRequests: (int) getenv('BOUND_MAX'),
   jitter: 0.0,
   maxMemory: is_numeric($memory) ? (int) $memory : $memory,
));
$Server->on(Events::RequestReceived, static function (Request $Request, Response $Response): Response {
   // # Kept for the worker's life: its real memory only grows
   if ($Request->URI === '/grow') {
      $GLOBALS['BOUND_GROWN'][] = str_repeat('g', 8 * 1024 * 1024);
   }
   // # Blocks its worker for thirty seconds: no loop turn, no drain tick
   if ($Request->URI === '/block') {
      $until = hrtime(true) + 30_000_000_000;
      while (hrtime(true) < $until) {
         usleep(50_000);
      }
   }

   return $Response(body: (string) getmypid());
});
$Server->start();
PHP;

      /**
       * Boot one server; run $Drive against it; return what it saw, the
       * server log, its exit code (null while it ran) and whether its session
       * group was left clean.
       *
       * @param array<string,string> $Options
       *
       * @return array{0:array<string,mixed>,1:string,2:null|int,3:bool}
       */
      $Run = static function (array $Options, Closure $Drive, array $INI = []) use ($Script): array {
         // @ Reserve a free loopback port.
         $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }

         $Environment = (array) getenv();
         $Environment['BOUND_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['BOUND_PORT'] = (string) $port;
         $Environment['BOUND_MAX'] = $Options['max'] ?? '0';
         $Environment['BOUND_DRAIN'] = $Options['drain'] ?? '30';
         $Environment['BOUND_MEMORY'] = $Options['memory'] ?? '0';
         $Environment['BOUND_BORN'] = $Options['born'] ?? '0';
         $Environment['BOUND_HEAVY'] = $Options['heavy'] ?? '';
         $Environment['BOUND_BALLAST'] = $Options['ballast'] ?? '';
         $Command = [PHP_BINARY, '-d', 'opcache.jit=0'];
         foreach ($INI as $directive) {
            $Command[] = '-d';
            $Command[] = $directive;
         }
         // ! A server that reloads runs from a file: the reload re-executes
         //   its entry script, which `-r` code has none of
         $file = '';
         if (isSet($Options['ballast'])) {
            $file = BOOTGLY_STORAGE_DIR . "recycle-bound-{$port}.php";
            file_put_contents($file, "<?php\n{$Script}\n");
            $Command[] = $file;
         }
         else {
            $Command[] = '-r';
            $Command[] = $Script;
         }
         $Process = proc_open(
            $Command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Environment,
         );
         $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
         stream_set_blocking($Pipes[1], false);
         stream_set_blocking($Pipes[2], false);
         $output = '';
         $Read = static function () use ($Pipes, &$output): string {
            $output .= (string) stream_get_contents($Pipes[1]) . (string) stream_get_contents($Pipes[2]);

            return $output;
         };

         $Observed = [];
         $code = null;
         $clean = false;
         try {
            $Observed = $Drive($port, $master, $Read, $Process);
            // ! An exit the drive saw carries its code: proc_get_status()
            //   reports it once only
            if (array_key_exists('exit', $Observed)) {
               $code = $Observed['exit'];
               unset($Observed['exit']);
            }
            else {
               $Observed['master alive'] = is_resource($Process) && proc_get_status($Process)['running'];
            }
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
            foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $entry) {
               if (str_starts_with((string) $entry, "HTTP_Server_CLI.{$port}.")) {
                  @unlink(BOOTGLY_STORAGE_DIR . "pids/{$entry}");
               }
            }
            if ($file !== '') {
               @unlink($file);
            }
         }

         return [$Observed, $output, $code, $clean];
      };
      /** Wait until the server accepts; whether it did. */
      $Up = static function (int $port, Closure $Read): bool {
         $deadline = hrtime(true) + 8_000_000_000;
         do {
            $Read();
            $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 0.2);
            if ($Client !== false) {
               fclose($Client);

               return true;
            }
            usleep(50_000);
         } while (hrtime(true) < $deadline);

         return false;
      };
      /**
       * The processes in the server's session (its master leads it) — the
       * unreaped ones included, unless `$live`: a zombie still holds its PID.
       */
      $Count = static function (int $session, bool $live = false): int {
         $count = 0;
         foreach ((array) glob('/proc/[0-9]*/stat') as $file) {
            $stat = @file_get_contents((string) $file);
            if (is_string($stat) === false) {
               continue;
            }
            // ! The fields after the command name, which may hold spaces
            $Fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
            if ((int) ($Fields[3] ?? 0) === $session && ($live === false || ($Fields[0] ?? '') !== 'Z')) {
               $count++;
            }
         }

         return $count;
      };
      /** One request on a fresh connection; the answering worker PID or 0. */
      $Ask = static function (int $port, string $path = '/'): int {
         $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
         if ($Client === false) {
            return 0;
         }
         stream_set_timeout($Client, 6);
         @fwrite($Client, "GET {$path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
         $answer = (string) stream_get_contents($Client);
         fclose($Client);

         return str_starts_with($answer, 'HTTP/1.1 200')
            ? (int) substr($answer, (int) strpos($answer, "\r\n\r\n") + 4)
            : 0;
      };
      /** Wait until the server log holds $needle; whether it did. */
      $Expect = static function (Closure $Read, string $needle, float $seconds): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
         do {
            if (str_contains($Read(), $needle)) {
               return true;
            }
            usleep(10_000);
         } while (hrtime(true) < $deadline);

         return false;
      };
      /**
       * The published state of the server on `$port`.
       *
       * @return array{0:array<int,int>,1:array<int,int>} [workers, retirees]
       */
      $State = static function (int $port): array {
         $State = json_decode((string) @file_get_contents(BOOTGLY_STORAGE_DIR . "pids/HTTP_Server_CLI.{$port}.json"), true);
         $Workers = is_array($State) && is_array($State['workers'] ?? null) ? array_values($State['workers']) : [];
         $Retirees = is_array($State) && is_array($State['retirees'] ?? null) ? array_values($State['retirees']) : [];

         return [$Workers, $Retirees];
      };

      // @ One retiree per slot — the first retiree pinned by a silent connection
      [$Observed, $output, , $clean] = $Run(['max' => '3'], static function (int $port, int $master, Closure $Read) use ($Up, $Count, $Ask, $Expect, $State): array {
         $up = $Up($port, $Read);
         $Silent = [];
         $PIDs = [];
         $peak = 0;
         $answered = 0;
         $asked = 0;
         $latency = null;
         for ($round = 0; $up && $round < 12; $round++) {
            // # A connection the current worker accepts and nobody speaks on
            $Silent[] = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
            usleep(30_000);
            for ($request = 0; $request < 3; $request++) {
               $PID = $Ask($port);
               $asked++;
               if ($PID > 0) {
                  $answered++;
                  $PIDs[$PID] = ($PIDs[$PID] ?? 0) + 1;
               }
               $peak = max($peak, $Count($master));
            }
            // @@ The first recycle: its replacement forks at once — the
            //    master wakes on the beacon
            if ($round === 0) {
               $triggered = hrtime(true);
               $Expect($Read, 'recycling, forking its replacement', 2.0);
               $latency = round((hrtime(true) - $triggered) / 1_000_000_000, 3);
            }
            $settle = hrtime(true) + 50_000_000;
            while (hrtime(true) < $settle) {
               $peak = max($peak, $Count($master));
               usleep(5_000);
            }
         }
         $log = $Read();
         [$first, $second] = array_pad(array_keys($PIDs), 2, 0);
         // # The published state lists the retiree apart from the serving worker
         [$Workers, $Retirees] = $State($port);
         $listed = $Workers === [$second] && $Retirees === [$first] && posix_kill($first, 0);
         $held = substr_count($log, 'recycling, forking its replacement') === 1
            && str_contains($log, "Worker (PID: {$second}) recycling:") === false;

         // # The pinned retiree is let go: its replacement recycles now
         $released = 0.0;
         if (is_resource($Silent[0] ?? null)) {
            fclose($Silent[0]);
         }
         $gone = $Expect($Read, "(PID: {$first}) recycled.", 4.0);
         $left = hrtime(true);
         $recycled = $Expect($Read, "(PID: {$second}) recycling, forking its replacement", 4.0);
         $released = round((hrtime(true) - $left) / 1_000_000_000, 3);
         $peak = max($peak, $Count($master));
         // # The state follows: the one that left is no longer listed (the
         //   master saves it right after the fork it just logged)
         $followed = false;
         $deadline = hrtime(true) + 2_000_000_000;
         while ($followed === false && hrtime(true) < $deadline) {
            [$Workers, $Retirees] = $State($port);
            $followed = $Retirees === [$second] && in_array($first, $Workers, true) === false && count($Workers) === 1;
            usleep(10_000);
         }

         // # A stop ends the serving worker and the retiree alike
         posix_kill($master, SIGTERM);
         $deadline = hrtime(true) + 8_000_000_000;
         while ($Count($master, live: true) > 0 && hrtime(true) < $deadline) {
            usleep(20_000);
         }
         $stopped = $Count($master, live: true) === 0;
         $Read();
         foreach ($Silent as $Client) {
            if (is_resource($Client)) {
               fclose($Client);
            }
         }

         return [
            'server up' => $up,
            'every request answered' => $asked === 36 && $answered === 36,
            'the first worker recycled at its limit (3 answers)' => ($PIDs[$first] ?? 0) === 3,
            'its replacement answered every later request — past its limit, still accepting' => ($PIDs[$second] ?? 0) === 33 && count($PIDs) === 2,
            'the replacement forked as soon as the recycle was announced' => $latency !== null && $latency < 0.25,
            'the replacement held its recycle while the retiree drained' => $held,
            'the state lists the retiree apart' => $listed,
            'once the retiree is gone, the replacement recycles' => $gone && $recycled && $released < 2.5,
            'the state no longer lists the retiree that left' => $followed,
            'a stop ends the worker and the retiree' => $stopped,
            'second' => $second,
            'peak' => $peak,
         ];
      });
      $peak = $Observed['peak'];
      $second = $Observed['second'];
      unset($Observed['peak'], $Observed['second']);
      $Observed['the session never held more than 2 × workers + 1 processes'] = $peak > 0 && $peak <= 3;
      $Observed['it says it held its recycle'] = str_contains($output, "Worker (PID: {$second}) held its recycle while its slot's previous worker drained");
      $Observed['no worker killed'] = str_contains($output, 'killed') === false;
      $Observed['the stop counts the retiree'] = str_contains($output, 'Stopping 1 worker(s) and 1 retiring')
         && str_contains($output, '1 worker(s) and 1 retiring stopped!');
      $Observed['no crash logged'] = str_contains($output, 'crashed') === false && str_contains($output, 'Uncaught') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: "one retiree per slot: a pinned retiree holds its slot's next recycle — twelve rounds never take a one-worker server past 3 processes (peak {$peak})")
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'every request answered' => true,
            'the first worker recycled at its limit (3 answers)' => true,
            'its replacement answered every later request — past its limit, still accepting' => true,
            'the replacement forked as soon as the recycle was announced' => true,
            'the replacement held its recycle while the retiree drained' => true,
            'the state lists the retiree apart' => true,
            'once the retiree is gone, the replacement recycles' => true,
            'the state no longer lists the retiree that left' => true,
            'a stop ends the worker and the retiree' => true,
            'master alive' => false,
            'the session never held more than 2 × workers + 1 processes' => true,
            'it says it held its recycle' => true,
            'no worker killed' => true,
            'the stop counts the retiree' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ The deadline — a retiree whose handler blocks; then the go
      [$Observed, $output, , $clean] = $Run(['max' => '2', 'drain' => '3'], static function (int $port, int $master, Closure $Read) use ($Up, $Ask, $Expect): array {
         $up = $Up($port, $Read);
         // # Request 1, then a connection the worker accepts silently
         $old = $Ask($port);
         $Silent = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
         usleep(100_000);
         // # Request 2: the recycle
         $trigger = $Ask($port);
         $triggered = hrtime(true);
         // # The silent connection's request blocks the retiree
         if ($Silent !== false) {
            @fwrite($Silent, "GET /block HTTP/1.1\r\nHost: localhost\r\n\r\n");
            stream_set_blocking($Silent, false);
         }
         // # Its replacement: its limit, then more — held while the retiree lives
         $Replacement = [];
         for ($request = 0; $request < 4; $request++) {
            $Replacement[] = $Ask($port);
         }
         $new = $Replacement[0];
         $holding = str_contains($Read(), "Worker (PID: {$new}) recycling:") === false;
         // @@ The kill
         $killed = null;
         if ($Silent !== false) {
            $deadline = hrtime(true) + 14_000_000_000;
            while (hrtime(true) < $deadline) {
               $chunk = @fread($Silent, 4096);
               if (($chunk === '' || $chunk === false) && feof($Silent)) {
                  $killed = (hrtime(true) - $triggered) / 1_000_000_000;
                  break;
               }
               usleep(20_000);
            }
            fclose($Silent);
         }
         // # The go: the replacement recycles right after the kill
         $after = hrtime(true);
         $released = $Expect($Read, "(PID: {$new}) recycling, forking its replacement", 4.0);
         $go = round((hrtime(true) - $after) / 1_000_000_000, 3);
         $fresh = $Ask($port);
         $log = $Read();
         $kill = strpos($log, "(PID: {$old}) killed, still draining past its deadline");
         $hold = strpos($log, "Worker (PID: {$new}) held its recycle");

         return [
            'server up' => $up,
            'the second request recycles the first worker' => $old > 0 && $trigger === $old,
            'its replacement answers past its limit while the retiree is blocked (4 of 4)' => $new > 0 && $new !== $old && $Replacement === array_fill(0, 4, $new),
            'and holds its recycle' => $holding,
            'the blocked retiree is killed at its deadline (7.5 to 9.5 s)' => $killed !== null && $killed > 7.5 && $killed < 9.5,
            'it is gone' => $old > 0 && posix_kill($old, 0) === false,
            'the replacement recycles right after the kill — never before' => $released && $go < 2.5
               && $kill !== false && $hold !== false && $kill < $hold,
            'a new worker serves' => $fresh > 0 && $fresh !== $old && $fresh !== $new,
            'old' => $old,
            'killed' => $killed,
         ];
      });
      $old = $Observed['old'];
      $killed = $Observed['killed'];
      unset($Observed['old'], $Observed['killed']);
      $Observed['the kill is logged with its reason'] = str_contains($output, "(PID: {$old}) killed, still draining past its deadline (drainTimeout + 5 s)");
      $Observed['no crash logged'] = str_contains($output, 'crashed') === false && str_contains($output, 'Uncaught') === false;
      $Observed['group clean'] = $clean;
      $seconds = json_encode($killed);

      yield new Assertion(description: "a deadline per retiree: one whose handler blocks is killed drainTimeout + 5 s after it began to recycle ({$seconds} s), and its slot's worker recycles right after")
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'the second request recycles the first worker' => true,
            'its replacement answers past its limit while the retiree is blocked (4 of 4)' => true,
            'and holds its recycle' => true,
            'the blocked retiree is killed at its deadline (7.5 to 9.5 s)' => true,
            'it is gone' => true,
            'the replacement recycles right after the kill — never before' => true,
            'a new worker serves' => true,
            'master alive' => true,
            'the kill is logged with its reason' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ A memory ceiling no worker can honor is refused at a first start()
      $Exit = static function (int $port, int $master, Closure $Read, mixed $Process): array {
         // @@ It refuses before it forks
         $code = null;
         $deadline = hrtime(true) + 8_000_000_000;
         while (is_resource($Process) && hrtime(true) < $deadline) {
            $Status = proc_get_status($Process);
            if ($Status['running'] === false) {
               $code = $Status['exitcode'];
               break;
            }
            usleep(20_000);
         }
         $Read();

         return ['exit' => $code];
      };
      [, $low, $lowCode] = $Run(['memory' => '1'], $Exit);
      [, $high, $highCode] = $Run(['memory' => '128M'], $Exit, ['memory_limit=64M']);
      $Refusals = [
         'not above what a worker starts with: exit 1' => $lowCode === 1,
         'said so' => str_contains($low, 'Server not started: maxMemory (1 bytes) is not above the'),
         'no worker forked' => str_contains($low, 'Forking') === false,
         'not below memory_limit: exit 1' => $highCode === 1,
         'said so too' => str_contains($high, 'Server not started: maxMemory (134217728 bytes) is not below memory_limit 64M'),
         'no worker forked either' => str_contains($high, 'Forking') === false,
      ];

      yield new Assertion(description: 'start() refuses a maxMemory no worker can honor, before any fork')
         ->expect($Refusals, Op::Identical, array_fill_keys(array_keys($Refusals), true))
         ->assert();

      // @ A reload whose fresh image could not honor it: the ceiling off, still serving
      $runner = getmypid();
      $flag = BOOTGLY_STORAGE_DIR . "recycle-bound-{$runner}.ballast";
      @unlink($flag);
      [$Observed, $output, , $clean] = $Run(
         ['memory' => '32M', 'ballast' => $flag],
         static function (int $port, int $master, Closure $Read) use ($Up, $Ask, $Expect, $flag): array {
            $up = $Up($port, $Read);
            $before = $up ? $Ask($port) : 0;
            // # The next master image boots past the ceiling
            touch($flag);
            posix_kill($master, SIGUSR2);
            $reloaded = $Expect($Read, 'Reloaded without a memory ceiling: maxMemory (33554432 bytes) is not above the', 10.0);
            $after = 0;
            $deadline = hrtime(true) + 8_000_000_000;
            while ($after === 0 && hrtime(true) < $deadline) {
               $after = $Ask($port);
               usleep(50_000);
            }
            $Read();

            return [
               'server up' => $up,
               'a worker served before the reload' => $before > 0,
               'the fresh image says its ceiling is off' => $reloaded,
               'a new worker serves after the reload' => $after > 0 && $after !== $before,
               'the master kept its PID' => posix_kill($master, 0),
            ];
         }
      );
      @unlink($flag);
      $Observed['never refused'] = str_contains($output, 'Server not started') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'a reload whose fresh image cannot honor maxMemory keeps serving, with the ceiling off')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'a worker served before the reload' => true,
            'the fresh image says its ceiling is off' => true,
            'a new worker serves after the reload' => true,
            'the master kept its PID' => true,
            'master alive' => true,
            'never refused' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ A worker its own boot took past the ceiling: memory ceiling off in it
      [$Observed, $output, , $clean] = $Run(
         ['memory' => '48M', 'born' => (string) (64 * 1024 * 1024)],
         static function (int $port, int $master, Closure $Read) use ($Up, $Ask): array {
            $up = $Up($port, $Read);
            $PIDs = [];
            for ($request = 0; $up && $request < 4; $request++) {
               $PIDs[] = $Ask($port);
            }
            $Read();

            return [
               'server up' => $up,
               'one worker answers every request' => count($PIDs) === 4 && $PIDs[0] > 0 && array_unique($PIDs) === [$PIDs[0]],
            ];
         }
      );
      $Observed['it says why it will not recycle by memory'] = str_contains($output, 'not under maxMemory')
         && str_contains($output, 'it will not recycle by memory');
      $Observed['no recycle'] = str_contains($output, 'recycling') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'a worker born past maxMemory serves on with its memory ceiling off: no fork per dispatch')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'one worker answers every request' => true,
            'master alive' => true,
            'it says why it will not recycle by memory' => true,
            'no recycle' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ A replacement born past the ceiling while its slot's previous
      //   worker drains: nothing to recycle by — the go changes nothing
      $heavy = BOOTGLY_STORAGE_DIR . "recycle-bound-{$runner}.heavy";
      @unlink($heavy);
      [$Observed, $output, , $clean] = $Run(
         ['memory' => '24M', 'born' => (string) (32 * 1024 * 1024), 'heavy' => $heavy],
         static function (int $port, int $master, Closure $Read) use ($Up, $Ask, $Expect, $heavy): array {
            $up = $Up($port, $Read);
            // # A connection the worker accepts and nobody speaks on: it pins
            //   the worker once it recycles
            $Silent = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
            usleep(100_000);
            // # The next boots are heavy; the worker grows past its ceiling
            touch($heavy);
            $old = 0;
            for ($request = 0; $request < 4 && str_contains($Read(), 'recycling, forking its replacement') === false; $request++) {
               $old = $Ask($port, '/grow') ?: $old;
               usleep(50_000);
            }
            $recycled = $Expect($Read, "(PID: {$old}) recycling, forking its replacement", 2.0);
            $new = $Ask($port);
            $born = $Expect($Read, 'not under maxMemory', 2.0);
            // # The old worker goes: its slot's worker gets the go
            if (is_resource($Silent)) {
               fclose($Silent);
            }
            $gone = $Expect($Read, "(PID: {$old}) recycled.", 4.0);
            $settle = hrtime(true) + 2_500_000_000;
            while (hrtime(true) < $settle) {
               $Read();
               usleep(50_000);
            }
            $after = $Ask($port);
            $log = $Read();

            return [
               'server up' => $up,
               'the first worker recycled by memory' => $old > 0 && $recycled,
               'its replacement was born past the ceiling, and says so' => $new > 0 && $new !== $old && $born,
               'the old worker went' => $gone,
               'the go recycles nothing: the replacement serves on' => $after === $new
                  && str_contains($log, "Worker (PID: {$new}) held its recycle") === false
                  && str_contains($log, "Worker (PID: {$new}) recycling:") === false,
            ];
         }
      );
      @unlink($heavy);
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'a replacement born past maxMemory while its slot\'s previous worker drains has nothing to recycle by: the go changes nothing')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'the first worker recycled by memory' => true,
            'its replacement was born past the ceiling, and says so' => true,
            'the old worker went' => true,
            'the go recycles nothing: the replacement serves on' => true,
            'master alive' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
