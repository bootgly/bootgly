<?php


use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events as WPIEvents;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * An evicted Fiber is never resumed — and it must not linger either. The
 * reactor takes its own reference before dropping any seat (the graveyard),
 * and releases it at a safe point outside every queue walk: PHP then unwinds
 * the suspended Fiber through its finally blocks (never its catch), right
 * there, without waiting for the cycle collector. A finally that calls back
 * into the reactor finds consistent queues; a throwing finally is contained.
 */
return new Test(
   description: 'Select should release an evicted Fiber at the reactor safe point so its finally runs promptly — never its catch',
   test: new Assertions(Case: function (): Generator {
      $Reflection = new ReflectionClass(Select::class);
      $Bind = $Reflection->getProperty('Bindings');
      $Await = $Reflection->getProperty('awaitingReads');
      $Reads = $Reflection->getProperty('reads');
      $Ticks = $Reflection->getProperty('Fibers');
      $Graveyard = $Reflection->getProperty('Graveyard');
      $Seats = new ReflectionProperty(Response::class, 'Fibers');

      $Sockets = [];
      $Selects = [];
      $Noop = static function (): void {};

      $pair = static function () use (&$Sockets): array {
         $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
         if ($pair === false) {
            throw new RuntimeException('Graveyard fixture could not allocate a socket pair.');
         }
         $Sockets[] = $pair;

         return $pair;
      };
      $reactor = static function () use (&$Selects): Select {
         $Server = new TCP_Server_CLI;
         $Connections = new Connections($Server);
         $Select = new Select($Connections);
         $Selects[] = $Select;

         return $Select;
      };
      // ! One reactor turn: the stop timer fires at the FIRST tick after 20 ms
      $turn = static function (Select $Select, array &$log): void {
         $Select->defer(microtime(true) + 0.020, static function () use ($Select, &$log): void {
            $log[] = 'stop';
            $Select->loop = false; // @phpstan-ignore-line (property on the Select impl)
         });
         $Select->loop = true; // @phpstan-ignore-line (property on the Select impl)
         $Select->loop();
      };
      // ! Park a Fiber exactly as defer() does: open its generation, bind it
      //   (the Leave closure captures the Fiber, like production), schedule it
      $park = static function (Select $Select, Fiber $Fiber, mixed $value) use ($Noop): Cancellation {
         $Token = Cancellation::open($Fiber);
         $Select->bind($Fiber, $Noop, static function () use ($Fiber): void {});
         $Select->schedule($Fiber, $value);

         return $Token;
      };

      try {
         // @@ A) Released at the safe point: finally runs there, catch never
         $Select = $reactor();
         $log = [];
         $inside = false;
         [$socket] = $pair();
         $Fiber = new Fiber(static function () use ($socket, &$log, &$inside): void {
            try {
               Fiber::suspend(Readiness::read($socket));
               $log[] = 'resumed';
            }
            catch (Throwable) {
               $log[] = 'caught';
            }
            finally {
               $log[] = 'finally';
               $log[] = $inside ? 'inside' : 'outside';
            }
         });
         $suspended = $Fiber->start();
         $Token = $park($Select, $Fiber, $suspended);
         $Weak = WeakReference::create($Fiber);
         unset($Fiber);

         $inside = true;
         $Token->cancel();
         $inside = false;

         $held = $Weak->get() !== null;
         $buried = count($Graveyard->getValue($Select));
         $queued = count($Await->getValue($Select)) + count($Reads->getValue($Select)) + count($Bind->getValue($Select));
         $before = $log;

         $turn($Select, $log);

         yield assert(
            assertion: $held && $buried === 1 && $queued === 0 && $before === []
               && $log === ['finally', 'outside', 'stop']
               && $Weak->get() === null
               && $Graveyard->getValue($Select) === [],
            description: 'cancelling a parked generation seats it in the graveyard with every queue already clear; the next turn releases it before the timer, its finally runs outside the eviction and its catch never — observed: '
               . json_encode(['held' => $held, 'buried' => $buried, 'queued' => $queued, 'before' => $before, 'log' => $log, 'released' => $Weak->get() === null])
         );

         // @@ A-2) Bound but never queued: the Leave closure is the only seat
         $log = [];
         $Lone = new Fiber(static function () use (&$log): void {
            try {
               Fiber::suspend();
            }
            finally {
               $log[] = 'lone-finally';
            }
         });
         $Lone->start();
         $loneToken = Cancellation::open($Lone);
         $Select->bind($Lone, $Noop, static function () use ($Lone): void {});
         $Weak = WeakReference::create($Lone);
         unset($Lone);

         $loneToken->cancel();
         $held = $Weak->get() !== null;

         $turn($Select, $log);

         yield assert(
            assertion: $held && $log === ['lone-finally', 'stop'] && $Weak->get() === null,
            description: 'a generation whose only seat was its binding still reaches the graveyard and is released at the safe point — observed: '
               . json_encode(['held' => $held, 'log' => $log, 'released' => $Weak->get() === null])
         );

         // @@ A-3) Evicted by a sibling inside the same tick batch
         //   P is parked FIRST so the batch resumes it before Q; Q is tick-
         //   parked too, and would otherwise be resumed (and finish) on its own
         $log = [];
         // ! A witness parked on an ALREADY readable descriptor: the reactor
         //   can only resume it after it has waited on the selector again, so
         //   its marker fences this iteration's safe point off from the next
         //   batch walk — the walk temporaries must be released before it
         [$readable, $peer] = $pair();
         fwrite($peer, 'x');
         $W = new Fiber(static function () use ($readable, &$log): void {
            Fiber::suspend(Readiness::read($readable));
            $log[] = 'W-dispatched';
         });
         $park($Select, $W, $W->start());
         $qToken = null;
         $P = new Fiber(static function () use (&$qToken, &$log): void {
            Fiber::suspend();
            $log[] = 'P-cancels-Q';
            $qToken?->cancel();
            Fiber::suspend();
         });
         $park($Select, $P, $P->start());
         $Q = new Fiber(static function () use (&$log): void {
            try {
               Fiber::suspend();
            }
            finally {
               $log[] = 'Q-finally';
            }
         });
         $Q->start();
         $qToken = $park($Select, $Q, null);
         $Weak = WeakReference::create($Q);
         unset($Q);

         $turn($Select, $log);
         $positions = array_flip($log);

         yield assert(
            assertion: isset($positions['Q-finally'], $positions['stop'], $positions['W-dispatched'])
               && $positions['P-cancels-Q'] < $positions['Q-finally']
               && $positions['Q-finally'] < $positions['W-dispatched']
               && $positions['Q-finally'] < $positions['stop']
               && $Weak->get() === null,
            description: 'a generation evicted by a sibling in the tick batch is released before the reactor waits again — observed: '
               . json_encode(['log' => $log, 'released' => $Weak->get() === null])
         );
         $Select->destroy();

         // @@ A-4) Evicted by a sibling that TERMINATES in the same tick batch
         //   No later batch walk overwrites the temporaries: only the explicit
         //   release after the walk can free the grave before the stop timer
         $Select = $reactor();
         $log = [];
         $qToken = null;
         $P = new Fiber(static function () use (&$qToken, &$log): void {
            Fiber::suspend();
            $log[] = 'P-cancels-Q';
            $qToken?->cancel();
         });
         $park($Select, $P, $P->start());
         $Q = new Fiber(static function () use (&$log): void {
            try {
               Fiber::suspend();
            }
            finally {
               $log[] = 'Q-finally';
            }
         });
         $Q->start();
         $qToken = $park($Select, $Q, null);
         $Weak = WeakReference::create($Q);
         unset($Q);

         $turn($Select, $log);
         $positions = array_flip($log);

         yield assert(
            assertion: isset($positions['Q-finally'], $positions['stop'])
               && $positions['P-cancels-Q'] < $positions['Q-finally']
               && $positions['Q-finally'] < $positions['stop']
               && $Weak->get() === null,
            description: 'the batch walk temporaries are released even when no later batch overwrites them — observed: '
               . json_encode(['log' => $log, 'released' => $Weak->get() === null])
         );
         $Select->destroy();

         // @@ A-5) Evicted inside the READ dispatch: two waiters on one readable
         //   descriptor, the first cancels the second and terminates
         $Select = $reactor();
         $log = [];
         [$readable, $peer] = $pair();
         $yToken = null;
         $X = new Fiber(static function () use ($readable, &$yToken, &$log): void {
            Fiber::suspend(Readiness::read($readable));
            $log[] = 'X-cancels-Y';
            $yToken?->cancel();
         });
         $park($Select, $X, $X->start());
         $Y = new Fiber(static function () use ($readable, &$log): void {
            try {
               Fiber::suspend(Readiness::read($readable));
            }
            finally {
               $log[] = 'Y-finally';
            }
         });
         $yToken = $park($Select, $Y, $Y->start());
         $Weak = WeakReference::create($Y);
         unset($Y);
         fwrite($peer, 'x');

         $turn($Select, $log);
         $positions = array_flip($log);

         yield assert(
            assertion: isset($positions['Y-finally'], $positions['stop'])
               && $positions['X-cancels-Y'] < $positions['Y-finally']
               && $positions['Y-finally'] < $positions['stop']
               && $Weak->get() === null,
            description: 'the read-dispatch temporaries are released before the reactor waits again — observed: '
               . json_encode(['log' => $log, 'released' => $Weak->get() === null])
         );
         $Select->destroy();

         // @@ A-6) The same shape on the WRITE dispatch
         $Select = $reactor();
         $log = [];
         [$writable] = $pair();
         $yToken = null;
         $X = new Fiber(static function () use ($writable, &$yToken, &$log): void {
            Fiber::suspend(Readiness::write($writable));
            $log[] = 'X-cancels-Y';
            $yToken?->cancel();
         });
         $park($Select, $X, $X->start());
         $Y = new Fiber(static function () use ($writable, &$log): void {
            try {
               Fiber::suspend(Readiness::write($writable));
            }
            finally {
               $log[] = 'Y-finally';
            }
         });
         $yToken = $park($Select, $Y, $Y->start());
         $Weak = WeakReference::create($Y);
         unset($Y);

         $turn($Select, $log);
         $positions = array_flip($log);

         yield assert(
            assertion: isset($positions['Y-finally'], $positions['stop'])
               && $positions['X-cancels-Y'] < $positions['Y-finally']
               && $positions['Y-finally'] < $positions['stop']
               && $Weak->get() === null,
            description: 'the write-dispatch temporaries are released before the reactor waits again — observed: '
               . json_encode(['log' => $log, 'released' => $Weak->get() === null])
         );
         $Select->destroy();

         // @@ A-7) A grave dug by the LAST dispatch before the loop stops is
         //   released before loop() returns
         $Select = $reactor();
         $log = [];
         [$readable, $peer] = $pair();
         $yToken = null;
         $X = new Fiber(static function () use ($Select, $readable, &$yToken, &$log): void {
            Fiber::suspend(Readiness::read($readable));
            $log[] = 'X-stops';
            $yToken?->cancel();
            $Select->loop = false; // @phpstan-ignore-line (property on the Select impl)
         });
         $park($Select, $X, $X->start());
         [$never] = $pair();
         $Y = new Fiber(static function () use ($never, &$log): void {
            try {
               Fiber::suspend(Readiness::read($never));
            }
            finally {
               $log[] = 'Y-finally';
            }
         });
         $yToken = $park($Select, $Y, $Y->start());
         $Weak = WeakReference::create($Y);
         unset($Y);
         fwrite($peer, 'x');
         $Select->loop = true; // @phpstan-ignore-line (property on the Select impl)
         $Select->loop();
         $released = $Weak->get() === null;

         yield assert(
            assertion: $released && $log === ['X-stops', 'Y-finally'],
            description: 'a generation evicted by the last dispatch is released before loop() returns — observed: '
               . json_encode(['log' => $log, 'released' => $released])
         );
         $Select->destroy();

         // @@ A-8) A one-shot armed by an unwinding finally is honoured by the
         //   very select that follows the safe point — the wait is recomputed
         //   after the release, so the timer fires now, not at the long stop
         $Select = $reactor();
         $log = [];
         [$idle] = $pair();
         $Select->add($idle, WPIEvents::EVENT_READ, $Noop);
         [$never] = $pair();
         $Armed = new Fiber(static function () use ($Select, $never, &$log): void {
            try {
               Fiber::suspend(Readiness::read($never));
            }
            finally {
               $log[] = 'finally';
               $Select->defer(microtime(true) + 0.010, static function () use ($Select, &$log): void {
                  $log[] = 'armed-timer';
                  $Select->loop = false; // @phpstan-ignore-line (property on the Select impl)
               });
            }
         });
         $armedToken = $park($Select, $Armed, $Armed->start());
         $Weak = WeakReference::create($Armed);
         unset($Armed);
         $armedToken->cancel();
         // ! The long stop is the hang guard: a stale wait would block the
         //   selector on the idle descriptor until it fires
         $Select->defer(microtime(true) + 2.0, static function () use ($Select, &$log): void {
            $log[] = 'stop';
            $Select->loop = false; // @phpstan-ignore-line (property on the Select impl)
         });
         $started = microtime(true);
         $Select->loop = true; // @phpstan-ignore-line (property on the Select impl)
         $Select->loop();
         $elapsed = microtime(true) - $started;

         yield assert(
            assertion: $log === ['finally', 'armed-timer'] && $elapsed < 0.5 && $Weak->get() === null,
            description: 'a timer armed by an unwinding finally fires on the next wakeup, not after the stale wait — observed: '
               . json_encode(['log' => $log, 'elapsed' => round($elapsed, 3), 'released' => $Weak->get() === null])
         );
         $Select->destroy();

         // @@ B) The real Response::wait() frame must not pin its own Fiber
         $Select = $reactor();
         $log = [];
         [$socket] = $pair();
         $HTTPResponse = new Response;
         $Storage = $Seats->getValue($HTTPResponse);
         $Waiter = new Fiber(static function () use ($HTTPResponse, $socket, &$log): void {
            try {
               $HTTPResponse->wait(Readiness::read($socket));
               $log[] = 'resumed';
            }
            finally {
               $log[] = 'wait-finally';
            }
         });
         $Storage->offsetSet($Waiter, true);
         $suspended = $Waiter->start();
         $waiterToken = $park($Select, $Waiter, $suspended);
         $Weak = WeakReference::create($Waiter);
         // ! Unseat weakly on settle, as defer() does
         $waiterToken->observe(static function () use ($Storage, $Weak): void {
            $Parked = $Weak->get();
            if ($Parked !== null && $Storage->offsetExists($Parked)) {
               $Storage->offsetUnset($Parked);
            }
         });
         unset($Waiter);

         $waiterToken->cancel();
         $held = $Weak->get() !== null;

         $turn($Select, $log);

         yield assert(
            assertion: $held && $log === ['wait-finally', 'stop'] && $Weak->get() === null,
            description: 'a Fiber parked inside Response::wait() is released at the safe point without the cycle collector — observed: '
               . json_encode(['held' => $held, 'log' => $log, 'released' => $Weak->get() === null])
         );
         $Select->destroy();

         // @@ C) A finally that calls back into the reactor finds consistent queues
         $Select = $reactor();
         $log = [];
         $warnings = [];
         [$socketX] = $pair();
         [$socketY] = $pair();
         [$socketP] = $pair();
         $Select->add($socketP, WPIEvents::EVENT_READ, $Noop);
         $Y = new Fiber(static function () use ($socketY, &$log): void {
            try {
               Fiber::suspend(Readiness::read($socketY));
            }
            finally {
               $log[] = 'Y';
            }
         });
         $yToken = $park($Select, $Y, $Y->start());
         $weakY = WeakReference::create($Y);
         unset($Y);
         $X = new Fiber(static function () use ($Select, $socketX, $socketP, $yToken, &$log): void {
            try {
               Fiber::suspend(Readiness::read($socketX));
            }
            finally {
               $Select->del($socketP, WPIEvents::EVENT_READ);
               $Select->defer(microtime(true), static function () use (&$log): void {
                  $log[] = 'timer';
               });
               $yToken->cancel();
               $log[] = 'X';
            }
         });
         $xToken = $park($Select, $X, $X->start());
         $weakX = WeakReference::create($X);
         unset($X);

         $xToken->cancel();
         set_error_handler(static function (int $code, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
         });
         try {
            $turn($Select, $log);
         }
         finally {
            restore_error_handler();
         }
         $positions = array_flip($log);

         yield assert(
            assertion: isset($positions['X'], $positions['Y'], $positions['stop'], $positions['timer'])
               && $positions['X'] < $positions['Y']
               && $positions['Y'] < $positions['stop']
               && $warnings === []
               && isset($Reads->getValue($Select)[(int) $socketP]) === false
               && $Await->getValue($Select) === []
               && $Ticks->getValue($Select) === []
               && $Bind->getValue($Select) === []
               && $Graveyard->getValue($Select) === []
               && $weakX->get() === null && $weakY->get() === null,
            description: 'a finally that removes a descriptor, arms a timer and cancels a sibling leaves the reactor consistent and the sibling is reaped in the same drain — observed: '
               . json_encode(['log' => $log, 'warnings' => $warnings, 'reads' => count($Reads->getValue($Select)), 'awaiting' => count($Await->getValue($Select)), 'ticks' => count($Ticks->getValue($Select)), 'bindings' => count($Bind->getValue($Select)), 'graveyard' => count($Graveyard->getValue($Select))])
         );
         $Select->destroy();

         // @@ D) A throwing or suspending finally is contained
         $Select = $reactor();
         $log = [];
         $Weaks = [];
         $bodies = [
            static function () use (&$log): void {
               try {
                  Fiber::suspend();
               }
               finally {
                  $log[] = 'F1';
                  throw new RuntimeException('finally failed');
               }
            },
            static function () use (&$log): void {
               try {
                  Fiber::suspend();
               }
               finally {
                  try {
                     Fiber::suspend();
                  }
                  catch (FiberError) {
                     $log[] = 'suspend-refused';
                  }
               }
            },
            static function () use (&$log): void {
               try {
                  Fiber::suspend();
               }
               finally {
                  $log[] = 'F3';
               }
            },
         ];
         $Tokens = [];
         foreach ($bodies as $body) {
            $Fiber = new Fiber($body);
            $Tokens[] = $park($Select, $Fiber, $Fiber->start());
            $Weaks[] = WeakReference::create($Fiber);
            unset($Fiber);
         }
         foreach ($Tokens as $Token) {
            $Token->cancel();
         }

         $turn($Select, $log);

         $released = 0;
         foreach ($Weaks as $Weak) {
            if ($Weak->get() === null) {
               $released++;
            }
         }
         $positions = array_flip($log);

         yield assert(
            assertion: $released === 3
               && isset($positions['F1'], $positions['suspend-refused'], $positions['F3'], $positions['stop']),
            description: 'a throwing finally and a suspending finally are contained; every grave is still released — observed: '
               . json_encode(['released' => $released, 'log' => $log])
         );
         $Select->destroy();

         // @@ E) destroy() releases what it evicts and contains its unwinds
         $Select = $reactor();
         $log = [];
         [$socket] = $pair();
         $Doomed = new Fiber(static function () use ($socket, &$log): void {
            try {
               Fiber::suspend(Readiness::read($socket));
            }
            finally {
               $log[] = 'doomed-finally';
               throw new RuntimeException('finally failed on shutdown');
            }
         });
         $park($Select, $Doomed, $Doomed->start());
         $Weak = WeakReference::create($Doomed);
         unset($Doomed);

         $escaped = null;
         try {
            $Select->destroy();
         }
         catch (Throwable $Throwable) {
            $escaped = $Throwable::class;
         }

         yield assert(
            assertion: $escaped === null
               && $log === ['doomed-finally']
               && $Weak->get() === null
               && $Graveyard->getValue($Select) === [],
            description: 'destroy() releases every parked generation and contains a throwing finally — observed: '
               . json_encode(['escaped' => $escaped, 'log' => $log, 'released' => $Weak->get() === null])
         );
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
         foreach ($Sockets as $pair) {
            foreach ($pair as $Socket) {
               if (is_resource($Socket)) {
                  fclose($Socket);
               }
            }
         }
      }
   })
);
