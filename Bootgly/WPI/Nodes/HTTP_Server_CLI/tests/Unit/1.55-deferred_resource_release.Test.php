<?php


use const Bootgly\WPI;
use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\KV as KVDatabase;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections as TCPConnections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\KV;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * A deferred job's forked Response references itself (its resource registry's
 * attach hook, its cancellation observers), so once the job ends it is garbage
 * only the cycle collector can reclaim. A KV resource built on it from a
 * definition hands back the commands its handler never awaited only when it is
 * cleaned — and until then each one holds its pooled connection (a KV pool
 * holds one by default). The job loop therefore cleans the fork's own,
 * definition-built resources the moment the job ends: on return, on throw, and
 * after the job came back from the reactor. It keeps them mounted — a stream
 * the job opened (SSE), or anything reached later through the fork, is still
 * the instance the job used. A command whose reply already arrived is finished
 * by that cleanup, not withdrawn, so its connection stays pooled. User-mounted
 * instances are shared with the Response that mounted them and are left
 * alone. A resource whose cleanup throws is reported, and the job loop still
 * finishes its own cleanup.
 *
 * Driven through the real `defer()` and job loop on a pooled Fiber, with the
 * worker reactor installed and the cycle collector disabled. The client
 * stand-in's socket is already closed, so the job serializes nothing — the
 * wire is not what this spec observes. Fake peers only: a loopback listener
 * that is never accepted, so a command never gets its reply — except the one
 * the spec accepts once and answers itself.
 */
if (! class_exists('U155Connection', false)) {
   class U155Connection extends Connection
   {
      /** @param resource $Socket */
      public function __construct (mixed &$Socket)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 15;
         $this->ip = '127.0.0.1';
         $this->port = 12345;
         $this->encrypted = false;
         $this->handshaking = false;
         $this->handshakeTimer = 0;
         $this->status = TCPConnections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 0;
      }
   }
}
if (! class_exists('U155Faulty', false)) {
   /**
    * A definition-built resource whose request cleanup fails.
    */
   class U155Faulty extends Resource
   {
      // * Data
      public private(set) int $cleans = 0;


      public function clean (): void
      {
         $this->cleans++;

         throw new RuntimeException('Release fixture cleanup failed.');
      }
   }
}


