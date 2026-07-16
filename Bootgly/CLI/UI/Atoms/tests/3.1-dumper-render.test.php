<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function rewind;
use function str_contains;
use function str_ends_with;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render structured value dumps through the ABI engine',
   test: function () {
      // @ RETURN_OUTPUT — decorated dump
      $Output = new Output('php://memory');
      $Dumper = new Dumper($Output);
      $Dumper->decoration = true;
      $Dumper->value = ['name' => 'Bootgly', 'ok' => true];

      $rendered = (string) $Dumper->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[92m'name'\e[0m") === true
            && str_contains($rendered, "\e[95mtrue\e[0m") === true,
         description: 'Decorated dumps paint keys and literals with the bootgly palette'
      );

      yield assert(
         assertion: str_ends_with($rendered, "\n") === true,
         description: 'Rendered output ends with a newline'
      );

      // @ WRITE_OUTPUT — stream read-back
      $Output = new Output('php://memory');
      $Dumper = new Dumper($Output);
      $Dumper->decoration = true;
      $Dumper->value = 42;

      $Dumper->render();

      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($written, '42') === true,
         description: 'WRITE_OUTPUT writes the dump to the Output stream'
      );
   }
);
