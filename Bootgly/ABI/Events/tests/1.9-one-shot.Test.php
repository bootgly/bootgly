<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\Emitter\Listener;
use Bootgly\ABI\Events\tests\Events;
use Bootgly\ACI\Tests\Suite\Test;

require_once __DIR__ . '/Events.php';


return new Test(
   description: 'Emitter: listen(once: true) delivers to the listener at most once',
   test: function () {
      // # Alone on its event
      $Emitter = new Emitter();

      $received = [];
      $Returned = $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$received) {
         $received[] = $Emission->payload;
      }, once: true);

      $First = $Emitter->emit(Events::Alpha, 'one', 2);
      $Second = $Emitter->emit(Events::Alpha, 'again');
      $Third = $Emitter->emit(Events::Alpha, 'and again');

      yield assert(
         assertion: $Returned === $Emitter,
         description: 'listen(once: true) returns the emitter for chaining'
      );
      yield assert(
         assertion: $received === [['one', 2]],
         description: 'A one-shot listener receives the first emission, with its payload, and no other'
      );
      yield assert(
         assertion: $First instanceof Emission && $Second === null && $Third === null,
         description: 'Once delivered, an event with no other listener is unobserved: emit() returns null'
      );

      // # Among persistent listeners, in priority order
      $Emitter = new Emitter();

      $order = [];
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'low once';
      }, priority: -5, once: true);
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'mid';
      });
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'high once';
      }, priority: 5, once: true);
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'tail';
      }, priority: -10);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['high once', 'mid', 'low once', 'tail'],
         description: 'One-shot listeners take their priority position among the persistent ones'
      );

      $order = [];
      $Emitter->emit(Events::Alpha);
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['mid', 'tail', 'mid', 'tail'],
         description: 'Later emissions reach the persistent listeners only, still in order'
      );

      // # Not reached: propagation stopped above it
      $Emitter = new Emitter();

      $runs = 0;
      $stop = true;
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$stop) {
         if ($stop) {
            $Emission->stop();
         }
      }, priority: 10);
      $Emitter->listen(Events::Alpha, function () use (&$runs) {
         $runs++;
      }, once: true);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $runs === 0,
         description: 'A one-shot listener below a stopped propagation does not run'
      );

      $stop = false;
      $Emitter->emit(Events::Alpha);
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $runs === 1,
         description: 'It stays registered until an emission reaches it, then runs once'
      );

      // # It stops the propagation itself
      $Emitter = new Emitter();

      $order = [];
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$order) {
         $order[] = 'once and stop';

         $Emission->stop();
      }, priority: 10, once: true);
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'below';
      });

      $First = $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['once and stop'] && $First instanceof Emission && $First->stopped === true,
         description: 'A one-shot listener that stops the propagation halts its emission like any listener'
      );

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['once and stop', 'below'],
         description: 'The next emission, without it, reaches the listener below'
      );

      // # The same closure, one-shot and persistent
      $Emitter = new Emitter();

      $runs = 0;
      $Counter = function () use (&$runs) {
         $runs++;
      };
      $Emitter->listen(Events::Alpha, $Counter);
      $Emitter->listen(Events::Alpha, $Counter, once: true);
      $Emitter->listen(Events::Alpha, $Counter, once: true);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $runs === 3,
         description: 'Each registration of one closure is delivered to: one persistent and two one-shot'
      );

      $runs = 0;
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $runs === 1,
         description: 'Delivering to the one-shot registrations leaves the persistent one of the same closure'
      );

      // # A Listener object
      $Emitter = new Emitter();

      $Object = new class implements Listener {
         public int $handled = 0;

         public function handle (Emission $Emission): void
         {
            $this->handled++;
         }
      };
      $Emitter->listen(Events::Beta, $Object, once: true);

      $Emitter->emit(Events::Beta);
      $Emitter->emit(Events::Beta);

      yield assert(
         assertion: $Object->handled === 1,
         description: 'A Listener object registered once is handled once'
      );

      // # It emits its own event again
      $Emitter = new Emitter();

      $order = [];
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use ($Emitter, &$order) {
         $order[] = 'once';

         $Nested = $Emitter->emit(Events::Alpha, 'nested');
         $order[] = $Nested instanceof Emission ? 'nested delivered' : 'nested unobserved';
      }, priority: 10, once: true);
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$order) {
         $order[] = $Emission->payload[0] ?? 'root';
      });

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['once', 'nested', 'nested delivered', 'root'],
         description: 'A one-shot listener that emits its event again is not delivered to by that emission'
      );

      // # A nested emission delivers to a one-shot the outer one has not reached
      $Emitter = new Emitter();

      $order = [];
      $depth = 0;
      $Emitter->listen(Events::Alpha, function () use ($Emitter, &$order, &$depth) {
         $order[] = "outer:$depth";

         if ($depth === 0) {
            $depth++;
            $Emitter->emit(Events::Alpha);
            $depth--;
         }
      }, priority: 10);
      $Emitter->listen(Events::Alpha, function () use (&$order, &$depth) {
         $order[] = "once:$depth";
      }, once: true);
      $Emitter->listen(Events::Alpha, function () use (&$order, &$depth) {
         $order[] = "tail:$depth";
      }, priority: -10);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['outer:0', 'outer:1', 'once:1', 'tail:1', 'tail:0'],
         description: 'The outer emission skips the one-shot already delivered to, and no other listener'
      );

      // # A one-shot registered by a one-shot (re-arming)
      $Emitter = new Emitter();

      $runs = 0;
      $Rearming = function () use ($Emitter, &$runs, &$Rearming) {
         $runs++;

         $Emitter->listen(Events::Alpha, $Rearming, once: true);
      };
      $Emitter->listen(Events::Alpha, $Rearming, once: true);

      $Emitter->emit(Events::Alpha);
      $Emitter->emit(Events::Alpha);
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $runs === 3,
         description: 'A one-shot listener that registers itself again runs once per emission'
      );
   }
);
