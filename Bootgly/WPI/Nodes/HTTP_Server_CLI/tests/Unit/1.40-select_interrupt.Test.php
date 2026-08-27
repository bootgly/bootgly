<?php

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Events\Select;


/**
 * `Scheduler::interrupt()` delivers a Throwable at a parked Fiber's suspension
 * point while its generation is still ACTIVE: the Fiber leaves every queue it
 * occupies, runs its own catch/finally, and whatever it suspends with next is
 * queued again. A terminal generation is never resumed — it is evicted. A
 * Fiber this reactor never seated (pooled, detached, running) is refused.
 */
return new Test(
   description: 'Select::interrupt() should deliver a Throwable at the wait point of a seated Fiber and refuse everything else',
   test: new Assertions(Case: function (): Generator {
      $Reflection = new ReflectionClass(Select::class);
      $queue = $Reflection->getMethod('queue');
      $reject = $Reflection->getMethod('reject');
      $Await = $Reflection->getProperty('awaitingReads');
      $Reads = $Reflection->getProperty('reads');
      $Ticks = $Reflection->getProperty('Fibers');
      $Bind = $Reflection->getProperty('Bindings');

      $Sockets = [];
      $Noop = static function (): void {};

      // ! A Fiber that catches at its wait point, then parks again on a tick
      $catching = static function (&$seen): Fiber {
         $Fiber = new Fiber(static function () use (&$seen): void {
            try {
               Fiber::suspend();
            }
            catch (Throwable $Throwable) {
               $seen = $Throwable;
            }
            Fiber::suspend(null);
         });
         $Fiber->start();

         return $Fiber;
      };
      $pair = static function () use (&$Sockets): array {
         $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
         if ($pair === false) {
            throw new RuntimeException('Select interrupt fixture could not allocate a socket pair.');
         }
         $Sockets[] = $pair;

         return $pair;
      };
      $bind = static function (Select $Select, Fiber $Fiber) use ($Bind, $Noop): Cancellation {
         $Token = Cancellation::open($Fiber);
         $Bindings = $Bind->getValue($Select);
         $Bindings[spl_object_id($Fiber)] = ['Enter' => $Noop, 'Leave' => $Noop, 'Token' => $Token];
         $Bind->setValue($Select, $Bindings);

         return $Token;
      };
      $seated = static function (Select $Select, Fiber $Fiber) use ($Await, $Ticks): array {
         $reads = 0;
         foreach ($Await->getValue($Select) as $Queued) {
            foreach ($Queued as $Waiter) {
               if ($Waiter === $Fiber) {
                  $reads++;
               }
            }
         }
         $ticks = 0;
         foreach ($Ticks->getValue($Select) as $Waiter) {
            if ($Waiter === $Fiber) {
               $ticks++;
            }
         }

         return ['reads' => $reads, 'ticks' => $ticks];
      };

      try {
         // @@ A) A seated Fiber with an ACTIVE generation
         $Select = $Reflection->newInstanceWithoutConstructor();
         $seen = null;
         $Fiber = $catching($seen);
         $Token = $bind($Select, $Fiber);
         [$Socket] = $pair();
         $queue->invoke($Select, $Fiber, $Socket, Select::SCHEDULE_READ);
         $Error = new RuntimeException('deadline');

         $delivered = $Select->interrupt($Fiber, $Error);
         $after = $seated($Select, $Fiber);

         yield assert(
            assertion: $delivered === true
               && $seen === $Error
               && $after === ['reads' => 0, 'ticks' => 1]
               && isset($Reads->getValue($Select)[(int) $Socket]) === false
               && isset($Bind->getValue($Select)[spl_object_id($Fiber)])
               && $Token->check() === false,
            description: 'the Throwable reaches the wait point, the socket seat is withdrawn, the next suspend is parked, the binding and the generation survive — observed: '
               . json_encode(['delivered' => $delivered, 'seen' => $seen === $Error, 'seats' => $after, 'bound' => isset($Bind->getValue($Select)[spl_object_id($Fiber)]), 'settled' => $Token->check()])
         );

         // @@ B) A Fiber this reactor never seated
         $seen = null;
         $Stranger = $catching($seen);

         $delivered = $Select->interrupt($Stranger, new RuntimeException('stranger'));

         $seenBound = null;
         $Bound = $catching($seenBound);
         $bind($Select, $Bound);

         $deliveredBound = $Select->interrupt($Bound, new RuntimeException('bound but never queued'));

         yield assert(
            assertion: $delivered === false && $seen === null
               && $deliveredBound === false && $seenBound === null,
            description: 'an unseated Fiber — unbound or bound but never queued — is refused and receives nothing — observed: '
               . json_encode(['unbound' => $delivered, 'bound' => $deliveredBound])
         );

         // @@ C) A terminal generation is evicted, never resumed
         $seen = null;
         $Settled = $catching($seen);
         $settledToken = $bind($Select, $Settled);
         [$settledSocket] = $pair();
         $queue->invoke($Select, $Settled, $settledSocket, Select::SCHEDULE_READ);
         $settledToken->cancel();

         $delivered = $Select->interrupt($Settled, new RuntimeException('too late'));
         $after = $seated($Select, $Settled);

         yield assert(
            assertion: $delivered === false
               && $seen === null
               && $after === ['reads' => 0, 'ticks' => 0]
               && isset($Bind->getValue($Select)[spl_object_id($Settled)]) === false,
            description: 'a settled generation is evicted from its seats and binding instead of being resumed — observed: '
               . json_encode(['delivered' => $delivered, 'seats' => $after])
         );

         // @@ D) A Fiber that does not catch terminates; its generation is cancelled
         $Bare = new Fiber(static function (): void {
            Fiber::suspend();
         });
         $Bare->start();
         $bareToken = $bind($Select, $Bare);
         [$bareSocket] = $pair();
         $queue->invoke($Select, $Bare, $bareSocket, Select::SCHEDULE_READ);

         $delivered = $Select->interrupt($Bare, new RuntimeException('uncaught'));
         $after = $seated($Select, $Bare);

         yield assert(
            assertion: $delivered === true
               && $Bare->isTerminated()
               && $bareToken->check() === true
               && $after === ['reads' => 0, 'ticks' => 0]
               && isset($Bind->getValue($Select)[spl_object_id($Bare)]) === false,
            description: 'an uncaught Throwable terminates the Fiber, cancels its generation and evicts it — observed: '
               . json_encode(['delivered' => $delivered, 'terminated' => $Bare->isTerminated(), 'settled' => $bareToken->check(), 'seats' => $after])
         );

         // @@ E) A running Fiber cannot interrupt itself
         $Self = new Fiber(static function () use ($Select): void {
            $result = $Select->interrupt(Fiber::getCurrent(), new RuntimeException('self'));
            Fiber::suspend($result);
         });
         $result = $Self->start();

         yield assert(
            assertion: $result === false,
            description: 'a running Fiber is refused — observed: ' . var_export($result, true)
         );

         // @@ F) reject() shares the delivery tail: a catching Fiber is parked again
         $seen = null;
         $Rejected = $catching($seen);
         $bind($Select, $Rejected);

         $rescheduled = $reject->invoke($Select, $Rejected);
         $after = $seated($Select, $Rejected);

         yield assert(
            assertion: $rescheduled === true
               && $seen instanceof RuntimeException
               && str_contains($seen->getMessage(), 'selector admission')
               && $after === ['reads' => 0, 'ticks' => 1],
            description: 'the admission rejection still reaches the wait point and re-parks the Fiber — observed: '
               . json_encode(['rescheduled' => $rescheduled, 'message' => $seen?->getMessage(), 'seats' => $after])
         );
      }
      finally {
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
