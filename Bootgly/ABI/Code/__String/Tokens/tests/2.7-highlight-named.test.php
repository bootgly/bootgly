<?php

namespace Bootgly\ABI\Code\__String\Tokens;


use function assert;
use function str_contains;
use ValueError;

use Bootgly\ABI\Code\__String\Tokens;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should resolve named highlight themes from the registry',
   test: function () {
      // @ Custom named theme — registered values replace the default palette
      Highlighter::$Themes['test.red'] = [
         Tokens::TOKEN_VARIABLE => '31'
      ];

      $Highlighter = new Highlighter('test.red');
      $highlighted = $Highlighter->highlight("\$a = 'x';", gutter: false);

      yield assert(
         assertion: str_contains($highlighted, "\e[31m\$a") === true
            && str_contains($highlighted, "\e[96m") === false,
         description: 'A registered named theme paints with its own values'
      );

      unset(Highlighter::$Themes['test.red']);

      // @ Builtin `plain` — colorless render, zero escapes
      $Highlighter = new Highlighter('plain');
      $highlighted = $Highlighter->highlight("\$a = 'x';", gutter: false);

      yield assert(
         assertion: str_contains($highlighted, "\e") === false
            && str_contains($highlighted, '$a') === true,
         description: 'The builtin `plain` theme emits no escape codes'
      );

      // @ Default — the `bootgly` palette
      $Highlighter = new Highlighter;
      $highlighted = $Highlighter->highlight("\$a = 'x';", gutter: false);

      yield assert(
         assertion: str_contains($highlighted, "\e[96m\$a") === true,
         description: 'The default theme is the `bootgly` palette'
      );

      // @ Unknown names fail loud
      $caught = false;
      try {
         new Highlighter('nonexistent');
      }
      catch (ValueError) {
         $caught = true;
      }

      yield assert(
         assertion: $caught === true,
         description: 'An unknown theme name throws a ValueError'
      );
   }
);
