<?php

use Bootgly\ABI\Code\__String\Markdown;
use Bootgly\ABI\Code\__String\Markdown\Blocks;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should parse fenced code blocks verbatim',
   test: function () {
      $Markdown = new Markdown;

      // @ Basic fence with a language
      $Blocks = $Markdown->parse("```php\necho '*raw*';\n```");

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Fence
            && $Blocks[0]->language === 'php'
            && $Blocks[0]->text === "echo '*raw*';",
         description: 'The fence keeps its content verbatim and captures the language'
      );

      // @ Unterminated fences run to the end of input
      $Blocks = $Markdown->parse("```\nno close");

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Fence && $Blocks[0]->text === 'no close',
         description: 'An unterminated fence is still a code block'
      );

      // @ Tilde fences and longer closing runs
      $Blocks = $Markdown->parse("~~~\ncontent\n~~~~~");

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Fence && $Blocks[0]->text === 'content',
         description: 'A longer closing run closes a tilde fence'
      );

      // @ Content dedents by the opening fence indent only
      $Blocks = $Markdown->parse("  ```\n    keep\n  ```");

      yield assert(
         assertion: $Blocks[0]->text === '  keep',
         description: 'Content dedents by the opening indent, keeping the rest'
      );

      // @ Backtick fences reject backticks in the info string
      $Blocks = $Markdown->parse('``` a`b');

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Paragraph,
         description: 'A backtick in the info string voids the fence'
      );
   }
);
