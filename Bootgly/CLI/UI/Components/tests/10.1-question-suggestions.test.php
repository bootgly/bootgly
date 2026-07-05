<?php

namespace Bootgly\CLI\UI\Components;


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


return new Specification(
   description: 'It should autocomplete with suggestions (dropdown on TTY; plain scan on pipes)',
   test: function () {
      if (BOOTGLY_TTY === true) {
         // ! Interactive: type `We`, dropdown filters, Enter accepts (strict → aimed match)
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "We\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Question = new Question($Input, $Output);
         $Question->prompt = 'Platform';
         $Question->suggestions = ['Console', 'Web', 'Both'];
         $Question->strict = true;

         yield assert(
            assertion: $Question->ask() === 'Web',
            description: 'Typing filters the dropdown; Enter accepts the aimed match (strict)'
         );

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: str_contains($output, 'Web') === true,
            description: 'The dropdown frame renders the matches'
         );

         // ! Non-strict: free text wins over the matches
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "Custom\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Question = new Question($Input, $Output);
         $Question->prompt = 'Platform';
         $Question->suggestions = ['Console', 'Web'];

         yield assert(
            assertion: $Question->ask() === 'Custom',
            description: 'Non-strict mode accepts free text'
         );
      }
      else {
         // ! Pipes: plain scan line; strict validates membership
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "Nope\nWeb\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Question = new Question($Input, $Output);
         $Question->prompt = 'Platform';
         $Question->suggestions = ['Console', 'Web', 'Both'];
         $Question->strict = true;

         yield assert(
            assertion: $Question->ask() === 'Web',
            description: 'Strict mode re-asks until a listed answer arrives (pipe)'
         );

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: str_contains($output, 'Pick one of the suggestions.') === true,
            description: 'Unlisted answers render the strict Failure Alert'
         );
      }
   }
);
