<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function fopen;
use function str_contains;
use function str_starts_with;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render the expanded tree with connector guides',
   test: function () {
      // ! Tree with in-memory streams
      $make = static function (): Tree {
         $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         return new Tree($Input, $Output);
      };

      // ! 3-level fixture
      $Tree = $make();
      $Root = $Tree->add('Bootgly');
      $ABI = $Root->add('ABI');
      $ABI->add('Data');
      $ABI->add('IO');
      $ACI = $Root->add('ACI');
      $ACI->add('hidden');
      $ACI->collapse();
      $CLI = $Root->add('CLI');
      $CLI->add('Terminal');

      $frame = (string) $Tree->render(Tree::RETURN_OUTPUT);

      // @ Valid — structure
      yield assert(
         assertion: str_starts_with($frame, '▾ Bootgly'),
         description: 'The root renders first, without a prefix and without a blank line'
      );
      yield assert(
         assertion: str_contains($frame, '├─ ') === true && str_contains($frame, '▾ ABI') === true,
         description: 'Mid siblings connect with ├─'
      );
      yield assert(
         assertion: str_contains($frame, '│  ├─ ') === true && str_contains($frame, '· Data') === true,
         description: 'Nested rows under a mid sibling carry the │ stem'
      );
      yield assert(
         assertion: str_contains($frame, '│  └─ ') === true && str_contains($frame, '· IO') === true,
         description: 'The last nested child connects with └─ under the stem'
      );
      yield assert(
         assertion: str_contains($frame, '   └─ ') === true && str_contains($frame, '· Terminal') === true,
         description: 'Children of the last sibling carry a blank stem'
      );

      // @ Valid — collapsed branches
      yield assert(
         assertion: str_contains($frame, '▸ ACI') === true && str_contains($frame, 'hidden') === false,
         description: 'Collapsed branches show ▸ and hide their children'
      );

      // @ Valid — report format
      yield assert(
         assertion: str_contains($frame, '=>') === false,
         description: 'Static output carries no aim marker'
      );

      // @ Valid — prompt
      $Tree->prompt = 'Project';
      $prompted = (string) $Tree->render(Tree::RETURN_OUTPUT);

      yield assert(
         assertion: str_starts_with($prompted, "Project\n"),
         description: 'A non-empty prompt renders as the header line'
      );

      // @ Valid — plain indentation
      $Plain = $make();
      $PlainRoot = $Plain->add('root');
      $PlainRoot->add('child');
      $Plain->guides = false;

      $flat = (string) $Plain->render(Tree::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($flat, '├─') === false && str_contains($flat, '└─') === false,
         description: 'guides = false renders plain indentation without connectors'
      );

      // @ Valid — per-node glyph override
      $PlainRoot->glyph = '★';
      $starred = (string) $Plain->render(Tree::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($starred, '★ root') === true,
         description: 'A node glyph overrides the state glyphs'
      );
   }
);
