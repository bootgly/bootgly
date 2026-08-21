<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder as ServerDecoder;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Encoder as ServerEncoder;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Endpoints\Servers\Resuming;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Stream;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


if (! class_exists('L1LedgerConnection', false)) {
   class L1LedgerConnection extends Connection
   {
      public bool $closed = false;

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

      public function close (): true
      {
         $this->closed = true;
         $this->status = Connections::STATUS_CLOSED;

         return true;
      }
   }
}

if (! class_exists('L1LedgerPackage', false)) {
   class L1LedgerPackage extends TCPPackages
   {
      /** Retain one decoder tail through the production carry path. */
      public function hold (string $input): void
      {
         $this->retain($input, 0, strlen($input));
      }

      /** Exercise the complete-consumption release branch. */
      public function drop (): void
      {
         $this->retain('', 0, 0);
      }

      /** @param resource $Socket */
      public function enqueue (mixed &$Socket, string $buffer, array $uploads): bool
      {
         return $this->queue($Socket, $buffer, $uploads);
      }

      public function inspect (): int
      {
         return $this->measure();
      }

      public function purge (): void
      {
         $this->release();
      }
   }
}

if (! class_exists('L1LedgerResumer', false)) {
   class L1LedgerResumer implements Resuming
   {
      public int $calls = 0;

      public function resume (): void
      {
         $this->calls++;
      }
   }
}

if (! class_exists('L1LedgerStream', false)) {
   class L1LedgerStream
   {
      /** @var array<string, bool> */
      public static array $blocked = [];

      public mixed $context;
      private string $key = '';


      public static function reset (): void
      {
         self::$blocked = [];
      }

      public static function block (string $key, bool $blocked): void
      {
         self::$blocked[$key] = $blocked;
      }

      public function stream_open (
         string $path,
         string $mode,
         int $options,
         null|string &$opened_path
      ): bool {
         $this->key = (string) (parse_url($path, PHP_URL_HOST) ?: 'default');
         self::$blocked[$this->key] ??= false;

         return true;
      }

      public function stream_write (string $data): int
      {
         return self::$blocked[$this->key] ? 0 : strlen($data);
      }

      public function stream_eof (): bool
      {
         return false;
      }

      /** @return array<string, mixed> */
      public function stream_stat (): array
      {
         return [];
      }
   }
}

if (! class_exists('L1WriteFailureStream', false)) {
   class L1WriteFailureStream
   {
      /** @var array<string, string> */
      public static array $inputs = [];
      /** @var array<string, int> */
      public static array $offsets = [];
      /** @var array<string, bool> */
      public static array $blocked = [];
      /** @var array<string, string> */
      public static array $written = [];

      public mixed $context;
      private string $key = '';


      public static function reset (string $key, string $input, bool $blocked): void
      {
         self::$inputs[$key] = $input;
         self::$offsets[$key] = 0;
         self::$blocked[$key] = $blocked;
         self::$written[$key] = '';
      }

      public function stream_open (
         string $path,
         string $mode,
         int $options,
         null|string &$opened_path
      ): bool {
         $this->key = (string) (parse_url($path, PHP_URL_HOST) ?: 'default');
         self::$inputs[$this->key] ??= '';
         self::$offsets[$this->key] ??= 0;
         self::$blocked[$this->key] ??= false;
         self::$written[$this->key] ??= '';

         return true;
      }

      public function stream_read (int $count): string
      {
         $input = self::$inputs[$this->key];
         $offset = self::$offsets[$this->key];
         $chunk = substr($input, $offset, $count);
         self::$offsets[$this->key] += strlen($chunk);

         return $chunk;
      }

      public function stream_write (string $data): int
      {
         if (self::$blocked[$this->key]) {
            return 0;
         }

         self::$written[$this->key] .= $data;

         return strlen($data);
      }

      public function stream_eof (): bool
      {
         return self::$offsets[$this->key] >= strlen(self::$inputs[$this->key]);
      }

      /** @return array<string, mixed> */
      public function stream_stat (): array
      {
         return [];
      }
   }
}

if (! class_exists('L1WriteFailureConnection', false)) {
   class L1WriteFailureConnection extends Connection
   {
      public bool $closed = false;

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

      public function close (): true
      {
         $this->closed = true;
         $this->status = Connections::STATUS_CLOSED;

         return true;
      }
   }
}

if (! class_exists('L1WriteFailurePackage', false)) {
   class L1WriteFailurePackage extends TCPPackages
   {
      public function purge (): void
      {
         $this->release();
      }
   }
}

if (! class_exists('L1WriteFailureDecoder', false)) {
   class L1WriteFailureDecoder implements ServerDecoder
   {
      public int $calls = 0;

      public function decode (
         ServerPackages $Package,
         string $buffer,
         int $size
      ): States {
         $this->calls++;

         if ($this->calls === 1) {
            $Package->consumed = 4;
            return States::Complete;
         }

         $Package->consumed = 0;
         return States::Incomplete;
      }
   }
}

if (! class_exists('L1WriteFailureEncoder', false)) {
   class L1WriteFailureEncoder implements ServerEncoder
   {
      public static string $wire = '';


      public static function encode (
         ServerPackages $Package,
         null|int &$length
      ): string {
         $length = strlen(self::$wire);

         return self::$wire;
      }
   }
}

if (! class_exists('L1PipelineCloseDecoder', false)) {
   class L1PipelineCloseDecoder implements ServerDecoder
   {
      public int $calls = 0;

      public function decode (
         ServerPackages $Package,
         string $buffer,
         int $size
      ): States {
         $this->calls++;

         if ($this->calls <= 2) {
            $Package->consumed = 4;
            return States::Complete;
         }

         $Package->consumed = 0;
         return States::Incomplete;
      }
   }
}

if (! class_exists('L1QuantumDecoder', false)) {
   class L1QuantumDecoder extends Decoder_HTTP2
   {
      public int $continuations = 0;


      protected function schedule (): void
      {
         $this->continuations++;
      }
   }
}

if (! class_exists('L1PipelineCloseEncoder', false)) {
   class L1PipelineCloseEncoder implements ServerEncoder
   {
      public static int $calls = 0;
      public static string $wire = '';


      public static function encode (
         ServerPackages $Package,
         null|int &$length
      ): string {
         self::$calls++;
         $length = strlen(self::$wire);

         // @ Model a terminal response selected for the second request in one
         //   pipelined read. writing() closes after the successful full drain
         //   and returns true, so reading() must also inspect connection state.
         if (self::$calls === 2) {
            $Package->closeAfterDrain = true;
         }

         return self::$wire;
      }
   }
}

$probe = [
   'error' => '',
   'baseline' => null,
   'accountant' => [],
   'h2_carry' => [],
   'outbox' => [],
   'batch' => [],
   'quantum' => [],
   'resume' => [],
   'pads' => [],
   'carry' => [],
   'write_failure' => [],
   'final' => null,
];

