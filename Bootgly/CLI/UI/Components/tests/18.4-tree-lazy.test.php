<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function count;
use function fopen;
use function str_contains;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Tree\Node;


return new Specification(
   description: 'It should resolve lazy children exactly once on the first expand',
   test: function () {
      // ! Tree with in-memory streams
      $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Tree = new Tree($Input, $Output);

      // ! Lazy node with an invocation counter
      $calls = 0;
      $Root = $Tree->add('deps');
      $Vendor = $Root->add('vendor', resolver: static function (Node $Node) use (&$calls): void {
         $calls++;

         $Node->add('bootgly');
         $Node->add('composer.json');
      });

      // @ Unresolved lazy nodes render folded
      $frame = (string) $Tree->render(Tree::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '▸ vendor') === true && str_contains($frame, 'bootgly') === false,
         description: 'An unresolved lazy node renders folded, children absent'
      );

      // @ First expand resolves
      $Vendor->expand();

      yield assert(
         assertion: $calls === 1 && count($Vendor->Nodes) === 2 && $Vendor->resolved === true,
         description: 'The first expand runs the resolver once and populates the children'
      );

      $resolved = (string) $Tree->render(Tree::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($resolved, '▾ vendor') === true && str_contains($resolved, '· bootgly') === true,
         description: 'Resolved children join the rendered tree in place'
      );

      // @ Repeated expands never re-run the resolver
      $Vendor->collapse();
      $Vendor->expand();
      $Vendor->toggle();
      $Vendor->toggle();

      yield assert(
         assertion: $calls === 1,
         description: 'Collapse/expand/toggle cycles never re-run the resolver'
      );

      // @ Empty resolution converts the node into a leaf
      $Empty = $Root->add('empty', resolver: static function (Node $Node): void {
         // resolves to nothing
      });

      yield assert(
         assertion: $Empty->leaf === false,
         description: 'Before resolution the lazy node is not a leaf'
      );

      $Empty->expand();

      yield assert(
         assertion: $Empty->leaf === true && $Empty->resolved === true,
         description: 'A resolver that adds nothing converts the node into a leaf'
      );

      $emptied = (string) $Tree->render(Tree::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($emptied, '· empty') === true,
         description: 'An emptily-resolved node renders with the leaf glyph'
      );

      // @ Throwing resolvers roll the latch back — the lazy load stays retryable
      $attempts = 0;
      $Flaky = $Root->add('flaky', resolver: static function (Node $Node) use (&$attempts): void {
         $attempts++;

         if ($attempts === 1) {
            throw new RuntimeException('scan failed');
         }

         $Node->add('recovered');
      });

      try {
         $Flaky->expand();
      }
      catch (RuntimeException) {
         // expected on the first attempt
      }

      yield assert(
         assertion: $Flaky->resolved === false && $Flaky->leaf === false,
         description: 'A throwing resolver rolls back: the node stays expandable'
      );

      $Flaky->expand();

      yield assert(
         assertion: $attempts === 2 && count($Flaky->Nodes) === 1 && $Flaky->resolved === true,
         description: 'Retrying the expand resolves the children'
      );

      // @ Mixed eager+lazy nodes stay folded until the resolver runs
      $Mixed = $Root->add('mixed', resolver: static function (Node $Node): void {
         $Node->add('lazy-child');
      });
      $Mixed->add('eager-child');

      $folded = (string) $Tree->render(Tree::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($folded, '▸ mixed') === true
            && str_contains($folded, 'eager-child') === false,
         description: 'A pending resolver keeps the branch folded, eager children hidden'
      );

      $Mixed->expand();
      $opened = (string) $Tree->render(Tree::RETURN_OUTPUT);

      yield assert(
         assertion: count($Mixed->Nodes) === 2 && str_contains($opened, 'eager-child') === true
            && str_contains($opened, 'lazy-child') === true,
         description: 'Expanding resolves and reveals eager and lazy children together'
      );
   }
);
