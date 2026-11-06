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
 * A paused server stays paused across a recycle — live, on a one-worker
 * Interactive HTTP server (`maxRequests: 3`, `jitter: 0`, stdin at EOF). The
 * operator pauses it (SIGTSTP); a keep-alive client it still serves reaches
 * the limit and the worker recycles. Its replacement boots paused: a new
 * client gets no answer, until the operator resumes the server (SIGCONT) —
 * then the replacement answers it. The Interactive prompt cannot wake on a
 * beacon, yet it looks often enough while recycling is on that the
 * replacement is forked while the old worker drains a connection it accepted
 * before the pause (the overlap), not after it exits — and, once resumed,
 * each later recycle's replacement forks within a quarter of a second. Each
 * of those recycles is driven once the worker drained before it is gone and
 * the master's go has reached its replacement: before, it would hold.
 */
return new Test(
   description: 'Recycling: a replacement forked while the server is paused boots paused, and a resume resumes it',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('PAUSED_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

$Server = new HTTP_Server_CLI(Modes::Interactive);
$Server->configure(new HTTP_Server_CLI\Configs(
   host: '127.0.0.1',
   port: (int) getenv('PAUSED_PORT'),
   workers: 1,
   maxRequests: 3,
   jitter: 0.0,
));
$Server->on(Events::RequestReceived, static fn (Request $Request, Response $Response): Response => $Response(body: (string) getmypid()));
$Server->start();
PHP;

      // @ Reserve a free loopback port.
      $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }

      $Environment = (array) getenv();
      $Environment['PAUSED_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
      $Environment['PAUSED_PORT'] = (string) $port;
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
      // ! By reference: the second server below reads through the same helpers
      $Read = static function () use (&$Pipes, &$output): string {
         $output .= (string) stream_get_contents($Pipes[1]) . (string) stream_get_contents($Pipes[2]);

         return $output;
      };
      /**
       * Send one request; the answering worker PID, or 0 when nothing came
       * back within $seconds.
       *
       * @param resource $Client
       */
      $Ask = static function ($Client, string $connection, float $seconds): int {
         @fwrite($Client, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: {$connection}\r\n\r\n");

         $buffer = '';
         $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
         while (hrtime(true) < $deadline) {
            $chunk = @fread($Client, 8192);
            if ($chunk !== false && $chunk !== '') {
               $buffer .= $chunk;
               $head = strpos($buffer, "\r\n\r\n");
               if (
                  $head !== false
                  && preg_match('/^Content-Length:\s*(\d+)/mi', $buffer, $Match) === 1
                  && strlen($buffer) >= $head + 4 + (int) $Match[1]
               ) {
                  return (int) substr($buffer, $head + 4);
               }

               continue;
            }
            if (feof($Client)) {
               break;
            }
            usleep(10_000);
         }

         return 0;
      };
      /** @return resource|false A new nonblocking connection. */
      $Connect = static function () use (&$port) {
         $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
         if ($Client !== false) {
            stream_set_blocking($Client, false);
         }

         return $Client;
      };
      /** Wait until the log says $line; whether it did. */
      $Expect = static function (string $line, float $seconds) use ($Read): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
         do {
            if (str_contains($Read(), $line)) {
               return true;
            }
            usleep(20_000);
         } while (hrtime(true) < $deadline);

         return false;
      };

      $Observed = [];
      $Latencies = [];
      $Pins = [];
      $clean = false;
      try {
         // @ Up: a keep-alive client is served, and a silent one accepted —
         //   the old worker will drain it
         $up = $Expect('Entering in Interactive mode', 8.0);
         $Keep = $Connect();
         $old = $Keep !== false ? $Ask($Keep, 'keep-alive', 3.0) : 0;
         $Silent = $Connect();
         usleep(100_000);

         // @ Paused: a new client waits
         posix_kill($master, SIGTSTP);
         $paused = $Expect('Pausing 1 worker(s)', 3.0);
         usleep(300_000);
         $Waiting = $Connect();
         $before = $Waiting !== false ? $Ask($Waiting, 'close', 1.0) : -1;

         // @ The keep-alive client reaches the limit: the worker recycles
         $second = $Keep !== false ? $Ask($Keep, 'keep-alive', 3.0) : 0;
         $third = $Keep !== false ? $Ask($Keep, 'keep-alive', 3.0) : 0;
         $recovered = $Expect('recovered (new PID', 3.0);
         usleep(500_000);

         // @ The replacement booted paused: a new client still waits
         $Late = $Connect();
         $after = $Late !== false ? $Ask($Late, 'close', 1.5) : -1;

         // @ Resumed: the replacement answers the client that waited
         posix_kill($master, SIGCONT);
         $resumed = $Expect('Resuming 1 worker(s)', 3.0);
         $answered = 0;
         if ($Late !== false) {
            $buffer = '';
            $deadline = hrtime(true) + 4_000_000_000;
            while (hrtime(true) < $deadline) {
               $chunk = @fread($Late, 8192);
               if ($chunk !== false && $chunk !== '') {
                  $buffer .= $chunk;
               }
               $head = strpos($buffer, "\r\n\r\n");
               if ($head !== false && preg_match('/^Content-Length:\s*(\d+)/mi', $buffer, $Match) === 1
                  && strlen($buffer) >= $head + 4 + (int) $Match[1]) {
                  $answered = (int) substr($buffer, $head + 4);
                  break;
               }
               usleep(10_000);
            }
         }

         // @ Interactive, unpaused: each recycle's replacement forks within a
         //   turn of the prompt — while recycling is on, it looks at the
         //   beacons every 50 ms (it cannot wait on them)
         $Latencies = [];
         $Pins = [];
         // ! The worker each cycle pins: it drains — and holds its slot's next
         //   recycle — until its pin closes. The first one is the old worker,
         //   pinned by the silent client since before the pause
         $Pin = $Silent;
         $pinned = $old;
         for ($cycle = 0; $cycle < 4; $cycle++) {
            // # The pinned worker goes: the current one may recycle once the
            //   master's go reaches it (read once a second)
            if (is_resource($Pin)) {
               fclose($Pin);
            }
            $Expect("(PID: {$pinned}) recycled.", 3.0);
            usleep(1_100_000);
            // # A connection the current worker accepts: it will drain it
            $Pin = $Pins[] = $Connect();
            usleep(100_000);
            $forks = substr_count($Read(), 'recycling, forking its replacement');
            // @@ Requests until one triggers the recycle (Connection: close)
            $closed = false;
            for ($request = 0; $closed === false && $request < 4; $request++) {
               $Client = $Connect();
               if ($Client === false) {
                  break;
               }
               @fwrite($Client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
               $buffer = '';
               $deadline = hrtime(true) + 3_000_000_000;
               while (hrtime(true) < $deadline && str_contains($buffer, "\r\n\r\n") === false) {
                  $chunk = @fread($Client, 8192);
                  if ($chunk === false || $chunk === '') {
                     usleep(5_000);

                     continue;
                  }
                  $buffer .= $chunk;
               }
               $closed = preg_match('/^Connection:\s*close/mi', $buffer) === 1;
               // ! The worker that recycles in this cycle: its answer's body
               $stop = hrtime(true) + 1_000_000_000;
               while ($closed && hrtime(true) < $stop && preg_match('/\r\n\r\n(\d+)$/', $buffer) !== 1) {
                  $chunk = @fread($Client, 8192);
                  if ($chunk !== false && $chunk !== '') {
                     $buffer .= $chunk;
                  }
               }
               if ($closed && preg_match('/\r\n\r\n(\d+)$/', $buffer, $Match) === 1) {
                  $pinned = (int) $Match[1];
               }
               fclose($Client);
            }
            $triggered = hrtime(true);
            while (
               substr_count($Read(), 'recycling, forking its replacement') === $forks
               && hrtime(true) - $triggered < 2_000_000_000
            ) {
               usleep(5_000);
            }
            $Latencies[] = round((hrtime(true) - $triggered) / 1_000_000_000, 3);
         }

         $Observed = [
            'server up, a keep-alive client served' => $up && $old > 0,
            'paused' => $paused,
            'a new client waits while paused' => $before === 0,
            'the keep-alive client is still served, up to the limit' => $second === $old && $third === $old,
            'the worker recycled and its replacement was forked' => $recovered,
            'the replacement forked while paused does not answer' => $after === 0,
            'resumed' => $resumed,
            'the replacement answers the waiting client once resumed' => $answered > 0 && $answered !== $old,
            'each later recycle forks its replacement within a quarter second' => count($Latencies) === 4 && max($Latencies) < 0.25,
            'master alive' => is_resource($Process) && proc_get_status($Process)['running'],
         ];
         $settle = hrtime(true) + 300_000_000;
         while (hrtime(true) < $settle) {
            $Read();
            usleep(50_000);
         }
         foreach ([$Keep, $Waiting, $Late, ...$Pins] as $Client) {
            if (is_resource($Client)) {
               fclose($Client);
            }
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
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "HTTP_Server_CLI.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      }
      $Observed['forked while the old worker drained (the overlap)'] = str_contains($output, 'recycling, forking its replacement');
      $Observed['no crash logged'] = str_contains($output, 'crashed') === false && str_contains($output, 'Uncaught') === false;
      $Observed['group clean'] = $clean;

      $seconds = json_encode($Latencies);

      yield new Assertion(description: "a recycle while paused forks a paused replacement; resume resumes it; Interactive forks each replacement within a turn ({$seconds})")
         ->expect($Observed, Op::Identical, [
            'server up, a keep-alive client served' => true,
            'paused' => true,
            'a new client waits while paused' => true,
            'the keep-alive client is still served, up to the limit' => true,
            'the worker recycled and its replacement was forked' => true,
            'the replacement forked while paused does not answer' => true,
            'resumed' => true,
            'the replacement answers the waiting client once resumed' => true,
            'each later recycle forks its replacement within a quarter second' => true,
            'master alive' => true,
            'forked while the old worker drained (the overlap)' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ A paused server stays paused across Monitor and back: entering
      //   either console never marks it running behind its paused workers
      $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }
      $Environment['PAUSED_PORT'] = (string) $port;
      $Process = proc_open(
         [PHP_BINARY, '-d', 'opcache.jit=0', '-r', $Script],
         [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
         $Pipes,
         BOOTGLY_ROOT_BASE,
         $Environment,
      );
      $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
      stream_set_blocking($Pipes[1], false);
      stream_set_blocking($Pipes[2], false);
      $output = '';
      $Console = [];
      $clean = false;
      try {
         $up = $Expect('Entering in Interactive mode', 8.0);
         $Keep = $Connect();
         $served = $Keep !== false ? $Ask($Keep, 'keep-alive', 3.0) : 0;

         // # Paused from the console, then into Monitor and back
         @fwrite($Pipes[0], "pause\n");
         $paused = $Expect('Pausing 1 worker(s)', 3.0);
         @fwrite($Pipes[0], "monitor\n");
         $monitor = $Expect("\e[?1049h", 3.0);
         usleep(300_000);
         $entered = substr_count($Read(), 'Entering in Interactive mode');
         posix_kill($master, SIGTSTP);
         $deadline = hrtime(true) + 3_000_000_000;
         while (substr_count($Read(), 'Entering in Interactive mode') === $entered && hrtime(true) < $deadline) {
            usleep(20_000);
         }
         $back = substr_count($Read(), 'Entering in Interactive mode') > $entered;

         // # Still paused: a new client waits — until a resume, which the
         //   paused master takes
         $Waiting = $Connect();
         $before = $Waiting !== false ? $Ask($Waiting, 'close', 1.0) : -1;
         posix_kill($master, SIGCONT);
         $resumed = $Expect('Resuming 1 worker(s)', 3.0);
         $answered = 0;
         if ($Waiting !== false) {
            $buffer = '';
            $deadline = hrtime(true) + 3_000_000_000;
            while (hrtime(true) < $deadline) {
               $chunk = @fread($Waiting, 8192);
               if ($chunk !== false && $chunk !== '') {
                  $buffer .= $chunk;
               }
               $head = strpos($buffer, "\r\n\r\n");
               if ($head !== false && preg_match('/^Content-Length:\s*(\d+)/mi', $buffer, $Match) === 1
                  && strlen($buffer) >= $head + 4 + (int) $Match[1]) {
                  $answered = (int) substr($buffer, $head + 4);
                  break;
               }
               usleep(10_000);
            }
         }
         $Read();

         $Console = [
            'server up, a client served' => $up && $served > 0,
            'paused from the console' => $paused,
            'into Monitor' => $monitor,
            'back to Interactive' => $back,
            'a new client still waits' => $before === 0,
            'the master takes the resume' => $resumed && str_contains($output, 'needs to be paused to resume') === false,
            'the waiting client is answered' => $answered > 0,
            'master alive' => is_resource($Process) && proc_get_status($Process)['running'],
         ];
         foreach ([$Keep, $Waiting] as $Client) {
            if (is_resource($Client)) {
               fclose($Client);
            }
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
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "HTTP_Server_CLI.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      }
      $Console['group clean'] = $clean;

      yield new Assertion(description: 'a paused server stays paused across Monitor and back to Interactive: a resume still resumes it')
         ->expect($Console, Op::Identical, [
            'server up, a client served' => true,
            'paused from the console' => true,
            'into Monitor' => true,
            'back to Interactive' => true,
            'a new client still waits' => true,
            'the master takes the resume' => true,
            'the waiting client is answered' => true,
            'master alive' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