return new Test(
   description: 'TCP retained-byte ledger must be exact across owner types and cleanup',
   Separator: new Separator(line: true),

   request: static function () use (&$probe): string {
      $savedWorkerCap = TCPServer::$maxWorkerPendingBytes;
      $savedPendingCap = TCPServer::$maxPendingBytes;
      $OldDecoder = TCPServer::$Decoder;
      $OldEncoder = TCPServer::$Encoder;
      $baseline = TCPServer::$pendingBytes;
      $probe['baseline'] = $baseline;
      $Resources = [];
      $Owners = [];
      $Decoders = [];
      $Streams = [];
      $Tokens = [];
      $paths = [];
      $Destructor = null;

      try {
         // # Exact cap, cap+1 refusal and cross-protocol authority.
         TCPServer::$maxWorkerPendingBytes = $baseline + 1152;

         $Socket = fopen('php://temp', 'w+');
         if (! is_resource($Socket)) {
            throw new RuntimeException('Could not create the Package fixture stream.');
         }
         $Resources[] = $Socket;
         $Connection = new L1LedgerConnection($Socket, 18401);
         $Package = new L1LedgerPackage($Connection);
         $Owners[] = $Package;

         $Stream = new Stream(1, 0, 0, new Bodies(1, 1));
         $Streams[] = $Stream;
         $Stream->backlog = str_repeat('H', 512);
         $Stream->chunks = [[
            'data' => str_repeat('P', 256),
            'position' => 128,
         ]];
         $measured = $Stream->measure();

         $packageAccepted = $Package->Buffers->reserve(384);
         $streamAccepted = $Stream->Buffers->reserve($measured);
         $Overflow = new Buffers;
         $Tokens[] = $Overflow;
         $overflowAccepted = $Overflow->reserve(1);

         $atCap = [
            'package_accepted' => $packageAccepted,
            'stream_accepted' => $streamAccepted,
            'overflow_accepted' => $overflowAccepted,
            'stream_measured' => $measured,
            'package_retained' => $Package->Buffers->retained,
            'stream_retained' => $Stream->Buffers->retained,
            'overflow_retained' => $Overflow->retained,
            'total' => TCPServer::$pendingBytes,
         ];

         $shrunk = $Package->Buffers->reserve(128);
         $afterShrink = TCPServer::$pendingBytes;
         $Package->Buffers->release();
         $afterRelease = TCPServer::$pendingBytes;
         $Package->Buffers->release();
         $afterDuplicate = TCPServer::$pendingBytes;
         $Stream->close();
         $afterStreamClose = TCPServer::$pendingBytes;

         // # Destructor fallback and zero-growth policy.
         TCPServer::$maxWorkerPendingBytes = $baseline + 1;
         $Destructor = new Buffers;
         $destructorAccepted = $Destructor->reserve(1);
         $duringDestructor = TCPServer::$pendingBytes;
         unset($Destructor);
         gc_collect_cycles();
         $afterDestructor = TCPServer::$pendingBytes;

         TCPServer::$maxWorkerPendingBytes = $baseline;
         $Zero = new Buffers;
         $Tokens[] = $Zero;
         $zeroAccepted = $Zero->reserve(1);

         // # Fresh-worker reset clears both the public diagnostic and the
         //   authoritative private ledger. Seed through the public reservation
         //   API, then prove idempotence and full-cap admission after reset.
         if (TCPServer::$pendingBytes !== 0) {
            throw new RuntimeException(
               'Buffers::reset fixture requires an empty worker ledger.'
            );
         }

         TCPServer::$maxWorkerPendingBytes = 64;
         $Reset = new Buffers;
         $Tokens[] = $Reset;
         $resetAccepted = $Reset->reserve(64);
         $beforeReset = TCPServer::$pendingBytes;

         Buffers::reset();
         $afterReset = TCPServer::$pendingBytes;

         Buffers::reset();
         $afterDuplicateReset = TCPServer::$pendingBytes;

         $Fresh = new Buffers;
         $Tokens[] = $Fresh;
         $freshAccepted = $Fresh->reserve(64);
         $afterFresh = TCPServer::$pendingBytes;

         // @ reset() is worker-boot-only. Neutralize the deliberately stale
         //   fixture tokens before the rest of this shared-process case runs.
         Buffers::reset();
         $Reset->release();
         $Fresh->release();
         $afterResetCleanup = TCPServer::$pendingBytes;

         $probe['accountant'] = [
            'at_cap' => $atCap,
            'shrunk' => $shrunk,
            'after_shrink' => $afterShrink,
            'after_release' => $afterRelease,
            'after_duplicate' => $afterDuplicate,
            'after_stream_close' => $afterStreamClose,
            'destructor_accepted' => $destructorAccepted,
            'during_destructor' => $duringDestructor,
            'after_destructor' => $afterDestructor,
            'zero_accepted' => $zeroAccepted,
            'zero_retained' => $Zero->retained,
            'reset' => [
               'accepted' => $resetAccepted,
               'before' => $beforeReset,
               'after' => $afterReset,
               'after_duplicate' => $afterDuplicateReset,
               'fresh_accepted' => $freshAccepted,
               'after_fresh' => $afterFresh,
               'after_cleanup' => $afterResetCleanup,
            ],
         ];

         // # Decoder-internal HTTP/2 carry is not visible to Packages::carry.
         //   Exercise both a partial frame and a retained header block through
         //   the real decode path under one exact worker authority.
         $partial = substr(HTTP2::PREFACE, 0, 12);
         TCPServer::$maxWorkerPendingBytes = $baseline + 23;

         $H2SocketA = fopen('php://temp', 'w+');
         $H2SocketB = fopen('php://temp', 'w+');
         $H2SocketC = fopen('php://temp', 'w+');
         $H2SocketD = fopen('php://temp', 'w+');
         $H2SocketE = fopen('php://temp', 'w+');
         if (
            ! is_resource($H2SocketA)
            || ! is_resource($H2SocketB)
            || ! is_resource($H2SocketC)
            || ! is_resource($H2SocketD)
            || ! is_resource($H2SocketE)
         ) {
            throw new RuntimeException('Could not create HTTP/2 carry fixture streams.');
         }
         $Resources[] = $H2SocketA;
         $Resources[] = $H2SocketB;
         $Resources[] = $H2SocketC;
         $Resources[] = $H2SocketD;
         $Resources[] = $H2SocketE;

         $H2ConnectionA = new L1LedgerConnection($H2SocketA, 18406);
         $H2ConnectionB = new L1LedgerConnection($H2SocketB, 18407);
         $H2ConnectionC = new L1LedgerConnection($H2SocketC, 18408);
         $H2ConnectionD = new L1LedgerConnection($H2SocketD, 18409);
         $H2ConnectionE = new L1LedgerConnection($H2SocketE, 18410);
         $H2PackageA = new L1LedgerPackage($H2ConnectionA);
         $H2PackageB = new L1LedgerPackage($H2ConnectionB);
         $H2PackageC = new L1LedgerPackage($H2ConnectionC);
         $H2PackageD = new L1LedgerPackage($H2ConnectionD);
         $H2PackageE = new L1LedgerPackage($H2ConnectionE);
         $Owners[] = $H2PackageA;
         $Owners[] = $H2PackageB;
         $Owners[] = $H2PackageC;
         $Owners[] = $H2PackageD;
         $Owners[] = $H2PackageE;

         $DecoderA = new Decoder_HTTP2;
         $DecoderB = new Decoder_HTTP2;
         $DecoderC = new Decoder_HTTP2;
         $DecoderD = new Decoder_HTTP2;
         $DecoderE = new Decoder_HTTP2;
         $Decoders[] = $DecoderA;
         $Decoders[] = $DecoderB;
         $Decoders[] = $DecoderC;
         $Decoders[] = $DecoderD;
         $Decoders[] = $DecoderE;

         $PartialA = $DecoderA->decode($H2PackageA, $partial, strlen($partial));
         $partialABytes = strlen($DecoderA->buffer);
         $partialARetained = $DecoderA->Buffers->retained;
         $partialTotal = TCPServer::$pendingBytes;
         $PartialB = $DecoderB->decode($H2PackageB, $partial, strlen($partial));
         $partialOverflowTotal = TCPServer::$pendingBytes;

         $settings = Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0);
         $tail = substr(HTTP2::PREFACE, strlen($partial)) . $settings;
         $PartialComplete = $DecoderA->decode(
            $H2PackageA,
            $tail,
            strlen($tail),
         );
         $partialCompleteRetained = $DecoderA->Buffers->retained;
         $partialReleased = TCPServer::$pendingBytes;

         $preface = HTTP2::PREFACE . $settings;
         $DecoderC->decode($H2PackageC, $preface, strlen($preface));
         $DecoderD->decode($H2PackageD, $preface, strlen($preface));
         $DecoderE->decode($H2PackageE, $preface, strlen($preface));

         $headerBlock = HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/l1-h2-carry'],
            // ! Deliberately omit authority: completion must release the
            //   decoder carry through a stream-level protocol reset without
            //   creating a separately-budgeted persistent request head.
         ]);
         $fragmentBytes = max(1, intdiv(strlen($headerBlock), 2));
         TCPServer::$maxWorkerPendingBytes = $baseline + (2 * $fragmentBytes) - 1;
         $header = Frame::pack(
            HTTP2::FRAME_HEADERS,
            0,
            1,
            substr($headerBlock, 0, $fragmentBytes),
         );
         $FragmentA = $DecoderA->decode($H2PackageA, $header, strlen($header));
         $fragmentARetained = $DecoderA->Buffers->retained;
         $fragmentTotal = TCPServer::$pendingBytes;
         $FragmentC = $DecoderC->decode($H2PackageC, $header, strlen($header));
         $fragmentOverflowTotal = TCPServer::$pendingBytes;

         TCPServer::$maxWorkerPendingBytes = $baseline + 128 * 1024;
         $continuation = Frame::pack(
            HTTP2::FRAME_CONTINUATION,
            HTTP2::FLAG_END_HEADERS,
            1,
            substr($headerBlock, $fragmentBytes),
         );
         $FragmentComplete = $DecoderA->decode(
            $H2PackageA,
            $continuation,
            strlen($continuation),
         );
         $fragmentCompleteRetained = $DecoderA->Buffers->retained;
         $fragmentReleased = TCPServer::$pendingBytes;
         $fragmentHPACKRetained = $DecoderA->HPACKBuffers->retained;
         $DecoderA->disconnect();
         $fragmentDisconnected = TCPServer::$pendingBytes;

         $feed = str_repeat('F', 10);
         TCPServer::$maxWorkerPendingBytes = $baseline + (2 * strlen($feed)) - 1;
         $DecoderD->feed($feed);
         $feedDRetained = $DecoderD->Buffers->retained;
         $feedTotal = TCPServer::$pendingBytes;
         $DecoderE->feed($feed);
         $feedOverflowTotal = TCPServer::$pendingBytes;
         $DecoderD->disconnect();
         $feedReleased = TCPServer::$pendingBytes;

         $probe['h2_carry'] = [
            'partial_a' => $PartialA === States::Incomplete,
            'partial_a_bytes' => $partialABytes,
            'partial_a_retained' => $partialARetained,
            'partial_total' => $partialTotal,
            'partial_b' => $PartialB === States::Rejected,
            'partial_b_closed' => $H2ConnectionB->closed,
            'partial_b_retained' => $DecoderB->Buffers->retained,
            'partial_overflow_total' => $partialOverflowTotal,
            'partial_complete' => $PartialComplete === States::Incomplete,
            'partial_complete_retained' => $partialCompleteRetained,
            'partial_released' => $partialReleased,
            'fragment_bytes' => $fragmentBytes,
            'fragment_a' => $FragmentA === States::Incomplete,
            'fragment_a_retained' => $fragmentARetained,
            'fragment_total' => $fragmentTotal,
            'fragment_c' => $FragmentC === States::Rejected,
            'fragment_c_closed' => $H2ConnectionC->closed,
            'fragment_c_retained' => $DecoderC->Buffers->retained,
            'fragment_overflow_total' => $fragmentOverflowTotal,
            'fragment_complete' => $FragmentComplete === States::Incomplete,
            'fragment_complete_retained' => $fragmentCompleteRetained,
            'fragment_released' => $fragmentReleased,
            'fragment_hpack_retained' => $fragmentHPACKRetained,
            'fragment_disconnected' => $fragmentDisconnected,
            'feed_bytes' => strlen($feed),
            'feed_d_retained' => $feedDRetained,
            'feed_total' => $feedTotal,
            'feed_e_closed' => $H2ConnectionE->closed,
            'feed_e_retained' => $DecoderE->Buffers->retained,
            'feed_overflow_total' => $feedOverflowTotal,
            'feed_released' => $feedReleased,
         ];

         // # Control frames must leave Decoder_HTTP2::$outbox before a
         //   Complete request reaches an application that can defer its
         //   response. A zero-progress transport makes that handoff persistent
         //   and proves it enters the same worker-wide accounting authority.
         $OutboxPairA = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
         );
         $OutboxPairB = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
         );
         if ($OutboxPairA === false || $OutboxPairB === false) {
            throw new RuntimeException('Could not create HTTP/2 outbox fixture sockets.');
         }
         [$OutboxSocketA, $OutboxPeerA] = $OutboxPairA;
         [$OutboxSocketB, $OutboxPeerB] = $OutboxPairB;
         stream_set_blocking($OutboxSocketA, false);
         stream_set_blocking($OutboxPeerA, false);
         stream_set_blocking($OutboxSocketB, false);
         stream_set_blocking($OutboxPeerB, false);
         $Resources[] = $OutboxSocketA;
         $Resources[] = $OutboxPeerA;
         $Resources[] = $OutboxSocketB;
         $Resources[] = $OutboxPeerB;

         $OutboxConnectionA = new L1LedgerConnection($OutboxSocketA, 18410);
         $OutboxConnectionB = new L1LedgerConnection($OutboxSocketB, 18411);
         $OutboxPackageA = new L1LedgerPackage($OutboxConnectionA);
         $OutboxPackageB = new L1LedgerPackage($OutboxConnectionB);
         $Owners[] = $OutboxPackageA;
         $Owners[] = $OutboxPackageB;

         $OutboxDecoderA = new Decoder_HTTP2;
         $OutboxDecoderB = new Decoder_HTTP2;
         $Decoders[] = $OutboxDecoderA;
         $Decoders[] = $OutboxDecoderB;

         TCPServer::$maxWorkerPendingBytes = $baseline + 1024;
         $OutboxPrefaceA = $OutboxDecoderA->decode(
            $OutboxPackageA,
            $preface,
            strlen($preface),
         );
         $OutboxPrefaceB = $OutboxDecoderB->decode(
            $OutboxPackageB,
            $preface,
            strlen($preface),
         );

         $Fill = static function ($Socket): void {
            $block = str_repeat('B', 65536);
            while (true) {
               $written = @fwrite($Socket, $block);
               if ($written === false || $written === 0) {
                  return;
               }
            }
         };
         $Fill($OutboxSocketA);
         $Fill($OutboxSocketB);
         $pingCount = 4;
         $ping = Frame::pack(HTTP2::FRAME_PING, 0, 0, '12345678');
         $ackBytes = $pingCount * strlen(
            Frame::pack(HTTP2::FRAME_PING, HTTP2::FLAG_ACK, 0, '12345678')
         );
         $outboxFields = [
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', '/l1-outbox-defer'],
            [':authority', 'localhost'],
         ];
         $outboxList = 0;
         foreach ($outboxFields as [$name, $value]) {
            $outboxList += strlen($name) + strlen($value) + 32;
         }
         $outboxHead = 2 * $outboxList
            + count($outboxFields) * 384
            + 1024;
         $HPACKLimit = $OutboxDecoderA->HPACK->limit;
         $HPACKCapacity = 2 * $HPACKLimit
            + intdiv($HPACKLimit, 32) * 384
            + 1024;
         $request = Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            1,
            HPACK::encode($outboxFields),
         );
         $attack = str_repeat($ping, $pingCount) . $request;
         TCPServer::$maxWorkerPendingBytes =
            $baseline
            + (2 * $HPACKCapacity)
            + (2 * $outboxHead)
            + (2 * $ackBytes)
            - 1;

         $OutboxA = $OutboxDecoderA->decode(
            $OutboxPackageA,
            $attack,
            strlen($attack),
         );
         $outboxAPending = strlen($OutboxPackageA->pendingBuffer)
            - $OutboxPackageA->pendingOffset;
         $outboxARetained = $OutboxPackageA->Buffers->retained;
         $outboxHPACKRetained = $OutboxDecoderA->HPACKBuffers->retained;
         $outboxATotal = TCPServer::$pendingBytes;

         $OutboxB = $OutboxDecoderB->decode(
            $OutboxPackageB,
            $attack,
            strlen($attack),
         );
         // @ The fixture Connection intentionally overrides close() and does
         //   not run production decoder teardown; model that convergence
         //   explicitly before measuring the post-rejection worker ledger.
         $OutboxDecoderB->disconnect();
         $outboxOverflowTotal = TCPServer::$pendingBytes;

         while (true) {
            $drained = @fread($OutboxPeerA, 65536);
            if ($drained === false || $drained === '') {
               break;
            }
         }
         $outboxDrained = $OutboxPackageA->writing($OutboxSocketA);
         $OutboxDecoderA->disconnect();

         $probe['outbox'] = [
            'preface_a' => $OutboxPrefaceA === States::Incomplete,
            'preface_b' => $OutboxPrefaceB === States::Incomplete,
            'ack_bytes' => $ackBytes,
            'head_bytes' => $outboxHead,
            'hpack_capacity' => $HPACKCapacity,
            'hpack_retained' => $outboxHPACKRetained,
            'a' => $OutboxA === States::Complete,
            'a_decoder_bytes' => strlen($OutboxDecoderA->outbox),
            'a_pending' => $outboxAPending,
            'a_retained' => $outboxARetained,
            'a_total' => $outboxATotal,
            'b' => $OutboxB === States::Rejected,
            'b_closed' => $OutboxConnectionB->closed,
            'b_decoder_bytes' => strlen($OutboxDecoderB->outbox),
            'b_pending' => strlen($OutboxPackageB->pendingBuffer)
               - $OutboxPackageB->pendingOffset,
            'b_retained' => $OutboxPackageB->Buffers->retained,
            'overflow_total' => $outboxOverflowTotal,
            'drained' => $outboxDrained,
            'drained_pending' => strlen($OutboxPackageA->pendingBuffer)
               - $OutboxPackageA->pendingOffset,
            'drained_retained' => $OutboxPackageA->Buffers->retained,
            'drained_total' => TCPServer::$pendingBytes,
         ];

         // # An inflated peer window must not materialize a whole disk-backed
         //   HTTP/2 response in one decoder callback. Each call is bounded by
         //   one serialized MiB and the unread tail remains only on disk.
         $path = tempnam(sys_get_temp_dir(), 'bootgly-l1-');
         if ($path === false) {
            throw new RuntimeException('Could not create the HTTP/2 batch fixture.');
         }
         $paths[] = $path;

         $Handler = fopen($path, 'wb');
         if (! is_resource($Handler)) {
            throw new RuntimeException('Could not open the HTTP/2 batch fixture.');
         }
         $block = str_repeat('F', 65536);
         for ($part = 0; $part < 32; $part++) {
            if (fwrite($Handler, $block) !== 65536) {
               @fclose($Handler);
               throw new RuntimeException('Could not populate the HTTP/2 batch fixture.');
            }
         }
         $state = fstat($Handler);
         @fclose($Handler);
         if (
            $state === false
            || ! is_int($state['dev'] ?? null)
            || ! is_int($state['ino'] ?? null)
            || ! is_int($state['mode'] ?? null)
            || ! is_int($state['size'] ?? null)
            || ! is_int($state['mtime'] ?? null)
            || ! is_int($state['ctime'] ?? null)
         ) {
            throw new RuntimeException('Could not identify the HTTP/2 batch fixture.');
         }

         $fileSize = 2 * 1024 * 1024;
         $Decoder = new Decoder_HTTP2;
         $Decoder->window = 2147483647;
         $Decoder->Remote->frame = 16777215;
         $FileStream = new Stream(3, 2147483647, 0, new Bodies(1, 1));
         $Streams[] = $FileStream;
         $FileStream->chunks = [[
            'file' => $path,
            'offset' => 0,
            'length' => $fileSize,
            'position' => 0,
            'identity' => [
               'device' => $state['dev'],
               'inode' => $state['ino'],
               'mode' => $state['mode'],
               'size' => $state['size'],
               'modified' => $state['mtime'],
               'changed' => $state['ctime'],
            ],
         ]];

         $calls = 0;
         $largest = 0;
         $progressive = true;
         $previous = 0;
         $firstBytes = null;
         $firstPosition = null;
         $firstDone = null;
         $done = false;
         $handlerPersisted = false;
         $remainingBudget = null;
         while ($done === false && $calls < 8) {
            [$wire, $done, $remainingBudget] = $Decoder->drain($FileStream, 3);
            $calls++;
            $wireBytes = strlen($wire);
            $largest = max($largest, $wireBytes);
            $position = $FileStream->chunks[0]['position'] ?? $fileSize;
            $progressive = $progressive && is_int($position) && $position > $previous;
            $previous = is_int($position) ? $position : $previous;
            $handlerPersisted = $handlerPersisted
               || is_resource($FileStream->chunks[0]['handler'] ?? null);

            if ($calls === 1) {
               $firstBytes = $wireBytes;
               $firstPosition = $position;
               $firstDone = $done;
            }
         }

         $probe['batch'] = [
            'calls' => $calls,
            'largest' => $largest,
            'progressive' => $progressive,
            'first_bytes' => $firstBytes,
            'first_position' => $firstPosition,
            'first_done' => $firstDone,
            'done' => $done,
            'final_position' => $previous,
            'remaining_budget' => $remainingBudget,
            'handler_persisted' => $handlerPersisted,
            'stream_chunk' => $FileStream->chunk,
            'stream_measured' => $FileStream->measure(),
            'stream_retained' => $FileStream->Buffers->retained,
            'total' => TCPServer::$pendingBytes,
         ];

         // # Large disk responses coalesce tiny credits before reopening.
         //   This bounds open/stat/seek/read/close amplification without
         //   changing byte-granular behavior for small files.
         $QuantumStream = new Stream(5, 1, 0, new Bodies(1, 1));
         $Streams[] = $QuantumStream;
         $QuantumStream->chunks = [[
            'file' => $path,
            'offset' => 0,
            'length' => $fileSize,
            'position' => 0,
            'identity' => [
               'device' => $state['dev'],
               'inode' => $state['ino'],
               'mode' => $state['mode'],
               'size' => $state['size'],
               'modified' => $state['mtime'],
               'changed' => $state['ctime'],
            ],
         ]];
         [$quantumBefore, $quantumBeforeDone] = $Decoder->drain($QuantumStream, 5);
         $quantumBeforePosition = $QuantumStream->chunks[0]['position'] ?? null;
         $quantumBeforeHandler = is_resource(
            $QuantumStream->chunks[0]['handler'] ?? null
         );

         $QuantumStream->window += 4095;
         [$quantumAfter, $quantumAfterDone] = $Decoder->drain($QuantumStream, 5);
         $quantumAfterPosition = $QuantumStream->chunks[0]['position'] ?? null;
         $quantumAfterHandler = is_resource(
            $QuantumStream->chunks[0]['handler'] ?? null
         );

         // # Consuming a preceding segment can leave enough serialized budget
         //   to enter the file branch, but one byte less than FILE_QUANTUM plus
         //   its DATA header. With ample peer credit, this is a local slice and
         //   must schedule exactly one continuation instead of waiting for a
         //   WINDOW_UPDATE that the peer has no reason to send.
         $QuantumDecoder = new L1QuantumDecoder;
         $Decoders[] = $QuantumDecoder;
         $QuantumDecoder->window = 65535;
         $QuantumDecoder->Remote->frame = 16384;
         $ContinuationStream = new Stream(7, 65535, 0, new Bodies(1, 1));
         $Streams[] = $ContinuationStream;
         $ContinuationStream->chunks = [
            [
               'data' => 'P',
               'position' => 0,
            ],
            [
               'file' => $path,
               'offset' => 0,
               'length' => $fileSize,
               'position' => 0,
               'identity' => [
                  'device' => $state['dev'],
                  'inode' => $state['ino'],
                  'mode' => $state['mode'],
                  'size' => $state['size'],
                  'modified' => $state['mtime'],
                  'changed' => $state['ctime'],
               ],
            ],
         ];
         [$continuationWire, $continuationDone, $continuationBudget] =
            $QuantumDecoder->drain($ContinuationStream, 7, 4114);
         $continuationPosition = $ContinuationStream->chunks[1]['position'] ?? null;
         $continuationConnectionWindow = $QuantumDecoder->window;
         $continuationStreamWindow = $ContinuationStream->window;
         $continuations = $QuantumDecoder->continuations;
         $ContinuationStream->window = 4095;
         [$streamCreditWire, $streamCreditDone, $streamCreditBudget] =
            $QuantumDecoder->drain($ContinuationStream, 7, 4114);
         $ContinuationStream->window = 65535;
         $QuantumDecoder->window = 4095;
         [$connectionCreditWire, $connectionCreditDone, $connectionCreditBudget] =
            $QuantumDecoder->drain($ContinuationStream, 7, 4114);

         $probe['quantum'] = [
            'before_bytes' => strlen($quantumBefore),
            'before_done' => $quantumBeforeDone,
            'before_position' => $quantumBeforePosition,
            'before_handler' => $quantumBeforeHandler,
            'after_bytes' => strlen($quantumAfter),
            'after_done' => $quantumAfterDone,
            'after_position' => $quantumAfterPosition,
            'after_handler' => $quantumAfterHandler,
            'continuation_wire_bytes' => strlen($continuationWire),
            'continuation_done' => $continuationDone,
            'continuation_budget' => $continuationBudget,
            'continuation_chunk' => $ContinuationStream->chunk,
            'continuation_position' => $continuationPosition,
            'continuation_connection_window' => $continuationConnectionWindow,
            'continuation_stream_window' => $continuationStreamWindow,
            'continuations' => $continuations,
            'stream_credit_wire_bytes' => strlen($streamCreditWire),
            'stream_credit_done' => $streamCreditDone,
            'stream_credit_budget' => $streamCreditBudget,
            'connection_credit_wire_bytes' => strlen($connectionCreditWire),
            'connection_credit_done' => $connectionCreditDone,
            'connection_credit_budget' => $connectionCreditBudget,
            'control_position' => $ContinuationStream->chunks[1]['position'] ?? null,
            'control_continuations' => $QuantumDecoder->continuations,
         ];

         // # A backpressured protocol continuation is event-driven: after the
         //   terminal TCP drain it receives exactly one resume notification,
         //   with no timer polling and no retained byte left behind.
         TCPServer::$maxWorkerPendingBytes = $baseline + 1;
         $SocketResume = fopen('php://temp', 'w+');
         if (! is_resource($SocketResume)) {
            throw new RuntimeException('Could not create the resume fixture stream.');
         }
         $Resources[] = $SocketResume;
         $ConnectionResume = new L1LedgerConnection($SocketResume, 18405);
         $PackageResume = new L1LedgerPackage($ConnectionResume);
         $Owners[] = $PackageResume;
         $Resumer = new L1LedgerResumer;
         $PackageResume->decoded = $Resumer;
         $PackageResume->pendingBuffer = 'R';
         $resumeReserved = $PackageResume->Buffers->reserve(1);
         $resumeWritten = $PackageResume->writing($SocketResume);

         $probe['resume'] = [
            'reserved' => $resumeReserved,
            'written' => $resumeWritten,
            'calls' => $Resumer->calls,
            'pending' => $PackageResume->pendingBuffer,
            'retained' => $PackageResume->Buffers->retained,
            'total' => TCPServer::$pendingBytes,
         ];

         // # Queued and active file pads are in-memory output, while file
         //   ranges themselves stay disk-backed and uncharged.
         $uploads = [[
            'file' => '/disk-backed-only',
            'pads' => [
               ['prepend' => str_repeat('A', 40), 'append' => str_repeat('B', 24)],
               ['prepend' => str_repeat('C', 16), 'append' => ''],
            ],
         ]];
         TCPServer::$maxWorkerPendingBytes = $baseline + 144;
         $queued = $Package->enqueue($Socket, str_repeat('H', 64), $uploads);
         $queuedMeasured = $Package->inspect();
         $queuedTotal = TCPServer::$pendingBytes;

         $SocketOverflow = fopen('php://temp', 'w+');
         if (! is_resource($SocketOverflow)) {
            throw new RuntimeException('Could not create the pad overflow stream.');
         }
         $Resources[] = $SocketOverflow;
         $ConnectionOverflow = new L1LedgerConnection($SocketOverflow, 18404);
         $PackageOverflow = new L1LedgerPackage($ConnectionOverflow);
         $Owners[] = $PackageOverflow;
         $overflowQueued = $PackageOverflow->enqueue($SocketOverflow, 'X', []);

         $Package->purge();
         $afterQueuedPurge = TCPServer::$pendingBytes;

         TCPServer::$maxWorkerPendingBytes = $baseline + 160;
         $Package->uploading = $uploads;
         $Package->stagedUploading = $uploads;
         $activeMeasured = $Package->inspect();
         $activeReserved = $Package->Buffers->reserve($activeMeasured);
         $activeTotal = TCPServer::$pendingBytes;
         $Package->purge();

         $probe['pads'] = [
            'queued' => $queued,
            'queued_measured' => $queuedMeasured,
            'queued_total' => $queuedTotal,
            'overflow_queued' => $overflowQueued,
            'overflow_closed' => $ConnectionOverflow->closed,
            'overflow_retained' => $PackageOverflow->Buffers->retained,
            'after_queued_purge' => $afterQueuedPurge,
            'active_measured' => $activeMeasured,
            'active_reserved' => $activeReserved,
            'active_total' => $activeTotal,
            'after_active_purge' => TCPServer::$pendingBytes,
         ];

         // # Receive-carry aggregation uses the same worker authority.
         $fragment = 'GET /l1-carry HTT';
         $bytes = strlen($fragment);
         TCPServer::$maxWorkerPendingBytes = $baseline + (2 * $bytes) - 1;

         $SocketA = fopen('php://temp', 'w+');
         $SocketB = fopen('php://temp', 'w+');
         if (! is_resource($SocketA) || ! is_resource($SocketB)) {
            throw new RuntimeException('Could not create carry fixture streams.');
         }
         $Resources[] = $SocketA;
         $Resources[] = $SocketB;

         $ConnectionA = new L1LedgerConnection($SocketA, 18402);
         $ConnectionB = new L1LedgerConnection($SocketB, 18403);
         $PackageA = new L1LedgerPackage($ConnectionA);
         $PackageB = new L1LedgerPackage($ConnectionB);
         $Owners[] = $PackageA;
         $Owners[] = $PackageB;

         $PackageA->hold($fragment);
         $afterFirst = TCPServer::$pendingBytes;
         $PackageB->hold($fragment);
         $afterSecond = TCPServer::$pendingBytes;
         $PackageA->drop();
         $afterDrop = TCPServer::$pendingBytes;

         $probe['carry'] = [
            'bytes' => $bytes,
            'first_closed' => $ConnectionA->closed,
            'first_retained' => $PackageA->Buffers->retained,
            'first_carry_after_drop' => strlen($PackageA->carry),
            'second_closed' => $ConnectionB->closed,
            'second_retained' => $PackageB->Buffers->retained,
            'second_carry' => strlen($PackageB->carry),
            'after_first' => $afterFirst,
            'after_second' => $afterSecond,
            'after_drop' => $afterDrop,
         ];

         // # A failed first response write closes and releases the Package.
         //   The same read callback must stop immediately: decoding a
         //   pipelined incomplete tail afterward would reacquire carry on a
         //   dead connection until cycle collection.
         $scheme = 'bootgly-l1-write-failure';
         if (! in_array($scheme, stream_get_wrappers(), true)) {
            stream_wrapper_register($scheme, L1WriteFailureStream::class);
         }

         $input = 'REQ1TAIL';
         $tail = 'TAIL';
         L1WriteFailureEncoder::$wire = str_repeat('R', 16);
         TCPServer::$maxWorkerPendingBytes = $baseline + 64;
         TCPServer::$Encoder = new L1WriteFailureEncoder;

         // @ Positive control: a successful first write keeps pipelining and
         //   retains exactly the decoder-declared incomplete tail.
         L1WriteFailureStream::reset('control', $input, false);
         $ControlSocket = fopen("{$scheme}://control/probe", 'w+');
         if (! is_resource($ControlSocket)) {
            throw new RuntimeException('Could not create the write-success control stream.');
         }
         $Resources[] = $ControlSocket;

         // ! Keep the same cap as the failure leg. A complete socket write
         //   retains no bytes, so a wire larger than the retention ceiling is
         //   valid and isolates transport progress as the only variable.
         TCPServer::$maxPendingBytes = 8;
         $ControlConnection = new L1WriteFailureConnection($ControlSocket, 18412);
         $ControlPackage = new L1WriteFailurePackage($ControlConnection);
         $ControlDecoder = new L1WriteFailureDecoder;
         $Owners[] = $ControlPackage;
         TCPServer::$Decoder = $ControlDecoder;

         $controlRead = $ControlPackage->reading($ControlSocket);
         $control = [
            'read' => $controlRead,
            'calls' => $ControlDecoder->calls,
            'closed' => $ControlConnection->closed,
            'written' => L1WriteFailureStream::$written['control'],
            'carry' => $ControlPackage->carry,
            'retained' => $ControlPackage->Buffers->retained,
            'total' => TCPServer::$pendingBytes,
         ];
         $ControlPackage->purge();
         $control['after_cleanup'] = TCPServer::$pendingBytes;

         // ! Attack/misuse leg: the wire exceeds the per-Package retained cap
         //   and the transport makes zero progress, forcing writing() to abort.
         //   No subsequent decoder invocation or retained owner is admissible.
         L1WriteFailureStream::reset('attack', $input, true);
         $AttackSocket = fopen("{$scheme}://attack/probe", 'w+');
         if (! is_resource($AttackSocket)) {
            throw new RuntimeException('Could not create the write-failure probe stream.');
         }
         $Resources[] = $AttackSocket;

         TCPServer::$maxPendingBytes = 8;
         $AttackConnection = new L1WriteFailureConnection($AttackSocket, 18413);
         $AttackPackage = new L1WriteFailurePackage($AttackConnection);
         $AttackDecoder = new L1WriteFailureDecoder;
         $Owners[] = $AttackPackage;
         TCPServer::$Decoder = $AttackDecoder;

         $attackRead = $AttackPackage->reading($AttackSocket);
         $attack = [
            'read' => $attackRead,
            'calls' => $AttackDecoder->calls,
            'closed' => $AttackConnection->closed,
            'written' => L1WriteFailureStream::$written['attack'],
            'carry' => $AttackPackage->carry,
            'retained' => $AttackPackage->Buffers->retained,
            'total' => TCPServer::$pendingBytes,
         ];
         $AttackPackage->purge();
         $attack['after_cleanup'] = TCPServer::$pendingBytes;

         // ! Normal terminal drain is distinct from writing() failure: it
         //   closes after successfully flushing an already-deferred owner and
         //   returns true. reading() must still stop before the pipeline tail.
         $ClosePair = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
         );
         if ($ClosePair === false) {
            throw new RuntimeException('Could not create the close-after-drain probe sockets.');
         }
         [$CloseSocket, $ClosePeer] = $ClosePair;
         stream_set_blocking($CloseSocket, false);
         stream_set_blocking($ClosePeer, false);
         $Resources[] = $CloseSocket;
         $Resources[] = $ClosePeer;

         TCPServer::$maxPendingBytes = 64;
         TCPServer::$maxWorkerPendingBytes = $baseline + 64;
         $CloseConnection = new L1WriteFailureConnection($CloseSocket, 18414);
         $ClosePackage = new L1WriteFailurePackage($CloseConnection);
         $CloseDecoder = new L1WriteFailureDecoder;
         $Owners[] = $ClosePackage;
         TCPServer::$Decoder = $CloseDecoder;

         $Fill($CloseSocket);
         $parked = $ClosePackage->writing($CloseSocket, buffer: 'OLD');
         $parkedPending = $ClosePackage->pendingBuffer;
         $parkedRetained = $ClosePackage->Buffers->retained;
         $parkedTotal = TCPServer::$pendingBytes;
         $ClosePackage->closeAfterDrain = true;
         while (true) {
            $drained = @fread($ClosePeer, 65536);
            if ($drained === false || $drained === '') {
               break;
            }
         }
         if (@fwrite($ClosePeer, $input) !== strlen($input)) {
            throw new RuntimeException('Could not feed the close-after-drain probe.');
         }

         $closeRead = $ClosePackage->reading($CloseSocket);
         $closeWritten = '';
         while (true) {
            $drained = @fread($ClosePeer, 65536);
            if ($drained === false || $drained === '') {
               break;
            }
            $closeWritten .= $drained;
         }
         $close = [
            'parked' => $parked,
            'parked_pending' => $parkedPending,
            'parked_retained' => $parkedRetained,
            'parked_total' => $parkedTotal,
            'read' => $closeRead,
            'calls' => $CloseDecoder->calls,
            'closed' => $CloseConnection->closed,
            'written' => $closeWritten,
            'carry' => $ClosePackage->carry,
            'retained' => $ClosePackage->Buffers->retained,
            'total' => TCPServer::$pendingBytes,
         ];
         $ClosePackage->purge();
         $close['after_cleanup'] = TCPServer::$pendingBytes;

         // ! Exercise the write inside the pipeline loop, not its top-level
         //   twin: Complete -> Complete -> Incomplete. The encoder makes the
         //   second response terminal, so a missing post-write status guard
         //   would decode and retain the third segment after the close.
         $pipelineInput = 'REQ1REQ2TAIL';
         L1WriteFailureStream::reset('pipeline-close', $pipelineInput, false);
         $PipelineSocket = fopen("{$scheme}://pipeline-close/probe", 'w+');
         if (! is_resource($PipelineSocket)) {
            throw new RuntimeException('Could not create the pipelined-close probe stream.');
         }
         $Resources[] = $PipelineSocket;

         TCPServer::$maxPendingBytes = 8;
         TCPServer::$maxWorkerPendingBytes = $baseline + 64;
         $PipelineConnection = new L1WriteFailureConnection($PipelineSocket, 18415);
         $PipelinePackage = new L1WriteFailurePackage($PipelineConnection);
         $PipelineDecoder = new L1PipelineCloseDecoder;
         $Owners[] = $PipelinePackage;
         L1PipelineCloseEncoder::$calls = 0;
         L1PipelineCloseEncoder::$wire = str_repeat('P', 16);
         TCPServer::$Decoder = $PipelineDecoder;
         TCPServer::$Encoder = new L1PipelineCloseEncoder;

         $pipelineRead = $PipelinePackage->reading($PipelineSocket);
         $pipelineClose = [
            'read' => $pipelineRead,
            'decoder_calls' => $PipelineDecoder->calls,
            'encoder_calls' => L1PipelineCloseEncoder::$calls,
            'closed' => $PipelineConnection->closed,
            'written' => L1WriteFailureStream::$written['pipeline-close'],
            'carry' => $PipelinePackage->carry,
            'retained' => $PipelinePackage->Buffers->retained,
            'total' => TCPServer::$pendingBytes,
         ];
         $PipelinePackage->purge();
         $pipelineClose['after_cleanup'] = TCPServer::$pendingBytes;

         $probe['write_failure'] = [
            'input' => $input,
            'tail' => $tail,
            'wire_bytes' => strlen(L1WriteFailureEncoder::$wire),
            'control' => $control,
            'attack' => $attack,
            'close' => $close,
            'pipeline_close' => $pipelineClose,
         ];
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($Decoders as $Decoder) {
            $Decoder->disconnect();
         }
         foreach ($Streams as $Stream) {
            $Stream->close();
         }
         foreach ($Owners as $Owner) {
            $Owner->Buffers->release();
         }
         foreach ($Tokens as $Token) {
            $Token->release();
         }
         // ? The destructor-fallback leg unsets this variable on purpose, so
         //   reaching that point leaves nothing to release here. Test the
         //   binding first: the framework promotes the "undefined variable"
         //   warning to an ErrorException, which would abort this cleanup.
         if (isSet($Destructor) && $Destructor instanceof Buffers) {
            $Destructor->release();
         }
         foreach ($Resources as $Resource) {
            if (is_resource($Resource)) {
               @fclose($Resource);
            }
         }
         foreach ($paths as $path) {
            if (is_file($path)) {
               @unlink($path);
            }
         }
         TCPServer::$Decoder = $OldDecoder;
         TCPServer::$Encoder = $OldEncoder;
         TCPServer::$maxPendingBytes = $savedPendingCap;
         TCPServer::$maxWorkerPendingBytes = $savedWorkerCap;
         $probe['final'] = TCPServer::$pendingBytes;
      }

      return "GET /l1-ledger-lifecycle HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) {
      yield $Router->route(
         '/l1-ledger-lifecycle',
         static function (Request $Request, Response $Response): Response {
            return $Response(code: 200, body: 'L1-LEDGER-LIFECYCLE-OK');
         },
         GET,
      );

      yield $Router->route(
         '/*',
         static function (Request $Request, Response $Response): Response {
            return $Response(code: 404, body: 'Not Found');
         },
      );
   },

   test: static function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['L1 ledger lifecycle fixture'];
         dump(json_encode($probe));

         return 'L1 ledger lifecycle fixture failed: ' . $probe['error'];
      }
      if (! str_contains($response, 'L1-LEDGER-LIFECYCLE-OK')) {
         return 'L1 ledger lifecycle request did not reach its control route.';
      }

      $baseline = $probe['baseline'];
      $accountant = $probe['accountant'];
      $atCap = $accountant['at_cap'] ?? [];
      if (
         ! is_int($baseline)
         || ($atCap['package_accepted'] ?? null) !== true
         || ($atCap['stream_accepted'] ?? null) !== true
         || ($atCap['overflow_accepted'] ?? null) !== false
         || ($atCap['stream_measured'] ?? null) !== 768
         || ($atCap['package_retained'] ?? null) !== 384
         || ($atCap['stream_retained'] ?? null) !== 768
         || ($atCap['overflow_retained'] ?? null) !== 0
         || ($atCap['total'] ?? null) !== $baseline + 1152
      ) {
         Vars::$labels = ['L1 exact cross-owner cap'];
         dump(json_encode($probe));

         return 'L1 ledger did not enforce the exact shared Package/HTTP2 cap.';
      }

      if (
         ($accountant['shrunk'] ?? null) !== true
         || ($accountant['after_shrink'] ?? null) !== $baseline + 896
         || ($accountant['after_release'] ?? null) !== $baseline + 768
         || ($accountant['after_duplicate'] ?? null) !== $baseline + 768
         || ($accountant['after_stream_close'] ?? null) !== $baseline
         || ($accountant['destructor_accepted'] ?? null) !== true
         || ($accountant['during_destructor'] ?? null) !== $baseline + 1
         || ($accountant['after_destructor'] ?? null) !== $baseline
         || ($accountant['zero_accepted'] ?? null) !== false
         || ($accountant['zero_retained'] ?? null) !== 0
      ) {
         Vars::$labels = ['L1 shrink and cleanup'];
         dump(json_encode($probe));

         return 'L1 ledger shrink, idempotent release, destructor, or zero-cap '
            . 'semantics were not exact.';
      }

      $reset = $accountant['reset'] ?? [];
      if (
         ($reset['accepted'] ?? null) !== true
         || ($reset['before'] ?? null) !== 64
         || ($reset['after'] ?? null) !== 0
         || ($reset['after_duplicate'] ?? null) !== 0
         || ($reset['fresh_accepted'] ?? null) !== true
         || ($reset['after_fresh'] ?? null) !== 64
         || ($reset['after_cleanup'] ?? null) !== 0
      ) {
         Vars::$labels = ['L1 fresh-worker ledger reset'];
         dump(json_encode($probe));

         return 'L1 Buffers::reset did not clear the authoritative ledger '
            . 'idempotently or permit a fresh full-cap reservation.';
      }

      $H2Carry = $probe['h2_carry'];
      if (
         ($H2Carry['partial_a'] ?? null) !== true
         || ($H2Carry['partial_a_bytes'] ?? null) !== 12
         || ($H2Carry['partial_a_retained'] ?? null) !== 12
         || ($H2Carry['partial_total'] ?? null) !== $baseline + 12
         || ($H2Carry['partial_b'] ?? null) !== true
         || ($H2Carry['partial_b_closed'] ?? null) !== true
         || ($H2Carry['partial_b_retained'] ?? null) !== 0
         || ($H2Carry['partial_overflow_total'] ?? null) !== $baseline + 12
         || ($H2Carry['partial_complete'] ?? null) !== true
         || ($H2Carry['partial_complete_retained'] ?? null) !== 0
         || ($H2Carry['partial_released'] ?? null) !== $baseline
         || ! is_int($H2Carry['fragment_bytes'] ?? null)
         || $H2Carry['fragment_bytes'] <= 0
         || ($H2Carry['fragment_a'] ?? null) !== true
         || ($H2Carry['fragment_a_retained'] ?? null) !== $H2Carry['fragment_bytes']
         || ($H2Carry['fragment_total'] ?? null) !== $baseline + $H2Carry['fragment_bytes']
         || ($H2Carry['fragment_c'] ?? null) !== true
         || ($H2Carry['fragment_c_closed'] ?? null) !== true
         || ($H2Carry['fragment_c_retained'] ?? null) !== 0
         || ($H2Carry['fragment_overflow_total'] ?? null) !== $baseline + $H2Carry['fragment_bytes']
         || ($H2Carry['fragment_complete'] ?? null) !== true
         || ($H2Carry['fragment_complete_retained'] ?? null) !== 0
         || ! is_int($H2Carry['fragment_hpack_retained'] ?? null)
         || $H2Carry['fragment_hpack_retained'] <= 0
         || ($H2Carry['fragment_released'] ?? null)
            !== $baseline + $H2Carry['fragment_hpack_retained']
         || ($H2Carry['fragment_disconnected'] ?? null) !== $baseline
         || ($H2Carry['feed_bytes'] ?? null) !== 10
         || ($H2Carry['feed_d_retained'] ?? null) !== 10
         || ($H2Carry['feed_total'] ?? null) !== $baseline + 10
         || ($H2Carry['feed_e_closed'] ?? null) !== true
         || ($H2Carry['feed_e_retained'] ?? null) !== 0
         || ($H2Carry['feed_overflow_total'] ?? null) !== $baseline + 10
         || ($H2Carry['feed_released'] ?? null) !== $baseline
      ) {
         Vars::$labels = ['L1 HTTP/2 receive-carry accounting'];
         dump(json_encode($probe));

         return 'L1 HTTP/2 partial frames, CONTINUATION fragments, or fed tails '
            . 'bypassed the shared worker cap or failed exact release.';
      }

      $outbox = $probe['outbox'];
      $ackBytes = $outbox['ack_bytes'] ?? null;
      $headBytes = $outbox['head_bytes'] ?? null;
      $HPACKCapacity = $outbox['hpack_capacity'] ?? null;
      if (
         ($outbox['preface_a'] ?? null) !== true
         || ($outbox['preface_b'] ?? null) !== true
         || ! is_int($ackBytes)
         || $ackBytes <= 0
         || ! is_int($headBytes)
         || $headBytes <= 0
         || ! is_int($HPACKCapacity)
         || $HPACKCapacity <= 0
         || ($outbox['hpack_retained'] ?? null) !== $HPACKCapacity
         || ($outbox['a'] ?? null) !== true
         || ($outbox['a_decoder_bytes'] ?? null) !== 0
         || ($outbox['a_pending'] ?? null) !== $ackBytes
         || ($outbox['a_retained'] ?? null) !== $ackBytes
         || ($outbox['a_total'] ?? null)
            !== $baseline + $HPACKCapacity + $headBytes + $ackBytes
         || ($outbox['b'] ?? null) !== true
         || ($outbox['b_closed'] ?? null) !== true
         || ($outbox['b_decoder_bytes'] ?? null) !== 0
         || ($outbox['b_pending'] ?? null) !== 0
         || ($outbox['b_retained'] ?? null) !== 0
         || ($outbox['overflow_total'] ?? null)
            !== $baseline + $HPACKCapacity + $headBytes + $ackBytes
         || ($outbox['drained'] ?? null) !== true
         || ($outbox['drained_pending'] ?? null) !== 0
         || ($outbox['drained_retained'] ?? null) !== 0
         || ($outbox['drained_total'] ?? null) !== $baseline
      ) {
         Vars::$labels = ['L1 deferred HTTP/2 control-frame handoff'];
         dump(json_encode($probe));

         return 'L1 HTTP/2 control frames remained in the decoder across a '
            . 'Complete/deferred boundary or bypassed the shared worker cap.';
      }

      $batch = $probe['batch'];
      if (
         ($batch['calls'] ?? null) !== 3
         || ($batch['largest'] ?? null) !== 1024 * 1024
         || ($batch['progressive'] ?? null) !== true
         || ($batch['first_bytes'] ?? null) !== 1024 * 1024
         || ! is_int($batch['first_position'] ?? null)
         || $batch['first_position'] <= 0
         || $batch['first_position'] >= 2 * 1024 * 1024
         || ($batch['first_done'] ?? null) !== false
         || ($batch['done'] ?? null) !== true
         || ($batch['final_position'] ?? null) !== 2 * 1024 * 1024
         || ($batch['handler_persisted'] ?? null) !== false
         || ($batch['stream_chunk'] ?? null) !== 1
         || ($batch['stream_measured'] ?? null) !== 0
         || ($batch['stream_retained'] ?? null) !== 0
         || ($batch['total'] ?? null) !== $baseline
      ) {
         Vars::$labels = ['L1 bounded HTTP/2 file materialization'];
         dump(json_encode($probe));

         return 'L1 HTTP/2 file framing was not bounded to one serialized MiB '
            . 'per callback, retained a handler between callbacks, or failed '
            . 'to preserve disk-backed progress.';
      }

      $quantum = $probe['quantum'];
      if (
         ($quantum['before_bytes'] ?? null) !== 0
         || ($quantum['before_done'] ?? null) !== false
         || ($quantum['before_position'] ?? null) !== 0
         || ($quantum['before_handler'] ?? null) !== false
         || ($quantum['after_bytes'] ?? null) !== 4096 + 9
         || ($quantum['after_done'] ?? null) !== false
         || ($quantum['after_position'] ?? null) !== 4096
         || ($quantum['after_handler'] ?? null) !== false
         || ($quantum['continuation_wire_bytes'] ?? null) !== 10
         || ($quantum['continuation_done'] ?? null) !== false
         || ($quantum['continuation_budget'] ?? null) !== 4104
         || ($quantum['continuation_chunk'] ?? null) !== 1
         || ($quantum['continuation_position'] ?? null) !== 0
         || ($quantum['continuation_connection_window'] ?? null) !== 65534
         || ($quantum['continuation_stream_window'] ?? null) !== 65534
         || ($quantum['continuations'] ?? null) !== 1
         || ($quantum['stream_credit_wire_bytes'] ?? null) !== 0
         || ($quantum['stream_credit_done'] ?? null) !== false
         || ($quantum['stream_credit_budget'] ?? null) !== 4114
         || ($quantum['connection_credit_wire_bytes'] ?? null) !== 0
         || ($quantum['connection_credit_done'] ?? null) !== false
         || ($quantum['connection_credit_budget'] ?? null) !== 4114
         || ($quantum['control_position'] ?? null) !== 0
         || ($quantum['control_continuations'] ?? null) !== 1
      ) {
         Vars::$labels = ['H2 HTTP/2 file reopen quantum'];
         dump(json_encode($probe));

         return 'H2 large HTTP/2 file responses reopened for sub-quantum '
            . 'credit, retained a handler, or missed one local continuation '
            . 'after useful credit was drained.';
      }

      $resume = $probe['resume'];
      if (
         ($resume['reserved'] ?? null) !== true
         || ($resume['written'] ?? null) !== true
         || ($resume['calls'] ?? null) !== 1
         || ($resume['pending'] ?? null) !== ''
         || ($resume['retained'] ?? null) !== 0
         || ($resume['total'] ?? null) !== $baseline
      ) {
         Vars::$labels = ['L1 event-driven protocol continuation'];
         dump(json_encode($probe));

         return 'L1 terminal TCP drain did not resume its protocol continuation '
            . 'exactly once after releasing the retained-byte owner.';
      }

      $pads = $probe['pads'];
      if (
         ($pads['queued'] ?? null) !== true
         || ($pads['queued_measured'] ?? null) !== 144
         || ($pads['queued_total'] ?? null) !== $baseline + 144
         || ($pads['overflow_queued'] ?? null) !== false
         || ($pads['overflow_closed'] ?? null) !== true
         || ($pads['overflow_retained'] ?? null) !== 0
         || ($pads['after_queued_purge'] ?? null) !== $baseline
         || ($pads['active_measured'] ?? null) !== 160
         || ($pads['active_reserved'] ?? null) !== true
         || ($pads['active_total'] ?? null) !== $baseline + 160
         || ($pads['after_active_purge'] ?? null) !== $baseline
      ) {
         Vars::$labels = ['L1 file-pad ownership'];
         dump(json_encode($probe));

         return 'L1 queued, active, or staged file pads bypassed exact accounting.';
      }

      $carry = $probe['carry'];
      $bytes = $carry['bytes'] ?? null;
      if (
         ! is_int($bytes)
         || $bytes <= 0
         || ($carry['first_closed'] ?? null) !== false
         || ($carry['second_closed'] ?? null) !== true
         || ($carry['second_retained'] ?? null) !== 0
         || ($carry['second_carry'] ?? null) !== 0
         || ($carry['after_first'] ?? null) !== $baseline + $bytes
         || ($carry['after_second'] ?? null) !== $baseline + $bytes
         || ($carry['after_drop'] ?? null) !== $baseline
         || ($carry['first_retained'] ?? null) !== 0
         || ($carry['first_carry_after_drop'] ?? null) !== 0
      ) {
         Vars::$labels = ['L1 carry admission and release'];
         dump(json_encode($probe));

         return 'L1 carry owners bypassed the shared cap or failed exact release.';
      }

      $writeFailure = $probe['write_failure'];
      $control = $writeFailure['control'] ?? [];
      $attack = $writeFailure['attack'] ?? [];
      $close = $writeFailure['close'] ?? [];
      $pipelineClose = $writeFailure['pipeline_close'] ?? [];
      $tail = $writeFailure['tail'] ?? null;
      $wireBytes = $writeFailure['wire_bytes'] ?? null;
      if (
         ! is_string($tail)
         || $tail === ''
         || ! is_int($wireBytes)
         || $wireBytes <= 8
         || ($control['read'] ?? null) !== true
         || ($control['calls'] ?? null) !== 2
         || ($control['closed'] ?? null) !== false
         || ($control['written'] ?? null) !== L1WriteFailureEncoder::$wire
         || ($control['carry'] ?? null) !== $tail
         || ($control['retained'] ?? null) !== strlen($tail)
         || ($control['total'] ?? null) !== $baseline + strlen($tail)
         || ($control['after_cleanup'] ?? null) !== $baseline
      ) {
         Vars::$labels = ['L1 initial-write failure positive control'];
         dump(json_encode($probe));

         return 'L1 write-failure fixture did not prove that successful output '
            . 'continues pipelining and retains the declared incomplete tail.';
      }

      if (
         ($attack['read'] ?? null) !== true
         || ($attack['calls'] ?? null) !== 1
         || ($attack['closed'] ?? null) !== true
         || ($attack['written'] ?? null) !== ''
         || ($attack['carry'] ?? null) !== ''
         || ($attack['retained'] ?? null) !== 0
         || ($attack['total'] ?? null) !== $baseline
         || ($attack['after_cleanup'] ?? null) !== $baseline
      ) {
         Vars::$labels = ['L1 post-close pipeline retention'];
         dump(json_encode($probe));

         return 'L1 still reproduced: a failed initial response write closed and '
            . 'released the connection, but reading() continued decoding and '
            . 'retained a pipelined incomplete tail afterward.';
      }

      if (
         ($close['parked'] ?? null) !== true
         || ($close['parked_pending'] ?? null) !== 'OLD'
         || ($close['parked_retained'] ?? null) !== 3
         || ($close['parked_total'] ?? null) !== $baseline + 3
         || ($close['read'] ?? null) !== true
         || ($close['calls'] ?? null) !== 1
         || ($close['closed'] ?? null) !== true
         || ($close['written'] ?? null) !== 'OLD' . L1WriteFailureEncoder::$wire
         || ($close['carry'] ?? null) !== ''
         || ($close['retained'] ?? null) !== 0
         || ($close['total'] ?? null) !== $baseline
         || ($close['after_cleanup'] ?? null) !== $baseline
      ) {
         Vars::$labels = ['L1 post-drain close pipeline retention'];
         dump(json_encode($probe));

         return 'L1 still reproduced: a terminal close-after-drain flushed and '
            . 'closed the connection, but reading() continued decoding and '
            . 'retained a pipelined incomplete tail afterward.';
      }

      if (
         ($pipelineClose['read'] ?? null) !== true
         || ($pipelineClose['decoder_calls'] ?? null) !== 2
         || ($pipelineClose['encoder_calls'] ?? null) !== 2
         || ($pipelineClose['closed'] ?? null) !== true
         || ($pipelineClose['written'] ?? null)
            !== L1PipelineCloseEncoder::$wire . L1PipelineCloseEncoder::$wire
         || ($pipelineClose['carry'] ?? null) !== ''
         || ($pipelineClose['retained'] ?? null) !== 0
         || ($pipelineClose['total'] ?? null) !== $baseline
         || ($pipelineClose['after_cleanup'] ?? null) !== $baseline
      ) {
         Vars::$labels = ['L1 in-loop post-drain close pipeline retention'];
         dump(json_encode($probe));

         return 'L1 still reproduced: the second response in one pipelined read '
            . 'closed after a successful terminal drain, but reading() decoded '
            . 'and retained the following incomplete tail afterward.';
      }

      if ($probe['final'] !== $baseline) {
         Vars::$labels = ['L1 final ledger'];
         dump(json_encode($probe));

         return 'L1 lifecycle cleanup did not restore the baseline counter.';
      }

      return true;
   },
);
