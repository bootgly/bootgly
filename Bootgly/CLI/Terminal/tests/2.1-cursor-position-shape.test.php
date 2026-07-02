<?php

namespace Bootgly\CLI\Terminal;


use function assert;
use function is_int;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should always expose the Cursor position with the 4-key shape',
   test: function () {
      $Output = new Output('php://memory');

      // @
      $position = $Output->Cursor->position;

      // @ Valid (both the interactive and the degraded paths must honor the contract)
      yield assert(
         assertion: isSet($position[0], $position[1], $position['row'], $position['column']),
         description: 'Position exposes the 0, 1, row and column keys'
      );
      yield assert(
         assertion: is_int($position['row']) && $position['row'] >= 0,
         description: 'Row is a non-negative integer: ' . $position['row']
      );
      yield assert(
         assertion: is_int($position['column']) && $position['column'] >= 0,
         description: 'Column is a non-negative integer: ' . $position['column']
      );
      yield assert(
         assertion: $position[0] === $position['row'] && $position[1] === $position['column'],
         description: 'Indexed and named keys mirror each other'
      );
   }
);
