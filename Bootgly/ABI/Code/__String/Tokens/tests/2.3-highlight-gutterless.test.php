<?php

namespace Bootgly\ABI\Code\__String\Tokens;


use function assert;
use function str_contains;
use function str_ends_with;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render bare colored lines without the gutter',
   test: function () {
      $Highlighter = new Highlighter;

      // @ Gutterless output
      $highlighted = $Highlighter->highlight("\$a = 1;\n\$b = \$a->c;", gutter: false);

      yield assert(
         assertion: str_contains($highlighted, '▕') === false
            && str_contains($highlighted, '▶') === false,
         description: 'Gutterless output has no divider and no marker'
      );
      yield assert(
         assertion: substr_count($highlighted, "\n") === 1
            && str_ends_with($highlighted, "\n") === false,
         description: 'Two source lines join with a single break and no trailing newline'
      );
      yield assert(
         assertion: str_contains($highlighted, "\e[96m") === true
            && str_contains($highlighted, "\e[37m->") === true,
         description: 'Variables paint bright cyan and the object operator paints white'
      );

      // @ Empty source
      yield assert(
         assertion: $Highlighter->highlight('', gutter: false) === '',
         description: 'An empty source renders as an empty string'
      );

      // @ Marked window still applies to bare lines
      $highlighted = $Highlighter->highlight("a();\nb();\nc();\nd();\ne();", 3, 1, 1, gutter: false);

      yield assert(
         assertion: str_contains($highlighted, 'b') === true
            && str_contains($highlighted, 'd') === true
            && str_contains($highlighted, 'e') === false,
         description: 'gutter: false composes with the marked-line window'
      );
   }
);
