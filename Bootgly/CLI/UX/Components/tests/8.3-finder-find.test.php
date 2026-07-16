<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function str_contains;
use function stream_get_contents;
use Closure;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should find values interactively and degrade to a typed line on pipes',
   test: function () {
      // ! Options fixture
      $options = [
         'mysql' => 'MySQL',
         'sqlite' => 'SQLite',
         'redis' => 'Redis'
      ];

      // ! Finder factory with in-memory streams
      $make = static function (string $keys, array $options, null|Closure $source = null): array {
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, $keys);
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Finder = new Finder($Input, $Output);
         $Finder->options = $options;
         $Finder->source = $source;

         // :
         return [$Finder, $Output];
      };

      if (BOOTGLY_TTY === true) {
         // @ Typing filters — `lite` narrows the matches and Enter confirms SQLite
         //   (`sq` would keep My`SQ`L too — stripos matches anywhere in the label)
         [$Finder, $Output] = $make("lite\n", $options);

         $found = $Finder->find();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $found === 'sqlite',
            description: 'Typing narrows the matches and Enter confirms the aimed one'
         );
         yield assert(
            assertion: $Finder->found === 'sqlite',
            description: 'The found value stays exposed on $found'
         );
         yield assert(
            assertion: str_contains($output, '=> MySQL') === true
               && str_contains($output, 'Redis') === true,
            description: 'The empty query shows all options with the first one aimed'
         );
         yield assert(
            assertion: str_contains($output, "\e[?25l") === true
               && str_contains($output, "\e[?25h") === true,
            description: 'The cursor hides during the session and shows back after'
         );
         yield assert(
            assertion: str_contains($output, 'Search: SQLite') === true,
            description: 'The final frame replaces the dropdown with the found label'
         );

         // @ Aiming — Down aims the second option and Enter confirms it
         [$Aimed] = $make("\e[B\n", $options);

         yield assert(
            assertion: $Aimed->find() === 'sqlite',
            description: 'Down aims the second option and Enter confirms it'
         );

         // @ Canceling — Esc finds nothing
         [$Canceled, $Output] = $make("\e", $options);

         $found = $Canceled->find();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $found === null && $Canceled->found === null,
            description: 'Esc cancels: find() returns null and $found stays null'
         );
         yield assert(
            assertion: str_contains($output, "Search: \n") === true,
            description: 'The cancel final frame keeps the label empty'
         );

         // @ EOF — cancels, and the terminal restore still runs (try/finally)
         [$Drained, $Output] = $make('', $options);

         $found = $Drained->find();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $found === null && str_contains($output, "\e[?25h") === true,
            description: 'EOF cancels and the cursor restore still runs'
         );

         // @ Hint — a dim helper line renders right below the prompt
         [$Hinted, $Output] = $make("\n", $options);
         $Hinted->hint = '(type to filter)';

         $Hinted->find();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: str_contains($output, '(type to filter)') === true,
            description: 'The hint renders below the prompt during the session'
         );

         // @ Blink — the aim marker wraps in the blink SGR
         [$Blinking, $Output] = $make("\n", $options);
         $Blinking->blink = true;

         $found = $Blinking->find();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $found === 'mysql',
            description: 'Enter with no typing confirms the first option'
         );
         yield assert(
            assertion: str_contains($output, "\e[5m") === true,
            description: 'blink=true wraps the aim marker in the blink SGR'
         );

         // @ Dynamic source — bypasses the static filter and resolves the value
         $queries = [];
         [$Sourced] = $make("x\n", [], static function (string $query) use (&$queries): array {
            $queries[] = $query;

            // :
            return ['k' => 'X-Ray'];
         });

         yield assert(
            assertion: $Sourced->find() === 'k',
            description: 'The source resolves the options and Enter confirms the value'
         );
         yield assert(
            assertion: $queries === ['', 'x'],
            description: 'The source receives the initial empty query and every edit'
         );
      }
      else {
         // @ Non-interactive — a typed line resolves by case-insensitive exact label
         [$Typed] = $make("MYSQL\n", $options);

         yield assert(
            assertion: $Typed->find() === 'mysql',
            description: 'Pipes: a typed label matches case-insensitively'
         );

         // @ Non-interactive — an unknown label finds nothing
         [$Unknown] = $make("nope\n", $options);

         yield assert(
            assertion: $Unknown->find() === null,
            description: 'Pipes: an unknown label returns null'
         );

         // @ Non-interactive — an empty line finds nothing
         [$Empty] = $make("\n", $options);

         yield assert(
            assertion: $Empty->find() === null,
            description: 'Pipes: an empty line returns null'
         );

         // @ Non-interactive — the source candidates still resolve the typed line
         [$Piped] = $make("beta\n", [], static function (string $query): array {
            // :
            return ['b' => 'Beta'];
         });

         yield assert(
            assertion: $Piped->find() === 'b',
            description: 'Pipes: the source candidates resolve the typed line'
         );
      }
   }
);
