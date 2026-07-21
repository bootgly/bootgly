<?php

namespace Bootgly\ABI\Code\__String\Tokens;


use function assert;
use function count;
use function str_contains;

use Bootgly\ABI\Code\__String\Tokens;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should split token segments per line and keep trailing content',
   test: function () {
      $Tokens = new Tokens;

      // @ Per-line split
      $lines = $Tokens->tokenize("<?php\n\$a = 1;\n\$b = 2;");

      yield assert(
         assertion: count($lines) === 3,
         description: 'A three-line source splits into three token lines'
      );

      // @ Consecutive same-type tokens coalesce into one segment
      $lines = $Tokens->tokenize('<?php $a = [];');

      $coalesced = false;
      foreach ($lines as $line) {
         foreach ($line as $segment) {
            // ! Leading whitespace merges into the following segment
            if ($segment[0] === Tokens::TOKEN_DELIMITER && str_contains((string) $segment[1], '[]') === true) {
               $coalesced = true;
            }
         }
      }

      yield assert(
         assertion: $coalesced === true,
         description: 'Adjacent delimiters coalesce into a single segment'
      );

      // @ Trailing close tag survives (the trailing-buffer flush)
      $lines = $Tokens->tokenize('<?php $a = 1; ?>');

      $flat = '';
      foreach ($lines as $line) {
         foreach ($line as $segment) {
            $flat .= $segment[1];
         }
      }

      yield assert(
         assertion: str_contains($flat, '?>') === true,
         description: 'A trailing close tag is not dropped'
      );

      // @ Trailing newline yields a final empty line (explode parity)
      $lines = $Tokens->tokenize("<?php\n\$a = 1;\n");

      yield assert(
         assertion: count($lines) === 3 && $lines[2] === [],
         description: 'A trailing newline keeps its final empty line'
      );
   }
);
