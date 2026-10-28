<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\DatabaseWithdraw;


use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function array_shift;
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
use function stream_socket_pair;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use function unpack;
use function usleep;
use Closure;
use Fiber;
use ReflectionClass;
use ReflectionClassConstant;
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
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;


/**
 * In-process PostgreSQL peer on loopback. `silent` accepts and reads but never
 * answers, so a dialed connection stays in Startup; `answer` completes the
 * startup and every simple query; `hold` records each query and owes its
 * answer until flush(). It acts only inside pump(), so the spec decides
 * exactly when the peer has spoken.
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
   /** @var array<int,string> Answers held in `hold` mode, per client */
   public array $owed = [];
   /** Tag every answer with its query's position (`SELECT <n>`), so a reader
       that took another query's answer shows it */
   public bool $numbered = false;


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
    * Write every answer held in `hold` mode.
    */
   public function flush (): void
   {
      foreach ($this->owed as $id => $answers) {
         $client = $this->clients[$id] ?? null;

         if (is_resource($client)) {
            @fwrite($client, $answers);
         }
      }

      $this->owed = [];
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

            $position = $this->numbered ? count($this->queries) : 0;
            $tag = "SELECT {$position}\0";
            $answer = 'C' . pack('N', 4 + strlen($tag)) . $tag . 'Z' . pack('N', 5) . 'I';

            if ($this->mode === 'answer') {
               @fwrite($client, $answer);
            }
            else if ($this->mode === 'hold') {
               $this->owed[$id] = ($this->owed[$id] ?? '') . $answer;
            }
         }
      }
   }
}


/**
 * Worker reactor stand-in: records every Fiber handed to schedule() instead of
 * running a loop, so the spec drives each one itself, the way Select resumes a
 * scheduled Fiber. A recorded Fiber is held strongly, as a reactor seat holds
 * it. `refusing` refuses every Fiber the way Select refuses a wait it cannot
 * seat (Select::reject()): the admission error is thrown in at the suspension
 * point, and only a Fiber still suspended afterwards is queued again. The
 * real Select never refuses a tick admission — the adopter's — so this is the
 * Scheduler contract's refusal, carried out with Select's own mechanics.
 */
final class Reactor extends Select
{
   // * Data
   public bool $refusing = false;
   /** @var array<int,array{0:Fiber<mixed,mixed,mixed,mixed>,1:mixed}> */
   public array $Scheduled = [];

   // * Metadata
   public private(set) int $refusals = 0;


   public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool
   {
      if ($this->refusing) {
         $this->refusals++;

         try {
            $value = $Fiber->throw(new RuntimeException('Fiber I/O resource failed selector admission.'));
         }
         catch (Throwable) {
            return false;
         }

         if ($Fiber->isTerminated()) {
            return false;
         }
      }

      $this->Scheduled[] = [$Fiber, $value];

      return true;
   }
}


