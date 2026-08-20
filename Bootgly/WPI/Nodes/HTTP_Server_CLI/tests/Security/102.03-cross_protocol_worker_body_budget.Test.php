<?php


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies as WorkerBodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Waiting;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


if (! class_exists('HTTPServerCLIM6BodyConnection', false)) {
   class HTTPServerCLIM6BodyConnection extends Connection
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

      // These decoder probes do not own a selector registration.
      public function close (): true
      {
         $this->status = Connections::STATUS_CLOSED;

         return true;
      }
   }
}


/**
 * Security PoC M6 (Medium) -- HTTP/1 and HTTP/2 unfinished request bodies have
 * no common worker-wide aggregate authority.
 *
 * The pre-remediation protocol knobs were distinct. The retained regression
 * sets the canonical worker ledger to a reduced 96-byte policy while leaving
 * the subordinate HTTP/2 controls at 192 bytes, proves the useful exact
 * boundary, then attempts byte 97 in both protocol orders. This proves the
 * common authority is decisive and rejects an over-restrictive fix that simply
 * forbids mixed HTTP/1 + HTTP/2 retention.
 *
 * Every leg uses the production Content-Length/Decoder_Waiting path or the
 * production HTTP/2 DATA decoder. Small values exercise the same private static
 * totals as the 64 MiB defaults without allocating material memory.
 */
$Probe = new class {
   public string $error = '';
   public int $aggregateCap = 96;
   public int $firstBytes = 64;
   public int $boundaryBytes = 32;
   /** @var array<string,mixed> */
   public array $HTTP1Control = [];
   /** @var array<string,mixed> */
   public array $HTTP2Control = [];
   /** @var array<string,mixed> */
   public array $HTTP1First = [];
   /** @var array<string,mixed> */
   public array $HTTP2First = [];
   /** @var array<string,mixed> */
   public array $cleanup = [];
};

