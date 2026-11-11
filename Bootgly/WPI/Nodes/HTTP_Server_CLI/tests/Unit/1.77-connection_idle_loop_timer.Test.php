<?php

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Events\Timer\Registry;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Connections\Peer;
use Bootgly\WPI\Events;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;


/**
 * The idle expiration of a TCP connection has one owner per worker: the event
 * loop when it offers recurring timers (`Repeating`), the static `Timer`
 * otherwise — never both. On the loop the worker runs ONE supervision sweep,
 * a one-second recurring timer, whatever the number of connections: each
 * connection carries the wall second of its next periodic check
 * (`Connection::$due`) and no static task is registered, so the worker's
 * SIGALRM stays unarmed.
 *
 * The private `Select::tick()` is what `loop()` calls on every iteration; it
 * is driven by hand here, against the real clock.
 */
return new Test(
   description: 'A TCP connection should keep its idle expiration on the one supervision sweep of a loop that offers recurring timers, on the static Timer otherwise, and leave it on every close path',
   test: new Assertions(Case: function (): Generator {
      $Reflection = new ReflectionClass(Select::class);
      $tick = $Reflection->getMethod('tick');
      $Repeaters = $Reflection->getProperty('Repeaters');
      $Supervised = new ReflectionProperty(Connections::class, 'Supervised');
      $supervised = static fn (Connection $Connection): bool
         => isset($Supervised->getValue()[spl_object_id($Connection)]);
      $Reporters = Throwables::$reporters;
      // ! A loop with no timers of its own: the connection stays on the static Timer
      $Legacy = new class implements Events, Loops, Scheduler {
         /** @var array<int> */
         public array $cancelled = [];
         public function add ($Socket, int $flag, $payload): bool { return true; }
         public function del ($Socket, int $flag): bool { return true; }
         public function loop (): void {}
         public function destroy (): void {}
         public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool { return false; }
         public function defer (float|int $deadline, Closure $Callback): int { return 0; }
         public function cancel (int $ID): bool { $this->cancelled[] = $ID; return false; }
         public function interrupt (Fiber $Fiber, Throwable $Throwable): bool { return false; }
      };
      // ! A sleep a signal cannot cut short
      $wait = static function (int $until): void {
         while (($left = $until - (int) hrtime(true)) > 0) {
            usleep((int) max(1, min(20_000, $left / 1_000)));
         }
      };
      $Sockets = [];
      $Connecteds = [];
      // ! A real established loopback pair: the Connection needs a peer name
      $open = static function () use (&$Sockets, &$Connecteds): Connection {
         $Listener = stream_socket_server('tcp://127.0.0.1:0');
         if ($Listener === false) {
            throw new RuntimeException('Could not create the loopback listener.');
         }
         $Sockets[] = $Listener;
         $Client = stream_socket_client('tcp://' . stream_socket_get_name($Listener, false), $code, $message, 2.0);
         $Accepted = stream_socket_accept($Listener, 2.0);
         if ($Client === false || $Accepted === false) {
            throw new RuntimeException('Could not establish the loopback pair.');
         }
         $Sockets[] = $Client;
         $Sockets[] = $Accepted;
         [$IP, $port] = Peer::parse((string) stream_socket_get_name($Accepted, true));

         $Connection = new Connection($Accepted, $IP, $port);
         $Connecteds[] = $Connection;

         return $Connection;
      };
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $NS = 1_000_000_000;

      try {
         // ! Hermetic timer and server statics (same pattern as 1.4)
         Timer::del();
         TCP_Server_CLI::$context = [];
         $Server = new TCP_Server_CLI;
         $Connections = new Connections($Server);
         $Loop = new Select($Connections);
         TCP_Server_CLI::$Event = $Loop;

         $intervals = static fn (Select $Loop): array => array_values(array_column($Repeaters->getValue($Loop), 'interval'));

         // @@ A) The loop is the owner: one sweep for the worker, no static
         //       task, so no alarm is armed
         TCP_Server_CLI::$connectionIdleTimeout = 1;
         $idle = $intervals($Loop);
         $registered = (int) hrtime(true);
         $Idle = $open();
         $Busy = $open();
         $ahead = [$Idle->due - time(), $Busy->due - time()];

         yield assert(
            assertion: $idle === [] && $intervals($Loop) === [$NS]
               && $ahead[0] >= 0 && $ahead[0] <= 1 && $ahead[1] >= 0 && $ahead[1] <= 1
               && $supervised($Idle) && $supervised($Busy)
               && $Idle->timers === [] && $Busy->timers === []
               && Registry::snapshot() === [],
            description: 'on a loop with recurring timers the first connection arms one one-second sweep for the worker, every connection after it only joins — a due second each, no loop timer and no static Timer task of its own — observed: '
               . json_encode(['idle' => $idle, 'loop' => $intervals($Loop), 'ahead' => $ahead, 'timers' => [$Idle->timers, $Busy->timers], 'static' => Registry::snapshot()])
         );

         // @@ B) Idle expiration on the sweep: not before the interval, then
         //       the silent connection goes and the one that wrote is renewed once
         $wait($registered + (int) (0.5 * $NS));
         $tick->invoke($Loop);
         $early = [$Idle->status, $Busy->status];
         $Busy->writes++;
         $wait($registered + (int) (1.1 * $NS));
         $tick->invoke($Loop);
         $first = [$Idle->status, $Busy->status, $Idle->due, $supervised($Idle), $supervised($Busy)];
         $wait($registered + (int) (2.2 * $NS));
         $tick->invoke($Loop);
         $second = [$Busy->status, $Busy->due, $supervised($Busy), $intervals($Loop)];

         yield assert(
            assertion: $early === [Connections::STATUS_ESTABLISHED, Connections::STATUS_ESTABLISHED]
               && $first === [Connections::STATUS_CLOSED, Connections::STATUS_ESTABLISHED, 0, false, true]
               && $second === [Connections::STATUS_CLOSED, 0, false, [$NS]],
            description: 'the silent connection is closed by the sweep that follows its interval — released from inside the walk — a write renews the other for exactly one more interval, and the sweep stays armed with nothing left to supervise — observed: '
               . json_encode(['early' => $early, 'first' => $first, 'second' => $second])
         );

         // @@ C) close() takes the connection off the sweep and releases it
         TCP_Server_CLI::$connectionIdleTimeout = 3600;
         $Closed = $open();
         $before = [$Closed->due - time(), $supervised($Closed)];
         $Closed->close();
         $after = [$Closed->due, $supervised($Closed)];
         $Closed->close();
         $Weak = WeakReference::create($Closed);
         array_pop($Connecteds);
         unset($Closed);
         gc_collect_cycles();

         yield assert(
            assertion: $before === [3600, true] && $after === [0, false] && $Weak->get() === null,
            description: 'close() takes the connection off the sweep on its first transition — a second close is a no-op and the closed connection is collected — observed: '
               . json_encode(['before' => $before, 'after' => $after, 'released' => $Weak->get() === null])
         );

         // @@ D) An expiration the loop cannot schedule leaves no check, anywhere
         TCP_Server_CLI::$connectionIdleTimeout = (int) (Select::HORIZON * 2);
         $Eternal = $open();
         $eternal = [$Eternal->due, $supervised($Eternal), $Eternal->timers, Registry::snapshot()];
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         $Disabled = $open();
         $disabled = [$Disabled->due, $supervised($Disabled), $Disabled->timers, Registry::snapshot()];

         yield assert(
            assertion: $eternal === [0, false, [], []] && $disabled === [0, false, [], []],
            description: 'an expiration beyond the loop horizon, like a disabled one, leaves the connection off the sweep and registers nothing on the static Timer — observed: '
               . json_encode(['eternal' => $eternal, 'disabled' => $disabled])
         );

         // @@ E) watch(): a protocol's own check takes the place of the idle
         //       expiration; a throwing check is reported and starves no other;
         //       a non-positive interval stops the check
         $Watched = $open();
         $Beside = $open();
         $runs = [0, 0];
         $Reported = [];
         Throwables::$reporters = [static function (Throwable $Throwable, array $context) use (&$Reported): void {
            $Reported[] = [$Throwable->getMessage(), array_keys($context), $context['origin'] ?? null];
         }];
         $installed = [
            $Watched->watch(1, static function () use (&$runs): void {
               $runs[0]++;
               throw new RuntimeException('check');
            }),
            $Beside->watch(1, static function () use (&$runs): void {
               $runs[1]++;
            }),
            $supervised($Watched),
            $supervised($Beside)
         ];
         // ! Not due yet: the direct call is a no-op, like the sweep's
         $Watched->inspect($Watched->due - 1);
         $premature = $runs;
         $registered = (int) hrtime(true);
         $escaped = false;
         try {
            $wait($registered + (int) (2.1 * $NS));
            $tick->invoke($Loop);
         }
         catch (Throwable) {
            $escaped = true;
         }
         Throwables::$reporters = $Reporters;
         $ran = $runs;
         // ! A sweep that keeps running is never armed anew: one more
         //   connection, seconds after it was armed, joins the same timer
         $sweep = array_keys($Repeaters->getValue($Loop));
         TCP_Server_CLI::$connectionIdleTimeout = 3600;
         $Joined = $open();
         $joined = [$supervised($Joined), array_keys($Repeaters->getValue($Loop)) === $sweep];
         $rearmed = $Watched->due - time();
         $stopped = [$Watched->watch(0), $Watched->due, $supervised($Watched)];
         $Watched->inspect(time() + 3600);
         $Beside->close();
         $late = [$Beside->watch(1), $Beside->due, $supervised($Beside)];

         yield assert(
            assertion: $installed === [true, true, true, true] && $premature === [0, 0]
               && $escaped === false && $ran === [1, 1]
               && $Reported === [['check', ['origin', 'id'], 'loop.repeat']]
               && $rearmed >= 0 && $rearmed <= 1
               && $Watched->status === Connections::STATUS_ESTABLISHED
               && $stopped === [true, 0, false] && $runs === [1, 1]
               && $late === [true, 0, false] && $joined === [true, true],
            description: 'watch() installs a check of its own in place of the idle expiration: it runs once per interval, a throwing one is reported, re-armed and starves no other; watch(0) stops it, a closed connection is never put back on the sweep, and a running sweep is joined, not armed anew — observed: '
               . json_encode(['joined' => $joined, 'installed' => $installed, 'premature' => $premature, 'escaped' => $escaped, 'ran' => $ran, 'reported' => $Reported, 'rearmed' => $rearmed, 'stopped' => $stopped, 'runs' => $runs, 'late' => $late])
         );

         // @@ E2) A check is re-armed from the clock once it ran, not from the
         //        second its sweep started at: a sweep that read the clock
         //        seconds ago (earlier checks of the walk took time) must not
         //        leave the next check overdue — a WebSocket supervisor would
         //        run again at once and drop a peer it has just pinged
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         $Due = new ReflectionProperty(Connection::class, 'due');
         $Paced = $open();
         $paces = 0;
         $Paced->watch(2, static function () use (&$paces): void {
            $paces++;
         });
         // ! Bracketed by one wall second, so the comparison is exact
         do {
            $second = time();
            $Due->setValue($Paced, $second - 3);
            $Paced->inspect($second - 3);
         }
         while (time() !== $second);
         $paced = [$paces >= 1, $Paced->due - $second];
         // ! The idle expiration, with activity seen by the late check
         TCP_Server_CLI::$connectionIdleTimeout = 2;
         $Renewed = $open();
         do {
            $second = time();
            $Renewed->writes++;
            $Due->setValue($Renewed, $second - 3);
            $Renewed->inspect($second - 3);
         }
         while (time() !== $second);
         $renewed = [$Renewed->status, $Renewed->used - $second, $Renewed->due - $second];
         // ! A check that stops itself stays stopped
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         $Stopper = $open();
         $Stopper->watch(2, static function () use ($Stopper): void {
            $Stopper->watch(0);
         });
         $Due->setValue($Stopper, time() - 3);
         $Stopper->inspect(time());
         $stopper = [$Stopper->due, $supervised($Stopper), $Stopper->status];

         yield assert(
            assertion: $paced === [true, 2]
               && $renewed === [Connections::STATUS_ESTABLISHED, 0, 2]
               && $stopper === [0, false, Connections::STATUS_ESTABLISHED],
            description: 'a check run by a sweep whose second is stale is re-armed one full interval from the clock — its own check and the idle expiration alike — and a check that stops itself is not re-armed — observed: '
               . json_encode(['paced' => $paced, 'renewed' => $renewed, 'stopper' => $stopper])
         );

         // @@ F) The sweep follows the worker's loop: another loop gets a timer
         //       of its own, over its own connections only; a loop whose owner
         //       already runs the sweep — the worker's ticker does — is joined
         //       with no timer armed, and that sweep is what reaps
         TCP_Server_CLI::$connectionIdleTimeout = 3600;
         $Other = new Select($Connections);
         TCP_Server_CLI::$Event = $Other;
         $Moved = $open();
         $moved = [$intervals($Other), $supervised($Moved), $supervised($Joined), count($Supervised->getValue())];
         $Other->destroy();

         $Driven = new Select($Connections);
         TCP_Server_CLI::$Event = $Driven;
         Connections::sweep();
         TCP_Server_CLI::$connectionIdleTimeout = 1;
         $registered = (int) hrtime(true);
         $Ridden = $open();
         $ridden = [$intervals($Driven), $supervised($Ridden), $supervised($Moved)];
         Connections::sweep();
         $ridden[] = $Ridden->status;
         $wait($registered + (int) (1.1 * $NS));
         Connections::sweep();
         $ridden[] = $Ridden->status;
         $ridden[] = $intervals($Driven);
         $Driven->destroy();
         TCP_Server_CLI::$Event = $Loop;

         yield assert(
            assertion: $moved === [[$NS], true, false, 1]
               && $ridden === [[], true, false, Connections::STATUS_ESTABLISHED, Connections::STATUS_CLOSED, []],
            description: 'a different loop gets a sweep timer of its own over its own connections only; on a loop whose owner already calls sweep() a connection joins with no timer armed, and that call reaps it after its interval — observed: '
               . json_encode(['moved' => $moved, 'ridden' => $ridden])
         );

         // @@ G) A loop without the capability keeps the static Timer, alone
         TCP_Server_CLI::$Event = $Legacy;
         TCP_Server_CLI::$connectionIdleTimeout = 3600;
         $Static = $open();
         $ids = $Static->timers;
         $static = [count($ids), $Static->due, $supervised($Static), $ids === Registry::snapshot(), $Static->watch(5)];
         $Static->close();
         $released = [$Static->timers, Registry::snapshot(), $Legacy->cancelled];

         yield assert(
            assertion: $static === [1, 0, false, true, false] && $released === [[], [], []],
            description: 'on a loop without recurring timers the expiration is one static Timer task, deleted on close; watch() installs nothing and says so, and the loop is asked to cancel nothing — observed: '
               . json_encode(['static' => $static, 'released' => $released])
         );

         // @@ H) An instance that never ran its constructor still destructs
         $Shell = new ReflectionClass(Connection::class)->newInstanceWithoutConstructor();
         $Shell->timers = [];
         $failure = '';
         try {
            unset($Shell);
         }
         catch (Throwable $Throwable) {
            $failure = $Throwable->getMessage();
         }

         yield assert(
            assertion: $failure === '',
            description: "a connection built without its constructor (test doubles do) destructs cleanly — observed: {$failure}"
         );
      }
      finally {
         foreach ($Connecteds as $Connection) {
            try {
               $Connection->close();
            }
            catch (Throwable) {
               // ...
            }
         }
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
         Throwables::$reporters = $Reporters;
         Timer::del();
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
      }
   })
);
