<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 HTTP/2 pre-reset Response-clone context regression.
 *
 * Deferred stream A leaves the worker-persistent Response bound to A's
 * transport, Request, Exchange and Cancellation. Stream B then arrives on a
 * different HTTP/2 connection. Before Response::reset(), a Received listener
 * clones that singleton. The clone must carry B's Package, stream and Exchange
 * while inheriting no scheduled generation from still-active A.
 */
$GatePair = stream_socket_pair(
   STREAM_PF_UNIX,
   STREAM_SOCK_STREAM,
   STREAM_IPPROTO_IP,
);
if ($GatePair === false) {
   throw new RuntimeException('L4-101.23 could not create its deferred rendezvous pair.');
}
[$gateWorker, $gateTest] = $GatePair;

$ResponseReflection = new ReflectionClass(Response::class);
$PackageProperty = $ResponseReflection->getProperty('Package');
$RequestProperty = $ResponseReflection->getProperty('Request');
$ExchangeProperty = $ResponseReflection->getProperty('Exchange');
$CancellationProperty = $ResponseReflection->getProperty('Cancellation');

$Probe = new class {
   public null|Emitter $Emitter = null;
   public mixed $Encoder = null;
   public null|Response $Clone = null;
   public null|Exchange $ExchangeA = null;
   public null|Exchange $ExchangeB = null;
   public null|Cancellation $CancellationA = null;
   public mixed $PackageA = null;
   public string $error = '';
   public string $marker = '';
   public int $receivedCalls = 0;
   public int $handlerB = 0;
   public bool $handlerExchangeB = false;
   /** @var array<string,mixed> */
   public array $sourceA = [];
   /** @var array<string,bool> */
   public array $boundary = [];
   /** @var list<array{owner:string,exchange:bool,code:null|int}> */
   public array $terminals = [];
   /** @var list<array{connection:string,type:int,flags:int,stream:int,payload:string}> */
   public array $Frames = [];
};

$Send = static function ($Socket, string $wire, string $label): void {
   if (is_resource($Socket) === false) {
      throw new RuntimeException("L4-101.23 {$label} socket is unavailable.");
   }

   $offset = 0;
   while ($offset < strlen($wire)) {
      $written = fwrite($Socket, substr($wire, $offset));
      if ($written === false || $written === 0) {
         break;
      }
      $offset += $written;
   }
   if ($offset !== strlen($wire)) {
      throw new RuntimeException("L4-101.23 {$label} write was incomplete.");
   }
};

$ReadLine = static function ($Socket): string {
   if (is_resource($Socket) === false) {
      return '';
   }

   stream_set_blocking($Socket, true);
   stream_set_timeout($Socket, 10);
   $line = '';
   while (str_contains($line, "\n") === false) {
      $chunk = fread($Socket, 8192);
      if ($chunk === false || $chunk === '') {
         break;
      }
      $line .= $chunk;
   }

   return $line;
};

$ReadUntil = static function (
   $Socket,
   string $connection,
   int $stream,
   string &$buffer,
) use ($Probe): void {
   if (is_resource($Socket) === false) {
      throw new RuntimeException("L4-101.23 connection {$connection} is unavailable.");
   }

   stream_set_blocking($Socket, false);
   $terminalAt = null;
   $deadline = microtime(true) + 5.0;
   while (microtime(true) < $deadline) {
      $read = [$Socket];
      $write = null;
      $except = null;
      $ready = stream_select($read, $write, $except, 0, 50000);
      if ($ready === false) {
         break;
      }
      if ($ready === 1) {
         $chunk = fread($Socket, 65536);
         if ($chunk === false) {
            break;
         }
         if ($chunk !== '') {
            $buffer .= $chunk;
         }
         else if (feof($Socket)) {
            break;
         }
      }

      while (strlen($buffer) >= 9) {
         $size = (ord($buffer[0]) << 16)
            | (ord($buffer[1]) << 8)
            | ord($buffer[2]);
         if (strlen($buffer) < 9 + $size) {
            break;
         }

         $Frame = [
            'connection' => $connection,
            'type' => ord($buffer[3]),
            'flags' => ord($buffer[4]),
            'stream' => (
               ((ord($buffer[5]) & 0x7f) << 24)
               | (ord($buffer[6]) << 16)
               | (ord($buffer[7]) << 8)
               | ord($buffer[8])
            ),
            'payload' => substr($buffer, 9, $size),
         ];
         $Probe->Frames[] = $Frame;
         $buffer = substr($buffer, 9 + $size);

         if (
            $Frame['stream'] === $stream
            && ($Frame['flags'] & HTTP2::FLAG_END_STREAM) !== 0
            && $terminalAt === null
         ) {
            $terminalAt = microtime(true);
         }
      }

      if ($terminalAt !== null && microtime(true) - $terminalAt >= 0.10) {
         return;
      }
   }

   throw new RuntimeException(
      "L4-101.23 connection {$connection} did not terminate stream {$stream}."
   );
};

