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
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Encoder as ServerEncoder;
use Bootgly\WPI\Endpoints\Servers\Ownership;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers\Shares;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections as TCPConnections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * H-HSC-6 — the one-park backstop of `Response::defer()`: an HTTP/1.1
 * connection that already owns a parked deferral never parks another.
 *
 * The hold (`Decoder_Holding`) keeps every request pipelined behind a parked
 * deferral undecoded, so a second top-level `defer()` on that connection is
 * unreachable; should the hold ever regress, the backstop answers nothing
 * and closes the connection instead of parking without bound. Driven through
 * the real `defer()`, with the connection's teardown owners standing in for
 * a parked generation (`Ownership::attach()`). Pinned:
 *
 * - B1: HTTP/1, an owner attached, no selection lease — refused before the
 *   snapshot: the work never runs, the Response is marked deferred, the
 *   transport closes the connection after the write, nothing is charged to
 *   the Inbound share and no pooled Fiber is taken;
 * - B2: the same owner on an HTTP/2 stream — admitted (streams park
 *   concurrently, bounded by the stream cap);
 * - B3: HTTP/1 with no owner — admitted;
 * - B4: a nested handoff from inside parked work (the connection owns the
 *   parent generation, the child holds its lease) — admitted;
 * - B5: a sibling clone deferring while another generation holds the lease —
 *   a no-op: its work never runs and the connection is not closed.
 */
