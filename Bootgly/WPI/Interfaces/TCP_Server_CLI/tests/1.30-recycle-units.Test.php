<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use const Bootgly\CLI;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Process;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Endpoints\Server\Status;
use Bootgly\CLI\Terminal\Output;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\WS_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI\Relay;


/**
 * The master's beacon reads, its retiree supervision, and a recycling
 * worker's leftovers, in this process.
 *
 * - `poll()` walks the live beacon set: a beacon closed under it — by a
 *   handler that ran asynchronously while it read another one, unset first
 *   or not — is skipped, never read (a closed stream would throw a
 *   TypeError out of the master's loop).
 * - `heed()` reads the beacons with SIGCHLD blocked, so an asynchronous
 *   dispatch can never reap a child under it, and restores the mask after.
 * - A recycling WebSocket worker's `shed()` hands the slot's cross-worker
 *   mailbox to its replacement: it stops reading it here.
 * - A recycling worker's drain hands its idle connections back at once —
 *   `drain()` calls the node's `shed()` before any drain tick — and a
 *   second SIGQUIT finds the drain running.
 * - A beacon pair is close-on-exec where ext-sockets offers `SOCK_CLOEXEC`:
 *   a process a worker's handler starts never inherits its end (a plain
 *   stream pair, the control, is inherited).
 * - The go: a retiree that exits (`classify()`) or is killed past its
 *   deadline (`kill()`) lets its own slot's worker recycle — `G` on that
 *   slot's beacon, never on another slot's. A retiree that ended on its own
 *   just before the deadline kill is an ordinary retiree death, classified
 *   next turn: never logged as killed.
 * - `await()`: the go lets a gated worker recycle — at once when it was held
 *   past a limit, never while it already drains (a reload, a stop); no go,
 *   it stays gated.
 * - A draining HTTP worker (a recycle's retiree) never consults the Auto-TLS
 *   swap a SIGURG announces; a serving one does.
 * - `status` counts the retirees beside the workers (`+1 retiring`).
 */
