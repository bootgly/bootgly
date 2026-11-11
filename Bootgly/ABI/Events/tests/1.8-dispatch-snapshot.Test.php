<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\Emitter\Listeners;
use Bootgly\ABI\Events\tests\Events;
use Bootgly\ACI\Tests\Suite\Test;

require_once __DIR__ . '/Events.php';


return new Test(
   description: 'Emitter: an emission delivers to the listeners registered when it started (registration inside a handler, duplicates, nested emissions, check())',
   test: function () {
      // # listen() chains and check() follows the registrations
      $Emitter = new Emitter();

      yield assert(
         assertion: $Emitter->check(Events::Alpha) === false
            && isSet($Emitter->Listeners[spl_object_id(Events::Alpha)]) === false,
         description: 'check() and the public Listeners map answer false before any registration'
      );

      $Returned = $Emitter->listen(Events::Alpha, fn () => null);

      yield assert(
         assertion: $Returned === $Emitter,
         description: 'listen() returns the emitter for chaining'
      );
      yield assert(
         assertion: $Emitter->check(Events::Alpha) === true
            && $Emitter->check(Events::Beta) === false
            && isSet($Emitter->Listeners[spl_object_id(Events::Alpha)]) === true
            && $Emitter->Listeners[spl_object_id(Events::Alpha)] instanceof Listeners,
         description: 'check() and the public Listeners map answer true for the listened event only'
      );

      // # A listener registered inside a handler waits for the next emission
      $Emitter = new Emitter();

      $order = [];
      $Emitter->listen(Events::Alpha, function () use ($Emitter, &$order) {
         $order[] = 'first';

         $Emitter->listen(Events::Alpha, function () use (&$order) {
            $order[] = 'late';
         }, priority: 100);
      });
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'second';
      });

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['first', 'second'],
         description: 'A listener registered during an emission does not run in it, whatever its priority'
      );

      $order = [];
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['late', 'first', 'second'],
         description: 'It runs from the next emission on, in its priority position'
      );

      // # The same listener registered twice runs twice
      $Emitter = new Emitter();

      $runs = 0;
      $Counter = function () use (&$runs) {
         $runs++;
      };
      $Emitter->listen(Events::Alpha, $Counter);
      $Emitter->listen(Events::Alpha, $Counter);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $runs === 2,
         description: 'Two registrations of one closure are two deliveries'
      );

      // # Registration order breaks priority ties across registration batches
      $Emitter = new Emitter();

      $order = [];
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'a';
      });
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'b';
      });
      $Emitter->emit(Events::Alpha);
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'c';
      });
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'top';
      }, priority: 1);

      $order = [];
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['top', 'a', 'b', 'c'],
         description: 'Equal priorities keep registration order after a later batch is sorted in'
      );

      // # A nested emission of the same event is a full pass of its own
      $Emitter = new Emitter();

      $order = [];
      $depth = 0;
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use ($Emitter, &$order, &$depth) {
         $order[] = "outer:$depth";

         if ($depth === 0) {
            $depth++;
            $Emitter->emit(Events::Alpha, 'nested');
            $depth--;
         }
      }, priority: 10);
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$order, &$depth) {
         $payload = $Emission->payload[0] ?? 'root';
         $order[] = "inner:$depth:$payload";
      });

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['outer:0', 'outer:1', 'inner:1:nested', 'inner:0:root'],
         description: 'The nested emission runs every listener, then the outer one resumes where it was'
      );

      // # Listeners is iterable in dispatch order
      $Listeners = new Listeners();
      $Low = fn () => 'low';
      $High = fn () => 'high';
      $Listeners->add($Low);
      $Listeners->add($High, 5);

      yield assert(
         assertion: iterator_to_array($Listeners) === [$High, $Low],
         description: 'Listeners::add() + iteration yields the listeners as a list, in descending priority'
      );
   }
);
