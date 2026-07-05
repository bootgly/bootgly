<?php

namespace Bootgly\CLI\UI\Components;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Timeline\States;


return new Specification(
   description: 'It should transition step states through the guided flow',
   test: function () {
      // ! Timeline with an in-memory stream
      $Output = new Output('php://memory');

      $Timeline = new Timeline($Output);
      $A = $Timeline->add('Mode');
      $B = $Timeline->add('Path');
      $C = $Timeline->add('Scaffold');

      // @ Valid
      yield assert(
         assertion: $A->State === States::Pending && $Timeline->Steps->count === 3,
         description: 'Steps start Pending'
      );

      // @ Start activates the first step
      $Timeline->start();

      yield assert(
         assertion: $A->State === States::Active && $B->State === States::Pending,
         description: 'start() activates the first step'
      );

      // @ Advance completes the active step and activates the next
      $Timeline->advance('create');

      yield assert(
         assertion: $A->State === States::Done && $A->note === 'create' && $B->State === States::Active,
         description: 'advance() completes the active step (with note) and activates the next'
      );

      // @ Fail stops the flow
      $Timeline->fail('permission denied');

      // @ Valid
      yield assert(
         assertion: $B->State === States::Failed && $B->note === 'permission denied',
         description: 'fail() marks the active step as Failed with its note'
      );
      yield assert(
         assertion: $Timeline->finished === true && $C->State === States::Pending,
         description: 'A failed flow finishes — later steps stay Pending'
      );

      // @ Transitions after the flow finished are no-ops
      $Timeline->advance();

      yield assert(
         assertion: $C->State === States::Pending,
         description: 'advance() after finish is a no-op'
      );
   }
);
