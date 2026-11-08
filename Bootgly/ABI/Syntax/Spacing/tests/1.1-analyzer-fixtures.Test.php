<?php

use Bootgly\ABI\Syntax\Spacing;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Spacing: the accepted fixture is clean; the rejected one reports every declaration and call gap, a commented gap without an offset',
   test: function () {
      $Spacing = new Spacing;

      // @ Accepted — declarations with one space, calls without, hooks, control structures, constructs
      $Result = $Spacing->analyze(__DIR__ . '/fixtures/accepted.php');
      $found = [];
      foreach ($Result->issues as $Issue) {
         $found[] = "{$Issue->line}:{$Issue->symbol}";
      }
      yield assert(
         assertion: $found === [],
         description: 'The accepted fixture must report nothing, got: ' . json_encode($found)
      );

      // @ Rejected — each gap, in source order
      $Result = $Spacing->analyze(__DIR__ . '/fixtures/rejected.php');
      $found = [];
      $offsets = [];
      foreach ($Result->issues as $Issue) {
         $found[] = [$Issue->line, $Issue->type, $Issue->symbol, $Issue->kind];
         $offsets[$Issue->symbol] = $Issue->offset;
      }
      $expected = [
         [11, 'declaration_spacing', 'main', 'declaration'],
         [13, 'declaration_spacing', 'function', 'declaration'],
         [14, 'declaration_spacing', 'fn', 'declaration'],
         [15, 'declaration_spacing', 'function', 'declaration'],
         [17, 'call_spacing', 'strlen', 'call'],
         [17, 'call_spacing', '$wide', 'call'],
         [17, 'call_spacing', 'count', 'call'],
         [19, 'call_spacing', 'intval', 'call'],
         [19, 'call_spacing', 'Holder', 'call'],
         [25, 'declaration_spacing', 'set', 'declaration'],
         [30, 'declaration_spacing', 'measure', 'declaration'],
         [30, 'call_spacing', 'isset', 'call'],
         [31, 'declaration_spacing', 'keep', 'declaration'],
      ];
      yield assert(
         assertion: $found === $expected,
         description: 'The rejected fixture must report exactly its gaps, got: ' . json_encode($found)
      );

      // @ A gap holding a comment is reported but never offered to the formatter
      yield assert(
         assertion: $offsets['keep'] === -1 && $offsets['main'] >= 0 && $offsets['strlen'] >= 0,
         description: 'A commented gap must carry offset -1, the others a byte offset, got: ' . json_encode($offsets)
      );
   }
);
