<?php


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Stream;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


if (! class_exists('HTTPServerCLIM1HPACKConnection', false)) {
   class HTTPServerCLIM1HPACKConnection extends Connection
   {
      /** @param resource $Socket */
      public function __construct (mixed &$Socket, int $port)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 0;
         $this->ip = '127.0.0.1';
         $this->port = $port;
         $this->encrypted = false;
         $this->handshaking = false;
         $this->handshakeTimer = 0;
         $this->status = Connections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 0;
      }

      // These socketless decoder probes do not own a selector registration.
      public function close (): true
      {
         $this->status = Connections::STATUS_CLOSED;

         return true;
      }
   }
}


/**
 * Security PoC M1 -- the persistent HPACK dynamic table must be included in
 * the HTTP worker retained-byte authority.
 *
 * A literal with incremental indexing mutates compression state before HTTP
 * semantics are validated. The attack block contains 128 empty-name/value
 * literals (`40 00 00`), exactly filling the 4,096-byte RFC table. The empty
 * name is then rejected as a stream error, but the connection must keep the
 * table synchronized if it remains live.
 *
 * Two production decoders receive the same 4,096-byte persistent table under
 * a 7,680-byte worker allowance. Secure behavior reserves a conservative table
 * capacity before mutation, then rejects every connection that cannot fit;
 * an implementation charging only RFC bytes may keep one synchronized table
 * live but must reject the other before their 8,192-byte aggregate exceeds the
 * authority. The current code keeps both live while
 * TCPServer::$pendingBytes remains unchanged. A valid incremental-index
 * insertion followed by an index-62 reuse proves that ordinary dynamic-table
 * compression works. After each malformed block, a PING ACK and a healthy
 * context-free request on the same decoder prove that reflection did not merely
 * inspect state on a connection that could no longer make protocol progress.
 */
$Probe = new class {
   public string $error = '';
   public int $workerAllowance = 7680;
   public int $capacityCharge = 58368;
   public int $entriesPerTable = 128;
   public int $bytesPerTable = 4096;
   public int $baseline = -1;
   /** @var array<string,mixed> */
   public array $control = [];
   /** @var array<string,mixed> */
   public array $failure = [];
   /** @var array<int,array<string,mixed>> */
   public array $attacks = [];
   /** @var array<string,mixed> */
   public array $aggregate = [];
   /** @var array<string,mixed> */
   public array $cleanup = [];
};

