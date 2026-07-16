<?php

use Bootgly\ABI\Data\__String\Markdown;
use Bootgly\ABI\Data\__String\Markdown\Blocks;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should parse nested tight lists, tasks and marker splits',
   test: function () {
      $Markdown = new Markdown;

      // @ Unordered items
      $Blocks = $Markdown->parse("- one\n- two");
      $List = $Blocks[0];

      yield assert(
         assertion: $List->type === Blocks::List && $List->ordered === false
            && count($List->Children) === 2
            && $List->Children[0]->Children[0]->Children[0]->text === 'one',
         description: 'Bullets collect into one unordered List of Items'
      );

      // @ Ordered start number
      $Blocks = $Markdown->parse("3. three\n4. four");
      $List = $Blocks[0];

      yield assert(
         assertion: $List->ordered === true && $List->start === 3,
         description: 'The ordered list starts at the first item number'
      );

      // @ Nesting by indentation
      $Blocks = $Markdown->parse("- parent\n  - child");
      $Item = $Blocks[0]->Children[0];

      yield assert(
         assertion: $Item->Children[0]->type === Blocks::Paragraph
            && $Item->Children[1]->type === Blocks::List
            && $Item->Children[1]->Children[0]->Children[0]->Children[0]->text === 'child',
         description: 'Indented markers nest a List inside the Item'
      );

      // @ Marker flavor changes split lists
      $Blocks = $Markdown->parse("- dash\n* star");

      yield assert(
         assertion: count($Blocks) === 2
            && $Blocks[0]->type === Blocks::List && $Blocks[1]->type === Blocks::List,
         description: 'A different bullet character opens a new List'
      );

      $Blocks = $Markdown->parse("1. dot\n2) paren");

      yield assert(
         assertion: count($Blocks) === 2,
         description: 'A different ordered delimiter opens a new List'
      );

      // @ Task items
      $Blocks = $Markdown->parse("- [x] done\n- [ ] todo\n- [X] DONE\n- plain");
      $Items = $Blocks[0]->Children;

      yield assert(
         assertion: $Items[0]->checked === true
            && $Items[1]->checked === false
            && $Items[2]->checked === true
            && $Items[3]->checked === null,
         description: 'Task states parse; non-tasks stay null'
      );

      // @ Continuation lines join the item; `-foo` is no list
      $Blocks = $Markdown->parse("- first\n  more");

      yield assert(
         assertion: count($Blocks) === 1
            && $Blocks[0]->Children[0]->Children[0]->Children[0]->text === 'first more',
         description: 'Indented continuation joins the item paragraph'
      );

      $Blocks = $Markdown->parse('-foo');

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Paragraph,
         description: 'A marker without a space is a paragraph'
      );

      // @ `- - -` is a Rule, never a List
      $Blocks = $Markdown->parse('- - -');

      yield assert(
         assertion: $Blocks[0]->type === Blocks::Rule,
         description: 'Horizontal rules win over list items'
      );
   }
);
