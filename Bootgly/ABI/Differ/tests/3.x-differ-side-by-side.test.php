<?php

use Bootgly\ABI\Differ;
use Bootgly\ABI\Differ\Outputs\SideBySide;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Outputs\\SideBySide: two-column diff with line numbers',
   test: function (): Generator {
         $deletedLine    = "\033[38;2;204;102;102;48;2;58;48;48m";
         $deletedWord    = "\033[38;2;255;204;204;48;2;97;38;38m";
         $insertedLine   = "\033[38;2;102;204;102;48;2;48;58;48m";
         $insertedWord   = "\033[38;2;204;255;204;48;2;38;97;38m";
         $missingLine    = "\033[38;2;102;102;102m";
         $reset          = "\033[0m";
         $deletedBlock   = "\033[91m" . '■' . $reset;
         $insertedBlock  = "\033[92m" . '■' . $reset;
         $oldRed         = "\033[41m";
         $oldGreen       = "\033[42m";
         $oldBrightRed   = "\033[101m";
         $oldBrightGreen = "\033[102m";

         // @ Modified line — both columns aligned with different line numbers
      $Differ = new Differ(new SideBySide(width: 80));
      $output = $Differ->diff("a\nb\nc\n", "a\nB\nc\n");

      yield assert(
         assertion: str_contains($output, 'Original') && str_contains($output, 'New'),
         description: 'header has file labels'
      );

      yield assert(
         assertion: str_contains($output, 'b') && str_contains($output, 'B'),
         description: 'modified line shows both old and new content'
      );

      // @ Custom starting line numbers — used by git diff hunks
      $DifferOffset = new Differ(new SideBySide(width: 80, colored: false, fromStart: 20, toStart: 35));
      $outputOffset = $DifferOffset->diff(['same', 'old'], ['same', 'new']);

      yield assert(
         assertion: str_contains($outputOffset, '  20 │')
            && str_contains($outputOffset, '  35 │')
            && str_contains($outputOffset, '  21 │')
            && str_contains($outputOffset, '  36 │'),
         description: 'custom starting line numbers are used by side-by-side output'
      );

      // @ Pure add — left column blank
      $Differ2 = new Differ(new SideBySide(width: 80));
      $output2 = $Differ2->diff("a\n", "a\nnew\n");

      yield assert(
         assertion: str_contains($output2, 'new'),
         description: 'pure add appears on right column'
      );

      yield assert(
         assertion: str_contains($output2, '////'),
         description: 'pure add shows slash hatching on missing left column'
      );

      // @ Pure remove — right column blank
      $Differ3 = new Differ(new SideBySide(width: 80));
      $output3 = $Differ3->diff("a\nold\n", "a\n");

      yield assert(
         assertion: str_contains($output3, 'old'),
         description: 'pure remove appears on left column'
      );

      yield assert(
         assertion: str_contains($output3, '////'),
         description: 'pure remove shows slash hatching on missing right column'
      );

      // @ Same content inside a changed block — context row, no +/- prefixes
      $DifferSame = new Differ(new SideBySide(width: 100, colored: false));
      $outputSame = $DifferSame->diff(
         "Keep this line equal.\nOld line that should be removed.\nFinal shared line.",
         "Keep this line equal.\nNew line that should be added.\nFinal shared line.\nA new line."
      );

      yield assert(
         assertion: ! str_contains($outputSame, '- Final shared line.')
            && ! str_contains($outputSame, '+ Final shared line.')
            && substr_count($outputSame, 'Final shared line.') === 2,
         description: 'same content inside change block is rendered as context without +/- prefixes'
      );

      $outputShiftedSame = $DifferSame->diff(
         "Keep this line equal.\nOld line that should be removed.\nFinal shared line.",
         "Keep this line equal.\nNew line that should be added.\nInserted before shared.\nFinal shared line.\nA new line."
      );

      yield assert(
         assertion: ! str_contains($outputShiftedSame, '- Final shared line.')
            && ! str_contains($outputShiftedSame, '+ Final shared line.')
            && substr_count($outputShiftedSame, 'Final shared line.') === 2,
         description: 'shifted same content inside change block is aligned as context'
      );

      // @ Long line truncated with ellipsis
      $Differ4 = new Differ(new SideBySide(width: 60));
      $long    = str_repeat('x', 200) . "\n";
      $output4 = $Differ4->diff($long, "y\n");

      yield assert(
         assertion: str_contains($output4, '…'),
         description: 'long line truncated with ellipsis'
      );

      // @ Plain mode has no ANSI codes
      $Differ5 = new Differ(new SideBySide(width: 80, colored: false));
      $output5 = $Differ5->diff("a\n", "b\n");

      yield assert(
         assertion: ! str_contains($output5, "\033["),
         description: 'plain mode has no ANSI escapes'
      );

      // @ Colored mode has red and green
      $Differ6 = new Differ(new SideBySide(width: 80, colored: true));
      $output6 = $Differ6->diff("a\n", "b\n");

      yield assert(
         assertion: str_contains($output6, $deletedLine)
            && str_contains($output6, $insertedLine)
            && str_contains($output6, $deletedBlock . $insertedBlock)
            && str_contains($output6, '║')
            && ! str_contains($output6, $oldRed)
            && ! str_contains($output6, $oldGreen)
            && ! str_contains($output6, $oldBrightRed)
            && ! str_contains($output6, $oldBrightGreen),
         description: 'colored mode uses git-split-diffs dark theme RGB line colors'
      );

      // @ Colored pure add/delete headers use one marker block only
      $DifferHeader = new Differ(new SideBySide(width: 80, colored: true));
      $outputAddHeader = $DifferHeader->diff("a\n", "a\nnew\n");
      $outputDelHeader = $DifferHeader->diff("a\nold\n", "a\n");

      yield assert(
         assertion: str_contains($outputAddHeader, $insertedBlock)
            && ! str_contains($outputAddHeader, $deletedBlock)
            && str_contains($outputAddHeader, $missingLine . '////'),
         description: 'colored pure add header uses one green block and slash hatching'
      );

      yield assert(
         assertion: str_contains($outputDelHeader, $deletedBlock)
            && ! str_contains($outputDelHeader, $insertedBlock)
            && str_contains($outputDelHeader, $missingLine . '////'),
         description: 'colored pure delete header uses one red block and slash hatching'
      );

      // @ Colored modified line has base + whole-word intra-line backgrounds
      $Differ7 = new Differ(new SideBySide(width: 100, colored: true));
      $output7 = $Differ7->diff(
         "This file is the BEFORE side.\nOld line that should be removed.\n",
         "This file is the AFTER side.\nNew line that should be added.\n"
      );

      yield assert(
         assertion: str_contains($output7, $deletedLine)
            && str_contains($output7, $insertedLine)
            && str_contains($output7, $deletedWord . "BEFORE" . $deletedLine)
            && str_contains($output7, $insertedWord . "AFTER" . $insertedLine)
            && str_contains($output7, $deletedWord . "Old" . $deletedLine)
            && str_contains($output7, $insertedWord . "New" . $insertedLine)
            && str_contains($output7, $deletedWord . "removed" . $deletedLine)
            && str_contains($output7, $insertedWord . "added" . $insertedLine),
         description: 'colored modified line has base and whole-word intra-line ANSI background codes'
      );

      yield assert(
         assertion: ! str_contains($output7, $deletedWord . "B" . $deletedLine . "E")
            && ! str_contains($output7, $insertedWord . "AFT" . $insertedLine . "ER")
            && ! str_contains($output7, $deletedWord . "r" . $deletedLine . "e"),
         description: 'word replacements are not split into shared-letter highlight fragments'
      );

      // @ Intra-line highlighting can be disabled
      $Differ8 = new Differ(new SideBySide(width: 100, colored: true, intraLineHighlight: false));
      $output8 = $Differ8->diff("This file is the BEFORE side.\n", "This file is the AFTER side.\n");

      yield assert(
         assertion: ! str_contains($output8, $deletedWord) && ! str_contains($output8, $insertedWord),
         description: 'intra-line highlighting can be disabled'
      );

      // @ Mostly changed lines keep only line background, matching git-split-diffs noise threshold
      $DifferMostlyChanged = new Differ(new SideBySide(width: 100, colored: true));
      $outputMostlyChanged = $DifferMostlyChanged->diff("abcXdef\n", "abcYdef\n");

      yield assert(
         assertion: str_contains($outputMostlyChanged, $deletedLine)
            && str_contains($outputMostlyChanged, $insertedLine)
            && ! str_contains($outputMostlyChanged, $deletedWord)
            && ! str_contains($outputMostlyChanged, $insertedWord),
         description: 'mostly changed lines avoid noisy intra-line highlighting'
      );

      // @ Unicode words are highlighted as multibyte words
      $Differ9 = new Differ(new SideBySide(width: 100, colored: true));
      $output9 = $Differ9->diff("valor café final\n", "valor cafe final\n");

      yield assert(
         assertion: str_contains($output9, $deletedWord . "café" . $deletedLine)
            && str_contains($output9, $insertedWord . "cafe" . $insertedLine),
         description: 'unicode modified words are highlighted without byte splitting'
      );

      // @ Custom file labels
      $Differ10 = new Differ(new SideBySide(width: 80, fromFile: 'before.php', toFile: 'after.php'));
      $output10 = $Differ10->diff("a\n", "b\n");

      yield assert(
         assertion: str_contains($output10, 'before.php') && str_contains($output10, 'after.php'),
         description: 'custom file labels in header'
      );
   }
);
