<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function fopen;
use function rewind;
use function str_contains;
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
   }
);
