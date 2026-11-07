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
 * A slot keeps one draining worker at most, and that worker keeps its whole
 * drain — live, on Foreground HTTP servers (`maxRequests: 3`, `jitter: 0`):
 *
 * - One worker. A slow client downloads 4 MiB from worker A while two more
 *   requests recycle A: A drains the download. Its replacement B is forked
 *   gated: past its own limit while A still drains, it serves on — six more
 *   requests, all answered by B, the listener still accepting — and never
 *   recycles. The download completes, whole. Once A is gone, the master's go
 *   lets B recycle: it says it held its recycle, and a new worker serves. No
 *   process is killed, the session never holds more than the master and two
 *   workers, and the persisted state lists no retiree once they are gone.
 * - Two workers. A download pins slot X's retiree; slot Y recycles again and
 *   again meanwhile — its own retirees drain at once, and each go reaches
 *   its own slot only — while slot X's replacement, past its limit, holds
 *   its recycle until X is gone. A crash refork of slot Y, forked with no
 *   retiree in its own slot, recycles at its limit: another slot's retiree
 *   never gates it. The session never holds more than 2 × workers + 1
 *   processes.
 */
return new Test(
   description: 'Recycling: a replacement past its limit while its slot\'s previous worker drains holds its recycle until the go — per slot',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false
      || is_dir('/proc/self') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('GATE_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

$Server = new HTTP_Server_CLI(Modes::Foreground);
$Server->configure(new HTTP_Server_CLI\Configs(
   host: '127.0.0.1',
   port: (int) getenv('GATE_PORT'),
   workers: (int) getenv('GATE_WORKERS'),
   maxRequests: 3,
   jitter: 0.0,
));
$Server->on(Events::RequestReceived, static function (Request $Request, Response $Response): Response {
   $PID = getmypid();
   // # A download its client reads slowly: it keeps its worker draining
   if ($Request->URI === '/big') {
      $padding = str_repeat('x', 4 * 1024 * 1024);

      return $Response(body: "{$PID}:{$padding}");
   }

   return $Response(body: (string) $PID);
});
$Server->start();
PHP;

      /**
       * Boot one server; run $Drive against it; return what it saw, the
       * server log and whether its session group was left clean.
       *
       * @return array{0:array<string,mixed>,1:string,2:bool}
       */
      $Run = static function (int $workers, Closure $Drive) use ($Script): array {
         // @ Reserve a free loopback port.
         $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }

         $Environment = (array) getenv();
         $Environment['GATE_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['GATE_PORT'] = (string) $port;
         $Environment['GATE_WORKERS'] = (string) $workers;
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
         $Read = static function () use ($Pipes, &$output): string {
            $output .= (string) stream_get_contents($Pipes[1]) . (string) stream_get_contents($Pipes[2]);

            return $output;
         };

         $Observed = [];
         $clean = false;
         try {
            $Observed = $Drive($port, $master, $Read);
            $Observed['master alive'] = is_resource($Process) && proc_get_status($Process)['running'];
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
            foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
               if (str_starts_with((string) $file, "HTTP_Server_CLI.{$port}.")) {
                  @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
               }
            }
         }

         return [$Observed, $output, $clean];
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
      /** The processes in the server's session (its master leads it), zombies included. */
      $Count = static function (int $session): int {
         $count = 0;
         foreach ((array) glob('/proc/[0-9]*/stat') as $file) {
            $stat = @file_get_contents((string) $file);
            if (is_string($stat) === false) {
               continue;
            }
            // ! The fields after the command name, which may hold spaces
            $Fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
            if ((int) ($Fields[3] ?? 0) === $session) {
               $count++;
            }
         }

         return $count;
      };
      /** One request on a fresh connection; the answering worker PID or 0. */
      $Ask = static function (int $port): int {
         $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
         if ($Client === false) {
            return 0;
         }
         stream_set_timeout($Client, 6);
         @fwrite($Client, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
         $answer = (string) stream_get_contents($Client);
         fclose($Client);

         return str_starts_with($answer, 'HTTP/1.1 200')
            ? (int) substr($answer, (int) strpos($answer, "\r\n\r\n") + 4)
            : 0;
      };
      /**
       * Start the 4 MiB download on a fresh connection and read only its
       * first bytes: the rest stays pending on the worker that answers it.
       *
       * @return array{0:mixed,1:int,2:int} [the connection, worker PID, bytes read]
       */
      $Download = static function (int $port): array {
         $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
         if ($Client === false) {
            return [false, 0, 0];
         }
         // ! A small receive buffer: the download outgrows what the sockets
         //   can hold, so its worker keeps it pending
         if (function_exists('socket_import_stream')) {
            $Socket = socket_import_stream($Client);
            if ($Socket !== false) {
               @socket_set_option($Socket, SOL_SOCKET, SO_RCVBUF, 32_768);
            }
         }
         @fwrite($Client, "GET /big HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
         stream_set_timeout($Client, 6);
         $read = '';
         while (str_contains($read, "\r\n\r\n") === false || strlen($read) < (int) strpos($read, "\r\n\r\n") + 16) {
            $chunk = @fread($Client, 8_192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $read .= $chunk;
         }
         $body = substr($read, (int) strpos($read, "\r\n\r\n") + 4);

         return [$Client, (int) $body, strlen($read)];
      };
      /**
       * Read the rest of a download, slowly; the bytes read in all.
       *
       * @param resource $Client
       */
      $Finish = static function ($Client, int $read): int {
         stream_set_timeout($Client, 10);
         $deadline = hrtime(true) + 20_000_000_000;
         while (feof($Client) === false && hrtime(true) < $deadline) {
            $chunk = @fread($Client, 65_536);
            if ($chunk === false || $chunk === '') {
               continue;
            }
            $read += strlen($chunk);
            usleep(2_000);
         }
         fclose($Client);

         return $read;
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
       * Wait until the server log holds a match of $pattern AFTER $marker; its
       * first captured PID, or 0. A slot's earlier recoveries never match.
       */
      $Await = static function (Closure $Read, string $marker, string $pattern, float $seconds): int {
         $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
         do {
            $log = $Read();
            $at = strpos($log, $marker);
            if ($at !== false && preg_match($pattern, substr($log, $at), $Match) === 1) {
               return (int) $Match[1];
            }
            usleep(10_000);
         } while (hrtime(true) < $deadline);

         return 0;
      };
      // ! The download's head is under 200 bytes
      $whole = 4 * 1024 * 1024;

      // @ One worker — the download in flight on the old worker; its
      //   replacement holds its recycle
      [$Observed, $output, $clean] = $Run(1, static function (int $port, int $master, Closure $Read) use ($Up, $Count, $Ask, $Download, $Finish, $Expect, $whole): array {
         $up = $Up($port, $Read);
         $peak = 0;

         // # Request 1: the download, read slowly
         [$Big, $A, $read] = $up ? $Download($port) : [false, 0, 0];
         // # Requests 2 and 3: worker A recycles while the download is in flight
         $Recycles = [$Ask($port), $Ask($port)];
         $relieved = $Expect($Read, "(PID: {$A}) recycling, forking its replacement", 2.0);
         // # The replacement: its limit, then three more — held, still accepting
         $Held = [];
         for ($request = 0; $request < 6; $request++) {
            $Held[] = $Ask($port);
            $peak = max($peak, $Count($master));
         }
         $B = $Held[0];
         $settle = hrtime(true) + 300_000_000;
         while (hrtime(true) < $settle) {
            $peak = max($peak, $Count($master));
            usleep(10_000);
         }
         $draining = $A > 0 && posix_kill($A, 0);
         $holding = str_contains($Read(), "Worker (PID: {$B}) recycling:") === false;

         // # The download completes
         $bytes = $Big !== false ? $Finish($Big, $read) : 0;

         // # A is gone: B recycles now — then its own retiree goes too
         $gone = $Expect($Read, "(PID: {$A}) recycled.", 4.0);
         $released = $Expect($Read, "(PID: {$B}) recycling, forking its replacement", 4.0);
         $peak = max($peak, $Count($master));
         $fresh = $Ask($port);
         $settled = $Expect($Read, "(PID: {$B}) recycled.", 4.0);
         // ! The master saves the state right after the reap it just logged
         $Retirees = null;
         $Workers = [];
         $deadline = hrtime(true) + 2_000_000_000;
         do {
            $State = json_decode((string) @file_get_contents(BOOTGLY_STORAGE_DIR . "pids/HTTP_Server_CLI.{$port}.json"), true);
            $Retirees = is_array($State) && is_array($State['retirees'] ?? null) ? $State['retirees'] : null;
            $Workers = is_array($State) && is_array($State['workers'] ?? null) ? array_values($State['workers']) : [];
            usleep(10_000);
         } while (($Retirees !== [] || $Workers !== [$fresh]) && hrtime(true) < $deadline);
         $log = $Read();
         $done = strpos($log, "(PID: {$A}) recycled.");
         $go = strpos($log, "Worker (PID: {$B}) held its recycle while its slot's previous worker drained");

         return [
            'server up' => $up,
            'the download started on worker A' => $A > 0,
            'requests 2 and 3 recycle it' => $Recycles === [$A, $A] && $relieved,
            'its replacement answers past its limit, still accepting (6 of 6)' => $B > 0 && $B !== $A && $Held === array_fill(0, 6, $B),
            'A still drains the download meanwhile' => $draining,
            'B holds its recycle while A drains' => $holding,
            'the download completes, whole' => $bytes >= $whole,
            'A ends its drain: recycled' => $gone,
            'then B recycles, saying it held it' => $released && $done !== false && $go !== false && $done < $go,
            'a new worker serves after it' => $fresh > 0 && $fresh !== $A && $fresh !== $B,
            'the state lists no retiree once they are gone' => $settled && $Retirees === [] && $Workers === [$fresh],
            'peak' => $peak,
         ];
      });
      $peak = $Observed['peak'];
      unset($Observed['peak']);
      $Observed['the session never held more than the master and two workers'] = $peak > 0 && $peak <= 3;
      $Observed['no worker killed'] = str_contains($output, 'killed') === false;
      $Observed['no crash logged'] = str_contains($output, 'crashed') === false && str_contains($output, 'Uncaught') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: "one worker: the download completes on the old worker while its replacement, past its limit, holds its recycle until the go (peak {$peak})")
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'the download started on worker A' => true,
            'requests 2 and 3 recycle it' => true,
            'its replacement answers past its limit, still accepting (6 of 6)' => true,
            'A still drains the download meanwhile' => true,
            'B holds its recycle while A drains' => true,
            'the download completes, whole' => true,
            'A ends its drain: recycled' => true,
            'then B recycles, saying it held it' => true,
            'a new worker serves after it' => true,
            'the state lists no retiree once they are gone' => true,
            'master alive' => true,
            'the session never held more than the master and two workers' => true,
            'no worker killed' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ Two workers — the gate is per slot
      [$Observed, $output, $clean] = $Run(2, static function (int $port, int $master, Closure $Read) use ($Up, $Count, $Ask, $Download, $Finish, $Expect, $Await, $whole): array {
         $up = $Up($port, $Read);
         $peak = 0;

         // # A download on worker X: it pins X's drain once X recycles
         [$Big, $X, $read] = $up ? $Download($port) : [false, 0, 0];
         // # X's limit: requests until two more reach it
         $hits = 0;
         for ($attempt = 0; $X > 0 && $hits < 2 && $attempt < 60; $attempt++) {
            if ($Ask($port) === $X) {
               $hits++;
            }
            $peak = max($peak, $Count($master));
         }
         $relieved = $Expect($Read, "(PID: {$X}) recycling, forking its replacement", 2.0);
         // ! X's slot, and the worker that replaced it there
         $slot = -1;
         if ($relieved && preg_match("/Worker #(\\d+) \\(PID: {$X}\\) recycling, forking/", $Read(), $Match) === 1) {
            $slot = (int) $Match[1];
         }
         $Replacement = $slot >= 0
            ? $Await($Read, "(PID: {$X}) recycling, forking", "/Worker #{$slot} recovered \\(new PID: (\\d+)\\)/", 2.0)
            : 0;
         $other = $slot === 0 ? 1 : 0;
         $pattern = "/Worker #{$other} \\(PID: \\d+\\) (recycling, forking|recycled, reforking)/";
         $before = preg_match_all($pattern, $Read());

         // # The traffic goes on: slot Y recycles again and again; slot X's
         //   replacement passes its limit and holds
         $Answers = [];
         $others = 0;
         $past = 0;
         $deadline = hrtime(true) + 10_000_000_000;
         do {
            $Answers[] = $Ask($port);
            $peak = max($peak, $Count($master));
            usleep(40_000);
            $others = preg_match_all($pattern, $Read()) - $before;
            $past = count(array_keys($Answers, $Replacement, true));
         } while (($others < 2 || $past < 5) && hrtime(true) < $deadline);
         $draining = $X > 0 && posix_kill($X, 0);
         $log = $Read();
         $holding = $Replacement > 0
            && str_contains($log, "Worker (PID: {$Replacement}) recycling:") === false
            && preg_match_all("/Worker #{$slot} \\(PID: \\d+\\) recycling, forking/", $log) === 1;

         // # Slot Y's worker crashes once its own retiree is gone: the refork
         //   has no retiree in its slot — X's never gates it
         $crashed = 0;
         $refork = 0;
         if (preg_match_all("/Worker #{$other} \\(PID: (\\d+)\\) recycling, forking/", $log, $Matches) > 0) {
            $last = (int) end($Matches[1]);
            $Expect($Read, "(PID: {$last}) recycled.", 3.0);
            $log = $Read();
            $tail = substr($log, (int) strrpos($log, "(PID: {$last}) recycling, forking"));
            if (preg_match("/Worker #{$other} recovered \\(new PID: (\\d+)\\)/", $tail, $Match) === 1) {
               $crashed = (int) $Match[1];
               posix_kill($crashed, SIGKILL);
            }
         }
         if ($crashed > 0) {
            $refork = $Await(
               $Read, "(PID: {$crashed}) crashed, reforking", "/Worker #{$other} recovered \\(new PID: (\\d+)\\)/", 6.0
            );
         }
         $served = 0;
         $deadline = hrtime(true) + 6_000_000_000;
         while ($refork > 0 && $served < 3 && hrtime(true) < $deadline) {
            if ($Ask($port) === $refork) {
               $served++;
            }
            $peak = max($peak, $Count($master));
         }
         $free = $refork > 0 && $Expect($Read, "(PID: {$refork}) recycling, forking its replacement", 2.0)
            && $X > 0 && posix_kill($X, 0);

         // # The download completes: X goes, and its slot's worker recycles
         $bytes = $Big !== false ? $Finish($Big, $read) : 0;
         $gone = $Expect($Read, "(PID: {$X}) recycled.", 4.0);
         $released = $Expect($Read, "(PID: {$Replacement}) recycling, forking its replacement", 4.0);
         $peak = max($peak, $Count($master));
         $log = $Read();
         $done = strpos($log, "(PID: {$X}) recycled.");
         $go = strpos($log, "Worker (PID: {$Replacement}) held its recycle");

         return [
            'server up' => $up,
            'worker X recycled with the download in flight' => $X > 0 && $hits === 2 && $relieved && $slot >= 0,
            'its replacement was forked' => $Replacement > 0,
            'slot Y recycled twice or more meanwhile' => $others >= 2,
            'slot X\'s replacement answered past its limit (5+)' => $past >= 5,
            'every request answered' => $Answers !== [] && in_array(0, $Answers, true) === false,
            'X still drained the download meanwhile' => $draining,
            'slot X\'s replacement held its recycle meanwhile' => $holding,
            'a crash refork of slot Y recycles at its limit while X still drains' => $free,
            'the download completes, whole' => $bytes >= $whole,
            'X ends its drain, then its slot\'s worker recycles, saying it held it' => $gone && $released
               && $done !== false && $go !== false && $done < $go,
            'peak' => $peak,
         ];
      });
      $peak = $Observed['peak'];
      unset($Observed['peak']);
      $Observed['the session never held more than 2 × workers + 1 processes'] = $peak > 0 && $peak <= 5;
      $Observed['no worker killed'] = str_contains($output, 'killed') === false;
      $Observed['one crash logged: the one caused'] = substr_count($output, 'crashed, reforking') === 1
         && str_contains($output, 'Uncaught') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: "two workers: a pinned retiree holds only its own slot's next recycle; the other slot recycles freely (peak {$peak})")
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'worker X recycled with the download in flight' => true,
            'its replacement was forked' => true,
            'slot Y recycled twice or more meanwhile' => true,
            'slot X\'s replacement answered past its limit (5+)' => true,
            'every request answered' => true,
            'X still drained the download meanwhile' => true,
            'slot X\'s replacement held its recycle meanwhile' => true,
            'a crash refork of slot Y recycles at its limit while X still drains' => true,
            'the download completes, whole' => true,
            'X ends its drain, then its slot\'s worker recycles, saying it held it' => true,
            'master alive' => true,
            'the session never held more than 2 × workers + 1 processes' => true,
            'no worker killed' => true,
            'one crash logged: the one caused' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
