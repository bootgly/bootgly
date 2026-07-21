<?php

namespace Bootgly\ABI\Code\__String\Tokens;


use function assert;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should accept an injected theme',
   test: function () {
      // @ Custom theme — string decorations wrap every segment
      $Highlighter = new Highlighter([
         'highlighter.test' => [
            'options' => [
               'prepending' => ['type' => 'string', 'value' => "\e[35m"],
               'appending'  => ['type' => 'string', 'value' => "\e[0m"]
            ],
            'values' => []
         ]
      ]);

      $highlighted = $Highlighter->highlight("\$a = 'x';", gutter: false);

      yield assert(
         assertion: str_contains($highlighted, "\e[35m") === true
            && str_contains($highlighted, "\e[92m") === false,
         description: 'The injected theme replaces the default palette'
      );

      // @ Default theme — the access operator is painted (no bare reset wrapper)
      $Highlighter = new Highlighter;

      $highlighted = $Highlighter->highlight('$a->b;', gutter: false);

      yield assert(
         assertion: str_contains($highlighted, "\e[37m->") === true,
         description: 'The default theme paints the object operator white'
      );
   }
);
