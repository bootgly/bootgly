<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * H3 regression — decoded request heads that withhold END_STREAM have an
 * absolute lifetime independent of transport activity and body progress.
 *
 * Six live h2c legs prove:
 *  1. the reactor timer expires a head without any new peer frame;
 *  2. repeated PING/ACK traffic does not move the deadline;
 *  3. server-processed one-byte DATA progress does not move it; and
 *  4. a graceful peer GOAWAY does not disarm an existing deadline;
 *  5. fragmented trailers completed before expiry dispatch normally; and
 *  6. fragmented trailers crossing expiry fail the connection atomically
 *     without leaving a stale CONTINUATION state or killing the worker.
 *
 * The first leg also observes the exact logical head reservation, its exact
 * release, and successful reuse of the same connection after the RST_STREAM.
 */
$probe = [
   'error' => '',
   'token' => '',
   'setup' => [],
   'cap' => [],
   'cap_released' => [],
   'retained' => [],
   'released' => [],
   'timer' => [],
   'ping' => [],
   'data' => [],
   'data_retained' => [],
   'data_released' => [],
   'goaway' => [],
   'goaway_released' => [],
   'fragment_control' => [],
   'fragment_control_released' => [],
   'fragment_retained' => [],
   'fragment_expired' => [],
   'fragment' => [],
   'fragment_health' => [],
   'fragment_released' => [],
   'worker_state' => '',
   'worker_terminated' => false,
];
$worker = [
   'original' => null,
   'original_pending' => null,
   'baseline' => 0,
];