return new Test(
   description: 'Resources: a Database wait that never comes back withdraws its operations, and a transaction left open is adopted, not severed',
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
         $Reactor = new Reactor($Connections);
         TCP_Server_CLI::$Event = $Reactor;

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

         // ! Every leg below destroys a request Fiber inside transact() and then
         //   runs what the reactor was handed
         $Quarantine = new ReflectionProperty(Pool::class, 'quarantine');
         // ! The session reset the PostgreSQL driver writes on a kept session
         //   whose owner is gone: an adopted session ends with it on the wire
         $reset = new ReflectionClassConstant(PostgreSQL::class, 'RESET')->getValue();

         /**
          * Drive every Fiber the reactor was handed the way Select runs one:
          * resumed once its readiness signals, or on the next tick for any
          * other suspend value. Returns how many ran, or -1 past `$cap`.
          */
         $run = static function (Peer $Peer, float $cap = 2.0) use ($Reactor): int {
            $deadline = microtime(true) + $cap;
            $ran = 0;

            // @@
            while ($Reactor->Scheduled !== []) {
               [$Scheduled, $value] = array_shift($Reactor->Scheduled);
               $ran++;

               // @@
               while ($Scheduled->isTerminated() === false) {
                  $Peer->pump();

                  // ? Fail-closed bound: a lost resume must fail the case, not hang the suite
                  if (microtime(true) >= $deadline) {
                     return -1;
                  }

                  if ($value instanceof Readiness && is_resource($value->socket)) {
                     $read = [];
                     $write = [];
                     $except = [];

                     if ($value->flag === Scheduler::SCHEDULE_READ) {
                        $read[] = $value->socket;
                     }
                     else {
                        $write[] = $value->socket;
                     }

                     @stream_select($read, $write, $except, 0, 20000);
                  }
                  else {
                     usleep(2000);
                  }

                  $value = $Scheduled->resume();
               }
            }

            return $ran;
         };

         /**
          * Drop one request Fiber the way a client disconnect or an eviction
          * does — refcount alone, no GC run — then run whatever the reactor
          * was handed. Reports the census right after the destruction, what
          * was scheduled and sent before anything ran, and the end state.
          *
          * With `$drop`, the reactor drops what it was handed without running
          * it, as a reactor torn down does. Whatever the destruction rethrew —
          * the work's own unwinding — is reported as `escaped`.
          *
          * @param Fiber<mixed,mixed,mixed,mixed> $Fiber
          * @param-out null $Fiber
          * @return array<string,mixed>
          */
         $orphan = static function (Fiber &$Fiber, SQL $SQL, Peer $Peer, bool $drop = false) use ($Reactor, $Quarantine, $census, $run, $settle, $collectable): array {
            $before = $census($SQL);
            $Reactor->Scheduled = [];
            $Weak = WeakReference::create($Fiber);
            $escaped = null;

            gc_disable();
            try {
               $Fiber = null;
            }
            catch (Throwable $Throwable) {
               // @ As Select::reap() contains it
               $escaped = $Throwable->getMessage();
            }
            $destroyed = $Weak->get() === null;
            $after = $census($SQL);
            if ($collectable) {
               gc_enable();
            }

            $admitted = [count($Reactor->Scheduled), $Peer->queries];
            $ran = 0;

            if ($drop) {
               $Reactor->Scheduled = [];
            }
            else {
               $ran = $run($Peer);
            }

            $settle($Peer);

            /** @var array<int,float> $quarantine */
            $quarantine = $Quarantine->getValue($SQL->Pool);

            return [
               'destroyed' => $destroyed,
               'before' => [$before['busy'], $before['locked']],
               'kept' => [$after['created'], $after['busy'], $after['locked']],
               'admitted' => $admitted,
               'ran' => $ran,
               'queries' => $Peer->queries,
               'session' => is_resource($Peer->clients[0] ?? null),
               'quarantine' => count($quarantine),
            ] + $census($SQL) + ($escaped === null ? [] : ['escaped' => $escaped]);
         };

         // ! The adopted end state: the session kept (no EOF at the peer),
         //   nothing quarantined, the reservation released by the teardown's
         //   own answer, the connection idle for the next caller
         $adopted = [
            'session' => true,
            'quarantine' => 0,
            'created' => 1,
            'idle' => 1,
            'busy' => 0,
            'pending' => 0,
            'locked' => 0,
         ];

         // # (e) transact(): the Fiber is destroyed while the work parks outside the database
         //   BEGIN went through and the connection is reserved. Only the
         //   `finally` blocks run: the transaction is not severed but adopted —
         //   a detached Fiber, handed to the reactor before it touches
         //   anything, rolls it back on its own session, and the ROLLBACK's
         //   answer releases the reservation.
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
         $state = ['held' => $held] + $orphan($Fiber, $SQL, $Peer);

         yield assert(
            assertion: $state === [
               'held' => 'parked',
               'destroyed' => true,
               'before' => [1, 1],
               'kept' => [1, 1, 1],
               'admitted' => [1, ['BEGIN']],
               'ran' => 1,
               'queries' => ['BEGIN', 'ROLLBACK', $reset],
            ] + $adopted,
            description: 'transact(): dropping the parked Fiber hands the transaction to an adopter, which rolls it back on its own session and releases the reservation, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (e1) transact(): the Fiber is destroyed while a statement is in flight
         //   await() leaves the statement to the adopter instead of withdrawing
         //   it — a withdrawal abandons it on the reserved connection, which
         //   severs the session. The adopter drains its answer, then rolls back.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Captured = null;
         $Fiber = new Fiber(static function () use ($Resource, &$Captured): void {
            $Resource->transact(static function (Transaction $Transaction, Database $Database) use (&$Captured): void {
               $Captured = $Transaction;

               $Database->query('SELECT 1');
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer, static fn (): bool => $Peer->queries === ['BEGIN', 'SELECT 1']);
         $Statement = $Captured?->Operation;
         $flight = $Statement?->finished === false;
         $state = ['held' => $held instanceof Readiness, 'flight' => $flight] + $orphan($Fiber, $SQL, $Peer) + [
            'statement' => [$Statement?->state->name, $Statement?->error, $Statement?->revoked],
         ];

         yield assert(
            assertion: $state === [
               'held' => true,
               'flight' => true,
               'destroyed' => true,
               'before' => [1, 1],
               'kept' => [1, 1, 1],
               'admitted' => [1, ['BEGIN', 'SELECT 1']],
               'ran' => 1,
               'queries' => ['BEGIN', 'SELECT 1', 'ROLLBACK', $reset],
            ] + $adopted + [
               'statement' => ['Finished', null, false],
            ],
            description: 'transact(): a statement in flight when the Fiber drops is drained by the adopter, not withdrawn, before the ROLLBACK, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (e2) Nested transact(): the inner work parks, the Fiber is dropped
         //   The inner frame found depth 1 and leaves the teardown to the
         //   outermost one, whose adopter ends every level with one ROLLBACK.
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
         $savepoint = $Peer->queries[1] ?? '';
         $state = ['held' => $held, 'depth' => $depth] + $orphan($Fiber, $SQL, $Peer);

         yield assert(
            assertion: str_starts_with($savepoint, 'SAVEPOINT ') && $state === [
               'held' => 'parked',
               'depth' => 2,
               'destroyed' => true,
               'before' => [1, 1],
               'kept' => [1, 1, 1],
               'admitted' => [1, ['BEGIN', $savepoint]],
               'ran' => 1,
               'queries' => ['BEGIN', $savepoint, 'ROLLBACK', $reset],
            ] + $adopted,
            description: 'Nested transact(): dropping a Fiber parked inside the savepoint rolls the whole transaction back on its session, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (e3) Nested transact(): the Fiber is dropped while the SAVEPOINT is in flight
         //   The savepoint is the operation the transaction tracks, so it is
         //   left to the outermost frame's adopter like any statement.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $reached = false;
         $Fiber = new Fiber(static function () use ($Resource, &$reached): void {
            $Resource->transact(static function (Transaction $Transaction, Database $Database) use (&$reached): void {
               $Database->transact(static function () use (&$reached): void {
                  $reached = true;
               });
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer, static fn (): bool => count($Peer->queries) === 2);
         $savepoint = $Peer->queries[1] ?? '';
         $state = ['held' => $held instanceof Readiness, 'reached' => $reached] + $orphan($Fiber, $SQL, $Peer);

         yield assert(
            assertion: str_starts_with($savepoint, 'SAVEPOINT ') && $state === [
               'held' => true,
               'reached' => false,
               'destroyed' => true,
               'before' => [1, 1],
               'kept' => [1, 1, 1],
               'admitted' => [1, ['BEGIN', $savepoint]],
               'ran' => 1,
               'queries' => ['BEGIN', $savepoint, 'ROLLBACK', $reset],
            ] + $adopted,
            description: 'Nested transact(): a SAVEPOINT in flight when the Fiber drops is drained by the adopter before the ROLLBACK, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (f) transact(): the Fiber is destroyed while its BEGIN is in flight
         //   The BEGIN is awaited inside the teardown region: it is drained,
         //   then rolled back, and the work never runs.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $reached = false;
         $Fiber = new Fiber(static function () use ($Resource, &$reached): void {
            $Resource->transact(static function () use (&$reached): void {
               $reached = true;
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer, static fn (): bool => $Peer->queries === ['BEGIN']);
         $state = ['held' => $held instanceof Readiness, 'reached' => $reached] + $orphan($Fiber, $SQL, $Peer);

         yield assert(
            assertion: $state === [
               'held' => true,
               'reached' => false,
               'destroyed' => true,
               'before' => [1, 1],
               'kept' => [1, 1, 1],
               'admitted' => [1, ['BEGIN']],
               'ran' => 1,
               'queries' => ['BEGIN', 'ROLLBACK', $reset],
            ] + $adopted,
            description: 'transact(): a BEGIN in flight when the Fiber drops is drained and rolled back on its session, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (g) transact(): the Fiber is destroyed while the final COMMIT is in flight
         //   The COMMIT is drained — its answer is what releases the
         //   reservation — and no ROLLBACK follows: it already ended the
         //   transaction.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Captured = null;
         $Fiber = new Fiber(static function () use ($Resource, &$Captured): void {
            $Resource->transact(static function (Transaction $Transaction) use (&$Captured): void {
               $Captured = $Transaction;
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer, static fn (): bool => $Peer->queries === ['BEGIN', 'COMMIT']);
         $Commit = $Captured?->Operation;
         $flight = $Commit?->finished === false;
         $state = ['held' => $held instanceof Readiness, 'flight' => $flight] + $orphan($Fiber, $SQL, $Peer) + [
            'commit' => [$Commit?->state->name, $Commit?->error, $Commit?->revoked],
         ];

         yield assert(
            assertion: $state === [
               'held' => true,
               'flight' => true,
               'destroyed' => true,
               'before' => [1, 1],
               'kept' => [1, 1, 1],
               'admitted' => [1, ['BEGIN', 'COMMIT']],
               'ran' => 1,
               'queries' => ['BEGIN', 'COMMIT', $reset],
            ] + $adopted + [
               'commit' => ['Finished', null, false],
            ],
            description: 'transact(): a COMMIT in flight when the Fiber drops is drained, releases the reservation, and no ROLLBACK follows, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (h) transact(): the reactor refuses the adopter
         //   The refusal is delivered at the adopter's admission point, where
         //   it has touched nothing yet: it ends there, and the transaction is
         //   withdrawn as it was before adoption — the statement in flight is
         //   withdrawn on the reserved connection, which severs the session
         //   and quarantines its slot until its deadline.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Captured = null;
         $Fiber = new Fiber(static function () use ($Resource, &$Captured): void {
            $Resource->transact(static function (Transaction $Transaction, Database $Database) use (&$Captured): void {
               $Captured = $Transaction;

               $Database->query('SELECT 1');
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer, static fn (): bool => $Peer->queries === ['BEGIN', 'SELECT 1']);
         $Statement = $Captured?->Operation;
         $Reactor->refusing = true;
         $refusals = $Reactor->refusals;

         try {
            $state = ['held' => $held instanceof Readiness] + $orphan($Fiber, $SQL, $Peer);
         }
         finally {
            $Reactor->refusing = false;
         }

         $state += [
            'refused' => $Reactor->refusals - $refusals,
            'statement' => [$Statement?->state->name, $Statement?->error, $Statement?->revoked],
         ];

         yield assert(
            assertion: $state === [
               'held' => true,
               'destroyed' => true,
               'before' => [1, 1],
               'kept' => [0, 0, 0],
               'admitted' => [0, ['BEGIN', 'SELECT 1']],
               'ran' => 0,
               'queries' => ['BEGIN', 'SELECT 1'],
               'session' => false,
               'quarantine' => 1,
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
               'refused' => 1,
               'statement' => ['Failed', $withdrawn, true],
            ],
            description: 'transact(): an adopter the reactor refuses falls back to withdrawing the transaction — severed, slot quarantined, reservation dropped, found: ' . json_encode($state)
         );

         $close($SQL);

         // ! The real worker reactor for the legs (i)/(i2): Select::loop()
         //   driven in short slices — each ended by a one-shot stop — with
         //   the peer pumped between them, until `$done` holds or `$bound`
         //   passes. Bounded: a lost resume fails the leg, never hangs it.
         $Loop = new Select($Connections);
         $loop = static function (Peer $Peer, Closure $done, float $bound = 2.0) use ($Loop): void {
            $deadline = microtime(true) + $bound;

            // @@
            do {
               $Loop->defer(microtime(true) + 0.010, static function () use ($Loop): void {
                  $Loop->loop = false;
               });
               $Loop->loop = true;
               $Loop->loop();

               $Peer->pump();
            }
            while ($done() === false && microtime(true) < $deadline);
         };

         // # (i) transact(): the real reactor runs the adopter
         //   Select parks the adopter's admission on its tick queue, resumes
         //   it there, seats its readiness wait, and resumes it again once
         //   the ROLLBACK's answer arrives.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Fiber = new Fiber(static function () use ($Resource): void {
            $Resource->transact(static function (): void {
               Fiber::suspend('parked');
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer);
         TCP_Server_CLI::$Event = $Loop;

         try {
            gc_disable();
            $Fiber = null;
            $kept = $census($SQL);
            if ($collectable) {
               gc_enable();
            }

            $loop($Peer, static fn (): bool => count($SQL->Pool->idle) === 1);
            $settle($Peer);
         }
         finally {
            TCP_Server_CLI::$Event = $Reactor;
         }

         /** @var array<int,float> $quarantine */
         $quarantine = $Quarantine->getValue($SQL->Pool);
         $state = [
            'held' => $held,
            'kept' => [$kept['created'], $kept['busy'], $kept['locked']],
            'queries' => $Peer->queries,
            'session' => is_resource($Peer->clients[0] ?? null),
            'quarantine' => count($quarantine),
         ] + $census($SQL);

         yield assert(
            assertion: $state === [
               'held' => 'parked',
               'kept' => [1, 1, 1],
               'queries' => ['BEGIN', 'ROLLBACK', $reset],
            ] + $adopted,
            description: 'transact(): under the real reactor the adopter rolls the transaction back on its session and releases it, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (i2) transact(): the real selector refuses the adopter's readiness wait
         //   Admitted, the adopter is on its own. With the selector's read
         //   table full (its descriptor budget), Select refuses the wait the
         //   ROLLBACK parks on and delivers the refusal at the suspension
         //   point: the adopter withdraws the transaction as it was before
         //   adoption — the ROLLBACK severs the session, quarantined until its
         //   deadline — instead of polling by ticks, which spins the reactor.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Fiber = new Fiber(static function () use ($Resource): void {
            $Resource->transact(static function (): void {
               Fiber::suspend('parked');
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer);

         // ! The read table at Select::CAPACITY: an idle pair seated through
         //   add(), the rest synthetic entries on it under keys no resource id
         //   takes (as Unit 1.53 saturates it)
         $Reads = new ReflectionProperty(Select::class, 'reads');
         $Reading = new ReflectionProperty(Select::class, 'reading');
         $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
         if ($pair === false) {
            throw new RuntimeException('The saturated selector could not allocate a socket pair.');
         }
         $Payload = new class {
            public function reading (mixed $Socket): void {}
            public function writing (mixed $Socket): void {}
         };
         $Loop->add($pair[0], Select::EVENT_READ, $Payload);
         $Loop->add($pair[1], Select::EVENT_READ, $Payload);
         /** @var array<int,resource> $reads */
         $reads = $Reads->getValue($Loop);
         /** @var array<int,mixed> $Payloads */
         $Payloads = $Reading->getValue($Loop);
         for ($key = -1; count($reads) < Select::CAPACITY; $key--) {
            $reads[$key] = $pair[0];
            $Payloads[$key] = $Payload;
         }
         $Reads->setValue($Loop, $reads);
         $Reading->setValue($Loop, $Payloads);
         $saturated = count($reads) === Select::CAPACITY;
         TCP_Server_CLI::$Event = $Loop;

         try {
            gc_disable();
            $Fiber = null;
            $kept = $census($SQL);
            if ($collectable) {
               gc_enable();
            }

            $loop($Peer, static fn (): bool => $SQL->Pool->created === 0);
            $settle($Peer);
         }
         finally {
            TCP_Server_CLI::$Event = $Reactor;
            $Loop->destroy();
            fclose($pair[0]);
            fclose($pair[1]);
         }

         /** @var array<int,float> $quarantine */
         $quarantine = $Quarantine->getValue($SQL->Pool);
         $state = [
            'held' => $held,
            'saturated' => $saturated,
            'kept' => [$kept['created'], $kept['busy'], $kept['locked']],
            'queries' => $Peer->queries,
            'session' => is_resource($Peer->clients[0] ?? null),
            'quarantine' => count($quarantine),
         ] + $census($SQL);

         yield assert(
            assertion: $state === [
               'held' => 'parked',
               'saturated' => true,
               'kept' => [1, 1, 1],
               'queries' => ['BEGIN', 'ROLLBACK'],
               'session' => false,
               'quarantine' => 1,
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
            ],
            description: 'transact(): a readiness wait the real selector refuses mid-adoption withdraws the transaction — severed, quarantined, reservation dropped, no tick polling, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (j) transact(): the reactor drops an admitted adopter before it ever ran
         //   Once admitted, the adopter alone answers for the transaction: a
         //   reactor torn down (worker shutdown) destroys it at its admission
         //   point, and it withdraws the transaction as it was before adoption
         //   instead of leaving the reservation open.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Fiber = new Fiber(static function () use ($Resource): void {
            $Resource->transact(static function (): void {
               Fiber::suspend('parked');
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer);
         $state = ['held' => $held] + $orphan($Fiber, $SQL, $Peer, drop: true);

         yield assert(
            assertion: $state === [
               'held' => 'parked',
               'destroyed' => true,
               'before' => [1, 1],
               'kept' => [1, 1, 1],
               'admitted' => [1, ['BEGIN']],
               'ran' => 0,
               'queries' => ['BEGIN'],
               'session' => false,
               'quarantine' => 0,
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
            ],
            description: 'transact(): an admitted adopter the reactor drops before it ran withdraws the transaction — severed, reservation dropped, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (k) transact(): the Fiber is destroyed while its BEGIN is still dialing
         //   A BEGIN that never reached the server opened nothing to end: it
         //   is withdrawn, not adopted — its dialing connection is discarded
         //   and the slot comes back at once, with nothing scheduled.
         $Peer = $Peers[] = new Peer('silent');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $reached = false;
         $Fiber = new Fiber(static function () use ($Resource, &$reached): void {
            $Resource->transact(static function () use (&$reached): void {
               $reached = true;
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer, $startup($SQL));
         $state = ['held' => $held instanceof Readiness, 'reached' => $reached] + $orphan($Fiber, $SQL, $Peer);

         yield assert(
            assertion: $state === [
               'held' => true,
               'reached' => false,
               'destroyed' => true,
               'before' => [1, 1],
               'kept' => [0, 0, 0],
               'admitted' => [0, []],
               'ran' => 0,
               'queries' => [],
               'session' => false,
               'quarantine' => 0,
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
            ],
            description: 'transact(): a BEGIN still dialing when the Fiber drops is withdrawn, not adopted — its connection discarded, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (l) transact(): the work's own unwinding queries while the Fiber is destroyed
         //   The statement in flight is left to the adopter, so the `finally`
         //   that queries again is refused by the transaction and throws into
         //   transact(). That throw must not be taken for a failed work: a
         //   rollback composed there would discard the statement left in
         //   flight, dropping its session outside the pool's accounting while
         //   the server still runs it. The transaction is adopted instead.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Captured = null;
         $Fiber = new Fiber(static function () use ($Resource, &$Captured): void {
            $Resource->transact(static function (Transaction $Transaction, Database $Database) use (&$Captured): void {
               $Captured = $Transaction;

               try {
                  $Database->query('SELECT 1');
               }
               finally {
                  // @ An ordinary cleanup: an audit row, a lock release
                  $Database->fetch('SELECT 2');
               }
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer, static fn (): bool => $Peer->queries === ['BEGIN', 'SELECT 1']);
         $Statement = $Captured?->Operation;
         $state = ['held' => $held instanceof Readiness] + $orphan($Fiber, $SQL, $Peer) + [
            'statement' => [$Statement?->state->name, $Statement?->error, $Statement?->revoked],
         ];

         yield assert(
            assertion: $state === [
               'held' => true,
               'destroyed' => true,
               'before' => [1, 1],
               'kept' => [1, 1, 1],
               'admitted' => [1, ['BEGIN', 'SELECT 1']],
               'ran' => 1,
               'queries' => ['BEGIN', 'SELECT 1', 'ROLLBACK', $reset],
            ] + $adopted + [
               'escaped' => 'SQL transaction operation is still active.',
               'statement' => ['Finished', null, false],
            ],
            description: 'transact(): a work whose finally queries while the Fiber drops still hands the transaction to the adopter — never a session dropped with its statement running, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (l2) transact(): the work's own unwinding rolls the transaction back while the Fiber is destroyed
         //   A `finally` — or a guard's destructor — calls the top-level
         //   rollback() itself while the statement left for the adopter is
         //   still in flight. The rollback overtakes that statement on the
         //   reserved connection, where nobody is left to read it, so its
         //   session drops; the statement keeps its slot until its deadline
         //   all the same, as any withdrawn statement does — the server still
         //   runs it. The adopter then finds the transaction already over.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Captured = null;
         $Fiber = new Fiber(static function () use ($Resource, &$Captured): void {
            $Resource->transact(static function (Transaction $Transaction, Database $Database) use (&$Captured): void {
               $Captured = $Transaction;

               try {
                  $Database->query('SELECT 1');
               }
               finally {
                  $Transaction->rollback();
               }
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer, static fn (): bool => $Peer->queries === ['BEGIN', 'SELECT 1']);
         $Statement = $Captured?->Operation;
         $state = ['held' => $held instanceof Readiness] + $orphan($Fiber, $SQL, $Peer) + [
            'statement' => [$Statement?->state->name, $Statement?->error, $Statement?->revoked],
         ];

         yield assert(
            assertion: $state === [
               'held' => true,
               'destroyed' => true,
               'before' => [1, 1],
               'kept' => [0, 0, 0],
               'admitted' => [1, ['BEGIN', 'SELECT 1']],
               'ran' => 1,
               'queries' => ['BEGIN', 'SELECT 1'],
               'session' => false,
               'quarantine' => 1,
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
               'statement' => ['Failed', $withdrawn, true],
            ],
            description: 'transact(): a work whose finally rolls back while the Fiber drops discards the statement in flight without freeing its slot — severed, quarantined until its deadline, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (m) transact(): the Fiber is destroyed while the rollback of a failed work is in flight
         //   The outermost frame keeps the surface across its rollback, so
         //   the ROLLBACK is left to the adopter like any statement: drained
         //   on its session, which releases the reservation — and no second
         //   ROLLBACK follows.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Caught = null;
         $Fiber = new Fiber(static function () use ($Resource, &$Caught): void {
            try {
               $Resource->transact(static function (): void {
                  throw new RuntimeException('The work failed.');
               });
            }
            catch (Throwable $Throwable) {
               $Caught = $Throwable;
            }
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer, static fn (): bool => $Peer->queries === ['BEGIN', 'ROLLBACK']);
         $state = ['held' => $held instanceof Readiness] + $orphan($Fiber, $SQL, $Peer) + ['caught' => $Caught === null];

         yield assert(
            assertion: $state === [
               'held' => true,
               'destroyed' => true,
               'before' => [1, 1],
               'kept' => [1, 1, 1],
               'admitted' => [1, ['BEGIN', 'ROLLBACK']],
               'ran' => 1,
               'queries' => ['BEGIN', 'ROLLBACK', $reset],
            ] + $adopted + [
               'caught' => true,
            ],
            description: 'transact(): a ROLLBACK in flight when the Fiber drops is drained by the adopter, which releases the reservation, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (n) transact(): the connection is never lent while the adopted transaction is open
         //   The reservation outlives the request: until the ROLLBACK is
         //   answered, another caller's statement waits in `pending` — max=1,
         //   no second dial — and once it is, that caller gets the very same
         //   session.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Fiber = new Fiber(static function () use ($Resource): void {
            $Resource->transact(static function (): void {
               Fiber::suspend('parked');
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer);
         $Peer->mode = 'hold';
         $Reactor->Scheduled = [];

         gc_disable();
         $Fiber = null;
         if ($collectable) {
            gc_enable();
         }

         [$Adopter, $value] = $Reactor->Scheduled[0] ?? [null, null];
         $Reactor->Scheduled = [];

         // @ Admitted and resumed: the ROLLBACK reaches the peer, its answer held
         $value = $Adopter?->resume();
         $settle($Peer);

         $Next = $SQL->query('SELECT 2');

         for ($turn = 0; $turn < 5; $turn++) {
            $Next = $SQL->advance($Next);
            $Peer->pump();
            usleep(2000);
         }

         $reserved = [
            'next' => $Next->state->name,
            'dials' => count($Peer->clients),
            'queries' => $Peer->queries,
         ] + $census($SQL);

         // @ The answer arrives: the adopter finishes, then the waiting caller
         $Peer->mode = 'answer';
         $Peer->flush();
         $deadline = microtime(true) + 2.0;

         // @@
         while ($Adopter !== null && $Adopter->isTerminated() === false && microtime(true) < $deadline) {
            $Peer->pump();

            if ($value instanceof Readiness && is_resource($value->socket)) {
               $read = [$value->socket];
               $write = [];
               $except = [];
               @stream_select($read, $write, $except, 0, 20000);
            }
            else {
               usleep(2000);
            }

            $value = $Adopter->resume();
         }

         // @@
         while ($Next->finished === false && microtime(true) < $deadline) {
            $Next = $SQL->advance($Next);
            $Peer->pump();
            usleep(2000);
         }

         $state = [
            'held' => $held,
            'adopter' => $Adopter?->isTerminated(),
            'reserved' => $reserved,
            'next' => [$Next->state->name, $Next->error],
            'dials' => count($Peer->clients),
            'queries' => $Peer->queries,
         ] + $census($SQL);

         yield assert(
            assertion: $state === [
               'held' => 'parked',
               'adopter' => true,
               'reserved' => [
                  'next' => 'Pending',
                  'dials' => 1,
                  'queries' => ['BEGIN', 'ROLLBACK'],
                  'created' => 1,
                  'idle' => 0,
                  'busy' => 1,
                  'pending' => 1,
                  'locked' => 1,
               ],
               'next' => ['Finished', null],
               'dials' => 1,
               'queries' => ['BEGIN', 'ROLLBACK', $reset, 'SELECT 2'],
               'created' => 1,
               'idle' => 1,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
            ],
            description: 'transact(): while the adopter has not seen its ROLLBACK answered the connection stays reserved — the next caller waits, nothing dials — then it gets the same session, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (o) transact(): the adopted session is reset before the connection is lent again
         //   Session-level state outlives a ROLLBACK — advisory locks, a
         //   LISTEN. The adopter's teardown releases a tainted connection: the
         //   reset is on the wire by the time the connection is idle, and the
         //   next caller drains it, then reads its own answer — never the
         //   reset's. Each answer carries its query's position in its tag.
         $Peer = $Peers[] = new Peer('answer');
         $Peer->numbered = true;
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Fiber = new Fiber(static function () use ($Resource): void {
            $Resource->transact(static function (): void {
               Fiber::suspend('parked');
            });
         });

         $held = $drive($Fiber, $Fiber->start(), $Peer);
         $Reactor->Scheduled = [];

         gc_disable();
         $Fiber = null;
         if ($collectable) {
            gc_enable();
         }

         [$Adopter, $value] = $Reactor->Scheduled[0] ?? [null, null];
         $Reactor->Scheduled = [];
         $idled = null;
         $deadline = microtime(true) + 2.0;

         // @@ One resume at a time, as the reactor runs it: what the peer had
         //    received the first time the connection showed idle
         while ($Adopter !== null && $Adopter->isTerminated() === false && microtime(true) < $deadline) {
            $Peer->pump();

            if ($value instanceof Readiness && is_resource($value->socket)) {
               $read = [$value->socket];
               $write = [];
               $except = [];
               @stream_select($read, $write, $except, 0, 20000);
            }
            else {
               usleep(2000);
            }

            $value = $Adopter->resume();

            if ($idled === null && count($SQL->Pool->idle) === 1) {
               $Peer->pump();
               $idled = $Peer->queries;
            }
         }

         $Next = $SQL->query('SELECT 2');

         // @@
         while ($Next->finished === false && microtime(true) < $deadline) {
            $Next = $SQL->advance($Next);
            $Peer->pump();
            usleep(2000);
         }

         $state = [
            'held' => $held,
            'adopter' => $Adopter?->isTerminated(),
            'idled' => $idled,
            'next' => [$Next->state->name, $Next->error, $Next->affected],
            'dials' => count($Peer->clients),
            'queries' => $Peer->queries,
         ] + $census($SQL);

         yield assert(
            assertion: $state === [
               'held' => 'parked',
               'adopter' => true,
               'idled' => ['BEGIN', 'ROLLBACK', $reset],
               'next' => ['Finished', null, 4],
               'dials' => 1,
               'queries' => ['BEGIN', 'ROLLBACK', $reset, 'SELECT 2'],
               'created' => 1,
               'idle' => 1,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
            ],
            description: 'transact(): the adopted session goes idle with its reset already on the wire, and the next caller drains it and reads only its own answer, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (p) transact(): a wait refused inside it while the Fiber lives keeps the withdrawal
         //   Only a destroyed Fiber adopts. A refused or interrupted wait
         //   delivers its error into a live Fiber: the statement is withdrawn
         //   on the reserved connection — severed, quarantined — the error
         //   reaches the caller, and nothing is scheduled. The same live Fiber
         //   then rolls a second, failing transaction back itself: it is never
         //   taken for one under destruction. Two slots, so the second dials
         //   while the first one's slot is quarantined.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = new SQL([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => $Peer->port,
            'timeout' => 5.0,
            'secure' => ['mode' => 'disable'],
            'pool' => ['min' => 0, 'max' => 2],
         ]);
         $Resource = new Database($SQL)->schedule($suspend);

         $Captured = null;
         $Caught = null;
         $Second = null;
         $Fiber = new Fiber(static function () use ($Resource, &$Captured, &$Caught, &$Second): void {
            try {
               $Resource->transact(static function (Transaction $Transaction, Database $Database) use (&$Captured): void {
                  $Captured = $Transaction;

                  $Database->query('SELECT 1');
               });
            }
            catch (Throwable $Throwable) {
               $Caught = $Throwable;
            }

            Fiber::suspend('between');

            try {
               $Resource->transact(static function (): void {
                  throw new RuntimeException('The second work failed.');
               });
            }
            catch (Throwable $Throwable) {
               $Second = $Throwable;
            }
         });

         $Reactor->Scheduled = [];
         $held = $drive($Fiber, $Fiber->start(), $Peer, static fn (): bool => $Peer->queries === ['BEGIN', 'SELECT 1']);
         $Statement = $Captured?->Operation;

         // @ The refusal Select::reject() delivers at the suspension point
         $between = $Fiber->throw($Rejection);
         $settle($Peer);

         /** @var array<int,float> $quarantine */
         $quarantine = $Quarantine->getValue($SQL->Pool);
         $first = [
            'between' => $between,
            'caught' => $Caught === $Rejection,
            'statement' => [$Statement?->state->name, $Statement?->error, $Statement?->revoked],
            'scheduled' => count($Reactor->Scheduled),
            'queries' => $Peer->queries,
            'session' => is_resource($Peer->clients[0] ?? null),
            'quarantine' => count($quarantine),
         ] + $census($SQL);

         $drive($Fiber, $Fiber->resume(), $Peer);

         $state = [
            'held' => $held instanceof Readiness,
            'first' => $first,
            'terminated' => $Fiber->isTerminated(),
            'second' => $Second?->getMessage(),
            'scheduled' => count($Reactor->Scheduled),
            'queries' => $Peer->queries,
         ] + $census($SQL);

         yield assert(
            assertion: $state === [
               'held' => true,
               'first' => [
                  'between' => 'between',
                  'caught' => true,
                  'statement' => ['Failed', $withdrawn, true],
                  'scheduled' => 0,
                  'queries' => ['BEGIN', 'SELECT 1'],
                  'session' => false,
                  'quarantine' => 1,
                  'created' => 0,
                  'idle' => 0,
                  'busy' => 0,
                  'pending' => 0,
                  'locked' => 0,
               ],
               'terminated' => true,
               'second' => 'The second work failed.',
               'scheduled' => 0,
               'queries' => ['BEGIN', 'SELECT 1', 'BEGIN', 'ROLLBACK'],
               'created' => 1,
               'idle' => 1,
               'busy' => 0,
               'pending' => 0,
               'locked' => 0,
            ],
            description: 'transact(): a refusal delivered into a live Fiber still withdraws and severs, nothing is adopted, and a later failing transaction in that Fiber rolls back itself, found: ' . json_encode($state)
         );

         $close($SQL);

         // # (q) transact(): a stranger context destroyed while awaiting the owner's statement
         //   One resource object reachable from two contexts: the owner's
         //   transaction is not the stranger's to adopt — no transact() frame
         //   of its own would ever run the adopter. Its destroyed wait
         //   withdraws the statement as before, and the owner, resumed, finds
         //   its transaction gone and rolls back itself — the stranger's
         //   destruction is never taken for its own.
         $Peer = $Peers[] = new Peer('answer');
         $SQL = $open($Peer);
         $Resource = new Database($SQL)->schedule($suspend);

         $Shared = null;
         $Caught = null;
         $Owner = new Fiber(static function () use ($Resource, &$Shared, &$Caught): void {
            try {
               $Resource->transact(static function (Transaction $Transaction) use (&$Shared): void {
                  // @ Issued, and handed to another context to await
                  $Shared = $Transaction->query('SELECT 1');

                  Fiber::suspend('parked');
               });
            }
            catch (Throwable $Throwable) {
               $Caught = $Throwable;
            }
         });

         $held = $drive($Owner, $Owner->start(), $Peer);
         $Stranger = new Fiber(static function () use ($Resource, &$Shared): void {
            if ($Shared instanceof Operation) {
               $Resource->await($Shared);
            }
         });

         $parked = $drive($Stranger, $Stranger->start(), $Peer, static fn (): bool => $Peer->queries === ['BEGIN', 'SELECT 1']);
         $Reactor->Scheduled = [];
         $Weak = WeakReference::create($Stranger);

         gc_disable();
         $Stranger = null;
         $destroyed = $Weak->get() === null;
         if ($collectable) {
            gc_enable();
         }

         $settle($Peer);

         /** @var array<int,float> $quarantine */
         $quarantine = $Quarantine->getValue($SQL->Pool);
         $stranger = [
            'destroyed' => $destroyed,
            'statement' => [$Shared?->state->name, $Shared?->error, $Shared?->revoked],
            'scheduled' => count($Reactor->Scheduled),
            'quarantine' => count($quarantine),
         ] + $census($SQL);

         $drive($Owner, $Owner->resume(), $Peer);

         $state = [
            'held' => $held,
            'parked' => $parked instanceof Readiness,
            'stranger' => $stranger,
            'terminated' => $Owner->isTerminated(),
            'owner' => $Caught?->getMessage(),
            'scheduled' => count($Reactor->Scheduled),
            'queries' => $Peer->queries,
         ];

         yield assert(
            assertion: $state === [
               'held' => 'parked',
               'parked' => true,
               'stranger' => [
                  'destroyed' => true,
                  'statement' => ['Failed', $withdrawn, true],
                  'scheduled' => 0,
                  'quarantine' => 1,
                  'created' => 0,
                  'idle' => 0,
                  'busy' => 0,
                  'pending' => 0,
                  'locked' => 0,
               ],
               'terminated' => true,
               'owner' => 'SQL transaction is not active.',
               'scheduled' => 0,
               'queries' => ['BEGIN', 'SELECT 1'],
            ],
            description: 'transact(): a stranger context destroyed while awaiting the owner\'s statement withdraws it, adopts nothing, and the owner rolls back itself, found: ' . json_encode($state)
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
