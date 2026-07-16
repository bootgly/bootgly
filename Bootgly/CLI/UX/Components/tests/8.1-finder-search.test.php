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


return new Specification(
   description: 'It should filter static options and query the dynamic source',
   test: function () {
      // ! Finder factory with in-memory streams
      $make = static function (string $keys, array $options): array {
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, $keys);
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Finder = new Finder($Input, $Output);
         $Finder->options = $options;

         // :
         return [$Finder, $Output];
      };
      $databases = [
         'db.mysql' => 'MySQL',
         'db.sqlite' => 'SQLite',
         'db.postgres' => 'PostgreSQL',
         'db.redis' => 'Redis'
      ];

      if (BOOTGLY_TTY === true) {
         // @ Static filtering — control() is a pure state machine (no session)
         [$Finder] = $make('', $databases);

         $Finder->control('s');
         $Finder->control('q');

         yield assert(
            assertion: $Finder->Window->total === 3,
            description: 'Query `sq` keeps MySQL, SQLite and PostgreSQL (stripos, case-insensitive)'
         );

         $Finder->control("\e[B");
         $Finder->control('l');
         $Finder->control('i');

         yield assert(
            assertion: $Finder->Window->total === 1 && $Finder->aimed === 0,
            description: 'Query `sqli` narrows to SQLite only and resets the aim'
         );

         // @ Erasing refilters — backspace widens, Ctrl+U lists everything
         $Finder->control("\x7F");
         $Finder->control("\x7F");

         yield assert(
            assertion: $Finder->Window->total === 3,
            description: 'Backspacing to `sq` widens back to the three `sq` labels'
         );

         $Finder->control("\x15");

         yield assert(
            assertion: $Finder->Window->total === 4,
            description: 'Ctrl+U empties the query: every option lists again'
         );

         // @ Int keys — the returned value is the label itself
         [$Names] = $make('', ['Alpha', 'Beta']);

         $Names->control('b');
         $Names->control('e');
         $continue = $Names->control("\n");

         yield assert(
            assertion: $continue === false && $Names->found === 'Beta',
            description: 'Enter confirms the aimed match: int keys return the label'
         );

         // @ Dynamic source — called with the query on every edit, never refiltered
         $queries = [];

         [$Sourced] = $make('', []);
         $Sourced->source = static function (string $query) use (&$queries): array {
            $queries[] = $query;

            // : Labels do not contain the queries — a stripos refilter would drop them
            return ['on.first' => 'Zzz', 'on.second' => 'Qqq'];
         };

         $Sourced->control('a');
         $Sourced->control("\e[B");
         $Sourced->control('b');

         yield assert(
            assertion: $queries === ['a', 'ab'],
            description: 'The source receives every query edit — aiming queries nothing'
         );
         yield assert(
            assertion: $Sourced->Window->total === 2,
            description: 'Source results count as-is: no stripos refilter drops them'
         );

         // @ Session — matches beyond the viewport render `↑`/`↓` more markers
         $items = [];
         for ($index = 0; $index < 10; $index++) {
            $items[] = "Item {$index}";
         }

         [$Session, $Output] = $make("\e[B\e[B\e[B\n", $items);
         $Session->viewport = 3;

         $found = $Session->find();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $found === 'Item 3' && str_contains($output, 'Search: Item 3') === true,
            description: 'Three downs + Enter find the fourth item and render its label'
         );
         yield assert(
            assertion: str_contains($output, '↑ 1 more') === true
               && str_contains($output, '↓ 6 more') === true,
            description: 'The window slides: both more markers render around the rows'
         );

         // @ Session — a query matching nothing renders the placeholder row
         [$Empty, $Output] = $make("z\e", $databases);

         $found = $Empty->find();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $found === null && str_contains($output, '(no matches)') === true,
            description: 'No matches render the placeholder; Esc cancels to null'
         );
      }
      else {
         // @ Non-interactive — a typed line resolves by case-insensitive exact label
         [$Finder] = $make("mysql\n", $databases);

         yield assert(
            assertion: $Finder->find() === 'db.mysql',
            description: 'Pipes: the typed label resolves to its value, case-insensitively'
         );

         // @ Non-interactive — a substring is never an exact label
         [$Partial] = $make("sql\n", $databases);

         yield assert(
            assertion: $Partial->find() === null,
            description: 'Pipes: a partial label match returns null'
         );

         // @ Non-interactive — an unknown line finds nothing
         [$Unknown] = $make("oracle\n", $databases);

         yield assert(
            assertion: $Unknown->find() === null && $Unknown->found === null,
            description: 'Pipes: a non-matching line returns null'
         );

         // @ Non-interactive — the dynamic source receives the typed line
         $queries = [];

         [$Sourced] = $make("custom\n", []);
         $Sourced->source = static function (string $query) use (&$queries): array {
            $queries[] = $query;

            // :
            return ['on.custom' => 'Custom'];
         };

         yield assert(
            assertion: $Sourced->find() === 'on.custom' && $queries === ['custom'],
            description: 'Pipes: the source is queried with the typed line and resolves the value'
         );
      }
   }
);
