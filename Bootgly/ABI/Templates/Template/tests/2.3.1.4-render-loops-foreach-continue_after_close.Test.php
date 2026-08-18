<?php

use Bootgly\ABI\Templates\Iterators;
use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should render loops: @continue after a @foreach has closed',
   test: function () {
      // ! A render that fatals is reported as its message, so the assertion below
      //   shows what went wrong instead of aborting the whole suite (TPL-2)
      $render = function (string $source, array $parameters = []): string {
         try {
            $Template = new Template($source);
            $Template->render($parameters);

            return $Template->output;
         }
         catch (Throwable $Throwable) {
            return $Throwable->getMessage();
         }
      };

      // @ Valid
      // The metavar left by a closed @foreach must not reach the `$_?->next();`
      // that @continue emits — dequeue() used to hand it a class-name string
      $output = $render(
         <<<'TEMPLATE'
         @foreach ($items as $item):
            @>. $item;
         @foreach;
         @for ($k = 0; $k < 2; $k++):
            @continue 1;
         @for;
         done
         TEMPLATE,
         ['items' => ['a']]
      );
      yield assert(
         assertion: $output === "a\ndone",
         description: "Template #1: @continue in a @for after a @foreach: \n`{$output}`"
      );

      // The same through @while, with the bare @continue;
      $output = $render(
         <<<'TEMPLATE'
         @foreach ($items as $item):
            @>. $item;
         @foreach;
         @: $k = 0; @;
         @while ($k < 2):
            @: $k++; @;
            @continue;
         @while;
         done
         TEMPLATE,
         ['items' => ['a']]
      );
      yield assert(
         assertion: str_contains($output, 'a') && str_contains($output, 'done'),
         description: "Template #2: @continue in a @while after a @foreach: \n`{$output}`"
      );

      // The conditional variant, `@continue <level> in <condition>;`
      $output = $render(
         <<<'TEMPLATE'
         @foreach ($items as $item):
            @>. $item;
         @foreach;
         @for ($k = 0; $k < 2; $k++):
            @continue 1 in $k === 0;
            @>. $k;
         @for;
         done
         TEMPLATE,
         ['items' => ['a']]
      );
      yield assert(
         assertion: $output === "a\n1\ndone",
         description: "Template #3: @continue <n> in <condition>; after a @foreach: \n`{$output}`"
      );

      // A @break; leaves the loop through the same closer, so it dequeues too
      $output = $render(
         <<<'TEMPLATE'
         @foreach ($items as $item):
            @break;
         @foreach;
         @for ($k = 0; $k < 2; $k++):
            @continue;
         @for;
         done
         TEMPLATE,
         ['items' => ['a', 'b']]
      );
      yield assert(
         assertion: $output === 'done',
         description: "Template #4: @continue after a @break-ed @foreach: \n`{$output}`"
      );

      // @ Neutral
      // The invariant itself: the stack hands the metavar back to the enclosing
      // loop, and null once no loop is left
      $outer = ['a'];
      $inner = ['b'];

      $Outer = Iterators::queue($outer);
      Iterators::queue($inner);

      $dequeued = Iterators::dequeue();
      yield assert(
         assertion: $dequeued === $Outer,
         description: 'Iterators: closing the inner loop must restore the outer Iterator, got: '
            . get_debug_type($dequeued)
      );

      $dequeued = Iterators::dequeue();
      yield assert(
         assertion: $dequeued === null,
         description: 'Iterators: closing the last loop must return null, got: '
            . get_debug_type($dequeued)
      );

      Iterators::reset();

      // Nested loops keep working through the compiled closer
      $output = $render(
         <<<'TEMPLATE'
         @foreach ($outer as $o):
            @foreach ($inner as $i):
               @>. $i;
            @foreach;
            @>. $@->index;
         @foreach;
         TEMPLATE,
         ['outer' => ['x', 'y'], 'inner' => ['1']]
      );
      yield assert(
         assertion: $output === "1\n0\n1\n1\n",
         description: "Template #5: nested @foreach with the metavar: \n`{$output}`"
      );

      // @ Invalid
      // ...
   }
);
