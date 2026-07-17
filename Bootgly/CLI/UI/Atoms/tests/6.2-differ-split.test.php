<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render side-by-side diffs with line numbers and columns',
   test: function () {
      $from = "line one\nline two\nline three\nline four\n";
      $to   = "line one\nline 2\nline three\nline four\nline five\n";

      // @ Plain split structure — columns, numbers, change rows
      $Output = new Output('php://memory');
      $Differ = new Differ($Output);
      $Differ->decoration = false;
      $Differ->split = true;
      $Differ->width = 76;
      $Differ->gutter = 3;
      $Differ->from = $from;
      $Differ->to = $to;

      $rendered = (string) $Differ->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, ' ■■ Original -> New') === true,
         description: 'The header labels both sides and marks removals and additions'
      );

      yield assert(
         assertion: str_contains($rendered, '║') === true
            && str_contains($rendered, '│') === true,
         description: 'Rows split into two line-numbered columns'
      );

      yield assert(
         assertion: str_contains($rendered, '- line two') === true
            && str_contains($rendered, '+ line 2') === true
            && str_contains($rendered, '  2 │') === true,
         description: 'Changed lines pair on the same row with their line numbers'
      );

      yield assert(
         assertion: str_contains($rendered, '//////') === true,
         description: 'Unpaired lines fill the missing cell with a slash block'
      );

      yield assert(
         assertion: str_contains($rendered, "\e") === false,
         description: 'decoration = false renders escape-free output'
      );

      // @ Decorated split — SGR present
      $Differ->decoration = true;

      $rendered = (string) $Differ->render(Component::RETURN_OUTPUT);

      // ? Intra-line highlight splits changed words into SGR segments —
      //   only assert on context content, never across painted boundaries
      yield assert(
         assertion: str_contains($rendered, "\e[") === true
            && str_contains($rendered, 'line one') === true,
         description: 'decoration = true paints the split view with escape sequences'
      );

      // @ Width fitting — long lines truncate inside their column
      $Differ = new Differ(new Output('php://memory'));
      $Differ->decoration = false;
      $Differ->split = true;
      $Differ->width = 44;
      $Differ->gutter = 3;
      $Differ->from = "this is a very long line that cannot fit\n";
      $Differ->to = "this is another very long line that cannot fit\n";

      $rendered = (string) $Differ->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '…') === true,
         description: 'Lines wider than the column truncate with an ellipsis'
      );
   }
);
