<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render markdown in both output modes and decorations',
   test: function () {
      // ! Component factory with an in-memory Output
      $make = static function (): array {
         $Output = new Output('php://memory');

         $Markdown = new Markdown($Output);
         $Markdown->width = 40;

         // :
         return [$Markdown, $Output];
      };

      // @ RETURN_OUTPUT returns the string and writes nothing
      [$Markdown, $Output] = $make();
      $Markdown->decoration = true;
      $Markdown->source = '# Title';

      $returned = $Markdown->render(Component::RETURN_OUTPUT);

      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains((string) $returned, '# Title') === true && $written === '',
         description: 'RETURN_OUTPUT returns the frame without writing'
      );

      // @ The render property pins the mode
      [$Pinned] = $make();
      $Pinned->decoration = true;
      $Pinned->source = 'text';
      $Pinned->render = Component::RETURN_OUTPUT;

      yield assert(
         assertion: str_contains((string) $Pinned->render(), 'text') === true,
         description: 'The $render property forces RETURN_OUTPUT'
      );

      // @ WRITE_OUTPUT writes to the Output
      [$Writer, $Output] = $make();
      $Writer->decoration = true;
      $Writer->source = 'written out';

      $result = $Writer->render();

      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: $result === null && str_contains($written, 'written out') === true,
         description: 'WRITE_OUTPUT writes the frame and returns null'
      );

      // @ Decorated output carries SGR; plain output carries none
      [$Styled] = $make();
      $Styled->decoration = true;
      $Styled->source = '**bold**';

      $decorated = (string) $Styled->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($decorated, "\e[1m") === true,
         description: 'decoration: true emits SGR styling'
      );

      [$Plain] = $make();
      $Plain->decoration = false;
      $Plain->source = "# H\n\n**bold** `code`\n\n> quote";

      $plain = (string) $Plain->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: substr_count($plain, "\e") === 0
            && str_contains($plain, '# H') === true
            && str_contains($plain, '│ quote') === true,
         description: 'decoration: false emits zero escape bytes, keeping structure'
      );

      // @ Empty sources never crash
      [$Empty] = $make();
      $Empty->decoration = false;
      $Empty->source = '';

      yield assert(
         assertion: $Empty->render(Component::RETURN_OUTPUT) === "\n",
         description: 'An empty source renders an empty frame'
      );
   }
);
