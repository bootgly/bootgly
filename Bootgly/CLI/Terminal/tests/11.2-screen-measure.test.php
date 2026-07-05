<?php

namespace Bootgly\CLI\Terminal;


use function assert;
use function putenv;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal;


return new Specification(
   description: 'It should measure the terminal size from the COLUMNS / LINES environment variables',
   test: function () {
      // ! Environment
      putenv('COLUMNS=123');
      putenv('LINES=45');

      // @
      [$columns, $lines] = Screen::measure();

      // @ Valid
      yield assert(
         assertion: $columns === 123,
         description: "Columns measured from environment: {$columns}"
      );
      yield assert(
         assertion: $lines === 45,
         description: "Lines measured from environment: {$lines}"
      );

      // @ Restore environment
      putenv('COLUMNS');
      putenv('LINES');

      // @ Terminal facade delegates to the same probe
      putenv('COLUMNS=67');
      putenv('LINES=8');

      new Terminal;

      yield assert(
         assertion: Terminal::$columns === 67 && Terminal::$lines === 8,
         description: 'Terminal size delegates to Screen::measure(): '
            . Terminal::$columns . '×' . Terminal::$lines
      );

      // @ Restore environment and Terminal size
      putenv('COLUMNS');
      putenv('LINES');
      new Terminal;
   }
);
