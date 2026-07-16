<?php

namespace Bootgly\ABI\Data\__String\Tokens;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should normalize CRLF and CR sources',
   test: function () {
      $Highlighter = new Highlighter;

      $expected = $Highlighter->highlight("\$a = 1;\n\$b = 2;", gutter: false);

      // @ CRLF
      yield assert(
         assertion: $Highlighter->highlight("\$a = 1;\r\n\$b = 2;", gutter: false) === $expected,
         description: 'CRLF sources render identically to LF sources'
      );

      // @ Lone CR
      yield assert(
         assertion: $Highlighter->highlight("\$a = 1;\r\$b = 2;", gutter: false) === $expected,
         description: 'CR sources render identically to LF sources'
      );

      // @ Guttered parity
      yield assert(
         assertion: $Highlighter->highlight("\$a = 1;\r\n\$b = 2;")
            === $Highlighter->highlight("\$a = 1;\n\$b = 2;"),
         description: 'Normalization also holds for guttered output'
      );
   }
);
