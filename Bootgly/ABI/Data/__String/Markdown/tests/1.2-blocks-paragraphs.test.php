<?php

use Bootgly\ABI\Data\__String\Markdown;
use Bootgly\ABI\Data\__String\Markdown\Blocks;
use Bootgly\ABI\Data\__String\Markdown\Inlines;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should accumulate paragraphs with soft and hard breaks',
   test: function () {
      $Markdown = new Markdown;

      // @ Soft breaks join with a single space
      $Blocks = $Markdown->parse("line one\nline two");

      yield assert(
         assertion: count($Blocks) === 1
            && $Blocks[0]->type === Blocks::Paragraph
            && $Blocks[0]->Children[0]->text === 'line one line two',
         description: 'Consecutive lines join into one paragraph with soft spaces'
      );

      // @ Two trailing spaces make a hard break
      $Blocks = $Markdown->parse("hard  \nbreak");
      $Children = $Blocks[0]->Children;

      yield assert(
         assertion: $Children[0]->text === 'hard'
            && $Children[1]->type === Inlines::Break
            && $Children[2]->text === 'break',
         description: 'Trailing double spaces produce a Break node'
      );

      // @ Blank lines separate paragraphs
      $Blocks = $Markdown->parse("one\n\ntwo");

      yield assert(
         assertion: count($Blocks) === 2
            && $Blocks[0]->type === Blocks::Paragraph
            && $Blocks[1]->type === Blocks::Paragraph,
         description: 'A blank line closes the paragraph'
      );

      // @ Leading indentation is trimmed
      $Blocks = $Markdown->parse('   indented');

      yield assert(
         assertion: $Blocks[0]->Children[0]->text === 'indented',
         description: 'Paragraph leading whitespace is trimmed'
      );
   }
);
