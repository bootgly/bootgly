<?php

use Bootgly\ABI\Data\__String\Markdown;
use Bootgly\ABI\Data\__String\Markdown\Blocks;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should parse blockquotes with nesting and lazy continuation',
   test: function () {
      $Markdown = new Markdown;

      // @ Simple quote
      $Blocks = $Markdown->parse('> quoted');

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Quote
            && $Blocks[0]->Children[0]->type === Blocks::Paragraph
            && $Blocks[0]->Children[0]->Children[0]->text === 'quoted',
         description: 'A `>` line becomes a Quote holding a Paragraph'
      );

      // @ Nesting — one marker level strips per pass
      $Blocks = $Markdown->parse("> outer\n> > inner");
      $Quote = $Blocks[0];

      yield assert(
         assertion: $Quote->Children[0]->type === Blocks::Paragraph
            && $Quote->Children[1]->type === Blocks::Quote
            && $Quote->Children[1]->Children[0]->Children[0]->text === 'inner',
         description: '`> >` nests a Quote inside the Quote'
      );

      // @ Lazy continuation — plain text keeps the quote open
      $Blocks = $Markdown->parse("> start\nlazy");

      yield assert(
         assertion: count($Blocks) === 1
            && $Blocks[0]->Children[0]->Children[0]->text === 'start lazy',
         description: 'A plain following line continues the quoted paragraph'
      );

      // @ A blank line closes the quote
      $Blocks = $Markdown->parse("> inside\n\noutside");

      yield assert(
         assertion: count($Blocks) === 2
            && $Blocks[0]->type === Blocks::Quote
            && $Blocks[1]->type === Blocks::Paragraph,
         description: 'The quote ends at the blank line'
      );

      // @ A block starter stops the lazy continuation
      $Blocks = $Markdown->parse("> inside\n# heading");

      yield assert(
         assertion: count($Blocks) === 2 && $Blocks[1]->type === Blocks::Heading,
         description: 'A heading after the quote is not swallowed by laziness'
      );
   }
);
