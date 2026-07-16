<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should highlight PHP sources with an optional gutter',
   test: function () {
      $make = static function (): Highlighter {
         $Highlighter = new Highlighter(new Output('php://memory'));
         $Highlighter->decoration = true;

         return $Highlighter;
      };

      // @ Gutterless — bare colored lines
      $Highlighter = $make();
      $Highlighter->gutter = false;
      $Highlighter->source = "\$greeting = 'Hello';\n\$count = 3;";
      $rendered = (string) $Highlighter->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[96m") === true
            && str_contains($rendered, "\e[92m") === true,
         description: 'Colored output paints variables (96) and strings (92)'
      );
      yield assert(
         assertion: str_contains($rendered, '▕') === false
            && str_contains($rendered, '<?php') === false,
         description: 'Gutterless output has no divider and no synthetic open tag'
      );
      yield assert(
         assertion: substr_count($rendered, "\n") === 2,
         description: 'Two source lines render as two lines plus the final EOL'
      );

      // @ Guttered — numbered lines
      $Highlighter = $make();
      $Highlighter->source = "\$a = 1;";
      $rendered = (string) $Highlighter->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '▕') === true
            && str_contains($rendered, '  1') === true,
         description: 'Guttered output has the divider and padded line numbers'
      );

      // @ WRITE_OUTPUT writes to the Output stream
      $Output = new Output('php://memory');
      $Highlighter = new Highlighter($Output);
      $Highlighter->decoration = true;
      $Highlighter->gutter = false;
      $Highlighter->source = '$x = 1;';
      $Highlighter->render();

      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($written, "\e[96m") === true
            && str_contains($written, '$x') === true,
         description: 'render() writes the highlighted source to the Output'
      );
   }
);
