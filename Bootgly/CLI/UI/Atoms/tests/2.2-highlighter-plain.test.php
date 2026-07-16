<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should degrade to escape-free output when undecorated',
   test: function () {
      $make = static function (): Highlighter {
         $Highlighter = new Highlighter(new Output('php://memory'));
         $Highlighter->decoration = false;

         return $Highlighter;
      };

      // @ Plain + gutter — structure survives, colors do not
      $Highlighter = $make();
      $Highlighter->source = "\$a = 1;\n\$b = 2;";
      $rendered = (string) $Highlighter->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e") === false,
         description: 'Plain output emits zero escape bytes'
      );
      yield assert(
         assertion: str_contains($rendered, '  1▕') === true
            && str_contains($rendered, '  2▕') === true,
         description: 'Plain output keeps the line numbers and the divider'
      );

      // @ Plain + no gutter — verbatim source
      $Highlighter = $make();
      $Highlighter->gutter = false;
      $Highlighter->source = "\$a = 1;\n\$b = 2;";
      $rendered = (string) $Highlighter->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: $rendered === "\$a = 1;\n\$b = 2;\n",
         description: 'Plain gutterless output is the verbatim source'
      );

      // @ Plain + marked line — the marker glyph survives, escape-free
      $Highlighter = $make();
      $Highlighter->mark = 2;
      $Highlighter->source = "\$a = 1;\n\$b = 2;\n\$c = 3;";
      $rendered = (string) $Highlighter->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '▶') === true
            && str_contains($rendered, "\e") === false,
         description: 'Plain marked output keeps the line marker without escapes'
      );
   }
);
