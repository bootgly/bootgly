<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter\Listener;
use Bootgly\ABI\Events\Emitter\Listeners;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Emitter\Listeners: add(), remove(), drop() and iteration keep the registrations in dispatch order under stable ids',
   test: function () {
      $A = fn () => 'a';
      $B = fn () => 'b';
      $C = fn () => 'c';
      $D = fn () => 'd';

      // # add(): ids in registration order, entries in dispatch order
      $Listeners = new Listeners();

      yield assert(
         assertion: $Listeners->entries === [] && iterator_to_array($Listeners) === [],
         description: 'A new collection has no registration'
      );

      $Listeners->add($A);
      $Listeners->add($B, 10);
      $Listeners->add($C, once: true);
      $Listeners->add($D, 10);

      yield assert(
         assertion: $Listeners->entries === [
            1 => [10, $B, false],
            3 => [10, $D, false],
            0 => [0, $A, false],
            2 => [0, $C, true],
         ],
         description: 'entries holds priority, listener and one-shot flag by registration id, highest priority first, ties in registration order'
      );
      yield assert(
         assertion: iterator_to_array($Listeners) === [$B, $D, $A, $C],
         description: 'Iteration yields the listeners as a list in dispatch order — the ids are the keys of entries only'
      );

      // # Priorities below, between and above the registered ones
      $Listeners = new Listeners();
      foreach ([0, -5, 5, 0, 3, -5, 9, -9] as $priority) {
         $Listeners->add(fn () => null, $priority);
      }

      $priorities = [];
      foreach ($Listeners->entries as $id => $entry) {
         $priorities[] = [$entry[0], $id];
      }

      yield assert(
         assertion: $priorities === [[9, 6], [5, 2], [3, 4], [0, 0], [0, 3], [-5, 1], [-5, 5], [-9, 7]],
         description: 'A registration lands after every one of a higher or equal priority, wherever that is'
      );

      // # drop(): one registration, by id
      $Listeners = new Listeners();
      $Listeners->add($A);
      $Listeners->add($A, 5);
      $Listeners->add($B);

      yield assert(
         assertion: $Listeners->drop(1) === true
            && array_keys($Listeners->entries) === [0, 2]
            && iterator_to_array($Listeners) === [$A, $B],
         description: 'drop() removes the one registration with that id and keeps the order of the rest'
      );
      yield assert(
         assertion: $Listeners->drop(1) === false && $Listeners->drop(99) === false
            && count($Listeners->entries) === 2,
         description: 'drop() answers false for an id already dropped or never given'
      );

      // # Ids are never reused
      $Listeners->drop(2);
      $Listeners->add($C);

      yield assert(
         assertion: array_keys($Listeners->entries) === [0, 3]
            && iterator_to_array($Listeners) === [$A, $C],
         description: 'A registration made after the latest one was dropped takes a new id'
      );

      $Listeners->drop(0);
      $Listeners->drop(3);
      $Listeners->add($D);

      yield assert(
         assertion: array_keys($Listeners->entries) === [4]
            && iterator_to_array($Listeners) === [$D],
         description: 'A collection emptied and filled again goes on from its last id'
      );

      // # remove(): every registration of the listener
      $Listeners = new Listeners();
      $Listeners->add($A);
      $Listeners->add($B, 5);
      $Listeners->add($A, 10, once: true);

      yield assert(
         assertion: $Listeners->remove($A) === true
            && array_keys($Listeners->entries) === [1]
            && iterator_to_array($Listeners) === [$B],
         description: 'remove() removes every registration of the closure'
      );
      yield assert(
         assertion: $Listeners->remove($A) === false && $Listeners->remove($C) === false
            && iterator_to_array($Listeners) === [$B],
         description: 'remove() answers false when the listener has no registration'
      );

      // # remove(): Listener objects by identity, closures by equality
      $Object = new class implements Listener {
         public function handle (Emission $Emission): void
         {
         }
      };
      $Twin = clone $Object;

      $Listeners = new Listeners();
      $Listeners->add($Object);
      $Listeners->add($Twin);
      $Listeners->add($Object->handle(...));
      $Listeners->add($Twin->handle(...));

      yield assert(
         assertion: $Listeners->remove($Twin) === true
            && array_keys($Listeners->entries) === [0, 2, 3],
         description: 'remove() of a Listener object leaves an equal object and the closures over its methods'
      );
      yield assert(
         assertion: $Listeners->remove($Object->handle(...)) === true
            && array_keys($Listeners->entries) === [0, 3],
         description: 'remove() of a first-class callable removes the equal one, not the same method of another object'
      );

      // # An iteration in flight keeps the registrations it started with
      $Listeners = new Listeners();
      $Listeners->add($A);
      $Listeners->add($B);
      $Listeners->add($C);

      $Seen = [];
      foreach ($Listeners as $Listener) {
         $Seen[] = $Listener;

         if ($Listener === $A) {
            $Listeners->remove($B);
            $Listeners->add($D, 100);
         }
      }

      yield assert(
         assertion: $Seen === [$A, $B, $C] && array_keys($Listeners->entries) === [3, 0, 2],
         description: 'A removed registration is still iterated, with its id gone from entries; an added one is not'
      );
      yield assert(
         assertion: iterator_to_array($Listeners) === [$D, $A, $C],
         description: 'The next iteration reflects both changes'
      );
   }
);
