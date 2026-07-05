<?php

namespace Bootgly\CLI\UX;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UX\Form\Controls;


return new Specification(
   description: 'It should ask fields sequentially and confirm deterministically on both stream modes',
   test: function () {
      // ! Form with in-memory streams
      // Interactive terminals consume: Text line, Menu Enter, Confirm line, summary Enter.
      // Non-interactive streams consume: Text line, Select line (empty = default), Confirm line.
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "Alpha\n\ny\n\n");
      rewind($stream);

      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Form = new Form($Input, $Output);
      $Form->title = 'New project';

      $Form->add('Name', required: true);
      $Form->add('Platform', Controls::Select, default: 'Console', options: ['Console', 'Web']);
      $Form->add('Git', Controls::Confirm, default: 'no');

      // @
      $answers = $Form->ask();

      // @ Valid
      yield assert(
         assertion: $answers === ['Name' => 'Alpha', 'Platform' => 'Console', 'Git' => 'yes'],
         description: 'Fields are asked in order — defaults and Confirm answers land in the answers map'
      );
      yield assert(
         assertion: $Form->confirmed === true,
         description: 'The form confirms on ' . (BOOTGLY_TTY ? 'summary Confirm' : 'the last stdin line')
      );

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($output, 'Name: Alpha') === true,
            description: 'The summary Fieldset renders the recorded answers'
         );
         yield assert(
            assertion: str_contains($output, 'Confirm') === true,
            description: 'The summary Menu offers the Confirm option'
         );

         // @ Editing a field from the summary before confirming
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "Alpha\n\ny\n\e[B\nBeta\n\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Form = new Form($Input, $Output);
         $Form->add('Name', required: true);
         $Form->add('Platform', Controls::Select, default: 'Console', options: ['Console', 'Web']);
         $Form->add('Git', Controls::Confirm, default: 'no');

         $answers = $Form->ask();

         yield assert(
            assertion: $answers['Name'] === 'Beta',
            description: 'The summary Menu re-edits the chosen field before confirming'
         );
      }
      else {
         yield assert(
            assertion: str_contains($output, 'Confirm') === false,
            description: 'Non-interactive streams never render the summary Menu'
         );
      }
   }
);
