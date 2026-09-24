<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SERVER_BIND;
use const STREAM_SOCK_STREAM;
use function array_filter;
use function array_key_first;
use function array_keys;
use function assert;
use function count;
use function fclose;
use function is_int;
use function is_resource;
use function max;
use function microtime;
use function posix_getppid;
use function str_contains;
use function stream_socket_pair;
use function stream_socket_server;
use ReflectionProperty;
use RuntimeException;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Handlers\Memory as MemoryHandler;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Process;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Endpoints\Server\Status;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as UDPServer;


/**
 * A worker's datagram socket refused by the selector on resume() keeps it Paused.
 *
 * The UDP twin of TCP_Server_CLI's 1.11: pause() frees the worker socket's
 * read entry; while the worker is paused the Fibers of deferred work may take
 * it for their dependency waits. The selector admits Select::CAPACITY entries
 * per table, so resume() used to ignore a refused re-registration and report
 * Running while nothing read a datagram. Now the refusal is logged as
 * critical, the worker stays Paused, resume() returns false and a retry is
 * deferred one second out — re-armed every second while the table stays full,
 * promoting the worker to Running once an entry frees (the socket back under
 * EVENT_READ with the Connections Router as its payload), and standing down
 * if the worker left Paused by another route. One chain runs at a time: a
 * later refused resume() takes the armed one over, and a pause() arriving
 * while it is armed stands — it cancels the retry and the worker stays
 * Paused, so nothing brings the socket back. A later resume() that succeeds
 * disarms the chain too, so the next pause() is a real one: it deregisters
 * the socket and sets Paused, and no stale retry survives to bring it back.
 *
 * The server is never started: the worker side is reached by pointing the
 * Process master at the parent PID, and the retry timers are fast-forwarded
 * (made due now) so each reactor run is exactly one tick — except after a
 * cancelled retry, where the reactor runs for real past its deadline.
 */
