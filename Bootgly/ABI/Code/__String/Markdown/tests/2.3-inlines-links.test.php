<?php

use Bootgly\ABI\Code\__String\Markdown;
use Bootgly\ABI\Code\__String\Markdown\Inlines;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should parse inline links and images',
   test: function () {
      $Markdown = new Markdown;

      $inline = static fn (string $source): array => $Markdown->parse($source)[0]->Children;

      // @ A basic link
      $Nodes = $inline('[label](https://bootgly.com)');

      yield assert(
         assertion: $Nodes[0]->type === Inlines::Link
            && $Nodes[0]->URL === 'https://bootgly.com'
            && $Nodes[0]->Children[0]->text === 'label',
         description: 'Link label and destination parse'
      );

      // @ The label is inline-scanned
      $Nodes = $inline('[has **bold**](u)');

      yield assert(
         assertion: $Nodes[0]->Children[1]->type === Inlines::Bold,
         description: 'Emphasis works inside link labels'
      );

      // @ A quoted title is tolerated and discarded
      $Nodes = $inline('[t](https://x.y "the title")');

      yield assert(
         assertion: $Nodes[0]->URL === 'https://x.y',
         description: 'The title never leaks into the URL'
      );

      // @ Images keep a literal alt
      $Nodes = $inline('![the *alt*](img.png)');

      yield assert(
         assertion: $Nodes[0]->type === Inlines::Image
            && $Nodes[0]->text === 'the *alt*'
            && $Nodes[0]->URL === 'img.png',
         description: 'Image alt text is literal, not scanned'
      );

      // @ A bracket without a destination is literal
      $Nodes = $inline('[not a link]');

      yield assert(
         assertion: count($Nodes) === 1
            && $Nodes[0]->type === Inlines::Text
            && $Nodes[0]->text === '[not a link]',
         description: 'No `(` after `]` keeps the bracket literal'
      );

      // @ Escaped brackets never open links
      $Nodes = $inline('\[x](y)');

      yield assert(
         assertion: $Nodes[0]->type === Inlines::Text
            && $Nodes[0]->text === '[x](y)',
         description: '`\[` is a literal bracket'
      );
   }
);
