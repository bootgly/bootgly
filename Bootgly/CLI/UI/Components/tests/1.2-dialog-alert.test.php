<?php

namespace Bootgly\CLI\UI\Components;


use const PHP_EOL;
use function assert;
use function fopen;
use function rewind;
use function str_contains;
use function str_starts_with;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render alerts and never hang without an interactive terminal',
   test: function () {
      // ! Dialog with in-memory streams (empty Input = immediate EOF)
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');
      $Dialog = new Dialog($Input, $Output);

      // @
      $Dialog->alert('Disk is almost full');

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      // @ Valid
      yield assert(
         assertion: str_contains($output, 'ATTENTION') === true,
         description: 'Alert renders with the Attention type label'
      );
      yield assert(
         assertion: str_contains($output, 'Disk is almost full') === true,
         description: 'Alert renders the message'
      );
      yield assert(
         assertion: str_starts_with($output, PHP_EOL) === true,
         description: 'Alert renders with a leading blank line by default'
      );

      // ! Alert spacing config (spaced=false glues consecutive alerts)
      $Output = new Output('php://memory');
      $Alert = new Alert($Output);
      $Alert->message = 'glued';
      $Alert->spaced = false;
      $Alert->render();

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      // @ Valid
      yield assert(
         assertion: str_starts_with($output, PHP_EOL) === false,
         description: 'Alert with spaced=false renders without a leading blank line'
      );
   }
);
