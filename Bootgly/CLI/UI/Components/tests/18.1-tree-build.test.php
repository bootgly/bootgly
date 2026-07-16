<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function count;
use function fopen;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Tree\Node;


return new Specification(
   description: 'It should build tree nodes with parent, depth and leaf wiring',
   test: function () {
      // ! Tree with in-memory streams
      $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Tree = new Tree($Input, $Output);

      // @ Roots
      $Root = $Tree->add('Bootgly', value: 'root-payload');

      yield assert(
         assertion: $Root instanceof Node && $Tree->Nodes[0] === $Root,
         description: 'add() returns the new root Node and appends it to the Tree'
      );
      yield assert(
         assertion: $Root->value === 'root-payload',
         description: 'The consumer payload rounds trip through the constructor'
      );
      yield assert(
         assertion: $Root->Parent === null && $Root->depth === 0,
         description: 'Roots have no Parent and depth 0'
      );
      yield assert(
         assertion: $Root->expanded === true,
         description: 'Nodes start expanded (zero-config full dump)'
      );
      yield assert(
         assertion: $Root->leaf === true,
         description: 'A node without children and without a resolver is a leaf'
      );

      // @ Children
      $Child = $Root->add('CLI');
      $Grandchild = $Child->add('Terminal', value: 42);

      yield assert(
         assertion: $Child->Parent === $Root && $Child->depth === 1,
         description: 'add() wires the child Parent and depth'
      );
      yield assert(
         assertion: $Grandchild->Parent === $Child && $Grandchild->depth === 2,
         description: 'Depth accumulates per level'
      );
      yield assert(
         assertion: $Root->leaf === false && count($Root->Nodes) === 1,
         description: 'A node with children is not a leaf'
      );
      yield assert(
         assertion: $Grandchild->value === 42,
         description: 'Child payloads round trip through add()'
      );

      // @ Lazy nodes
      $Lazy = $Root->add('vendor', resolver: static function (Node $Node): void {
         $Node->add('autoload.php');
      });

      yield assert(
         assertion: $Lazy->leaf === false && $Lazy->Nodes === [] && $Lazy->resolved === false,
         description: 'An unresolved lazy node is not a leaf even without children'
      );

      // @ Expand / collapse / toggle state
      $Child->collapse();

      yield assert(
         assertion: $Child->expanded === false,
         description: 'collapse() folds the node'
      );

      $Child->toggle();

      yield assert(
         assertion: $Child->expanded === true,
         description: 'toggle() opens a folded node'
      );
   }
);