if (! class_exists('U166Encoder', false)) {
   /**
    * Route stand-in: the transport hands it each decoded request.
    */
   class U166Encoder implements ServerEncoder
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
if (! class_exists('U166Owner', false)) {
   /**
    * A parked generation's stand-in on the connection's teardown registry.
    */
   class U166Owner implements Disconnecting
   {
      // * Metadata
      public int $calls = 0;

      public function disconnect (): void
      {
         $this->calls++;
      }
   }
}


return new Test(
   description: 'H-HSC-6: an HTTP/1.1 connection that owns a parked deferral never parks another',
   test: new Assertions(Case: function (): Generator {
      $WPI = WPI;
      $Parked = new ReflectionProperty(Response::class, 'Pool');
      $Contexts = new ReflectionProperty(Language::class, 'Contexts');
      $Held = static fn (): int => new ReflectionProperty(Buffers::class, 'held')->getValue()[Shares::Inbound->value];

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
      $Generations = [];
      $Select = null;

      // ! A server end that reads everything the client end sent and a
      //   client end that never blocks
      $Open = static function (int $port) use (&$Sockets, &$Connections): array {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('U166 could not open a socket pair.');
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
      $Compose = static fn (string $path): string => "GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n";
      // ! The work every admitted deferral runs: it records that it ran and
      //   its generation, then parks for good (cancelled at teardown)
      $Park = static function (string $tag, array &$runs) use (&$Generations): Closure {
         return static function (Response $Deferred) use ($tag, &$runs, &$Generations): void {
            $runs[] = $tag;
            $Fiber = Fiber::getCurrent();
            $Generation = $Fiber === null ? null : Cancellation::fetch($Fiber);
            if ($Generation !== null) {
               $Generations[] = $Generation;
            }
            // @@ Parked until teardown cancels the generation
            while (true) {
               $Deferred->wait();
            }
         };
      };

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
         TCP_Server_CLI::$Encoder = new U166Encoder;
         $WPI->Router = new Router;
         // ! The ambient context a deferred segment binds over and restores
         $WPI->Request = new Request;
         $WPI->Response = new Response;
         $Parked->setValue(null, []);
         Response::$deferredTimeout = 0;

         // ! What each route observed right after its defer() call
         $observed = [];
         $runs = [];

         // ! One deferral that completes inline parks its Fiber in the pool:
         //   a refusal must leave it there
         [$Connection, $Socket, $Client] = $Open(40020);
         U166Encoder::$Route = static function (Request $Request, TCPPackages $Package): string {
            $Response = new Response;
            $Response->reset($Package, $Request);
            $Response->defer(static function (Response $Deferred): void {
               $Deferred->Body->raw = 'warm';
            });

            return '';
         };
         fwrite($Client, $Compose('/warm'));
         $Connection->reading($Socket);
         $warm = str_ends_with($Receive($Client), 'warm');

         // @@ B1) HTTP/1, an owner attached, no lease: refused, closed
         [$Connection, $Socket, $Client] = $Open(40021);
         $Owner = new U166Owner;
         Ownership::attach($Connection, $Owner);
         $pooled = count($Parked->getValue());
         U166Encoder::$Route = static function (Request $Request, TCPPackages $Package) use ($Park, &$runs, &$observed, $Held, $Parked): string {
            $Response = new Response;
            $Response->reset($Package, $Request);
            $held = $Held();
            $pool = count($Parked->getValue());
            $Response->defer($Park('B1', $runs));
            $observed['B1'] = [
               'deferred' => $Response->deferred,
               'closeAfterWrite' => $Package->closeAfterWrite,
               'charged' => $Held() - $held,
               'pool' => count($Parked->getValue()) - $pool,
               'idle' => $pool > 0,
            ];

            return '';
         };
         fwrite($Client, $Compose('/b1'));
         $Connection->reading($Socket);
         $refused = [
            'observed' => $observed['B1'] ?? null,
            'ran' => in_array('B1', $runs, true),
            'status' => $Connection->status,
            'written' => $Receive($Client),
            'owner' => $Owner->calls,
            'pooled' => count($Parked->getValue()) - $pooled,
            'warm' => $warm,
         ];
         $evidence = json_encode($refused);

         yield assert(
            assertion: $refused === [
               'observed' => [
                  'deferred' => true,
                  'closeAfterWrite' => true,
                  'charged' => 0,
                  'pool' => 0,
                  'idle' => true,
               ],
               'ran' => false,
               'status' => TCPConnections::STATUS_CLOSED,
               'written' => '',
               'owner' => 1,
               'pooled' => 0,
               'warm' => true,
            ],
            description: "B1: an HTTP/1.1 connection that owns a parked deferral refuses another before the snapshot — the work never runs, nothing is charged, the connection closes after the write: {$evidence}",
         );

         // @@ B2) The same owner on an HTTP/2 stream: admitted
         [$Connection, $Socket, $Client] = $Open(40022);
         Ownership::attach($Connection, new U166Owner);
         U166Encoder::$Route = static function (Request $Request, TCPPackages $Package) use ($Park, &$runs, &$observed): string {
            $Request->stream = 1;
            $Response = new Response;
            $Response->reset($Package, $Request);
            $Response->defer($Park('B2', $runs));
            $observed['B2'] = ['closeAfterWrite' => $Package->closeAfterWrite];

            return '';
         };
         fwrite($Client, $Compose('/b2'));
         $Connection->reading($Socket);
         $stream = [
            'observed' => $observed['B2'] ?? null,
            'ran' => in_array('B2', $runs, true),
            'status' => $Connection->status,
         ];

         // @@ B3) HTTP/1 with no owner: admitted
         [$Connection, $Socket, $Client] = $Open(40023);
         U166Encoder::$Route = static function (Request $Request, TCPPackages $Package) use ($Park, &$runs, &$observed): string {
            $Response = new Response;
            $Response->reset($Package, $Request);
            $Response->defer($Park('B3', $runs));
            $observed['B3'] = ['closeAfterWrite' => $Package->closeAfterWrite];

            return '';
         };
         fwrite($Client, $Compose('/b3'));
         $Connection->reading($Socket);
         $ownerless = [
            'observed' => $observed['B3'] ?? null,
            'ran' => in_array('B3', $runs, true),
            'status' => $Connection->status,
         ];
         $evidence = json_encode(['B2' => $stream, 'B3' => $ownerless]);

         yield assert(
            assertion: $stream === [
               'observed' => ['closeAfterWrite' => false],
               'ran' => true,
               'status' => TCPConnections::STATUS_ESTABLISHED,
            ]
               && $ownerless === [
                  'observed' => ['closeAfterWrite' => false],
                  'ran' => true,
                  'status' => TCPConnections::STATUS_ESTABLISHED,
               ],
            description: "B2/B3: an HTTP/2 stream on an owned connection, and an HTTP/1.1 connection with no owner, are admitted: {$evidence}",
         );

         // @@ B4) A nested handoff from inside the parent's work: the
         //    connection owns the parent generation, the child holds its lease
         [$Connection, $Socket, $Client] = $Open(40024);
         U166Encoder::$Route = static function (Request $Request, TCPPackages $Package) use ($Park, &$runs, &$observed): string {
            $Response = new Response;
            $Response->reset($Package, $Request);
            $Response->defer(static function (Response $Deferred) use ($Park, $Package, &$runs, &$observed): void {
               $runs[] = 'B4 parent';
               $owned = Ownership::check($Package->Connection);
               $Deferred->defer($Park('B4 child', $runs));
               $observed['B4'] = [
                  'owned' => $owned,
                  'closeAfterWrite' => $Package->closeAfterWrite,
               ];
            });

            return '';
         };
         fwrite($Client, $Compose('/b4'));
         $Connection->reading($Socket);
         $nested = [
            'observed' => $observed['B4'] ?? null,
            'ran' => [in_array('B4 parent', $runs, true), in_array('B4 child', $runs, true)],
            'status' => $Connection->status,
         ];

         // @@ B5) A sibling clone deferring while another generation holds
         //    the lease: a no-op
         [$Connection, $Socket, $Client] = $Open(40025);
         U166Encoder::$Route = static function (Request $Request, TCPPackages $Package) use ($Park, &$runs, &$observed): string {
            $Response = new Response;
            $Response->reset($Package, $Request);
            $Sibling = clone $Response;
            $Response->defer(static function (Response $Deferred) use ($Park, $Package, $Sibling, &$runs, &$observed): void {
               $runs[] = 'B5 parent';
               $Sibling->defer($Park('B5 sibling', $runs));
               $observed['B5'] = [
                  'deferred' => $Sibling->deferred,
                  'closeAfterWrite' => $Package->closeAfterWrite,
               ];
               // @@ The parent stays parked
               while (true) {
                  $Deferred->wait();
               }
            });

            return '';
         };
         fwrite($Client, $Compose('/b5'));
         $Connection->reading($Socket);
         $sibling = [
            'observed' => $observed['B5'] ?? null,
            'ran' => [in_array('B5 parent', $runs, true), in_array('B5 sibling', $runs, true)],
            'status' => $Connection->status,
         ];
         $evidence = json_encode(['B4' => $nested, 'B5' => $sibling]);

         yield assert(
            assertion: $nested === [
               'observed' => ['owned' => true, 'closeAfterWrite' => false],
               'ran' => [true, true],
               'status' => TCPConnections::STATUS_ESTABLISHED,
            ]
               && $sibling === [
                  'observed' => ['deferred' => true, 'closeAfterWrite' => false],
                  'ran' => [true, false],
                  'status' => TCPConnections::STATUS_ESTABLISHED,
               ],
            description: "B4: a nested handoff holding the lease is admitted on a connection that owns its parent; B5: a sibling clone is a no-op that closes nothing: {$evidence}",
         );
      }
      finally {
         U166Encoder::$Route = null;
         // @ Parked work ends with its generation
         foreach ($Generations as $Generation) {
            $Generation->cancel();
         }
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
