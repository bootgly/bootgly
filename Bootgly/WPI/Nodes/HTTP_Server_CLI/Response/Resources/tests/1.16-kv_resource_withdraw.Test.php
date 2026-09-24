<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\KVWithdraw;


use function array_column;
use function array_count_values;
use function array_fill;
use function array_map;
use function assert;
use function count;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function gc_disable;
use function gc_enable;
use function gc_enabled;
use function in_array;
use function is_resource;
use function json_encode;
use function microtime;
use function range;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function strpos;
use function strrpos;
use function strtoupper;
use function substr;
use function usleep;
use Closure;
use Fiber;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use WeakReference;

use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\KV as KVDatabase;
use Bootgly\ADI\Databases\KV\Operation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\KV;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Timeout;


/**
 * In-process Redis peer on loopback. Plaintext, no password and database `0`
 * mean no preamble, so the handshake is the TCP dial alone. `silent` reads and
 * withholds every reply; answer() switches it to replying and answers what it
 * withheld. It acts only inside pump(), so the spec decides when it has spoken.
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
   /** @var array<int,int> Replies withheld per client */
   public array $owed = [];
   /** @var array<int,string> Commands received, in wire order (`VERB key`) */
   public array $commands = [];


   public function __construct (string $mode)
   {
      $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);

      if ($server === false) {
         throw new RuntimeException("Unable to open the Redis peer: {$message}");
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
         $this->owed[] = 0;
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
    * Start replying, and answer every command withheld so far.
    */
   public function answer (): void
   {
      $this->mode = 'answer';

      foreach ($this->clients as $id => $client) {
         for (; $this->owed[$id] > 0; $this->owed[$id]--) {
            if (is_resource($client)) {
               @fwrite($client, "\$5\r\nvalue\r\n");
            }
         }
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
    * Consume whole RESP command arrays (bulk strings) from one client buffer.
    */
   private function parse (int $id): void
   {
      // @@
      while (true) {
         $buffer = $this->buffers[$id];
         $end = strpos($buffer, "\r\n");

         if ($buffer === '' || $buffer[0] !== '*' || $end === false) {
            return;
         }

         $count = (int) substr($buffer, 1, $end - 1);
         $offset = $end + 2;
         $items = [];

         for ($item = 0; $item < $count; $item++) {
            $end = strpos($buffer, "\r\n", $offset);

            if ($end === false) {
               return;
            }

            $length = (int) substr($buffer, $offset + 1, $end - $offset - 1);
            $offset = $end + 2;

            if (strlen($buffer) < $offset + $length + 2) {
               return;
            }

            $items[] = substr($buffer, $offset, $length);
            $offset += $length + 2;
         }

         $this->buffers[$id] = substr($buffer, $offset);
         $this->commands[] = strtoupper($items[0] ?? '') . ' ' . ($items[1] ?? '');

         if ($this->mode === 'answer') {
            @fwrite($this->clients[$id], "\$5\r\nvalue\r\n");
         }
         else {
            $this->owed[$id]++;
         }
      }
   }
}


/**
 * KV pool whose withdraw() throws for the commands it is told to refuse — the
 * stand-in for any failure inside a withdrawal — and withdraws every other
 * one. It throws before touching a refused command, which stays exactly as it
 * was: in flight, its slot held. Each refusal is a fresh exception carrying a
 * cause of its own, so the spec can tell where PHP chains an exception the
 * refusal displaced.
 */
final class RefusingPool extends Pool
{
   // * Data
   /** @var array<int,string> Keys of the commands whose withdrawal fails */
   public array $refused = [];
   /** @var array<int,string> Keys of the commands withdraw() was asked for, in call order */
   public array $calls = [];
   public null|RuntimeException $Failure = null;


   public function withdraw (DatabaseOperation $Operation): DatabaseOperation
   {
      $key = $Operation instanceof Operation ? (string) ($Operation->arguments[0] ?? '') : '';
      $this->calls[] = $key;

      // ? Refused: fails before touching anything
      if (in_array($key, $this->refused, true)) {
         $this->Failure = new RuntimeException(
            "Withdrawal of the `{$key}` command failed.",
            previous: new RuntimeException('The driver could not reconcile the wire.')
         );

         throw $this->Failure;
      }

      // :
      return parent::withdraw($Operation);
   }
}


return new Test(
   description: 'Resources: a KV wait that never comes back withdraws its commands',
   test: function () {
      $withdrawn = 'Database operation was withdrawn: its caller stopped waiting.';
      $Rejection = new RuntimeException('Fiber I/O resource failed selector admission.');
      $Peers = [];

      /**
       * One KV facade per leg: plaintext Redis over the given peer.
       */
      $open = static function (Peer $Peer, int $max): KVDatabase {
         return new KVDatabase([
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => $Peer->port,
            'database' => '0',
            'timeout' => 5.0,
            'secure' => ['mode' => 'disable'],
            'pool' => ['min' => 0, 'max' => $max],
         ]);
      };

      // ! The Response bridge: a deferred response parks its Fiber on the token
      $suspend = static function (mixed $value = null): mixed {
         return Fiber::suspend($value);
      };

      /**
       * @return array<string,int>
       */
      $census = static function (KVDatabase $KV): array {
         $Pool = $KV->Pool;

         return [
            'created' => $Pool->created,
            'idle' => count($Pool->idle),
            'busy' => count($Pool->busy),
            'pending' => count($Pool->pending),
         ];
      };

      /**
       * @return array{0:null|string,1:bool,2:null|string}
       */
      $describe = static function (null|Operation $Operation): array {
         return [$Operation?->state->name, $Operation?->revoked ?? false, $Operation?->error];
      };

      /**
       * Drive one request Fiber the way the worker reactor does: pump the
       * peer, then resume the Fiber once its readiness signals. Stops when the
       * Fiber terminates (null), parks on something that is not a database
       * readiness (that value), or `$until` holds (the Readiness). Bounded:
       * `false` once `$cap` seconds pass.
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

            if ($until !== null && $until()) {
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

      // ? The command is on the wire: its reply is what the park waits for
      $reading = static function (null|Operation &$Operation): Closure {
         return static function () use (&$Operation): bool {
            return $Operation?->state === OperationStates::Reading;
         };
      };

      // ? Same, for a command the spec only watches: its handler alone holds
      //   it, so a strong reference here would mask what keeps it alive
      $wired = static function (null|WeakReference &$Weak): Closure {
         return static function () use (&$Weak): bool {
            $Operation = $Weak?->get();

            return $Operation instanceof Operation && $Operation->state === OperationStates::Reading;
         };
      };

      $close = static function (KVDatabase $KV): void {
         foreach ([...$KV->Pool->idle, ...$KV->Pool->busy] as $Connection) {
            $Connection->disconnect();
         }
      };

      $collectable = gc_enabled();

      try {
         // # (a) A rejected wait while the command is in flight
         //   The reactor refuses the park (Select::reject() throws into the
         //   Fiber): the caller gets that very exception, and the command —
         //   with the connection that owes its reply — is taken back at once.
         $Peer = $Peers[] = new Peer('silent');
         $KV = $open($Peer, 1);
         $Resource = new KV($KV)->schedule($suspend);

         $A = null;
         $Caught = null;
         $Fiber = new Fiber(static function () use ($Resource, &$A, &$Caught): void {
            $A = $Resource->command('GET', ['a']);

            try {
               $Resource->await($A);
            }
            catch (Throwable $Throwable) {
               $Caught = $Throwable;
            }
         });

         $Park = $drive($Fiber, $Fiber->start(), $Peer, $reading($A));
         $Fiber->throw($Rejection);

         yield assert(
            assertion: $Park instanceof Readiness && $Fiber->isTerminated() && $Caught === $Rejection,
            description: 'await(): the rejection thrown into the park reaches the caller as the same object'
         );

         $state = [
            'A' => $describe($A),
            'commands' => $Peer->commands,
         ] + $census($KV);

         yield assert(
            assertion: $state === [
               'A' => ['Failed', true, $withdrawn],
               'commands' => ['GET a'],
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'await(): the refused command is withdrawn and the connection owing its reply dropped, found: ' . json_encode($state)
         );

         // @ Redis drops a disconnected client's command with its session
         //   (`LINGERING` is false for KV): the one slot is free at once, and
         //   the next command dials instead of parking until A's deadline
         $Next = $KV->advance($KV->command('GET', ['next']));
         $state = [
            'Next' => $Next->state->name,
         ] + $census($KV);
         $KV->withdraw($Next);

         yield assert(
            assertion: $state === [
               'Next' => 'Connecting',
               'created' => 1,
               'idle' => 0,
               'busy' => 1,
               'pending' => 0,
            ],
            description: 'await(): the withdrawn command holds no slot past its dropped session, the next one dials at once, found: ' . json_encode($state)
         );

         $close($KV);

         // # (b) The Fiber is destroyed while parked in await()
         //   A client disconnect or an eviction drops the request Fiber: only
         //   its `finally` blocks run — refcount alone, no GC run needed.
         $Peer = $Peers[] = new Peer('silent');
         $KV = $open($Peer, 1);
         $Resource = new KV($KV)->schedule($suspend);

         $A = null;
         $Fiber = new Fiber(static function () use ($Resource, &$A): void {
            $A = $Resource->command('GET', ['a']);
            $Resource->await($A);
         });

         $Park = $drive($Fiber, $Fiber->start(), $Peer, $reading($A));
         $before = $census($KV);
         $Weak = WeakReference::create($Fiber);

         gc_disable();
         unset($Fiber);
         $destroyed = $Weak->get() === null;
         $after = $census($KV);
         if ($collectable) {
            gc_enable();
         }

         $state = [
            'parked' => $Park instanceof Readiness,
            'destroyed' => $destroyed,
            'A' => $describe($A),
            'before' => $before['busy'],
         ] + $after;

         yield assert(
            assertion: $state === [
               'parked' => true,
               'destroyed' => true,
               'A' => ['Failed', true, $withdrawn],
               'before' => 1,
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'await(): dropping the parked Fiber withdraws its command without gc_collect_cycles(), found: ' . json_encode($state)
         );

         $close($KV);

         // # (c) drain(): a rejected park withdraws the whole pipelined group
         //   One slot: the first command owns the wire, the second is
         //   co-located behind it once the connection is ready.
         $Peer = $Peers[] = new Peer('silent');
         $KV = $open($Peer, 1);
         $Resource = new KV($KV)->schedule($suspend);

         $A = null;
         $B = null;
         $Caught = null;
         $Fiber = new Fiber(static function () use ($Resource, &$A, &$B, &$Caught): void {
            $A = $Resource->command('GET', ['a']);
            $B = $Resource->command('GET', ['b']);

            try {
               $Resource->drain([$A, $B]);
            }
            catch (Throwable $Throwable) {
               $Caught = $Throwable;
            }
         });

         $Park = $drive($Fiber, $Fiber->start(), $Peer, $reading($A));
         $colocated = $B !== null && $B->Connection !== null && $B->Connection === $A?->Connection;
         $Fiber->throw($Rejection);

         $state = [
            'parked' => $Park instanceof Readiness,
            'colocated' => $colocated,
            'caught' => $Caught === $Rejection,
            'A' => $describe($A),
            'B' => $describe($B),
         ] + $census($KV);

         yield assert(
            assertion: $state === [
               'parked' => true,
               'colocated' => true,
               'caught' => true,
               'A' => ['Failed', true, $withdrawn],
               'B' => ['Failed', true, $withdrawn],
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'drain(): a rejected park withdraws both commands and frees the slot, found: ' . json_encode($state)
         );

         $close($KV);

         // # (d) Control: a wait that really waits keeps the command
         $Peer = $Peers[] = new Peer('answer');
         $KV = $open($Peer, 1);
         $Resource = new KV($KV)->schedule($suspend);

         $A = null;
         $Fiber = new Fiber(static function () use ($Resource, &$A): void {
            $A = $Resource->command('GET', ['a']);
            $Resource->await($A);
         });

         $drive($Fiber, $Fiber->start(), $Peer);

         $state = [
            'terminated' => $Fiber->isTerminated(),
            'A' => $describe($A),
            'response' => $A?->response,
         ] + $census($KV);

         yield assert(
            assertion: $state === [
               'terminated' => true,
               'A' => ['Finished', false, null],
               'response' => 'value',
               'created' => 1,
               'idle' => 1,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'Control: an answered wait resolves the command, unrevoked, and the connection goes back idle, found: ' . json_encode($state)
         );

         $close($KV);

         // # (f) Destruction withdraws an unawaited sibling
         //   The Fiber issued A and B and parks on A. Destroyed, it never runs
         //   again: every command it issued through the resource goes too.
         $Peer = $Peers[] = new Peer('silent');
         $KV = $open($Peer, 2);
         $Resource = new KV($KV)->schedule($suspend);

         $A = null;
         $B = null;
         $Fiber = new Fiber(static function () use ($Resource, &$A, &$B): void {
            $A = $Resource->command('GET', ['a']);
            $B = $Resource->command('GET', ['b']);
            $Resource->await($A);
         });

         $Park = $drive($Fiber, $Fiber->start(), $Peer, $reading($A));
         $before = $census($KV);
         $Weak = WeakReference::create($Fiber);

         gc_disable();
         unset($Fiber);
         $destroyed = $Weak->get() === null;
         $after = $census($KV);
         if ($collectable) {
            gc_enable();
         }

         $state = [
            'parked' => $Park instanceof Readiness,
            'destroyed' => $destroyed,
            'A' => $describe($A),
            'B' => $describe($B),
            'before' => $before['busy'],
         ] + $after;

         yield assert(
            assertion: $state === [
               'parked' => true,
               'destroyed' => true,
               'A' => ['Failed', true, $withdrawn],
               'B' => ['Failed', true, $withdrawn],
               'before' => 2,
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'Destruction: dropping a Fiber parked on A withdraws its unawaited sibling B too, found: ' . json_encode($state)
         );

         $close($KV);

         // # (g) An exception keeps the unawaited sibling
         //   The handler catches the rejection of A and keeps using B: only the
         //   awaited command goes, and B still gets its own reply.
         $Peer = $Peers[] = new Peer('silent');
         $KV = $open($Peer, 2);
         $Resource = new KV($KV)->schedule($suspend);

         $A = null;
         $B = null;
         $Caught = null;
         $kept = [];
         $Fiber = new Fiber(static function () use ($Resource, $KV, $census, $describe, &$A, &$B, &$Caught, &$kept): void {
            $A = $Resource->command('GET', ['a']);
            $B = $Resource->command('GET', ['b']);

            try {
               $Resource->await($A);
            }
            catch (Throwable $Throwable) {
               $Caught = $Throwable;
            }

            $kept = [
               'B' => [$B->finished, $B->revoked],
               'busy' => $census($KV)['busy'],
            ];

            $Resource->await($B);
         });

         $Park = $drive($Fiber, $Fiber->start(), $Peer, $reading($A));
         // @ The handler caught it and parked again, now on B
         $Next = $Fiber->throw($Rejection);
         $Peer->answer();
         $drive($Fiber, $Next, $Peer);

         $state = [
            'parked' => $Park instanceof Readiness,
            'caught' => $Caught === $Rejection,
            'A' => $describe($A),
            'kept' => $kept,
            'terminated' => $Fiber->isTerminated(),
            'B' => $describe($B),
            'response' => $B?->response,
         ];

         yield assert(
            assertion: $state === [
               'parked' => true,
               'caught' => true,
               'A' => ['Failed', true, $withdrawn],
               'kept' => [
                  'B' => [false, false],
                  'busy' => 1,
               ],
               'terminated' => true,
               'B' => ['Finished', false, null],
               'response' => 'value',
            ],
            description: 'Exception: only the awaited command is withdrawn, the unawaited sibling stays in flight and resolves, found: ' . json_encode($state)
         );

         $close($KV);

         // # (h) An uncaught deferral timeout, then the request ends
         //   The handler issues A and B and awaits A; the deferral budget
         //   elapses while A's reply is owed, and the Timeout is thrown into
         //   the park. The handler does not catch it: await() withdraws A
         //   alone and the Fiber ends with the exception, its locals with it.
         //   B — still dialing its own connection, never awaited — was held
         //   by nothing else (the driver holds a command only once it joins
         //   the pipeline): the resource's ledger alone keeps it alive. The
         //   response then drops its per-request resources: dropping the
         //   resource must withdraw B, or its slot stays taken for the
         //   worker's life. The spec watches both only through weak
         //   references, as a strong one would keep B alive by itself.
         $Peer = $Peers[] = new Peer('silent');
         $KV = $open($Peer, 2);
         $Resource = new KV($KV)->schedule($suspend);

         $WeakA = null;
         $WeakB = null;
         $awaited = null;
         $Fiber = new Fiber(static function () use ($Resource, $describe, &$WeakA, &$WeakB, &$awaited): void {
            $A = $Resource->command('GET', ['a']);
            $B = $Resource->command('GET', ['b']);
            $WeakA = WeakReference::create($A);
            $WeakB = WeakReference::create($B);

            try {
               $Resource->await($A);
            }
            finally {
               // @ Seen on the way out: the Timeout passes through uncaught
               $awaited = $describe($A);
            }

            $Resource->await($B);
         });

         $Park = $drive($Fiber, $Fiber->start(), $Peer, $wired($WeakA));
         $Timeout = new Timeout(1);
         $Uncaught = null;

         try {
            $Fiber->throw($Timeout);
         }
         catch (Throwable $Throwable) {
            $Uncaught = $Throwable;
         }

         // @ The ended Fiber stays referenced (a pooled Fiber is never
         //   destroyed), but its handler's locals are gone: only the
         //   resource still holds B, and only its drop can reach it now
         $left = [
            'B' => $describe($WeakB?->get()),
            'busy' => $census($KV)['busy'],
         ];
         $Weak = WeakReference::create($Resource);

         gc_disable();
         unset($Resource);
         $dropped = $Weak->get() === null;
         $freed = $WeakB?->get() === null;
         $after = $census($KV);
         if ($collectable) {
            gc_enable();
         }

         $state = [
            'parked' => $Park instanceof Readiness,
            'terminated' => $Fiber->isTerminated(),
            'uncaught' => $Uncaught === $Timeout,
            'A' => $awaited,
            'left' => $left,
            'dropped' => $dropped,
            'freed' => $freed,
            'commands' => $Peer->commands,
         ] + $after;

         yield assert(
            assertion: $state === [
               'parked' => true,
               'terminated' => true,
               'uncaught' => true,
               'A' => ['Failed', true, $withdrawn],
               'left' => [
                  'B' => ['Connecting', false, null],
                  'busy' => 1,
               ],
               'dropped' => true,
               'freed' => true,
               'commands' => ['GET a'],
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'Uncaught Timeout: the ledger alone keeps the unawaited sibling still dialing, and dropping the resource withdraws it, found: ' . json_encode($state)
         );

         // @ The next request on the same worker pool, the peer now answering
         $Peer->answer();
         $Resource = new KV($KV)->schedule($suspend);

         $C = null;
         $Fiber = new Fiber(static function () use ($Resource, &$C): void {
            $C = $Resource->command('GET', ['c']);
            $Resource->await($C);
         });

         $drive($Fiber, $Fiber->start(), $Peer);

         $state = [
            'terminated' => $Fiber->isTerminated(),
            'C' => $describe($C),
            'response' => $C?->response,
         ] + $census($KV);

         yield assert(
            assertion: $state === [
               'terminated' => true,
               'C' => ['Finished', false, null],
               'response' => 'value',
               'created' => 1,
               'idle' => 1,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'Uncaught Timeout: the pool is not wedged, a fresh command on it completes, found: ' . json_encode($state)
         );

         $close($KV);

         // # (i) The Fiber is destroyed while parked on another wait
         //   The handler issues A, then parks on something that is not a KV
         //   wait (an SQL query overlapping the command). A client disconnect
         //   drops the Fiber there: the `finally` that runs is not the KV
         //   resource's, and A — never awaited, still dialing, held in the
         //   handler's locals only — would go with the Fiber. The resource's
         //   ledger keeps it until the response drops the resource, which
         //   must withdraw it. Watched through a weak reference only.
         $Peer = $Peers[] = new Peer('silent');
         $KV = $open($Peer, 1);
         $Resource = new KV($KV)->schedule($suspend);

         $WeakA = null;
         $Fiber = new Fiber(static function () use ($Resource, &$WeakA): void {
            $A = $Resource->command('GET', ['a']);
            $WeakA = WeakReference::create($A);

            // @ A non-KV wait: another resource's readiness
            Fiber::suspend('elsewhere');

            $Resource->await($A);
         });

         $Park = $Fiber->start();
         $Weak = WeakReference::create($Fiber);
         $Held = WeakReference::create($Resource);

         gc_disable();
         unset($Fiber);
         $destroyed = $Weak->get() === null;
         $left = [
            'A' => $describe($WeakA?->get()),
            'busy' => $census($KV)['busy'],
         ];
         unset($Resource);
         $dropped = $Held->get() === null;
         $freed = $WeakA?->get() === null;
         $after = $census($KV);
         if ($collectable) {
            gc_enable();
         }

         $state = [
            'parked' => $Park,
            'destroyed' => $destroyed,
            'left' => $left,
            'dropped' => $dropped,
            'freed' => $freed,
            'commands' => $Peer->commands,
         ] + $after;

         yield assert(
            assertion: $state === [
               'parked' => 'elsewhere',
               'destroyed' => true,
               'left' => [
                  'A' => ['Connecting', false, null],
                  'busy' => 1,
               ],
               'dropped' => true,
               'freed' => true,
               'commands' => [],
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'Destroyed elsewhere: the ledger alone keeps the command its dead Fiber never awaited, and dropping the resource withdraws it, found: ' . json_encode($state)
         );

         $close($KV);

         // # (j) One resource, two Fibers: destruction takes only its own
         //   A user-mounted instance is carried unchanged into every deferred
         //   clone, so one resource serves several Fibers. F and G each issue
         //   a command through it and park awaiting it; dropping F withdraws
         //   what F issued, while G's command stays in flight and still gets
         //   its reply.
         $Peer = $Peers[] = new Peer('silent');
         $KV = $open($Peer, 2);
         $Resource = new KV($KV)->schedule($suspend);

         $A = null;
         $B = null;
         $F = new Fiber(static function () use ($Resource, &$A): void {
            $A = $Resource->command('GET', ['a']);
            $Resource->await($A);
         });
         $G = new Fiber(static function () use ($Resource, &$B): void {
            $B = $Resource->command('GET', ['b']);
            $Resource->await($B);
         });

         $Park = $drive($F, $F->start(), $Peer, $reading($A));
         $Wait = $drive($G, $G->start(), $Peer, $reading($B));
         $Weak = WeakReference::create($F);

         gc_disable();
         unset($F);
         $destroyed = $Weak->get() === null;
         $kept = [
            'B' => $describe($B),
            'busy' => $census($KV)['busy'],
         ];
         if ($collectable) {
            gc_enable();
         }

         $Peer->answer();
         $drive($G, $Wait, $Peer);

         $state = [
            'parked' => $Park instanceof Readiness && $Wait instanceof Readiness,
            'destroyed' => $destroyed,
            'A' => $describe($A),
            'kept' => $kept,
            'terminated' => $G->isTerminated(),
            'B' => $describe($B),
            'response' => $B?->response,
         ];

         yield assert(
            assertion: $state === [
               'parked' => true,
               'destroyed' => true,
               'A' => ['Failed', true, $withdrawn],
               'kept' => [
                  'B' => ['Reading', false, null],
                  'busy' => 1,
               ],
               'terminated' => true,
               'B' => ['Finished', false, null],
               'response' => 'value',
            ],
            description: 'Shared resource: dropping one Fiber withdraws only its command, the other Fiber\'s stays in flight and resolves, found: ' . json_encode($state)
         );

         $close($KV);

         // # (k) A command issued outside any Fiber, never awaited
         //   A synchronous route (no deferral, so no Fiber) fires a command
         //   and returns without awaiting it or keeping it. Still dialing,
         //   the command is held by the resource alone. When the response
         //   resets its per-request resources, dropping the resource must
         //   withdraw it: nobody else ever will, and its slot would stay
         //   taken for the worker's life.
         $Peer = $Peers[] = new Peer('silent');
         $KV = $open($Peer, 1);
         $Resource = new KV($KV)->schedule($suspend);

         $outside = Fiber::getCurrent() === null;
         $WeakA = WeakReference::create($Resource->command('GET', ['a']));
         $left = [
            'A' => $describe($WeakA->get()),
            'busy' => $census($KV)['busy'],
         ];
         $Held = WeakReference::create($Resource);

         gc_disable();
         unset($Resource);
         $dropped = $Held->get() === null;
         $freed = $WeakA->get() === null;
         $after = $census($KV);
         if ($collectable) {
            gc_enable();
         }

         $state = [
            'outside' => $outside,
            'left' => $left,
            'dropped' => $dropped,
            'freed' => $freed,
            'commands' => $Peer->commands,
         ] + $after;

         yield assert(
            assertion: $state === [
               'outside' => true,
               'left' => [
                  'A' => ['Connecting', false, null],
                  'busy' => 1,
               ],
               'dropped' => true,
               'freed' => true,
               'commands' => [],
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'Outside a Fiber: the resource keeps the command nobody awaited, and dropping it withdraws the command, found: ' . json_encode($state)
         );

         $close($KV);

         // # (l) A long-lived instance keeps its ledger bounded
         //   A user-mounted resource outlives every request it serves, so its
         //   ledger must not keep what already finished — yet sweeping it on
         //   every command() costs a pipeline of n commands O(n²). The sweep
         //   runs only once the ledger reaches its watermark (16 at first),
         //   keeps exactly the unfinished entries, and sets the watermark to
         //   twice what survived (never below 16): the ledger never holds
         //   more than max(16, 2 × the unfinished entries of its last sweep).
         //   First N = 40 requests each issue a command and await it to the
         //   end: the ledger grows to 16, and the 17th and 33rd commands
         //   sweep it empty before joining it. Then the peer falls silent and
         //   24 commands issued outside any Fiber stay on the wire: the 9th
         //   sweeps out the 8 finished entries and keeps the 8 in flight, the
         //   17th keeps all 16 and doubles the watermark to 32. Dropping the
         //   instance withdraws every command still in flight.
         $Peer = $Peers[] = new Peer('answer');
         $KV = $open($Peer, 1);
         $Resource = new KV($KV)->schedule($suspend);
         $Ledger = new ReflectionProperty(KV::class, 'Operations');
         $Watermark = new ReflectionProperty(KV::class, 'watermark');

         $rounds = 40;
         $sizes = [];
         $watermarks = [];
         $answered = 0;

         // @@
         for ($round = 0; $round < $rounds; $round++) {
            $Fiber = new Fiber(static function () use ($Resource, $Ledger, $Watermark, $round, &$sizes, &$watermarks, &$answered): void {
               $Operation = $Resource->command('GET', ["k{$round}"]);
               $sizes[] = count($Ledger->getValue($Resource));
               $watermarks[] = $Watermark->getValue($Resource);

               $Resource->await($Operation);

               if ($Operation->response === 'value') {
                  $answered++;
               }
            });

            $drive($Fiber, $Fiber->start(), $Peer);
         }

         $finished = [
            'answered' => $answered,
            'sizes' => $sizes,
            'watermarks' => $watermarks,
         ];

         // @ The peer falls silent: every next command stays on the wire
         $Peer->mode = 'silent';
         $Unanswered = [];
         $sizes = [];
         $watermarks = [];
         $swept = [];

         // @@
         for ($round = 0; $round < 24; $round++) {
            $Unanswered[] = $Resource->command('GET', ["u{$round}"]);
            $sizes[] = count($Ledger->getValue($Resource));
            $watermarks[] = $Watermark->getValue($Resource);

            // ? Right after the two sweeps: the ledger is exactly the commands in flight
            if ($round === 8 || $round === 16) {
               $swept[] = array_column($Ledger->getValue($Resource), 0) === $Unanswered;
            }
         }

         $Entries = array_column($Ledger->getValue($Resource), 0);
         $ledger = [
            'entries' => count($Entries),
            'kept' => $Entries === $Unanswered,
            'states' => array_count_values(array_map(static fn (Operation $Operation): string => $Operation->state->name, $Entries)),
         ];
         $Held = WeakReference::create($Resource);

         gc_disable();
         unset($Resource, $Entries, $Fiber);
         $dropped = $Held->get() === null;
         $after = $census($KV);
         if ($collectable) {
            gc_enable();
         }

         $state = [
            'finished' => $finished,
            'unanswered' => [
               'sizes' => $sizes,
               'watermarks' => $watermarks,
               'swept' => $swept,
            ],
            'ledger' => $ledger,
            'dropped' => $dropped,
            'withdrawn' => array_count_values(array_map(static fn (Operation $Operation): string => json_encode($describe($Operation)), $Unanswered)),
         ] + $after;

         yield assert(
            assertion: $state === [
               'finished' => [
                  'answered' => $rounds,
                  'sizes' => [...range(1, 16), ...range(1, 16), ...range(1, 8)],
                  'watermarks' => array_fill(0, $rounds, 16),
               ],
               'unanswered' => [
                  'sizes' => [...range(9, 16), ...range(9, 16), ...range(17, 24)],
                  'watermarks' => [...array_fill(0, 16, 16), ...array_fill(0, 8, 32)],
                  'swept' => [true, true],
               ],
               'ledger' => [
                  'entries' => 24,
                  'kept' => true,
                  'states' => ['Reading' => 24],
               ],
               'dropped' => true,
               'withdrawn' => [json_encode(['Failed', true, $withdrawn]) => 24],
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'Long-lived instance: the ledger is swept at its watermark, only the commands in flight survive, the watermark doubles with them, and the drop withdraws them, found: ' . json_encode($state)
         );

         $close($KV);

         // # (m) A withdrawal that fails as the resource goes
         //   The request ends with two commands still dialing, and the pool
         //   fails to take the first one back. Dropping the resource is the
         //   response releasing it: nobody is left to hear that failure, so
         //   the drop must not throw — and the failure must not cost the
         //   others: the second command is still withdrawn, its connection
         //   given back. The refused one stays as the pool left it.
         $Peer = $Peers[] = new Peer('silent');
         $KV = $open($Peer, 2);
         $Refusing = new RefusingPool($KV->Config, $KV->Connection, $KV->drivers);
         $Refusing->refused = ['a'];
         $KV->Pool = $Refusing;
         $Resource = new KV($KV)->schedule($suspend);

         $A = $Resource->command('GET', ['a']);
         $B = $Resource->command('GET', ['b']);
         $left = [
            'A' => $describe($A),
            'B' => $describe($B),
            'busy' => $census($KV)['busy'],
         ];
         $Held = WeakReference::create($Resource);
         $Escaped = null;

         gc_disable();
         try {
            unset($Resource);
         }
         catch (Throwable $Throwable) {
            $Escaped = $Throwable;
         }
         $dropped = $Held->get() === null;
         $after = $census($KV);
         if ($collectable) {
            gc_enable();
         }

         $state = [
            'left' => $left,
            'escaped' => $Escaped?->getMessage(),
            'dropped' => $dropped,
            'refused' => $Refusing->Failure !== null,
            'calls' => $Refusing->calls,
            'A' => $describe($A),
            'B' => $describe($B),
         ] + $after;

         yield assert(
            assertion: $state === [
               'left' => [
                  'A' => ['Connecting', false, null],
                  'B' => ['Connecting', false, null],
                  'busy' => 2,
               ],
               'escaped' => null,
               'dropped' => true,
               'refused' => true,
               'calls' => ['a', 'b'],
               'A' => ['Connecting', false, null],
               'B' => ['Failed', true, $withdrawn],
               'created' => 1,
               'idle' => 0,
               'busy' => 1,
               'pending' => 0,
            ],
            description: 'Failed withdrawal on the drop: nothing escapes the drop, and the command after the refused one is still withdrawn, found: ' . json_encode($state)
         );

         $close($KV);

         // # (n) A withdrawal that fails under a deferral timeout
         //   The handler awaits A when its deferral budget elapses: the
         //   Timeout is thrown into the park, and await() withdraws A on its
         //   way out — which the pool fails to do. That failure is what
         //   reaches the handler, with the Timeout it displaced chained at the
         //   END of its getPrevious() chain, past the failure's own cause:
         //   neither is lost. A stays in flight, and the resource still holds
         //   it: once the pool can take it back, the drop withdraws it.
         $Peer = $Peers[] = new Peer('silent');
         $KV = $open($Peer, 1);
         $Refusing = new RefusingPool($KV->Config, $KV->Connection, $KV->drivers);
         $Refusing->refused = ['a'];
         $KV->Pool = $Refusing;
         $Resource = new KV($KV)->schedule($suspend);

         $A = null;
         $Caught = null;
         $Fiber = new Fiber(static function () use ($Resource, &$A, &$Caught): void {
            $A = $Resource->command('GET', ['a']);

            try {
               $Resource->await($A);
            }
            catch (Throwable $Throwable) {
               $Caught = $Throwable;
            }
         });

         $Park = $drive($Fiber, $Fiber->start(), $Peer, $reading($A));
         $Timeout = new Timeout(1);
         $Fiber->throw($Timeout);

         // ! What the handler caught, then its getPrevious() chain, in order
         $Chain = [];

         // @@
         for ($Link = $Caught; $Link !== null; $Link = $Link->getPrevious()) {
            $Chain[] = $Link;
         }

         $state = [
            'parked' => $Park instanceof Readiness,
            'terminated' => $Fiber->isTerminated(),
            'caught' => $Caught !== null && $Caught === $Refusing->Failure,
            'last' => ($Chain[count($Chain) - 1] ?? null) === $Timeout,
            'chain' => array_map(static fn (Throwable $Link): string => $Link->getMessage(), $Chain),
         ];

         yield assert(
            assertion: $state === [
               'parked' => true,
               'terminated' => true,
               'caught' => true,
               'last' => true,
               'chain' => [
                  'Withdrawal of the `a` command failed.',
                  'The driver could not reconcile the wire.',
                  $Timeout->getMessage(),
               ],
            ],
            description: 'await(): a withdrawal failing under a Timeout reaches the handler, the Timeout at the end of its getPrevious() chain, found: ' . json_encode($state)
         );

         // @ A is still in flight and still the resource's: once the pool can
         //   take it back, the response dropping the resource withdraws it
         $kept = [
            'A' => $describe($A),
            'calls' => $Refusing->calls,
         ] + $census($KV);
         $Refusing->refused = [];
         $Held = WeakReference::create($Resource);

         gc_disable();
         unset($Resource);
         $dropped = $Held->get() === null;
         $after = $census($KV);
         if ($collectable) {
            gc_enable();
         }

         $state = [
            'kept' => $kept,
            'dropped' => $dropped,
            'calls' => $Refusing->calls,
            'A' => $describe($A),
         ] + $after;

         yield assert(
            assertion: $state === [
               'kept' => [
                  'A' => ['Reading', false, null],
                  'calls' => ['a'],
                  'created' => 1,
                  'idle' => 0,
                  'busy' => 1,
                  'pending' => 0,
               ],
               'dropped' => true,
               'calls' => ['a', 'a'],
               'A' => ['Failed', true, $withdrawn],
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'await(): the command a failed withdrawal left in flight stays the resource\'s, and its drop takes it back, found: ' . json_encode($state)
         );

         $close($KV);

         // # (o) A command whose reply already arrived is finished, not withdrawn
         //   A deferred job fires a command it never awaits: it is on the
         //   wire, the server answers at once, and the reply sits unread in
         //   the socket when the job ends. The response then cleans the
         //   resource: one non-blocking read finishes the command honestly —
         //   the server did run it — and its connection goes back idle, still
         //   up. The instance stays mounted and serves the next request on
         //   that same connection, with no new dial; and a command left the
         //   same way when the resource goes away is finished on the drop.
         $Peer = $Peers[] = new Peer('answer');
         $KV = $open($Peer, 1);
         $Resource = new KV($KV)->schedule($suspend);
         $Ledger = new ReflectionProperty(KV::class, 'Operations');

         /**
          * Let the peer answer, then wait (bounded) until the reply is
          * readable on the client socket — nothing advances the command.
          */
         $arrive = static function (null|Operation $Operation) use ($Peer): bool {
            $socket = $Operation?->Connection?->socket;
            $deadline = microtime(true) + 2.0;

            // @@
            while (is_resource($socket) && microtime(true) < $deadline) {
               $Peer->pump();

               $read = [$socket];
               $write = [];
               $except = [];

               if (@stream_select($read, $write, $except, 0, 20000) > 0) {
                  return true;
               }
            }

            return false;
         };

         // @ A first request dials the connection and resolves on it
         $Fiber = new Fiber(static function () use ($Resource): void {
            $Resource->await($Resource->command('GET', ['warm']));
         });
         $drive($Fiber, $Fiber->start(), $Peer);

         // @ The deferred job: fires A and ends without awaiting it
         $A = null;
         $Fiber = new Fiber(static function () use ($Resource, &$A): void {
            $A = $Resource->command('GET', ['a']);
         });
         $Fiber->start();

         $Connection = $A?->Connection;
         $left = [
            'ended' => $Fiber->isTerminated(),
            'buffered' => $arrive($A),
            'A' => $describe($A),
         ];

         // @ The job ends: the response cleans the resource it keeps mounted
         $Resource->clean();
         $Peer->pump();

         $state = [
            'left' => $left,
            'A' => $describe($A),
            'response' => $A?->response,
            'up' => $Connection?->connected,
            'pooled' => in_array($Connection, $KV->Pool->idle, true),
            'open' => is_resource($Peer->clients[0] ?? null),
            'ledger' => count($Ledger->getValue($Resource)),
         ] + $census($KV);

         yield assert(
            assertion: $state === [
               'left' => [
                  'ended' => true,
                  'buffered' => true,
                  'A' => ['Reading', false, null],
               ],
               'A' => ['Finished', false, null],
               'response' => 'value',
               'up' => true,
               'pooled' => true,
               'open' => true,
               'ledger' => 0,
               'created' => 1,
               'idle' => 1,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'clean(): an unawaited command whose reply arrived is finished, unrevoked, and its connection stays up and idle, found: ' . json_encode($state)
         );

         // @ The next request, through the same mounted instance
         $B = null;
         $Fiber = new Fiber(static function () use ($Resource, &$B): void {
            $B = $Resource->command('GET', ['b']);
            $Resource->await($B);
         });
         $drive($Fiber, $Fiber->start(), $Peer);

         $next = [
            'B' => $describe($B),
            'response' => $B?->response,
            'dials' => count($Peer->clients),
         ];

         // @ Then a command left the same way when the resource goes away
         $C = $Resource->command('GET', ['c']);
         $left = [
            'buffered' => $arrive($C),
            'C' => $describe($C),
         ];
         $Held = WeakReference::create($Resource);

         gc_disable();
         unset($Resource, $Fiber);
         $dropped = $Held->get() === null;
         $Peer->pump();
         $after = $census($KV);
         if ($collectable) {
            gc_enable();
         }

         $state = [
            'next' => $next,
            'left' => $left,
            'dropped' => $dropped,
            'C' => $describe($C),
            'response' => $C->response,
            'up' => $Connection?->connected,
            'open' => is_resource($Peer->clients[0] ?? null),
            'dials' => count($Peer->clients),
            'commands' => $Peer->commands,
         ] + $after;

         yield assert(
            assertion: $state === [
               'next' => [
                  'B' => ['Finished', false, null],
                  'response' => 'value',
                  'dials' => 1,
               ],
               'left' => [
                  'buffered' => true,
                  'C' => ['Reading', false, null],
               ],
               'dropped' => true,
               'C' => ['Finished', false, null],
               'response' => 'value',
               'up' => true,
               'open' => true,
               'dials' => 1,
               'commands' => ['GET warm', 'GET a', 'GET b', 'GET c'],
               'created' => 1,
               'idle' => 1,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'clean(): the mounted instance serves the next request on the same connection, and the drop finishes an answered command too, found: ' . json_encode($state)
         );

         $close($KV);

         // # (p) A withdrawal that fails while the Fiber is being destroyed
         //   The handler awaits A when a client disconnect drops its Fiber:
         //   PHP unwinds it running only `finally` blocks, and await()
         //   withdraws A on the way out — which the pool fails to do. No
         //   exception is in flight and nobody is left to report to: the
         //   failure must not replace the unwinding, or the handler's own
         //   `catch` would run inside a Fiber that can no longer suspend, and
         //   what it rethrows would escape the Fiber's destruction. A stays
         //   in flight and the resource's: once the pool can take it back,
         //   the drop withdraws it.
         $Peer = $Peers[] = new Peer('silent');
         $KV = $open($Peer, 1);
         $Refusing = new RefusingPool($KV->Config, $KV->Connection, $KV->drivers);
         $Refusing->refused = ['a'];
         $KV->Pool = $Refusing;
         $Resource = new KV($KV)->schedule($suspend);

         $A = null;
         $Caught = null;
         $unwound = false;
         $continued = false;
         $Fiber = new Fiber(static function () use ($Resource, &$A, &$Caught, &$unwound, &$continued): void {
            $A = $Resource->command('GET', ['a']);

            try {
               $Resource->await($A);
            }
            catch (Throwable $Throwable) {
               $Caught = $Throwable;

               throw $Throwable;
            }
            finally {
               $unwound = true;
            }

            $continued = true;
         });

         $Park = $drive($Fiber, $Fiber->start(), $Peer, $reading($A));
         $Weak = WeakReference::create($Fiber);
         $Escaped = null;

         gc_disable();
         try {
            unset($Fiber);
         }
         catch (Throwable $Throwable) {
            $Escaped = $Throwable;
         }
         $destroyed = $Weak->get() === null;
         $after = $census($KV);
         if ($collectable) {
            gc_enable();
         }

         $state = [
            'parked' => $Park instanceof Readiness,
            'destroyed' => $destroyed,
            'escaped' => $Escaped?->getMessage(),
            'caught' => $Caught?->getMessage(),
            'unwound' => $unwound,
            'continued' => $continued,
            'refused' => $Refusing->Failure !== null,
            'calls' => $Refusing->calls,
            'A' => $describe($A),
         ] + $after;

         yield assert(
            assertion: $state === [
               'parked' => true,
               'destroyed' => true,
               'escaped' => null,
               'caught' => null,
               'unwound' => true,
               'continued' => false,
               'refused' => true,
               'calls' => ['a'],
               'A' => ['Reading', false, null],
               'created' => 1,
               'idle' => 0,
               'busy' => 1,
               'pending' => 0,
            ],
            description: 'Destruction: a withdrawal failing in the dying Fiber runs no handler catch and nothing escapes its destruction, found: ' . json_encode($state)
         );

         // @ The pool can take A back now: the response dropping the resource does
         $Refusing->refused = [];
         $Held = WeakReference::create($Resource);

         gc_disable();
         unset($Resource);
         $dropped = $Held->get() === null;
         $after = $census($KV);
         if ($collectable) {
            gc_enable();
         }

         $state = [
            'dropped' => $dropped,
            'calls' => $Refusing->calls,
            'A' => $describe($A),
         ] + $after;

         yield assert(
            assertion: $state === [
               'dropped' => true,
               'calls' => ['a', 'a'],
               'A' => ['Failed', true, $withdrawn],
               'created' => 0,
               'idle' => 0,
               'busy' => 0,
               'pending' => 0,
            ],
            description: 'Destruction: the command the failed withdrawal left in flight stays the resource\'s, and its drop takes it back, found: ' . json_encode($state)
         );

         $close($KV);
      }
      finally {
         if ($collectable) {
            gc_enable();
         }

         foreach ($Peers as $Open) {
            $Open->close();
         }
      }
   }
);
