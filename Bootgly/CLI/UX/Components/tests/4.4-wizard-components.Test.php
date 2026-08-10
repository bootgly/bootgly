<?php

namespace Bootgly\CLI\UX\Components;


use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Textbox;
use Bootgly\CLI\UI\Components\Timeline\States;


return new Test(
   description: 'It should host any component between the timeline points',
   test: function () {
      // ! Wizard with in-memory streams — the Textbox answer is primed
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "Alpha\n");
      rewind($stream);
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Wizard = new Wizard($Input, $Output);
      $Name = $Wizard->add('Name', function (Wizard $Wizard) {
         // @ Handlers instantiate components directly with the shared IO
         $Textbox = new Textbox($Wizard->Input, $Wizard->Output);
         $Textbox->prompt = 'Project name';

         // :
         return $Textbox->ask();
      });

      // @ Run drives the component between the transitions
      $done = $Wizard->run();

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: $done === true && $Name->State === States::Done,
         description: 'The flow completes through the hosted component'
      );
      yield assert(
         assertion: $Name->note === 'Alpha',
         description: 'The component answer becomes the step note'
      );
      yield assert(
         assertion: str_contains($output, 'Project name'),
         description: 'The component content renders between the timeline points'
      );
   }
);
