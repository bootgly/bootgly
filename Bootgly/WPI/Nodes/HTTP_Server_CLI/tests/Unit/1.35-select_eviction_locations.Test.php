<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Events\Select;


/**
 * Eviction costs a generation's own queue entries, not a reactor sweep.
 *
 * `evict()` used to scan every tick waiter and every awaiting-read/write
 * bucket to find one Fiber, and it runs on the NORMAL deferred completion path
 * — three times per completion — where the pre-change code did a single O(1)
 * unset. That made a completion linear in the number of parked waiters and a
 * full drain quadratic in concurrency.
 *
 * The recorded locations must be a SUPERSET of where the Fiber actually sits:
 * a stale entry left by readiness dispatch, expiry or release costs one lookup
 * and matches nothing, while a MISSING entry would strand a Fiber in a queue
 * forever. `queue()` and `park()` are the only writers of those queues, which
 * is what makes the superset property hold.
 */
return new Test(
   description: 'Select should evict a generation through its recorded locations, leaving every other waiter untouched',
   test: new Assertions(Case: function (): Generator {
      $Reflection = new ReflectionClass(Select::class);
      $queue = $Reflection->getMethod('queue');
      $park = $Reflection->getMethod('park');
      $evict = $Reflection->getMethod('evict');
      $Await = $Reflection->getProperty('awaitingReads');
      $Reads = $Reflection->getProperty('reads');
      $Ticks = $Reflection->getProperty('Fibers');
      $Bind = $Reflection->getProperty('Bindings');
      $Waits = $Reflection->getProperty('Waits');

      $Sockets = [];
      $Noop = static function (): void {};

      $spawn = static function (): Fiber {
         $Fiber = new Fiber(static function (): void {
            Fiber::suspend();
         });
         $Fiber->start();

         return $Fiber;
      };

      try {
         // @@ A) One generation among many parked waiters
         $Select = $Reflection->newInstanceWithoutConstructor();
         $Bindings = [];
         $Fibers = [];

         for ($i = 0; $i < 24; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false) {
               throw new RuntimeException('Select eviction fixture could not allocate a socket pair.');
            }
            $Sockets[] = $pair;

            $Fiber = $spawn();
            $Bindings[spl_object_id($Fiber)] = [
               'Enter' => $Noop,
               'Leave' => $Noop,
               'Token' => Cancellation::open($Fiber),
            ];
            $Fibers[] = $Fiber;
         }
         $Bind->setValue($Select, $Bindings);

         foreach ($Fibers as $index => $Fiber) {
            $queue->invoke($Select, $Fiber, $Sockets[$index][0], Select::SCHEDULE_READ);
         }

         $seated = count($Await->getValue($Select));

         $Target = $Fibers[7];
         $target = (int) $Sockets[7][0];
         $Recorded = $Waits->getValue($Select)[$Target] ?? null;

         yield assert(
            assertion: $seated === 24 && $Recorded === [$target => true],
            description: 'queue() records exactly the location it used — seated: '
               . $seated . ', recorded: ' . json_encode($Recorded)
         );

         $evict->invoke($Select, $Target, $Bindings[spl_object_id($Target)]['Token']);

         $remaining = $Await->getValue($Select);

         yield assert(
            assertion: count($remaining) === 23
               && isset($remaining[$target]) === false
               && isset($Reads->getValue($Select)[$target]) === false
               && isset($Waits->getValue($Select)[$Target]) === false,
            description: 'evicting one generation drops only its own bucket, descriptor and hint'
               . ' — buckets: ' . count($remaining)
               . ', bucket gone: ' . var_export(isset($remaining[$target]) === false, true)
         );

         // @@ B) A generation parked on SEVERAL descriptors leaves none behind
         $Multi = $Fibers[11];
         $Token = $Bindings[spl_object_id($Multi)]['Token'];
         $extra = [];
         foreach ([3, 5, 9] as $slot) {
            $queue->invoke($Select, $Multi, $Sockets[$slot][1], Select::SCHEDULE_READ);
            $extra[] = (int) $Sockets[$slot][1];
         }

         $evict->invoke($Select, $Multi, $Token);

         $stranded = 0;
         foreach ($Await->getValue($Select) as $Queued) {
            foreach ($Queued as $Waiter) {
               if ($Waiter === $Multi) {
                  $stranded++;
               }
            }
         }

         yield assert(
            assertion: $stranded === 0,
            description: 'a generation queued on several descriptors is removed from all of them'
               . ' — stranded entries: ' . $stranded
         );

         // @@ C) A stale location (its bucket already consumed) is harmless
         $Stale = $Fibers[2];
         $staleToken = $Bindings[spl_object_id($Stale)]['Token'];
         $Buckets = $Await->getValue($Select);
         unset($Buckets[(int) $Sockets[2][0]]);
         $Await->setValue($Select, $Buckets);
         $survivors = count($Buckets);

         $evict->invoke($Select, $Stale, $staleToken);

         yield assert(
            assertion: count($Await->getValue($Select)) === $survivors,
            description: 'a location whose bucket was already consumed removes nothing else'
               . ' — before: ' . $survivors . ', after: ' . count($Await->getValue($Select))
         );

         // @@ D) Another generation's token must not evict this Fiber
         $Keeper = $Fibers[15];
         $keeper = (int) $Sockets[15][0];
         $Foreign = Cancellation::open($spawn());

         $evict->invoke($Select, $Keeper, $Foreign);

         yield assert(
            assertion: isset($Await->getValue($Select)[$keeper]),
            description: 'a foreign token cannot evict a live waiter — bucket present: '
               . var_export(isset($Await->getValue($Select)[$keeper]), true)
         );

         // @@ E) The tick queue: a Fiber parked twice loses both slots
         $Reactor = $Reflection->newInstanceWithoutConstructor();
         $Twice = $spawn();
         $Other = $spawn();
         $tickToken = Cancellation::open($Twice);
         $Bind->setValue($Reactor, [
            spl_object_id($Twice) => ['Enter' => $Noop, 'Leave' => $Noop, 'Token' => $tickToken],
         ]);

         $park->invoke($Reactor, $Twice);
         $park->invoke($Reactor, $Other);
         $park->invoke($Reactor, $Twice);

         $parked = count($Ticks->getValue($Reactor));
         $evict->invoke($Reactor, $Twice, $tickToken);
         $left = $Ticks->getValue($Reactor);

         yield assert(
            assertion: $parked === 3 && count($left) === 1 && reset($left) === $Other,
            description: 'both tick slots of one Fiber are dropped and the other waiter stays'
               . ' — parked: ' . $parked . ', left: ' . count($left)
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
   }),
);
