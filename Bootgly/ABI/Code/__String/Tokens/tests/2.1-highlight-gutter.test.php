<?php

namespace Bootgly\ABI\Code\__String\Tokens;


use const PHP_EOL;
use function assert;
use function str_contains;
use function str_ends_with;


use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render the line-number gutter by default',
   test: function () {
      $Highlighter = new Highlighter;

      // @ Default guttered output
      $highlighted = $Highlighter->highlight("<?php\n\$a = 'x';");

      yield assert(
         assertion: str_contains($highlighted, '▕') === true
            && str_contains($highlighted, '  1') === true
            && str_contains($highlighted, '  2') === true,
         description: 'Guttered output has padded line numbers and the divider'
      );
      yield assert(
         assertion: str_contains($highlighted, "\e[92m") === true,
         description: 'String tokens paint bright green'
      );
      yield assert(
         assertion: str_contains($highlighted, '▶') === false
            && str_ends_with($highlighted, PHP_EOL) === true,
         description: 'Unmarked output has no line marker and ends with an EOL'
      );

      // @ Empty source
      $highlighted = $Highlighter->highlight('');

      yield assert(
         assertion: str_contains($highlighted, '  1') === true
            && str_ends_with($highlighted, PHP_EOL) === true,
         description: 'An empty source renders one numbered blank line'
      );
   }
);
