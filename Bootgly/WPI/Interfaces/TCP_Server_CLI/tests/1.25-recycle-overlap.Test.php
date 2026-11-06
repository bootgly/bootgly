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
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


/**
 * Worker recycling overlaps the old worker and its replacement, live, on a
 * one-worker Foreground server (`jitter: 0`): the slot is never left without
 * a worker accepting while the old one drains.
 *
 * - HTTP/1 (`maxRequests: 4`): an idle keep-alive client is held open, a
 *   deferred request is in flight and a connection opened before the recycle
 *   has not spoken yet when the fourth request — a handler that blocks for a
 *   quarter of a second — triggers the recycle. The idle keep-alive
 *   connection is closed at once (within half a second, before the first
 *   drain tick); a keep-alive client that wrote its next request while the
 *   trigger blocked is answered, with `Connection: close`, never cut; a new
 *   client is answered by the replacement within a second; the connection
 *   opened before the recycle is served by the old worker when its first
 *   request comes, with `Connection: close`; the in-flight request is still
 *   answered by the old worker, which then closes its connection and exits.
 *   Two SIGQUITs sent to the draining worker change nothing: one drain, one
 *   recycle line, no crash.
 * - HTTP/2 (`maxRequests: 2`): a stream in flight when another stream of the
 *   same connection triggers the recycle keeps its connection: the client
 *   gets one GOAWAY naming the last stream, then the in-flight answer. A
 *   burst of new streams the client sent before reading that GOAWAY — more
 *   than the rapid-reset budget, one of them with a body whose DATA follows
 *   — is refused stream by stream (`REFUSED_STREAM`), never counted as
 *   churn, and the DATA of a refused stream is no protocol error: no second
 *   GOAWAY, no higher Last-Stream-ID. A second connection whose request body is still arriving
 *   gets its GOAWAY too but stays open: the body ends, the old worker
 *   answers, and the connection is closed once its stream is done.
 * - WebSocket (`maxRequests: 3` connections): an open session is closed with
 *   `1001 Going Away` when the recycle begins, and a new client upgrades on
 *   the replacement.
 */
