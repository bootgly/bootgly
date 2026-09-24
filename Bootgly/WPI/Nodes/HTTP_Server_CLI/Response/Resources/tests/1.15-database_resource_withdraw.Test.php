<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\DatabaseWithdraw;


use function assert;
use function count;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function gc_disable;
use function gc_enable;
use function gc_enabled;
use function is_resource;
use function json_encode;
use function microtime;
use function pack;
use function rtrim;
use function str_starts_with;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use function unpack;
use function usleep;
use Closure;
use Fiber;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use WeakReference;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection\ConnectionStates;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;


/**
 * In-process PostgreSQL peer on loopback. `silent` accepts and reads but never
 * answers, so a dialed connection stays in Startup; `answer` completes the
 * startup and every simple query. It acts only inside pump(), so the spec
 * decides exactly when the peer has spoken.
 */
final class Peer
{
   // * Config
   public string $mode;

   // * Data
   /** @var resource */
   public mixed $server;
   public int $port;
   /** @var array<int,resource> */
   public array $clients = [];
   /** @var array<int,string> */
   public array $buffers = [];
   /** @var array<int,bool> */
   public array $started = [];
   /** @var array<int,string> Simple-query texts received, in wire order */
   public array $queries = [];


   public function __construct (string $mode)
   {
      $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);

      if ($server === false) {
         throw new RuntimeException("Unable to open the PostgreSQL peer: {$message}");
      }

      stream_set_blocking($server, false);
      $name = (string) stream_socket_get_name($server, false);

      // * Config
      $this->mode = $mode;

      // * Data
      $this->server = $server;
      $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
   }

   /**
    * Accept every completed dial, then read and answer what arrived.
    */
   public function pump (): void
   {
      // @@
      while (($client = @stream_socket_accept($this->server, 0)) !== false) {
         stream_set_blocking($client, false);

         $this->clients[] = $client;
         $this->buffers[] = '';
         $this->started[] = false;
      }

      // @@
      foreach ($this->clients as $id => $client) {
         if (is_resource($client) === false) {
            continue;
         }

         $data = @fread($client, 65536);

         if ($data === false || $data === '') {
            if (feof($client)) {
               fclose($client);
            }

            continue;
         }

         $this->buffers[$id] .= $data;
         $this->parse($id);
      }
   }

   /**
    * Close the listener and every accepted socket.
    */
   public function close (): void
   {
      foreach ($this->clients as $client) {
         if (is_resource($client)) {
            fclose($client);
         }
      }

      if (is_resource($this->server)) {
         fclose($this->server);
      }
   }

   /**
    * Consume whole frontend messages from one client buffer.
    */
   private function parse (int $id): void
   {
      $client = $this->clients[$id];

      // @@
      while (true) {
         $buffer = $this->buffers[$id];

         // # Startup: Int32 length + Int32 protocol, no type byte
         if ($this->started[$id] === false) {
            if (strlen($buffer) < 8) {
               return;
            }

            /** @var array{1:int} $length */
            $length = unpack('N', substr($buffer, 0, 4));

            if (strlen($buffer) < $length[1]) {
               return;
            }

            $this->buffers[$id] = substr($buffer, $length[1]);
            $this->started[$id] = true;

            if ($this->mode === 'answer') {
               $parameter = "server_version\0" . "16.0\0";

               @fwrite($client, 'R' . pack('N', 8) . pack('N', 0)
                  . 'S' . pack('N', 4 + strlen($parameter)) . $parameter
                  . 'K' . pack('N', 12) . pack('N', 4242) . pack('N', 9999)
                  . 'Z' . pack('N', 5) . 'I');
            }

            continue;
         }

         // # Typed message: Byte1 type + Int32 length
         if (strlen($buffer) < 5) {
            return;
         }

         /** @var array{1:int} $length */
         $length = unpack('N', substr($buffer, 1, 4));

         if (strlen($buffer) < 1 + $length[1]) {
            return;
         }

         $type = $buffer[0];
         $payload = substr($buffer, 5, $length[1] - 4);
         $this->buffers[$id] = substr($buffer, 1 + $length[1]);

         if ($type === 'Q') {
            $this->queries[] = rtrim($payload, "\0");

            if ($this->mode === 'answer') {
               $tag = "SELECT 0\0";

               @fwrite($client, 'C' . pack('N', 4 + strlen($tag)) . $tag . 'Z' . pack('N', 5) . 'I');
            }
         }
      }
   }
}


