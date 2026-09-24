<?php


use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\KV as KVDatabase;
use Bootgly\ADI\Databases\KV\Drivers\Redis;
use Bootgly\ADI\Databases\SQL;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events as WPIEvents;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\KV;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Timeout;


/**
 * A deferred response parked on a dependency (KV or SQL) that stops waiting
 * gives the pool slot back at once — whatever ended the wait: the selector
 * refused the wait, the client disconnected (the reactor evicts and reaps the
 * Fiber, so only its finally blocks run), or the response budget interrupted
 * it. Nothing is sent to the server: the operation is withdrawn locally.
 *
 * A withdrawal can tear the dependency session down under a sibling Fiber
 * still registered on the same socket — in the read table (a pipelined reply)
 * or in the write table (a command queued behind the half-written frame). The
 * reactor must drop that closed descriptor and resume its waiter, never
 * recycle the worker because stream_select() keeps failing on the admitted set.
 *
 * Fake peers only: a loopback listener that is never accepted — the kernel
 * completes the handshake and nobody ever answers.
 */
return new Test(
   description: 'Deferred KV/SQL waits that stop waiting should withdraw their operations and free the pool slot without poisoning the reactor',
   test: new Assertions(Case: function (): Generator {
      $Reflection = new ReflectionClass(Select::class);
      $Reads = $Reflection->getProperty('reads');
      $Reading = $Reflection->getProperty('reading');
      $Writes = $Reflection->getProperty('writes');
      $Await = $Reflection->getProperty('awaitingReads');
      $AwaitWrites = $Reflection->getProperty('awaitingWrites');
      $Writer = new ReflectionProperty(Redis::class, 'Writing');

      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $Sockets = [];
      $Selects = [];

      $refusal = 'Fiber I/O resource failed selector admission.';
      $withdrawal = 'Database operation was withdrawn: its caller stopped waiting.';

      // ! A dependency peer that never answers: listening, never accepted
      $listen = static function () use (&$Sockets): int {
         $Server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
         if ($Server === false) {
            throw new RuntimeException("Withdrawal fixture could not listen: {$error}");
         }
         $Sockets[] = $Server;

         $address = (string) stream_socket_get_name($Server, false);

         return (int) substr($address, (int) strrpos($address, ':') + 1);
      };
      $redis = static fn (int $port): KVDatabase => new KVDatabase([
         'driver' => 'redis',
         'host' => '127.0.0.1',
         'port' => $port,
         'database' => '0',
         'timeout' => 5.0,
         'secure' => ['mode' => 'disable'],
         'pool' => ['min' => 0, 'max' => 1],
      ]);
      $postgres = static fn (int $port): SQL => new SQL([
         'driver' => 'pgsql',
         'host' => '127.0.0.1',
         'port' => $port,
         'timeout' => 5.0,
         'secure' => ['mode' => 'disable'],
         'pool' => ['min' => 0, 'max' => 1],
      ]);
      // ! The worker reactor — also the one Database::wake() arms completion
      //   edges through, as in a worker
      $reactor = static function () use (&$Selects): Select {
         $Connections = new class implements Connections {
            public function connect (): bool
            {
               return false;
            }
         };
         $Select = new Select($Connections);
         $Selects[] = $Select;
         TCP_Server_CLI::$Event = $Select;

         return $Select;
      };
      // ! Response::wait()'s effective body: the resource bridge suspends
      $bridge = static function (mixed $value = null): void {
         Fiber::suspend($value);
      };
      // ! A deferred handler: what it caught, and that its finally ran
      $handle = static function (Closure $work, array &$log, null|Select $Stop = null): Fiber {
         return new Fiber(static function () use ($work, &$log, $Stop): void {
            try {
               $work();
               $log[] = 'returned';
            }
            catch (Throwable $Throwable) {
               $log[] = "caught: {$Throwable->getMessage()}";
            }
            finally {
               $log[] = 'finally';

               if ($Stop !== null) {
                  $Stop->loop = false; // @phpstan-ignore-line (property on the Select impl)
               }
            }
         });
      };
      // ! Park a Fiber exactly as defer() does: open its generation, bind it,
      //   schedule its first suspension
      $park = static function (Select $Select, Fiber $Fiber, mixed $value): array {
         $Token = Cancellation::open($Fiber);
         $Select->bind($Fiber, static function (): void {}, static function () use ($Fiber): void {});

         return [$Token, $Select->schedule($Fiber, $value)];
      };
      // ! Bounded run: short reactor slices, each ended by a deferred stop,
      //   until the leg's outcome is reached or the hard deadline passes — a
      //   stalled host costs one more slice, never a false verdict. What
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
      $describe = static fn (mixed $value): string => $value instanceof Readiness
         ? ($value->flag === Select::SCHEDULE_READ ? 'read' : 'write')
         : get_debug_type($value);
      $withdrawn = static fn (null|Operation $Operation): bool => $Operation !== null
         && $Operation->finished
         && $Operation->error === $withdrawal
         && $Operation->revoked;

      try {
         // @@ A) Selector admission refused: the read table is saturated by
         //    client stand-ins, and the dependency wait cannot be seated
         $Select = $reactor();
         $Payload = new class {
            public function reading (mixed $Socket): void {}
            public function writing (mixed $Socket): void {}
         };
         // ! Two real client stand-ins seated through add(): an idle pair
         //   that never turns readable
         $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
         if ($pair === false) {
            throw new RuntimeException('Withdrawal fixture could not allocate a socket pair.');
         }
         $Sockets[] = $pair[0];
         $Sockets[] = $pair[1];
         $seated = $Select->add($pair[0], WPIEvents::EVENT_READ, $Payload)
            && $Select->add($pair[1], WPIEvents::EVENT_READ, $Payload);
         // ! The rest of the table: synthetic stand-ins on that pair, under
         //   keys no resource id takes — no descriptor per entry, so the
         //   descriptors the host already holds never push a stand-in past
         //   FD_SETSIZE (only the pair itself must sit below it)
         $reads = $Reads->getValue($Select);
         $Payloads = $Reading->getValue($Select);
         for ($key = -1; count($reads) < Select::CAPACITY; $key--) {
            $reads[$key] = $pair[0];
            $Payloads[$key] = $Payload;
         }
         $Reads->setValue($Select, $reads);
         $Reading->setValue($Select, $Payloads);
         $saturated = count($Reads->getValue($Select));
         $descriptors = count((array) scandir('/proc/self/fd')) - 2;

         yield assert(
            assertion: $seated === true && $saturated === Select::CAPACITY,
            description: 'the read table holds exactly Select::CAPACITY client stand-ins: two idle sockets seated by add(), the rest synthetic entries on them — observed: '
               . json_encode(['seated' => $seated, 'reads' => $saturated, 'capacity' => Select::CAPACITY, 'open_descriptors' => $descriptors])
         );

         // @ A-1) KV: refused synchronously by schedule()
         $KV = $redis($listen());
         $Resource = new KV($KV)->schedule($bridge);
         $log = [];
         $Command = null;
         $Fiber = $handle(static function () use ($Resource, &$Command): void {
            $Command = $Resource->command('GET', ['key']);
            $Resource->await($Command);
         }, $log);
         $suspended = $Fiber->start();
         $Socket = $suspended instanceof Readiness ? $suspended->socket : null;
         [, $scheduled] = $park($Select, $Fiber, $suspended);

         yield assert(
            assertion: $describe($suspended) === 'read'
               && $scheduled === false
               && $log === ["caught: {$refusal}", 'finally']
               && $withdrawn($Command)
               && $census($KV->Pool) === $idle
               && is_resource($Socket) === false
               && count($Reads->getValue($Select)) === Select::CAPACITY,
            description: 'a KV wait refused by a saturated selector delivers the refusal and withdraws the command: slot freed, session closed, no seat leaked — observed: '
               . json_encode(['parked' => $describe($suspended), 'scheduled' => $scheduled, 'log' => $log, 'error' => $Command?->error, 'revoked' => $Command?->revoked, 'pool' => $census($KV->Pool), 'reads' => count($Reads->getValue($Select))])
         );

         // @ A-2) SQL: the connect wait is seated (write table), the startup
         //   read is refused when the reactor resumes it
         $SQL = $postgres($listen());
         $Resource = new Database($SQL)->schedule($bridge);
         $log = [];
         $Query = null;
         $Fiber = $handle(static function () use ($Resource, $SQL, &$Query): void {
            $Query = $SQL->query('SELECT 1');
            $Resource->await($Query);
         }, $log, $Select);
         $suspended = $Fiber->start();
         [, $scheduled] = $park($Select, $Fiber, $suspended);
         $escaped = $Fiber->isTerminated() ? null : $run($Select, static fn (): bool => $Fiber->isTerminated(), 2.0);

         yield assert(
            assertion: $escaped === null
               && $log === ["caught: {$refusal}", 'finally']
               && $withdrawn($Query)
               && $census($SQL->Pool) === $idle
               && count($Reads->getValue($Select)) === Select::CAPACITY,
            description: 'an SQL wait refused by a saturated selector delivers the refusal and withdraws the query: slot freed — observed: '
               . json_encode(['parked' => $describe($suspended), 'scheduled' => $scheduled, 'escaped' => $escaped, 'log' => $log, 'error' => $Query?->error, 'pool' => $census($SQL->Pool), 'reads' => count($Reads->getValue($Select))])
         );
         $Select->destroy();

         // @@ B) Client disconnect: the generation is cancelled, the reactor
         //    evicts the parked Fiber and reaps it — only its finally runs
         $Select = $reactor();
         $KV = $redis($listen());
         $SQL = $postgres($listen());
         $KVResource = new KV($KV)->schedule($bridge);
         $SQLResource = new Database($SQL)->schedule($bridge);
         $logs = ['kv' => [], 'sql' => []];
         $Command = null;
         $Query = null;
         $Handlers = [
            'kv' => $handle(static function () use ($KVResource, &$Command): void {
               $Command = $KVResource->command('GET', ['key']);
               $KVResource->await($Command);
            }, $logs['kv']),
            'sql' => $handle(static function () use ($SQLResource, $SQL, &$Query): void {
               $Query = $SQL->query('SELECT 1');
               $SQLResource->await($Query);
            }, $logs['sql']),
         ];
         $Tokens = [];
         $Weaks = [];
         $parked = [];
         foreach ($Handlers as $name => $Fiber) {
            $suspended = $Fiber->start();
            [$Tokens[$name], $parked[$name]] = $park($Select, $Fiber, $suspended);
            $Weaks[$name] = WeakReference::create($Fiber);
         }
         unset($Handlers, $Fiber);
         $taken = [$census($KV->Pool)['busy'], $census($SQL->Pool)['busy']];

         // @ Ownership::close() on a client disconnect
         foreach ($Tokens as $Token) {
            $Token->disconnect();
         }
         $escaped = $run($Select, static fn (): bool => $Weaks['kv']->get() === null && $Weaks['sql']->get() === null, 2.0);

         yield assert(
            assertion: $parked === ['kv' => true, 'sql' => true]
               && $taken === [1, 1]
               && $escaped === null
               && $logs === ['kv' => ['finally'], 'sql' => ['finally']]
               && $Weaks['kv']->get() === null && $Weaks['sql']->get() === null
               && $withdrawn($Command) && $withdrawn($Query)
               && $census($KV->Pool) === $idle
               && $census($SQL->Pool) === $idle,
            description: 'a parked KV/SQL Fiber evicted on client disconnect withdraws its operation from its finally: both slots freed, catch never ran — observed: '
               . json_encode(['parked' => $parked, 'taken' => $taken, 'escaped' => $escaped, 'logs' => $logs, 'kv' => $census($KV->Pool), 'sql' => $census($SQL->Pool), 'errors' => [$Command?->error, $Query?->error]])
         );
         $Select->destroy();

         // @@ C) The deferral budget: a Timeout delivered at the wait point
         $Select = $reactor();
         $KV = $redis($listen());
         $SQL = $postgres($listen());
         $KVResource = new KV($KV)->schedule($bridge);
         $SQLResource = new Database($SQL)->schedule($bridge);
         $logs = ['kv' => [], 'sql' => []];
         $Command = null;
         $Query = null;
         $Handlers = [
            'kv' => $handle(static function () use ($KVResource, &$Command): void {
               $Command = $KVResource->command('GET', ['key']);
               $KVResource->await($Command);
            }, $logs['kv']),
            'sql' => $handle(static function () use ($SQLResource, $SQL, &$Query): void {
               $Query = $SQL->query('SELECT 1');
               $SQLResource->await($Query);
            }, $logs['sql']),
         ];
         $interrupted = [];
         foreach ($Handlers as $name => $Fiber) {
            $park($Select, $Fiber, $Fiber->start());
         }
         $taken = [$census($KV->Pool)['busy'], $census($SQL->Pool)['busy']];
         foreach ($Handlers as $name => $Fiber) {
            $interrupted[$name] = $Select->interrupt($Fiber, new Timeout(0.5));
         }
         $budget = 'caught: HTTP deferred response exceeded its 0.5s budget.';

         yield assert(
            assertion: $interrupted === ['kv' => true, 'sql' => true]
               && $taken === [1, 1]
               && $logs === ['kv' => [$budget, 'finally'], 'sql' => [$budget, 'finally']]
               && $Handlers['kv']->isTerminated() && $Handlers['sql']->isTerminated()
               && $withdrawn($Command) && $withdrawn($Query)
               && $census($KV->Pool) === $idle
               && $census($SQL->Pool) === $idle,
            description: 'a KV/SQL wait interrupted by the deferral budget withdraws its operation before the handler answers: both slots freed — observed: '
               . json_encode(['interrupted' => $interrupted, 'taken' => $taken, 'logs' => $logs, 'kv' => $census($KV->Pool), 'sql' => $census($SQL->Pool), 'errors' => [$Command?->error, $Query?->error]])
         );
         unset($Handlers, $Fiber);
         $Select->destroy();

         // @@ D) One dependency socket, two waiters: Y reads its pipelined
         //    reply, X still holds the write stream with a half-written frame.
         //    X's withdrawal must sever the session (the peer would read the
         //    next command as the rest of X's value) — closing the socket
         //    while Y is still registered on it.
         $Select = $reactor();
         $KV = $redis($listen());
         $log = [];
         $Reply = null;
         $Frame = null;
         $Shared = null;
         $YResource = new KV($KV)->schedule($bridge);
         $XResource = new KV($KV)->schedule($bridge);
         $Y = new Fiber(static function () use ($YResource, $Select, &$Reply, &$log): void {
            try {
               $Reply = $YResource->command('GET', ['y']);
               $Reply = $YResource->await($Reply);
               $log[] = "Y-returned: {$Reply->error}";
            }
            catch (Throwable $Throwable) {
               $log[] = "Y-caught: {$Throwable->getMessage()}";
            }
            finally {
               $log[] = 'Y-finally';
               $Select->loop = false; // @phpstan-ignore-line (property on the Select impl)
            }
         });
         $X = new Fiber(static function () use ($XResource, $Select, $Await, &$Frame, &$Shared, &$log): void {
            try {
               // ! Far beyond the loopback send and receive buffers: the peer
               //   never reads, so the frame stays half-written
               $Frame = $XResource->command('SET', ['x', str_repeat('x', 16 << 20)]);
               $XResource->await($Frame);
               $log[] = 'X-returned';
            }
            catch (Throwable $Throwable) {
               $log[] = "X-caught: {$Throwable->getMessage()}";
            }
            finally {
               // ! Runs after the resource withdrew X's frame
               $waiting = $Await->getValue($Select);
               $log[] = 'X-finally: ' . (is_resource($Shared) ? 'open' : 'closed')
                  . (isset($waiting[(int) $Shared]) ? ' while Y waits' : ' with no waiter');
            }
         });
         $YParked = $Y->start();
         $XParked = $X->start();
         $Shared = $YParked instanceof Readiness ? $YParked->socket : null;
         $Protocol = $Frame?->Protocol;
         $precondition = [
            'y' => $describe($YParked),
            'x' => $describe($XParked),
            'shared' => $XParked instanceof Readiness && $XParked->socket === $Shared,
            'writer' => $Protocol !== null && $Writer->getValue($Protocol) === $Frame,
            'pipelined' => $Reply?->Protocol === $Protocol,
         ];
         [$YToken, $YScheduled] = $park($Select, $Y, $YParked);
         [$XToken, $XScheduled] = $park($Select, $X, $XParked);
         unset($X);

         $XToken->disconnect();
         $escaped = $run($Select, static fn (): bool => $Y->isTerminated(), 2.0);
         $id = (int) $Shared;

         yield assert(
            assertion: $precondition === ['y' => 'read', 'x' => 'write', 'shared' => true, 'writer' => true, 'pipelined' => true]
               && $YScheduled && $XScheduled
               && $escaped === null
               && $log === [
                  'X-finally: closed while Y waits',
                  'Y-returned: Redis operation was abandoned while writing its command.',
                  'Y-finally',
               ]
               && $withdrawn($Frame)
               && $Reply?->finished === true
               && $census($KV->Pool) === $idle
               && isset($Reads->getValue($Select)[$id]) === false
               && isset($Writes->getValue($Select)[$id]) === false
               && $Await->getValue($Select) === [],
            description: 'a withdrawal that closes a socket another Fiber still awaits never fails the reactor: the closed descriptor is dropped, the sibling resumes and its command fails cleanly, the slot is freed — observed: '
               . json_encode(['precondition' => $precondition, 'escaped' => $escaped, 'log' => $log, 'reply' => $Reply?->error, 'frame' => $Frame?->error, 'pool' => $census($KV->Pool), 'reads' => count($Reads->getValue($Select)), 'writes' => count($Writes->getValue($Select))])
         );
         unset($Y, $Frame);

         // @@ E) The same teardown with the survivor in the WRITE table: X
         //    holds the write stream with a half-written frame, Z is co-located
         //    behind it and waits for that stream. Nothing is pipelined, so
         //    nobody reads — the socket is registered for writes only, and X's
         //    withdrawal closes it while Z still holds its write seat.
         $Select = $reactor();
         $KV = $redis($listen());
         $log = [];
         $Frame = null;
         $Queued = null;
         $Shared = null;
         $XResource = new KV($KV)->schedule($bridge);
         $ZResource = new KV($KV)->schedule($bridge);
         $X = new Fiber(static function () use ($XResource, $Select, $Writes, $AwaitWrites, &$Frame, &$Shared, &$log): void {
            try {
               // ! Far beyond the loopback send and receive buffers: the peer
               //   never reads, so the frame stays half-written
               $Frame = $XResource->command('SET', ['x', str_repeat('x', 16 << 20)]);
               $XResource->await($Frame);
               $log[] = 'X-returned';
            }
            catch (Throwable $Throwable) {
               $log[] = "X-caught: {$Throwable->getMessage()}";
            }
            finally {
               // ! Runs after the resource withdrew X's frame
               $id = (int) $Shared;
               $state = is_resource($Shared) ? 'open' : 'closed';
               $seat = isset($Writes->getValue($Select)[$id]) ? 'in the write table' : 'out of the write table';
               $waiter = isset($AwaitWrites->getValue($Select)[$id]) ? 'while Z waits' : 'with no waiter';
               $log[] = "X-finally: {$state} {$seat} {$waiter}";
            }
         });
         $Z = new Fiber(static function () use ($ZResource, $Select, &$Queued, &$log): void {
            try {
               $Queued = $ZResource->command('GET', ['z']);
               $Queued = $ZResource->await($Queued);
               $log[] = "Z-returned: {$Queued->error}";
            }
            catch (Throwable $Throwable) {
               $log[] = "Z-caught: {$Throwable->getMessage()}";
            }
            finally {
               $log[] = 'Z-finally';
               $Select->loop = false; // @phpstan-ignore-line (property on the Select impl)
            }
         });
         $XParked = $X->start();
         $ZParked = $Z->start();
         $Shared = $XParked instanceof Readiness ? $XParked->socket : null;
         $Protocol = $Frame?->Protocol;
         $precondition = [
            'x' => $describe($XParked),
            'z' => $describe($ZParked),
            'shared' => $ZParked instanceof Readiness && $ZParked->socket === $Shared,
            'writer' => $Protocol !== null && $Writer->getValue($Protocol) === $Frame,
            'colocated' => $Queued?->Protocol === $Protocol,
            'unsent' => $Queued?->state === OperationStates::Querying,
         ];
         [$XToken, $XScheduled] = $park($Select, $X, $XParked);
         [, $ZScheduled] = $park($Select, $Z, $ZParked);
         unset($X);
         $id = (int) $Shared;
         // ! Both waiters sit on the write seat and nothing reads: the socket
         //   is in the write table only — no read seat covers it
         $seated = [
            'writes' => isset($Writes->getValue($Select)[$id]),
            'reads' => isset($Reads->getValue($Select)[$id]),
            'waiters' => count($AwaitWrites->getValue($Select)[$id] ?? []),
         ];

         $XToken->disconnect();
         $escaped = $run($Select, static fn (): bool => $Z->isTerminated(), 2.0);

         yield assert(
            assertion: $precondition === ['x' => 'write', 'z' => 'write', 'shared' => true, 'writer' => true, 'colocated' => true, 'unsent' => true]
               && $seated === ['writes' => true, 'reads' => false, 'waiters' => 2]
               && $XScheduled && $ZScheduled
               && $escaped === null
               && $log === [
                  'X-finally: closed in the write table while Z waits',
                  'Z-returned: Redis connection was torn down before the command was sent.',
                  'Z-finally',
               ]
               && $withdrawn($Frame)
               && $Queued?->finished === true
               && $census($KV->Pool) === $idle
               && isset($Writes->getValue($Select)[$id]) === false
               && isset($Reads->getValue($Select)[$id]) === false
               && $AwaitWrites->getValue($Select) === [],
            description: 'a withdrawal that closes a socket another Fiber still awaits for WRITE never fails the reactor: the closed descriptor leaves the write table, the queued command resumes and fails cleanly, the slot is freed — observed: '
               . json_encode(['precondition' => $precondition, 'seated' => $seated, 'escaped' => $escaped, 'log' => $log, 'queued' => $Queued?->error, 'frame' => $Frame?->error, 'pool' => $census($KV->Pool), 'reads' => count($Reads->getValue($Select)), 'writes' => count($Writes->getValue($Select))])
         );
         unset($Z, $Frame, $Queued);
      }
      finally {
         foreach ($Selects as $Select) {
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
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
      }
   })
);
