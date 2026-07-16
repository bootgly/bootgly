<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should paint tables with per-column alignment',
   test: function () {
      // ! Plain factory
      $make = static function (string $source): Markdown {
         $Markdown = new Markdown(new Output('php://memory'));
         $Markdown->width = 60;
         $Markdown->decoration = false;
         $Markdown->source = $source;

         // :
         return $Markdown;
      };

      // @ Grid shape — header, separator, aligned body
      $rendered = (string) $make(
         "| Name | Qty |\n|:-----|----:|\n| Foo | 1 |\n| Barbaz | 42 |"
      )->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, ' Name   │ Qty') === true,
         description: 'The header row pads cells to the column width'
      );
      yield assert(
         assertion: str_contains($rendered, '┼') === true,
         description: 'A separator renders under the header'
      );
      yield assert(
         assertion: str_contains($rendered, ' Foo    │   1') === true
            && str_contains($rendered, ' Barbaz │  42') === true,
         description: 'Right-aligned cells pad on the left'
      );

      // @ Center alignment distributes the padding
      $rendered = (string) $make(
         "| CCCCC |\n|:-----:|\n| x |"
      )->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '  x  ') === true,
         description: 'Centered cells split their padding'
      );

      // @ Styled cells keep the grid aligned (SGR is zero-width)
      $Styled = new Markdown(new Output('php://memory'));
      $Styled->width = 60;
      $Styled->decoration = true;
      $Styled->source = "| A | B |\n|---|---|\n| **x** | y |";

      $rendered = (string) $Styled->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[1mx\e[0m") === true
            && str_contains($rendered, '│') === true,
         description: 'Emphasis inside cells styles without breaking the grid'
      );
   }
);
