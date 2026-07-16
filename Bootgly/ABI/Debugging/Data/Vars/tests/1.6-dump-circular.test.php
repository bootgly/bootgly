<?php

namespace Bootgly\ABI\Debugging\Data\Vars;


use function assert;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;


class DumpNode
{
   public null|DumpNode $next = null;
   public string $label = 'n';
}

return new Specification(
   description: 'It should mark ancestor cycles only — shared siblings expand normally',
   test: function () {
      $Dumper = new Dumper('plain');

      // @ Self-reference — true cycle marked
      $Node = new DumpNode;
      $Node->next = $Node;

      $expected = <<<'DUMP'
      Bootgly\ABI\Debugging\Data\Vars\DumpNode {
         +next: Bootgly\ABI\Debugging\Data\Vars\DumpNode *RECURSION*
         +label: 'n'
      }
      DUMP;
      yield assert(
         assertion: $Dumper->dump($Node) === $expected,
         description: 'A self-referencing object marks *RECURSION*'
      );

      // @ DAG — the same object under two siblings is NOT a cycle
      $Shared = new DumpNode;
      $dumped = $Dumper->dump([$Shared, $Shared]);

      yield assert(
         assertion: substr_count($dumped, '*RECURSION*') === 0
            && substr_count($dumped, "+label: 'n'") === 2,
         description: 'Shared siblings expand twice without a recursion mark'
      );
   }
);