return new Test(
   description: 'HTTP/2 incomplete request deadline must be absolute and fragment-safe',

   request: static function (string $hostPort, int $testIndex) use (
      &$probe,
   ): string {
      $token = bin2hex(random_bytes(8));
      $probe['token'] = $token;
      $Sockets = [];

      $Write = static function ($Socket, string $wire): void {
         $offset = 0;
         $length = strlen($wire);
         while ($offset < $length) {
            $written = @fwrite($Socket, substr($wire, $offset));
            if ($written === false) {
               throw new RuntimeException('HTTP/2 fixture write failed.');
            }
            if ($written === 0) {
               $read = [];
               $write = [$Socket];
               $except = [];
               if (@stream_select($read, $write, $except, 0, 200000) !== 1) {
                  throw new RuntimeException('HTTP/2 fixture write made no progress.');
               }
               continue;
            }
            $offset += $written;
         }
      };

      $Read = static function ($Socket, float $seconds): string {
         $wire = '';
         $deadline = microtime(true) + $seconds;
         stream_set_blocking($Socket, false);

         while (microtime(true) < $deadline) {
            $remaining = max(0.0, $deadline - microtime(true));
            $secondsPart = (int) $remaining;
            $microseconds = (int) (($remaining - $secondsPart) * 1_000_000);
            $read = [$Socket];
            $write = [];
            $except = [];
            $ready = @stream_select(
               $read,
               $write,
               $except,
               $secondsPart,
               $microseconds
            );
            if ($ready === false) {
               throw new RuntimeException('HTTP/2 fixture read selector failed.');
            }
            if ($ready === 0) {
               break;
            }

            $chunk = @fread($Socket, 65535);
            if ($chunk === false) {
               throw new RuntimeException('HTTP/2 fixture read failed.');
            }
            if ($chunk === '') {
               if (@feof($Socket)) {
                  break;
               }
               continue;
            }
            $wire .= $chunk;
         }

         return $wire;
      };

      $Walk = static function (string $wire): array {
         $result = [
            'frames' => 0,
            'resets' => [],
            'pings' => [],
            'goaways' => [],
            'data' => '',
         ];
         $offset = 0;
         $length = strlen($wire);
         while ($offset + 9 <= $length) {
            $header = substr($wire, $offset, 9);
            $size = (ord($header[0]) << 16)
               | (ord($header[1]) << 8)
               | ord($header[2]);
            if ($size > 0x1000000 || $offset + 9 + $size > $length) {
               break;
            }

            $type = ord($header[3]);
            $flags = ord($header[4]);
            /** @var array{1:int} $streamParts */
            $streamParts = unpack('N', substr($header, 5, 4));
            $stream = $streamParts[1] & 0x7fffffff;
            $payload = substr($wire, $offset + 9, $size);
            $result['frames']++;

            if ($type === HTTP2::FRAME_RST_STREAM && $size === 4) {
               /** @var array{1:int} $errorParts */
               $errorParts = unpack('N', $payload);
               $result['resets'][$stream][] = $errorParts[1];
            }
            else if (
               $type === HTTP2::FRAME_PING
               && ($flags & HTTP2::FLAG_ACK) !== 0
               && $size === 8
            ) {
               $result['pings'][] = bin2hex($payload);
            }
            else if ($type === HTTP2::FRAME_GOAWAY && $size >= 8) {
               /** @var array{1:int,2:int} $goawayParts */
               $goawayParts = unpack('N2', substr($payload, 0, 8));
               $result['goaways'][] = $goawayParts[2];
            }
            else if ($type === HTTP2::FRAME_DATA) {
               $result['data'] .= $payload;
            }

            $offset += 9 + $size;
         }

         return $result;
      };

      $Call = static function (string $path) use (
         $hostPort,
         $testIndex,
         $token,
         $Read,
      ): array {
         $Socket = @stream_socket_client(
            "tcp://{$hostPort}",
            $errorNumber,
            $errorMessage,
            timeout: 3
         );
         if (! is_resource($Socket)) {
            throw new RuntimeException(
               "HTTP fixture connect failed: {$errorNumber} {$errorMessage}"
            );
         }

         stream_set_timeout($Socket, 3);
         $request = "GET {$path} HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "X-H3-Test-Index: {$testIndex}\r\n"
            . "X-H3-Token: {$token}\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n";
         if (@fwrite($Socket, $request) !== strlen($request)) {
            @fclose($Socket);
            throw new RuntimeException('HTTP fixture request write failed.');
         }

         $response = $Read($Socket, 0.75);
         @fclose($Socket);

         $separator = strpos($response, "\r\n\r\n");
         $decoded = $separator === false
            ? null
            : json_decode(substr($response, $separator + 4), true);
         if (! is_array($decoded)) {
            throw new RuntimeException("Invalid HTTP fixture response for {$path}.");
         }

         return $decoded;
      };

      $Open = static function () use ($hostPort, $Write, $Read, &$Sockets): array {
         $Socket = @stream_socket_client(
            "tcp://{$hostPort}",
            $errorNumber,
            $errorMessage,
            timeout: 3
         );
         if (! is_resource($Socket)) {
            throw new RuntimeException(
               "h2c connect failed: {$errorNumber} {$errorMessage}"
            );
         }
         $Sockets[] = $Socket;
         stream_set_timeout($Socket, 2);
         $Write(
            $Socket,
            "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"
               . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
         );

         return [$Socket, $Read($Socket, 0.25)];
      };

      $Fields = static function (
         string $token,
         string $path = '/h3-deadline-hold',
      ): array {
         return [
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', $path],
            [':authority', 'localhost'],
            ['x-h3-token', $token],
         ];
      };

      $FragmentFields = static function (
         string $token,
         int $testIndex,
      ): array {
         return [
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', '/h3-deadline-fragment-ok'],
            [':authority', 'localhost'],
            ['content-length', '0'],
            ['x-h3-token', $token],
            ['x-bootgly-test', (string) $testIndex],
         ];
      };

      try {
         $probe['setup'] = $Call('/h3-deadline-setup');

         // # Per-connection exact cap: one head fits; a second equal head
         //   forces an explicit pressure GOAWAY and releases the first.
         [$CapSocket, $capWire] = $Open();
         $capHead = Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS,
            1,
            HPACK::encode($Fields($token))
         );
         $Write($CapSocket, $capHead);
         $capWire .= $Read($CapSocket, 0.12);
         $capFirst = $Walk($capWire);
         $Write(
            $CapSocket,
            Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS,
               3,
               HPACK::encode($Fields($token))
            )
         );
         $capWire .= $Read($CapSocket, 0.5);
         $probe['cap'] = [
            'first' => $capFirst,
            'final' => $Walk($capWire),
         ];
         @fclose($CapSocket);
         $probe['cap_released'] = $Call('/h3-deadline-snapshot');

         // # No-input leg: only the reactor timer can produce this reset.
         [$TimerSocket, $timerWire] = $Open();
         $fields = $Fields($token);
         $head = Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS,
            1,
            HPACK::encode($fields)
         );
         $started = hrtime(true);
         $Write($TimerSocket, $head);
         usleep(80000);
         $probe['retained'] = $Call('/h3-deadline-snapshot');
         $timerLimit = microtime(true) + 2.7;
         while (microtime(true) < $timerLimit) {
            $timerWire .= $Read($TimerSocket, 0.05);
            $state = $Walk($timerWire);
            if (
               in_array(
                  Errors::EnhanceYourCalm->value,
                  $state['resets'][1] ?? [],
                  true
               )
            ) {
               break;
            }
         }
         $elapsed = (hrtime(true) - $started) / 1_000_000;

         // @ The stream-level RST must not kill the connection.
         $ping = 'timer-ok';
         $Write(
            $TimerSocket,
            Frame::pack(HTTP2::FRAME_PING, 0, 0, $ping)
               . Frame::pack(
                  HTTP2::FRAME_HEADERS,
                  HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
                  3,
                  HPACK::encode([
                     [':method', 'GET'],
                     [':scheme', 'http'],
                     [':path', '/h3-deadline-ok'],
                     [':authority', 'localhost'],
                     ['x-bootgly-test', (string) $testIndex],
                  ])
               )
         );
         $timerWire .= $Read($TimerSocket, 0.8);
         $probe['timer'] = [
            ...$Walk($timerWire),
            'elapsed_ms' => $elapsed,
         ];
         @fclose($TimerSocket);
         $probe['released'] = $Call('/h3-deadline-snapshot');

         // # PING leg: ACK writes and decode activity cannot move the clock.
         [$PingSocket, $pingWire] = $Open();
         $Write(
            $PingSocket,
            Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS,
               1,
               HPACK::encode($Fields($token))
            )
         );
         $pingStarted = hrtime(true);
         $pingWrites = 0;
         for ($tick = 0; $tick < 16; $tick++) {
            $payload = pack('N2', 0x48335049, $tick);
            $Write($PingSocket, Frame::pack(HTTP2::FRAME_PING, 0, 0, $payload));
            $pingWrites++;
            $pingWire .= $Read($PingSocket, 0.08);
            $state = $Walk($pingWire);
            if (
               in_array(
                  Errors::EnhanceYourCalm->value,
                  $state['resets'][1] ?? [],
                  true
               )
            ) {
               break;
            }
            usleep(80000);
         }
         $probe['ping'] = [
            ...$Walk($pingWire),
            'writes' => $pingWrites,
            'elapsed_ms' => (hrtime(true) - $pingStarted) / 1_000_000,
         ];
         @fclose($PingSocket);

         // # DATA leg: real body progress is still bounded by the same
         //   absolute ceiling; it is not an inactivity timeout.
         [$DataSocket, $dataWire] = $Open();
         $Write(
            $DataSocket,
            Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS,
               1,
               HPACK::encode($Fields($token))
            )
         );
         $dataStarted = hrtime(true);
         $dataWrites = 1;
         $dataBarrier = 'data-one';
         $Write(
            $DataSocket,
            Frame::pack(HTTP2::FRAME_DATA, 0, 1, 'D')
               . Frame::pack(HTTP2::FRAME_PING, 0, 0, $dataBarrier)
         );
         $barrierLimit = microtime(true) + 0.75;
         while (microtime(true) < $barrierLimit) {
            $dataWire .= $Read($DataSocket, 0.05);
            if (
               in_array(
                  bin2hex($dataBarrier),
                  $Walk($dataWire)['pings'],
                  true
               )
            ) {
               break;
            }
         }
         $probe['data_retained'] = $Call('/h3-deadline-snapshot');

         for ($tick = 1; $tick < 24; $tick++) {
            $Write(
               $DataSocket,
               Frame::pack(HTTP2::FRAME_DATA, 0, 1, 'D')
            );
            $dataWrites++;
            $dataWire .= $Read($DataSocket, 0.08);
            $state = $Walk($dataWire);
            if (
               in_array(
                  Errors::EnhanceYourCalm->value,
                  $state['resets'][1] ?? [],
                  true
               )
            ) {
               break;
            }
            usleep(80000);
         }
         $probe['data'] = [
            ...$Walk($dataWire),
            'writes' => $dataWrites,
            'elapsed_ms' => (hrtime(true) - $dataStarted) / 1_000_000,
         ];
         @fclose($DataSocket);
         $probe['data_released'] = $Call('/h3-deadline-snapshot');

         // # A graceful peer GOAWAY forbids new Streams, but must not cancel
         //   the already-armed deadline of an existing incomplete request.
         [$GoawaySocket, $goawayWire] = $Open();
         $goawayStarted = hrtime(true);
         $Write(
            $GoawaySocket,
            Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS,
               1,
               HPACK::encode($Fields($token))
            )
               . Frame::pack(
                  HTTP2::FRAME_GOAWAY,
                  0,
                  0,
                  pack('NN', 1, Errors::None->value)
               )
         );
         $goawayWire .= $Read($GoawaySocket, 2.4);
         $probe['goaway'] = [
            ...$Walk($goawayWire),
            'elapsed_ms' => (hrtime(true) - $goawayStarted) / 1_000_000,
         ];
         @fclose($GoawaySocket);
         $probe['goaway_released'] = $Call('/h3-deadline-snapshot');

         // # Fragment control: completing trailing headers before the
         //   request deadline must synchronize HPACK and dispatch normally.
         $fragmentFields = $FragmentFields($token, $testIndex);
         $fragmentBlock = HPACK::encode([['x-h3-trailer', 'accepted']]);
         $fragmentPrefix = substr($fragmentBlock, 0, -1);
         $fragmentSuffix = substr($fragmentBlock, -1);

         [$ControlSocket, $controlWire] = $Open();
         $Write(
            $ControlSocket,
            Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS,
               1,
               HPACK::encode($fragmentFields)
            )
               . Frame::pack(
                  HTTP2::FRAME_HEADERS,
                  HTTP2::FLAG_END_STREAM,
                  1,
                  $fragmentPrefix
               )
         );
         $controlFinal = Frame::pack(
            HTTP2::FRAME_CONTINUATION,
            HTTP2::FLAG_END_HEADERS,
            1,
            $fragmentSuffix
         );
         $Write($ControlSocket, substr($controlFinal, 0, -1));
         usleep(20_000);
         $Write($ControlSocket, substr($controlFinal, -1));
         $controlWire .= $Read($ControlSocket, 1.0);
         $probe['fragment_control'] = $Walk($controlWire);
         @fclose($ControlSocket);
         $probe['fragment_control_released'] = $Call('/h3-deadline-snapshot');

         // # Fragment attack: expire while a valid trailing header block is
         //   awaiting CONTINUATION, then attempt its final byte in a separate
         //   TCP read. The safe policy is connection-fatal because HPACK is
         //   connection state and the peer may withhold that byte forever.
         [$FragmentSocket, $fragmentWire] = $Open();
         $fragmentStarted = hrtime(true);
         $Write(
            $FragmentSocket,
            Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS,
               1,
               HPACK::encode($fragmentFields)
            )
               . Frame::pack(
                  HTTP2::FRAME_HEADERS,
                  HTTP2::FLAG_END_STREAM,
                  1,
                  $fragmentPrefix
               )
         );
         usleep(80_000);
         $probe['fragment_retained'] = $Call('/h3-deadline-snapshot');

         $fragmentLimit = microtime(true) + 2.7;
         while (microtime(true) < $fragmentLimit) {
            $fragmentWire .= $Read($FragmentSocket, 0.05);
            $state = $Walk($fragmentWire);
            if (
               in_array(
                  Errors::EnhanceYourCalm->value,
                  $state['resets'][1] ?? [],
                  true
               )
               || in_array(
                  Errors::EnhanceYourCalm->value,
                  $state['goaways'],
                  true
               )
               || @feof($FragmentSocket)
            ) {
               break;
            }
         }
         $fragmentElapsed = (hrtime(true) - $fragmentStarted) / 1_000_000;
         $probe['fragment_expired'] = $Call('/h3-deadline-snapshot');

         $fragmentFinal = Frame::pack(
            HTTP2::FRAME_CONTINUATION,
            HTTP2::FLAG_END_HEADERS,
            1,
            $fragmentSuffix
         );
         $finalAttempted = true;
         $finalWritten = false;
         $finalError = '';
         try {
            $Write($FragmentSocket, substr($fragmentFinal, 0, -1));
            usleep(20_000);
            $Write($FragmentSocket, substr($fragmentFinal, -1));
            $finalWritten = true;
         }
         catch (Throwable $Throwable) {
            $finalError = $Throwable::class . ': ' . $Throwable->getMessage();
         }
         $fragmentWire .= $Read($FragmentSocket, 0.6);
         $probe['fragment'] = [
            ...$Walk($fragmentWire),
            'elapsed_ms' => $fragmentElapsed,
            'eof' => @feof($FragmentSocket),
            'final_attempted' => $finalAttempted,
            'final_written' => $finalWritten,
            'final_error' => $finalError,
            'fragment_bytes' => strlen($fragmentPrefix),
         ];
         @fclose($FragmentSocket);

         // @ A worker-fatal decoder Error is directly observable through its
         //   kernel PID identity. The suite uses exactly one worker.
         $workerPID = $probe['setup']['pid'] ?? 0;
         $deathLimit = microtime(true) + 1.5;
         do {
            $processStatus = is_int($workerPID) && $workerPID > 0
               ? @file_get_contents("/proc/{$workerPID}/status")
               : false;
            if ($processStatus === false) {
               $probe['worker_state'] = 'absent';
               $probe['worker_terminated'] = true;
               break;
            }
            if (preg_match('/^State:\s+([A-Z])/m', $processStatus, $matches) === 1) {
               $probe['worker_state'] = $matches[1];
               if ($matches[1] === 'Z' || $matches[1] === 'X') {
                  $probe['worker_terminated'] = true;
                  break;
               }
            }
            if (
               function_exists('posix_kill')
               && @posix_kill($workerPID, 0) === false
            ) {
               $probe['worker_state'] = 'unreachable';
               $probe['worker_terminated'] = true;
               break;
            }
            usleep(10_000);
         }
         while (microtime(true) < $deathLimit);

         if ($probe['worker_terminated'] === false) {
            [$HealthSocket, $healthWire] = $Open();
            $healthPing = 'frag-ok!';
            $Write(
               $HealthSocket,
               Frame::pack(HTTP2::FRAME_PING, 0, 0, $healthPing)
                  . Frame::pack(
                     HTTP2::FRAME_HEADERS,
                     HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
                     1,
                     HPACK::encode([
                        [':method', 'GET'],
                        [':scheme', 'http'],
                        [':path', '/h3-deadline-ok'],
                        [':authority', 'localhost'],
                        ['x-bootgly-test', (string) $testIndex],
                     ])
                  )
            );
            $healthWire .= $Read($HealthSocket, 0.8);
            $probe['fragment_health'] = $Walk($healthWire);
            @fclose($HealthSocket);
            $probe['fragment_released'] = $Call('/h3-deadline-snapshot');
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
      }

      return "GET /h3-deadline-cleanup HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "X-H3-Token: {$token}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use (&$worker) {
      $Snapshot = static function (string $token): array {
         $tagged = 0;
         $head = 0;
         $body = 0;
         $bodyLedger = 0;
         $fragmentLedger = 0;
         foreach (Connections::$Connections as $Connection) {
            $Decoder = $Connection->Decoder;
            if (! $Decoder instanceof Decoder_HTTP2) {
               continue;
            }
            $fragmentLedger += $Decoder->Buffers->retained;
            $bodyLedger += $Decoder->Bodies->retained;
            foreach ($Decoder->Streams as $Stream) {
               if (($Stream->fields['x-h3-token'] ?? null) !== $token) {
                  continue;
               }
               $tagged++;
               $head += $Stream->HeadBuffers->retained;
               $body += strlen($Stream->body);
            }
         }

         return [
            'pid' => getmypid(),
            'pending' => TCP_Server_CLI::$pendingBytes,
            'tagged' => $tagged,
            'head' => $head,
            'body' => $body,
            'body_ledger' => $bodyLedger,
            'fragment_ledger' => $fragmentLedger,
         ];
      };

      yield $Router->route('/h3-deadline-setup', static function (
         Request $Request,
         Response $Response,
      ) use (&$worker): Response {
         $worker['original'] ??= Decoder_HTTP2::$maxRequestWallTime;
         $worker['original_pending'] ??= TCP_Server_CLI::$maxPendingBytes;
         $worker['baseline'] = TCP_Server_CLI::$pendingBytes;
         Decoder_HTTP2::$maxRequestWallTime = 2;
         $token = $Request->Header->get('X-H3-Token') ?? '';
         // @ The test router consumes its own X-Bootgly-Test selector before
         //   route dispatch. Keep an independent copy for exact head pricing.
         $testIndex = $Request->Header->get('X-H3-Test-Index') ?? '';
         $fields = [
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', '/h3-deadline-fragment-ok'],
            [':authority', 'localhost'],
            ['content-length', '0'],
            ['x-h3-token', $token],
            ['x-bootgly-test', $testIndex],
         ];
         $list = 0;
         foreach ($fields as [$name, $value]) {
            $list += strlen($name) + strlen($value) + 32;
         }
         $fragment = HPACK::encode([['x-h3-trailer', 'accepted']]);
         $fragmentCharge = 2 * $list + count($fields) * 384 + 1024;
         $fragmentBytes = strlen($fragment) - 1;
         TCP_Server_CLI::$maxPendingBytes = $fragmentCharge + $fragmentBytes;

         return $Response->JSON->send([
            'pid' => getmypid(),
            'deadline' => Decoder_HTTP2::$maxRequestWallTime,
            'baseline' => $worker['baseline'],
            'pending_cap' => TCP_Server_CLI::$maxPendingBytes,
            'fragment_head_charge' => $fragmentCharge,
            'fragment_bytes' => $fragmentBytes,
         ]);
      }, GET);

      yield $Router->route('/h3-deadline-snapshot', static function (
         Request $Request,
         Response $Response,
      ) use ($Snapshot): Response {
         $token = $Request->Header->get('X-H3-Token') ?? '';

         return $Response->JSON->send($Snapshot($token));
      }, GET);

      yield $Router->route('/h3-deadline-ok', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'H3-DEADLINE-OK');
      }, GET);

      yield $Router->route('/h3-deadline-fragment-ok', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'H3-FRAGMENT-CONTROL');
      }, POST);

      yield $Router->route('/h3-deadline-cleanup', static function (
         Request $Request,
         Response $Response,
      ) use (&$worker, $Snapshot): Response {
         $token = $Request->Header->get('X-H3-Token') ?? '';
         if (is_int($worker['original'])) {
            Decoder_HTTP2::$maxRequestWallTime = $worker['original'];
         }
         if (is_int($worker['original_pending'])) {
            TCP_Server_CLI::$maxPendingBytes = $worker['original_pending'];
         }

         return $Response->JSON->send([
            ...$Snapshot($token),
            'restored' => Decoder_HTTP2::$maxRequestWallTime,
            'restored_pending' => TCP_Server_CLI::$maxPendingBytes,
            'baseline' => $worker['baseline'],
         ]);
      }, GET);
   },

   test: static function (string $response) use (&$probe): bool|string {
      $separator = strpos($response, "\r\n\r\n");
      $cleanup = $separator === false
         ? null
         : json_decode(substr($response, $separator + 4), true);
      if ($probe['error'] !== '') {
         Vars::$labels = ['H3 deadline fixture'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H3 deadline fixture error: ' . $probe['error'];
      }

      $baseline = $probe['setup']['baseline'] ?? -1;
      $setupPID = $probe['setup']['pid'] ?? 0;
      $fragmentControl = $probe['fragment_control'];
      $fragmentControlReleased = $probe['fragment_control_released'];
      $fragmentControlSafe =
         is_int($setupPID)
         && $setupPID > 0
         && str_contains(
            $fragmentControl['data'] ?? '',
            'H3-FRAGMENT-CONTROL'
         )
         && ($fragmentControl['resets'] ?? null) === []
         && ($fragmentControl['goaways'] ?? null) === []
         && ($fragmentControlReleased['pid'] ?? 0) === $setupPID
         && ($fragmentControlReleased['tagged'] ?? -1) === 0
         && ($fragmentControlReleased['head'] ?? -1) === 0
         && ($fragmentControlReleased['body_ledger'] ?? -1) === 0
         && ($fragmentControlReleased['fragment_ledger'] ?? -1) === 0
         && ($fragmentControlReleased['pending'] ?? -1) === $baseline;
      if ($fragmentControlSafe === false) {
         Vars::$labels = ['H3 fragmented-trailer positive control'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H3 fragmented-trailer fixture did not dispatch its valid '
            . 'pre-expiry control: '
            . (string) json_encode([
               'setup' => $probe['setup'],
               'control' => $fragmentControl,
               'released' => $fragmentControlReleased,
            ], JSON_UNESCAPED_SLASHES);
      }

      if ($probe['worker_terminated'] === true) {
         Vars::$labels = ['H3 fragmented-trailer deadline confirmation'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'CONFIRMED H3: the post-deadline final CONTINUATION '
            . "terminated serving worker PID {$setupPID}; "
            . 'the valid pre-expiry fragmented-trailer control dispatched '
            . 'normally, excluding a fixture or HPACK failure.';
      }
      if (! is_array($cleanup)) {
         return 'H3 deadline cleanup response was not valid JSON.';
      }

      $fields = [
         [':method', 'POST'],
         [':scheme', 'http'],
         [':path', '/h3-deadline-hold'],
         [':authority', 'localhost'],
         ['x-h3-token', $probe['token']],
      ];
      $list = 0;
      foreach ($fields as [$name, $value]) {
         $list += strlen($name) + strlen($value) + 32;
      }
      $charge = 2 * $list + count($fields) * 384 + 1024;
      $cap = $probe['cap'];
      $capReleased = $probe['cap_released'];
      $retained = $probe['retained'];
      $released = $probe['released'];
      $timer = $probe['timer'];
      $ping = $probe['ping'];
      $data = $probe['data'];
      $dataRetained = $probe['data_retained'];
      $dataReleased = $probe['data_released'];
      $goaway = $probe['goaway'];
      $goawayReleased = $probe['goaway_released'];
      $fragmentRetained = $probe['fragment_retained'];
      $fragmentExpired = $probe['fragment_expired'];
      $fragment = $probe['fragment'];
      $fragmentHealth = $probe['fragment_health'];
      $fragmentReleased = $probe['fragment_released'];
      $fragmentHeadCharge = $probe['setup']['fragment_head_charge'] ?? -1;
      $fragmentBytes = $probe['setup']['fragment_bytes'] ?? -1;

      $timerCalm = in_array(
         Errors::EnhanceYourCalm->value,
         $timer['resets'][1] ?? [],
         true
      );
      $pingCalm = in_array(
         Errors::EnhanceYourCalm->value,
         $ping['resets'][1] ?? [],
         true
      );
      $dataCalm = in_array(
         Errors::EnhanceYourCalm->value,
         $data['resets'][1] ?? [],
         true
      );
      $goawayCalm = in_array(
         Errors::EnhanceYourCalm->value,
         $goaway['resets'][1] ?? [],
         true
      );
      $fragmentCalm = in_array(
         Errors::EnhanceYourCalm->value,
         $fragment['goaways'] ?? [],
         true
      );
      $fragmentRST = in_array(
         Errors::EnhanceYourCalm->value,
         $fragment['resets'][1] ?? [],
         true
      );
      $fragmentRetainedExact =
         $fragmentHeadCharge > 0
         && $fragmentBytes > 0
         && ($fragmentRetained['pid'] ?? 0) === $setupPID
         && ($fragmentRetained['tagged'] ?? -1) === 1
         && ($fragmentRetained['head'] ?? -1) === $fragmentHeadCharge
         && ($fragmentRetained['body'] ?? -1) === 0
         && ($fragmentRetained['body_ledger'] ?? -1) === 0
         && ($fragmentRetained['fragment_ledger'] ?? -1) === $fragmentBytes
         && ($fragmentRetained['pending'] ?? -1)
            === $baseline + $fragmentHeadCharge + $fragmentBytes;
      $fragmented =
         $fragmentRetainedExact
         && $fragmentCalm
         && ($fragment['eof'] ?? false) === true
         && ($fragment['final_attempted'] ?? false) === true
         && ($fragment['fragment_bytes'] ?? -1) === $fragmentBytes
         && ($fragment['elapsed_ms'] ?? 0.0) >= 1600.0
         && ($fragment['elapsed_ms'] ?? PHP_FLOAT_MAX) < 3200.0
         && ($fragmentExpired['pid'] ?? 0) === $setupPID
         && ($fragmentExpired['tagged'] ?? -1) === 0
         && ($fragmentExpired['head'] ?? -1) === 0
         && ($fragmentExpired['body'] ?? -1) === 0
         && ($fragmentExpired['body_ledger'] ?? -1) === 0
         && ($fragmentExpired['fragment_ledger'] ?? -1) === 0
         && ($fragmentExpired['pending'] ?? -1) === $baseline
         && in_array(
            bin2hex('frag-ok!'),
            $fragmentHealth['pings'] ?? [],
            true
         )
         && str_contains(
            $fragmentHealth['data'] ?? '',
            'H3-DEADLINE-OK'
         )
         && ($fragmentHealth['goaways'] ?? []) === []
         && ($fragmentReleased['pid'] ?? 0) === $setupPID
         && ($fragmentReleased['tagged'] ?? -1) === 0
         && ($fragmentReleased['head'] ?? -1) === 0
         && ($fragmentReleased['body'] ?? -1) === 0
         && ($fragmentReleased['body_ledger'] ?? -1) === 0
         && ($fragmentReleased['fragment_ledger'] ?? -1) === 0
         && ($fragmentReleased['pending'] ?? -1) === $baseline;

      if ($fragmentRetainedExact && $fragmentRST && $fragmentCalm === false) {
         Vars::$labels = ['H3 fragmented-trailer deadline confirmation'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'CONFIRMED H3: a request deadline expired while its trailing '
            . 'header block awaited CONTINUATION, but the connection remained '
            . 'open with stale HPACK transition state instead of failing '
            . 'atomically.';
      }
      $healthy =
         in_array(bin2hex('timer-ok'), $timer['pings'] ?? [], true)
         && str_contains($timer['data'] ?? '', 'H3-DEADLINE-OK')
         && ($timer['goaways'] ?? []) === [];
      $bounded =
         ($probe['setup']['pending_cap'] ?? -1)
            === $fragmentHeadCharge + $fragmentBytes
         && ($cap['first']['resets'] ?? null) === []
         && ($cap['first']['goaways'] ?? null) === []
         && in_array(
            Errors::EnhanceYourCalm->value,
            $cap['final']['goaways'] ?? [],
            true
         )
         && ($capReleased['tagged'] ?? -1) === 0
         && ($capReleased['head'] ?? -1) === 0
         && ($capReleased['fragment_ledger'] ?? -1) === 0
         && ($capReleased['pending'] ?? -1) === $baseline;
      $exact =
         ($probe['setup']['deadline'] ?? 0) === 2
         && $baseline >= 0
         && ($retained['tagged'] ?? -1) === 1
         && ($retained['head'] ?? -1) === $charge
         && ($retained['pending'] ?? -1) === $baseline + $charge
         && ($retained['body'] ?? -1) === 0
         && ($released['tagged'] ?? -1) === 0
         && ($released['head'] ?? -1) === 0
         && ($released['body_ledger'] ?? -1) === 0
         && ($released['fragment_ledger'] ?? -1) === 0
         && ($released['pending'] ?? -1) === $baseline
         && ($cleanup['tagged'] ?? -1) === 0
         && ($cleanup['head'] ?? -1) === 0
         && ($cleanup['body_ledger'] ?? -1) === 0
         && ($cleanup['fragment_ledger'] ?? -1) === 0
         && ($cleanup['pending'] ?? -1) === $baseline
         && ($cleanup['pid'] ?? 0) === $setupPID
         && ($cleanup['restored'] ?? 0) > 2
         && ($cleanup['restored_pending'] ?? 0) > $fragmentHeadCharge;
      $absolute =
         $timerCalm
         && ($timer['elapsed_ms'] ?? 0.0) >= 1600.0
         && ($timer['elapsed_ms'] ?? PHP_FLOAT_MAX) < 3200.0
         && $pingCalm
         && ($ping['writes'] ?? 0) >= 4
         && count($ping['pings'] ?? []) >= 3
         && ($ping['elapsed_ms'] ?? 0.0) >= 1600.0
         && ($ping['elapsed_ms'] ?? PHP_FLOAT_MAX) < 3200.0
         && $dataCalm
         && ($data['writes'] ?? 0) >= 4
         && in_array(bin2hex('data-one'), $data['pings'] ?? [], true)
         && ($dataRetained['tagged'] ?? -1) === 1
         && ($dataRetained['head'] ?? -1) === $charge
         && ($dataRetained['pending'] ?? -1) === $baseline + $charge
         && ($dataRetained['body'] ?? -1) === 1
         && ($dataRetained['body_ledger'] ?? -1) === 1
         && ($dataRetained['fragment_ledger'] ?? -1) === 0
         && ($dataReleased['tagged'] ?? -1) === 0
         && ($dataReleased['head'] ?? -1) === 0
         && ($dataReleased['body'] ?? -1) === 0
         && ($dataReleased['body_ledger'] ?? -1) === 0
         && ($dataReleased['fragment_ledger'] ?? -1) === 0
         && ($dataReleased['pending'] ?? -1) === $baseline
         && ($data['elapsed_ms'] ?? 0.0) >= 1600.0
         && ($data['elapsed_ms'] ?? PHP_FLOAT_MAX) < 3200.0
         && $goawayCalm
         && ($goaway['elapsed_ms'] ?? 0.0) >= 1600.0
         && ($goaway['elapsed_ms'] ?? PHP_FLOAT_MAX) < 3200.0
         && ($goawayReleased['tagged'] ?? -1) === 0
         && ($goawayReleased['head'] ?? -1) === 0
         && ($goawayReleased['body_ledger'] ?? -1) === 0
         && ($goawayReleased['fragment_ledger'] ?? -1) === 0
         && ($goawayReleased['pending'] ?? -1) === $baseline;

      if (
         $bounded
         && $exact
         && $absolute
         && $healthy
         && $fragmentControlSafe
         && $fragmented
      ) {
         return true;
      }

      Vars::$labels = ['H3 absolute request deadline evidence'];
      dump(json_encode([
         ...$probe,
         'cleanup' => $cleanup,
         'expected_charge' => $charge,
         'bounded' => $bounded,
         'exact' => $exact,
         'absolute' => $absolute,
         'healthy' => $healthy,
         'fragment_control_safe' => $fragmentControlSafe,
         'fragmented' => $fragmented,
      ], JSON_UNESCAPED_SLASHES));

      return 'H3 deadline regression failed: the decoded head was not charged '
         . 'and released exactly, or its absolute timer was extended by idle, '
         . 'PING, one-byte DATA, or fragmented-trailer activity. '
         . (string) json_encode([
            'setup' => $probe['setup'],
            'cap' => $cap,
            'cap_released' => $capReleased,
            'retained' => $retained,
            'released' => $released,
            'timer' => $timer,
            'ping' => $ping,
            'data' => $data,
            'data_retained' => $dataRetained,
            'data_released' => $dataReleased,
            'goaway' => $goaway,
            'goaway_released' => $goawayReleased,
            'fragment_control' => $fragmentControl,
            'fragment_control_released' => $fragmentControlReleased,
            'fragment_retained' => $fragmentRetained,
            'fragment_expired' => $fragmentExpired,
            'fragment' => $fragment,
            'fragment_health' => $fragmentHealth,
            'fragment_released' => $fragmentReleased,
            'cleanup' => $cleanup,
            'expected_charge' => $charge,
            'fragment_head_charge' => $fragmentHeadCharge,
            'fragment_bytes' => $fragmentBytes,
            'bounded' => $bounded,
            'exact' => $exact,
            'absolute' => $absolute,
            'healthy' => $healthy,
            'fragment_control_safe' => $fragmentControlSafe,
            'fragmented' => $fragmented,
         ], JSON_UNESCAPED_SLASHES);
   },
);
