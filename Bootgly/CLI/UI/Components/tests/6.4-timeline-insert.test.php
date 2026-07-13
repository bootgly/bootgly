<?php

namespace Bootgly\CLI\UI\Components;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Timeline\States;


return new Specification(
   description: 'It should insert steps at a position, protecting the walked prefix',
   test: function () {
      // ! Timeline with an in-memory stream
      $Output = new Output('php://memory');

      $Timeline = new Timeline($Output);
      $Timeline->add('One');
      $Timeline->add('Three');

      $Steps = $Timeline->Steps;

      // @ Insert between pending steps
      $Steps->insert('Two', at: 1);

      yield assert(
         assertion: $Steps->count === 3
            && $Steps->Steps[1]->label === 'Two'
            && $Steps->Steps[2]->label === 'Three',
         description: 'insert() slots the Step at the position — later Steps shift forward'
      );

      // @ The walked prefix is immutable
      $Timeline->start();
      $Timeline->advance('first');

      $Steps->insert('Zero', at: 0);

      yield assert(
         assertion: $Steps->Steps[0]->label === 'One'
            && $Steps->Steps[0]->State === States::Done
            && $Steps->Steps[2]->label === 'Zero',
         description: 'Positions at or before the current Step clamp to right after it'
      );

      yield assert(
         assertion: $Steps->Steps[1]->label === 'Two'
            && $Steps->Steps[1]->State === States::Active,
         description: 'The active Step index stays valid across insertions'
      );
   }
);
