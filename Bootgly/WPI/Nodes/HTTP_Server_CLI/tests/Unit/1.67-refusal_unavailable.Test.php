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
use Bootgly\WPI\Endpoints\Servers\Encoder as ServerEncoder;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers\Shares;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies\Refusal;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_Testing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * ENC-8 — a refusal of the worker memory budget (`Decoders\Bodies\Refusal`)
 * is the server's capacity policy, not an application error: left unhandled
 * it is answered `503 Service Unavailable` and reported nowhere.
 *
 * Driven over a real connection on a socket pair, the request decoded by
 * `Decoder_`, with the Test environment's Catcher and a recording
 * `Throwables` reporter. Pinned:
 *
 * - through the production encoder (`Encoder_`) and its Test-environment
 *   mirror (`Encoder_Testing`): a route that throws a `Refusal`, and a route
 *   whose `defer()` the budget refuses (`Request::capture()` with the Inbound
 *   share full), answer 503 with no report; a route that throws any other
 *   `RuntimeException` still answers 500 and is reported once;
 * - through both encoders, a `Request\Events::Handled` listener whose
 *   `defer()` the budget refuses answers 503 with no report; a Handled
 *   listener that throws any other `RuntimeException` answers 500, reported
 *   once; a request the listener leaves alone keeps its route's answer;
 * - inside deferred work (the job loop): an unhandled `Refusal`, and the
 *   refusals of a cookie parse (`Header\Cookies`) and of a languages parse
 *   (`Request::charge()`) on a snapshot whose share holds nothing more,
 *   answer 503 with no report; any other `RuntimeException` answers 500 and
 *   is reported once;
 * - a Catcher that itself throws while it answers (here: the request head
 *   it negotiates from fails) degrades to a bare fallback that keeps the
 *   mapping — 503 for a budget refusal, 500 (reported once) for any other
 *   failure — at the routing catch and the Handled catch of both encoders
 *   and in the job loop;
 * - a refused `defer()` frees its Response clone: `Response::__clone()`
 *   captures the Request before the clone refers to itself, because PHP
 *   never frees a clone whose `__clone` threw after that — 1,673 B leaked
 *   per refusal, and a stream of refused deferrals killed a 128M worker.
 *   2,000 refusals after a warm-up keep under 64 B each.
 */
