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
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\KV as KVDatabase;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\HTTP;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\KV;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * A completed deferred generation is freed by refcounting, the moment its
 * job lets go of it — never left to the cycle collector. Its forked Response
 * used to reach itself (its clone source, its resource registry's context
 * and attach hook, the resources bound to it and their wait bridges), so a
 * finished job was garbage only a collector run could free, and that
 * garbage carried destructors (the Exchange, the snapshot's ledger token)
 * which make the run take a second pass.
 *
 * Driven through the real `defer()` and job loop on a pooled Fiber, with the
 * worker reactor installed, over a real connection on a socket pair, and
 * the cycle collector disabled. Every built-in resource that binds the
 * response and every configured one with a wait bridge (Database, KV, HTTP,
 * a user resource) is mounted by the work. Pinned:
 *
 * - the control: the collector counter is live — a collection over a garbage
 *   cycle is counted — so every leg below that shows it unchanged saw no
 *   collection, not even one a framework path runs on its own (`Timer::del()`
 *   runs one), and what that leg saw go was freed by refcounting;
 * - normal completion inline, and after the job came back from the reactor:
 *   once the worker's Response moves on to the next request, the forked
 *   Response and its Header (a copy builds its Cookies only when used), its
 *   Exchange, its Cancellation, its captured Request and that snapshot's
 *   ledger token are gone, with no collector run, and the job's answer is on
 *   the wire;
 * - a nested handoff: both generations — parent and child — are gone the
 *   same way, the child's answer on the wire; when the child parks, the
 *   parent is gone while the child is still parked, the child once it
 *   completed;
 * - normal completion on an HTTP/2 stream, through the real decoder and
 *   encoder: the same parts go the moment the job returns, everything once
 *   the worker's Response moves on to the next stream, and the answer is on
 *   that stream;
 * - a generation cancelled while parked (the client left), a generation
 *   cancelled from inside its work, and a generation whose request built a
 *   Session keep today's timing: their Response stays until a collector
 *   run, then goes. The Session destructor is the collector-time safety net
 *   of an abandoned write (BG-15); the abandoned clone must not land it
 *   early.
 */
if (! class_exists('U169Waiter', false)) {
   /**
    * A user resource that parks through the response wait bridge.
    */
   class U169Waiter extends Resource implements Scheduling
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
         $Wait = $this->Wait ?? throw new RuntimeException('U169 waiter is not bound.');

         return $Wait($value);
      }
   }
}
if (! class_exists('U169SQL', false)) {
   /**
    * A database whose every query resolves inline.
    */
   final class U169SQL extends SQL
   {
      public function __construct ()
      {
         parent::__construct(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]]);
      }

      /**
       * @param string|Builder|Query $query
       * @param array<int|string,mixed> $parameters
       */
      public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
      {
         $Normalized = new Normalized($query, $parameters);
         $Operation = new Operation(null, $Normalized->SQL, $Normalized->parameters);
         $Operation->resolve(new Result(rows: [['answer' => 1]], columns: ['answer']));

         return $Operation;
      }
   }
}