return new Test(
   description: 'Resources: a Database wait that never comes back withdraws its operations',
   test: function () {
      // ! Statics survive the suite: snapshot every one this case writes —
      //   the server constructor sets the debugging Vars and installs its own
      //   selector, and each Connections constructor resets the census
      $Statics = [];

      foreach ([TCP_Server_CLI::class, Connections::class, Vars::class] as $class) {
         foreach (new ReflectionClass($class)->getProperties(ReflectionProperty::IS_STATIC) as $Property) {
            if ($Property->getDeclaringClass()->name === $class && $Property->isInitialized()) {
               $Statics[] = [$Property, $Property->getValue()];
            }
         }
      }

      // ! A cold process has no selector installed yet, and PHP cannot unset a
      //   static: teardown then puts back the one the server constructor
      //   installs, which no leg below ever touches — never the stand-in
      $cold = new ReflectionProperty(TCP_Server_CLI::class, 'Event')->isInitialized() === false;
      $Constructed = null;

      $withdrawn = 'Database operation was withdrawn: its caller stopped waiting.';
      $Locked = new ReflectionProperty(Pool::class, 'locked');
      $Peers = [];

      /**
       * One SQL facade per leg: PostgreSQL over the given peer, one slot.
       */
      $open = static function (Peer $Peer): SQL {
         return new SQL([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => $Peer->port,
            'timeout' => 5.0,
            'secure' => ['mode' => 'disable'],
            'pool' => ['min' => 0, 'max' => 1],
         ]);
      };

      // ! The Response bridge: a deferred response parks its Fiber on the token
      $suspend = static function (mixed $value = null): mixed {
         return Fiber::suspend($value);
      };

      /**
       * @return array<string,int>
       */
      $census = static function (SQL $SQL) use ($Locked): array {
         $Pool = $SQL->Pool;
         /** @var array<int,true> $locked */
         $locked = $Locked->getValue($Pool);

         return [
            'created' => $Pool->created,
            'idle' => count($Pool->idle),
            'busy' => count($Pool->busy),
            'pending' => count($Pool->pending),
            'locked' => count($locked),
         ];
      };

      // ? The readiness belongs to a pooled connection still in Startup
      $startup = static function (SQL $SQL): Closure {
         return static function (Readiness $Readiness) use ($SQL): bool {
            foreach ($SQL->Pool->busy as $Connection) {
               if ($Connection->socket === $Readiness->socket && $Connection->state === ConnectionStates::Startup) {
                  return true;
               }
            }

            return false;
         };
      };

      /**
       * Drive one request Fiber the way the worker reactor does: pump the
       * peer, then resume the Fiber once its readiness signals. Stops when the
       * Fiber terminates (null), parks on something that is not a database
       * readiness (that value), or `$until` holds for its readiness (the
       * Readiness). Bounded: `false` once `$cap` seconds pass.
       *
       * @param Fiber<mixed,mixed,mixed,mixed> $Fiber
       */
      $drive = static function (Fiber $Fiber, mixed $value, Peer $Peer, null|Closure $until = null, float $cap = 2.0): mixed {
         $deadline = microtime(true) + $cap;

         // @@
         while ($Fiber->isTerminated() === false) {
            $Peer->pump();

            if ($value instanceof Readiness === false) {
               return $value;
            }

            if ($until !== null && $until($value)) {
               return $value;
            }

            // ? Fail-closed bound: a lost resume must fail the case, not hang the suite
            if (microtime(true) >= $deadline) {
               return false;
            }

            $socket = $value->socket;

            if (is_resource($socket)) {
               $read = [];
               $write = [];
               $except = [];

               if ($value->flag === Scheduler::SCHEDULE_READ) {
                  $read[] = $socket;
               }
               else {
                  $write[] = $socket;
               }

               @stream_select($read, $write, $except, 0, 20000);
            }
            else {
               usleep(2000);
            }

            $value = $Fiber->resume();
         }

         return null;
      };

      // ! Let the peer observe whatever reached it (bounded, ~50 ms)
      $settle = static function (Peer $Peer): void {
         for ($turn = 0; $turn < 10; $turn++) {
            $Peer->pump();
            usleep(5000);
         }
      };

      $close = static function (SQL $SQL): void {
         foreach ([...$SQL->Pool->idle, ...$SQL->Pool->busy] as $Connection) {
            $Connection->disconnect();
         }
      };

      $collectable = gc_enabled();

      try {
         // ! Worker reactor stand-in, so wake() arms completion Wakers on the
         //   parks below — restored on teardown
         $Server = new TCP_Server_CLI;
         $Constructed = TCP_Server_CLI::$Event;
         $Connections = new Connections($Server);
         TCP_Server_CLI::$Event = new Select($Connections);

         // # (a) A rejected wait while the connection is still in Startup
         //   The reactor refuses the park (Select::reject() throws into the
         //   Fiber): the caller gets that very exception, and the operation —
         //   with the connection it was dialing — is taken back at once.
         $Peer = $Peers[] = new Peer('silent');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);
         $Rejection = new RuntimeException('Fiber I/O resource failed selector admission.');

         $Operation = null;
         $Caught = null;
         $Fiber = new Fiber(static function () use ($SQL, $Resource, &$Operation, &$Caught): void {
            $Operation = $SQL->query('SELECT 1');

            try {
               $Resource->await($Operation);
            }
            catch (Throwable $Throwable) {
               $Caught = $Throwable;
            }
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer, $startup($SQL));
         $armed = $Operation?->Waker !== null;
         $Fiber->throw($Rejection);

         yield assert(
            assertion: $held instanceof Readiness && $armed && $Fiber->isTerminated() && $Caught === $Rejection,
            description: 'await(): the rejection thrown into a Startup park reaches the caller as the same object'
         );

         $state = [
            'state' => $Operation?->state->name,
            'error' => $Operation?->error,
            'revoked' => $Operation?->revoked,
            'waker' => $Operation?->Waker === null,
         ] + $census($SQL);

         yield assert(
            assertion: $state === [
               'state' => 'Failed',
               'error' => $withdrawn,
               'revoked' => true,
               'waker' => true,
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
            ],
            description: 'await(): the refused operation is withdrawn, disarmed and its Startup connection discarded, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (b) The Fiber is destroyed while parked in await()
         //   A client disconnect or an eviction drops the request Fiber: only
         //   its `finally` blocks run — refcount alone, no GC run needed.
         $Peer = $Peers[] = new Peer('silent');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Operation = null;
         $Fiber = new Fiber(static function () use ($SQL, $Resource, &$Operation): void {
            $Operation = $SQL->query('SELECT 1');
            $Resource->await($Operation);
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer, $startup($SQL));
         $before = $census($SQL);
         $Weak = WeakReference::create($Fiber);

         gc_disable();
         unset($Fiber);
         $destroyed = $Weak->get() === null;
         $after = $census($SQL);
         if ($collectable) {
            gc_enable();
         }

         $state = [
            'held' => $held instanceof Readiness,
            'destroyed' => $destroyed,
            'state' => $Operation?->state->name,
            'revoked' => $Operation?->revoked,
            'before' => $before['busy'],
         ] + $after;

         yield assert(
            assertion: $state === [
               'held' => true,
               'destroyed' => true,
               'state' => 'Failed',
               'revoked' => true,
               'before' => 1,
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
            ],
            description: 'await(): dropping the parked Fiber withdraws its operation without gc_collect_cycles(), found: ' . json_encode($state)
         );

         $close($SQL);

         // # (c) drain(): a Startup operation plus a BEGIN parked behind it
         //   One slot: the statement dials it, the exclusive BEGIN parks in
         //   `pending`. Withdrawing the dialing statement first would free the
         //   slot and promote() the BEGIN onto a fresh dial — parked ones go first.
         $Peer = $Peers[] = new Peer('silent');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Statement = $SQL->query('SELECT 1');
         $Transaction = $SQL->begin();
         $Begin = $Transaction->Operation;
         $parked = $Begin?->state === OperationStates::Pending && count($SQL->Pool->pending) === 1;

         $Caught = null;
         $Fiber = new Fiber(static function () use ($Resource, $Statement, $Begin, &$Caught): void {
            try {
               $Resource->drain([$Statement, $Begin]);
            }
            catch (Throwable $Throwable) {
               $Caught = $Throwable;
            }
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer, $startup($SQL));
         $Fiber->throw($Rejection);

         // ! Read off the BEGIN itself, no dial count sampled in a time
         //   window: promotion assigns a connection and a driver before it
         //   dials, and the withdrawal that follows fails the operation
         //   without clearing either — so both still unset means the BEGIN
         //   left `pending` withdrawn, never assigned, never Connecting.
         $state = [
            'parked' => $parked,
            'held' => $held instanceof Readiness,
            'caught' => $Caught === $Rejection,
            'statement' => [$Statement->state->name, $Statement->revoked],
            'begin' => [$Begin?->state->name, $Begin?->revoked],
            'assigned' => [$Begin?->Connection !== null, $Begin?->Protocol !== null],
         ] + $census($SQL);

         yield assert(
            assertion: $state === [
               'parked' => true,
               'held' => true,
               'caught' => true,
               'statement' => ['Failed', true],
               'begin' => ['Failed', true],
               'assigned' => [false, false],
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
            ],
            description: 'drain(): a rejected park withdraws the whole group, and the parked BEGIN is never promoted onto the wire, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (d) Control: a wait that really waits keeps the operation
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Operation = null;
         $Fiber = new Fiber(static function () use ($SQL, $Resource, &$Operation): void {
            $Operation = $SQL->query('SELECT 1');
            $Resource->await($Operation);
         });

         $drive($Fiber, $Fiber->start(), $Peer);

         $state = [
            'terminated' => $Fiber->isTerminated(),
            'state' => $Operation?->state->name,
            'error' => $Operation?->error,
            'revoked' => $Operation?->revoked,
            'waker' => $Operation?->Waker === null,
            'queries' => $Peer->queries,
         ] + $census($SQL);

         yield assert(
            assertion: $state === [
               'terminated' => true,
               'state' => 'Finished',
               'error' => null,
               'revoked' => false,
               'waker' => true,
               'queries' => ['SELECT 1'],
               'created' => 1,
               'idle' => 1,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
            ],
            description: 'Control: an answered wait resolves the operation, unrevoked, and the connection goes back idle, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (e) transact(): the Fiber is destroyed while the work parks outside the database
         //   BEGIN went through and the connection is reserved. Only the
         //   outer `finally` runs: the transaction is aborted locally — its
         //   ROLLBACK withdrawn before the wire, which severs the session.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Fiber = new Fiber(static function () use ($Resource): void {
            $Resource->transact(static function (): void {
               // @ A non-database wait: an HTTP client call, a Response::wait()
               Fiber::suspend('parked');
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer);
         $before = $census($SQL);
         $Weak = WeakReference::create($Fiber);

         gc_disable();
         unset($Fiber);
         $destroyed = $Weak->get() === null;
         $after = $census($SQL);
         if ($collectable) {
            gc_enable();
         }

         $settle($Peer);

         $state = [
            'held' => $held,
            'destroyed' => $destroyed,
            'before' => [$before['busy'], $before['locked']],
            'queries' => $Peer->queries,
         ] + $after;

         yield assert(
            assertion: $state === [
               'held' => 'parked',
               'destroyed' => true,
               'before' => [1, 1],
               'queries' => ['BEGIN'],
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
            ],
            description: 'transact(): dropping the parked Fiber ends the transaction and frees the reservation without a GC run, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (e2) Nested transact(): the inner work parks, the Fiber is dropped
         //   The inner frame found depth 1 and leaves the teardown to the
         //   outermost one, which aborts every level at once.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $depth = 0;
         $Fiber = new Fiber(static function () use ($Resource, &$depth): void {
            $Resource->transact(static function (Transaction $Transaction, Database $Database) use (&$depth): void {
               $Database->transact(static function (Transaction $Inner) use (&$depth): void {
                  $depth = $Inner->depth;

                  Fiber::suspend('parked');
               });
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer);
         $before = $census($SQL);
         $Weak = WeakReference::create($Fiber);

         gc_disable();
         unset($Fiber);
         $destroyed = $Weak->get() === null;
         $after = $census($SQL);
         if ($collectable) {
            gc_enable();
         }

         $settle($Peer);

         $state = [
            'held' => $held,
            'depth' => $depth,
            'destroyed' => $destroyed,
            'before' => [$before['busy'], $before['locked']],
            'begin' => $Peer->queries[0] ?? null,
            'savepoint' => str_starts_with($Peer->queries[1] ?? '', 'SAVEPOINT '),
            'sent' => count($Peer->queries),
         ] + $after;

         yield assert(
            assertion: $state === [
               'held' => 'parked',
               'depth' => 2,
               'destroyed' => true,
               'before' => [1, 1],
               'begin' => 'BEGIN',
               'savepoint' => true,
               'sent' => 2,
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
            ],
            description: 'Nested transact(): dropping a Fiber parked inside the savepoint ends the whole transaction, found: ' . json_encode($state)
         );

         $close($SQL);
      }
      finally {
         if ($collectable) {
            gc_enable();
         }

         foreach ($Peers as $Open) {
            $Open->close();
         }

         foreach ($Statics as [$Property, $value]) {
            $Property->setValue(null, $value);
         }

         if ($cold && $Constructed !== null) {
            TCP_Server_CLI::$Event = $Constructed;
         }
      }
   }
);
