<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Events\Select;


/**
 * The reactor reports the peak scheduled-waiter population, not a sample.
 *
 * A workload that never fills the awaiting queues cannot distinguish reactor
 * implementations, and an instantaneous count cannot tell the two apart: it is
 * taken from inside a request, when the caller's own generation is the only
 * one seated. The high-water mark is what states the regime a run reached, so
 * it must survive eviction — otherwise a load that peaked at 256 and drained
 * would report the same 0 as a load that never parked anything.
 *
 * It counts queue OCCUPANCY (tick entries + one per awaited descriptor), not
 * waiters: two Fibers sharing one descriptor cost one selector slot and are
 * walked as one bucket, which is what the number exists to describe.
 */
return new Test(
   description: 'Select should report the peak scheduled-waiter population and keep it across eviction',
   test: new Assertions(Case: function (): Generator {
      $Reflection = new ReflectionClass(Select::class);
      $queue = $Reflection->getMethod('queue');
      $park = $Reflection->getMethod('park');
      $evict = $Reflection->getMethod('evict');
      $destroy = $Reflection->getMethod('destroy');
      $Bind = $Reflection->getProperty('Bindings');

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
         // ! A reactor that never scheduled anything reports no regime at all.
         $Select = $Reflection->newInstanceWithoutConstructor();

         yield assert(
            assertion: $Select->parked === 0,
            description: 'a fresh reactor reports no parked waiters — parked: ' . $Select->parked
         );

         // @@ A) Seat 12 generations, each on its own descriptor
         $Bindings = [];
         $Fibers = [];

         for ($i = 0; $i < 12; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false) {
               throw new RuntimeException('Select watermark fixture could not allocate a socket pair.');
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

         yield assert(
            assertion: $Select->parked === 12,
            description: 'the mark follows the awaiting-read queue up — parked: ' . $Select->parked
         );

         // @@ B) A write wait and a tick park both count
         $queue->invoke($Select, $Fibers[0], $Sockets[0][1], Select::SCHEDULE_WRITE);
         $park->invoke($Select, $Fibers[1]);

         yield assert(
            assertion: $Select->parked === 14,
            description: 'write waits and tick parks raise the same mark — parked: ' . $Select->parked
         );

         // @@ C) A second waiter on a SEATED descriptor is one bucket, not two
         $peak = $Select->parked;
         $queue->invoke($Select, $Fibers[2], $Sockets[3][0], Select::SCHEDULE_READ);

         yield assert(
            assertion: $Select->parked === $peak,
            description: 'sharing a descriptor costs no extra selector slot — parked: '
               . $Select->parked . ', before: ' . $peak
         );

         // @@ D) Draining every generation must not erase the observation
         foreach ($Fibers as $Fiber) {
            $evict->invoke($Select, $Fiber, $Bindings[spl_object_id($Fiber)]['Token']);
         }

         yield assert(
            assertion: $Select->parked === 14,
            description: 'the peak survives a full drain — parked: ' . $Select->parked
         );

         // @@ E) A later, smaller generation must not overwrite the peak.
         //    Admission is the only place occupancy is read, and admission only
         //    ever grows it, so an assignment reads identically to a maximum
         //    until a drain comes between two waits — which is exactly the
         //    shape of a benchmark run, and the only shape that tells them apart.
         $Late = $spawn();
         $Bindings[spl_object_id($Late)] = [
            'Enter' => $Noop,
            'Leave' => $Noop,
            'Token' => Cancellation::open($Late),
         ];
         $Bind->setValue($Select, $Bindings);
         $queue->invoke($Select, $Late, $Sockets[0][0], Select::SCHEDULE_READ);

         yield assert(
            assertion: $Select->parked === 14,
            description: 'one waiter seated after a drain does not lower the peak — parked: '
               . $Select->parked
         );

         // @@ F) Teardown clears it: a reused reactor reports its own regime
         $destroy->invoke($Select);

         yield assert(
            assertion: $Select->parked === 0,
            description: 'destroy() resets the mark for the next drain — parked: ' . $Select->parked
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
