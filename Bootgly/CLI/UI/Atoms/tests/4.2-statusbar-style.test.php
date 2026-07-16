<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function rewind;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should paint the bar style and degrade to plain output',
   test: function () {
      $Output = new Output('php://memory');
      $Statusbar = new Statusbar($Output);
      $Statusbar->width = 30;
      $Statusbar->left = ['Ready'];

      // @ Styled — default 256-color background wraps the row, reset closes it
      $Statusbar->decoration = true;
      $row = (string) $Statusbar->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_starts_with($row, "\e[48;5;236;97m") === true
            && str_ends_with($row, "\e[0m") === true,
         description: 'The default style paints a 256-color bar with a closing reset'
      );

      // @ Custom style codes
      $Statusbar->style = ['44', '37'];
      $row = (string) $Statusbar->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_starts_with($row, "\e[44;37m") === true,
         description: 'Custom SGR codes replace the bar style'
      );

      // @ Plain — zero escapes, embedded segment escapes stripped
      $Statusbar->decoration = false;
      $Statusbar->left = ["\e[31mRed\e[0m alert"];
      $row = (string) $Statusbar->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($row, "\e") === false
            && str_contains($row, 'Red alert') === true,
         description: 'Plain mode strips every escape, including embedded ones'
      );

      // @ WRITE_OUTPUT — writes the row + newline to the Output stream
      $Statusbar->decoration = false;
      $Statusbar->left = ['Written'];
      $Statusbar->render();

      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($written, 'Written') === true
            && str_ends_with($written, "\n") === true,
         description: 'WRITE_OUTPUT writes the row with a trailing newline'
      );
   }
);
