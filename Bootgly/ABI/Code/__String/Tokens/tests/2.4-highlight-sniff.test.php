<?php

namespace Bootgly\ABI\Code\__String\Tokens;


use function assert;
use function str_contains;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should colorize tagless snippets as pure PHP',
   test: function () {
      $Highlighter = new Highlighter;

      // @ Tagless snippet — synthetic open tag is prepended and dropped
      $highlighted = $Highlighter->highlight("use Foo;\n\$a = 1;", gutter: false);

      yield assert(
         assertion: str_contains($highlighted, '<?php') === false
            && substr_count($highlighted, "\n") === 1,
         description: 'The synthetic open tag never reaches the output and the line count holds'
      );
      yield assert(
         assertion: str_contains($highlighted, "\e[96m") === true
            && str_contains($highlighted, "\e[95muse") === true,
         description: 'Tagless snippets colorize as pure PHP (use paints magenta)'
      );

      // @ Tagless snippet — guttered numbering starts at 1
      $highlighted = $Highlighter->highlight("\$a = 1;\n\$b = 2;");

      yield assert(
         assertion: str_contains($highlighted, '  1') === true
            && str_contains($highlighted, '  2') === true
            && str_contains($highlighted, '<?php') === false,
         description: 'Guttered tagless snippets number from line 1'
      );

      // @ Real open tag — the tag line is preserved
      $highlighted = $Highlighter->highlight("<?php\n\$a = 1;", gutter: false);

      yield assert(
         assertion: str_contains($highlighted, '<?php') === true
            && substr_count($highlighted, "\n") === 1,
         description: 'Sources with a real open tag keep it on line 1'
      );

      // @ Echo tag — raw tokenization path
      $highlighted = $Highlighter->highlight('<?= $x ?>', gutter: false);

      yield assert(
         assertion: str_contains($highlighted, '<?=') === true,
         description: 'Echo-tag sources take the raw path'
      );

      // @ Snippet merely mentioning an open tag — raw path, tag text survives
      $highlighted = $Highlighter->highlight("\$s = 'has <?php inside';", gutter: false);

      yield assert(
         assertion: str_contains($highlighted, '<?php') === true
            && str_contains($highlighted, 'inside') === true,
         description: 'A mentioned open tag keeps the raw tokenization (segments split by SGR)'
      );
   }
);
