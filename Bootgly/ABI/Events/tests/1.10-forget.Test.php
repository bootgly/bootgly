<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\Emitter\Listener;
use Bootgly\ABI\Events\tests\Events;
use Bootgly\ACI\Tests\Suite\Test;

require_once __DIR__ . '/Events.php';


return new Test(
   description: 'Emitter: forget() removes a listener by closure or by Listener object',
   test: function () {
      // # By closure
      $Emitter = new Emitter();

      $order = [];
      $High = function () use (&$order) {
         $order[] = 'high';
      };
      $Mid = function () use (&$order) {
         $order[] = 'mid';
      };
      $Low = function () use (&$order) {
         $order[] = 'low';
      };
      $Emitter->listen(Events::Alpha, $Low, priority: -5);
      $Emitter->listen(Events::Alpha, $High, priority: 5);
      $Emitter->listen(Events::Alpha, $Mid);

      $removed = $Emitter->forget(Events::Alpha, $Mid);
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $removed === true && $order === ['high', 'low'],
         description: 'forget() answers true and the closure is not delivered to; the others keep their priority order'
      );
      yield assert(
         assertion: $Emitter->forget(Events::Alpha, $Mid) === false,
         description: 'Forgetting it a second time answers false'
      );

      // # Nothing to remove
      $Stranger = fn () => null;

      yield assert(
         assertion: $Emitter->forget(Events::Alpha, $Stranger) === false
            && $Emitter->forget(Events::Beta, $High) === false,
         description: 'forget() answers false for a listener never registered and for an event nobody listens to'
      );

      $Emitter->listen(Events::Beta, $Stranger);
      $order = [];

      yield assert(
         assertion: $Emitter->forget(Events::Beta, $High) === false
            && $Emitter->emit(Events::Alpha) instanceof Emission
            && $order === ['high', 'low']
            && $Emitter->check(Events::Beta) === true,
         description: 'A listener is removed from the event named only: it stays on the event it listens to'
      );

      // # Registered again after removal
      $Emitter->listen(Events::Alpha, $Mid, priority: 5);

      $order = [];
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['high', 'mid', 'low'],
         description: 'A forgotten closure can be registered again: it is a new registration, last among equal priorities'
      );

      // # Every registration of the listener
      $Emitter = new Emitter();

      $runs = 0;
      $Counter = function () use (&$runs) {
         $runs++;
      };
      $Emitter->listen(Events::Alpha, $Counter, priority: 10);
      $Emitter->listen(Events::Alpha, $Counter);
      $Emitter->listen(Events::Alpha, $Counter, priority: -10, once: true);
      $Emitter->listen(Events::Alpha, function () use (&$runs) {
         $runs += 100;
      });

      $removed = $Emitter->forget(Events::Alpha, $Counter);
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $removed === true && $runs === 100,
         description: 'forget() removes every registration of the closure — persistent and one-shot — in one call'
      );

      // # By Listener object: identity
      $Emitter = new Emitter();

      $Class = new class implements Listener {
         public int $handled = 0;

         public function handle (Emission $Emission): void
         {
            $this->handled++;
         }
      };
      $Twin = clone $Class;

      $Emitter->listen(Events::Alpha, $Class);
      $Emitter->listen(Events::Alpha, $Twin);

      yield assert(
         assertion: $Class == $Twin && $Emitter->forget(Events::Alpha, $Class) === true,
         description: 'forget() removes a Listener object'
      );

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $Class->handled === 0 && $Twin->handled === 1,
         description: 'An equal Listener object of the same class is another listener: it is still delivered to'
      );

      $Unregistered = clone $Twin;

      yield assert(
         assertion: $Unregistered == $Twin
            && $Emitter->forget(Events::Alpha, $Unregistered) === false
            && $Emitter->check(Events::Alpha) === true,
         description: 'An equal object that was never registered removes nothing'
      );

      // # A one-shot Listener object
      $Emitter = new Emitter();

      $Once = clone $Class;
      $Emitter->listen(Events::Alpha, $Once, once: true);

      yield assert(
         assertion: $Emitter->forget(Events::Alpha, $Once) === true
            && $Emitter->emit(Events::Alpha) === null
            && $Once->handled === 0,
         description: 'forget() removes a one-shot Listener object before its emission'
      );

      // # First-class callables: equal, never identical
      $Emitter = new Emitter();

      $Handler = new class {
         public int $handled = 0;

         public function handle (Emission $Emission): void
         {
            $this->handled++;
         }
      };
      $Other = clone $Handler;

      $Emitter->listen(Events::Alpha, $Handler->handle(...));
      $Emitter->listen(Events::Alpha, $Other->handle(...));

      yield assert(
         assertion: $Handler->handle(...) !== $Handler->handle(...)
            && $Emitter->forget(Events::Alpha, $Handler->handle(...)) === true,
         description: 'A first-class callable removes the registration of an earlier one of the same method on the same object'
      );

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $Handler->handled === 0 && $Other->handled === 1,
         description: 'The same method on another object is another listener'
      );

      // # Two closures written apart never match
      $Emitter = new Emitter();

      $runs = 0;
      $Emitter->listen(Events::Alpha, function () use (&$runs) {
         $runs++;
      });

      $removed = $Emitter->forget(Events::Alpha, function () use (&$runs) {
         $runs++;
      });
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $removed === false && $runs === 1,
         description: 'A closure with the same body is not the registered closure'
      );

      // # A one-shot listener forgetting itself: it is gone already
      $Emitter = new Emitter();

      $answers = [];
      $Departed = function () use ($Emitter, &$answers, &$Departed) {
         $answers[] = $Emitter->forget(Events::Alpha, $Departed);
      };
      $Emitter->listen(Events::Alpha, $Departed, once: true);
      $Emitter->listen(Events::Alpha, fn () => null);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $answers === [false],
         description: 'Inside a one-shot listener, forgetting itself answers false: it was removed before it ran'
      );

      // # A one-shot listener forgotten before any emission
      $Emitter = new Emitter();

      $runs = 0;
      $Once = function () use (&$runs) {
         $runs++;
      };
      $Emitter->listen(Events::Alpha, $Once, once: true);

      yield assert(
         assertion: $Emitter->forget(Events::Alpha, $Once) === true
            && $Emitter->emit(Events::Alpha) === null
            && $runs === 0,
         description: 'A one-shot listener forgotten before its emission never runs'
      );
   }
);
