<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use const Bootgly\WPI;
use Bootgly\ABI\Data\Language;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Endpoints\Servers\Encoder as ServerEncoder;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections as TCPConnections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Holding;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Streaming;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * H-HSC-6 / ENC-3 — an HTTP/1.1 connection whose deferral is parked holds the
 * requests pipelined behind it, and releases them only after the parked
 * response is on the wire (RFC 9112 §9.3.2: responses in request order).
 *
 * Driven through the real `Response::defer()` and job loop on the worker
 * reactor, over a real connection on a socket pair. A route stand-in (the
 * encoder) parks every `/d…` request until the spec opens its gate and
 * answers any other request at once with `echo:<path>`. Pinned:
 *
 * - while `/d1` is parked, the `/d2` and `/e` pipelined behind it are held
 *   undecoded in the carry (`Decoder_Holding`) and nothing is answered;
 *   when `/d1` is answered its response leaves first, `/d2` is decoded on a
 *   later tick and parks under a new hold (`/e` still held), and `/e` is
 *   answered only after `/d2` — wire order D1, D2, E; a request sent in the
 *   same read as the parked one is held and answered after it the same way;
 * - a parked deferral abandoned with no response (its generation cancelled)
 *   closes the connection after the drain: nothing pipelined is answered;
 * - a parked request that asked `Connection: close` is answered and the
 *   connection closes: the request held behind it is never answered;
 * - when the decoder slot changed hands while parked (an SSE stream installs
 *   `Decoder_Streaming`), the release hands the held bytes to the new owner,
 *   which discards them: the carry and its transport charge are released,
 *   the slot keeps its new owner, nothing else is answered and the
 *   connection stays open;
 * - a slot already taken when the deferral parks is never replaced, and an
 *   HTTP/2 stream's deferral never installs a hold;
 * - both closing releases wait for the drain: a `Connection: close` answer
 *   larger than the socket buffer, still pending when the release runs,
 *   arrives whole and then EOF; a park abandoned while an earlier answer
 *   larger than the socket buffer is still pending lets that answer arrive
 *   whole, then EOF — nothing held is answered after either;
 * - a held request that fails when the release decodes it closes the
 *   connection.
 */
if (! class_exists('U165Gate', false)) {
   /**
    * One parked deferral: the spec opens it; the work records its generation.
    */
   class U165Gate
   {
      // * Data
      public bool $open = false;
      public null|Cancellation $Generation = null;
   }
}
if (! class_exists('U165Encoder', false)) {
   /**
    * Route stand-in: the transport hands it each decoded request.
    */
   class U165Encoder implements ServerEncoder
   {
      // * Data
      public static null|Closure $Route = null;

      public static function encode (ServerPackages $Package, null|int &$length): string
      {
         $Route = self::$Route;
         $wire = $Route === null ? '' : $Route(HTTP_Server_CLI::$Request, $Package);
         $length = strlen($wire);

         return $wire;
      }
   }
}