return new Test(
   description: 'Recycling: beacon reads survive a handler and run with SIGCHLD blocked; the go reaches its own slot; a recycling worker sheds at once, a WS one leaves its mailbox',
   skip: function_exists('pcntl_async_signals') === false
      || function_exists('pcntl_sigprocmask') === false
      || function_exists('posix_kill') === false,
   test: new Assertions(Case: function (): Generator {
      // ! A beacon the spec scripts: its reads run `$Read`, it never ends
      $Beacon = new class {
         /** @var resource */
         public $context;
         public static null|Closure $Read = null;

         public function stream_open (string $path, string $mode, int $options, null|string &$opened): bool
         {
            return true;
         }
         public function stream_read (int $count): string
         {
            if (self::$Read !== null) {
               (self::$Read)();
            }

            return '';
         }
         public function stream_eof (): bool
         {
            return false;
         }
         public function stream_set_option (int $option, int $first, null|int $second): bool
         {
            return false;
         }
      };
      stream_wrapper_register('bootglyrecycle', $Beacon::class);
      /** The signals blocked in this process now. */
      $Blocked = static function (): array {
         $mask = [];
         pcntl_sigprocmask(SIG_BLOCK, [SIGURG], $mask);
         pcntl_sigprocmask(SIG_SETMASK, $mask);

         return $mask;
      };

      $async = pcntl_async_signals();
      $Handler = pcntl_signal_get_handler(SIGUSR1);
      try {
         // @@ poll(): a beacon closed under the walk, by an asynchronous handler
         $Results = [];
         foreach (['unset, then closed (hear())' => true, 'closed, still listed' => false] as $case => $unset) {
            $Server = (new ReflectionClass(TCP_Server_CLI::class))->newInstanceWithoutConstructor();
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('Could not create a beacon pair.');
            }
            stream_set_blocking($Pair[0], false);
            Closure::bind(function (array $Beacons): void {
               $this->Beacons = $Beacons;
               $this->words = [];
            }, $Server, TCP_Server_CLI::class)([0 => fopen('bootglyrecycle://0', 'r'), 1 => $Pair[0]]);

            // ! Reading beacon 0 raises the signal whose handler closes beacon 1
            $Beacon::$Read = static function (): void {
               posix_kill(posix_getpid(), SIGUSR1);
            };
            pcntl_async_signals(true);
            pcntl_signal(SIGUSR1, Closure::bind(function () use ($unset): void {
               if ($unset) {
                  $this->hear(1);

                  return;
               }
               fclose($this->Beacons[1]);
            }, $Server, TCP_Server_CLI::class));

            $thrown = '';
            try {
               Closure::bind(fn () => $this->poll(), $Server, TCP_Server_CLI::class)();
            }
            catch (Throwable $Throwable) {
               $thrown = $Throwable::class;
            }
            pcntl_async_signals(false);
            $Beacon::$Read = null;

            $Results[$case] = [
               'thrown' => $thrown,
               'beacons left' => array_keys(Closure::bind(fn (): array => $this->Beacons, $Server, TCP_Server_CLI::class)()),
            ];
            fclose($Pair[1]);
         }

         yield new Assertion(description: 'poll() skips a beacon an asynchronous handler closed under it, listed or not')
            ->expect($Results, Op::Identical, [
               'unset, then closed (hear())' => ['thrown' => '', 'beacons left' => [0]],
               'closed, still listed' => ['thrown' => '', 'beacons left' => [0]],
            ])
            ->assert();

         // @@ heed(): the beacons are read with SIGCHLD blocked
         $Server = (new ReflectionClass(TCP_Server_CLI::class))->newInstanceWithoutConstructor();
         $Process = new Process('RecycleUnitsTest', (string) getmypid());
         $during = null;
         Closure::bind(function (Process $Process, array $Beacons): void {
            $this->Process = $Process;
            $this->Beacons = $Beacons;
         }, $Server, TCP_Server_CLI::class)($Process, [0 => fopen('bootglyrecycle://0', 'r')]);
         $Beacon::$Read = static function () use (&$during, $Blocked): void {
            $during = in_array(SIGCHLD, $Blocked(), true);
         };
         $before = in_array(SIGCHLD, $Blocked(), true);
         Closure::bind(fn () => $this->heed(), $Server, TCP_Server_CLI::class)();
         $Beacon::$Read = null;
         $after = in_array(SIGCHLD, $Blocked(), true);

         yield new Assertion(description: 'heed() reads the beacons with SIGCHLD blocked, and unblocks it after')
            ->expect(['before' => $before, 'while reading' => $during, 'after' => $after], Op::Identical, [
               'before' => false,
               'while reading' => true,
               'after' => false,
            ])
            ->assert();
      }
      finally {
         pcntl_async_signals($async);
         pcntl_signal(SIGUSR1, $Handler);
         $Beacon::$Read = null;
         stream_wrapper_unregister('bootglyrecycle');
      }

      // @@ WS shed(): the slot's mailbox goes to the replacement
      $Event = isSet(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $Instance = Relay::$Instance;
      $Peers = Connections::$Connections;
      $Buses = [
         stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0),
         stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0),
      ];
      try {
         if (in_array(false, $Buses, true)) {
            throw new RuntimeException('Could not create the relay bus.');
         }
         /** @var array<int,array<int,resource>> $Buses */
         $Select = (new ReflectionClass(Select::class))->newInstanceWithoutConstructor();
         TCP_Server_CLI::$Event = $Select;
         $Relay = new Relay($Buses, 0, 2);
         Relay::$Instance = $Relay;
         $Select->add($Relay->Socket, Select::EVENT_READ, $Relay);
         $Read = Closure::bind(
            fn ($Socket): bool => isSet($this->reads[(int) $Socket], $this->reading[(int) $Socket]),
            $Select,
            Select::class
         );
         $read = $Read($Relay->Socket);

         // ! No connection to close: the mailbox alone
         Connections::$Connections = [];
         $WS = (new ReflectionClass(WS_Server_CLI::class))->newInstanceWithoutConstructor();
         Closure::bind(fn () => $this->shed(), $WS, WS_Server_CLI::class)();

         $Shed = [
            'the mailbox was read here' => $read,
            'no longer read here' => $Read($Relay->Socket) === false,
            'no relay left in this worker' => Relay::$Instance === null,
         ];
      }
      finally {
         if ($Event !== null) {
            TCP_Server_CLI::$Event = $Event;
         }
         Relay::$Instance = $Instance;
         Connections::$Connections = $Peers;
         foreach ($Buses as $Pair) {
            foreach ((array) $Pair as $End) {
               if (is_resource($End)) {
                  fclose($End);
               }
            }
         }
      }

      yield new Assertion(description: 'a recycling WebSocket worker leaves the slot\'s mailbox to its replacement')
         ->expect($Shed, Op::Identical, [
            'the mailbox was read here' => true,
            'no longer read here' => true,
            'no relay left in this worker' => true,
         ])
         ->assert();

      // @@ drain(): a recycle sheds at once
      $Event = isSet(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $Peers = Connections::$Connections;
      $recycling = TCP_Server_CLI::$recycling;
      $segments = Display::$segments;
      $Spy = new class extends TCP_Server_CLI {
         public int $sheds = 0;

         // ! No server: a bare node whose `shed()` is counted
         public function __construct ()
         {
         }

         protected function shed (): void
         {
            $this->sheds++;
         }
      };
      try {
         // ! A worker (its master is another process) with nothing in flight
         $Worker = new Process('RecycleUnitsDrain', (string) getmypid());
         Closure::bind(function (): void {
            $this->master = posix_getppid();
         }, $Worker, Process::class)();
         Closure::bind(function (Process $Worker): void {
            $this->Process = $Worker;
         }, $Spy, TCP_Server_CLI::class)($Worker);
         $Select = (new ReflectionClass(Select::class))->newInstanceWithoutConstructor();
         TCP_Server_CLI::$Event = $Select;
         Connections::$Connections = [];
         TCP_Server_CLI::$recycling = true;
         Display::show(Display::NONE);

         $Drain = Closure::bind(fn () => $this->drain(), $Spy, TCP_Server_CLI::class);
         $Drain();
         $once = $Spy->sheds;
         $Drain();
         $Drained = [
            'shed at once, before any drain tick' => $once,
            'the loop ends: nothing in flight' => $Select->loop === false,
            'a second SIGQUIT finds the drain running' => $Spy->sheds,
         ];
      }
      finally {
         Display::show($segments);
         TCP_Server_CLI::$recycling = $recycling;
         Connections::$Connections = $Peers;
         if ($Event !== null) {
            TCP_Server_CLI::$Event = $Event;
         }
      }

      // @@ pair(): close-on-exec beacon ends
      if (defined('SOCK_CLOEXEC') && function_exists('socket_create_pair') && function_exists('shell_exec')) {
         $Pairing = (new ReflectionClass(TCP_Server_CLI::class))->newInstanceWithoutConstructor();
         /** @var array<int,resource>|false $Pair */
         $Pair = Closure::bind(fn () => $this->pair(), $Pairing, TCP_Server_CLI::class)();
         $Control = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         /** Whether a process started now holds this socket. */
         $Inherited = static function ($End): bool {
            $inode = (int) (fstat($End)['ino'] ?? 0);
            $descriptors = (string) shell_exec('ls -l /proc/self/fd 2>/dev/null');

            return $inode > 0 && str_contains($descriptors, "socket:[{$inode}]");
         };
         $Exec = [
            'a pair' => is_array($Pair) && count($Pair) === 2,
            'its worker end stays out of a started process' => is_array($Pair) && $Inherited($Pair[1]) === false,
            'its master end too' => is_array($Pair) && $Inherited($Pair[0]) === false,
            'a plain stream pair is inherited (control)' => $Control !== false && $Inherited($Control[1]),
         ];
         foreach ([$Pair, $Control] as $Ends) {
            foreach ((array) $Ends as $End) {
               if (is_resource($End)) {
                  fclose($End);
               }
            }
         }

         yield new Assertion(description: 'a beacon pair is close-on-exec: a started process never inherits it')
            ->expect($Exec, Op::Identical, [
               'a pair' => true,
               'its worker end stays out of a started process' => true,
               'its master end too' => true,
               'a plain stream pair is inherited (control)' => true,
            ])
            ->assert();
      }

      yield new Assertion(description: 'a recycling worker sheds at once when its drain begins, and drains once')
         ->expect($Drained, Op::Identical, [
            'shed at once, before any drain tick' => 1,
            'the loop ends: nothing in flight' => true,
            'a second SIGQUIT finds the drain running' => 1,
         ])
         ->assert();

      // @@ The go — a retiree that exits or is killed lets its own slot recycle
      /** A child of this runner that runs $command — never this runner's shutdown functions. */
      $Spawn = static function (string $command): int {
         $PID = pcntl_fork();
         if ($PID === 0) {
            pcntl_exec('/bin/sh', ['-c', $command]);
            posix_kill(posix_getpid(), SIGKILL);
         }

         return $PID;
      };
      /** Wait until $PID is a zombie; whether it became one. */
      $Zombie = static function (int $PID): bool {
         $deadline = hrtime(true) + 3_000_000_000;
         do {
            $stat = (string) @file_get_contents("/proc/{$PID}/stat");
            if (($Fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2)))[0] === 'Z') {
               return true;
            }
            usleep(5_000);
         } while (hrtime(true) < $deadline);

         return false;
      };
      /**
       * A bare master supervising $Process, with one beacon per slot.
       *
       * @param array<int,resource> $Beacons
       */
      $Supervisor = static function (Process $Process, array $Beacons): TCP_Server_CLI {
         $Server = (new ReflectionClass(TCP_Server_CLI::class))->newInstanceWithoutConstructor();
         Closure::bind(function () use ($Process, $Beacons): void {
            $this->Process = $Process;
            $this->Beacons = $Beacons;
         }, $Server, TCP_Server_CLI::class)();

         return $Server;
      };
      /**
       * What reached each slot's worker end of its beacon.
       *
       * @param array<int,array<int,resource>> $Pairs
       *
       * @return array<int,string>
       */
      $Heard = static function (array $Pairs): array {
         $Said = [];
         foreach ($Pairs as $slot => $Pair) {
            stream_set_blocking($Pair[1], false);
            $Said[$slot] = (string) @fread($Pair[1], 16);
         }

         return $Said;
      };
      $segments = Display::$segments;
      $Child = pcntl_signal_get_handler(SIGCHLD);
      $Children = [];
      $Pairs = [];
      $Gone = [];
      try {
         Display::show(Display::NONE);
         // ! Zombies stay until reaped here: no handler, no auto-reap
         pcntl_signal(SIGCHLD, SIG_DFL);
         for ($slot = 0; $slot < 2; $slot++) {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('Could not create a beacon pair.');
            }
            $Pairs[$slot] = $Pair;
         }
         $Beacons = array_map(static fn (array $Pair) => $Pair[0], $Pairs);

         // # classify(): a retiree of slot 1 exits — recycled
         $Process = new Process('RecycleUnitsGate', (string) getmypid());
         $Exited = $Children[] = $Spawn('exit 75');
         $Process->Children->push($Exited, 1);
         $Process->Children->retire($Exited);
         $zombie = $Zombie($Exited);
         $Server = $Supervisor($Process, $Beacons);
         $left = Closure::bind(fn (): bool => $this->classify(), $Server, TCP_Server_CLI::class)();
         $Gone['classify'] = [
            'a zombie first' => $zombie,
            'a retiree left' => $left,
            'reaped and forgotten' => $Process->Children->retirees === [] && posix_kill($Exited, 0) === false,
            'the go, on its own slot only' => $Heard($Pairs),
         ];

         // # kill(): a retiree of slot 0 still draining past its deadline
         $Process = new Process('RecycleUnitsGate', (string) getmypid());
         $Draining = $Children[] = $Spawn('exec sleep 30');
         $Process->Children->push($Draining, 0);
         $Process->Children->retire($Draining);
         $Server = $Supervisor($Process, $Beacons);
         $left = Closure::bind(fn (): bool => $this->kill($Draining), $Server, TCP_Server_CLI::class)();
         $Gone['kill'] = [
            'a retiree left' => $left,
            'killed and reaped' => $Process->Children->retirees === [] && posix_kill($Draining, 0) === false,
            'nothing left to classify' => Closure::bind(fn (): array => $this->deaths, $Server, TCP_Server_CLI::class)(),
            'the go, on its own slot only' => $Heard($Pairs),
         ];

         // # kill(): a retiree of slot 1 that ended on its own just before it
         $Process = new Process('RecycleUnitsGate', (string) getmypid());
         $Ended = $Children[] = $Spawn('exit 75');
         $Process->Children->push($Ended, 1);
         $Process->Children->retire($Ended);
         $zombie = $Zombie($Ended);
         $Server = $Supervisor($Process, $Beacons);
         $left = Closure::bind(fn (): bool => $this->kill($Ended), $Server, TCP_Server_CLI::class)();
         $Deaths = Closure::bind(fn (): array => $this->deaths, $Server, TCP_Server_CLI::class)();
         $Gone['ended'] = [
            'a zombie first' => $zombie,
            'a retiree left' => $left,
            'reaped' => $Process->Children->retirees === [] && posix_kill($Ended, 0) === false,
            'an ordinary retiree death, classified next turn' => count($Deaths) === 1
               && ($Deaths[0][0] ?? null) === 1
               && ($Deaths[0][1] ?? null) === $Ended
               && pcntl_wifexited($Deaths[0][2] ?? 0)
               && pcntl_wexitstatus($Deaths[0][2] ?? 0) === TCP_Server_CLI::RECYCLE
               && ($Deaths[0][3] ?? null) === true,
            'no go yet' => $Heard($Pairs),
         ];
      }
      finally {
         // ! No child of this case outlives it
         foreach ($Children as $PID) {
            if ($PID > 0) {
               @posix_kill($PID, SIGKILL);
               pcntl_waitpid($PID, $status, WNOHANG);
            }
         }
         foreach ($Pairs as $Pair) {
            foreach ($Pair as $End) {
               if (is_resource($End)) {
                  fclose($End);
               }
            }
         }
         pcntl_signal(SIGCHLD, $Child);
         Display::show($segments);
      }

      yield new Assertion(description: 'the go: a retiree that exits or is killed lets its own slot recycle; one that ended just before the kill is an ordinary death')
         ->expect($Gone, Op::Identical, [
            'classify' => [
               'a zombie first' => true,
               'a retiree left' => true,
               'reaped and forgotten' => true,
               'the go, on its own slot only' => [0 => '', 1 => 'G'],
            ],
            'kill' => [
               'a retiree left' => true,
               'killed and reaped' => true,
               'nothing left to classify' => [],
               'the go, on its own slot only' => [0 => 'G', 1 => ''],
            ],
            'ended' => [
               'a zombie first' => true,
               'a retiree left' => true,
               'reaped' => true,
               'an ordinary retiree death, classified next turn' => true,
               'no go yet' => [0 => '', 1 => ''],
            ],
         ])
         ->assert();

      // @@ await(): a gated worker's read of the go
      $Gate = [TCP_Server_CLI::$gated, TCP_Server_CLI::$counting, TCP_Server_CLI::$recycling];
      $Quit = pcntl_signal_get_handler(SIGQUIT);
      $quits = 0;
      $Awaited = [];
      try {
         Display::show(Display::NONE);
         pcntl_signal(SIGQUIT, static function () use (&$quits): void {
            $quits++;
         });
         /**
          * One gated worker, $Status, past a limit or not, whose beacon got
          * $said: [gated, recycling, SIGQUITs] after one await().
          *
          * @return array{bool,bool,int}
          */
         $Await = static function (Status $Status, bool $counting, string $said) use (&$quits): array {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('Could not create a beacon pair.');
            }
            stream_set_blocking($Pair[0], false);
            if ($said !== '') {
               fwrite($Pair[1], $said);
            }
            $Server = (new ReflectionClass(TCP_Server_CLI::class))->newInstanceWithoutConstructor();
            Closure::bind(function () use ($Pair, $Status): void {
               $this->Beacon = $Pair[0];
               $this->Status = $Status;
            }, $Server, TCP_Server_CLI::class)();
            TCP_Server_CLI::$gated = true;
            TCP_Server_CLI::$counting = $counting;
            TCP_Server_CLI::$recycling = false;
            $quits = 0;
            Closure::bind(fn () => $this->await(), $Server, TCP_Server_CLI::class)();
            pcntl_signal_dispatch();
            fclose($Pair[0]);
            fclose($Pair[1]);

            return [TCP_Server_CLI::$gated, TCP_Server_CLI::$recycling, $quits];
         };
         $Awaited = [
            'no go yet' => $Await(Status::Running, false, ''),
            'the go, held past a limit: it recycles now' => $Await(Status::Running, false, 'G'),
            'the go, under its limits: only let go' => $Await(Status::Running, true, 'G'),
            'the go while draining: nothing' => $Await(Status::Stopping, false, 'G'),
         ];
      }
      finally {
         [TCP_Server_CLI::$gated, TCP_Server_CLI::$counting, TCP_Server_CLI::$recycling] = $Gate;
         pcntl_signal(SIGQUIT, $Quit);
         Display::show($segments);
      }

      yield new Assertion(description: 'await(): the go lets a gated worker recycle — at once if it is held past a limit; never while it drains')
         ->expect($Awaited, Op::Identical, [
            // [gated, recycling, SIGQUITs]
            'no go yet' => [true, false, 0],
            'the go, held past a limit: it recycles now' => [false, true, 1],
            'the go, under its limits: only let go' => [false, false, 0],
            'the go while draining: nothing' => [true, false, 0],
         ])
         ->assert();

      // @@ SIGURG: a draining HTTP worker never consults the Auto-TLS swap
      $Node = (new ReflectionClass(HTTP_Server_CLI::class))->newInstanceWithoutConstructor();
      // ! A bare Auto-TLS: reading its swap throws — the probe of a consult
      $AutoTLS = (new ReflectionClass(AutoTLS::class))->newInstanceWithoutConstructor();
      $Consulted = static function (Status $Status) use ($Node, $AutoTLS): bool {
         Closure::bind(function () use ($Status, $AutoTLS): void {
            $this->AutoTLS = $AutoTLS;
            $this->Status = $Status;
         }, $Node, HTTP_Server_CLI::class)();
         try {
            $Node->handle(SIGURG);
         }
         catch (Error $Error) {
            return str_contains($Error->getMessage(), 'AutoTLS::$path');
         }

         return false;
      };

      yield new Assertion(description: 'a draining HTTP worker ignores the certificate swap SIGURG announces; a serving one reads it')
         ->expect([
            'draining (Stopping)' => $Consulted(Status::Stopping),
            'serving (Running)' => $Consulted(Status::Running),
         ], Op::Identical, [
            'draining (Stopping)' => false,
            'serving (Running)' => true,
         ])
         ->assert();

      // @@ status: the retirees beside the workers
      $Terminal = CLI->Terminal;
      $Screen = $Terminal->Output;
      $Event = isSet(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $Probe = null;
      $rendered = '';
      try {
         $Probe = new TCP_Server_CLI(Modes::Test);
         // ! Never this probe's shutdown teardown: its children are not real
         $Probe->Process->stopping = true;
         // ! The socket of a configured server, without configure()'s sinks
         Closure::bind(function (): void {
            $this->host = '127.0.0.1';
            $this->port = 0;
            $this->workers = 1;
         }, $Probe, TCP_Server_CLI::class)();
         $Probe->Process->Children->push(2_147_483_000, 0);
         $Probe->Process->Children->retire(2_147_483_000);
         $Probe->Process->Children->push(getmypid(), 0);
         $Host = new Output('php://memory');
         $Terminal->Output = $Host;
         CLI->Commands->find('status', From: $Probe)?->run();
         rewind($Host->stream);
         $rendered = (string) preg_replace('/\e\[[0-9;]*m/', '', (string) stream_get_contents($Host->stream));
      }
      finally {
         $Terminal->Output = $Screen;
         $Probe?->Process->Children->remove(2_147_483_000);
         $Probe?->Process->Children->remove(getmypid());
         if ($Event !== null) {
            TCP_Server_CLI::$Event = $Event;
         }
      }

      yield new Assertion(description: 'status counts the retirees beside the workers')
         ->expect(preg_match('/Workers count:\s+1 \(\+1 retiring\)/', $rendered), Op::Identical, 1)
         ->assert();
   }),
);
