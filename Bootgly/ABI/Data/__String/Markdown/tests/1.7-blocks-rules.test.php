<?php

use Bootgly\ABI\Data\__String\Markdown;
use Bootgly\ABI\Data\__String\Markdown\Blocks;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should parse horizontal rules from dashes, stars and underscores',
   test: function () {
      $Markdown = new Markdown;

      // @ The three characters, plain and spaced
      foreach (['---', '***', '___', '- - -', '*  *  *'] as $source) {
         $Blocks = $Markdown->parse($source);

         yield assert(
            assertion: $Blocks[0]->type === Blocks::Rule,
            description: "`{$source}` is a Rule"
         );
      }

      // @ Two characters are not enough
      $Blocks = $Markdown->parse('--');

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Paragraph,
         description: '`--` is a paragraph'
      );

      // @ Mixed characters are not a rule
      $Blocks = $Markdown->parse('-*-');

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Paragraph,
         description: 'Mixed rule characters stay a paragraph'
      );
   }
);