return new Test(
   description: 'HTTP/1 and HTTP/2 unfinished bodies need a shared aggregate worker budget',
   Separator: new Separator(line: true),

   request: static function () use ($Probe): string {
      $oldRequestBodySize = Request::$maxBodySize;
      $oldWorkerBodySize = WorkerBodies::$maxWorkerBodySize;
      $oldHTTP2ConnectionSize = Decoder_HTTP2::$maxConnectionBodySize;
      $oldHTTP2WorkerSize = Decoder_HTTP2::$maxWorkerBodySize;
      $OldRequest = Server::$Request;

      /** @var array<int,resource> $Resources */
      $Resources = [];
      /** @var array<int,Decoder_Waiting> $HTTP1Decoders */
      $HTTP1Decoders = [];
      /** @var array<int,Decoder_HTTP2> $HTTP2Decoders */
      $HTTP2Decoders = [];
      /** @var array<int,TCPPackages> $Packages */
      $Packages = [];

      $BuildHTTP1 = static function (
         string $path,
         int $bytes,
         int $port,
      ) use (&$Resources, &$HTTP1Decoders, &$Packages): array {
         $Socket = fopen('php://temp', 'w+b');
         if (! is_resource($Socket)) {
            throw new RuntimeException('Could not create the M6 HTTP/1 decoder stream.');
         }
         $Resources[] = $Socket;

         $Connection = new HTTPServerCLIM6BodyConnection($Socket, $port);
         $Package = new class($Connection) extends TCPPackages {};
         $Packages[] = $Package;

         $head = "POST {$path} HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "Content-Length: 128\r\n\r\n";
         $wire = $head . str_repeat('A', $bytes);
         $State = (new Decoder_)->decode($Package, $wire, strlen($wire));
         $Decoder = $Package->Decoder;
         if (! $Decoder instanceof Decoder_Waiting) {
            throw new RuntimeException('M6 HTTP/1 input did not install Decoder_Waiting.');
         }
         $HTTP1Decoders[] = $Decoder;

         return [
            'State' => $State,
            'Package' => $Package,
            'Connection' => $Connection,
            'Decoder' => $Decoder,
            'Request' => $Package->decoded,
         ];
      };

      $BuildHTTP2 = static function (
         string $path,
         int $bytes,
         int $port,
      ) use (&$Resources, &$HTTP2Decoders, &$Packages): array {
         $Socket = fopen('php://temp', 'w+b');
         if (! is_resource($Socket)) {
            throw new RuntimeException('Could not create the M6 HTTP/2 decoder stream.');
         }
         $Resources[] = $Socket;

         $Connection = new HTTPServerCLIM6BodyConnection($Socket, $port);
         $Package = new class($Connection) extends TCPPackages {};
         $Packages[] = $Package;
         $Decoder = new Decoder_HTTP2;
         $HTTP2Decoders[] = $Decoder;
         $Package->Decoder = $Decoder;
         $Package->decoded = $Decoder;

         $block = HPACK::encode([
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', $path],
            [':authority', 'localhost'],
            ['content-length', '128'],
         ]);
         $wire = HTTP2::PREFACE
            . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
            . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 1, $block)
            . Frame::pack(HTTP2::FRAME_DATA, 0, 1, str_repeat('B', $bytes));
         $State = $Decoder->decode($Package, $wire, strlen($wire));

         return [
            'State' => $State,
            'Package' => $Package,
            'Connection' => $Connection,
            'Decoder' => $Decoder,
            'Stream' => $Decoder->Streams[1] ?? null,
         ];
      };

      $ReleaseHTTP1 = static function (null|array &$Fixture): void {
         if (! is_array($Fixture)) {
            return;
         }
         $Decoder = $Fixture['Decoder'] ?? null;
         $Package = $Fixture['Package'] ?? null;
         if ($Decoder instanceof Decoder_Waiting) {
            $Decoder->disconnect();
         }
         if ($Package instanceof TCPPackages) {
            $Package->Decoder = null;
            $Package->decoded = null;
         }
         $Fixture = null;
      };

      $ReleaseHTTP2 = static function (null|array &$Fixture): void {
         if (! is_array($Fixture)) {
            return;
         }
         $Decoder = $Fixture['Decoder'] ?? null;
         $Package = $Fixture['Package'] ?? null;
         if ($Decoder instanceof Decoder_HTTP2) {
            $Decoder->disconnect();
         }
         if ($Package instanceof TCPPackages) {
            $Package->Decoder = null;
            $Package->decoded = null;
         }
         $Fixture = null;
      };

      $HTTP1Control = null;
      $HTTP2Control = null;
      $HTTP1FirstHTTP1 = null;
      $HTTP1FirstHTTP2 = null;
      $HTTP2FirstHTTP2 = null;
      $HTTP2FirstHTTP1 = null;

      try {
         Request::$maxBodySize = 128;
         WorkerBodies::$maxWorkerBodySize = $Probe->aggregateCap;
         Decoder_HTTP2::$maxConnectionBodySize = 2 * $Probe->aggregateCap;
         Decoder_HTTP2::$maxWorkerBodySize = 2 * $Probe->aggregateCap;

         // Isolated controls prove both individual production decoder paths.
         $HTTP1Control = $BuildHTTP1('/m6-control-http1?cache=miss', 64, 18601);
         $ControlRequest = $HTTP1Control['Request'];
         $ControlDecoder = $HTTP1Control['Decoder'];
         $ControlPackage = $HTTP1Control['Package'];
         $Probe->HTTP1Control = [
            'state' => $HTTP1Control['State']->name,
            'rejected' => $ControlPackage->rejected,
            'waiting' => $ControlRequest instanceof Request
               ? $ControlRequest->Body->waiting
               : null,
            'retained' => $ControlDecoder->Bodies->retained,
         ];
         $ReleaseHTTP1($HTTP1Control);
         $Probe->HTTP1Control['released'] = $ControlDecoder->Bodies->retained;

         $HTTP2Control = $BuildHTTP2('/m6-control-http2', 64, 18602);
         $ControlDecoder = $HTTP2Control['Decoder'];
         $ControlPackage = $HTTP2Control['Package'];
         $ControlStream = $HTTP2Control['Stream'];
         $Probe->HTTP2Control = [
            'state' => $HTTP2Control['State']->name,
            'rejected' => $ControlPackage->rejected,
            'closing' => $ControlDecoder->closing,
            'stream_bytes' => $ControlStream === null ? -1 : strlen($ControlStream->body),
            'retained' => $ControlDecoder->Bodies->retained,
         ];
         $ReleaseHTTP2($HTTP2Control);
         $Probe->HTTP2Control['released'] = $ControlDecoder->Bodies->retained;

         // Order A: HTTP/1 owns 64, HTTP/2 reaches the exact remaining 32.
         $HTTP1FirstHTTP1 = $BuildHTTP1('/m6-http1-first?cache=miss', 64, 18603);
         $HTTP1FirstHTTP2 = $BuildHTTP2('/m6-http1-first', 32, 18604);
         $HTTP1Decoder = $HTTP1FirstHTTP1['Decoder'];
         $HTTP2Decoder = $HTTP1FirstHTTP2['Decoder'];
         $HTTP1Package = $HTTP1FirstHTTP1['Package'];
         $HTTP2Package = $HTTP1FirstHTTP2['Package'];
         $HTTP2Stream = $HTTP1FirstHTTP2['Stream'];
         $Probe->HTTP1First['boundary'] = [
            'http1_rejected' => $HTTP1Package->rejected,
            'http1_waiting' => $HTTP1Decoder->Request->Body->waiting,
            'http1_body_bytes' => strlen($HTTP1Decoder->Request->Body->raw),
            'http1_retained' => $HTTP1Decoder->Bodies->retained,
            'http2_rejected' => $HTTP2Package->rejected,
            'http2_closing' => $HTTP2Decoder->closing,
            'http2_stream_bytes' => $HTTP2Stream === null ? -1 : strlen($HTTP2Stream->body),
            'http2_retained' => $HTTP2Decoder->Bodies->retained,
            'combined' => $HTTP1Decoder->Bodies->retained + $HTTP2Decoder->Bodies->retained,
         ];

         $plus = Frame::pack(HTTP2::FRAME_DATA, 0, 1, 'B');
         $PlusState = $HTTP2Decoder->decode($HTTP2Package, $plus, strlen($plus));
         $HTTP2Stream = $HTTP2Decoder->Streams[1] ?? null;
         $Probe->HTTP1First['plus_one'] = [
            'state' => $PlusState->name,
            'http1_waiting' => $HTTP1Decoder->Request->Body->waiting,
            'http1_body_bytes' => strlen($HTTP1Decoder->Request->Body->raw),
            'http1_retained' => $HTTP1Decoder->Bodies->retained,
            'http2_rejected' => $HTTP2Package->rejected,
            'http2_closing' => $HTTP2Decoder->closing,
            'http2_stream_present' => $HTTP2Stream !== null,
            'http2_stream_bytes' => $HTTP2Stream === null ? 0 : strlen($HTTP2Stream->body),
            'http2_retained' => $HTTP2Decoder->Bodies->retained,
            'refused' => $PlusState === States::Rejected
               || $HTTP2Package->rejected
               || $HTTP2Decoder->closing
               || $HTTP2Stream === null,
            'combined' => $HTTP1Decoder->Bodies->retained + $HTTP2Decoder->Bodies->retained,
         ];
         $ReleaseHTTP1($HTTP1FirstHTTP1);
         $ReleaseHTTP2($HTTP1FirstHTTP2);

         // Order B: HTTP/2 owns 64, HTTP/1 reaches the exact remaining 32.
         $HTTP2FirstHTTP2 = $BuildHTTP2('/m6-http2-first', 64, 18605);
         $HTTP2FirstHTTP1 = $BuildHTTP1('/m6-http2-first?cache=miss', 32, 18606);
         $HTTP2Decoder = $HTTP2FirstHTTP2['Decoder'];
         $HTTP1Decoder = $HTTP2FirstHTTP1['Decoder'];
         $HTTP2Package = $HTTP2FirstHTTP2['Package'];
         $HTTP1Package = $HTTP2FirstHTTP1['Package'];
         $HTTP2Stream = $HTTP2FirstHTTP2['Stream'];
         $Probe->HTTP2First['boundary'] = [
            'http2_rejected' => $HTTP2Package->rejected,
            'http2_closing' => $HTTP2Decoder->closing,
            'http2_stream_bytes' => $HTTP2Stream === null ? -1 : strlen($HTTP2Stream->body),
            'http2_retained' => $HTTP2Decoder->Bodies->retained,
            'http1_rejected' => $HTTP1Package->rejected,
            'http1_waiting' => $HTTP1Decoder->Request->Body->waiting,
            'http1_body_bytes' => strlen($HTTP1Decoder->Request->Body->raw),
            'http1_retained' => $HTTP1Decoder->Bodies->retained,
            'combined' => $HTTP2Decoder->Bodies->retained + $HTTP1Decoder->Bodies->retained,
         ];

         $PlusState = $HTTP1Decoder->decode($HTTP1Package, 'A', 1);
         $HTTP2Stream = $HTTP2Decoder->Streams[1] ?? null;
         $Probe->HTTP2First['plus_one'] = [
            'state' => $PlusState->name,
            'http2_stream_bytes' => $HTTP2Stream === null ? -1 : strlen($HTTP2Stream->body),
            'http2_retained' => $HTTP2Decoder->Bodies->retained,
            'http1_rejected' => $HTTP1Package->rejected,
            'http1_waiting' => isset($HTTP1Decoder->Request)
               ? $HTTP1Decoder->Request->Body->waiting
               : false,
            'http1_retained' => $HTTP1Decoder->Bodies->retained,
            'refused' => $PlusState === States::Rejected || $HTTP1Package->rejected,
            'combined' => $HTTP2Decoder->Bodies->retained + $HTTP1Decoder->Bodies->retained,
         ];
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach (['HTTP1Control', 'HTTP1FirstHTTP1', 'HTTP2FirstHTTP1'] as $name) {
            $ReleaseHTTP1($$name);
         }
         foreach (['HTTP2Control', 'HTTP1FirstHTTP2', 'HTTP2FirstHTTP2'] as $name) {
            $ReleaseHTTP2($$name);
         }

         $Probe->cleanup = [
            'http1_tokens' => array_map(
               static fn (Decoder_Waiting $Decoder): int => $Decoder->Bodies->retained,
               $HTTP1Decoders,
            ),
            'http2_tokens' => array_map(
               static fn (Decoder_HTTP2 $Decoder): int => $Decoder->Bodies->retained,
               $HTTP2Decoders,
            ),
         ];

         // The shared static ledger must be reusable after every protocol
         // owner disconnects; per-object retained=0 alone cannot prove that.
         $Reusable = new WorkerBodies;
         $Probe->cleanup['reusable'] = $Reusable->reserve($Probe->aggregateCap);
         $Probe->cleanup['reusable_retained'] = $Reusable->retained;
         $Reusable->release();
         $Probe->cleanup['reusable_released'] = $Reusable->retained;

         foreach ($Resources as $Resource) {
            if (is_resource($Resource)) {
               fclose($Resource);
            }
         }

         Request::$maxBodySize = $oldRequestBodySize;
         WorkerBodies::$maxWorkerBodySize = $oldWorkerBodySize;
         Decoder_HTTP2::$maxConnectionBodySize = $oldHTTP2ConnectionSize;
         Decoder_HTTP2::$maxWorkerBodySize = $oldHTTP2WorkerSize;
         Server::$Request = $OldRequest;
      }

      return "GET /m6-body-budget-harness HTTP/1.1\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield $Router->route('/m6-body-budget-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'M6-BODY-BUDGET-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use ($Probe): bool|string {
      if (! str_contains($response, 'M6-BODY-BUDGET-HARNESS-OK')) {
         return 'M6 native harness did not receive its positive response.';
      }
      if ($Probe->error !== '') {
         return 'M6 fixture error: ' . $Probe->error;
      }
      if (
         ($Probe->HTTP1Control['state'] ?? null) !== States::Complete->name
         || ($Probe->HTTP1Control['rejected'] ?? null) !== false
         || ($Probe->HTTP1Control['waiting'] ?? null) !== true
         || ($Probe->HTTP1Control['retained'] ?? null) !== $Probe->firstBytes
         || ($Probe->HTTP1Control['released'] ?? null) !== 0
         || ($Probe->HTTP2Control['state'] ?? null) !== States::Incomplete->name
         || ($Probe->HTTP2Control['rejected'] ?? null) !== false
         || ($Probe->HTTP2Control['closing'] ?? null) !== false
         || ($Probe->HTTP2Control['stream_bytes'] ?? null) !== $Probe->firstBytes
         || ($Probe->HTTP2Control['retained'] ?? null) !== $Probe->firstBytes
         || ($Probe->HTTP2Control['released'] ?? null) !== 0
      ) {
         return 'M6 isolated production-decoder controls failed: ' . json_encode([
            'http1' => $Probe->HTTP1Control,
            'http2' => $Probe->HTTP2Control,
         ]);
      }

      foreach (['HTTP1First', 'HTTP2First'] as $order) {
         $Boundary = $Probe->{$order}['boundary'] ?? [];
         if (
            ($Boundary['http1_rejected'] ?? null) !== false
            || ($Boundary['http2_rejected'] ?? null) !== false
            || ($Boundary['http2_closing'] ?? null) !== false
            || ($Boundary['http1_waiting'] ?? null) !== true
            || ($Boundary['http1_retained'] ?? null)
               !== ($order === 'HTTP1First' ? $Probe->firstBytes : $Probe->boundaryBytes)
            || ($Boundary['http1_body_bytes'] ?? null)
               !== ($order === 'HTTP1First' ? $Probe->firstBytes : $Probe->boundaryBytes)
            || ($Boundary['http2_retained'] ?? null)
               !== ($order === 'HTTP1First' ? $Probe->boundaryBytes : $Probe->firstBytes)
            || ($Boundary['http2_stream_bytes'] ?? null)
               !== ($order === 'HTTP1First' ? $Probe->boundaryBytes : $Probe->firstBytes)
            || ($Boundary['combined'] ?? null) !== $Probe->aggregateCap
         ) {
            return "M6 {$order} exact-boundary control failed: " . json_encode($Boundary);
         }
      }

      $violations = [];
      foreach (['HTTP1First', 'HTTP2First'] as $order) {
         $Plus = $Probe->{$order}['plus_one'] ?? [];
         if (
            ($Plus['refused'] ?? false) === false
            && ($Plus['combined'] ?? 0) > $Probe->aggregateCap
         ) {
            $violations[$order] = $Plus;
         }
      }
      if ($violations !== []) {
         Vars::$labels = ['M6 cross-protocol aggregate-body evidence'];
         dump(json_encode([
            'policy' => ['aggregate_cap' => $Probe->aggregateCap],
            'violations' => $violations,
         ]));

         return 'CONFIRMED M6: after accepting the exact 96-byte mixed-protocol '
            . 'boundary, the independent HTTP/1 and HTTP/2 body authorities admitted byte 97 '
            . 'in these protocol orders: ' . implode(', ', array_keys($violations)) . '. Evidence: '
            . json_encode($violations);
      }

      $HTTP1First = $Probe->HTTP1First['plus_one'] ?? [];
      $HTTP2First = $Probe->HTTP2First['plus_one'] ?? [];
      if (
         ($HTTP1First['refused'] ?? null) !== true
         || ($HTTP1First['http1_waiting'] ?? null) !== true
         || ($HTTP1First['http1_body_bytes'] ?? null) !== $Probe->firstBytes
         || ($HTTP1First['http1_retained'] ?? null) !== $Probe->firstBytes
         || ($HTTP1First['combined'] ?? PHP_INT_MAX) > $Probe->aggregateCap
         || ($HTTP2First['refused'] ?? null) !== true
         || ($HTTP2First['http2_stream_bytes'] ?? null) !== $Probe->firstBytes
         || ($HTTP2First['http2_retained'] ?? null) !== $Probe->firstBytes
         || ($HTTP2First['combined'] ?? PHP_INT_MAX) > $Probe->aggregateCap
      ) {
         return 'M6 +1 refusal did not preserve the first owner and shared cap: '
            . json_encode([
               'http1_first' => $HTTP1First,
               'http2_first' => $HTTP2First,
            ]);
      }

      foreach (array_merge(
         $Probe->cleanup['http1_tokens'] ?? [-1],
         $Probe->cleanup['http2_tokens'] ?? [-1],
      ) as $retained) {
         if ($retained !== 0) {
            return 'M6 decoder cleanup left a body reservation: '
               . json_encode($Probe->cleanup);
         }
      }
      if (
         ($Probe->cleanup['reusable'] ?? null) !== true
         || ($Probe->cleanup['reusable_retained'] ?? null) !== $Probe->aggregateCap
         || ($Probe->cleanup['reusable_released'] ?? null) !== 0
      ) {
         return 'M6 shared worker ledger was not fully reusable after cleanup: '
            . json_encode($Probe->cleanup);
      }

      return true;
   },
);