return new Test(
   description: 'A completed deferred generation should be freed by refcounting, without a collector run, while a cancelled or Session-bearing one keeps its collector-time lifetime',
   test: new Assertions(Case: function (): Generator {
      $WPI = WPI;
      $Parked = new ReflectionProperty(Response::class, 'Pool');
      $Contexts = new ReflectionProperty(Language::class, 'Contexts');
      $Token = new ReflectionProperty(Request::class, 'Bodies');

      // ! Statics this case writes — directly, or through defer() and the
      //   job loop
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $OldRouter = isset($WPI->Router) ? $WPI->Router : null;
      $OldAmbient = isset($WPI->Request) ? $WPI->Request : null;
      $OldAnswer = isset($WPI->Response) ? $WPI->Response : null;
      $OldPool = $Parked->getValue();
      $OldContexts = $Contexts->getValue();
      $oldTimeout = Response::$deferredTimeout;
      $collectable = gc_enabled();
      // # The server statics the HTTP/2 leg drives the real encoder with
      $OldDecoder = TCP_Server_CLI::$Decoder;
      $OldEncoder = TCP_Server_CLI::$Encoder;
      $oldHTTP2 = HTTP_Server_CLI::$enableHTTP2;
      $OldRequest = isset(HTTP_Server_CLI::$Request) ? HTTP_Server_CLI::$Request : null;
      $OldResponse = isset(HTTP_Server_CLI::$Response) ? HTTP_Server_CLI::$Response : null;
      $OldServerRouter = isset(HTTP_Server_CLI::$Router) ? HTTP_Server_CLI::$Router : null;
      $OldHandler = isset(SAPI::$Handler) ? SAPI::$Handler : null;
      $OldMiddlewares = isset(SAPI::$Middlewares) ? SAPI::$Middlewares : null;
      $OldEnvironment = SAPI::$Environment;
      $oldIndex = SAPI::$testIndexHeader;
      $OldCatcher = Catcher::$Environment;
      $OldReporters = Throwables::$reporters;
      $OldEmitter = isset(Emitter::$Instance) ? Emitter::$Instance : null;

      $Sockets = [];
      $Select = null;

      // ! A decoded per-connection Request, its head in strings of its own
      $Decode = static function (string $path): Request {
         $Connection = new ReflectionClass(Connection::class)->newInstanceWithoutConstructor();
         $Connection->ip = '127.0.0.1';
         $Connection->port = 40169;
         $Connection->encrypted = false;
         $Connection->writes = 1;
         $Package = new class extends TCPPackages {
            // ! decode() only reads the connection truth and the reject slot
            public function __construct () {}

            public function reject (string $raw): void
            {
               throw new RuntimeException("A 1.69 head was rejected: {$raw}");
            }
         };
         $Package->Connection = $Connection;
         $Request = new Request;
         $Request->reset();
         $buffer = "GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $Request->decode($Package, $buffer, strlen($buffer));

         return $Request;
      };
      // ! A server end the response is written to and a client end that
      //   never blocks
      $Open = static function () use (&$Sockets): array {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('U169 could not open a socket pair.');
         }
         [$Server, $Client] = $Pair;
         stream_set_blocking($Server, false);
         stream_set_blocking($Client, false);
         $Sockets[] = $Server;
         $Sockets[] = $Client;

         return [new Connection($Server, '127.0.0.1', 40169), $Client];
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
      //   until `$done` holds or the hard deadline passes
      $run = static function (Select $Select, Closure $done, float $bound): null|string {
         $deadline = microtime(true) + $bound;

         do {
            $Select->defer(microtime(true) + 0.010, static function () use ($Select): void {
               $Select->loop = false; // @phpstan-ignore-line (property on the Select impl)
            });
            $Select->loop = true; // @phpstan-ignore-line (property on the Select impl)

            try {
               $Select->loop();
            }
            catch (Throwable $Throwable) {
               return "{$Throwable->getMessage()}";
            }
         } while ($done() === false && microtime(true) < $deadline);

         return null;
      };
      // ! The worker's persistent Response for one live request, with the
      //   configured resources a project registers
      $configure = static function (Response $Response): Response {
         $Response->Resources->load([
            'Database' => static fn (object $Context): Database => new Database(new U169SQL),
            'KV' => static fn (object $Context): KV => new KV(new KVDatabase([
               'driver' => 'redis',
               'host' => '127.0.0.1',
               'port' => 1,
               'database' => '0',
               'timeout' => 1.0,
               'secure' => ['mode' => 'disable'],
               'pool' => ['min' => 0, 'max' => 1],
            ])),
            'Upstream' => static fn (object $Context): HTTP => new HTTP(host: '127.0.0.1', port: 1, connectTimeout: 1, timeout: 1),
            'Waiter' => static fn (object $Context): U169Waiter => new U169Waiter,
         ]);

         return $Response;
      };
      $respond = static function (bool $sessioned = false) use ($Open, $Decode, $configure): array {
         [$Connection, $Client] = $Open();
         $Request = $Decode('/u169');

         if ($sessioned) {
            // ! A Session this request built — no handler, nothing to persist
            $Session = new ReflectionClass(Session::class)->newInstanceWithoutConstructor();
            new ReflectionProperty(Request::class, 'Session')->setRawValue($Request, $Session);
            new ReflectionProperty(Request::class, 'sessioned')->setValue($Request, true);
         }

         $Response = $configure(new Response);
         $Response->reset($Connection, $Request);

         return [$Response, $Connection, $Client];
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
      // ! The DATA a stream carried
      $Data = static function (array $frames, int $stream): string {
         $data = '';
         foreach ($frames[$stream] ?? [] as [$type, $payload]) {
            if ($type === HTTP2::FRAME_DATA) {
               $data .= $payload;
            }
         }

         return $data;
      };
      // ! Every resource that binds its response or carries a wait bridge
      $names = ['JSON', 'JSONP', 'Negotiation', 'Plaintext', 'Pre', 'SSE', 'View', 'XML', 'Database', 'KV', 'Upstream', 'Waiter'];
      // ! What one generation is made of, held weakly
      $record = static function (Response $Deferred) use ($Token): array {
         $Fiber = Fiber::getCurrent();
         $Exchange = Exchange::fetch($Deferred);
         $Generation = $Fiber === null ? null : Cancellation::fetch($Fiber);
         $Snapshot = $Deferred->Request;
         $Bodies = $Snapshot === null ? null : $Token->getValue($Snapshot);

         return [
            'Response' => WeakReference::create($Deferred),
            'Header' => WeakReference::create($Deferred->Header),
            'Exchange' => $Exchange === null ? null : WeakReference::create($Exchange),
            'Cancellation' => $Generation === null ? null : WeakReference::create($Generation),
            'Request' => $Snapshot === null ? null : WeakReference::create($Snapshot),
            'Bodies' => $Bodies === null ? null : WeakReference::create($Bodies),
         ];
      };
      // ! Which recorded parts are still alive — a part never recorded
      //   reads as alive, so a vacuous record can never pass
      $alive = static function (array $Weak): array {
         $alive = [];
         foreach (['Response', 'Header', 'Exchange', 'Cancellation', 'Request', 'Bodies'] as $part) {
            $Reference = $Weak[$part] ?? null;
            $alive[$part] = $Reference === null || $Reference->get() !== null;
         }

         return $alive;
      };
      $gone = ['Response' => false, 'Header' => false, 'Exchange' => false, 'Cancellation' => false, 'Request' => false, 'Bodies' => false];
      $runs = static fn (): int => gc_status()['runs'];

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
         $WPI->Router = new Router;
         // ! The ambient context a deferred segment binds over and restores
         $WPI->Request = $Decode('/ambient');
         $WPI->Response = new Response;
         $Parked->setValue(null, []);
         Response::$deferredTimeout = 0;

         // ! No cycle collection from here on: only refcounting can free
         //   what the legs below observe
         gc_disable();

         // @ The control behind every count below: a collection with garbage
         //   to scan is counted, so a leg whose count did not move saw none
         //   — not even one a framework path runs on its own (Timer::del()
         //   does) — and what it saw go was freed by refcounting
         $Cycle = new stdClass;
         $Cycle->self = $Cycle;
         $Control = WeakReference::create($Cycle);
         unset($Cycle);
         $before = $runs();
         gc_collect_cycles();
         $counted = ['counted' => $runs() > $before, 'collected' => $Control->get() === null];

         yield assert(
            assertion: $counted === ['counted' => true, 'collected' => true],
            description: 'the collector counter is live: a collection over a garbage cycle is counted — the control that makes every unchanged count below mean no collection ran — observed: '
               . json_encode($counted)
         );
         unset($Control);

         // @@ A) Normal completion, inline — every resource mounted
         [$Response, $Connection, $Client] = $respond();
         $Weak = [];
         $mounted = [];
         $before = $runs();

         $Response->defer(static function (Response $Deferred) use ($names, $record, &$Weak, &$mounted): void {
            foreach ($names as $name) {
               $mounted[] = $Deferred->{$name}::class;
            }
            $Deferred->Database->fetch('SELECT 1 AS answer');
            $Weak = $record($Deferred);

            $Deferred->JSON->send(['leg' => 'A']);
         });
         // ! The clone goes the moment its job lets go of it: only the
         //   worker's Response still holds the Exchange and the Cancellation,
         //   until it moves on to the next request
         $early = $alive($Weak);
         $Response->reset($Connection, $Decode('/next'));
         $wire = $Receive($Client);
         $left = $alive($Weak);

         yield assert(
            assertion: count($mounted) === count($names)
               && str_contains($wire, 'HTTP/1.1 200 OK')
               && str_contains($wire, '{"leg":"A"}')
               && [$early['Response'], $early['Header'], $early['Request'], $early['Bodies']] === [false, false, false, false]
               && $left === $gone
               && $runs() === $before,
            description: 'a generation completed inline, with every binding resource mounted, is freed by refcounting — the Response and its Header, its snapshot and the ledger token the moment the job returns, the Exchange and the Cancellation once the worker\'s Response moves on, with the collector count unchanged (see the control) — and its answer is on the wire — observed: '
               . json_encode(['mounted' => count($mounted), 'answered' => str_contains($wire, '{"leg":"A"}'), 'early' => $early, 'alive' => $left, 'runs' => $runs() - $before])
         );
         unset($Response, $Connection, $Client, $Weak);

         // @@ B) Normal completion after the job came back from the reactor
         [$Response, $Connection, $Client] = $respond();
         $Weak = [];
         $log = [];
         $before = $runs();

         $Response->defer(static function (Response $Deferred) use ($names, $record, &$Weak, &$log): void {
            foreach ($names as $name) {
               $Deferred->{$name};
            }
            $Weak = $record($Deferred);

            // @ A tick park: the reactor resumes this job on its next turn
            $Deferred->wait();
            $log[] = 'resumed';

            $Deferred->JSON->send(['leg' => 'B']);
         });
         $parked = $log;
         $escaped = $run($Select, static function () use (&$log): bool {
            return $log !== [];
         }, 2.0);
         // @ The reactor flushes what the release scheduled
         $run($Select, static fn (): bool => true, 0.0);
         $Response->reset($Connection, $Decode('/next'));
         $wire = $Receive($Client);
         $left = $alive($Weak);

         yield assert(
            assertion: $parked === []
               && $escaped === null
               && $log === ['resumed']
               && str_contains($wire, '{"leg":"B"}')
               && $left === $gone
               && $runs() === $before,
            description: 'a generation completed after the reactor resumed it is freed by refcounting the same way, the collector count unchanged — observed: '
               . json_encode(['parked' => $parked, 'escaped' => $escaped, 'log' => $log, 'answered' => str_contains($wire, '{"leg":"B"}'), 'alive' => $left, 'runs' => $runs() - $before])
         );
         unset($Response, $Connection, $Client, $Weak);

         // @@ C) A nested handoff: the parent hands the wire to its child
         [$Response, $Connection, $Client] = $respond();
         $Parent = [];
         $Child = [];
         $before = $runs();

         $Response->defer(static function (Response $Deferred) use ($names, $record, &$Parent, &$Child): void {
            foreach ($names as $name) {
               $Deferred->{$name};
            }
            $Parent = $record($Deferred);

            $Deferred->defer(static function (Response $Nested) use ($names, $record, &$Child): void {
               foreach ($names as $name) {
                  $Nested->{$name};
               }
               $Child = $record($Nested);

               $Nested->JSON->send(['leg' => 'C']);
            });
         });
         $Response->reset($Connection, $Decode('/next'));
         $wire = $Receive($Client);
         $left = ['parent' => $alive($Parent), 'child' => $alive($Child)];

         yield assert(
            assertion: str_contains($wire, '{"leg":"C"}')
               && substr_count($wire, 'HTTP/1.1 200 OK') === 1
               && $left === ['parent' => $gone, 'child' => $gone]
               && $runs() === $before,
            description: 'a nested handoff frees both generations — parent and child — by refcounting, the collector count unchanged, with one answer on the wire — observed: '
               . json_encode(['answers' => substr_count($wire, 'HTTP/1.1 200 OK'), 'alive' => $left, 'runs' => $runs() - $before])
         );
         unset($Response, $Connection, $Client, $Parent, $Child);

         // @@ G) A nested handoff whose child parks
         [$Response, $Connection, $Client] = $respond();
         $Parent = [];
         $Child = [];
         $log = [];
         $before = $runs();

         $Response->defer(static function (Response $Deferred) use ($names, $record, &$Parent, &$Child, &$log): void {
            foreach ($names as $name) {
               $Deferred->{$name};
            }
            $Parent = $record($Deferred);

            $Deferred->defer(static function (Response $Nested) use ($names, $record, &$Child, &$log): void {
               foreach ($names as $name) {
                  $Nested->{$name};
               }
               $Child = $record($Nested);

               // @ A tick park: the reactor resumes the child on its next turn
               $Nested->wait();
               $log[] = 'resumed';

               $Nested->JSON->send(['leg' => 'G']);
            });
         });
         // ! The parent's job returned, its child still parked
         $parked = [
            'log' => $log,
            'parent' => array_intersect_key($alive($Parent), ['Response' => true, 'Header' => true]),
            'child' => $alive($Child)['Response'],
         ];
         $escaped = $run($Select, static function () use (&$log): bool {
            return $log !== [];
         }, 2.0);
         // @ The reactor flushes what the release scheduled
         $run($Select, static fn (): bool => true, 0.0);
         $Response->reset($Connection, $Decode('/next'));
         $wire = $Receive($Client);
         $left = ['parent' => $alive($Parent), 'child' => $alive($Child)];

         yield assert(
            assertion: $parked === ['log' => [], 'parent' => ['Response' => false, 'Header' => false], 'child' => true]
               && $escaped === null
               && $log === ['resumed']
               && str_contains($wire, '{"leg":"G"}')
               && substr_count($wire, 'HTTP/1.1 200 OK') === 1
               && $left === ['parent' => $gone, 'child' => $gone]
               && $runs() === $before,
            description: 'a nested handoff whose child parks frees the parent generation by refcounting while the child is still parked, and the child once it completed, the collector count unchanged, with one answer on the wire — observed: '
               . json_encode(['parked' => $parked, 'escaped' => $escaped, 'log' => $log, 'answers' => substr_count($wire, 'HTTP/1.1 200 OK'), 'alive' => $left, 'runs' => $runs() - $before])
         );
         unset($Response, $Connection, $Client, $Parent, $Child);

         // @@ D) Cancelled while parked: the client left
         [$Response, $Connection, $Client] = $respond();
         $Weak = null;
         $Generation = null;
         $log = [];

         $Response->defer(static function (Response $Deferred) use ($names, &$Weak, &$Generation, &$log): void {
            foreach ($names as $name) {
               $Deferred->{$name};
            }
            $Weak = WeakReference::create($Deferred);
            $Fiber = Fiber::getCurrent();
            $Generation = $Fiber === null ? null : Cancellation::fetch($Fiber);
            unset($Fiber);

            try {
               // @@ Parked until the generation is torn down
               while (true) {
                  $Deferred->wait();
               }
            }
            finally {
               $log[] = 'unwound';
            }
         });
         $Generation?->cancel();
         $escaped = $run($Select, static function () use (&$log): bool {
            return $log !== [];
         }, 2.0);
         $Response->reset($Connection, $Decode('/next'));
         $kept = [
            'cancelled' => $Generation?->cancelled,
            'log' => $log,
            'escaped' => $escaped,
            'alive' => $Weak?->get() !== null,
         ];
         gc_collect_cycles();
         $kept['collected'] = $Weak?->get() === null;

         yield assert(
            assertion: $kept === [
               'cancelled' => true,
               'log' => ['unwound'],
               'escaped' => null,
               'alive' => true,
               'collected' => true,
            ],
            description: 'a generation cancelled while parked keeps its Response until a collector run, as before — observed: '
               . json_encode($kept)
         );
         unset($Response, $Connection, $Client, $Weak, $Generation);

         // @@ E) Cancelled from inside its own work
         [$Response, $Connection, $Client] = $respond();
         $Weak = null;
         $cancelled = null;

         $Response->defer(static function (Response $Deferred) use ($names, &$Weak, &$cancelled): void {
            foreach ($names as $name) {
               $Deferred->{$name};
            }
            $Weak = WeakReference::create($Deferred);
            $Fiber = Fiber::getCurrent();
            $Generation = $Fiber === null ? null : Cancellation::fetch($Fiber);
            unset($Fiber);

            $Generation?->cancel();
            $cancelled = $Generation?->cancelled;
         });
         $Response->reset($Connection, $Decode('/next'));
         $wire = $Receive($Client);
         $kept = [
            'cancelled' => $cancelled,
            'wire' => $wire,
            'alive' => $Weak?->get() !== null,
         ];
         gc_collect_cycles();
         $kept['collected'] = $Weak?->get() === null;

         yield assert(
            assertion: $kept === [
               'cancelled' => true,
               'wire' => '',
               'alive' => true,
               'collected' => true,
            ],
            description: 'a generation cancelled from inside its work answers nothing and keeps its Response until a collector run, as before — observed: '
               . json_encode($kept)
         );
         unset($Response, $Connection, $Client, $Weak);

         // @@ F) Completed, by a request that built a Session
         [$Response, $Connection, $Client] = $respond(sessioned: true);
         $Weak = null;
         $sessioned = null;

         $Response->defer(static function (Response $Deferred) use ($names, &$Weak, &$sessioned): void {
            foreach ($names as $name) {
               $Deferred->{$name};
            }
            $Weak = WeakReference::create($Deferred);
            $sessioned = $Deferred->Request?->sessioned;

            $Deferred->JSON->send(['leg' => 'F']);
         });
         $Response->reset($Connection, $Decode('/next'));
         $wire = $Receive($Client);
         $kept = [
            'sessioned' => $sessioned,
            'answered' => str_contains($wire, '{"leg":"F"}'),
            'alive' => $Weak?->get() !== null,
         ];
         gc_collect_cycles();
         $kept['collected'] = $Weak?->get() === null;

         yield assert(
            assertion: $kept === [
               'sessioned' => true,
               'answered' => true,
               'alive' => true,
               'collected' => true,
            ],
            description: 'a completed generation whose request built a Session keeps its Response until a collector run, as before — observed: '
               . json_encode($kept)
         );
         unset($Response, $Connection, $Client, $Weak);

         // @@ H) Normal completion on an HTTP/2 stream — the real decoder and
         //   encoder, the worker's Response moving on with the next stream
         [$Connection, $Client] = $Open();
         TCP_Server_CLI::$Decoder = new Decoder_;
         TCP_Server_CLI::$Encoder = new Encoder_;
         HTTP_Server_CLI::$enableHTTP2 = true;
         HTTP_Server_CLI::$Request = new Request;
         HTTP_Server_CLI::$Response = $configure(new Response);
         HTTP_Server_CLI::$Router = new Router;
         SAPI::$Middlewares = new Middlewares;
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
         $Weak = [];
         SAPI::$Handler = static function (Request $Request, Response $Response) use ($names, $record, &$Weak): Response {
            if ($Request->URI === '/h2/next') {
               return $Response(body: 'next');
            }

            return $Response->defer(static function (Response $Deferred) use ($names, $record, &$Weak): void {
               foreach ($names as $name) {
                  $Deferred->{$name};
               }
               $Weak = $record($Deferred);

               $Deferred->JSON->send(['leg' => 'H']);
            });
         };
         $Stream = static fn (int $stream, string $path): string => Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            $stream,
            HPACK::encode([
               [':method', 'GET'],
               [':scheme', 'http'],
               [':path', $path],
               [':authority', 'localhost'],
            ])
         );
         $before = $runs();

         fwrite($Client, HTTP2::PREFACE . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, '') . $Stream(1, '/h2/defer'));
         $Connection->reading($Connection->Socket);
         $first = $Frames($Receive($Client));
         // ! The clone goes the moment its job lets go of it, as on HTTP/1.1
         $early = $alive($Weak);
         fwrite($Client, $Stream(3, '/h2/next'));
         $Connection->reading($Connection->Socket);
         $second = $Frames($Receive($Client));
         $left = $alive($Weak);

         yield assert(
            assertion: str_contains($Data($first, 1), '{"leg":"H"}')
               && $Data($second, 3) === 'next'
               && [$early['Response'], $early['Header'], $early['Request'], $early['Bodies']] === [false, false, false, false]
               && $left === $gone
               && $reports === []
               && $runs() === $before,
            description: 'a generation completed on an HTTP/2 stream is freed by refcounting the same way — the Response and its Header, its snapshot and the ledger token the moment the job returns, everything once the worker\'s Response moves on to the next stream, the collector count unchanged — and its answer is on that stream — observed: '
               . json_encode(['answered' => $Data($first, 1), 'next' => $Data($second, 3), 'early' => $early, 'alive' => $left, 'reports' => $reports, 'runs' => $runs() - $before])
         );
         unset($Connection, $Client, $Weak);
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

         $Parked->setValue(null, $OldPool);
         $Contexts->setValue(null, $OldContexts);
         Response::$deferredTimeout = $oldTimeout;
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         foreach (['Router' => $OldRouter, 'Request' => $OldAmbient, 'Response' => $OldAnswer] as $slot => $Previous) {
            if ($Previous !== null) {
               $WPI->{$slot} = $Previous;
            }
            else {
               unset($WPI->{$slot});
            }
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
         // # The HTTP/2 leg's server statics
         TCP_Server_CLI::$Decoder = $OldDecoder;
         TCP_Server_CLI::$Encoder = $OldEncoder;
         HTTP_Server_CLI::$enableHTTP2 = $oldHTTP2;
         if ($OldRequest !== null) {
            HTTP_Server_CLI::$Request = $OldRequest;
         }
         if ($OldResponse !== null) {
            HTTP_Server_CLI::$Response = $OldResponse;
         }
         if ($OldServerRouter !== null) {
            HTTP_Server_CLI::$Router = $OldServerRouter;
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

         // ! What the legs left behind is reclaimed here, not in a later case
         gc_collect_cycles();
      }
   })
);