return new Test(
   description: 'Recycling: the replacement serves while the old worker drains; idle HTTP/1, HTTP/2 and WebSocket clients are handed back at once, busy ones served',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('OVERLAP_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\WS_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI\Events as WS_Events;

$port = (int) getenv('OVERLAP_PORT');
$max = (int) getenv('OVERLAP_MAX');

// # WebSocket: every session learns which worker holds it
if (getenv('OVERLAP_NODE') === 'ws') {
   $Server = new WS_Server_CLI(Modes::Foreground);
   $Server->configure(new WS_Server_CLI\Configs(
      host: '127.0.0.1',
      port: $port,
      workers: 1,
      maxRequests: $max,
      jitter: 0.0,
   ));
   $Server->on(WS_Events::Connected, static function ($Session): void {
      $Session->send('worker:' . getmypid());
   });
   $Server->on(WS_Events::MessageReceived, static fn ($Session, $Message): string => (string) getmypid());
   $Server->start();
   exit(0);
}

$Server = new HTTP_Server_CLI(Modes::Foreground);
$Server->configure(new HTTP_Server_CLI\Configs(
   host: '127.0.0.1',
   port: $port,
   workers: 1,
   maxRequests: $max,
   jitter: 0.0,
));
$Server->on(Events::RequestReceived, static function (Request $Request, Response $Response): Response {
   // # Blocks the worker for a quarter of a second
   if ($Request->URI === '/block') {
      $until = hrtime(true) + 250_000_000;
      while (hrtime(true) < $until) {
         usleep(10_000);
      }
   }
   // # In flight for two seconds, without blocking the worker
   if ($Request->URI === '/hold') {
      return $Response->defer(static function (Response $Response): void {
         $until = hrtime(true) + 2_000_000_000;
         while (hrtime(true) < $until) {
            $Response->wait();
         }
         $Response(body: (string) getmypid());
      });
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
      $Run = static function (string $node, int $max, Closure $Drive) use ($Script): array {
         // @ Reserve a free loopback port.
         $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }

         $Environment = (array) getenv();
         $Environment['OVERLAP_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['OVERLAP_PORT'] = (string) $port;
         $Environment['OVERLAP_MAX'] = (string) $max;
         $Environment['OVERLAP_NODE'] = $node;
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
            // @ Wait until it accepts — the HTTP node counts no bare
            //   connection; the WebSocket node counts this one
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
            $settle = hrtime(true) + 1_000_000_000;
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
               if (
                  str_starts_with((string) $file, "HTTP_Server_CLI.{$port}.")
                  || str_starts_with((string) $file, "WS_Server_CLI.{$port}.")
               ) {
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

         return [(int) substr($head, 9, 3), (int) $body, preg_match('/^Connection:\s*close/mi', $head) === 1];
      };
      /**
       * Whether the server closed this connection (EOF) within $seconds.
       *
       * @param resource $Client
       */
      $Closed = static function ($Client, float $seconds): bool {
         stream_set_blocking($Client, false);
         $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
         while (hrtime(true) < $deadline) {
            $chunk = @fread($Client, 4096);
            if (($chunk === '' || $chunk === false) && feof($Client)) {
               return true;
            }
            usleep(10_000);
         }

         return false;
      };

      // @ HTTP/1 — idle, waiting, speaking and in-flight connections across the recycle
      [$Observed, $output, $clean] = $Run('http', 4, static function (int $port, Closure $Read, bool $up) use ($Response, $Closed): array {
         $Connect = static function () use ($port) {
            $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
            if ($Client !== false) {
               stream_set_timeout($Client, 6);
            }

            return $Client;
         };
         $GET = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";

         // # Request 1: the keep-alive connection, then held idle
         $Idle = $Connect();
         $first = null;
         if ($Idle !== false) {
            @fwrite($Idle, $GET);
            $first = $Response($Idle);
         }
         $old = $first[1] ?? 0;
         // # Request 2: a keep-alive connection that speaks again later
         $Speaker = $Connect();
         if ($Speaker !== false) {
            @fwrite($Speaker, $GET);
            $Response($Speaker);
         }
         // # A connection that has not spoken yet
         $Silent = $Connect();
         usleep(100_000);
         // # Request 3: in flight for two seconds
         $Held = $Connect();
         if ($Held !== false) {
            @fwrite($Held, "GET /hold HTTP/1.1\r\nHost: localhost\r\n\r\n");
         }
         usleep(200_000);
         // # Request 4: the recycle — its handler blocks while the speaker
         //   writes its next request
         $Trigger = $Connect();
         $fourth = null;
         if ($Trigger !== false) {
            @fwrite($Trigger, "GET /block HTTP/1.1\r\nHost: localhost\r\n\r\n");
            usleep(60_000);
            if ($Speaker !== false) {
               @fwrite($Speaker, $GET);
            }
            $fourth = $Response($Trigger);
            fclose($Trigger);
         }
         $triggered = hrtime(true);

         // # The idle keep-alive connection: closed at once, not at a drain
         //   tick (the one-second timer wheel may run the first one sooner
         //   than a second after: the window stays well under it)
         $idleClosed = $Idle !== false && $Closed($Idle, 0.25);
         // # The request written while the trigger blocked
         $spoken = $Speaker !== false ? $Response($Speaker) : null;

         // # A new client: the replacement answers it while the old drains
         $New = $Connect();
         $fresh = null;
         if ($New !== false) {
            @fwrite($New, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
            $fresh = $Response($New);
            fclose($New);
         }
         $answered = (hrtime(true) - $triggered) / 1_000_000_000;

         // # The connection opened before the recycle speaks now
         $silent = null;
         if ($Silent !== false) {
            @fwrite($Silent, $GET);
            $silent = $Response($Silent);
         }

         // # Two more SIGQUITs to the draining worker
         $alive = $old > 0 && posix_kill($old, 0);
         if ($alive) {
            posix_kill($old, SIGQUIT);
            usleep(20_000);
            posix_kill($old, SIGQUIT);
         }

         // # The in-flight request ends on the old worker
         $held = $Held !== false ? $Response($Held) : null;
         $heldClosed = $Held !== false && $Closed($Held, 2.5);
         $Read();

         return [
            'server up' => $up,
            'the fourth request triggers the recycle (Connection: close)' => $fourth !== null && $fourth[1] === $old && $fourth[2] === true,
            'the idle keep-alive connection is closed at once' => $idleClosed,
            'a request written while the trigger ran is answered there, with Connection: close' => $spoken !== null
               && $spoken[0] === 200 && $spoken[1] === $old && $spoken[2] === true,
            'a new client is answered by the replacement' => $fresh !== null && $fresh[0] === 200 && $fresh[1] > 0 && $fresh[1] !== $old,
            'within a second of the recycle' => $fresh !== null && $answered < 1.0,
            'a connection opened before the recycle is served by the old worker, with Connection: close' => $silent !== null
               && $silent[0] === 200 && $silent[1] === $old && $silent[2] === true,
            'the old worker still drains then' => $alive,
            'the in-flight request is answered by the old worker' => $held !== null && $held[0] === 200 && $held[1] === $old,
            'the in-flight connection is closed once answered' => $heldClosed,
            'old' => $old,
         ];
      });
      $old = $Observed['old'];
      unset($Observed['old']);
      $Observed['one recycle line for the old worker'] = substr_count($output, "Worker (PID: {$old}) recycling:") === 1;
      $Observed['the replacement forked at the recycle'] = str_contains($output, "(PID: {$old}) recycling, forking its replacement");
      $Observed['the retiree reaped as recycled'] = str_contains($output, "(PID: {$old}) recycled.");
      $Observed['no crash logged'] = str_contains($output, 'crashed') === false && str_contains($output, 'Uncaught') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'HTTP/1: the replacement serves at once; idle clients are closed at once, waiting and speaking ones answered; a repeated SIGQUIT is one drain')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'the fourth request triggers the recycle (Connection: close)' => true,
            'the idle keep-alive connection is closed at once' => true,
            'a request written while the trigger ran is answered there, with Connection: close' => true,
            'a new client is answered by the replacement' => true,
            'within a second of the recycle' => true,
            'a connection opened before the recycle is served by the old worker, with Connection: close' => true,
            'the old worker still drains then' => true,
            'the in-flight request is answered by the old worker' => true,
            'the in-flight connection is closed once answered' => true,
            'master alive' => true,
            'one recycle line for the old worker' => true,
            'the replacement forked at the recycle' => true,
            'the retiree reaped as recycled' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ HTTP/2 — a stream in flight, a burst after the GOAWAY, a body still arriving
      [$Observed, $output, $clean] = $Run('http', 2, static function (int $port, Closure $Read, bool $up): array {
         $Request = static fn (string $method, string $path): string => HPACK::encode([
            [':method', $method],
            [':scheme', 'http'],
            [':path', $path],
            [':authority', "127.0.0.1:{$port}"],
         ]);
         $flags = HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM;
         /**
          * What one frame says, for the sequences below.
          *
          * @param array{type:int,flags:int,stream:int,payload:string} $Frame
          */
         $Say = static function (array $Frame, array &$PIDs): string {
            if ($Frame['type'] === HTTP2::FRAME_DATA && $Frame['payload'] !== '') {
               $PIDs[$Frame['stream']] = (int) $Frame['payload'];

               return "DATA {$Frame['stream']}";
            }
            if ($Frame['type'] === HTTP2::FRAME_GOAWAY) {
               /** @var array{1:int,2:int} $Fields */
               $Fields = unpack('N2', $Frame['payload']);
               $last = $Fields[1] & 0x7fffffff;

               return "GOAWAY {$last} {$Fields[2]}";
            }
            if ($Frame['type'] === HTTP2::FRAME_RST_STREAM) {
               /** @var array{1:int} $Code */
               $Code = unpack('N', $Frame['payload']);

               return "RST {$Code[1]}";
            }

            return '';
         };

         // # A: stream 1 in flight for two seconds
         $A = new Client("tcp://127.0.0.1:{$port}");
         $A->send(
            HTTP2::PREFACE
            . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
            . Frame::pack(HTTP2::FRAME_HEADERS, $flags, 1, $Request('GET', '/hold'))
         );
         // # B: a request body still arriving (no dispatch yet)
         $B = new Client("tcp://127.0.0.1:{$port}");
         $B->send(
            HTTP2::PREFACE
            . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
            . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 1, $Request('POST', '/'))
            . Frame::pack(HTTP2::FRAME_DATA, 0, 1, 'part')
         );
         usleep(200_000);
         // # A, stream 3: the recycle — then, once its answer is read but
         //   before its GOAWAY is, a burst of new streams past the
         //   rapid-reset budget (64)
         $A->send(Frame::pack(HTTP2::FRAME_HEADERS, $flags, 3, $Request('GET', '/')));
         $SeenA = [];
         $PIDs = [];
         $deadline = microtime(true) + 3.0;
         while (microtime(true) < $deadline && in_array('DATA 3', $SeenA, true) === false) {
            $Frame = $A->frame(0.05);
            $said = $Frame === null ? '' : $Say($Frame, $PIDs);
            if ($said !== '') {
               $SeenA[] = $said;
            }
         }
         // ! The drain runs right after that answer — before a first drain tick
         usleep(30_000);
         $burst = '';
         for ($stream = 5; $stream < 5 + 2 * 70; $stream += 2) {
            $burst .= Frame::pack(HTTP2::FRAME_HEADERS, $flags, $stream, $Request('GET', '/'));
         }
         // # ...and one with a body: its DATA follows the refused HEADERS
         $burst .= Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 145, $Request('POST', '/'))
            . Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 145, 'body');
         $A->send($burst);

         // @@ What both connections carry until they end
         $SeenB = [];
         $PIDsB = [];
         $refused = 0;
         $finished = false;
         $deadline = microtime(true) + 6.0;
         $endedA = false;
         $endedB = false;
         while (microtime(true) < $deadline && ($endedA === false || $endedB === false)) {
            if ($endedA === false) {
               $Frame = $A->frame(0.05);
               if ($Frame === null) {
                  $endedA = @feof($A->Socket);
               }
               else {
                  $said = $Say($Frame, $PIDs);
                  // ! Stream resets are counted, not sequenced: a refused
                  //   stream's late DATA may draw its own
                  if (str_starts_with($said, 'RST')) {
                     $refused += $said === 'RST 7' ? 1 : 0;
                  }
                  else if ($said !== '') {
                     $SeenA[] = $said;
                  }
               }
            }
            if ($endedB === false) {
               $Frame = $B->frame(0.05);
               if ($Frame === null) {
                  $endedB = @feof($B->Socket);
               }
               else {
                  $said = $Say($Frame, $PIDsB);
                  if ($said !== '') {
                     $SeenB[] = $said;
                  }
                  // # B saw its GOAWAY: its body ends now
                  if (str_starts_with($said, 'GOAWAY') && $finished === false) {
                     $B->send(Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 1, 'rest'));
                     $finished = true;
                  }
               }
            }
         }
         $A->close();
         $B->close();
         $Read();

         return [
            'server up' => $up,
            'trigger answered, one GOAWAY(3, NO_ERROR), then the in-flight stream' => $SeenA === ['DATA 3', 'GOAWAY 3 0', 'DATA 1'],
            'all 71 streams opened after the GOAWAY are refused (REFUSED_STREAM), none counted as churn' => $refused === 71,
            'both answered by the old worker' => isSet($PIDs[1], $PIDs[3]) && $PIDs[1] === $PIDs[3] && $PIDs[1] > 0,
            'the connection ends after' => $endedA,
            'a connection with a body still arriving gets GOAWAY(1), then its answer' => $SeenB === ['GOAWAY 1 0', 'DATA 1'],
            'from the old worker' => ($PIDsB[1] ?? 0) === ($PIDs[1] ?? -1),
            'and is closed once its stream is done' => $endedB,
         ];
      });
      $Observed['the recycle forked a replacement'] = str_contains($output, 'recycling, forking its replacement');
      $Observed['no crash logged'] = str_contains($output, 'crashed') === false && str_contains($output, 'Uncaught') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'HTTP/2: a recycling worker sends one GOAWAY, refuses later streams without churn, and keeps each connection for its open streams')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'trigger answered, one GOAWAY(3, NO_ERROR), then the in-flight stream' => true,
            'all 71 streams opened after the GOAWAY are refused (REFUSED_STREAM), none counted as churn' => true,
            'both answered by the old worker' => true,
            'the connection ends after' => true,
            'a connection with a body still arriving gets GOAWAY(1), then its answer' => true,
            'from the old worker' => true,
            'and is closed once its stream is done' => true,
            'master alive' => true,
            'the recycle forked a replacement' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ WebSocket — an open session across the recycle
      [$Observed, $output, $clean] = $Run('ws', 3, static function (int $port, Closure $Read, bool $up): array {
         /**
          * Upgrade a new connection; its worker PID (from the greeting) or 0.
          *
          * @return array{0:resource|false,1:int}
          */
         $Upgrade = static function () use ($port): array {
            $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
            if ($Client === false) {
               return [false, 0];
            }
            stream_set_timeout($Client, 3);
            @fwrite($Client, "GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
            $head = '';
            while (str_contains($head, "\r\n\r\n") === false) {
               $byte = @fread($Client, 1);
               if ($byte === '' || $byte === false) {
                  return [$Client, 0];
               }
               $head .= $byte;
            }
            // ! Server frames are unmasked; the greeting is short
            $frame = (string) @fread($Client, 2);
            $length = strlen($frame) === 2 ? ord($frame[1]) & 0x7f : 0;
            $payload = $length > 0 ? (string) @fread($Client, $length) : '';

            return [$Client, str_starts_with($payload, 'worker:') ? (int) substr($payload, 7) : 0];
         };

         // # Connection 2 (the probe was 1): an open session
         [$Open, $old] = $Upgrade();
         // # Connection 3: the recycle — accepted, then never upgraded
         $Trigger = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);

         // # The open session is closed with 1001
         $close = 0;
         if ($Open !== false) {
            $frame = (string) @fread($Open, 2);
            if (strlen($frame) === 2 && (ord($frame[0]) & 0x0f) === 0x8) {
               $payload = (string) @fread($Open, ord($frame[1]) & 0x7f);
               $close = strlen($payload) >= 2 ? (int) unpack('n', $payload)[1] : -1;
            }
         }
         // # A new client upgrades on the replacement
         [$New, $fresh] = $Upgrade();
         foreach ([$Open, $Trigger, $New] as $Client) {
            if (is_resource($Client)) {
               fclose($Client);
            }
         }
         $Read();

         return [
            'server up' => $up,
            'the open session was upgraded' => $old > 0,
            'it is closed with 1001 Going Away' => $close === 1001,
            'a new client upgrades on the replacement' => $fresh > 0 && $fresh !== $old,
         ];
      });
      $Observed['the drain says why'] = str_contains($output, 'connections reached its limit of 3');
      $Observed['no crash logged'] = str_contains($output, 'crashed') === false && str_contains($output, 'Uncaught') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'WebSocket: a recycling worker closes its sessions with 1001; new clients reach the replacement')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'the open session was upgraded' => true,
            'it is closed with 1001 Going Away' => true,
            'a new client upgrades on the replacement' => true,
            'master alive' => true,
            'the drain says why' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
