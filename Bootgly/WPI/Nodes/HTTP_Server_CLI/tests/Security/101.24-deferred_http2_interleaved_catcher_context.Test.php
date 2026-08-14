<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
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
 * L4 deferred HTTP/2 interleaved-Catcher context regression.
 *
 * A deferred stream A is suspended while a different connection completes
 * stream B. The scheduler then rebinds A's captured request for its resumed
 * segment. Both a user callback throw and a later encode() throw force Catcher
 * to create a fresh Response. That Response must retain A's transport, stream
 * and Exchange: one 500 must terminate A/1 while B/3 keeps its own status and
 * cannot close A early.
 */
$GateWorkers = [];
$GateTests = [];
foreach (['H', 'E'] as $leg) {
   $GatePair = stream_socket_pair(
      STREAM_PF_UNIX,
      STREAM_SOCK_STREAM,
      STREAM_IPPROTO_IP,
   );
   if ($GatePair === false) {
      foreach (array_merge($GateWorkers, $GateTests) as $gate) {
         if (is_resource($gate)) {
            fclose($gate);
         }
      }
      throw new RuntimeException("L4-101.24 could not create gate {$leg}.");
   }

   [$GateWorkers[$leg], $GateTests[$leg]] = $GatePair;
}

$Probe = new class {
   public null|Emitter $Emitter = null;
   public mixed $Encoder = null;
   public null|Response $Response = null;
   public string $error = '';
   public int $encodes = 0;
   /** @var array<string,string> */
   public array $markers = [];
   /** @var array<string,int> */
   public array $handlers = [];
   /** @var array<string,int> */
   public array $callbacks = [];
   /** @var array<string,bool> */
   public array $deferred = [];
   /** @var array<string,bool> */
   public array $handlerExchange = [];
   /** @var array<string,array{URI:string,stream:int,distinct:bool}> */
   public array $ambient = [];
   /** @var array<string,array{URI:string,stream:int,distinct:bool,a_active:bool}> */
   public array $interleaved = [];
   /** @var array<string,Exchange> */
   public array $Exchanges = [];
   /** @var array<string,array{exchange:bool,code:null|int,a_active:null|bool}> */
   public array $terminals = [];
   /** @var array<string,bool> */
   public array $premature = [];
   /** @var list<array{connection:string,type:int,flags:int,stream:int,payload:string}> */
   public array $Frames = [];
};

