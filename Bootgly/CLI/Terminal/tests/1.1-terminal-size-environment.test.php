<?php

namespace Bootgly\CLI;


use function assert;
use function putenv;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should resolve Terminal size from the COLUMNS / LINES environment variables',
   test: function () {
      // ! Environment
      putenv('COLUMNS=123');
      putenv('LINES=45');

      // @
      new Terminal;

      // @ Valid
      yield assert(
         assertion: Terminal::$columns === 123,
         description: 'Columns resolved from environment: ' . Terminal::$columns
      );
      yield assert(
         assertion: Terminal::$lines === 45,
         description: 'Lines resolved from environment: ' . Terminal::$lines
      );
      yield assert(
         assertion: Terminal::$width === Terminal::$columns,
         description: 'Width mirrors columns: ' . Terminal::$width
      );
      yield assert(
         assertion: Terminal::$height === Terminal::$lines,
         description: 'Height mirrors lines: ' . Terminal::$height
      );

      // @ Restore environment and Terminal size
      putenv('COLUMNS');
      putenv('LINES');
      new Terminal;
   }
);
