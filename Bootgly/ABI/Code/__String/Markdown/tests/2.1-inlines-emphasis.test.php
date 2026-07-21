<?php

use Bootgly\ABI\Code\__String\Markdown;
use Bootgly\ABI\Code\__String\Markdown\Inlines;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should pair emphasis delimiters with CommonMark-like nesting',
   test: function () {
      $Markdown = new Markdown;

      // ! Inline helper — first paragraph children
      $inline = static fn (string $source): array => $Markdown->parse($source)[0]->Children;

      // @ The three basic emphases
      $Nodes = $inline('**b** *i* ~~s~~');

      yield assert(
         assertion: $Nodes[0]->type === Inlines::Bold
            && $Nodes[2]->type === Inlines::Italic
            && $Nodes[4]->type === Inlines::Strike,
         description: 'Bold, italic and strike pair their delimiters'
      );

      // @ Triple stars resolve to Italic(Bold)
      $Nodes = $inline('***x***');

      yield assert(
         assertion: $Nodes[0]->type === Inlines::Italic
            && $Nodes[0]->Children[0]->type === Inlines::Bold
            && $Nodes[0]->Children[0]->Children[0]->text === 'x',
         description: '`***x***` nests Bold inside Italic'
      );

      // @ Nested emphasis inside bold
      $Nodes = $inline('**a *b* c**');
      $Bold = $Nodes[0];

      yield assert(
         assertion: $Bold->type === Inlines::Bold
            && $Bold->Children[0]->text === 'a '
            && $Bold->Children[1]->type === Inlines::Italic
            && $Bold->Children[2]->text === ' c',
         description: 'Italic nests inside Bold with surrounding text'
      );

      // @ Unmatched delimiters degrade to literal text
      $Nodes = $inline('**unclosed');

      yield assert(
         assertion: $Nodes[0]->type === Inlines::Text && $Nodes[0]->text === '**'
            && $Nodes[1]->text === 'unclosed',
         description: 'An unclosed `**` stays literal'
      );

      // @ Space-flanked stars are inert
      $Nodes = $inline('a * b * c');

      yield assert(
         assertion: count($Nodes) === 1
            && $Nodes[0]->type === Inlines::Text && $Nodes[0]->text === 'a * b * c',
         description: 'Delimiters flanked by spaces never pair'
      );

      // @ Underscores never work intraword; stars do
      $Nodes = $inline('snake_case_name');

      yield assert(
         assertion: count($Nodes) === 1
            && $Nodes[0]->type === Inlines::Text && $Nodes[0]->text === 'snake_case_name',
         description: '`snake_case_name` keeps its underscores literal'
      );

      $Nodes = $inline('in*tra*word');

      yield assert(
         assertion: $Nodes[1]->type === Inlines::Italic
            && $Nodes[1]->Children[0]->text === 'tra',
         description: 'Intraword stars still emphasize'
      );

      // @ A single tilde is literal
      $Nodes = $inline('~one~ and ~~~three~~~');

      yield assert(
         assertion: $Nodes[0]->type === Inlines::Text,
         description: 'Only exactly two tildes strike'
      );
   }
);