$Send = static function ($Socket, string $wire, string $label): void {
   if (is_resource($Socket) === false) {
      throw new RuntimeException("L4-101.24 {$label} socket is unavailable.");
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
      throw new RuntimeException("L4-101.24 {$label} write was incomplete.");
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

$ReadFrames = static function (
   $Socket,
   string $connection,
   int $stream,
   string &$buffer,
   float $duration,
   bool $required,
) use ($Probe): void {
   if (is_resource($Socket) === false) {
      throw new RuntimeException("L4-101.24 {$connection} socket is unavailable.");
   }

   stream_set_blocking($Socket, false);
   $terminalAt = null;
   $deadline = microtime(true) + $duration;
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

   if ($terminalAt !== null || $required === false) {
      return;
   }

   throw new RuntimeException(
      "L4-101.24 {$connection} did not terminate stream {$stream}."
   );
};

$Drive = static function (
   string $hostPort,
   int $testIndex,
   string $leg,
   string $pathA,
   string $pathB,
   $gateTest,
) use ($Probe, $ReadFrames, $ReadLine, $Send): void {
   $labelA = "{$leg}-A";
   $labelB = "{$leg}-B";

   $SocketA = stream_socket_client(
      "tcp://{$hostPort}",
      $errorCodeA,
      $errorMessageA,
      timeout: 5,
   );
   if ($SocketA === false) {
      throw new RuntimeException(
         "L4-101.24 {$labelA} connection failed: {$errorCodeA} {$errorMessageA}"
      );
   }

   try {
      $HeadersA = HPACK::encode([
         [':method', 'GET'],
         [':scheme', 'http'],
         [':path', $pathA],
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
         "{$labelA} request",
      );

      $Probe->markers[$leg] = $ReadLine($gateTest);
      $marker = "L4-101.24-{$leg}-READY\n";
      if ($Probe->markers[$leg] !== $marker) {
         throw new RuntimeException(
            "L4-101.24 {$labelA} barrier was "
               . json_encode($Probe->markers[$leg])
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
            "L4-101.24 {$labelB} connection failed: {$errorCodeB} {$errorMessageB}"
         );
      }

      try {
         $HeadersB = HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', $pathB],
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
            "{$labelB} request",
         );

         $bufferB = '';
         $ReadFrames($SocketB, $labelB, 3, $bufferB, 5.0, true);

         // ! B must be terminal while A is still parked. Parse everything A
         //   emitted so far and remember any premature END_STREAM evidence.
         $bufferA = '';
         $ReadFrames($SocketA, $labelA, 1, $bufferA, 0.15, false);
         $Probe->premature[$leg] = false;
         foreach ($Probe->Frames as $Frame) {
            if (
               $Frame['connection'] === $labelA
               && $Frame['stream'] === 1
               && ($Frame['flags'] & HTTP2::FLAG_END_STREAM) !== 0
            ) {
               $Probe->premature[$leg] = true;
            }
         }

         $release = $leg === 'H' ? 'H' : 'E';
         if (fwrite($gateTest, $release) !== 1) {
            throw new RuntimeException("L4-101.24 could not release {$labelA}.");
         }
         fclose($gateTest);

         $ReadFrames($SocketA, $labelA, 1, $bufferA, 5.0, true);
      }
      finally {
         if (is_resource($SocketB)) {
            fclose($SocketB);
         }
      }
   }
   finally {
      if (is_resource($gateTest)) {
         @fwrite($gateTest, $leg);
         fclose($gateTest);
      }
      if (is_resource($SocketA)) {
         fclose($SocketA);
      }
   }
};

return new Test(
   description: 'Deferred HTTP/2 Catcher must retain A across an interleaved B request',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/h2-catcher/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $Drive,
         $GateTests,
         $GateWorkers,
         $Probe,
      ): string {
         foreach ($GateWorkers as $gateWorker) {
            if (is_resource($gateWorker)) {
               fclose($gateWorker);
            }
         }

         try {
            $Drive(
               $hostPort,
               $testIndex,
               'H',
               '/l4/h2-catcher/handler-a',
               '/l4/h2-catcher/handler-b',
               $GateTests['H'],
            );
            $Drive(
               $hostPort,
               $testIndex,
               'E',
               '/l4/h2-catcher/encode-a',
               '/l4/h2-catcher/encode-b',
               $GateTests['E'],
            );
         }
         catch (Throwable $Throwable) {
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();

            foreach ($GateTests as $leg => $gateTest) {
               if (is_resource($gateTest)) {
                  @fwrite($gateTest, $leg);
                  fclose($gateTest);
               }
            }
         }

         return "GET /l4/h2-catcher/evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($GateTests, $GateWorkers, $Probe): Generator {
      yield $Router->route('/l4/h2-catcher/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Response = Server::$Response;

         $Events = new Emitter;
         $Events->listen(
            RequestEvents::Received,
            static function (Emission $Emission) use ($Probe): void {
               $Received = $Emission->payload[0] ?? null;
               if ($Received instanceof Request === false) {
                  return;
               }

               $owners = [
                  '/l4/h2-catcher/handler-a' => 'H-A',
                  '/l4/h2-catcher/handler-b' => 'H-B',
                  '/l4/h2-catcher/encode-a' => 'E-A',
                  '/l4/h2-catcher/encode-b' => 'E-B',
               ];
               $owner = $owners[$Received->URI] ?? null;
               if ($owner === null) {
                  return;
               }

               $Exchange = Exchange::fetch($Received);
               if ($Exchange === null) {
                  return;
               }
               $Probe->Exchanges[$owner] = $Exchange;
               $Exchange->observe(static function (
                  Exchange $Observed,
                  null|int $code,
               ) use ($Exchange, $owner, $Probe): void {
                  $leg = $owner[0];
                  $Probe->terminals[$owner] = [
                     'exchange' => $Observed === $Exchange,
                     'code' => $code,
                     'a_active' => str_ends_with($owner, '-B')
                        ? (($Probe->Exchanges["{$leg}-A"] ?? null)?->check() === false)
                        : null,
                  ];
               });
            },
         );
         Emitter::$Instance = $Events;
         Server::$Encoder = new Encoder_;

         $Explosive = new class extends Response {
            public null|object $State = null;

            public function encode (Packages $Package, null|int &$length): string
            {
               if ($this->Body->raw === 'L4-101.24-ENCODE-THROW') {
                  $State = $this->State;
                  if ($State !== null) {
                     $State->encodes++;
                  }
                  throw new RuntimeException('L4-101.24-ENCODE-THROW');
               }

               return parent::encode($Package, $length);
            }
         };
         $Explosive->State = $Probe;
         Server::$Response = $Explosive;

         return $Explosive(body: 'L4-101.24-SETUP');
      }, GET);

      yield $Router->route('/l4/h2-catcher/handler-a', static function (
         Request $Request,
         Response $Response,
      ) use ($GateTests, $GateWorkers, $Probe): Response {
         $Probe->handlers['H'] = ($Probe->handlers['H'] ?? 0) + 1;
         $Probe->handlerExchange['H'] = Exchange::fetch($Request)
            === ($Probe->Exchanges['H-A'] ?? null);

         $Selected = $Response->defer(static function (Response $Deferred) use (
            $GateTests,
            $GateWorkers,
            $Probe,
            $Request,
         ): void {
            if (is_resource($GateTests['H'])) {
               fclose($GateTests['H']);
            }
            stream_set_blocking($GateWorkers['H'], false);
            $Deferred->wait($GateWorkers['H']);
            $release = fread($GateWorkers['H'], 1);
            fclose($GateWorkers['H']);
            if ($release !== 'H') {
               throw new RuntimeException('L4-101.24 handler resumed without H.');
            }

            $Probe->callbacks['H'] = ($Probe->callbacks['H'] ?? 0) + 1;
            $Ambient = Server::$Request;
            $Probe->ambient['H'] = [
               'URI' => $Ambient->URI,
               'stream' => $Ambient->stream,
               'distinct' => $Ambient !== $Request,
            ];

            throw new RuntimeException('L4-101.24-HANDLER-THROW');
         });
         $Probe->deferred['H'] = $Selected->deferred;

         $marker = "L4-101.24-H-READY\n";
         if (fwrite($GateWorkers['H'], $marker) !== strlen($marker)) {
            throw new RuntimeException('L4-101.24 handler barrier failed.');
         }

         return $Selected;
      }, GET);

      yield $Router->route('/l4/h2-catcher/encode-a', static function (
         Request $Request,
         Response $Response,
      ) use ($GateTests, $GateWorkers, $Probe): Response {
         $Probe->handlers['E'] = ($Probe->handlers['E'] ?? 0) + 1;
         $Probe->handlerExchange['E'] = Exchange::fetch($Request)
            === ($Probe->Exchanges['E-A'] ?? null);

         $Selected = $Response->defer(static function (Response $Deferred) use (
            $GateTests,
            $GateWorkers,
            $Probe,
            $Request,
         ): void {
            if (is_resource($GateTests['E'])) {
               fclose($GateTests['E']);
            }
            stream_set_blocking($GateWorkers['E'], false);
            $Deferred->wait($GateWorkers['E']);
            $release = fread($GateWorkers['E'], 1);
            fclose($GateWorkers['E']);
            if ($release !== 'E') {
               throw new RuntimeException('L4-101.24 encoder resumed without E.');
            }

            $Probe->callbacks['E'] = ($Probe->callbacks['E'] ?? 0) + 1;
            $Ambient = Server::$Request;
            $Probe->ambient['E'] = [
               'URI' => $Ambient->URI,
               'stream' => $Ambient->stream,
               'distinct' => $Ambient !== $Request,
            ];
            $Deferred(code: 409, body: 'L4-101.24-ENCODE-THROW');
         });
         $Probe->deferred['E'] = $Selected->deferred;

         $marker = "L4-101.24-E-READY\n";
         if (fwrite($GateWorkers['E'], $marker) !== strlen($marker)) {
            throw new RuntimeException('L4-101.24 encoder barrier failed.');
         }

         return $Selected;
      }, GET);

      yield $Router->route('/l4/h2-catcher/handler-b', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $ExchangeA = $Probe->Exchanges['H-A'] ?? null;
         $ExchangeB = $Probe->Exchanges['H-B'] ?? null;
         $Probe->handlers['H-B'] = ($Probe->handlers['H-B'] ?? 0) + 1;
         $Probe->interleaved['H'] = [
            'URI' => $Request->URI,
            'stream' => $Request->stream,
            'distinct' => $ExchangeB !== null && $ExchangeB !== $ExchangeA,
            'a_active' => $ExchangeA?->check() === false,
         ];

         return $Response(code: 201, body: 'L4-101.24-H-B-201');
      }, GET);

      yield $Router->route('/l4/h2-catcher/encode-b', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $ExchangeA = $Probe->Exchanges['E-A'] ?? null;
         $ExchangeB = $Probe->Exchanges['E-B'] ?? null;
         $Probe->handlers['E-B'] = ($Probe->handlers['E-B'] ?? 0) + 1;
         $Probe->interleaved['E'] = [
            'URI' => $Request->URI,
            'stream' => $Request->stream,
            'distinct' => $ExchangeB !== null && $ExchangeB !== $ExchangeA,
            'a_active' => $ExchangeA?->check() === false,
         ];

         return $Response(code: 202, body: 'L4-101.24-E-B-202');
      }, GET);

      yield $Router->route('/l4/h2-catcher/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($GateTests, $GateWorkers, $Probe): Response {
         $evidence = [
            'handlers' => $Probe->handlers,
            'callbacks' => $Probe->callbacks,
            'encodes' => $Probe->encodes,
            'deferred' => $Probe->deferred,
            'handler_exchange' => $Probe->handlerExchange,
            'ambient' => $Probe->ambient,
            'interleaved' => $Probe->interleaved,
            'exchanges_terminal' => [
               'H-A' => ($Probe->Exchanges['H-A'] ?? null)?->check() ?? false,
               'H-B' => ($Probe->Exchanges['H-B'] ?? null)?->check() ?? false,
               'E-A' => ($Probe->Exchanges['E-A'] ?? null)?->check() ?? false,
               'E-B' => ($Probe->Exchanges['E-B'] ?? null)?->check() ?? false,
            ],
            'terminals' => [
               'H-B' => $Probe->terminals['H-B'] ?? null,
               'H-A' => $Probe->terminals['H-A'] ?? null,
               'E-B' => $Probe->terminals['E-B'] ?? null,
               'E-A' => $Probe->terminals['E-A'] ?? null,
            ],
         ];

         foreach (array_merge($GateWorkers, $GateTests) as $gate) {
            if (is_resource($gate)) {
               fclose($gate);
            }
         }
         $Probe->Exchanges = [];
         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }
         if ($Probe->Response !== null) {
            Server::$Response = $Probe->Response;
         }

         return $Response(body: 'L4-101.24-EVIDENCE:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'L4-101.24-SETUP') === false
      ) {
         return 'L4-101.24 control failed: native setup response missing.';
      }
      if ($Probe->error !== '') {
         return 'L4-101.24 fixture failed: ' . $Probe->error;
      }
      if ($Probe->markers !== [
         'H' => "L4-101.24-H-READY\n",
         'E' => "L4-101.24-E-READY\n",
      ]) {
         return 'L4-101.24 control failed: rendezvous markers mismatched: '
            . json_encode($Probe->markers);
      }
      if ($Probe->premature !== ['H' => false, 'E' => false]) {
         return 'L4-101.24 regression: B terminalized A before its release: '
            . json_encode($Probe->premature);
      }

      $HPACKs = [];
      $statuses = [];
      $terminals = [
         'H-A:1' => 0,
         'H-B:3' => 0,
         'E-A:1' => 0,
         'E-B:3' => 0,
      ];
      foreach ($Probe->Frames as $Frame) {
         $key = $Frame['connection'] . ':' . $Frame['stream'];
         if (array_key_exists($key, $terminals) === false) {
            continue;
         }
         if (($Frame['flags'] & HTTP2::FLAG_END_STREAM) !== 0) {
            $terminals[$key]++;
         }
         if ($Frame['type'] !== HTTP2::FRAME_HEADERS) {
            continue;
         }

         $connection = $Frame['connection'];
         $HPACKs[$connection] ??= new HPACK(4096);
         $fields = $HPACKs[$connection]->decode(
            $Frame['payload'],
            PHP_INT_MAX,
         );
         foreach ($fields ?? [] as [$name, $value]) {
            if ($name === ':status') {
               $statuses[$key] = $value;
            }
         }
      }
      $expectedStatuses = [
         'H-B:3' => '201',
         'H-A:1' => '500',
         'E-B:3' => '202',
         'E-A:1' => '500',
      ];
      foreach ($terminals as $key => $count) {
         if ($count !== 1 || ($statuses[$key] ?? null) !== $expectedStatuses[$key]) {
            return 'L4-101.24 HTTP/2 stream/status isolation failed: '
               . json_encode([
                  'terminals' => $terminals,
                  'statuses' => $statuses,
                  'frames' => $Probe->Frames,
               ]);
         }
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-101.24-EVIDENCE:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'handlers' => ['H' => 1, 'H-B' => 1, 'E' => 1, 'E-B' => 1],
         'callbacks' => ['H' => 1, 'E' => 1],
         'encodes' => 1,
         'deferred' => ['H' => true, 'E' => true],
         'handler_exchange' => ['H' => true, 'E' => true],
         'ambient' => [
            'H' => [
               'URI' => '/l4/h2-catcher/handler-a',
               'stream' => 1,
               'distinct' => true,
            ],
            'E' => [
               'URI' => '/l4/h2-catcher/encode-a',
               'stream' => 1,
               'distinct' => true,
            ],
         ],
         'interleaved' => [
            'H' => [
               'URI' => '/l4/h2-catcher/handler-b',
               'stream' => 3,
               'distinct' => true,
               'a_active' => true,
            ],
            'E' => [
               'URI' => '/l4/h2-catcher/encode-b',
               'stream' => 3,
               'distinct' => true,
               'a_active' => true,
            ],
         ],
         'exchanges_terminal' => [
            'H-A' => true,
            'H-B' => true,
            'E-A' => true,
            'E-B' => true,
         ],
         'terminals' => [
            'H-B' => ['exchange' => true, 'code' => 201, 'a_active' => true],
            'H-A' => ['exchange' => true, 'code' => 500, 'a_active' => null],
            'E-B' => ['exchange' => true, 'code' => 202, 'a_active' => true],
            'E-A' => ['exchange' => true, 'code' => 500, 'a_active' => null],
         ],
      ];
      if ($evidence !== $expected) {
         return 'L4-101.24 regression: fresh Catcher lost deferred A context. '
            . json_encode(['expected' => $expected, 'actual' => $evidence]);
      }

      return true;
   },
);
