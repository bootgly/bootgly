<?php

namespace Bootgly\CLI\UX;


use function assert;
use function fopen;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should control aiming, confirming and canceling with pure keystrokes',
   test: function () {
      // ! Static options — string keys return the key, int keys return the label
      $options = [
         'mysql' => 'MySQL',
         'pgsql' => 'PostgreSQL',
         'sqlite' => 'SQLite'
      ];

      // ! Finder factory with in-memory streams — control() is pure, no key feed
      $make = static function (array $options, int $viewport = 8): Finder {
         $stream = fopen('php://memory', 'r+');

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Finder = new Finder($Input, $Output);
         $Finder->options = $options;
         $Finder->viewport = $viewport;

         // :
         return $Finder;
      };

      // @ Aiming — arrows clamp with no wrap
      $Finder = $make($options);
      $Finder->control('s');

      yield assert(
         assertion: $Finder->Window->total === 3 && $Finder->aimed === 0,
         description: 'A query edit refilters: every matching option enters the window'
      );

      $Finder->control("\e[B");
      $Finder->control("\e[B");
      $Finder->control("\e[B");
      $Finder->control("\e[B");

      yield assert(
         assertion: $Finder->aimed === 2,
         description: 'Down clamps the aim at the last match — no wrap'
      );

      $Finder->control("\e[A");
      $Finder->control("\e[A");
      $Finder->control("\e[A");

      yield assert(
         assertion: $Finder->aimed === 0,
         description: 'Up clamps the aim at the first match — no wrap'
      );

      // @ Confirming — Enter resolves the aimed match value
      $Finder->control("\e[B");
      $continues = $Finder->control("\n");

      yield assert(
         assertion: $continues === false && $Finder->found === 'pgsql',
         description: 'Enter confirms the aimed match: returns false and sets $found'
      );

      $Carriage = $make($options);
      $Carriage->control('s');
      $continues = $Carriage->control("\r");

      yield assert(
         assertion: $continues === false && $Carriage->found === 'mysql',
         description: 'Carriage return confirms exactly like Enter'
      );

      // @ Confirming with no matches — a pure selector never submits raw text
      $Empty = $make($options);
      $Empty->control('zzz');

      yield assert(
         assertion: $Empty->Window->total === 0,
         description: 'A query matching nothing empties the window'
      );

      $continues = $Empty->control("\n");

      yield assert(
         assertion: $continues === true && $Empty->found === null,
         description: 'Enter with no matches is a no-op: continues and finds nothing'
      );

      // @ Canceling — bare Esc finishes with nothing found
      $continues = $Empty->control("\e");

      yield assert(
         assertion: $continues === false && $Empty->found === null,
         description: 'Esc cancels: returns false and $found stays null'
      );

      // @ Refiltering — a changed query resets the aim
      $Refilter = $make($options);
      $Refilter->control('sql');
      $Refilter->control("\e[B");
      $Refilter->control("\e[B");
      $Refilter->control('i');

      yield assert(
         assertion: $Refilter->aimed === 0 && $Refilter->Window->total === 1,
         description: 'A changed query refilters and resets the aim to the first match'
      );

      // @ Non-edit control keys — an unhandled sequence edits nothing
      $continues = $Refilter->control("\e[5~");

      yield assert(
         assertion: $continues === true && $Refilter->Window->total === 1 && $Refilter->aimed === 0,
         description: 'PageUp edits no query: no refilter, the window stays intact'
      );

      // @ Erasing — Ctrl+U kills the whole query, Backspace one character
      $Refilter->control("\x15");

      yield assert(
         assertion: $Refilter->Window->total === 3,
         description: 'Ctrl+U clears the whole query: every option matches again'
      );

      $Refilter->control('pg');
      $emptied = $Refilter->Window->total;
      $Refilter->control("\x7F");

      yield assert(
         assertion: $emptied === 0 && $Refilter->Window->total === 1,
         description: 'Backspace removes one character and refilters the emptied window'
      );

      // @ Empty key — a safe no-op
      $continues = $Refilter->control('');

      yield assert(
         assertion: $continues === true && $Refilter->aimed === 0 && $Refilter->Window->total === 1,
         description: 'An empty key is a safe no-op'
      );

      // @ Sliding — the viewport window follows the aim down
      $Viewport = $make(['Item 1', 'Item 2', 'Item 3', 'Item 4', 'Item 5'], viewport: 2);
      $Viewport->control('Item');
      $Viewport->control("\e[B");
      $Viewport->control("\e[B");
      $Viewport->control("\e[B");

      yield assert(
         assertion: $Viewport->aimed === 3
            && $Viewport->Window->first === 2
            && $Viewport->Window->last === 3,
         description: 'The viewport slides down so the aimed row stays visible'
      );

      $continues = $Viewport->control("\n");

      yield assert(
         assertion: $continues === false && $Viewport->found === 'Item 4',
         description: 'Int-keyed options confirm the label itself as the value'
      );
   }
);
