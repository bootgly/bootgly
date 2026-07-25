<?php

namespace Bootgly\CLI\UI\Components;


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
   description: 'It should search options: static filtering, dynamic source and strict selection',
   test: function () {
      // ! Options fixture — string keys return the key, int keys return the label
      $databases = [
         'db.mysql' => 'MySQL',
         'db.sqlite' => 'SQLite',
         'db.postgres' => 'PostgreSQL',
         'db.redis' => 'Redis'
      ];

      // ! Textbox factory with in-memory streams
      $make = static function (
         string $keys,
         array $options,
         null|Closure $source = null,
         bool $strict = true
      ): array {
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, $keys);
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Textbox = new Textbox($Input, $Output);
         $Textbox->prompt = 'Search';
         $Textbox->options = $options;
         $Textbox->source = $source;
         $Textbox->strict = $strict;

         // :
         return [$Textbox, $Output];
      };

      if (BOOTGLY_TTY === true) {
         // @ Static filtering — `lite` narrows to SQLite and Enter submits its value
         //   (`sq` would keep My`SQ`L too — stripos matches anywhere in the label)
         [$Textbox, $Output] = $make("lite\n", $databases);

         $answer = $Textbox->ask();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $answer === 'db.sqlite' && $Textbox->answer === 'db.sqlite',
            description: 'Typing narrows the matches; strict Enter submits the aimed option value'
         );
         yield assert(
            assertion: str_contains($output, '=> MySQL') === true
               && str_contains($output, 'Redis') === true,
            description: 'The empty query lists every option with the first one aimed'
         );
         yield assert(
            assertion: str_contains($output, 'Search: db.sqlite') === true,
            description: 'The final frame replaces the option list with the submitted value'
         );
         yield assert(
            assertion: str_contains($output, "\e[?25l") === true
               && str_contains($output, "\e[?25h") === true,
            description: 'The cursor hides while editing and shows back after'
         );

         // @ Static filtering — Down clamps at the last match, so the aim proves the count
         //   (`sq` keeps MySQL, SQLite and PostgreSQL — Redis is filtered out)
         [$Clamped] = $make("sq\e[B\e[B\e[B\e[B\e[B\n", $databases);

         yield assert(
            assertion: $Clamped->ask() === 'db.postgres',
            description: 'Query `sq` keeps exactly three matches: Down clamps on PostgreSQL'
         );

         // @ Erasing re-queries — Ctrl+U empties the query and every option lists again
         [$Erased] = $make("sq\x15\e[B\e[B\e[B\e[B\e[B\n", $databases);

         yield assert(
            assertion: $Erased->ask() === 'db.redis',
            description: 'Ctrl+U clears the query: the four options list again and Down clamps on Redis'
         );

         // @ Aiming — Down aims the second match and Enter submits it
         [$Aimed] = $make("\e[B\n", $databases);

         yield assert(
            assertion: $Aimed->ask() === 'db.sqlite',
            description: 'Down aims the second option and Enter submits its value'
         );

         // @ Tab completes the typed line to the aimed label
         [$Completed, $Output] = $make("sq\e[B\t\n", $databases);

         $answer = $Completed->ask();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $answer === 'db.sqlite' && str_contains($output, 'SQLite') === true,
            description: 'Tab completes the line to the aimed label without moving the aim'
         );

         // @ Dynamic source — re-queried on every edit, never refiltered here
         $queries = [];
         $Source = static function (string $query) use (&$queries): array {
            $queries[] = $query;

            // : Labels never contain the query — a static refilter would drop them
            return ['on.first' => 'Zzz', 'on.second' => 'Qqq'];
         };

         [$Sourced, $Output] = $make("a\e[Bb\n", [], $Source);

         $answer = $Sourced->ask();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $queries === ['', 'a', 'ab'],
            description: 'The source receives the initial empty query and every edit — aiming queries nothing'
         );
         yield assert(
            assertion: $answer === 'on.first',
            description: 'Source results are submitted as-is: no stripos refilter drops them'
         );
         yield assert(
            assertion: str_contains($output, 'Qqq') === true,
            description: 'Every source result renders in the option list'
         );

         // @ Dynamic source — the aim picks among the source results
         $queries = [];
         [$Second] = $make("\e[B\n", [], $Source);

         yield assert(
            assertion: $Second->ask() === 'on.second' && $queries === [''],
            description: 'Down aims the second source result and Enter submits its value'
         );

         // @ Viewport — matches beyond it render the `↑`/`↓` more markers
         $items = [];
         for ($index = 0; $index < 10; $index++) {
            $items[] = "Item {$index}";
         }

         [$Windowed, $Output] = $make("\e[B\e[B\e[B\n", $items);
         $Windowed->viewport = 3;

         $answer = $Windowed->ask();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $answer === 'Item 3',
            description: 'Int-keyed options submit the label itself as the value'
         );
         yield assert(
            assertion: str_contains($output, '↑ 1 more') === true
               && str_contains($output, '↓ 6 more') === true,
            description: 'The window slides: both more markers render around the visible rows'
         );

         // @ Hint — a dim helper line renders right below the prompt
         [$Hinted, $Output] = $make("\n", $databases);
         $Hinted->hint = '(type to filter)';

         $answer = $Hinted->ask();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $answer === 'db.mysql' && str_contains($output, '(type to filter)') === true,
            description: 'The hint renders below the prompt and Enter submits the first option'
         );

         // @ Escape — closes the option list keeping the typed text (never cancels)
         //   The extended keyboard report is used so the Esc bytes never touch the
         //   Enter bytes — piped input would assemble `\e` + `\r` into ALT_ENTER.
         [$Closed] = $make("my\e[27;1;27~\n", $databases, strict: false);

         yield assert(
            assertion: $Closed->ask() === 'my',
            description: 'Esc closes the option list and Enter still submits the typed text'
         );

         // @ No matches — strict re-asks and the second round drains to the default
         [$Missed, $Output] = $make("zzz\n", $databases);
         $Missed->default = 'none';

         $answer = $Missed->ask();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $answer === 'none' && $Missed->attempt === 2,
            description: 'An unlisted line re-asks; the drained second round assumes the default'
         );
         yield assert(
            assertion: str_contains($output, 'Pick one of the options.') === true,
            description: 'The unlisted line renders the strict Failure Alert'
         );
      }
      else {
         // @ Pipes — a typed label answers with the option's value, exactly
         //   like picking its row would (and matches case-insensitively,
         //   the same way the interactive list filters)
         [$Labeled] = $make("mysql\n", $databases);

         yield assert(
            assertion: $Labeled->ask() === 'db.mysql',
            description: 'Pipes: a typed label resolves to the option value, case-insensitively'
         );

         // @ Pipes — a typed value passes it too
         [$Valued] = $make("db.redis\n", $databases);

         yield assert(
            assertion: $Valued->ask() === 'db.redis',
            description: 'Pipes: a typed option value is a listed answer'
         );

         // @ Pipes — an unlisted line re-asks until a listed one arrives
         [$Unknown, $Output] = $make("oracle\nSQLite\n", $databases);

         $answer = $Unknown->ask();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $answer === 'db.sqlite' && $Unknown->attempt === 2,
            description: 'Pipes: an unlisted line re-asks until a listed answer arrives'
         );
         yield assert(
            assertion: str_contains($output, 'Pick one of the options.') === true,
            description: 'Pipes: the unlisted line renders the strict Failure Alert'
         );

         // @ Pipes — the dynamic source still resolves the candidates
         $queries = [];
         [$Sourced] = $make("Custom\n", [], static function (string $query) use (&$queries): array {
            $queries[] = $query;

            // :
            return ['on.custom' => 'Custom'];
         });

         yield assert(
            assertion: $Sourced->ask() === 'on.custom' && $queries === ['Custom'],
            description: 'Pipes: the source candidates validate the typed line'
         );
      }
   }
);
