<?php

namespace Bootgly\ABI\Code\__String\Tokens;


use function assert;
use function count;

use Bootgly\ABI\Code\__String\Tokens;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should tokenize edge sources without crashing',
   test: function () {
      $Tokens = new Tokens;

      // @ Empty source
      $lines = $Tokens->tokenize('');

      yield assert(
         assertion: $lines === [[]],
         description: 'An empty source yields a single empty line'
      );

      // @ Unterminated comment spans to EOF
      $lines = $Tokens->tokenize("<?php\n/* a\nb");

      $comment = false;
      foreach ($lines as $line) {
         foreach ($line as $segment) {
            if ($segment[0] === Tokens::TOKEN_COMMENT) {
               $comment = true;
            }
         }
      }

      yield assert(
         assertion: count($lines) === 3 && $comment === true,
         description: 'An unterminated comment groups to EOF with the line count intact'
      );

      // @ Heredoc body groups as string
      $lines = $Tokens->tokenize("<?php\n\$s = <<<TXT\nabc\nTXT;\n");

      $string = false;
      foreach ($lines as $line) {
         foreach ($line as $segment) {
            if ($segment[0] === Tokens::TOKEN_STRING) {
               $string = true;
            }
         }
      }

      yield assert(
         assertion: count($lines) === 5 && $string === true,
         description: 'A heredoc body groups as string with the line count intact'
      );
   }
);