return new Test(
   description: 'H-HSC-6: a parked HTTP/1.1 deferral holds its pipeline and releases it in order, after its response',
   test: new Assertions(Case: function (): Generator {
      $WPI = WPI;
      $Parked = new ReflectionProperty(Response::class, 'Pool');
      $Contexts = new ReflectionProperty(Language::class, 'Contexts');

      // ! Statics this case writes — directly, or through defer() and the
      //   job loop
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $OldDecoder = TCP_Server_CLI::$Decoder;
      $OldEncoder = TCP_Server_CLI::$Encoder;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $OldRequest = isset(HTTP_Server_CLI::$Request) ? HTTP_Server_CLI::$Request : null;
      $OldRouter = isset($WPI->Router) ? $WPI->Router : null;
      $OldAmbient = isset($WPI->Request) ? $WPI->Request : null;
      $OldAnswer = isset($WPI->Response) ? $WPI->Response : null;
      $OldPool = $Parked->getValue();
      $OldContexts = $Contexts->getValue();
      $oldTimeout = Response::$deferredTimeout;

      $Sockets = [];
      $Connections = [];
      $Select = null;

      // ! A server end that reads everything the client end sent, up to one
      //   transport read, and a client end that never blocks
      $Open = static function (int $port) use (&$Sockets, &$Connections): array {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('U165 could not open a socket pair.');
         }
         [$Server, $Client] = $Pair;
         stream_set_blocking($Server, false);
         stream_set_blocking($Client, false);
         stream_set_read_buffer($Server, 0);
         $Sockets[] = $Server;
         $Sockets[] = $Client;
         $Connection = new Connection($Server, '127.0.0.1', $port);
         $Connections[] = $Connection;

         return [$Connection, $Server, $Client];
      };
      // ! Whatever reached the client end, without blocking
      $Receive = static function (mixed $Client): string {
         $wire = '';
         while (is_resource($Client)) {
            $chunk = @fread($Client, 65_536);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $wire .= $chunk;
         }

         return $wire;
      };
      // ! Bounded run: short reactor slices, each ended by a deferred stop,
      //   until `$Done` holds or the hard deadline passes
      $Run = static function (Select $Select, Closure $Done, float $bound): null|string {
         $deadline = microtime(true) + $bound;

         do {
            $Select->defer(microtime(true) + 0.010, static function () use ($Select): void {
               $Select->loop = false;
            });
            $Select->loop = true;

            try {
               $Select->loop();
            }
            catch (Throwable $Throwable) {
               return "{$Throwable->getMessage()}";
            }
         } while ($Done() === false && microtime(true) < $deadline);

         return null;
      };
      // ! The client end reads while the reactor flushes what is pending:
      //   everything that arrived, and whether EOF followed it
      $Drain = static function (Select $Select, mixed $Client) use ($Run, $Receive): array {
         $wire = '';
         $deadline = microtime(true) + 3.0;

         // @@ One reactor slice, then whatever the client end can read
         while (microtime(true) < $deadline) {
            $Run($Select, static fn (): bool => true, 0.0);
            $wire .= $Receive($Client);
            if (feof($Client)) {
               return [$wire, true];
            }
         }

         return [$wire, false];
      };
      $Compose = static fn (string $path, string $fields = ''): string => "GET {$path} HTTP/1.1\r\nHost: localhost\r\n{$fields}\r\n";
      // ! An answer larger than the socket-pair buffer
      $big = str_repeat('B', 2_000_000);

      try {
         // ! The worker reactor: defer() requires a context-aware scheduler
         $Peers = new class implements Connections {
            public function connect (): bool
            {
               return false;
            }
         };
         $Select = new Select($Peers);
         TCP_Server_CLI::$Event = $Select;
         TCP_Server_CLI::$context = [];
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         TCP_Server_CLI::$Decoder = new Decoder_;
         TCP_Server_CLI::$Encoder = new U165Encoder;
         $WPI->Router = new Router;
         // ! The ambient context a deferred segment binds over and restores
         $WPI->Request = new Request;
         $WPI->Response = new Response;
         $Parked->setValue(null, []);
         Response::$deferredTimeout = 0;

         // ! The route stand-in: `/d…`, `/o` and `/h2` park until their gate
         //   opens — `/o` after taking the decoder slot itself, `/h2` as an
         //   HTTP/2 stream, `/dbig` to answer `$big` — `/big` answers `$big`
         //   at once, `/boom` fails, and anything else is echoed at once
         /** @var array<string,U165Gate> $Gates */
         $Gates = [];
         $echoes = 0;
         $Occupant = new Decoder_Streaming;
         U165Encoder::$Route = static function (Request $Request, TCPPackages $Package) use (&$Gates, &$echoes, $Occupant, $big): string {
            $path = $Request->URI;
            if ($path === '/big') {
               return $big;
            }
            if ($path === '/boom') {
               throw new RuntimeException('U165 route failure');
            }
            if (str_starts_with($path, '/d') === false && $path !== '/o' && $path !== '/h2') {
               $echoes++;

               return "echo:{$path}";
            }

            if ($path === '/o') {
               $Package->Decoder = $Occupant;
            }
            if ($path === '/h2') {
               $Request->stream = 1;
            }
            $Gate = new U165Gate;
            $Gates[$path] = $Gate;
            $Response = new Response;
            $Response->reset($Package, $Request);
            $Response->defer(static function (Response $Deferred) use ($Gate, $path, $big): void {
               $Fiber = Fiber::getCurrent();
               $Gate->Generation = $Fiber === null ? null : Cancellation::fetch($Fiber);
               while ($Gate->open === false) {
                  $Deferred->wait();
               }
               $Deferred->Body->raw = $path === '/dbig' ? $big : "deferred:{$path}";
            });

            return '';
         };

         // @@ A) In order: /d1 parks, /d2 and /e wait behind it
         [$Connection, $Socket, $Client] = $Open(40011);
         fwrite($Client, $Compose('/d1'));
         $Connection->reading($Socket);
         $First = $Connection->Decoder;
         fwrite($Client, "{$Compose('/d2')}{$Compose('/e')}");
         $Connection->reading($Socket);
         $wire = $Receive($Client);
         $parked = [
            'parked' => array_keys($Gates),
            'held' => $First instanceof Decoder_Holding && $Connection->Decoder === $First,
            'carry' => $Connection->carry === "{$Compose('/d2')}{$Compose('/e')}",
            'wire' => $wire,
            'echoes' => $echoes,
         ];
         $evidence = json_encode($parked);

         yield assert(
            assertion: $parked === [
               'parked' => ['/d1'],
               'held' => true,
               'carry' => true,
               'wire' => '',
               'echoes' => 0,
            ],
            description: "While /d1 is parked, the requests pipelined behind it are held undecoded and nothing is answered: {$evidence}",
         );

         // @ /d1 is answered: its response leaves, /d2 parks under a new hold
         $Gates['/d1']->open = true;
         $escaped = $Run($Select, static function () use (&$Gates, &$wire, $Receive, $Client): bool {
            $wire .= $Receive($Client);

            return isset($Gates['/d2']);
         }, 2.0);
         $wire .= $Receive($Client);
         $Second = $Connection->Decoder;
         $released = [
            'escaped' => $escaped,
            'parked' => array_keys($Gates),
            'answered' => substr_count($wire, 'HTTP/1.1 200'),
            'first' => str_ends_with($wire, 'deferred:/d1'),
            'held' => $Second instanceof Decoder_Holding && $Second !== $First,
            'carry' => $Connection->carry === $Compose('/e'),
            'echoes' => $echoes,
         ];
         $evidence = json_encode($released);

         yield assert(
            assertion: $released === [
               'escaped' => null,
               'parked' => ['/d1', '/d2'],
               'answered' => 1,
               'first' => true,
               'held' => true,
               'carry' => true,
               'echoes' => 0,
            ],
            description: "/d1 is answered first; then /d2 is decoded and parks under a new hold, /e still held: {$evidence}",
         );

         // @ /d2 is answered: only then is /e decoded and answered
         $Gates['/d2']->open = true;
         $escaped = $Run($Select, static function () use (&$wire, $Receive, $Client): bool {
            $wire .= $Receive($Client);

            return str_contains($wire, 'echo:/e');
         }, 2.0);
         $wire .= $Receive($Client);
         $positions = [
            strpos($wire, 'deferred:/d1'),
            strpos($wire, 'deferred:/d2'),
            strpos($wire, 'echo:/e'),
         ];
         $ordered = [
            'escaped' => $escaped,
            'ordered' => $positions[0] !== false
               && $positions[1] !== false
               && $positions[2] !== false
               && $positions[0] < $positions[1]
               && $positions[1] < $positions[2],
            'last' => str_ends_with($wire, 'echo:/e'),
            'echoes' => $echoes,
            'slot' => $Connection->Decoder,
            'carry' => $Connection->carry,
            'status' => $Connection->status,
         ];
         $evidence = json_encode(['state' => $ordered, 'wire' => $wire]);

         yield assert(
            assertion: $ordered === [
               'escaped' => null,
               'ordered' => true,
               'last' => true,
               'echoes' => 1,
               'slot' => null,
               'carry' => '',
               'status' => TCPConnections::STATUS_ESTABLISHED,
            ],
            description: "The wire answers in request order — D1, D2, then E — and the connection is free again: {$evidence}",
         );

         // @@ A2) The same, sent in the read that parks: the pipelining loop
         //     meets the hold right after the parked request
         [$Connection, $Socket, $Client] = $Open(40017);
         $echoes = 0;
         fwrite($Client, "{$Compose('/d6')}{$Compose('/e')}");
         $Connection->reading($Socket);
         $wire = $Receive($Client);
         $held = [
            'held' => $Connection->Decoder instanceof Decoder_Holding,
            'carry' => $Connection->carry === $Compose('/e'),
            'wire' => $wire,
         ];
         $Gates['/d6']->open = true;
         $escaped = $Run($Select, static function () use (&$wire, $Receive, $Client): bool {
            $wire .= $Receive($Client);

            return str_contains($wire, 'echo:/e');
         }, 2.0);
         $wire .= $Receive($Client);
         $positions = [strpos($wire, 'deferred:/d6'), strpos($wire, 'echo:/e')];
         $same = [
            'escaped' => $escaped,
            'held' => $held,
            'ordered' => $positions[0] !== false && $positions[1] !== false && $positions[0] < $positions[1],
            'echoes' => $echoes,
            'slot' => $Connection->Decoder,
         ];
         $evidence = json_encode(['state' => $same, 'wire' => $wire]);

         yield assert(
            assertion: $same === [
               'escaped' => null,
               'held' => ['held' => true, 'carry' => true, 'wire' => ''],
               'ordered' => true,
               'echoes' => 1,
               'slot' => null,
            ],
            description: "A request pipelined in the same read as the parked one is held too, and answered after it: {$evidence}",
         );

         // @@ B) Abandoned: the parked generation is cancelled, no response
         [$Connection, $Socket, $Client] = $Open(40012);
         $echoes = 0;
         fwrite($Client, $Compose('/d3'));
         $Connection->reading($Socket);
         fwrite($Client, $Compose('/e'));
         $Connection->reading($Socket);
         $wire = $Receive($Client);
         $Gates['/d3']->Generation?->cancel();
         $escaped = $Run($Select, static fn (): bool => $Connection->status === TCPConnections::STATUS_CLOSED, 1.0);
         $wire .= $Receive($Client);
         $abandoned = [
            'escaped' => $escaped,
            'cancelled' => $Gates['/d3']->Generation?->cancelled,
            'status' => $Connection->status,
            'wire' => $wire,
            'echoes' => $echoes,
            'EOF' => feof($Client),
         ];
         $evidence = json_encode($abandoned);

         yield assert(
            assertion: $abandoned === [
               'escaped' => null,
               'cancelled' => true,
               'status' => TCPConnections::STATUS_CLOSED,
               'wire' => '',
               'echoes' => 0,
               'EOF' => true,
            ],
            description: "A parked deferral abandoned without a response closes the connection; nothing held is answered: {$evidence}",
         );

         // @@ C) `Connection: close`: answered, then closed
         [$Connection, $Socket, $Client] = $Open(40013);
         $echoes = 0;
         fwrite($Client, $Compose('/d4', "Connection: close\r\n"));
         $Connection->reading($Socket);
         fwrite($Client, $Compose('/e'));
         $Connection->reading($Socket);
         $wire = $Receive($Client);
         $Gates['/d4']->open = true;
         $escaped = $Run($Select, static fn (): bool => $Connection->status === TCPConnections::STATUS_CLOSED, 1.0);
         $wire .= $Receive($Client);
         $closing = [
            'escaped' => $escaped,
            'status' => $Connection->status,
            'answered' => substr_count($wire, 'HTTP/1.1 200'),
            'last' => str_ends_with($wire, 'deferred:/d4'),
            'echoes' => $echoes,
         ];
         $evidence = json_encode(['state' => $closing, 'wire' => $wire]);

         yield assert(
            assertion: $closing === [
               'escaped' => null,
               'status' => TCPConnections::STATUS_CLOSED,
               'answered' => 1,
               'last' => true,
               'echoes' => 0,
            ],
            description: "A parked request that asked Connection: close is answered and the connection closes; the request behind it is not answered: {$evidence}",
         );

         // @@ D) The slot changed hands while parked (an SSE stream): the
         //    release hands the held bytes to the new owner, which discards
         //    them — their transport charge is not kept for the stream's life
         [$Connection, $Socket, $Client] = $Open(40014);
         $echoes = 0;
         fwrite($Client, $Compose('/d5'));
         $Connection->reading($Socket);
         fwrite($Client, $Compose('/e'));
         $Connection->reading($Socket);
         $wire = $Receive($Client);
         // ! The held request is charged to the transport while parked
         $charged = $Connection->carry === $Compose('/e')
            && $Connection->Buffers->retained === Buffers::weigh(strlen($Compose('/e')));
         $Stream = new Decoder_Streaming;
         $Connection->Decoder = $Stream;
         $Gates['/d5']->open = true;
         $answered = false;
         $escaped = $Run($Select, static function () use (&$wire, &$answered, $Receive, $Client): bool {
            $wire .= $Receive($Client);
            // ! Stop on the slice after the answer: its release has run
            $done = $answered;
            $answered = str_contains($wire, 'deferred:/d5');

            return $done;
         }, 1.0);
         $wire .= $Receive($Client);
         $swapped = [
            'escaped' => $escaped,
            'charged' => $charged,
            'answered' => substr_count($wire, 'HTTP/1.1 200'),
            'last' => str_ends_with($wire, 'deferred:/d5'),
            'slot' => $Connection->Decoder === $Stream,
            'carry' => $Connection->carry,
            'retained' => $Connection->Buffers->retained,
            'echoes' => $echoes,
            'status' => $Connection->status,
         ];
         $evidence = json_encode(['state' => $swapped, 'wire' => $wire]);

         yield assert(
            assertion: $swapped === [
               'escaped' => null,
               'charged' => true,
               'answered' => 1,
               'last' => true,
               'slot' => true,
               'carry' => '',
               'retained' => 0,
               'echoes' => 0,
               'status' => TCPConnections::STATUS_ESTABLISHED,
            ],
            description: "A slot that changed hands while parked keeps its new owner, which takes the held bytes: the carry and its charge are released, nothing else is answered, the connection stays open: {$evidence}",
         );

         // @@ E) A taken slot is never replaced; an HTTP/2 stream never holds
         [$Connection, $Socket, $Client] = $Open(40015);
         fwrite($Client, $Compose('/o'));
         $Connection->reading($Socket);
         $taken = [
            'parked' => isset($Gates['/o']) && $Gates['/o']->Generation?->check() === false,
            'slot' => $Connection->Decoder === $Occupant,
         ];
         $Gates['/o']->Generation?->cancel();

         [$Connection, $Socket, $Client] = $Open(40016);
         fwrite($Client, $Compose('/h2'));
         $Connection->reading($Socket);
         $streamed = [
            'parked' => isset($Gates['/h2']) && $Gates['/h2']->Generation?->check() === false,
            'slot' => $Connection->Decoder,
         ];
         $Gates['/h2']->Generation?->cancel();
         $escaped = $Run($Select, static fn (): bool => false, 0.05);
         $evidence = json_encode(['taken' => $taken, 'stream' => $streamed, 'escaped' => $escaped]);

         yield assert(
            assertion: $taken === ['parked' => true, 'slot' => true]
               && $streamed === ['parked' => true, 'slot' => null]
               && $escaped === null,
            description: "A deferral that parks with the decoder slot taken leaves it to its owner, and an HTTP/2 stream's deferral installs no hold: {$evidence}",
         );

         // @@ F) `Connection: close` under backpressure: the parked answer is
         //    larger than the socket buffer and the client reads nothing
         //    until the release ran
         [$Connection, $Socket, $Client] = $Open(40018);
         $echoes = 0;
         fwrite($Client, $Compose('/dbig', "Connection: close\r\n"));
         $Connection->reading($Socket);
         fwrite($Client, $Compose('/e'));
         $Connection->reading($Socket);
         $Gates['/dbig']->open = true;
         $escaped = $Run($Select, static fn (): bool => false, 0.3);
         // ! The release ran with the answer still pending
         $backlog = [
            'pending' => $Connection->pendingBuffer !== '',
            'draining' => $Connection->closeAfterDrain,
            'status' => $Connection->status,
         ];
         [$wire, $EOF] = $Drain($Select, $Client);
         $head = strstr($wire, "\r\n\r\n", true);
         $draining = [
            'escaped' => $escaped,
            'backlog' => $backlog,
            'answered' => substr_count($wire, 'HTTP/1.1 '),
            'whole' => $head !== false && substr($wire, strlen($head) + 4) === $big,
            'echoes' => $echoes,
            'EOF' => $EOF,
            'status' => $Connection->status,
         ];
         $evidence = json_encode(['state' => $draining, 'bytes' => strlen($wire), 'head' => $head]);

         yield assert(
            assertion: $draining === [
               'escaped' => null,
               'backlog' => [
                  'pending' => true,
                  'draining' => true,
                  'status' => TCPConnections::STATUS_ESTABLISHED,
               ],
               'answered' => 1,
               'whole' => true,
               'echoes' => 0,
               'EOF' => true,
               'status' => TCPConnections::STATUS_CLOSED,
            ],
            description: "A Connection: close answer larger than the socket buffer, pending when the release runs, arrives whole and then EOF; the request held behind it is not answered: {$evidence}",
         );

         // @@ G) Abandoned under backpressure: an earlier answer larger than
         //    the socket buffer is still pending when the park behind it is
         //    abandoned
         [$Connection, $Socket, $Client] = $Open(40019);
         $echoes = 0;
         fwrite($Client, "{$Compose('/big')}{$Compose('/dab')}{$Compose('/e')}");
         $Connection->reading($Socket);
         $parked = [
            'pending' => $Connection->pendingBuffer !== '',
            'held' => $Connection->Decoder instanceof Decoder_Holding,
            'carry' => $Connection->carry === $Compose('/e'),
         ];
         $Gates['/dab']->Generation?->cancel();
         $escaped = $Run($Select, static fn (): bool => false, 0.3);
         // ! The release ran with the earlier answer still pending
         $backlog = [
            'pending' => $Connection->pendingBuffer !== '',
            'draining' => $Connection->closeAfterDrain,
            'status' => $Connection->status,
         ];
         [$wire, $EOF] = $Drain($Select, $Client);
         $abandoned = [
            'escaped' => $escaped,
            'parked' => $parked,
            'backlog' => $backlog,
            'whole' => $wire === $big,
            'echoes' => $echoes,
            'EOF' => $EOF,
            'status' => $Connection->status,
         ];
         $evidence = json_encode([
            'state' => $abandoned,
            'bytes' => strlen($wire),
            'tail' => substr($wire, -32),
         ]);

         yield assert(
            assertion: $abandoned === [
               'escaped' => null,
               'parked' => ['pending' => true, 'held' => true, 'carry' => true],
               'backlog' => [
                  'pending' => true,
                  'draining' => true,
                  'status' => TCPConnections::STATUS_ESTABLISHED,
               ],
               'whole' => true,
               'echoes' => 0,
               'EOF' => true,
               'status' => TCPConnections::STATUS_CLOSED,
            ],
            description: "A park abandoned while an earlier answer larger than the socket buffer is pending lets that answer arrive whole, then EOF; nothing held is answered: {$evidence}",
         );

         // @@ H) A held request that fails when the release decodes it
         //    closes the connection
         [$Connection, $Socket, $Client] = $Open(40020);
         $echoes = 0;
         fwrite($Client, $Compose('/d7'));
         $Connection->reading($Socket);
         fwrite($Client, $Compose('/boom'));
         $Connection->reading($Socket);
         $Gates['/d7']->open = true;
         $wire = '';
         $escaped = $Run($Select, static function () use (&$wire, $Receive, $Client, $Connection): bool {
            $wire .= $Receive($Client);

            return $Connection->status === TCPConnections::STATUS_CLOSED;
         }, 1.0);
         $wire .= $Receive($Client);
         $failed = [
            'escaped' => $escaped,
            'answered' => substr_count($wire, 'HTTP/1.1 200'),
            'last' => str_ends_with($wire, 'deferred:/d7'),
            'carry' => $Connection->carry,
            'status' => $Connection->status,
            'EOF' => feof($Client),
         ];
         $evidence = json_encode(['state' => $failed, 'wire' => $wire]);

         yield assert(
            assertion: $failed === [
               'escaped' => null,
               'answered' => 1,
               'last' => true,
               'carry' => '',
               'status' => TCPConnections::STATUS_CLOSED,
               'EOF' => true,
            ],
            description: "A held request that fails when the release decodes it closes the connection, after the parked answer: {$evidence}",
         );
      }
      finally {
         U165Encoder::$Route = null;
         foreach ($Connections as $Connection) {
            try {
               $Connection->close();
            }
            catch (Throwable) {
               // Teardown only.
            }
         }
         if ($Select !== null) {
            try {
               $Select->destroy();
            }
            catch (Throwable) {
               // Teardown only.
            }
         }
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               fclose($Socket);
            }
         }

         $Parked->setValue(null, $OldPool);
         $Contexts->setValue(null, $OldContexts);
         Response::$deferredTimeout = $oldTimeout;
         TCP_Server_CLI::$Decoder = $OldDecoder;
         TCP_Server_CLI::$Encoder = $OldEncoder;
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldRequest !== null) {
            HTTP_Server_CLI::$Request = $OldRequest;
         }
         if ($OldRouter !== null) {
            $WPI->Router = $OldRouter;
         }
         else {
            unset($WPI->Router);
         }
         if ($OldAmbient !== null) {
            $WPI->Request = $OldAmbient;
         }
         else {
            unset($WPI->Request);
         }
         if ($OldAnswer !== null) {
            $WPI->Response = $OldAnswer;
         }
         else {
            unset($WPI->Response);
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }

         // ! The generations this case left behind are cycles: reclaim them
         //   here, not in a later case
         gc_collect_cycles();
      }
   })
);
