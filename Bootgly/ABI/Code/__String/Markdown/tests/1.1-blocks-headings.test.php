<?php

use Bootgly\ABI\Code\__String\Markdown;
use Bootgly\ABI\Code\__String\Markdown\Blocks;
use Bootgly\ABI\Code\__String\Markdown\Inlines;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should parse ATX headings by level and reject fakes',
   test: function () {
      $Markdown = new Markdown;

      // @ Levels 1-6
      $Blocks = $Markdown->parse("# One\n###### Six");

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Heading && $Blocks[0]->level === 1
            && $Blocks[1]->type === Blocks::Heading && $Blocks[1]->level === 6,
         description: 'Hash runs map to heading levels 1-6'
      );
      yield assert(
         assertion: $Blocks[0]->Children[0]->type === Inlines::Text
            && $Blocks[0]->Children[0]->text === 'One',
         description: 'The heading content is inline-scanned'
      );

      // @ A space is required after the hashes
      $Blocks = $Markdown->parse('#hello');

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Paragraph,
         description: '`#hello` is a paragraph, not a heading'
      );

      // @ Seven hashes fall through
      $Blocks = $Markdown->parse('####### seven');

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Paragraph,
         description: 'Seven hashes are a paragraph'
      );

      // @ A space-preceded trailing hash run strips
      $Blocks = $Markdown->parse('## Two ##');

      yield assert(
         assertion: $Blocks[0]->level === 2 && $Blocks[0]->Children[0]->text === 'Two',
         description: 'The closing hash run is stripped'
      );

      // @ An empty heading is valid
      $Blocks = $Markdown->parse('##');

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Heading
            && $Blocks[0]->level === 2 && $Blocks[0]->Children === [],
         description: 'A bare `##` is an empty level-2 heading'
      );
   }
);
