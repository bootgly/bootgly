<?php

namespace Bootgly\CLI;


use function assert;
use function putenv;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should fall back to tput / defaults when COLUMNS / LINES are not numeric',
   test: function () {
      // ! Environment
      putenv('COLUMNS=abc');
      putenv('LINES=xyz');

      // @
      new Terminal;

      // @ Valid (exact values depend on the host: tput result or the 80x30 defaults)
      yield assert(
         assertion: Terminal::$columns >= 1,
         description: 'Columns resolved to a positive integer: ' . Terminal::$columns
      );
      yield assert(
         assertion: Terminal::$lines >= 1,
         description: 'Lines resolved to a positive integer: ' . Terminal::$lines
      );

      // @ Restore environment and Terminal size
      putenv('COLUMNS');
      putenv('LINES');
      new Terminal;
   }
);
