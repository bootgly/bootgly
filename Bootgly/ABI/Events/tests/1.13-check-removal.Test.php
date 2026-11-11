<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\tests\Events;
use Bootgly\ACI\Tests\Suite\Test;

require_once __DIR__ . '/Events.php';


return new Test(
   description: 'Emitter: check() and the public Listeners map answer false again once the last listener of an event is removed',
   test: function () {
      $alpha = spl_object_id(Events::Alpha);
      $beta = spl_object_id(Events::Beta);

      // # forget()
      $Emitter = new Emitter();

      $First = fn () => null;
      $Second = fn () => null;
      $Emitter->listen(Events::Alpha, $First);
      $Emitter->listen(Events::Alpha, $Second);
      $Emitter->listen(Events::Beta, $First);

      $Emitter->forget(Events::Alpha, $First);

      yield assert(
         assertion: $Emitter->check(Events::Alpha) === true
            && isSet($Emitter->Listeners[$alpha]) === true,
         description: 'An event that keeps a listener stays observed'
      );

      $Emitter->forget(Events::Alpha, $Second);

      yield assert(
         assertion: $Emitter->check(Events::Alpha) === false
            && isSet($Emitter->Listeners[$alpha]) === false
            && $Emitter->emit(Events::Alpha, 'payload') === null,
         description: 'Forgetting the last listener: check() is false, the map has no key, emit() returns null'
      );
      yield assert(
         assertion: $Emitter->check(Events::Beta) === true
            && isSet($Emitter->Listeners[$beta]) === true
            && $Emitter->emit(Events::Beta) instanceof Emission,
         description: 'Another event of the same emitter is not affected'
      );

      // # A forget() that removes nothing changes nothing
      $Emitter->forget(Events::Beta, $Second);

      yield assert(
         assertion: $Emitter->check(Events::Beta) === true
            && $Emitter->forget(Events::Alpha, $First) === false
            && isSet($Emitter->Listeners[$alpha]) === false,
         description: 'A forget() that matches nothing leaves an observed event observed and an unobserved one without a key'
      );

      // # Listening again
      $runs = 0;
      $Emitter->listen(Events::Alpha, function () use (&$runs) {
         $runs++;
      });

      yield assert(
         assertion: $Emitter->check(Events::Alpha) === true
            && $Emitter->emit(Events::Alpha) instanceof Emission
            && $runs === 1,
         description: 'An event emptied by forget() is observed again by a new listen()'
      );

      // # A one-shot listener, alone
      $Emitter = new Emitter();

      $inside = null;
      $Emitter->listen(Events::Alpha, function () use ($Emitter, $alpha, &$inside) {
         $inside = [$Emitter->check(Events::Alpha), isSet($Emitter->Listeners[$alpha])];
      }, once: true);

      $before = $Emitter->check(Events::Alpha);
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $before === true && $inside === [false, false],
         description: 'A one-shot listener is removed before it runs: inside it, its event is already unobserved'
      );
      yield assert(
         assertion: $Emitter->check(Events::Alpha) === false
            && isSet($Emitter->Listeners[$alpha]) === false
            && $Emitter->Listeners === [],
         description: 'After the emission check() is false and the map is empty'
      );

      // # A one-shot listener beside a persistent one
      $Emitter = new Emitter();

      $Emitter->listen(Events::Alpha, fn () => null, once: true);
      $Emitter->listen(Events::Alpha, fn () => null);
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $Emitter->check(Events::Alpha) === true
            && $Emitter->emit(Events::Alpha) instanceof Emission,
         description: 'Delivering to a one-shot listener leaves an event with other listeners observed'
      );

      // # The last listener is forgotten in flight, and a new one registered
      $Emitter = new Emitter();

      $order = [];
      $Only = function () use ($Emitter, &$order, &$Only) {
         $order[] = 'only';

         $Emitter->forget(Events::Alpha, $Only);
         $order[] = $Emitter->check(Events::Alpha);

         $Emitter->listen(Events::Alpha, function () use (&$order) {
            $order[] = 'successor';
         });
         $order[] = $Emitter->check(Events::Alpha);
      };
      $Emitter->listen(Events::Alpha, $Only);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['only', false, true],
         description: 'In flight: check() is false right after the last listener is forgotten, true after a new listen(); the new listener waits'
      );

      $order = [];
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['successor'] && $Emitter->check(Events::Alpha) === true,
         description: 'The next emission delivers to the successor only'
      );

      // # One-shot listeners delivered to by a nested emission
      $Emitter = new Emitter();

      $runs = 0;
      $Emitter->listen(Events::Alpha, function () use ($Emitter, &$runs) {
         $runs++;

         // @ Unobserved by now: nothing to deliver, nothing allocated
         $runs += $Emitter->emit(Events::Alpha) === null ? 10 : 100;
      }, once: true);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $runs === 11 && $Emitter->check(Events::Alpha) === false,
         description: 'An emission made by the only, one-shot, listener of its event finds it unobserved'
      );
   }
);
