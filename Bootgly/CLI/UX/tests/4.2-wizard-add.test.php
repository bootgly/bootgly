<?php

namespace Bootgly\CLI\UX;


use function assert;
use function fopen;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Timeline\States;


return new Specification(
   description: 'It should insert mid-run steps right after the active one (branching)',
   test: function () {
      // ! Wizard with in-memory streams
      $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $ran = [];

      $Wizard = new Wizard($Input, $Output);
      $Wizard->add('One', function (Wizard $Wizard) use (&$ran) {
         $ran[] = 'One';

         // @ The resolved branch slots its steps before the upcoming ones
         $Wizard->add('Two', function (Wizard $Wizard) use (&$ran) {
            $ran[] = 'Two';
            return 'branched';
         });
         $Wizard->add('Three', function (Wizard $Wizard) use (&$ran) {
            $ran[] = 'Three';
            return null;
         });

         return 'resolved';
      });
      $Wizard->add('Four', function (Wizard $Wizard) use (&$ran) {
         $ran[] = 'Four';
         return null;
      });

      // @ Run drains the mid-run insertions
      $done = $Wizard->run();

      yield assert(
         assertion: $done === true && $ran === ['One', 'Two', 'Three', 'Four'],
         description: 'Mid-run steps run right after the active one, before the upcoming ones'
      );

      $Steps = $Wizard->Timeline->Steps;

      yield assert(
         assertion: $Steps->count === 4
            && $Steps->Steps[1]->State === States::Done
            && $Steps->Steps[1]->note === 'branched'
            && $Steps->Steps[3]->State === States::Done,
         description: 'Inserted steps complete with their notes — later steps shift forward'
      );

      // @ The frame renders every step in flow order
      $frame = (string) $Wizard->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, 'One') && str_contains($frame, 'Two')
            && str_contains($frame, 'Three') && str_contains($frame, 'Four'),
         description: 'The returned frame includes the dynamically inserted steps'
      );
   }
);