return new Test(
   description: 'HPACK dynamic tables must share the worker retained-byte budget',
   Separator: new Separator(line: true),

   request: static function () use ($Probe): string {
      $oldWorkerCap = TCPServer::$maxWorkerPendingBytes;
      $oldConnectionCap = TCPServer::$maxPendingBytes;
      $OldRequest = Server::$Request;

      /** @var array<int,resource> $Resources */
      $Resources = [];
      /** @var array<int,Decoder_HTTP2> $Decoders */
      $Decoders = [];
      /** @var array<int,TCPPackages> $Packages */
      $Packages = [];

      $Walk = static function (string $wire): array {
         $types = [];
         $resets = 0;
         $goaways = [];
         $pingACKs = [];
         $offset = 0;
         $length = strlen($wire);
         while ($length - $offset >= 9) {
            /** @var array{word:int,flags:int,stream:int}|false $head */
            $head = unpack('Nword/Cflags/Nstream', $wire, $offset);
            if ($head === false) {
               break;
            }
            $payload = $head['word'] >> 8;
            if ($length - $offset < 9 + $payload) {
               break;
            }

            $type = $head['word'] & 0xff;
            $flags = $head['flags'];
            $types[] = $type;
            if ($type === HTTP2::FRAME_RST_STREAM) {
               $resets++;
            }
            else if ($type === HTTP2::FRAME_GOAWAY) {
               if ($payload >= 8) {
                  /** @var array{last:int,error:int}|false $goaway */
                  $goaway = unpack('Nlast/Nerror', $wire, $offset + 9);
                  if ($goaway !== false) {
                     $goaways[] = [
                        'last' => $goaway['last'],
                        'error' => $goaway['error'],
                        'debug' => substr($wire, $offset + 17, $payload - 8),
                     ];
                  }
               }
            }
            else if (
               $type === HTTP2::FRAME_PING
               && ($flags & HTTP2::FLAG_ACK) !== 0
               && $payload === 8
            ) {
               $pingACKs[] = bin2hex(substr($wire, $offset + 9, 8));
            }
            $offset += 9 + $payload;
         }

         return [
            'types' => $types,
            'resets' => $resets,
            'goaways' => count($goaways),
            'goaway_details' => $goaways,
            'ping_acks' => $pingACKs,
            'wire_bytes' => $length,
            'parsed_bytes' => $offset,
         ];
      };

      $Inspect = static function (Decoder_HTTP2 $Decoder): array {
         $Reflection = new ReflectionObject($Decoder->HPACK);
         $DynamicProperty = $Reflection->getProperty('dynamic');
         $SizeProperty = $Reflection->getProperty('size');
         $Dynamic = $DynamicProperty->getValue($Decoder->HPACK);
         $size = $SizeProperty->getValue($Decoder->HPACK);

         return [
            'entries' => is_array($Dynamic) ? count($Dynamic) : -1,
            'bytes' => is_int($size) ? $size : -1,
         ];
      };

      $Reserved = static function (Decoder_HTTP2 $Decoder): int {
         if (property_exists($Decoder, 'HPACKBuffers') === false) {
            return 0;
         }

         return $Decoder->HPACKBuffers->retained;
      };

      $Build = static function (
         string $block,
         int $flags,
         int $port,
      ) use (&$Resources, &$Decoders, &$Packages, $Walk, $Inspect): array {
         $Socket = fopen('php://temp', 'w+b');
         if (! is_resource($Socket)) {
            throw new RuntimeException('Could not create the M1 HTTP/2 decoder stream.');
         }
         $Resources[] = $Socket;

         $Connection = new HTTPServerCLIM1HPACKConnection($Socket, $port);
         $Package = new class($Connection) extends TCPPackages {};
         $Packages[] = $Package;
         $Decoder = new Decoder_HTTP2;
         $Decoders[] = $Decoder;
         $Package->Decoder = $Decoder;
         $Package->decoded = $Decoder;

         $wire = HTTP2::PREFACE
            . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
            . Frame::pack(HTTP2::FRAME_HEADERS, $flags, 1, $block);
         $State = $Decoder->decode($Package, $wire, strlen($wire));

         rewind($Socket);
         $output = stream_get_contents($Socket);
         if (! is_string($output)) {
            throw new RuntimeException('Could not read the M1 HTTP/2 control output.');
         }

         return [
            'State' => $State,
            'Package' => $Package,
            'Connection' => $Connection,
            'Decoder' => $Decoder,
            'table' => $Inspect($Decoder),
            'output' => $Walk($output),
            'input_bytes' => strlen($wire),
            'block_bytes' => strlen($block),
         ];
      };

      $Continue = static function (
         array $Fixture,
         string $wire,
      ) use ($Walk, $Inspect): array {
         $Decoder = $Fixture['Decoder'] ?? null;
         $Package = $Fixture['Package'] ?? null;
         $Connection = $Fixture['Connection'] ?? null;
         if (
            ! $Decoder instanceof Decoder_HTTP2
            || ! $Package instanceof TCPPackages
            || ! $Connection instanceof Connection
            || ! is_resource($Connection->Socket)
         ) {
            throw new RuntimeException('M1 continuation received an invalid decoder fixture.');
         }

         $Socket = $Connection->Socket;
         if (fseek($Socket, 0, SEEK_END) !== 0) {
            throw new RuntimeException('Could not seek the M1 HTTP/2 output stream.');
         }
         $start = ftell($Socket);
         if (! is_int($start)) {
            throw new RuntimeException('Could not measure the M1 HTTP/2 output stream.');
         }

         $Package->consumed = 0;
         $State = $Decoder->decode($Package, $wire, strlen($wire));
         fflush($Socket);
         if (fseek($Socket, $start, SEEK_SET) !== 0) {
            throw new RuntimeException('Could not seek to the M1 continuation output.');
         }
         $output = stream_get_contents($Socket);
         if (! is_string($output)) {
            throw new RuntimeException('Could not read the M1 continuation output.');
         }

         return [
            'State' => $State,
            'table' => $Inspect($Decoder),
            'output' => $Walk($output),
            'input_bytes' => strlen($wire),
         ];
      };

      $Release = static function (array $Fixture): void {
         $Decoder = $Fixture['Decoder'] ?? null;
         $Package = $Fixture['Package'] ?? null;
         if ($Decoder instanceof Decoder_HTTP2) {
            $Decoder->disconnect();
            $Decoder->disconnect();
         }
         if ($Package instanceof TCPPackages) {
            $Package->Decoder = null;
            $Package->decoded = null;
         }
      };

      $Control = null;
      $Failure = null;
      /** @var array<int,array<string,mixed>> $Attacks */
      $Attacks = [];

      try {
         $Probe->baseline = TCPServer::$pendingBytes;
         // The functional HPACK control temporarily holds two decoded heads on
         // one connection because this socketless probe does not run an encoder.
         TCPServer::$maxWorkerPendingBytes = $Probe->baseline + 128 * 1024;
         TCPServer::$maxPendingBytes = 128 * 1024;

         // Positive control: insert one valid regular field with incremental
         // indexing, then reference dynamic index 62 from the next Stream.
         $indexedName = 'x-m1-indexed';
         $indexedValue = 'stored';
         $controlBlock = HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/m1-hpack-index-insert'],
            [':authority', 'localhost'],
         ])
            . "\x40"
            . chr(strlen($indexedName)) . $indexedName
            . chr(strlen($indexedValue)) . $indexedValue;
         $Control = $Build(
            $controlBlock,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            18401,
         );
         $InsertRequest = Server::$Request;
         $ControlDecoder = $Control['Decoder'];
         $ControlPackage = $Control['Package'];

         $reuseBlock = HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/m1-hpack-index-reuse'],
            [':authority', 'localhost'],
         ]) . "\xbe";
         $Reuse = $Continue(
            $Control,
            Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
               3,
               $reuseBlock,
            ),
         );
         $ReuseRequest = Server::$Request;
         $Probe->control = [
            'insert_state' => $Control['State']->name,
            'insert_URI' => $InsertRequest->URI,
            'insert_value' => $InsertRequest->Header->get($indexedName),
            'reuse_state' => $Reuse['State']->name,
            'rejected' => $ControlPackage->rejected,
            'closing' => $ControlDecoder->closing,
            'current' => $ControlDecoder->current,
            'reuse_URI' => $ReuseRequest->URI,
            'reuse_value' => $ReuseRequest->Header->get($indexedName),
            'insert_table' => $Control['table'],
            'reuse_table' => $Reuse['table'],
            'hpack_reserved_during' => $Reserved($ControlDecoder),
            'pending_during' => TCPServer::$pendingBytes,
         ];
         $Release($Control);
         $Probe->control['pending_after'] = TCPServer::$pendingBytes;
         $Control = null;

         // Failure-path control: first retain a full synchronized table under
         // a roomy cap, then send an invalid indexed field. Compression failure
         // must drop both the actual table and its capacity token immediately;
         // Package teardown may discover the decoder alias again, hence the
         // double-disconnect cleanup above must remain idempotent.
         $attackBlock = str_repeat("\x40\x00\x00", $Probe->entriesPerTable);
         $Failure = $Build(
            $attackBlock,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            18402,
         );
         $FailureDecoder = $Failure['Decoder'];
         $FailurePackage = $Failure['Package'];
         $tailBoundary = [
            'supported' => false,
         ];
         if (method_exists($FailureDecoder, 'permit')) {
            TCPServer::$maxPendingBytes = $Probe->capacityCharge + 32;
            $TailStream = new Stream(
               9,
               65535,
               65535,
               $FailureDecoder->Bodies,
            );
            $OverflowStream = new Stream(
               11,
               65535,
               65535,
               $FailureDecoder->Bodies,
            );
            $FailureDecoder->Streams[9] = $TailStream;
            $FailureDecoder->Streams[11] = $OverflowStream;
            $tailAllowed = $FailureDecoder->permit($TailStream->Buffers, 32);
            $tailPending = TCPServer::$pendingBytes;
            $overflowAllowed = $FailureDecoder->permit($OverflowStream->Buffers, 1);
            $tailBoundary = [
               'supported' => true,
               'allowed' => $tailAllowed,
               'retained' => $TailStream->Buffers->retained,
               'pending' => $tailPending,
               'overflow_allowed' => $overflowAllowed,
               'overflow_retained' => $OverflowStream->Buffers->retained,
               'overflow_pending' => TCPServer::$pendingBytes,
            ];
            TCPServer::$maxPendingBytes = 128 * 1024;
         }
         $FailureTransition = $Continue(
            $Failure,
            Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
               3,
               "\x80",
            ),
         );
         $Probe->failure = [
            'initial_table' => $Failure['table'],
            'initial_output' => $Failure['output'],
            'tail_boundary' => $tailBoundary,
            'state' => $FailureTransition['State']->name,
            'rejected' => $FailurePackage->rejected,
            'closing' => $FailureDecoder->closing,
            'table' => $FailureTransition['table'],
            'output' => $FailureTransition['output'],
            'hpack_reserved_bytes' => $Reserved($FailureDecoder),
            'worker_pending_bytes' => TCPServer::$pendingBytes,
         ];
         $Release($Failure);
         $Probe->failure['pending_after'] = TCPServer::$pendingBytes;
         $Failure = null;

         TCPServer::$maxWorkerPendingBytes = $Probe->baseline + $Probe->workerAllowance;

         // Attack: fill the whole RFC dynamic table before semantic rejection.
         for ($index = 0; $index < 2; $index++) {
            $Fixture = $Build(
               $attackBlock,
               HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
               18403 + $index,
            );
            $Attacks[] = $Fixture;

            $Decoder = $Fixture['Decoder'];
            $Package = $Fixture['Package'];
            $Connection = $Fixture['Connection'];
            $table = $Fixture['table'];
            $initialAlive = ! $Decoder->closing
               && ! $Package->rejected
               && $Connection->status <= Connections::STATUS_ESTABLISHED;
            $reuse = [
               'attempted' => false,
               'healthy' => false,
            ];
            if ($initialAlive) {
               // Functional proof of the ATTACK table itself: index 62 is the
               // newest empty-name entry inserted above. With synchronized
               // state this decodes and produces another stream-level RST;
               // without that table it is a connection-fatal compression error.
               $dynamicBlock = HPACK::encode([
                  [':method', 'GET'],
                  [':scheme', 'http'],
                  [':path', "/m1-hpack-dynamic-reuse-{$index}"],
                  [':authority', 'localhost'],
               ]) . "\xbe";
               $DynamicReuse = $Continue(
                  $Fixture,
                  Frame::pack(
                     HTTP2::FRAME_HEADERS,
                     HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
                     3,
                     $dynamicBlock,
                  ),
               );
               $dynamicHealthy = $DynamicReuse['State'] === States::Incomplete
                  && ! $Decoder->closing
                  && ! $Package->rejected
                  && ($DynamicReuse['output']['resets'] ?? 0) >= 1;

               $ping = "M1PING0{$index}";
               $reusePath = "/m1-hpack-post-rst-{$index}";
               $reuseBlock = HPACK::encode([
                  [':method', 'GET'],
                  [':scheme', 'http'],
                  [':path', $reusePath],
                  [':authority', 'localhost'],
               ]);
               $Reuse = $Continue(
                  $Fixture,
                  Frame::pack(HTTP2::FRAME_PING, 0, 0, $ping)
                     . Frame::pack(
                        HTTP2::FRAME_HEADERS,
                        HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
                        5,
                        $reuseBlock,
                     ),
               );
               $ReuseRequest = Server::$Request;
               $pingACK = in_array(
                  bin2hex($ping),
                  $Reuse['output']['ping_acks'] ?? [],
                  true,
               );
               $healthy = $Reuse['State'] === States::Complete
                  && ! $Decoder->closing
                  && ! $Package->rejected
                  && $Decoder->current === 5
                  && $ReuseRequest->URI === $reusePath
                  && $pingACK;
               $reuse = [
                  'attempted' => true,
                  'healthy' => $dynamicHealthy && $healthy,
                  'dynamic' => [
                     'healthy' => $dynamicHealthy,
                     'state' => $DynamicReuse['State']->name,
                     'rejected' => $Package->rejected,
                     'closing' => $Decoder->closing,
                     'table' => $DynamicReuse['table'],
                     'output' => $DynamicReuse['output'],
                  ],
                  'state' => $Reuse['State']->name,
                  'rejected' => $Package->rejected,
                  'closing' => $Decoder->closing,
                  'current' => $Decoder->current,
                  'URI' => $ReuseRequest->URI,
                  'ping' => bin2hex($ping),
                  'ping_ack' => $pingACK,
                  'table' => $Reuse['table'],
                  'output' => $Reuse['output'],
               ];

               // The valid control Stream owns a legitimately-accounted request
               // head until the application responds. Close only that Stream
               // before sampling worker bytes so its head cannot masquerade as
               // HPACK accounting; the connection and compression table remain.
               $Settled = $Continue(
                  $Fixture,
                  Frame::pack(
                     HTTP2::FRAME_RST_STREAM,
                     0,
                     5,
                     pack('N', Errors::Cancel->value),
                  ),
               );
               $settled = $Settled['State'] === States::Incomplete
                  && ! $Decoder->closing
                  && ! $Package->rejected
                  && isset($Decoder->Streams[5]) === false;
               $reuse['settled'] = [
                  'healthy' => $settled,
                  'state' => $Settled['State']->name,
                  'rejected' => $Package->rejected,
                  'closing' => $Decoder->closing,
                  'stream_present' => isset($Decoder->Streams[5]),
                  'table' => $Settled['table'],
                  'worker_pending_bytes' => TCPServer::$pendingBytes,
               ];
            }
            $transportAlive = ! $Decoder->closing
               && ! $Package->rejected
               && $Connection->status <= Connections::STATUS_ESTABLISHED;
            $controlsHealthy = ($reuse['dynamic']['healthy'] ?? false) === true
               && ($reuse['healthy'] ?? false) === true
               && ($reuse['settled']['healthy'] ?? false) === true;
            $Probe->attacks[] = [
               'state' => $Fixture['State']->name,
               'rejected' => $Package->rejected,
               'closing' => $Decoder->closing,
               'connection_status' => $Connection->status,
               'alive' => $transportAlive,
               'controls_healthy' => $controlsHealthy,
               'entries' => $table['entries'],
               'table_bytes' => $table['bytes'],
               'hpack_reserved_bytes' => $Reserved($Decoder),
               'decoder_buffer_bytes' => $Decoder->Buffers->retained,
               'worker_pending_bytes' => TCPServer::$pendingBytes,
               'output' => $Fixture['output'],
               'input_bytes' => $Fixture['input_bytes'],
               'block_bytes' => $Fixture['block_bytes'],
               'reuse' => $reuse,
            ];
         }

         $liveTableBytes = 0;
         $liveConnections = 0;
         foreach ($Probe->attacks as $attack) {
            if (($attack['alive'] ?? false) !== true) {
               continue;
            }
            $liveConnections++;
            $liveTableBytes += (int) ($attack['table_bytes'] ?? 0);
         }
         $Probe->aggregate = [
            'live_connections' => $liveConnections,
            'live_table_bytes' => $liveTableBytes,
            'worker_allowance' => $Probe->workerAllowance,
            'worker_pending_bytes' => TCPServer::$pendingBytes,
         ];
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         if (is_array($Control)) {
            $Release($Control);
         }
         if (is_array($Failure)) {
            $Release($Failure);
         }
         foreach ($Attacks as $Fixture) {
            $Release($Fixture);
         }

         $Probe->cleanup = [
            'pending_bytes' => TCPServer::$pendingBytes,
            'baseline' => $Probe->baseline,
         ];

         foreach ($Resources as $Resource) {
            if (is_resource($Resource)) {
               fclose($Resource);
            }
         }

         Server::$Request = $OldRequest;
         TCPServer::$maxWorkerPendingBytes = $oldWorkerCap;
         TCPServer::$maxPendingBytes = $oldConnectionCap;
      }

      return "GET /m1-hpack-budget-harness HTTP/1.1\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ): Generator {
      yield $Router->route('/m1-hpack-budget-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'M1-HPACK-BUDGET-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use ($Probe): bool|string {
      if (! str_contains($response, 'M1-HPACK-BUDGET-HARNESS-OK')) {
         return 'M1 native harness did not receive its positive response.';
      }
      if ($Probe->error !== '') {
         Vars::$labels = ['M1 fixture error'];
         dump(json_encode($Probe));

         return 'M1 fixture error: ' . $Probe->error;
      }
      if (
         ($Probe->control['insert_state'] ?? null) !== States::Complete->name
         || ($Probe->control['insert_URI'] ?? null) !== '/m1-hpack-index-insert'
         || ($Probe->control['insert_value'] ?? null) !== 'stored'
         || ($Probe->control['reuse_state'] ?? null) !== States::Complete->name
         || ($Probe->control['rejected'] ?? null) !== false
         || ($Probe->control['closing'] ?? null) !== false
         || ($Probe->control['current'] ?? null) !== 3
         || ($Probe->control['reuse_URI'] ?? null) !== '/m1-hpack-index-reuse'
         || ($Probe->control['reuse_value'] ?? null) !== 'stored'
         || ($Probe->control['insert_table']['entries'] ?? null) !== 1
         || ($Probe->control['insert_table']['bytes'] ?? null) !== 50
         || ($Probe->control['reuse_table']['entries'] ?? null) !== 1
         || ($Probe->control['reuse_table']['bytes'] ?? null) !== 50
         || ($Probe->control['pending_after'] ?? null) !== $Probe->baseline
      ) {
         return 'M1 valid incremental-indexing/reuse control failed: '
            . json_encode($Probe->control);
      }
      if (count($Probe->attacks) !== 2) {
         return 'M1 attack did not execute both connection legs: '
            . json_encode($Probe->attacks);
      }
      if (($Probe->cleanup['pending_bytes'] ?? null) !== $Probe->baseline) {
         return 'M1 HPACK/decoder cleanup did not restore the worker ledger: '
            . json_encode($Probe->cleanup);
      }

      $First = $Probe->attacks[0];
      $Second = $Probe->attacks[1];
      $currentVulnerableShape = ($First['alive'] ?? null) === true
         && ($Second['alive'] ?? null) === true
         && ($First['entries'] ?? null) === $Probe->entriesPerTable
         && ($Second['entries'] ?? null) === $Probe->entriesPerTable
         && ($First['table_bytes'] ?? null) === $Probe->bytesPerTable
         && ($Second['table_bytes'] ?? null) === $Probe->bytesPerTable
         && ($First['output']['resets'] ?? 0) >= 1
         && ($Second['output']['resets'] ?? 0) >= 1
         && ($First['reuse']['healthy'] ?? null) === true
         && ($Second['reuse']['healthy'] ?? null) === true
         && ($First['reuse']['dynamic']['healthy'] ?? null) === true
         && ($Second['reuse']['dynamic']['healthy'] ?? null) === true
         && ($First['reuse']['settled']['healthy'] ?? null) === true
         && ($Second['reuse']['settled']['healthy'] ?? null) === true
         && ($First['controls_healthy'] ?? null) === true
         && ($Second['controls_healthy'] ?? null) === true
         && ($Probe->aggregate['live_table_bytes'] ?? 0) > $Probe->workerAllowance
         && ($Probe->aggregate['worker_pending_bytes'] ?? null) === $Probe->baseline;
      if ($currentVulnerableShape) {
         Vars::$labels = ['M1 unaccounted HPACK dynamic-table evidence'];
         dump(json_encode($Probe));

         return 'CONFIRMED M1: two live HTTP/2 connections retained '
            . "{$Probe->aggregate['live_table_bytes']} RFC-accounted HPACK bytes under a "
            . "{$Probe->workerAllowance}-byte worker allowance while the worker retained-byte "
            . 'counter stayed at baseline. Evidence: ' . json_encode($Probe->aggregate);
      }

      if (
         ($Probe->control['hpack_reserved_during'] ?? null) !== $Probe->capacityCharge
         || ($Probe->control['pending_during'] ?? 0)
            < $Probe->baseline + $Probe->capacityCharge
      ) {
         return 'M1 positive control did not reserve the conservative HPACK capacity: '
            . json_encode($Probe->control);
      }

      $Matches = static function (
         mixed $details,
         Errors $Error,
         string $debug,
      ): bool {
         if (is_array($details) === false) {
            return false;
         }
         foreach ($details as $detail) {
            if (
               is_array($detail)
               && ($detail['error'] ?? null) === $Error->value
               && ($detail['debug'] ?? null) === $debug
            ) {
               return true;
            }
         }

         return false;
      };

      if (
         ($Probe->failure['initial_table']['entries'] ?? null) !== $Probe->entriesPerTable
         || ($Probe->failure['initial_table']['bytes'] ?? null) !== $Probe->bytesPerTable
         || ($Probe->failure['initial_output']['resets'] ?? 0) < 1
         || ($Probe->failure['tail_boundary']['supported'] ?? null) !== true
         || ($Probe->failure['tail_boundary']['allowed'] ?? null) !== true
         || ($Probe->failure['tail_boundary']['retained'] ?? null) !== 32
         || ($Probe->failure['tail_boundary']['pending'] ?? null)
            !== $Probe->baseline + $Probe->capacityCharge + 32
         || ($Probe->failure['tail_boundary']['overflow_allowed'] ?? null) !== false
         || ($Probe->failure['tail_boundary']['overflow_retained'] ?? null) !== 0
         || ($Probe->failure['tail_boundary']['overflow_pending'] ?? null)
            !== $Probe->baseline + $Probe->capacityCharge + 32
         || ($Probe->failure['state'] ?? null) !== States::Rejected->name
         || ($Probe->failure['rejected'] ?? null) !== true
         || ($Probe->failure['closing'] ?? null) !== true
         || ($Probe->failure['table']['entries'] ?? null) !== 0
         || ($Probe->failure['table']['bytes'] ?? null) !== 0
         || ($Probe->failure['hpack_reserved_bytes'] ?? null) !== 0
         || ($Probe->failure['worker_pending_bytes'] ?? null) !== $Probe->baseline
         || ($Probe->failure['pending_after'] ?? null) !== $Probe->baseline
         || $Matches(
            $Probe->failure['output']['goaway_details'] ?? null,
            Errors::Compression,
            'header block decode failed',
         ) === false
      ) {
         return 'M1 compression failure did not drop its live table and capacity token: '
            . json_encode($Probe->failure);
      }

      $budgetRejections = 0;
      $liveConnections = 0;
      $liveTableBytes = 0;
      $liveReservedBytes = 0;
      foreach ($Probe->attacks as $index => $attack) {
         if (($attack['alive'] ?? false) === true) {
            $liveConnections++;
            $liveTableBytes += (int) ($attack['table_bytes'] ?? 0);
            $liveReservedBytes += (int) ($attack['hpack_reserved_bytes'] ?? 0);
            if (
               ($attack['controls_healthy'] ?? false) !== true
               || ($attack['entries'] ?? null) !== $Probe->entriesPerTable
               || ($attack['table_bytes'] ?? null) !== $Probe->bytesPerTable
               || ($attack['hpack_reserved_bytes'] ?? 0) < $Probe->bytesPerTable
               || ($attack['reuse']['table']['entries'] ?? null)
                  !== $Probe->entriesPerTable
               || ($attack['reuse']['table']['bytes'] ?? null)
                  !== $Probe->bytesPerTable
               || ($attack['reuse']['dynamic']['table']['entries'] ?? null)
                  !== $Probe->entriesPerTable
               || ($attack['reuse']['dynamic']['table']['bytes'] ?? null)
                  !== $Probe->bytesPerTable
               || ($attack['reuse']['settled']['table']['entries'] ?? null)
                  !== $Probe->entriesPerTable
               || ($attack['reuse']['settled']['table']['bytes'] ?? null)
                  !== $Probe->bytesPerTable
            ) {
               return "M1 live connection #{$index} lost synchronized/accounted HPACK "
                  . 'state or failed a PING/stream control: ' . json_encode($attack);
            }
            continue;
         }

         if (
            ($attack['rejected'] ?? null) !== true
            || ($attack['closing'] ?? null) !== true
            || ($attack['table_bytes'] ?? null) !== 0
            || ($attack['hpack_reserved_bytes'] ?? null) !== 0
            || $Matches(
               $attack['output']['goaway_details'] ?? null,
               Errors::EnhanceYourCalm,
               'HPACK table budget exceeded',
            ) === false
         ) {
            return "M1 closed connection #{$index} was not an explicit clean HPACK-budget "
               . 'rejection: ' . json_encode($attack);
         }
         $budgetRejections++;
      }

      $pending = (int) ($Probe->aggregate['worker_pending_bytes'] ?? -1);
      if (
         $budgetRejections < 1
         || ($Probe->aggregate['live_connections'] ?? null) !== $liveConnections
         || ($Probe->aggregate['live_table_bytes'] ?? null) !== $liveTableBytes
         || $liveTableBytes > $Probe->workerAllowance
         || $pending < $Probe->baseline + $liveTableBytes
         || $pending < $Probe->baseline + $liveReservedBytes
         || $pending > $Probe->baseline + $Probe->workerAllowance
      ) {
         return 'M1 secure behavior did not account every live table, explicitly reject '
            . 'over-budget peers, and remain within the worker authority: '
            . json_encode([
               'budget_rejections' => $budgetRejections,
               'live_connections' => $liveConnections,
               'live_table_bytes' => $liveTableBytes,
               'live_reserved_bytes' => $liveReservedBytes,
               'aggregate' => $Probe->aggregate,
            ]);
      }

      return true;
   },
);
