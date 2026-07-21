<?php

use Bootgly\ABI\Code\__String\Markdown;
use Bootgly\ABI\Code\__String\Markdown\Inlines;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should give code spans precedence and match exact runs',
   test: function () {
      $Markdown = new Markdown;

      $inline = static fn (string $source): array => $Markdown->parse($source)[0]->Children;

      // @ Code wins over emphasis
      $Nodes = $inline('`*not em*`');

      yield assert(
         assertion: $Nodes[0]->type === Inlines::Code && $Nodes[0]->text === '*not em*',
         description: 'Emphasis characters inside a code span stay literal'
      );

      // @ Exact-length run matching
      $Nodes = $inline('``a`b``');

      yield assert(
         assertion: $Nodes[0]->type === Inlines::Code && $Nodes[0]->text === 'a`b',
         description: 'A double-backtick span may contain a single backtick'
      );

      // @ One padding space strips when both sides are padded
      $Nodes = $inline('` code `');

      yield assert(
         assertion: $Nodes[0]->text === 'code',
         description: 'Symmetric padding spaces strip once'
      );

      // @ Unterminated backticks are literal
      $Nodes = $inline('a `unclosed');

      yield assert(
         assertion: count($Nodes) === 1
            && $Nodes[0]->type === Inlines::Text
            && $Nodes[0]->text === 'a `unclosed',
         description: 'A backtick without a closer stays literal'
      );
   }
);
