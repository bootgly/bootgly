<?php

use Bootgly\ABI\Code\__String\Markdown;
use Bootgly\ABI\Code\__String\Markdown\Blocks;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should survive degenerate inputs and normalize line endings',
   test: function () {
      $Markdown = new Markdown;

      // @ Empty and whitespace-only inputs
      yield assert(
         assertion: $Markdown->parse('') === [],
         description: 'An empty string parses to no blocks'
      );
      yield assert(
         assertion: $Markdown->parse("  \n\n   \n") === [],
         description: 'Whitespace-only input parses to no blocks'
      );

      // @ CRLF and CR normalize to LF
      $Blocks = $Markdown->parse("# A\r\ntext\rmore");

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Heading
            && count($Blocks) === 2
            && $Blocks[1]->Children[0]->text === 'text more',
         description: 'CRLF/CR line endings behave like LF'
      );

      // @ Tabs expand to four spaces (indentation semantics)
      $Blocks = $Markdown->parse("- item\n\tcontinued");

      yield assert(
         assertion: count($Blocks) === 1
            && $Blocks[0]->Children[0]->Children[0]->Children[0]->text === 'item continued',
         description: 'A tab-indented line continues the list item'
      );

      // @ NUL bytes strip
      $Blocks = $Markdown->parse("a\0b");

      yield assert(
         assertion: $Blocks[0]->Children[0]->text === 'ab',
         description: 'NUL bytes never reach the AST'
      );

      // @ Raw HTML stays literal text
      $Blocks = $Markdown->parse('<div>x</div>');

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Paragraph
            && $Blocks[0]->Children[0]->text === '<div>x</div>',
         description: 'HTML is not interpreted'
      );
   }
);
