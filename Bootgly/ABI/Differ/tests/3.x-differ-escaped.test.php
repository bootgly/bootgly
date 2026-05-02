<?php

use function array_filter;
use function explode;
use function str_contains;
use function str_starts_with;

use Bootgly\ABI\Differ;
use Bootgly\ABI\Differ\Outputs\Escaped;
use Bootgly\ABI\Differ\Outputs\Only;
use Bootgly\ABI\Differ\Outputs\Unified;
use Bootgly\ABI\Differ\Outputs\UnifiedStrict;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Outputs\\Escaped: ANSI color decorator',
   test: function (): Generator {
      // @ Added lines contain green escape and reset
      $Differ = new Differ(new Escaped(new Unified));
      $output = $Differ->diff("foo\n", "bar\n");

      yield assert(
         assertion: str_contains($output, "\033[32m") && str_contains($output, "\033[0m"),
         description: 'added line has green foreground and reset'
      );

      // @ Removed lines contain red escape
      yield assert(
         assertion: str_contains($output, "\033[31m"),
         description: 'removed line has red foreground'
      );

      // @ File headers contain bold
      yield assert(
         assertion: str_contains($output, "\033[1m"),
         description: 'header line has bold style'
      );

      // @ Hunk headers contain cyan (UnifiedStrict)
      $Differ2 = new Differ(new Escaped(new UnifiedStrict([
         'fromFile' => 'a.txt',
         'toFile'   => 'b.txt',
      ])));
      $output2 = $Differ2->diff("a\nb\n", "a\nB\n");

      yield assert(
         assertion: str_contains($output2, "\033[36m"),
         description: 'hunk header has cyan foreground'
      );

      // @ Only builder gets colored too
      $Differ3 = new Differ(new Escaped(new Only));
      $output3 = $Differ3->diff("x\n", "y\n");

      yield assert(
         assertion: str_contains($output3, "\033[32m") && str_contains($output3, "\033[31m"),
         description: 'Only output gets colored added/removed lines'
      );

      // @ Identical inputs produce empty output
      $Differ4 = new Differ(new Escaped(new UnifiedStrict([
         'fromFile' => 'a.txt',
         'toFile'   => 'b.txt',
      ])));
      $output4 = $Differ4->diff("same\n", "same\n");

      yield assert(
         assertion: $output4 === '',
         description: 'identical inputs produce empty output'
      );

      // @ Context lines have no ANSI escapes
      $Differ5 = new Differ(new Escaped(new Unified));
      $output5 = $Differ5->diff("a\nb\nc\n", "a\nB\nc\n");

      $lines = explode("\n", $output5);
      $contextLines = array_filter($lines, fn($l) => str_starts_with($l, ' '));
      $clean = true;
      foreach ($contextLines as $cl) {
         if (str_contains($cl, "\033[")) {
            $clean = false;
            break;
         }
      }

      yield assert(
         assertion: $clean,
         description: 'context lines have no ANSI escapes'
      );
   }
);
