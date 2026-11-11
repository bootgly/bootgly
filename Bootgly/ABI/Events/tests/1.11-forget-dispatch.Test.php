<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\tests\Events;
use Bootgly\ACI\Tests\Suite\Test;

require_once __DIR__ . '/Events.php';


return new Test(
   description: 'Emitter: forget() inside a handler never skips or repeats another listener of the emission',
   test: function () {
      // # A listener forgets itself
      $Emitter = new Emitter();

      $order = [];
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'before';
      }, priority: 10);
      $Self = function () use ($Emitter, &$order, &$Self) {
         $order[] = 'self';
         $order[] = $Emitter->forget(Events::Alpha, $Self);
      };
      $Emitter->listen(Events::Alpha, $Self);
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'after';
      }, priority: -10);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['before', 'self', true, 'after'],
         description: 'A listener that forgets itself: the ones before and after it run once each'
      );

      $order = [];
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['before', 'after'],
         description: 'It is not delivered to by the next emission'
      );

      // # A listener forgets one the emission has not reached
      $Emitter = new Emitter();

      $order = [];
      $Victim = function () use (&$order) {
         $order[] = 'victim';
      };
      $Emitter->listen(Events::Alpha, function () use ($Emitter, $Victim, &$order) {
         $order[] = 'remover';
         $order[] = $Emitter->forget(Events::Alpha, $Victim);
      }, priority: 10);
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'between';
      }, priority: 5);
      $Emitter->listen(Events::Alpha, $Victim);
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'last';
      }, priority: -5);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['remover', true, 'between', 'last'],
         description: 'A listener forgotten by an earlier one is not delivered to by the emission in flight; the others run once, in order'
      );

      // # A listener forgets one that already ran
      $Emitter = new Emitter();

      $order = [];
      $Early = function () use (&$order) {
         $order[] = 'early';
      };
      $Emitter->listen(Events::Alpha, $Early, priority: 10);
      $Emitter->listen(Events::Alpha, function () use ($Emitter, $Early, &$order) {
         $order[] = 'remover';
         $Emitter->forget(Events::Alpha, $Early);
      }, priority: 5);
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'next';
      });
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'last';
      }, priority: -5);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['early', 'remover', 'next', 'last'],
         description: 'Forgetting a listener that already ran does not shift the rest: none skipped, none repeated'
      );

      $order = [];
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['remover', 'next', 'last'],
         description: 'The next emission goes without it'
      );

      // # Removal and registration in the same handler
      $Emitter = new Emitter();

      $order = [];
      $Last = function () use (&$order) {
         $order[] = 'last registered';
      };
      $Emitter->listen(Events::Alpha, function () use ($Emitter, $Last, &$order) {
         $order[] = 'swapper';

         $Emitter->forget(Events::Alpha, $Last);
         $Emitter->listen(Events::Alpha, function () use (&$order) {
            $order[] = 'replacement';
         });
      }, priority: 10);
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'kept';
      });
      $Emitter->listen(Events::Alpha, $Last);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['swapper', 'kept'],
         description: 'A registration taking the place of a removed one in flight runs neither the removed listener nor the new one'
      );

      $order = [];
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['swapper', 'kept', 'replacement'],
         description: 'The replacement runs from the next emission on'
      );

      // # The first listener forgets every other one
      $Emitter = new Emitter();

      $order = [];
      $Second = function () use (&$order) {
         $order[] = 'second';
      };
      $Third = function () use (&$order) {
         $order[] = 'third';
      };
      $First = function () use ($Emitter, &$order, &$First, $Second, $Third) {
         $order[] = 'first';

         $Emitter->forget(Events::Alpha, $Second);
         $Emitter->forget(Events::Alpha, $Third);
         $Emitter->forget(Events::Alpha, $First);
      };
      $Emitter->listen(Events::Alpha, $First, priority: 10);
      $Emitter->listen(Events::Alpha, $Second);
      $Emitter->listen(Events::Alpha, $Third);

      $Emission = $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['first']
            && $Emission instanceof Emission
            && $Emission->stopped === false
            && $Emitter->check(Events::Alpha) === false
            && $Emitter->emit(Events::Alpha) === null,
         description: 'An emission whose listeners are all forgotten in flight ends without them, and the event is unobserved'
      );

      // # The event is emptied and listened to again in flight
      $Emitter = new Emitter();

      $order = [];
      $Forgotten = function () use (&$order) {
         $order[] = 'forgotten';
      };
      $Emptier = function () use ($Emitter, &$order, &$Emptier, $Forgotten) {
         $order[] = 'emptier';

         // @ The collection is emptied and leaves the map...
         $Emitter->forget(Events::Alpha, $Emptier);
         $Emitter->forget(Events::Alpha, $Forgotten);

         // @ ...and the event gets a new one, whose ids start over: the second
         //   registration takes the id the forgotten listener had
         $Emitter->listen(Events::Alpha, function () use (&$order) {
            $order[] = 'new first';
         });
         $Emitter->listen(Events::Alpha, function () use (&$order) {
            $order[] = 'new second';
         });
      };
      $Emitter->listen(Events::Alpha, $Emptier, priority: 10);
      $Emitter->listen(Events::Alpha, $Forgotten);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['emptier'],
         description: 'An emission in flight asks the collection it started with: listeners registered after the event was emptied do not revive a forgotten one'
      );

      $order = [];
      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['new first', 'new second'],
         description: 'The next emission delivers to the new listeners only'
      );

      // # A nested emission forgets a listener of the outer one
      $Emitter = new Emitter();

      $order = [];
      $depth = 0;
      $Tail = function () use (&$order, &$depth) {
         $order[] = "tail:$depth";
      };
      $Emitter->listen(Events::Alpha, function () use ($Emitter, &$order, &$depth) {
         $order[] = "head:$depth";

         if ($depth === 0) {
            $depth++;
            $Emitter->emit(Events::Alpha);
            $depth--;
         }
      }, priority: 10);
      $Emitter->listen(Events::Alpha, function () use ($Emitter, $Tail, &$order, &$depth) {
         $order[] = "middle:$depth";

         if ($depth === 1) {
            $Emitter->forget(Events::Alpha, $Tail);
         }
      });
      $Emitter->listen(Events::Alpha, $Tail, priority: -10);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['head:0', 'head:1', 'middle:1', 'middle:0'],
         description: 'A listener forgotten inside a nested emission is delivered to by neither emission; the outer one resumes with the rest'
      );

      // # Many removals in flight, on a longer list
      $Emitter = new Emitter();

      $order = [];
      $Numbered = [];
      for ($index = 0; $index < 8; $index++) {
         $Numbered[$index] = function () use ($Emitter, &$order, &$Numbered, $index) {
            $order[] = $index;

            // @ Every even listener forgets the one two places below itself
            if ($index % 2 === 0 && isSet($Numbered[$index + 2])) {
               $Emitter->forget(Events::Beta, $Numbered[$index + 2]);
            }
         };
         $Emitter->listen(Events::Beta, $Numbered[$index], priority: -$index);
      }

      $Emitter->emit(Events::Beta);

      yield assert(
         assertion: $order === [0, 1, 3, 4, 5, 7],
         description: 'Removals in flight drop exactly the forgotten listeners: every other one runs once, in priority order'
      );

      $order = [];
      $Emitter->emit(Events::Beta);

      yield assert(
         assertion: $order === [0, 1, 3, 4, 5, 7],
         description: 'The next emission delivers to the same survivors'
      );
   }
);