if (! class_exists('U167Encoder', false)) {
   /**
    * Route stand-in for the deferred legs: the transport hands it each
    * decoded request.
    */
   class U167Encoder implements ServerEncoder
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
if (! class_exists('U167Header', false)) {
   /**
    * Request head stand-in for the Catcher legs: its first read runs the
    * armed trap — which puts the request's own head back and throws, as a
    * Catcher that fails while it negotiates the answer — then it reads as
    * an empty head.
    */
   class U167Header extends Header
   {
      // * Data
      public static null|Closure $Trap = null;

      public function get (string $name): null|string
      {
         // ? Armed: fire once
         $Trap = self::$Trap;
         if ($Trap !== null) {
            self::$Trap = null;
            $Trap();
         }

         // :
         return parent::get($name);
      }
   }
}


return new Test(
   description: 'ENC-8: a budget refusal answers 503 and reports nothing; any other failure still answers 500 and is reported',
   test: new Assertions(Case: function (): Generator {
      $WPI = WPI;
      $Parked = new ReflectionProperty(Response::class, 'Pool');
      $Contexts = new ReflectionProperty(Language::class, 'Contexts');
      $Held = static fn (): int => new ReflectionProperty(Buffers::class, 'held')->getValue()[Shares::Inbound->value];
      $Price = static fn (Request $Request): int => new ReflectionMethod(Request::class, 'weigh')->invoke($Request);

      // ! Statics this case writes — directly, or through the encoders,
      //   defer() and the job loop
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $OldDecoder = TCP_Server_CLI::$Decoder;
      $OldEncoder = TCP_Server_CLI::$Encoder;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $oldBudget = TCP_Server_CLI::$maxWorkerPendingBytes;
      $OldRequest = isset(HTTP_Server_CLI::$Request) ? HTTP_Server_CLI::$Request : null;
      $OldResponse = isset(HTTP_Server_CLI::$Response) ? HTTP_Server_CLI::$Response : null;
      $OldServerRouter = isset(HTTP_Server_CLI::$Router) ? HTTP_Server_CLI::$Router : null;
      $OldRouter = isset($WPI->Router) ? $WPI->Router : null;
      $OldAmbient = isset($WPI->Request) ? $WPI->Request : null;
      $OldAnswer = isset($WPI->Response) ? $WPI->Response : null;
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

      $Sockets = [];
      $Connections = [];
      $Select = null;

      // ! A server end that reads everything the client end sent and a
      //   client end that never blocks
      $Open = static function (int $port) use (&$Sockets, &$Connections): array {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('U167 could not open a socket pair.');
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
      // ! The status line of a wire
      $Status = static fn (string $wire): string => strstr($wire, "\r\n", true) ?: $wire;
      $Compose = static fn (string $path, string $fields = ''): string => "GET {$path} HTTP/1.1\r\nHost: localhost\r\n{$fields}\r\n";
      // ! What the reporter received
      $reports = [];
      // ! A Catcher that throws while it answers: its one-shot environment
      //   leaves the Test answers (Production negotiates the representation
      //   from the request head), and the request's head throws on that read
      $Headed = new ReflectionProperty(Request::class, 'Header');
      $Arm = static function (Request $Request) use ($Headed): void {
         $Original = $Request->Header;
         $Headed->setValue($Request, new U167Header);
         Catcher::$Environment = Environments::Production;
         U167Header::$Trap = static function () use ($Headed, $Request, $Original): void {
            $Headed->setValue($Request, $Original);

            throw new RuntimeException('U167 Catcher failure');
         };
      };
      // ! The status line and whether the body is empty — the bare
      //   fallback's, not a Catcher page
      $Answer = static function (string $wire): array {
         $parts = explode("\r\n\r\n", $wire, 2);

         return [strstr($parts[0], "\r\n", true) ?: $parts[0], ($parts[1] ?? null) === ''];
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
         $WPI->Router = new Router;
         // ! The ambient context a deferred segment binds over and restores
         $WPI->Request = new Request;
         $WPI->Response = new Response;
         $Parked->setValue(null, []);
         Response::$deferredTimeout = 0;
         // ! Byte-stable Catcher answers, and a reporter that records
         SAPI::$Environment = Environments::Test;
         Catcher::$Environment = null;
         Throwables::$reporters = [
            static function (Throwable $Throwable, array $context) use (&$reports): void {
               $reports[] = $Throwable::class;
            },
         ];

         // @@ A) Routing: both encoders, three routes
         // ! The handler the encoders run: `/refuse` throws a refusal,
         //   `/capture` defers with the Inbound share full, `/fail` throws an
         //   application error
         $handled = [];
         SAPI::$Handler = static function (Request $Request, Response $Response, Router $Router) use (&$handled, $Held, $Arm): mixed {
            $path = $Request->URI;
            $handled[] = $path;

            if ($path === '/refuse') {
               throw new Refusal(Bodies::REFUSAL);
            }
            // ! The Catcher legs: the routing catch's Catcher throws
            if ($path === '/caught' || $path === '/crashed') {
               $Arm($Request);

               throw $path === '/caught'
                  ? new Refusal(Bodies::REFUSAL)
                  : new RuntimeException('U167 application failure');
            }
            // ! The Handled leg: the route answers, its listener acts after
            if (
               $path === '/handled' || $path === '/thrown' || $path === '/answered'
               || $path === '/caught/handled' || $path === '/crashed/handled'
            ) {
               return $Response(body: 'routed');
            }
            if ($path === '/capture') {
               $budget = TCP_Server_CLI::$maxWorkerPendingBytes;
               // ! A share that holds nothing more
               TCP_Server_CLI::$maxWorkerPendingBytes = 2 * ($Held() + 1);
               try {
                  $Response->defer(static function (Response $Deferred): void {
                     $Deferred->Body->raw = 'never';
                  });
               }
               finally {
                  TCP_Server_CLI::$maxWorkerPendingBytes = $budget;
               }

               return $Response;
            }

            throw new RuntimeException('U167 application failure');
         };

         $rows = [];
         $port = 40030;
         foreach ([Encoder_::class, Encoder_Testing::class] as $class) {
            HTTP_Server_CLI::$Response = new Response;
            HTTP_Server_CLI::$Router = new Router;
            SAPI::$Middlewares = new Middlewares;
            TCP_Server_CLI::$Encoder = new $class;
            [$Connection, $Socket, $Client] = $Open(++$port);
            $short = substr($class, strrpos($class, '\\') + 1);
            foreach (['/refuse', '/capture', '/fail'] as $path) {
               $reports = [];
               $handled = [];
               SAPI::$testIndexHeader = null;
               fwrite($Client, $Compose($path));
               $Connection->reading($Socket);
               $rows["{$short} {$path}"] = [
                  'status' => $Status($Receive($Client)),
                  'reports' => $reports,
                  'handled' => $handled === [$path],
               ];
            }
         }
         $evidence = json_encode($rows);
         $unavailable = ['status' => 'HTTP/1.1 503 Service Unavailable', 'reports' => [], 'handled' => true];
         $failed = ['status' => 'HTTP/1.1 500 Internal Server Error', 'reports' => [RuntimeException::class], 'handled' => true];

         yield assert(
            assertion: $rows === [
               'Encoder_ /refuse' => $unavailable,
               'Encoder_ /capture' => $unavailable,
               'Encoder_ /fail' => $failed,
               'Encoder_Testing /refuse' => $unavailable,
               'Encoder_Testing /capture' => $unavailable,
               'Encoder_Testing /fail' => $failed,
            ],
            description: "A route's budget refusal — thrown, or a defer() the Inbound share cannot hold — answers 503 with no report through both encoders; another failure answers 500, reported once: {$evidence}",
         );

         // @@ A2) Handled: a listener defers `/handled` with the Inbound
         //     share full, and throws an application error for `/thrown`
         $Emitter = new Emitter;
         $Emitter->listen(RequestEvents::Handled, static function (Emission $Emission) use ($Held, $Arm): void {
            [$Request, $Response] = $Emission->payload;
            if (! $Request instanceof Request || ! $Response instanceof Response) {
               return;
            }

            if ($Request->URI === '/thrown') {
               throw new RuntimeException('U167 listener failure');
            }
            // ! The Catcher legs: the Handled catch's Catcher throws
            if ($Request->URI === '/caught/handled' || $Request->URI === '/crashed/handled') {
               $Arm($Request);

               throw $Request->URI === '/caught/handled'
                  ? new Refusal(Bodies::REFUSAL)
                  : new RuntimeException('U167 listener failure');
            }
            if ($Request->URI === '/handled') {
               $budget = TCP_Server_CLI::$maxWorkerPendingBytes;
               // ! A share that holds nothing more
               TCP_Server_CLI::$maxWorkerPendingBytes = 2 * ($Held() + 1);
               try {
                  $Response->defer(static function (Response $Deferred): void {
                     $Deferred->Body->raw = 'never';
                  });
               }
               finally {
                  TCP_Server_CLI::$maxWorkerPendingBytes = $budget;
               }
            }
         });
         Emitter::$Instance = $Emitter;
         $rows = [];
         try {
            foreach ([Encoder_::class, Encoder_Testing::class] as $class) {
               HTTP_Server_CLI::$Response = new Response;
               HTTP_Server_CLI::$Router = new Router;
               SAPI::$Middlewares = new Middlewares;
               TCP_Server_CLI::$Encoder = new $class;
               [$Connection, $Socket, $Client] = $Open(++$port);
               $short = substr($class, strrpos($class, '\\') + 1);
               foreach (['/handled', '/thrown', '/answered'] as $path) {
                  $reports = [];
                  $handled = [];
                  SAPI::$testIndexHeader = null;
                  fwrite($Client, $Compose($path));
                  $Connection->reading($Socket);
                  $rows["{$short} {$path}"] = [
                     'status' => $Status($Receive($Client)),
                     'reports' => $reports,
                     'handled' => $handled === [$path],
                  ];
               }
            }
         }
         finally {
            Emitter::$Instance = $OldEmitter ?? new Emitter;
         }
         $evidence = json_encode($rows);
         $answered = ['status' => 'HTTP/1.1 200 OK', 'reports' => [], 'handled' => true];

         yield assert(
            assertion: $rows === [
               'Encoder_ /handled' => $unavailable,
               'Encoder_ /thrown' => $failed,
               'Encoder_ /answered' => $answered,
               'Encoder_Testing /handled' => $unavailable,
               'Encoder_Testing /thrown' => $failed,
               'Encoder_Testing /answered' => $answered,
            ],
            description: "A Handled listener's defer() the Inbound share cannot hold answers 503 with no report through both encoders; a listener's other failure answers 500, reported once: {$evidence}",
         );

         // @@ A3) A Catcher that throws while it answers: the routing catch
         //     (`/caught`, `/crashed`) and the Handled catch
         //     (`/caught/handled`, `/crashed/handled`) fall back to a bare
         //     answer that keeps the refusal's status
         Emitter::$Instance = $Emitter;
         $rows = [];
         try {
            foreach ([Encoder_::class, Encoder_Testing::class] as $class) {
               HTTP_Server_CLI::$Response = new Response;
               HTTP_Server_CLI::$Router = new Router;
               SAPI::$Middlewares = new Middlewares;
               TCP_Server_CLI::$Encoder = new $class;
               [$Connection, $Socket, $Client] = $Open(++$port);
               $short = substr($class, strrpos($class, '\\') + 1);
               foreach (['/caught', '/crashed', '/caught/handled', '/crashed/handled'] as $path) {
                  $reports = [];
                  $handled = [];
                  SAPI::$testIndexHeader = null;
                  fwrite($Client, $Compose($path));
                  $Connection->reading($Socket);
                  $rows["{$short} {$path}"] = [
                     'answer' => $Answer($Receive($Client)),
                     'reports' => $reports,
                     'handled' => $handled === [$path],
                     // ! The Catcher did throw: the trap fired
                     'trapped' => U167Header::$Trap === null,
                  ];
                  U167Header::$Trap = null;
                  Catcher::$Environment = null;
               }
            }
         }
         finally {
            Emitter::$Instance = $OldEmitter ?? new Emitter;
         }
         $evidence = json_encode($rows);
         $fallen = ['answer' => ['HTTP/1.1 503 Service Unavailable', true], 'reports' => [], 'handled' => true, 'trapped' => true];
         $crashed = ['answer' => ['HTTP/1.1 500 Internal Server Error', true], 'reports' => [RuntimeException::class], 'handled' => true, 'trapped' => true];

         yield assert(
            assertion: $rows === [
               'Encoder_ /caught' => $fallen,
               'Encoder_ /crashed' => $crashed,
               'Encoder_ /caught/handled' => $fallen,
               'Encoder_ /crashed/handled' => $crashed,
               'Encoder_Testing /caught' => $fallen,
               'Encoder_Testing /crashed' => $crashed,
               'Encoder_Testing /caught/handled' => $fallen,
               'Encoder_Testing /crashed/handled' => $crashed,
            ],
            description: "A Catcher that throws while answering a budget refusal falls back to a bare 503 — at the routing catch and at the Handled catch of both encoders; any other failure falls back to a bare 500, reported once: {$evidence}",
         );

         // @@ B) Deferred work: the job loop answers what the work left
         //    unhandled
         TCP_Server_CLI::$Encoder = new U167Encoder;
         U167Encoder::$Route = static function (Request $Request, TCPPackages $Package) use ($Held, $Price, $Arm): string {
            $path = $Request->URI;
            $Response = new Response;
            $Response->reset($Package, $Request);
            $budget = TCP_Server_CLI::$maxWorkerPendingBytes;
            // ! The parse legs: a share that holds the snapshot, nothing more
            if ($path === '/cookies' || $path === '/languages') {
               TCP_Server_CLI::$maxWorkerPendingBytes = 2 * ($Held() + $Price($Request));
            }
            try {
               $Response->defer(static function (Response $Deferred, Request $Snapshot) use ($path, $Arm): void {
                  // ! The Catcher legs: the job loop's Catcher throws
                  if ($path === '/caught' || $path === '/crashed') {
                     $Arm($Snapshot);

                     throw $path === '/caught'
                        ? new Refusal(Bodies::REFUSAL)
                        : new RuntimeException('U167 deferred failure');
                  }
                  match ($path) {
                     '/refuse' => throw new Refusal(Bodies::REFUSAL),
                     '/cookies' => $Snapshot->cookies,
                     '/languages' => $Snapshot->languages,
                     default => throw new RuntimeException('U167 deferred failure'),
                  };
                  $Deferred->Body->raw = 'parsed';
               });
            }
            finally {
               TCP_Server_CLI::$maxWorkerPendingBytes = $budget;
            }

            return '';
         };
         [$Connection, $Socket, $Client] = $Open(40040);
         $rows = [];
         $legs = [
            '/refuse' => '',
            '/cookies' => "Cookie: a=1; b=2; c=3\r\n",
            '/languages' => "Accept-Language: xx;q=0, yy;q=0, zz;q=0\r\n",
            '/fail' => '',
         ];
         foreach ($legs as $path => $fields) {
            $reports = [];
            fwrite($Client, $Compose($path, $fields));
            $Connection->reading($Socket);
            $rows[$path] = [
               'status' => $Status($Receive($Client)),
               'reports' => $reports,
            ];
         }
         $evidence = json_encode($rows);
         $unavailable = ['status' => 'HTTP/1.1 503 Service Unavailable', 'reports' => []];

         yield assert(
            assertion: $rows === [
               '/refuse' => $unavailable,
               '/cookies' => $unavailable,
               '/languages' => $unavailable,
               '/fail' => ['status' => 'HTTP/1.1 500 Internal Server Error', 'reports' => [RuntimeException::class]],
            ],
            description: "Deferred work that leaves a refusal unhandled — thrown, or refused by a cookie or languages parse on its snapshot — answers 503 with no report; another failure answers 500, reported once: {$evidence}",
         );

         // @@ B2) A Catcher that throws while the job loop answers falls back
         //     to a bare answer that keeps the refusal's status
         $rows = [];
         foreach (['/caught', '/crashed'] as $path) {
            $reports = [];
            fwrite($Client, $Compose($path));
            $Connection->reading($Socket);
            $rows[$path] = [
               'answer' => $Answer($Receive($Client)),
               'reports' => $reports,
               'trapped' => U167Header::$Trap === null,
            ];
            U167Header::$Trap = null;
            Catcher::$Environment = null;
         }
         $evidence = json_encode($rows);

         yield assert(
            assertion: $rows === [
               '/caught' => ['answer' => ['HTTP/1.1 503 Service Unavailable', true], 'reports' => [], 'trapped' => true],
               '/crashed' => ['answer' => ['HTTP/1.1 500 Internal Server Error', true], 'reports' => [RuntimeException::class], 'trapped' => true],
            ],
            description: "A Catcher that throws while the job loop answers a refusal falls back to a bare 503; any other failure falls back to a bare 500, reported once: {$evidence}",
         );

         // @@ C) A refused defer() leaves nothing behind
         [$Connection] = $Open(40050);
         $Response = new Response;
         $Response->reset($Connection, new Request);
         $Refuse = static function () use ($Response): bool {
            try {
               $Response->defer(static function (): void {});
            }
            catch (Refusal) {
               return true;
            }

            return false;
         };
         $budget = TCP_Server_CLI::$maxWorkerPendingBytes;
         // ! A share that holds nothing more
         TCP_Server_CLI::$maxWorkerPendingBytes = 2 * ($Held() + 1);
         try {
            // ! Warm-up: allocator caches and the first-use objects
            for ($index = 0; $index < 200; $index++) {
               $Refuse();
            }
            gc_collect_cycles();
            $before = memory_get_usage();
            $refused = 0;
            // @@
            for ($index = 0; $index < 2_000; $index++) {
               $refused += (int) $Refuse();
            }
            gc_collect_cycles();
            $growth = memory_get_usage() - $before;
         }
         finally {
            TCP_Server_CLI::$maxWorkerPendingBytes = $budget;
         }

         yield assert(
            assertion: $refused === 2_000 && $growth < 2_000 * 64,
            description: "2,000 refused defer() calls keep under 64 B each — the refused clone is freed: {$refused} refused, {$growth} B kept",
         );
      }
      finally {
         U167Encoder::$Route = null;
         U167Header::$Trap = null;
         Emitter::$Instance = $OldEmitter ?? new Emitter;
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
         Throwables::$reporters = $OldReporters;
         Catcher::$Environment = $OldCatcher;
         SAPI::$Environment = $OldEnvironment;
         SAPI::$testIndexHeader = $oldIndex;
         if ($OldHandler !== null) {
            SAPI::$Handler = $OldHandler;
         }
         if ($OldMiddlewares !== null) {
            SAPI::$Middlewares = $OldMiddlewares;
         }
         TCP_Server_CLI::$Decoder = $OldDecoder;
         TCP_Server_CLI::$Encoder = $OldEncoder;
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         TCP_Server_CLI::$maxWorkerPendingBytes = $oldBudget;
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldRequest !== null) {
            HTTP_Server_CLI::$Request = $OldRequest;
         }
         if ($OldResponse !== null) {
            HTTP_Server_CLI::$Response = $OldResponse;
         }
         if ($OldServerRouter !== null) {
            HTTP_Server_CLI::$Router = $OldServerRouter;
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
