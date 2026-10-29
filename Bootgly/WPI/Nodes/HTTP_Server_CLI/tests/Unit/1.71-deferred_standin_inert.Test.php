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
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_Testing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Recovering;


/**
 * A detached stand-in — what a resource kept past its deferred job answers
 * for once the job's Response is gone — is inert: a handler that returns it
 * answers nothing, it never becomes the worker's Response, and no two late
 * calls share it.
 *
 * The leftovers come from a real deferred job (the real `defer()` and job
 * loop on a pooled Fiber, the worker reactor installed, a real connection),
 * whose Response is freed by refcounting when the job ends: its JSON
 * formatter, its resource registry and a user resource with a wait bridge.
 * Then each stand-in source is returned to the real encoders (`Encoder_` and
 * its Test-environment mirror `Encoder_Testing`), over real connections on
 * socket pairs, the requests decoded by `Decoder_`, with the server statics
 * bound as a constructed server binds them. Pinned, for both encoders:
 *
 * - a stand-in returned by the handler — from the formatter, from a resource
 *   the orphaned registry builds lazily, from the wait bridge — by a route
 *   (static, dynamic, and one opted into the route cache), with a Received
 *   listener admitting the request, and on an HTTP/2 stream: nothing reaches
 *   the wire for that request (no HTTP/1 byte, no frame on that stream), and
 *   the worker's Response is still the original one, in both server slots;
 * - the cached route returning one stores nothing, although it stamped its
 *   TTL on the worker's Response — which the next request resets — while the
 *   same route answering with that Response is stored (the control);
 * - the next request on the same worker is answered 200 with the configured
 *   resource and the Header preset the worker's Response carries — on its
 *   own connection, on the same HTTP/1.1 connection after it, and pipelined
 *   behind it in one write;
 * - that is the behaviour a handler returning a deferred Response no job
 *   answers always had — pinned beside it: the handler's own Response
 *   flagged deferred, with no job — kept as is: that request gets no byte
 *   and arms no hold, so the request behind it is decoded and answered at
 *   once, and the one answer a pipelining client gets is the probe's — an
 *   HTTP/1.1 client pairing answers in order reads it as the first
 *   request's;
 * - a recovery boundary that answers a deferred failure with a stand-in
 *   declines: the stand-in never reaches the wire, and the request gets the
 *   Catcher's 500 — reported once, as a declined failure is;
 * - two late calls never share a stand-in or a body: each send answers with
 *   its own fresh stand-in, carrying its own body, deferred and bound to no
 *   request;
 * - each stand-in is freed by refcount, its Header with it, the moment its
 *   caller lets go — from the formatter, from the wait bridge, and the one
 *   the orphaned registry hands a factory as its context.
 */
if (! class_exists('U171Waiter', false)) {
   /**
    * A user resource that parks through the response wait bridge.
    */
   class U171Waiter extends Resource implements Scheduling
   {
      // * Data
      private null|Closure $Wait = null;


      public function schedule (Closure $Wait): static
      {
         $this->Wait = $Wait;

         return $this;
      }

      public function await (mixed $value = null): object
      {
         $Wait = $this->Wait ?? throw new RuntimeException('U171 waiter is not bound.');

         return $Wait($value);
      }
   }
}
if (! class_exists('U171Probe', false)) {
   /**
    * A resource the worker's configuration registers.
    */
   class U171Probe extends Resource
   {
   }
}
if (! class_exists('U171Boundary', false)) {
   /**
    * A global error boundary answering a deferred failure with whatever its
    * source hands it.
    */
   final class U171Boundary implements Recovering
   {
      // * Config
      public Closure $Answer;

      // * Metadata
      public private(set) int $recoveries = 0;


      public function __construct (Closure $Answer)
      {
         $this->Answer = $Answer;
      }

      public function process (object $Request, object $Response, Closure $next): object
      {
         return $next($Request, $Response);
      }

      public function recover (Request $Request, Response $Response, Throwable $Throwable): null|Response
      {
         $this->recoveries++;

         return ($this->Answer)();
      }
   }
}


