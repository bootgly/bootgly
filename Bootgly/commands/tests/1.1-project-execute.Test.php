<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const PHP_BINARY;
use function array_search;
use function array_slice;
use function assert;
use function explode;
use function file_put_contents;
use function in_array;
use function is_file;
use function json_encode;
use function rewind;
use function rmdir;
use function str_contains;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function substr;
use function substr_count;
use function unlink;
use ReflectionMethod;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\ACI\VCS\Git;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Output\Region;


/**
 * `projects` runs git through `ACI\VCS\Git` and writes every line the engine
 * hands over with `stream()`, nested in the current Output — inside a Wizard
 * step, the step's Region.
 *
 * A PHP child stands in for git, run by the real engine into the real
 * `stream()`: two lines on stdout, one on stderr, then a hostile one on stdout
 * — a concealing SGR, an erase-display sequence and Output markup — and exit
 * status 3. The Output is swapped for a Region the way the Wizard swaps it
 * while a step handler runs: the status comes back, stderr joins stdout in
 * order, every break re-enters after the region's guide, and the hostile line
 * arrives as text — the engine escapes its control sequences (`$output` keeps
 * them raw) and `write()` leaves its markup inert.
 */

return new Test(
   description: '`projects` streams git\'s output inside the current Output region: the exit status comes back, stderr joins stdout in order, every row re-enters the guide and control sequences show as text',
   test: function () {
      // ! A child that prints four lines and exits with a known status — a PHP
      //   script keeps the case portable (no shell builtins involved). The
      //   last line is hostile: raw, its SGR would conceal the rows that
      //   follow and its erase would wipe the Wizard's frame
      $directory = Temporaries::reserve('project-execute');
      $script = "{$directory}/child.php";
      // ! The hostile line as the region must show it — the controls escaped visibly
      $shown = '\u001b[8m\u001b[2J@#red:fourth@;';

      // ! The wizard region: a guide every row re-enters after
      $gutter = '@#Black:│@;';
      $Host = new Output('php://memory');
      $Region = new Region($Host->stream, $gutter, 3);

      try {
         file_put_contents($script, <<<'PHP'
         <?php
         echo "first\nsecond\n";
         fwrite(STDERR, "third\n");
         echo "\e[8m\e[2J@#red:fourth@;\n";
         exit(3);

         PHP);

         // ! The real engine, the PHP binary in git's place, and the real
         //   `stream()` — bound before the swap below
         $Git = new Git($directory, PHP_BINARY);
         $Method = new ReflectionMethod(ProjectsCommand::class, 'stream');
         $Stream = $Method->getClosure(new ProjectsCommand);

         // ! `stream()` resolves the Output per line (the Wizard swaps this
         //   very property while a step handler runs)
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Region;

         try {
            // @
            $status = $Git->execute([$script], $Stream);
         }
         finally {
            $Terminal->Output = $Restore;
         }
      }
      finally {
         if (is_file($script) === true) {
            unlink($script);
         }
         rmdir($directory);
      }

      rewind($Host->stream);
      $painted = (string) stream_get_contents($Host->stream);
      // ! The rows as the region shows them — the first sits on the host's
      //   row, every later one behind the guide
      $rows = [];
      foreach (explode("\n", $painted) as $index => $row) {
         $rows[] = $index > 0 && str_starts_with($row, $gutter) === true ? substr($row, strlen($gutter)) : $row;
      }
      $first = array_search('first', $rows, true);
      $breaks = substr_count($painted, "\n");
      // ! What was painted, for a failing description — JSON-escaped, so no
      //   byte of it drives the terminal that shows the report
      $excerpt = json_encode($painted, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

      yield assert(
         assertion: $status === 3,
         description: "The child exit status comes back to the caller — got {$status}"
      );

      yield assert(
         assertion: substr_count($painted, 'first') === 1
            && substr_count($painted, 'second') === 1
            && substr_count($painted, 'third') === 1
            && $first !== false
            && array_slice($rows, $first, 3) === ['first', 'second', 'third'],
         description: "Both child streams reach the Output, one row per line and in order — stderr joins stdout: {$excerpt}"
      );

      yield assert(
         assertion: $breaks >= 4 && substr_count($painted, "\n{$gutter}") === $breaks,
         description: "Every child line re-enters the region after its guide instead of escaping the frame ({$breaks} breaks): {$excerpt}"
      );

      yield assert(
         assertion: str_contains($Git->output, "\e[8m\e[2J") === true
            && in_array($shown, $rows, true) === true
            && str_contains($painted, "\e") === false,
         description: "The control sequences the child printed raw (`\$output` keeps them) reach the region as visible escapes (`\\u001b`) with the markup inert — nothing raw: {$excerpt}"
      );
   }
);
