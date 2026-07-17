<?php

namespace Bootgly\CLI\UX\Components;


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
use Bootgly\CLI\UX\Components\Form\Controls;


return new Specification(
   description: 'It should frame interactive fields as fieldsets — plain editors on pipes',
   test: function () {
      // ! Form with in-memory streams
      // Interactive terminals consume: Text line, radio Enter, summary Enter.
      // Non-interactive streams consume: Text line, Select line (empty = default).
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "Alpha\n\n\n");
      rewind($stream);

      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Form = new Form($Input, $Output);
      $Form->width = 40;

      $Form->add('Name');
      $Form->add('Platform', Controls::Select, default: 'Console', options: ['Console', 'Web']);

      // @
      $answers = $Form->ask();

      yield assert(
         assertion: $answers === ['Name' => 'Alpha', 'Platform' => 'Console'],
         description: 'The fieldset editors record the same answers on both stream modes'
      );

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      if (BOOTGLY_TTY === true) {
         // @ Interactive: fieldset frames — legend in the top border, editors inside
         yield assert(
            assertion: str_contains($output, '┌') === true
               && str_contains($output, '└') === true
               && str_contains($output, '│') === true,
            description: 'Interactive fields render inside fieldset frames'
         );

         yield assert(
            assertion: str_contains($output, '› ● Console') === true
               && str_contains($output, '○') === true,
            description: 'Select fields render as a radio list — aimed cursor and dots'
         );

         yield assert(
            assertion: str_contains($output, 'Alpha') === true,
            description: 'The settled frame keeps the recorded answer on screen'
         );
      }
      else {
         // @ Non-interactive: plain line editors — no frames on pipes
         yield assert(
            assertion: str_contains($output, '┌') === false,
            description: 'Non-interactive streams keep the plain, frameless editors'
         );
      }
   }
);
