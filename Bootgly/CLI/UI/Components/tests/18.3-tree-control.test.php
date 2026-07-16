<?php

namespace Bootgly\CLI\UI\Components;


use const PHP_EOL;
use function assert;
use function fopen;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Tree\Node;


return new Specification(
   description: 'It should control aiming, folding and selection with keystrokes',
   test: function () {
      // ! Tree with in-memory streams
      $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Tree = new Tree($Input, $Output);
      $Root = $Tree->add('app');
      $Src = $Root->add('src');
      $File = $Src->add('a.php');
      $Root->add('README');
      $Src->collapse();
      // Visible rows: [0 app, 1 src, 2 README]

      // @ Aiming — clamped at the edges
      yield assert(
         assertion: $Tree->control("\e[A") === true && $Tree->aimed === 0,
         description: 'Up at the first row clamps (no wrap)'
      );

      $Tree->control("\e[B");

      yield assert(
         assertion: $Tree->aimed === 1,
         description: 'Down moves the aim to the next visible row'
      );

      $Tree->control("\e[B");
      $Tree->control("\e[B");

      yield assert(
         assertion: $Tree->aimed === 2,
         description: 'Down at the last row clamps (no wrap)'
      );

      // @ Expanding — → on a folded branch opens it, aim stays
      $Tree->control("\e[A"); // aim src (row 1)
      $Tree->control("\e[C");

      yield assert(
         assertion: $Src->expanded === true && $Tree->aimed === 1,
         description: '→ expands a folded branch and keeps the aim on it'
      );

      // @ Expanding — → on an open branch moves to the first child
      $Tree->control("\e[C");

      yield assert(
         assertion: $Tree->aimed === 2,
         description: '→ on an open branch aims its first child (pre-order next row)'
      );

      // @ Collapsing — ← on a leaf jumps to the parent
      $Tree->control("\e[D");

      yield assert(
         assertion: $Tree->aimed === 1,
         description: '← on a leaf aims the parent'
      );

      // @ Collapsing — ← on an open branch folds it
      $Tree->control("\e[D");

      yield assert(
         assertion: $Src->expanded === false && $Tree->aimed === 1,
         description: '← folds an open branch and keeps the aim on it'
      );

      // @ Collapsing — ← walks up to the root, then no-ops
      $Tree->control("\e[D");

      yield assert(
         assertion: $Tree->aimed === 0,
         description: '← on a folded branch aims the parent'
      );
      yield assert(
         assertion: $Tree->control("\e[D") === true && $Tree->aimed === 0,
         description: '← on an open root with the aim at row 0 keeps aiming after folding'
      );

      // @ Toggling
      $Root->expand();
      $Tree->control("\e[B"); // aim src
      $Tree->control(' ');

      yield assert(
         assertion: $Src->expanded === true,
         description: 'Space opens a folded branch'
      );

      $Tree->control(' ');

      yield assert(
         assertion: $Src->expanded === false,
         description: 'Space folds an open branch'
      );

      // @ Selecting — Enter confirms the aimed node ("\r" raw byte form)
      yield assert(
         assertion: $Tree->control("\r") === false && $Tree->selected === $Src,
         description: 'Enter (CR) confirms the aimed node and finishes'
      );

      // @ Selecting — PHP_EOL byte form
      $Tree->control("\e[B");
      $Tree->control("\e[B"); // aim README

      yield assert(
         assertion: $Tree->control(PHP_EOL) === false && $Tree->selected?->label === 'README',
         description: 'Enter (LF) confirms the aimed node and finishes'
      );

      // @ Selecting — unselectable nodes ignore Enter
      $Src->expand();
      $File->selectable = false;
      // The aim stays on row 2 — with src expanded that row is a.php

      yield assert(
         assertion: $Tree->control("\r") === true && $Tree->selected?->label === 'README',
         description: 'Enter on an unselectable node is ignored'
      );

      // @ Canceling — Esc finishes with no selection
      yield assert(
         assertion: $Tree->control("\e") === false && $Tree->selected === null,
         description: 'Esc cancels: selection is null and the interaction finishes'
      );

      // @ Unknown keys are ignored
      yield assert(
         assertion: $Tree->control('x') === true,
         description: 'Unmapped keys keep the loop running'
      );

      // @ Actions — Enter runs the node action instead of confirming
      $actions = 0;
      $Root->action = static function (Node $Node) use (&$actions): Node {
         $actions++;

         return $Node->toggle();
      };

      $Tree->control("\e[A");
      $Tree->control("\e[A"); // aim app (row 0)

      yield assert(
         assertion: $Tree->control("\r") === true && $actions === 1 && $Root->expanded === false,
         description: 'Enter runs the node action and keeps navigating when it does not return false'
      );
      yield assert(
         assertion: $Tree->selected === null,
         description: 'An action that keeps navigating does not confirm the node'
      );

      // @ Actions — returning false confirms and finishes
      $Root->action = static function (Node $Node) use (&$actions): bool {
         $actions++;

         return false;
      };

      yield assert(
         assertion: $Tree->control("\r") === false && $Tree->selected === $Root && $actions === 2,
         description: 'An action returning false confirms the node and finishes'
      );

      // @ Actions — the explicit false return confirms even unselectable nodes
      $Command = new Tree($Input, $Output);
      $CommandNode = $Command->add('cmd');
      $CommandNode->selectable = false;
      $CommandNode->action = static fn (Node $Node): bool => false;

      yield assert(
         assertion: $Command->control("\r") === false && $Command->selected === $CommandNode,
         description: "An action's false return confirms even on an unselectable node"
      );

      // @ Guards — → and Space no-op on leaves
      $Leafy = new Tree($Input, $Output);
      $Leafy->add('solo');

      yield assert(
         assertion: $Leafy->control("\e[C") === true && $Leafy->control(' ') === true
            && $Leafy->Nodes[0]->expanded === true,
         description: '→ and Space on a leaf are no-ops'
      );

      // @ Guards — Enter on an empty tree finishes with no selection
      $Bare = new Tree($Input, $Output);

      yield assert(
         assertion: $Bare->control("\r") === false && $Bare->selected === null,
         description: 'Enter on an empty tree finishes with no selection'
      );

      // @ Clamping — a stale aim recovers after external tree mutation
      $Wide = new Tree($Input, $Output);
      $WideRoot = $Wide->add('root');
      for ($index = 0; $index < 5; $index++) {
         $WideRoot->add("child {$index}");
      }
      $Wide->control("\e[B");
      $Wide->control("\e[B");
      $Wide->control("\e[B");
      $Wide->control("\e[B"); // aimed 4
      $WideRoot->collapse();  // external mutation — 1 visible row left

      yield assert(
         assertion: $Wide->control("\e[B") === true && $Wide->aimed === 0,
         description: 'A stale aim clamps back into the shrunken visible range'
      );
      yield assert(
         assertion: $Wide->control("\r") === false && $Wide->selected === $WideRoot,
         description: 'Enter after an external shrink confirms the clamped row'
      );
   }
);