return new Test(
   description: 'A detached stand-in returned to the encoders or a recovery boundary should answer nothing, never become the worker Response, never be shared by two late calls and be freed by refcount',
   test: new Assertions(Case: function (): Generator {
      $WPI = WPI;
      $Parked = new ReflectionProperty(Response::class, 'Pool');
      $Contexts = new ReflectionProperty(Language::class, 'Contexts');
      $slots = ['Request', 'Response', 'Router'];

      // ! Whether a WPI slot is bound to its server static — a constructed
      //   server binds them — so the binding this case makes is undone
      //   exactly as it found it
      $Bound = static function (string $slot) use ($WPI): bool {
         if (
            isset($WPI->{$slot}) === false
            || isset(HTTP_Server_CLI::${$slot}) === false
            || $WPI->{$slot} !== HTTP_Server_CLI::${$slot}
         ) {
            return false;
         }

         $Saved = HTTP_Server_CLI::${$slot};
         $Fresh = match ($slot) {
            'Request' => new Request,
            'Response' => new Response,
            default => new Router,
         };
         HTTP_Server_CLI::${$slot} = $Fresh;
         $bound = $WPI->{$slot} === $Fresh;
         HTTP_Server_CLI::${$slot} = $Saved;

         return $bound;
      };

      // ! Statics this case writes — directly, or through the encoders,
      //   defer() and the job loop
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $OldDecoder = TCP_Server_CLI::$Decoder;
      $OldEncoder = TCP_Server_CLI::$Encoder;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $oldHTTP2 = HTTP_Server_CLI::$enableHTTP2;
      $OldStatics = [];
      $OldSlots = [];
      $bound = [];
      foreach ($slots as $slot) {
         $bound[$slot] = $Bound($slot);
         $OldStatics[$slot] = isset(HTTP_Server_CLI::${$slot}) ? HTTP_Server_CLI::${$slot} : null;
         $OldSlots[$slot] = isset($WPI->{$slot}) ? $WPI->{$slot} : null;
      }
      $OldHandler = isset(SAPI::$Handler) ? SAPI::$Handler : null;
      $OldMiddlewares = isset(SAPI::$Middlewares) ? SAPI::$Middlewares : null;
      $OldEnvironment = SAPI::$Environment;
      $oldIndex = SAPI::$testIndexHeader;
      $OldCatcher = Catcher::$Environment;
      $OldReporters = Throwables::$reporters;
      $OldEmitter = isset(Emitter::$Instance) ? Emitter::$Instance : null;
      $OldPool = $Parked->getValue();
      $OldContexts = $Contexts->getValue();
      $oldTimeout = Response::$deferredTimeout;
      $collectable = gc_enabled();

      $Sockets = [];
      $Select = null;
      $port = 40171;

      // ! A decoded per-connection Request, its head in strings of its own
      $Decode = static function (string $path): Request {
         $Connection = new ReflectionClass(Connection::class)->newInstanceWithoutConstructor();
         $Connection->ip = '127.0.0.1';
         $Connection->port = 40171;
         $Connection->encrypted = false;
         $Connection->writes = 1;
         $Package = new class extends TCPPackages {
            // ! decode() only reads the connection truth and the reject slot
            public function __construct () {}

            public function reject (string $raw): void
            {
               throw new RuntimeException("A 1.71 head was rejected: {$raw}");
            }
         };
         $Package->Connection = $Connection;
         $Request = new Request;
         $Request->reset();
         $buffer = "GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $Request->decode($Package, $buffer, strlen($buffer));

         return $Request;
      };
      // ! A server end the encoder writes to and a client end that never
      //   blocks
      $Open = static function () use (&$Sockets, &$port): array {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('U171 could not open a socket pair.');
         }
         [$Server, $Client] = $Pair;
         stream_set_blocking($Server, false);
         stream_set_blocking($Client, false);
         stream_set_read_buffer($Server, 0);
         $Sockets[] = $Server;
         $Sockets[] = $Client;

         return [new Connection($Server, '127.0.0.1', ++$port), $Server, $Client];
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
      // ! One HTTP/1.1 request through the real decoder and encoder
      $Exchange = static function (string $path) use ($Open, $Receive): string {
         [$Connection, $Server, $Client] = $Open();
         fwrite($Client, "GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");
         $Connection->reading($Server);

         return $Receive($Client);
      };
      // ! The frames of an HTTP/2 wire, by stream: type and payload
      $Frames = static function (string $wire): array {
         $frames = [];
         $offset = 0;
         while (strlen($wire) - $offset >= 9) {
            $length = (ord($wire[$offset]) << 16) | (ord($wire[$offset + 1]) << 8) | ord($wire[$offset + 2]);
            $stream = unpack('N', substr($wire, $offset + 5, 4))[1] & 0x7FFF_FFFF;
            $frames[$stream][] = [ord($wire[$offset + 3]), substr($wire, $offset + 9, $length)];
            $offset += 9 + $length;
         }

         return $frames;
      };
      // ! A probe of the worker: answered 200, its preset on the head, the
      //   configured resource reached, the body its own
      $Probe = static function (string $wire): array {
         $parts = explode("\r\n\r\n", $wire, 2);

         return [
            'status' => strstr($parts[0], "\r\n", true) ?: $parts[0],
            'preset' => str_contains($parts[0], "\r\nX-U171: kept"),
            'body' => $parts[1] ?? null,
         ];
      };
      $probed = ['status' => 'HTTP/1.1 200 OK', 'preset' => true, 'body' => 'probed'];

      // ! What a stand-in request leaves on the worker
      $Persistent = null;
      $Leave = static function () use ($WPI, &$Persistent): array {
         return [
            'static' => HTTP_Server_CLI::$Response === $Persistent,
            'slot' => $WPI->Response === $Persistent,
         ];
      };
      $kept = ['static' => true, 'slot' => true];
      // ! Every leg starts from the worker's own Response, so a leg that
      //   loses it is the only one that reports it
      $Seat = static function () use (&$Persistent): void {
         HTTP_Server_CLI::$Response = $Persistent;
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
         HTTP_Server_CLI::$enableHTTP2 = true;
         $WPI->Router = new Router;
         // ! The ambient context a deferred segment binds over and restores
         $WPI->Request = $Decode('/ambient');
         $WPI->Response = new Response;
         $Parked->setValue(null, []);
         Response::$deferredTimeout = 0;
         // ! Byte-stable Catcher answers, and a reporter that records
         SAPI::$Environment = Environments::Test;
         SAPI::$testIndexHeader = null;
         Catcher::$Environment = null;
         $reports = [];
         Throwables::$reporters = [
            static function (Throwable $Throwable, array $context) use (&$reports): void {
               $reports[] = $Throwable::class . ": {$Throwable->getMessage()}";
            },
         ];
         Emitter::$Instance = new Emitter;

         // ! No cycle collection from here on: only refcounting decides what
         //   outlives the job
         gc_disable();

         // @ The leftovers of a real deferred job whose Response is gone
         [$Connection, $Server, $Client] = $Open();
         $Job = new Response;
         // ! What the orphaned registry hands a factory as its context, and
         //   that context's Header, held weakly
         $Witnessed = [];
         $Job->Resources->load([
            'Waiter' => static fn (object $Context): U171Waiter => new U171Waiter,
            'Witness' => static function (object $Context) use (&$Witnessed): U171Probe {
               $Witnessed[] = WeakReference::create($Context);
               if ($Context instanceof Response) {
                  $Witnessed[] = WeakReference::create($Context->Header);
               }

               return new U171Probe;
            },
         ]);
         $Job->reset($Connection, $Decode('/job'));
         $Left = [];
         $Gone = null;
         $Job->defer(static function (Response $Deferred) use (&$Left, &$Gone): void {
            $Left['JSON'] = $Deferred->JSON;
            $Left['Waiter'] = $Deferred->Waiter;
            $Left['Resources'] = $Deferred->Resources;
            $Gone = WeakReference::create($Deferred);

            $Deferred->JSON->send(['job' => 171]);
         });
         $Job->reset($Connection, $Decode('/next'));
         $job = [
            'answered' => str_contains($Receive($Client), '{"job":171}'),
            'freed' => $Gone?->get() === null,
         ];
         unset($Job, $Connection, $Server, $Client);

         // ! The worker's Response: a configured resource and a Header preset
         $Persistent = new Response;
         $Persistent->Resources->load([
            'Probe' => static fn (object $Context): U171Probe => new U171Probe,
         ]);
         $Persistent->Header->preset('X-U171', 'kept');
         HTTP_Server_CLI::$Request = new Request;
         HTTP_Server_CLI::$Response = $Persistent;
         HTTP_Server_CLI::$Router = new Router;
         // ! Bound as a constructed server binds them: the router adopts
         //   into the same slot the encoders alias
         $WPI->Request = &HTTP_Server_CLI::$Request;
         $WPI->Response = &HTTP_Server_CLI::$Response;
         $WPI->Router = &HTTP_Server_CLI::$Router;

         $rows = [];
         $sources = [
            'json' => static fn (): Response => $Left['JSON']->send(['user' => 'alice', 'token' => 'SECRET-A']),
            'lazy' => static fn (): Response => $Left['Resources']->fetch('XML')->send('<token>SECRET-A</token>'),
            'bridge' => static fn (): object => $Left['Waiter']->await(),
         ];
         foreach ([Encoder_::class, Encoder_Testing::class] as $class) {
            TCP_Server_CLI::$Encoder = new $class;
            $short = substr($class, strrpos($class, '\\') + 1);

            // # Returned by the handler
            SAPI::$Middlewares = new Middlewares;
            SAPI::$Handler = static function (Request $Request, Response $Response) use ($sources): object {
               // ! Deferred work that fails: a recovery boundary answers it
               if ($Request->URI === '/standin/rescue') {
                  return $Response->defer(static function (): void {
                     throw new RuntimeException('U171 deferred failure');
                  });
               }
               // ! The handler's own Response flagged deferred, with no job
               //   to answer it
               if ($Request->URI === '/standin/own') {
                  $Response->deferred = true;

                  return $Response;
               }

               $source = $sources[substr($Request->URI, strlen('/standin/'))] ?? null;
               if ($source !== null) {
                  return $source();
               }

               // ! The probe: a configured resource of the worker's Response
               $Response->Probe;

               return $Response(body: 'probed');
            };
            foreach (array_keys($sources) as $source) {
               $Seat();
               $rows["{$short} {$source}"] = [
                  'wire' => $Exchange("/standin/{$source}"),
                  'worker' => $Leave(),
                  'probe' => $Probe($Exchange('/probe')),
               ];
            }

            // # The probe on the SAME HTTP/1.1 connection: after the request,
            //   then pipelined behind it in one write — for the stand-in, and
            //   for the handler's own Response flagged deferred with no job
            //   to answer it, which has always been suppressed this way. No
            //   byte answers either and no hold is armed for either, so the
            //   probe is decoded and answered at once: the one answer a
            //   pipelining client gets is the probe's
            foreach (['json', 'own'] as $source) {
               $Seat();
               [$Connection, $Server, $Client] = $Open();
               fwrite($Client, "GET /standin/{$source} HTTP/1.1\r\nHost: localhost\r\n\r\n");
               $Connection->reading($Server);
               $first = $Receive($Client);
               fwrite($Client, "GET /probe HTTP/1.1\r\nHost: localhost\r\n\r\n");
               $Connection->reading($Server);
               $rows["{$short} keep-alive {$source}"] = [
                  'wire' => $first,
                  'worker' => $Leave(),
                  'probe' => $Probe($Receive($Client)),
               ];
               $Seat();
               [$Connection, $Server, $Client] = $Open();
               fwrite($Client, "GET /standin/{$source} HTTP/1.1\r\nHost: localhost\r\n\r\nGET /probe HTTP/1.1\r\nHost: localhost\r\n\r\n");
               $Connection->reading($Server);
               $wire = $Receive($Client);
               $rows["{$short} pipelined {$source}"] = [
                  'answers' => substr_count($wire, 'HTTP/1.1 '),
                  'worker' => $Leave(),
                  'probe' => $Probe($wire),
               ];
            }
            unset($Connection, $Server, $Client);

            // # Returned by a recovery boundary for deferred work that threw:
            //   a decline — the Catcher answers, and reports the failure
            $Seat();
            $Boundary = new U171Boundary(static fn (): Response => $sources['json']());
            SAPI::$Middlewares = new Middlewares()->pipe($Boundary);
            $reports = [];
            $wire = $Exchange('/standin/rescue');
            $rows["{$short} rescue"] = [
               'status' => strstr($wire, "\r\n", true),
               'leaked' => str_contains($wire, 'SECRET'),
               'recoveries' => $Boundary->recoveries,
               'reports' => $reports,
               'worker' => $Leave(),
            ];
            SAPI::$Middlewares = new Middlewares;
            $reports = [];
            $rows["{$short} rescue"]['probe'] = $Probe($Exchange('/probe'));
            unset($Boundary);

            // # Returned while a Received listener admits the request
            $Seat();
            Emitter::$Instance->listen(RequestEvents::Received, static function (Emission $Emission): void {});
            $rows["{$short} observed"] = [
               'wire' => $Exchange('/standin/json'),
               'worker' => $Leave(),
               'probe' => $Probe($Exchange('/probe')),
            ];
            Emitter::$Instance = new Emitter;

            // # Returned on an HTTP/2 stream — the probe on the next stream
            $Seat();
            [$Connection, $Server, $Client] = $Open();
            fwrite($Client, HTTP2::PREFACE
               . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, '')
               . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 1, HPACK::encode([
                  [':method', 'GET'],
                  [':scheme', 'http'],
                  [':path', '/standin/json'],
                  [':authority', 'localhost'],
               ])));
            $Connection->reading($Server);
            $first = $Frames($Receive($Client));
            $worker = $Leave();
            fwrite($Client, Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 3, HPACK::encode([
               [':method', 'GET'],
               [':scheme', 'http'],
               [':path', '/probe'],
               [':authority', 'localhost'],
            ])));
            $Connection->reading($Server);
            $second = $Frames($Receive($Client));
            $Head = null;
            $body = '';
            foreach ($second[3] ?? [] as [$type, $payload]) {
               if ($type === HTTP2::FRAME_HEADERS) {
                  $Head = new HPACK()->decode($payload, 65_536);
               }
               if ($type === HTTP2::FRAME_DATA) {
                  $body .= $payload;
               }
            }
            $status = null;
            $preset = false;
            foreach ($Head ?? [] as [$name, $value]) {
               if ($name === ':status') {
                  $status = $value;
               }
               if ($name === 'x-u171') {
                  $preset = $value === 'kept';
               }
            }
            $rows["{$short} h2"] = [
               'frames' => isset($first[1]) ? count($first[1]) : 0,
               'worker' => $worker,
               'probe' => ['status' => $status, 'preset' => $preset, 'body' => $body],
            ];
            unset($Connection, $Server, $Client);

            // # Returned by a route: static, dynamic, and cached
            HTTP_Server_CLI::$Router = new Router;
            // ! The control: the cached route answering with the Response it
            //   was handed instead of a stand-in
            $control = false;
            // ! The route-cache stamp the probe's handler finds on the
            //   worker's Response
            $seen = null;
            SAPI::$Handler = static function (Request $Request, Response $Response, Router $Router) use ($sources, &$control, &$seen): Generator {
               yield $Router->route('/route/static', static fn (): Response => $sources['json'](), GET);
               yield $Router->route('/route/dynamic/:id', static fn (): Response => $sources['json'](), GET);
               yield $Router->route('/route/cached', static function (Request $Request, Response $Response) use ($sources, &$control): Response {
                  return $control
                     ? $Response(body: 'control')
                     : $sources['json']();
               }, GET, cache: ['TTL' => 60]);
               yield $Router->route('/probe', static function (Request $Request, Response $Response) use (&$seen): Response {
                  $seen = $Response->cache;
                  $Response->Probe;

                  return $Response(body: 'probed');
               }, GET);
            };
            foreach (['static' => '/route/static', 'dynamic' => '/route/dynamic/171'] as $route => $path) {
               $Seat();
               $rows["{$short} route {$route}"] = [
                  'wire' => $Exchange($path),
                  'worker' => $Leave(),
                  'probe' => $Probe($Exchange('/probe')),
               ];
            }
            // ! The cached route stamps its TTL on the worker's Response
            //   before its handler runs; the next request starts without it
            $Seat();
            $wire = $Exchange('/route/cached');
            $stamped = $Persistent->cache;
            $probe = $Probe($Exchange('/probe'));
            $rows["{$short} route cached"] = [
               'wire' => $wire,
               'worker' => $Leave(),
               'cached' => isset(Cache::$URIs['/route/cached']),
               'stamp' => [$stamped, $seen],
               'probe' => $probe,
            ];
            $control = true;
            $Seat();
            $wire = $Exchange('/route/cached');
            $rows["{$short} route control"] = [
               'status' => strstr($wire, "\r\n", true),
               'body' => explode("\r\n\r\n", $wire, 2)[1] ?? null,
               'cached' => isset(Cache::$URIs['/route/cached']),
            ];
            $control = false;
            Cache::flush();
         }

         $evidence = json_encode(['job' => $job, 'rows' => $rows, 'reports' => $reports]);
         $handled = ['wire' => '', 'worker' => $kept, 'probe' => $probed];
         $streamed = ['frames' => 0, 'worker' => $kept, 'probe' => ['status' => '200', 'preset' => true, 'body' => 'probed']];
         $expected = [];
         foreach (['Encoder_', 'Encoder_Testing'] as $short) {
            $expected["{$short} json"] = $handled;
            $expected["{$short} lazy"] = $handled;
            $expected["{$short} bridge"] = $handled;
            $expected["{$short} keep-alive json"] = $handled;
            $expected["{$short} pipelined json"] = ['answers' => 1, 'worker' => $kept, 'probe' => $probed];
            $expected["{$short} keep-alive own"] = $handled;
            $expected["{$short} pipelined own"] = ['answers' => 1, 'worker' => $kept, 'probe' => $probed];
            $expected["{$short} rescue"] = [
               'status' => 'HTTP/1.1 500 Internal Server Error',
               'leaked' => false,
               'recoveries' => 1,
               'reports' => ['RuntimeException: U171 deferred failure'],
               'worker' => $kept,
               'probe' => $probed,
            ];
            $expected["{$short} observed"] = $handled;
            $expected["{$short} h2"] = $streamed;
            $expected["{$short} route static"] = $handled;
            $expected["{$short} route dynamic"] = $handled;
            $expected["{$short} route cached"] = [
               'wire' => '',
               'worker' => $kept,
               'cached' => false,
               'stamp' => [60, 0],
               'probe' => $probed,
            ];
            $expected["{$short} route control"] = ['status' => 'HTTP/1.1 200 OK', 'body' => 'control', 'cached' => true];
         }

         yield assert(
            assertion: $job === ['answered' => true, 'freed' => true]
               && $rows === $expected
               && $reports === [],
            description: "A stand-in returned by a handler or a route — from the formatter, a lazily built resource or the wait bridge, observed or on an HTTP/2 stream — answers nothing, the worker's Response stays the original in both slots, and the next request is answered 200 with its configured resource and preset, on its own connection, after it on the same one and pipelined behind it; the cached route stores nothing for it though it stamped its TTL (reset by the next request), while the same route answering for real is stored; a recovery boundary returning a stand-in declines, and the Catcher answers: {$evidence}",
         );

         // @ Two late calls never share a stand-in or a body
         $Lazy = $Left['Resources']->fetch('XML');
         $A = $Left['JSON']->send(['user' => 'alice', 'token' => 'SECRET-A']);
         $B = $Left['JSON']->send(['user' => 'bob']);
         $C = $Lazy->send('<user>alice</user>');
         $D = $Lazy->send('<user>bob</user>');
         $E = $Left['Waiter']->await();
         $F = $Left['Waiter']->await();
         $inert = static fn (object $Inert): bool => $Inert instanceof Response
            && $Inert->deferred
            && $Inert->Request === null
            && $Inert !== $Persistent;
         $late = [
            'distinct' => count(array_unique(array_map(spl_object_id(...), [$A, $B, $C, $D, $E, $F]))) === 6,
            'inert' => array_map($inert, [$A, $B, $C, $D, $E, $F]) === array_fill(0, 6, true),
            'bodies' => [$A->Body->raw, $B->Body->raw, $C->Body->raw, $D->Body->raw],
            'mounted' => $Left['Resources']->fetch('XML') === $Lazy,
         ];

         yield assert(
            assertion: $late === [
               'distinct' => true,
               'inert' => true,
               'bodies' => ['{"user":"alice","token":"SECRET-A"}', '{"user":"bob"}', '<user>alice</user>', '<user>bob</user>'],
               'mounted' => true,
            ],
            description: 'Two late calls never share a stand-in: each send answers with its own fresh one — deferred, bound to no request — carrying its own body, from the formatter, the lazily built resource and the wait bridge alike: '
               . json_encode($late)
         );

         // @ Each stand-in goes the moment its caller lets go: the Response
         //   and its Header, freed by refcount (no collector runs here)
         $Answers = [];
         foreach (['formatter' => $A, 'lazy' => $C, 'bridge' => $E] as $source => $Answer) {
            $Answers[$source] = [WeakReference::create($Answer), WeakReference::create($Answer->Header)];
         }
         unset($Answer, $A, $B, $C, $D, $E, $F);
         $freed = [];
         foreach ($Answers as $source => [$Answer, $Header]) {
            $freed[$source] = [$Answer->get() === null, $Header->get() === null];
         }
         // ! The context the orphaned registry hands a factory
         $Witnessed = [];
         $mounted = $Left['Resources']->fetch('Witness') instanceof U171Probe;
         $freed['context'] = count($Witnessed) === 2 && $mounted
            ? [$Witnessed[0]->get() === null, $Witnessed[1]->get() === null]
            : count($Witnessed);

         yield assert(
            assertion: $freed === [
               'formatter' => [true, true],
               'lazy' => [true, true],
               'bridge' => [true, true],
               'context' => [true, true],
            ],
            description: 'Each stand-in is freed by refcount, its Header with it, the moment its caller lets go — the one a formatter send answers with, the one a lazily built formatter answers with, the one the wait bridge answers with, and the context the orphaned registry hands a factory: '
               . json_encode($freed)
         );
         unset($Left, $Lazy, $Answers, $Witnessed);
      }
      finally {
         if ($collectable) {
            gc_enable();
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

         // ! Undo the binding first, then restore each slot as it was found
         foreach ($slots as $slot) {
            unset($WPI->{$slot});
            if ($OldStatics[$slot] !== null) {
               HTTP_Server_CLI::${$slot} = $OldStatics[$slot];
            }
            if ($bound[$slot]) {
               $WPI->{$slot} = &HTTP_Server_CLI::${$slot};
            }
            else if ($OldSlots[$slot] !== null) {
               $WPI->{$slot} = $OldSlots[$slot];
            }
         }

         Cache::flush();
         $Parked->setValue(null, $OldPool);
         $Contexts->setValue(null, $OldContexts);
         Response::$deferredTimeout = $oldTimeout;
         TCP_Server_CLI::$Decoder = $OldDecoder;
         TCP_Server_CLI::$Encoder = $OldEncoder;
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         HTTP_Server_CLI::$enableHTTP2 = $oldHTTP2;
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
         if ($OldHandler !== null) {
            SAPI::$Handler = $OldHandler;
         }
         if ($OldMiddlewares !== null) {
            SAPI::$Middlewares = $OldMiddlewares;
         }
         SAPI::$Environment = $OldEnvironment;
         SAPI::$testIndexHeader = $oldIndex;
         Catcher::$Environment = $OldCatcher;
         Throwables::$reporters = $OldReporters;
         if ($OldEmitter !== null) {
            Emitter::$Instance = $OldEmitter;
         }

         // ! What the case left behind is reclaimed here, not in a later case
         gc_collect_cycles();
      }
   })
);
