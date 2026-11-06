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
 * Worker recycling on the HTTP node, live: a Foreground `HTTP_Server_CLI`
 * counts every complete request — the requests one keep-alive connection
 * carries count one each.
 *
 * - With `maxRequests: 6` (`jitter: 0`), one keep-alive connection gets six
 *   answers from one worker, the sixth with `Connection: close`, and is then
 *   closed: the recycling worker hands its keep-alive clients back instead of
 *   holding its drain open. Concurrent slow requests sent across the
 *   following recycle boundaries are all answered (in-flight work finishes,
 *   queued connections reach the replacement), and the master logs recycles,
 *   never a crash or a crash-loop backoff.
 * - With `maxMemory: "24M"` and no request limit, a handler that keeps 8 MiB
 *   per request recycles its worker once its real memory passes the ceiling,
 *   checked between requests; every request is answered.
 * - With `maxRequests: 2`, a deferred response that triggers the recycle ends
 *   its connection like any other: a request pipelined behind it is never
 *   answered by the recycling worker, and the connection closes right after
 *   the deferred answer.
 * - A request refused before dispatch (a JSON body over its decode bound,
 *   `413`) is no dispatch: two of them leave the worker's count untouched.
 */
return new Test(
   description: 'Recycling: an HTTP worker recycles after maxRequests requests or past maxMemory, without dropping one',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('HTTP_RECYCLE_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

$Server = new HTTP_Server_CLI(Modes::Foreground);
$Server->configure(new HTTP_Server_CLI\Configs(
   host: '127.0.0.1',
   port: (int) getenv('HTTP_RECYCLE_PORT'),
   workers: (int) getenv('HTTP_RECYCLE_WORKERS'),
   maxRequests: (int) getenv('HTTP_RECYCLE_MAX'),
   jitter: 0.0,
   maxMemory: (string) getenv('HTTP_RECYCLE_MEMORY'),
   maxWorkerPendingBytes: getenv('HTTP_RECYCLE_BUDGET') ? (int) getenv('HTTP_RECYCLE_BUDGET') : null,
));
$Server->on(Events::RequestReceived, static function (Request $Request, Response $Response): Response {
   // # Kept for the worker's life: its real memory only grows
   if ($Request->URI === '/grow') {
      $GLOBALS['ballast'][] = str_repeat('x', 8 * 1024 * 1024);
   }
   // # Answered on the next loop turn
   if ($Request->URI === '/defer') {
      return $Response->defer(static function (Response $Response): void {
         $Response->wait();
         $Response(body: (string) getmypid());
      });
   }
   // # In flight long enough to cross a recycle
   if ($Request->URI === '/slow') {
      usleep(150_000);
   }

   return $Response(body: (string) getmypid());
});
$Server->start();
PHP;

      /**
       * Boot one server; run $Drive against its port; return what it saw plus
       * the server log and whether its session group was left clean.
       *
       * @return array{0:array<string,mixed>,1:string,2:bool}
       */
      $Run = static function (int $workers, int $max, string $memory, Closure $Drive, int $budget = 0) use ($Script): array {
         // @ Reserve a free loopback port.
         $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }

         $Environment = (array) getenv();
         $Environment['HTTP_RECYCLE_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['HTTP_RECYCLE_PORT'] = (string) $port;
         $Environment['HTTP_RECYCLE_WORKERS'] = (string) $workers;
         $Environment['HTTP_RECYCLE_MAX'] = (string) $max;
         $Environment['HTTP_RECYCLE_MEMORY'] = $memory;
         $Environment['HTTP_RECYCLE_BUDGET'] = (string) $budget;
         $Process = proc_open(
            [PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'memory_limit=256M', '-r', $Script],
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
            // @ Wait until it accepts — a bare connection is no request, so
            //   it counts toward no worker's limit
            $deadline = hrtime(true) + 8_000_000_000;
            do {
               $Read();
               $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 0.2);
               usleep(50_000);
            } while ($Client === false && hrtime(true) < $deadline);
            if ($Client !== false) {
               fclose($Client);
            }
            $Observed = $Drive($port, $Read, $Client !== false);
            $Observed['master alive'] = is_resource($Process) && proc_get_status($Process)['running'];
            // ! Let the last drains finish so their log lines are read
            $settle = hrtime(true) + 1_500_000_000;
            while (hrtime(true) < $settle) {
               $Read();
               usleep(50_000);
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

         return [$Observed, $output, $clean];
      };

      /**
       * Read one HTTP/1.1 response.
       *
       * @param resource $Client
       *
       * @return null|array{int,int,bool} [status, worker PID, Connection: close] — null when closed first.
       */
      $Response = static function ($Client): null|array {
         $head = '';
         while (str_contains($head, "\r\n\r\n") === false) {
            $byte = @fread($Client, 1);
            if ($byte === '' || $byte === false) {
               return null;
            }
            $head .= $byte;
         }
         $length = preg_match('/^Content-Length:\s*(\d+)/mi', $head, $Match) === 1 ? (int) $Match[1] : 0;
         $body = '';
         while (strlen($body) < $length) {
            $chunk = @fread($Client, $length - strlen($body));
            if ($chunk === '' || $chunk === false) {
               return null;
            }
            $body .= $chunk;
         }
         $status = (int) substr($head, 9, 3);

         return [$status, (int) $body, preg_match('/^Connection:\s*close/mi', $head) === 1];
      };

      // @ maxRequests: 6, two workers
      [$Observed, $output, $clean] = $Run(2, 6, '0', static function (int $port, Closure $Read, bool $up) use ($Response): array {
         // # One keep-alive connection: its requests count one each
         $Answers = [];
         $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
         if ($Client !== false) {
            stream_set_timeout($Client, 5);
            for ($request = 0; $request < 10; $request++) {
               if (@fwrite($Client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n") === false) {
                  break;
               }
               $Answer = $Response($Client);
               if ($Answer === null) {
                  break;
               }
               $Answers[] = $Answer;
            }
            fclose($Client);
         }
         $Read();
         $first = $Answers[0][1] ?? 0;
         $Closes = array_map(static fn (array $Answer): bool => $Answer[2], $Answers);

         // # Waves of concurrent slow requests across the recycle boundaries
         $Results = [];
         for ($wave = 0; $up && $wave < 3; $wave++) {
            $Clients = [];
            for ($index = 0; $index < 8; $index++) {
               $Clients[$index] = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
            }
            foreach ($Clients as $Peer) {
               if ($Peer !== false) {
                  @fwrite($Peer, "GET /slow HTTP/1.1\r\nHost: localhost\r\n\r\n");
               }
            }
            foreach ($Clients as $Peer) {
               if ($Peer === false) {
                  $Results[] = null;
                  continue;
               }
               // ! A connection queued behind a draining slot waits for its
               //   replacement worker
               stream_set_timeout($Peer, 8);
               $Results[] = $Response($Peer);
               fclose($Peer);
               $Read();
            }
         }
         $answered = count(array_filter($Results, static fn (null|array $Answer): bool => $Answer !== null && $Answer[0] === 200 && $Answer[1] > 0));
         $PIDs = array_unique(array_filter(array_map(static fn (null|array $Answer): int => $Answer[1] ?? 0, $Results)));

         return [
            'server up' => $up,
            'keep-alive: six answers, then closed' => count($Answers) === 6,
            'keep-alive: one worker answered them' => $first > 0 && array_unique(array_column($Answers, 1)) === [$first],
            'keep-alive: only the sixth says Connection: close' => $Closes === [false, false, false, false, false, true],
            'every concurrent request answered 200 (24 of 24)' => count($Results) === 24 && $answered === 24,
            'the recycled worker is gone from the answers' => in_array($first, $PIDs, true) === false,
         ];
      });
      $Observed['recycles logged by the master (>= 2)'] = preg_match_all('/recycling, forking its replacement|recycled, reforking/', $output) >= 2;
      $Observed['each drain says why'] = str_contains($output, 'requests reached its limit of 6');
      $Observed['no crash logged'] = str_contains($output, 'crashed') === false;
      $Observed['no crash-loop backoff'] = str_contains($output, 'times in a row') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'past maxRequests requests (keep-alive included) a worker recycles: in-flight requests finish, none dropped')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'keep-alive: six answers, then closed' => true,
            'keep-alive: one worker answered them' => true,
            'keep-alive: only the sixth says Connection: close' => true,
            'every concurrent request answered 200 (24 of 24)' => true,
            'the recycled worker is gone from the answers' => true,
            'master alive' => true,
            'recycles logged by the master (>= 2)' => true,
            'each drain says why' => true,
            'no crash logged' => true,
            'no crash-loop backoff' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ maxMemory: "24M", no request limit, one worker
      [$Observed, $output, $clean] = $Run(1, 0, '24M', static function (int $port, Closure $Read, bool $up) use ($Response): array {
         $PIDs = [];
         for ($request = 0; $up && $request < 6; $request++) {
            $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
            if ($Client === false) {
               $PIDs[] = 0;
               continue;
            }
            stream_set_timeout($Client, 8);
            @fwrite($Client, "GET /grow HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
            $Answer = $Response($Client);
            fclose($Client);
            $PIDs[] = $Answer !== null && $Answer[0] === 200 ? $Answer[1] : 0;
            $Read();
         }

         return [
            'server up' => $up,
            'every request answered (6 of 6)' => count($PIDs) === 6 && in_array(0, $PIDs, true) === false,
            'the worker was replaced' => count(array_unique($PIDs)) >= 2,
         ];
      });
      $Observed['the drain names the memory ceiling'] = str_contains($output, 'its memory passed maxMemory (25165824 bytes)');
      $Observed['recycle logged by the master'] = preg_match('/recycling, forking its replacement|recycled, reforking/', $output) === 1;
      $Observed['no crash logged'] = str_contains($output, 'crashed') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'past maxMemory a worker recycles between requests')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'every request answered (6 of 6)' => true,
            'the worker was replaced' => true,
            'master alive' => true,
            'the drain names the memory ceiling' => true,
            'recycle logged by the master' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ maxRequests: 2 — a deferred response triggers the recycle
      [$Observed, $output, $clean] = $Run(1, 2, '0', static function (int $port, Closure $Read, bool $up) use ($Response): array {
         $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
         $first = null;
         $deferred = null;
         $pipelined = null;
         $closed = false;
         if ($Client !== false) {
            stream_set_timeout($Client, 5);
            @fwrite($Client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $first = $Response($Client);
            // ! The trigger and a request pipelined behind it, in one write
            @fwrite($Client, "GET /defer HTTP/1.1\r\nHost: localhost\r\n\r\nGET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $deferred = $Response($Client);
            $answered = hrtime(true);
            stream_set_timeout($Client, 2);
            $pipelined = $Response($Client);
            $closed = $pipelined === null && feof($Client) && hrtime(true) - $answered < 900_000_000;
            fclose($Client);
         }
         $Read();

         return [
            'server up' => $up,
            'the deferred trigger is answered by the same worker' => $first !== null && $deferred !== null
               && $deferred[0] === 200 && $deferred[1] === $first[1],
            'the request pipelined behind it is not answered there' => $pipelined === null,
            'the connection closes right after the deferred answer' => $closed,
         ];
      });
      $Observed['the recycle forked a replacement'] = str_contains($output, 'recycling, forking its replacement');
      $Observed['no crash logged'] = str_contains($output, 'crashed') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'a deferred response that triggers the recycle closes its connection')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'the deferred trigger is answered by the same worker' => true,
            'the request pipelined behind it is not answered there' => true,
            'the connection closes right after the deferred answer' => true,
            'master alive' => true,
            'the recycle forked a replacement' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ maxRequests: 2 — refused requests count for nothing
      [$Observed, $output, $clean] = $Run(1, 2, '0', static function (int $port, Closure $Read, bool $up) use ($Response): array {
         $Ask = static function (string $request) use ($port, $Response): null|array {
            $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
            if ($Client === false) {
               return null;
            }
            stream_set_timeout($Client, 5);
            @fwrite($Client, $request);
            $Answer = $Response($Client);
            fclose($Client);

            return $Answer;
         };
         $plain = "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
         // ! Decodes into far more than the 128 KiB bodies share of the budget
         $JSON = '[' . implode(',', array_fill(0, 4000, '[0]')) . ']';
         $length = strlen($JSON);
         $refused = "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: {$length}\r\nConnection: close\r\n\r\n{$JSON}";

         $first = $Ask($plain);
         $Refusals = [$Ask($refused), $Ask($refused)];
         $second = $Ask($plain);
         $Read();

         return [
            'server up' => $up,
            'both JSON bodies refused (413)' => ($Refusals[0][0] ?? 0) === 413 && ($Refusals[1][0] ?? 0) === 413,
            'the second dispatched request still reaches the first worker' => $first !== null && $second !== null
               && $first[1] > 0 && $second[1] === $first[1],
         ];
      }, 256 * 1024);
      $Observed['no crash logged'] = str_contains($output, 'crashed') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'a request refused with 413 before dispatch is not counted')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'both JSON bodies refused (413)' => true,
            'the second dispatched request still reaches the first worker' => true,
            'master alive' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