return new Test(
   description: 'An HTTP/2 Received clone must carry the new pre-reset stream context',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/h2-prereset/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $gateTest,
         $gateWorker,
         $Probe,
         $ReadLine,
         $ReadUntil,
         $Send,
      ): string {
         if (is_resource($gateWorker)) {
            fclose($gateWorker);
         }

         try {
            $SocketA = stream_socket_client(
               "tcp://{$hostPort}",
               $errorCodeA,
               $errorMessageA,
               timeout: 5,
            );
            if ($SocketA === false) {
               throw new RuntimeException(
                  "L4-101.23 connection A failed: {$errorCodeA} {$errorMessageA}"
               );
            }

            $HeadersA = HPACK::encode([
               [':method', 'GET'],
               [':scheme', 'http'],
               [':path', '/l4/h2-prereset/a'],
               [':authority', 'localhost'],
               ['x-bootgly-test', (string) $testIndex],
            ]);
            $Send(
               $SocketA,
               HTTP2::PREFACE
                  . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, '')
                  . Frame::pack(
                     HTTP2::FRAME_HEADERS,
                     HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
                     1,
                     $HeadersA,
                  ),
               'request A',
            );

            $Probe->marker = $ReadLine($gateTest);
            if ($Probe->marker !== "L4-101.23-A-READY\n") {
               throw new RuntimeException(
                  'L4-101.23 A did not reach its deferred boundary: '
                     . json_encode($Probe->marker)
               );
            }

            $SocketB = stream_socket_client(
               "tcp://{$hostPort}",
               $errorCodeB,
               $errorMessageB,
               timeout: 5,
            );
            if ($SocketB === false) {
               throw new RuntimeException(
                  "L4-101.23 connection B failed: {$errorCodeB} {$errorMessageB}"
               );
            }

            $HeadersB = HPACK::encode([
               [':method', 'GET'],
               [':scheme', 'http'],
               [':path', '/l4/h2-prereset/b'],
               [':authority', 'localhost'],
               ['x-bootgly-test', (string) $testIndex],
            ]);
            $Send(
               $SocketB,
               HTTP2::PREFACE
                  . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, '')
                  . Frame::pack(
                     HTTP2::FRAME_HEADERS,
                     HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
                     3,
                     $HeadersB,
                  ),
               'request B',
            );

            $bufferB = '';
            $ReadUntil($SocketB, 'B', 3, $bufferB);

            if (fwrite($gateTest, 'A') !== 1) {
               throw new RuntimeException('L4-101.23 could not release deferred A.');
            }
            fclose($gateTest);

            $bufferA = '';
            $ReadUntil($SocketA, 'A', 1, $bufferA);
            fclose($SocketB);
            fclose($SocketA);
         }
         catch (Throwable $Throwable) {
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();

            if (is_resource($gateTest)) {
               @fwrite($gateTest, 'A');
               fclose($gateTest);
            }
            if (isset($SocketB) && is_resource($SocketB)) {
               fclose($SocketB);
            }
            if (isset($SocketA) && is_resource($SocketA)) {
               fclose($SocketA);
            }
         }

         return "GET /l4/h2-prereset/evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use (
      $CancellationProperty,
      $ExchangeProperty,
      $gateTest,
      $gateWorker,
      $PackageProperty,
      $Probe,
      $RequestProperty,
   ): Generator {
      yield $Router->route('/l4/h2-prereset/setup', static function (
         Request $Request,
         Response $Response,
      ) use (
         $CancellationProperty,
         $ExchangeProperty,
         $PackageProperty,
         $Probe,
         $RequestProperty,
      ): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;

         $Events = new Emitter;
         $Events->listen(
            RequestEvents::Received,
            static function (Emission $Emission) use (
               $CancellationProperty,
               $ExchangeProperty,
               $PackageProperty,
               $Probe,
               $RequestProperty,
            ): void {
               $RequestB = $Emission->payload[0] ?? null;
               if (
                  $RequestB instanceof Request === false
                  || $RequestB->URI !== '/l4/h2-prereset/b'
               ) {
                  return;
               }

               $Probe->receivedCalls++;
               $ExchangeB = Exchange::fetch($RequestB);
               $Probe->ExchangeB = $ExchangeB;
               $ExchangeB?->observe(static function (
                  Exchange $Observed,
                  null|int $code,
               ) use ($ExchangeB, $Probe): void {
                  $Probe->terminals[] = [
                     'owner' => 'B',
                     'exchange' => $Observed === $ExchangeB,
                     'code' => $code,
                  ];
               });

               $Source = Server::$Response;
               $SourcePackage = $PackageProperty->getValue($Source);
               $SourceRequest = $RequestProperty->getValue($Source);
               $SourceExchange = $ExchangeProperty->getValue($Source);
               $SourceCancellation = $CancellationProperty->getValue($Source);

               $Clone = clone $Source;
               $Probe->Clone = $Clone;
               $ClonePackage = $PackageProperty->getValue($Clone);
               $CloneRequest = $RequestProperty->getValue($Clone);
               $CloneCancellation = $CancellationProperty->getValue($Clone);

               $SourceConnection = is_object($SourcePackage)
                  ? ($SourcePackage->Connection ?? null)
                  : null;
               $Probe->boundary = [
                  'source_package_b' => $SourcePackage !== $Probe->PackageA
                     && is_object($SourceConnection)
                     && ($SourceConnection->port ?? null) === $RequestB->port,
                  'source_request_b' => $SourceRequest === $RequestB,
                  'source_stream_b' => $SourceRequest instanceof Request
                     && $SourceRequest->stream === 3,
                  'source_exchange_b' => $SourceExchange === $ExchangeB,
                  'source_cancellation_clear' => $SourceCancellation === null,
                  'clone_package_b' => $ClonePackage === $SourcePackage,
                  'clone_request_copy' => $CloneRequest instanceof Request
                     && $CloneRequest !== $RequestB
                     && $CloneRequest->URI === $RequestB->URI,
                  'clone_stream_b' => $CloneRequest instanceof Request
                     && $CloneRequest->stream === 3,
                  'clone_exchange_b' => $ExchangeB !== null
                     && Exchange::fetch($Clone) === $ExchangeB,
                  'clone_request_exchange_b' => $CloneRequest instanceof Request
                     && Exchange::fetch($CloneRequest) === $ExchangeB,
                  'clone_cancellation_clear' => $CloneCancellation === null,
                  'clone_cancellation_unlinked' => Cancellation::fetch($Clone) === null,
                  'exchange_b_unleased' => $ExchangeB !== null
                     && Cancellation::fetch($ExchangeB) === null,
                  'distinct_exchanges' => $ExchangeB !== null
                     && $Probe->ExchangeA !== null
                     && $ExchangeB !== $Probe->ExchangeA,
                  'exchange_a_active' => $Probe->ExchangeA?->check() === false,
                  'cancellation_a_active' => $Probe->CancellationA?->check() === false,
               ];
            },
         );
         Emitter::$Instance = $Events;
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-101.23-SETUP');
      }, GET);

      yield $Router->route('/l4/h2-prereset/a', static function (
         Request $Request,
         Response $Response,
      ) use (
         $CancellationProperty,
         $ExchangeProperty,
         $gateTest,
         $gateWorker,
         $PackageProperty,
         $Probe,
         $RequestProperty,
      ): Response {
         $ExchangeA = Exchange::fetch($Request);
         $Probe->ExchangeA = $ExchangeA;
         $ExchangeA?->observe(static function (
            Exchange $Observed,
            null|int $code,
         ) use ($ExchangeA, $Probe): void {
            $Probe->terminals[] = [
               'owner' => 'A',
               'exchange' => $Observed === $ExchangeA,
               'code' => $code,
            ];
         });

         $Response->defer(static function (Response $Deferred) use (
            $gateTest,
            $gateWorker,
         ): void {
            if (is_resource($gateTest)) {
               fclose($gateTest);
            }
            stream_set_blocking($gateWorker, false);
            $Deferred->wait($gateWorker);
            $release = fread($gateWorker, 1);
            fclose($gateWorker);
            if ($release !== 'A') {
               throw new RuntimeException('L4-101.23 A resumed without its release byte.');
            }

            $Deferred(code: 202, body: 'L4-101.23-A-202');
         });

         $PackageA = $PackageProperty->getValue($Response);
         $SourceRequestA = $RequestProperty->getValue($Response);
         $SourceExchangeA = $ExchangeProperty->getValue($Response);
         $CancellationA = $CancellationProperty->getValue($Response);
         $Probe->PackageA = $PackageA;
         $Probe->CancellationA = $CancellationA instanceof Cancellation
            ? $CancellationA
            : null;
         $Probe->sourceA = [
            'package_a' => is_object($PackageA),
            'request_a' => $SourceRequestA === $Request,
            'stream_a' => $SourceRequestA instanceof Request
               ? $SourceRequestA->stream
               : null,
            'exchange_a' => $SourceExchangeA === $ExchangeA,
            'cancellation_a' => $CancellationA instanceof Cancellation,
            'lease_a' => $ExchangeA !== null
               && $CancellationA instanceof Cancellation
               && Cancellation::fetch($ExchangeA) === $CancellationA,
            'exchange_active' => $ExchangeA?->check() === false,
            'cancellation_active' => $CancellationA instanceof Cancellation
               && $CancellationA->check() === false,
         ];

         $marker = "L4-101.23-A-READY\n";
         if (fwrite($gateWorker, $marker) !== strlen($marker)) {
            throw new RuntimeException('L4-101.23 A could not publish its barrier.');
         }

         return $Response;
      }, GET);

      yield $Router->route('/l4/h2-prereset/b', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->handlerB++;
         $Probe->handlerExchangeB = $Probe->ExchangeB !== null
            && Exchange::fetch($Request) === $Probe->ExchangeB;

         return $Response(code: 201, body: 'L4-101.23-B-201');
      }, GET);

      yield $Router->route('/l4/h2-prereset/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $evidence = [
            'received_calls' => $Probe->receivedCalls,
            'source_a' => $Probe->sourceA,
            'boundary' => $Probe->boundary,
            'handler_b' => $Probe->handlerB,
            'handler_exchange_b' => $Probe->handlerExchangeB,
            'terminal_a' => $Probe->ExchangeA?->check() ?? false,
            'terminal_b' => $Probe->ExchangeB?->check() ?? false,
            'cancellation_a_terminal' => $Probe->CancellationA?->check() ?? false,
            'terminals' => $Probe->terminals,
         ];

         $Probe->Clone = null;
         $Probe->ExchangeA = null;
         $Probe->ExchangeB = null;
         $Probe->CancellationA = null;
         $Probe->PackageA = null;
         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }

         return $Response(body: 'L4-101.23-EVIDENCE:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'L4-101.23-SETUP') === false
      ) {
         return 'L4-101.23 control failed: native setup response missing.';
      }
      if ($Probe->error !== '') {
         return 'L4-101.23 fixture failed: ' . $Probe->error;
      }
      if ($Probe->marker !== "L4-101.23-A-READY\n") {
         return 'L4-101.23 control failed: deferred A barrier missing.';
      }

      $HPACKs = [
         'A' => new HPACK(4096),
         'B' => new HPACK(4096),
      ];
      $statuses = [];
      $terminals = ['A:1' => 0, 'B:3' => 0];
      foreach ($Probe->Frames as $Frame) {
         $key = $Frame['connection'] . ':' . $Frame['stream'];
         if (array_key_exists($key, $terminals)) {
            if (($Frame['flags'] & HTTP2::FLAG_END_STREAM) !== 0) {
               $terminals[$key]++;
            }
            if ($Frame['type'] === HTTP2::FRAME_HEADERS) {
               $fields = $HPACKs[$Frame['connection']]->decode(
                  $Frame['payload'],
                  PHP_INT_MAX,
               );
               foreach ($fields ?? [] as [$name, $value]) {
                  if ($name === ':status') {
                     $statuses[$key] = $value;
                  }
               }
            }
         }
      }
      if (
         $terminals !== ['A:1' => 1, 'B:3' => 1]
         || $statuses !== ['B:3' => '201', 'A:1' => '202']
      ) {
         return 'L4-101.23 HTTP/2 wire controls failed: ' . json_encode([
            'terminals' => $terminals,
            'statuses' => $statuses,
            'frames' => $Probe->Frames,
         ]);
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-101.23-EVIDENCE:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'received_calls' => 1,
         'source_a' => [
            'package_a' => true,
            'request_a' => true,
            'stream_a' => 1,
            'exchange_a' => true,
            'cancellation_a' => true,
            'lease_a' => true,
            'exchange_active' => true,
            'cancellation_active' => true,
         ],
         'boundary' => [
            'source_package_b' => true,
            'source_request_b' => true,
            'source_stream_b' => true,
            'source_exchange_b' => true,
            'source_cancellation_clear' => true,
            'clone_package_b' => true,
            'clone_request_copy' => true,
            'clone_stream_b' => true,
            'clone_exchange_b' => true,
            'clone_request_exchange_b' => true,
            'clone_cancellation_clear' => true,
            'clone_cancellation_unlinked' => true,
            'exchange_b_unleased' => true,
            'distinct_exchanges' => true,
            'exchange_a_active' => true,
            'cancellation_a_active' => true,
         ],
         'handler_b' => 1,
         'handler_exchange_b' => true,
         'terminal_a' => true,
         'terminal_b' => true,
         'cancellation_a_terminal' => true,
         'terminals' => [
            ['owner' => 'B', 'exchange' => true, 'code' => 201],
            ['owner' => 'A', 'exchange' => true, 'code' => 202],
         ],
      ];
      if ($evidence !== $expected) {
         return 'L4-101.23 regression: a pre-reset HTTP/2 clone inherited A '
            . 'or failed to carry B Package/Request/Exchange context. Evidence: '
            . json_encode(['expected' => $expected, 'actual' => $evidence]);
      }

      return true;
   },
);