return new Test(
   description: 'A deferred job should clean its definition-built resources when it ends and keep them mounted: an unawaited KV command is withdrawn without a GC run, an answered one finishes on its pooled connection, a user-mounted instance is left alone, a failing cleanup never escapes the job loop',
   test: new Assertions(Case: function (): Generator {
      $WPI = WPI;
      $Parked = new ReflectionProperty(Response::class, 'Pool');
      $Transport = new ReflectionProperty(Response::class, 'Package');
      $Contexts = new ReflectionProperty(Language::class, 'Contexts');

      // ! Statics this case writes — directly, or through defer() and the
      //   job loop (the pooled Fibers, their locale bindings, Catcher's
      //   one-shot environment, the throwable reporters)
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $OldRouter = isset($WPI->Router) ? $WPI->Router : null;
      $OldPool = $Parked->getValue();
      $OldContexts = $Contexts->getValue();
      $OldTimeout = Response::$deferredTimeout;
      $OldEnvironment = Catcher::$Environment;
      $OldReporters = Throwables::$reporters;
      $collectable = gc_enabled();

      $Sockets = [];
      $Databases = [];
      $Select = null;

      $withdrawal = 'Database operation was withdrawn: its caller stopped waiting.';

      // ! A dependency peer that never answers: listening, never accepted —
      //   the listener comes back through `$Listener` for the leg that does
      $listen = static function (mixed &$Listener = null) use (&$Sockets): int {
         $Server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
         if ($Server === false) {
            throw new RuntimeException("Release fixture could not listen: {$error}");
         }
         $Sockets[] = $Server;
         $Listener = $Server;

         $address = (string) stream_socket_get_name($Server, false);

         return (int) substr($address, (int) strrpos($address, ':') + 1);
      };
      $redis = static function (int $port) use (&$Databases): KVDatabase {
         $KV = new KVDatabase([
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => $port,
            'database' => '0',
            'timeout' => 5.0,
            'secure' => ['mode' => 'disable'],
            'pool' => ['min' => 0, 'max' => 1],
         ]);
         $Databases[] = $KV;

         return $KV;
      };
      // ! The answering peer: it reads one whole `INCR hits` frame on the
      //   connection it accepted and replies with its running count. Bounded;
      //   a connection the client closed answers nothing.
      $hits = 0;
      $answer = static function (mixed $Peer, float $bound) use (&$hits): bool {
         $frame = "*2\r\n\$4\r\nINCR\r\n\$4\r\nhits\r\n";
         $received = '';
         $deadline = microtime(true) + $bound;

         while (strlen($received) < strlen($frame) && microtime(true) < $deadline) {
            $read = [$Peer];
            $write = null;
            $except = null;

            if ((int) @stream_select($read, $write, $except, 0, 20000) < 1) {
               continue;
            }

            $chunk = fread($Peer, 8192);
            if ($chunk === false || $chunk === '') {
               return false;
            }
            $received .= $chunk;
         }

         if ($received !== $frame) {
            return false;
         }

         $hits++;

         return fwrite($Peer, ":{$hits}\r\n") !== false;
      };
      // ! Bounded: whether a reply waits, unread, on the client's socket
      $readable = static function (mixed $Socket, int $bound): bool {
         if (is_resource($Socket) === false) {
            return false;
         }

         $read = [$Socket];
         $write = null;
         $except = null;

         return @stream_select($read, $write, $except, $bound, 0) === 1;
      };
      // ! The worker's persistent Response for one request: its transport and
      //   a KV resource definition in the shape `KV::provide()` returns — one
      //   worker connection pool, a new instance per Response that reads it
      $respond = static function (KVDatabase $KV) use ($Transport): Response {
         $Socket = fopen('php://memory', 'r');
         if ($Socket === false) {
            throw new RuntimeException('Release fixture could not open the client stand-in.');
         }
         fclose($Socket);

         $Connection = new U155Connection($Socket);
         $Package = new class($Connection) extends TCPPackages {};
         $Response = new Response;
         $Transport->setValue($Response, $Package);

         $Response->Resources->define('KV', static function (object $Context) use ($KV): KV {
            if ($Context instanceof Response === false) {
               throw new RuntimeException('KV response resource expects a Response context.');
            }

            return new KV($KV);
         });

         return $Response;
      };
      // ! Bounded run: short reactor slices, each ended by a deferred stop,
      //   until the leg's outcome is reached or the hard deadline passes. What
      //   escaped the loop is returned.
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
      $census = static fn (Pool $Pool): array => [
         'busy' => count($Pool->busy),
         'idle' => count($Pool->idle),
         'pending' => count($Pool->pending),
      ];
      $idle = ['busy' => 0, 'idle' => 0, 'pending' => 0];
      $withdrawn = static fn (null|Operation $Operation): bool => $Operation !== null
         && $Operation->finished
         && $Operation->error === $withdrawal
         && $Operation->revoked;
      // ! Still mounted under its name — the very instance the job built. A
      //   WeakReference that was never taken proves nothing, and one whose
      //   referent is gone was rebuilt or destroyed.
      $same = static function (null|Resources $Registry, string $name, null|WeakReference $Weak): bool {
         $Resource = $Weak?->get();

         return $Resource !== null && ($Registry?->resources[$name] ?? null) === $Resource;
      };
      $mounted = static fn (null|Resources $Registry): array => $Registry === null
         ? []
         : array_keys($Registry->resources);
      $runs = static fn (): int => gc_status()['runs'];

      try {
         // ! The worker reactor: defer() requires a context-aware scheduler
         $Connections = new class implements Connections {
            public function connect (): bool
            {
               return false;
            }
         };
         $Select = new Select($Connections);
         TCP_Server_CLI::$Event = $Select;
         $WPI->Router = new Router;
         $Parked->setValue(null, []);
         Response::$deferredTimeout = 0;

         // ! No cycle collection from here on: only refcounting can reclaim a
         //   resource, exactly what the fork's self-reference defeats
         gc_disable();

         // @@ A) The handler issues a command and returns without awaiting it
         $KV = $redis($listen());
         $Response = $respond($KV);
         $Weak = null;
         $Command = null;
         $Registry = null;
         $inside = [];
         $before = $runs();

         $Response->defer(static function (Response $Deferred) use ($KV, $census, &$Weak, &$Command, &$Registry, &$inside): void {
            $Registry = $Deferred->Resources;
            $Resource = $Deferred->KV;
            $Weak = WeakReference::create($Resource);
            $Command = $Resource->command('GET', ['key']);
            $inside = [
               'fiber' => Fiber::getCurrent() !== null,
               'busy' => $census($KV->Pool)['busy'],
               'mounted' => array_keys($Deferred->Resources->resources),
            ];
         });
         // ! The worker's Response moves on to the next request: only the
         //   fork's own cycle still reaches what the job built
         unset($Response);

         yield assert(
            assertion: $inside === ['fiber' => true, 'busy' => 1, 'mounted' => ['KV']]
               && $same($Registry, 'KV', $Weak)
               && $withdrawn($Command)
               && $census($KV->Pool) === $idle
               && $mounted($Registry) === ['KV']
               && isset($Registry->definitions['KV'])
               && $runs() === $before,
            description: 'a job that returns with a KV command unawaited ends with the command withdrawn — slot freed, no GC run — while its definition-built resource stays mounted, the same instance — observed: '
               . json_encode(['inside' => $inside, 'same' => $same($Registry, 'KV', $Weak), 'error' => $Command?->error, 'revoked' => $Command?->revoked, 'pool' => $census($KV->Pool), 'mounted' => $mounted($Registry), 'runs' => $runs() - $before])
         );

         // @@ B) The handler throws after issuing the command
         $KV = $redis($listen());
         $Response = $respond($KV);
         $Weak = null;
         $Command = null;
         $Registry = null;
         $inside = [];
         $escaped = null;
         $before = $runs();

         try {
            $Response->defer(static function (Response $Deferred) use ($KV, $census, &$Weak, &$Command, &$Registry, &$inside): void {
               $Registry = $Deferred->Resources;
               $Resource = $Deferred->KV;
               $Weak = WeakReference::create($Resource);
               $Command = $Resource->command('GET', ['key']);
               $inside = [
                  'fiber' => Fiber::getCurrent() !== null,
                  'busy' => $census($KV->Pool)['busy'],
               ];

               throw new RuntimeException('handler failed');
            });
         }
         catch (Throwable $Throwable) {
            $escaped = $Throwable->getMessage();
         }
         unset($Response);

         yield assert(
            assertion: $inside === ['fiber' => true, 'busy' => 1]
               && $escaped === null
               && $same($Registry, 'KV', $Weak)
               && $withdrawn($Command)
               && $census($KV->Pool) === $idle
               && $mounted($Registry) === ['KV']
               && $runs() === $before,
            description: 'a job whose handler throws past an unawaited KV command is answered by the job loop and still ends with the command withdrawn, without a GC run, its resource still mounted — observed: '
               . json_encode(['inside' => $inside, 'escaped' => $escaped, 'same' => $same($Registry, 'KV', $Weak), 'error' => $Command?->error, 'pool' => $census($KV->Pool), 'mounted' => $mounted($Registry), 'runs' => $runs() - $before])
         );

         // @@ C) The job parks on the reactor, comes back and returns: the
         //    command stays in flight while the job lives, and is withdrawn
         //    when the reactor-driven job ends
         $KV = $redis($listen());
         $Response = $respond($KV);
         $Weak = null;
         $Command = null;
         $Registry = null;
         $log = [];
         $before = $runs();

         $Response->defer(static function (Response $Deferred) use (&$Weak, &$Command, &$Registry, &$log): void {
            $Registry = $Deferred->Resources;
            $Resource = $Deferred->KV;
            $Weak = WeakReference::create($Resource);
            $Command = $Resource->command('GET', ['key']);
            unset($Resource);

            // @ A tick park: the reactor resumes this job on its next turn
            $Deferred->wait();
            $log[] = 'resumed';
         });
         unset($Response);
         $parked = [
            'log' => $log,
            'same' => $same($Registry, 'KV', $Weak),
            'finished' => $Command?->finished,
            'busy' => $census($KV->Pool)['busy'],
         ];
         $escaped = $run($Select, static function () use (&$log): bool {
            return $log !== [];
         }, 2.0);

         yield assert(
            assertion: $parked === ['log' => [], 'same' => true, 'finished' => false, 'busy' => 1]
               && $escaped === null
               && $log === ['resumed']
               && $same($Registry, 'KV', $Weak)
               && $withdrawn($Command)
               && $census($KV->Pool) === $idle
               && $mounted($Registry) === ['KV']
               && $runs() === $before,
            description: 'a job parked on the reactor keeps its command in flight while it runs; when the reactor-driven job ends, the command is withdrawn without a GC run and the resource stays mounted — observed: '
               . json_encode(['parked' => $parked, 'escaped' => $escaped, 'log' => $log, 'same' => $same($Registry, 'KV', $Weak), 'error' => $Command?->error, 'pool' => $census($KV->Pool), 'mounted' => $mounted($Registry), 'runs' => $runs() - $before])
         );

         // @@ D) A user-mounted instance is shared with the Response that
         //    mounted it: the job's end cleans only the fork's own instances
         $KV = $redis($listen());
         $SharedKV = $redis($listen());
         $Response = $respond($KV);
         $Shared = $Response->Resources->set('Shared', new KV($SharedKV));
         $Weak = null;
         $Command = null;
         $SharedCommand = null;
         $Registry = null;
         $inside = [];
         $before = $runs();

         $Response->defer(static function (Response $Deferred) use ($KV, $SharedKV, $census, &$Weak, &$Command, &$SharedCommand, &$Registry, &$inside): void {
            $Registry = $Deferred->Resources;
            $SharedCommand = $Deferred->Shared->command('GET', ['shared']);
            $Resource = $Deferred->KV;
            $Weak = WeakReference::create($Resource);
            $Command = $Resource->command('GET', ['own']);
            $inside = [
               'busy' => [$census($KV->Pool)['busy'], $census($SharedKV->Pool)['busy']],
               'mounted' => array_keys($Deferred->Resources->resources),
            ];
         });

         $kept = [
            'fork' => ($Registry?->resources['Shared'] ?? null) === $Shared,
            'source' => ($Response->Resources->resources['Shared'] ?? null) === $Shared,
         ];

         yield assert(
            assertion: $inside === ['busy' => [1, 1], 'mounted' => ['Shared', 'KV']]
               && $mounted($Registry) === ['Shared', 'KV']
               && $kept === ['fork' => true, 'source' => true]
               && $SharedCommand !== null && $SharedCommand->finished === false
               && $census($SharedKV->Pool)['busy'] === 1
               && $same($Registry, 'KV', $Weak)
               && $withdrawn($Command)
               && $census($KV->Pool) === $idle
               && $runs() === $before,
            description: 'the job end leaves a user-mounted instance alone — mounted on the fork, its command in flight — while the definition-built one is cleaned in place and its command withdrawn — observed: '
               . json_encode(['inside' => $inside, 'mounted' => $mounted($Registry), 'kept' => $kept, 'shared' => $SharedCommand?->state->name, 'shared_pool' => $census($SharedKV->Pool), 'same' => $same($Registry, 'KV', $Weak), 'error' => $Command?->error, 'pool' => $census($KV->Pool), 'runs' => $runs() - $before])
         );

         // @ Its owner hands the shared command back
         if ($SharedCommand !== null && $SharedCommand->finished === false) {
            $SharedKV->withdraw($SharedCommand);
         }
         unset($Response, $Shared);

         // @@ E) The handler returns with a command whose reply already
         //    arrived: one non-blocking read at the job's end finishes it
         //    honestly — the connection stays pooled, and the next job's
         //    command rides it instead of dialing again
         $Listener = null;
         $KV = $redis($listen($Listener));
         $Peer = null;
         $Commands = [];
         $inside = [];
         $before = $runs();

         $job = static function (string $tag) use ($KV, $respond, $answer, $readable, &$Listener, &$Peer, &$Sockets, &$Commands, &$inside): void {
            $Response = $respond($KV);

            $Response->defer(static function (Response $Deferred) use ($tag, $KV, $answer, $readable, &$Listener, &$Peer, &$Sockets, &$Commands, &$inside): void {
               $Command = $Deferred->KV->command('INCR', ['hits']);
               $Commands[$tag] = $Command;

               // @ Drive the dial and the write out — non-blocking steps,
               //   bounded — until the whole frame is on the wire
               $deadline = microtime(true) + 2.0;
               while (
                  $Command->state !== OperationStates::Reading
                  && $Command->finished === false
                  && microtime(true) < $deadline
               ) {
                  usleep(1000);
                  $KV->advance($Command);
               }

               // @ The peer accepts ONE connection — its first — and answers
               //   on it
               if ($Peer === null) {
                  $Accepted = @stream_socket_accept($Listener, 1.0);
                  if ($Accepted !== false) {
                     $Sockets[] = $Accepted;
                     $Peer = $Accepted;
                  }
               }
               $answered = $Peer !== null && $answer($Peer, 1.0);

               // ! Nothing reads the reply before the job returns: it waits,
               //   whole, in the client socket's buffer
               $inside[$tag] = [
                  'state' => $Command->state->name,
                  'answered' => $answered,
                  'buffered' => $readable($Command->Connection?->socket, 1),
               ];
            });
         };
         $job('first');
         $First = $Commands['first'] ?? null;
         $settled = [
            'state' => $First?->state->name,
            'error' => $First?->error,
            'revoked' => $First?->revoked,
            'response' => $First?->response,
         ];

         yield assert(
            assertion: ($inside['first'] ?? null) === ['state' => 'Reading', 'answered' => true, 'buffered' => true]
               && $settled === ['state' => 'Finished', 'error' => null, 'revoked' => false, 'response' => 1]
               && $census($KV->Pool) === ['busy' => 0, 'idle' => 1, 'pending' => 0]
               && $runs() === $before,
            description: 'a job that returns with a KV command whose reply is already buffered ends with the command finished honestly — its reply read, not withdrawn — and its connection back in the pool, idle — observed: '
               . json_encode(['inside' => $inside['first'] ?? null, 'settled' => $settled, 'pool' => $census($KV->Pool), 'runs' => $runs() - $before])
         );

         $job('second');
         $Second = $Commands['second'] ?? null;
         // ! The command read, so its connection was established: a second
         //   dial would already wait in the listener's backlog
         $Redial = @stream_socket_accept($Listener, 0.05);
         if ($Redial !== false) {
            $Sockets[] = $Redial;
         }
         $settled = [
            'state' => $Second?->state->name,
            'error' => $Second?->error,
            'revoked' => $Second?->revoked,
            'response' => $Second?->response,
         ];

         yield assert(
            assertion: ($inside['second'] ?? null) === ['state' => 'Reading', 'answered' => true, 'buffered' => true]
               && $Redial === false
               && $settled === ['state' => 'Finished', 'error' => null, 'revoked' => false, 'response' => 2]
               && $census($KV->Pool) === ['busy' => 0, 'idle' => 1, 'pending' => 0]
               && $KV->Pool->created === 1
               && $runs() === $before,
            description: 'the next job\'s command rides the pooled connection — answered there by the peer, which accepts once — with no second dial — observed: '
               . json_encode(['inside' => $inside['second'] ?? null, 'redialed' => $Redial !== false, 'settled' => $settled, 'pool' => $census($KV->Pool), 'created' => $KV->Pool->created, 'runs' => $runs() - $before])
         );
         unset($job, $Commands, $First, $Second);

         // @@ F) What the job built stays reachable through its response after
         //    it ends: the SSE built-in and a configured resource are the
         //    instances the job used, not fresh rebuilds
         $KV = $redis($listen());
         $Response = $respond($KV);
         $Fork = null;
         $Built = [];
         $before = $runs();

         $Response->defer(static function (Response $Deferred) use (&$Fork, &$Built): void {
            $Fork = $Deferred;
            $Built = [
               'SSE' => WeakReference::create($Deferred->SSE),
               'KV' => WeakReference::create($Deferred->KV),
            ];
         });
         unset($Response);

         // ! What stays mounted is read first: reaching a name through the
         //   response rebuilds it when it is gone
         $left = $mounted($Fork?->Resources);
         $reached = [];
         foreach ($Built as $name => $Weak) {
            $Instance = $Weak->get();
            $reached[$name] = $Instance !== null && $Fork?->{$name} === $Instance;
         }
         unset($Instance);

         yield assert(
            assertion: $left === ['SSE', 'KV']
               && $reached === ['SSE' => true, 'KV' => true]
               && $runs() === $before,
            description: 'after the job ends, the SSE built-in and a configured resource reached through its response are the instances the job used, still mounted — observed: '
               . json_encode(['mounted' => $left, 'reached' => $reached, 'runs' => $runs() - $before])
         );
         unset($Fork, $Built);

         // @@ G) A definition-built resource whose cleanup throws: the job
         //    loop reports it and still finishes its own cleanup — the Fiber
         //    goes back to the pool with its locale binding dropped, and
         //    nothing escapes
         $Response = $respond($KV);
         $Response->Resources->define('Faulty', static function (object $Context): U155Faulty {
            return new U155Faulty;
         });
         $Faulty = null;
         $Worker = null;
         $bound = null;
         $escaped = null;
         $reports = [];
         Throwables::$reporters = [
            static function (Throwable $Throwable, array $context) use (&$reports): void {
               $reports[] = [$context['phase'] ?? null, $Throwable->getMessage()];
            },
         ];

         try {
            $Response->defer(static function (Response $Deferred) use ($Contexts, &$Faulty, &$Worker, &$bound): void {
               $Faulty = $Deferred->Faulty;
               $Worker = Fiber::getCurrent();
               $Bindings = $Contexts->getValue();
               $bound = $Worker !== null && $Bindings instanceof WeakMap && isset($Bindings[$Worker]);
            });
         }
         catch (Throwable $Throwable) {
            $escaped = $Throwable->getMessage();
         }
         finally {
            Throwables::$reporters = $OldReporters;
         }
         unset($Response);

         $Bindings = $Contexts->getValue();
         $ended = [
            'escaped' => $escaped,
            'cleans' => $Faulty?->cleans,
            'reports' => $reports,
            'bound' => [$bound, $Worker !== null && $Bindings instanceof WeakMap && isset($Bindings[$Worker])],
            'pooled' => $Worker !== null && in_array($Worker, $Parked->getValue(), true),
         ];
         unset($Bindings);

         yield assert(
            assertion: $ended === [
               'escaped' => null,
               'cleans' => 1,
               'reports' => [['Resources', 'Release fixture cleanup failed.']],
               'bound' => [true, false],
               'pooled' => true,
            ],
            description: 'a definition-built resource whose cleanup throws is reported under the Resources phase, and the job loop still ends its job: nothing escapes defer(), the locale binding is dropped, the Fiber is pooled again — observed: '
               . json_encode($ended)
         );
         unset($Worker, $Faulty);
      }
      finally {
         if ($collectable) {
            gc_enable();
         }

         foreach ($Databases as $Database) {
            foreach ([...$Database->Pool->idle, ...$Database->Pool->busy] as $Dial) {
               try {
                  $Dial->disconnect();
               }
               catch (Throwable) {
                  // Teardown only.
               }
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
         Response::$deferredTimeout = $OldTimeout;
         Catcher::$Environment = $OldEnvironment;
         Throwables::$reporters = $OldReporters;
         if ($OldRouter !== null) {
            $WPI->Router = $OldRouter;
         }
         else {
            unset($WPI->Router);
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }

         // ! The forks this case left behind are cycles: reclaim them here,
         //   not in a later case
         gc_collect_cycles();
      }
   })
);