return new Test(
   description: 'A worker resume() whose socket a full selector refuses stays Paused and retries every second (one chain at a time) until an entry frees, unless a pause() or a successful resume() cancels it; with room it resumes at once',
   test: function () {
      // ! Statics this case swaps
      $EventProperty = new ReflectionProperty(UDPServer::class, 'Event');
      $PreviousEvent = $EventProperty->isInitialized() ? UDPServer::$Event : null;
      $PreviousSinks = Logger::$Sinks;
      $display = Display::$segments;
      $debug = Vars::$debug;
      $print = Vars::$print;
      $exit = Vars::$exit;
      // ! Reflection over the worker's state and the selector's tables
      $MasterProperty = new ReflectionProperty(Process::class, 'master');
      $StatusProperty = new ReflectionProperty(UDPServer::class, 'Status');
      $SocketProperty = new ReflectionProperty(UDPServer::class, 'Socket');
      $RetryProperty = new ReflectionProperty(UDPServer::class, 'retry');
      $ReadsProperty = new ReflectionProperty(Select::class, 'reads');
      $ReadingProperty = new ReflectionProperty(Select::class, 'reading');
      $TimersProperty = new ReflectionProperty(Select::class, 'Timers');
      $MonotonicTimersProperty = new ReflectionProperty(Select::class, 'MonotonicTimers');

      $Server = null;
      $Process = null;
      $master = 0;
      $ConstructedEvent = null;
      $Socket = false;
      $Pair = false;

      try {
         // ! Worker logs land in memory, nothing on stdout
         Display::show(Display::NONE);
         $Memory = new MemoryHandler;
         Logger::$Sinks = new Handlers;
         Logger::$Sinks->push($Memory);
         $Refusals = static fn (): int => count(array_filter(
            $Memory->Records,
            static fn (Record $Record): bool => $Record->Level === Levels::Critical
               && str_contains($Record->message, 'refused by the selector on resume')
         ));

         // ! A server that is never started — its constructor installs its own selector
         $Server = new UDPServer(Modes::Test);
         $ConstructedEvent = UDPServer::$Event;
         $Connections = $Server->Connections;
         $Router = $Connections->Router;
         // ! Worker side: the master is the parent PID, so `level` reads 'child'
         $Process = $Server->Process;
         $master = $Process->master;
         $MasterProperty->setValue($Process, posix_getppid());
         // ! A real datagram socket, and an idle pair standing in for dependency sockets
         $Socket = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Socket === false || $Pair === false) {
            throw new RuntimeException("Could not create the socket fixture: {$message}");
         }
         $SocketProperty->setValue($Server, $Socket);
         $socket = (int) $Socket;

         // ! Fast-forward: every pending timer falls due now; a stop timer
         //   queued after them ends the loop within that same tick
         $Fire = static function (Select $Selector) use ($TimersProperty): void {
            $Timers = $TimersProperty->getValue($Selector);
            foreach (array_keys($Timers) as $ID) {
               $Timers[$ID]['deadline'] = 0.0;
            }
            $TimersProperty->setValue($Selector, $Timers);

            $Selector->loop = true;
            $Selector->defer(0.0, static function () use ($Selector): void {
               $Selector->loop = false;
            });
            $Selector->loop();
         };

         // # A) Control — room in the table: the socket re-enters at once
         $Selector = new Select($Connections);
         UDPServer::$Event = $Selector;
         $StatusProperty->setValue($Server, Status::Paused);

         $resumed = $Server->resume();
         $reads = $ReadsProperty->getValue($Selector);
         $reading = $ReadingProperty->getValue($Selector);
         $Timers = $TimersProperty->getValue($Selector);

         yield assert(
            assertion: $Process->level === 'child'
               && $resumed === true
               && $Server->Status === Status::Running
               && ($reads[$socket] ?? null) === $Socket
               && ($reading[$socket] ?? null) === $Router
               && $Timers === []
               && $RetryProperty->getValue($Server) === null
               && $Refusals() === 0,
            description: 'with room in the selector a worker resume() re-registers its socket under EVENT_READ with the Router payload, reports Running, defers nothing and logs no refusal'
         );

         // # B) A running worker pauses, and a dependency wait takes the entry pause() freed
         $Selector = new Select($Connections);
         UDPServer::$Event = $Selector;
         $StatusProperty->setValue($Server, Status::Running);
         $registered = $Selector->add($Socket, Select::EVENT_READ, $Router);
         // ! The rest of the table: stand-in entries under keys no resource id takes
         $reads = $ReadsProperty->getValue($Selector);
         for ($key = -1; count($reads) < Select::CAPACITY; $key--) {
            $reads[$key] = $Pair[0];
         }
         $ReadsProperty->setValue($Selector, $reads);

         $paused = $Server->pause();
         $freed = count($ReadsProperty->getValue($Selector));
         $waited = $Selector->add($Pair[1], Select::EVENT_READ, null);
         $full = count($ReadsProperty->getValue($Selector));

         $before = microtime(true);
         $resumed = $Server->resume();
         $after = microtime(true);
         $reads = $ReadsProperty->getValue($Selector);
         $reading = $ReadingProperty->getValue($Selector);
         $Timers = $TimersProperty->getValue($Selector);
         $retry = array_key_first($Timers);
         $deadline = is_int($retry) ? ($Timers[$retry]['deadline'] ?? 0.0) : 0.0;

         yield assert(
            assertion: $registered === true && $paused === true
               && $freed === Select::CAPACITY - 1
               && $waited === true && $full === Select::CAPACITY
               && $resumed === false
               && $Server->Status === Status::Paused
               && isset($reads[$socket]) === false
               && isset($reading[$socket]) === false
               && count($Timers) === 1
               && $RetryProperty->getValue($Server) === $retry
               && $deadline >= $before + 1.0 && $deadline <= $after + 1.0
               && $Refusals() === 1,
            description: 'a full selector refuses the socket: resume() returns false, the worker stays Paused, one critical refusal is logged '
               . 'and one retry is deferred one second out '
               . "(resumed=" . ($resumed ? 'true' : 'false') . ", status={$Server->Status->name}, timers=" . count($Timers) . ", refusals={$Refusals()})"
         );

         // # C) The retry fires into a table still full: it re-arms, one second out, silently
         $before = microtime(true);
         $Fire($Selector);
         $after = microtime(true);
         $reads = $ReadsProperty->getValue($Selector);
         $Timers = $TimersProperty->getValue($Selector);
         $rearmed = array_key_first($Timers);
         $deadline = is_int($rearmed) ? ($Timers[$rearmed]['deadline'] ?? 0.0) : 0.0;

         yield assert(
            assertion: $Server->Status === Status::Paused
               && isset($reads[$socket]) === false
               && count($reads) === Select::CAPACITY
               && count($Timers) === 1 && $rearmed !== $retry
               && $RetryProperty->getValue($Server) === $rearmed
               && $deadline >= $before + 1.0 && $deadline <= $after + 1.0
               && $Refusals() === 1,
            description: 'a retry still refused keeps the worker Paused and re-arms itself one second out, logging nothing more '
               . "(status={$Server->Status->name}, timers=" . count($Timers) . ", refusals={$Refusals()})"
         );

         // # D) The dependency wait ends: the next retry re-registers the socket
         $Selector->del($Pair[1], Select::EVENT_READ);
         $Fire($Selector);
         $reads = $ReadsProperty->getValue($Selector);
         $reading = $ReadingProperty->getValue($Selector);
         $Timers = $TimersProperty->getValue($Selector);

         yield assert(
            assertion: $Server->Status === Status::Running
               && ($reads[$socket] ?? null) === $Socket
               && ($reading[$socket] ?? null) === $Router
               && count($reads) === Select::CAPACITY
               && $Timers === []
               && $RetryProperty->getValue($Server) === null
               && $Refusals() === 1,
            description: 'once an entry frees, the deferred retry re-registers the socket under EVENT_READ with the Router payload and promotes the worker to Running, leaving no timer behind '
               . "(status={$Server->Status->name}, timers=" . count($Timers) . ')'
         );

         // # E) Refused again, then the worker leaves Paused by another route: the retry stands down
         $paused = $Server->pause();
         $waited = $Selector->add($Pair[1], Select::EVENT_READ, null);
         $resumed = $Server->resume();
         $deferred = count($TimersProperty->getValue($Selector));
         $StatusProperty->setValue($Server, Status::Stopping);
         $Selector->del($Pair[1], Select::EVENT_READ);
         $Fire($Selector);
         $reads = $ReadsProperty->getValue($Selector);
         $Timers = $TimersProperty->getValue($Selector);

         yield assert(
            assertion: $paused === true && $waited === true
               && $resumed === false && $deferred === 1
               && $Server->Status === Status::Stopping
               && isset($reads[$socket]) === false
               && count($reads) === Select::CAPACITY - 1
               && $Timers === []
               && $RetryProperty->getValue($Server) === null
               && $Refusals() === 2,
            description: 'a retry that finds the worker no longer Paused stands down: no socket registration, no Status change, no re-arm '
               . "(status={$Server->Status->name}, timers=" . count($Timers) . ')'
         );

         // # F) Refused, then paused again: the pause stands and cancels the armed retry
         $StatusProperty->setValue($Server, Status::Running);
         $registered = $Selector->add($Socket, Select::EVENT_READ, $Router);
         $paused = $Server->pause();
         $waited = $Selector->add($Pair[1], Select::EVENT_READ, null);
         $resumed = $Server->resume();
         $armed = $RetryProperty->getValue($Server);
         $Timers = $TimersProperty->getValue($Selector);
         $deferred = is_int($armed) && isset($Timers[$armed]) && count($Timers) === 1;
         $deadline = is_int($armed) ? ($Timers[$armed]['deadline'] ?? 0.0) : 0.0;

         $repaused = $Server->pause();
         $retry = $RetryProperty->getValue($Server);
         $Timers = $TimersProperty->getValue($Selector);
         $Monotonic = $MonotonicTimersProperty->getValue($Selector);

         yield assert(
            assertion: $registered === true && $paused === true && $waited === true
               && $resumed === false && $deferred === true
               && $repaused === true
               && $Server->Status === Status::Paused
               && $retry === null
               && is_int($armed) && isset($Timers[$armed]) === false
               && $Timers === [] && $Monotonic === []
               && $Refusals() === 3,
            description: 'a pause() while a refused resume() has its retry armed stands: it returns true, the worker stays Paused '
               . 'and the armed retry is cancelled, leaving no timer in the selector '
               . "(repaused=" . ($repaused ? 'true' : 'false') . ", status={$Server->Status->name}, retry=" . ($retry ?? 'null') . ', timers=' . count($Timers) . ", refusals={$Refusals()})"
         );

         // # F) The dependency wait ends; the reactor runs, for real, past the cancelled retry's deadline
         $Selector->del($Pair[1], Select::EVENT_READ);
         $Selector->loop = true;
         $Selector->defer(max($deadline, microtime(true)) + 0.1, static function () use ($Selector): void {
            $Selector->loop = false;
         });
         $Selector->loop();
         $ran = microtime(true);
         $reads = $ReadsProperty->getValue($Selector);
         $Timers = $TimersProperty->getValue($Selector);

         yield assert(
            assertion: $deadline > 0.0 && $ran >= $deadline
               && $Server->Status === Status::Paused
               && isset($reads[$socket]) === false
               && count($reads) === Select::CAPACITY - 1
               && $Timers === []
               && $RetryProperty->getValue($Server) === null
               && $Refusals() === 3,
            description: 'with the retry cancelled, a reactor run past its deadline after an entry frees leaves the socket out and the worker Paused '
               . "(status={$Server->Status->name}, timers=" . count($Timers) . ')'
         );

         // # G) Two refusals in a row: the second resume() takes the first one's chain over
         $waited = $Selector->add($Pair[1], Select::EVENT_READ, null);
         $first = $Server->resume();
         $chained = $RetryProperty->getValue($Server);
         $second = $Server->resume();
         $retry = $RetryProperty->getValue($Server);
         $Timers = $TimersProperty->getValue($Selector);
         $Monotonic = $MonotonicTimersProperty->getValue($Selector);
         $reads = $ReadsProperty->getValue($Selector);

         yield assert(
            assertion: $waited === true
               && $first === false && $second === false
               && is_int($chained) && is_int($retry) && $retry !== $chained
               && array_keys($Timers) === [$retry]
               && $Monotonic === []
               && $Server->Status === Status::Paused
               && isset($reads[$socket]) === false
               && $Refusals() === 5,
            description: 'two refused resume() calls in a row leave exactly one armed chain: the first retry is cancelled, one timer is pending '
               . "(status={$Server->Status->name}, timers=" . count($Timers) . ", refusals={$Refusals()})"
         );

         // # G) The single chain fires into a table still full: it alone re-arms
         $Fire($Selector);
         $reads = $ReadsProperty->getValue($Selector);
         $Timers = $TimersProperty->getValue($Selector);
         $rearmed = array_key_first($Timers);

         yield assert(
            assertion: $Server->Status === Status::Paused
               && isset($reads[$socket]) === false
               && count($Timers) === 1 && $rearmed !== $retry
               && $RetryProperty->getValue($Server) === $rearmed
               && $Refusals() === 5,
            description: 'the one chain fired into a table still full re-arms a single timer, not one per refused resume() '
               . "(status={$Server->Status->name}, timers=" . count($Timers) . ')'
         );

         // # G) An entry frees: the chain promotes the worker to Running once and leaves nothing behind
         $Selector->del($Pair[1], Select::EVENT_READ);
         $Fire($Selector);
         $reads = $ReadsProperty->getValue($Selector);
         $reading = $ReadingProperty->getValue($Selector);
         $Timers = $TimersProperty->getValue($Selector);

         yield assert(
            assertion: $Server->Status === Status::Running
               && ($reads[$socket] ?? null) === $Socket
               && ($reading[$socket] ?? null) === $Router
               && count($reads) === Select::CAPACITY
               && $Timers === []
               && $RetryProperty->getValue($Server) === null
               && $Refusals() === 5,
            description: 'once an entry frees, the single chain re-registers the socket and promotes the worker to Running, with no chain left armed '
               . "(status={$Server->Status->name}, timers=" . count($Timers) . ')'
         );

         // # H) Refused, the entry frees, then a second resume() succeeds: it disarms the chain
         $paused = $Server->pause();
         $waited = $Selector->add($Pair[1], Select::EVENT_READ, null);
         $refused = $Server->resume();
         $armed = $RetryProperty->getValue($Server);
         $Timers = $TimersProperty->getValue($Selector);
         $deferred = is_int($armed) && array_keys($Timers) === [$armed];
         $Selector->del($Pair[1], Select::EVENT_READ);
         $resumed = $Server->resume();
         $retry = $RetryProperty->getValue($Server);
         $reads = $ReadsProperty->getValue($Selector);
         $reading = $ReadingProperty->getValue($Selector);
         $Timers = $TimersProperty->getValue($Selector);
         $Monotonic = $MonotonicTimersProperty->getValue($Selector);

         yield assert(
            assertion: $paused === true && $waited === true
               && $refused === false && $deferred === true
               && $resumed === true
               && $Server->Status === Status::Running
               && ($reads[$socket] ?? null) === $Socket
               && ($reading[$socket] ?? null) === $Router
               && $retry === null
               && $Timers === [] && $Monotonic === []
               && $Refusals() === 6,
            description: 'a resume() that succeeds after a refused one re-registers the socket, reports Running and cancels the armed retry, leaving no timer pending '
               . "(status={$Server->Status->name}, retry=" . ($retry ?? 'null') . ', timers=' . count($Timers) . ", refusals={$Refusals()})"
         );

         // # H) The next pause() is a real one: it deregisters the socket and sets Paused
         $repaused = $Server->pause();
         $reads = $ReadsProperty->getValue($Selector);
         $reading = $ReadingProperty->getValue($Selector);
         $Timers = $TimersProperty->getValue($Selector);

         yield assert(
            assertion: $repaused === true
               && $Server->Status === Status::Paused
               && isset($reads[$socket]) === false
               && isset($reading[$socket]) === false
               && count($reads) === Select::CAPACITY - 1
               && $RetryProperty->getValue($Server) === null
               && $Timers === []
               && $Refusals() === 6,
            description: 'the pause() after that resume() deregisters the socket and leaves the worker Paused, not Running behind a stale retry '
               . "(status={$Server->Status->name}, timers=" . count($Timers) . ')'
         );

         // # H) The reactor ticks with room in the table: nothing brings the socket back
         $Fire($Selector);
         $reads = $ReadsProperty->getValue($Selector);
         $Timers = $TimersProperty->getValue($Selector);

         yield assert(
            assertion: $Server->Status === Status::Paused
               && isset($reads[$socket]) === false
               && count($reads) === Select::CAPACITY - 1
               && $Timers === []
               && $RetryProperty->getValue($Server) === null
               && $Refusals() === 6,
            description: 'a reactor tick after that pause() leaves the socket out and the worker Paused: no retry of the earlier refusal survives to resume it '
               . "(status={$Server->Status->name}, timers=" . count($Timers) . ')'
         );
      }
      finally {
         if ($Process !== null && $master !== 0) {
            $MasterProperty->setValue($Process, $master);
         }
         if ($PreviousEvent !== null) {
            UDPServer::$Event = $PreviousEvent;
         }
         else if ($ConstructedEvent !== null) {
            UDPServer::$Event = $ConstructedEvent;
         }
         Logger::$Sinks = $PreviousSinks;
         Display::show($display);
         Vars::$debug = $debug;
         Vars::$print = $print;
         Vars::$exit = $exit;
         if (is_resource($Socket)) {
            fclose($Socket);
         }
         if ($Pair !== false) {
            fclose($Pair[0]);
            fclose($Pair[1]);
         }
      }
   }
);
