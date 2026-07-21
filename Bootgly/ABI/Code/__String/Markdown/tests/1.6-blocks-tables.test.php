<?php

use Bootgly\ABI\Code\__String\Markdown;
use Bootgly\ABI\Code\__String\Markdown\Blocks;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should parse GFM tables with alignments and ragged rows',
   test: function () {
      $Markdown = new Markdown;

      // @ Alignments from the separator row
      $Blocks = $Markdown->parse("| L | C | R |\n|:--|:-:|--:|\n| a | b | c |");
      $Table = $Blocks[0];

      yield assert(
         assertion: $Table->type === Blocks::Table
            && $Table->alignments === ['left', 'center', 'right'],
         description: 'Separator colons map to left/center/right'
      );
      yield assert(
         assertion: count($Table->Children) === 2
            && count($Table->Children[0]->Children) === 3
            && $Table->Children[0]->Children[0]->Children[0]->text === 'L',
         description: 'The header row is row 0 with one Cell per column'
      );

      // @ Ragged rows pad missing cells and drop extras
      $Blocks = $Markdown->parse("| A | B |\n|---|---|\n| only |\n| x | y | extra |");
      $Rows = $Blocks[0]->Children;

      yield assert(
         assertion: count($Rows[1]->Children) === 2
            && $Rows[1]->Children[1]->Children === []
            && count($Rows[2]->Children) === 2,
         description: 'Short rows pad empty cells; long rows drop extras'
      );

      // @ Escaped pipes stay inside their cell
      $Blocks = $Markdown->parse("| a\\|b |\n|---|\n| c |");

      yield assert(
         assertion: $Blocks[0]->Children[0]->Children[0]->Children[0]->text === 'a|b',
         description: '`\\|` does not split the cell'
      );

      // @ A pipe line without a separator is a paragraph
      $Blocks = $Markdown->parse('just | a | pipe');

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Paragraph,
         description: 'No separator row, no table'
      );

      // @ A column-count mismatch voids the table
      $Blocks = $Markdown->parse("| a | b |\n|---|\ntext");

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Paragraph,
         description: 'Header/separator column counts must match'
      );

      // @ The body stops at a blank line
      $Blocks = $Markdown->parse("| a |\n|---|\n| b |\n\nafter");

      yield assert(
         assertion: count($Blocks) === 2
            && count($Blocks[0]->Children) === 2
            && $Blocks[1]->type === Blocks::Paragraph,
         description: 'The table closes before the trailing paragraph'
      );
   }
);
