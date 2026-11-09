<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ;


use const PREG_UNMATCHED_AS_NULL;
use function array_pop;
use function count;
use function max;
use function preg_match;
use function preg_split;
use function str_starts_with;

use Bootgly\ABI\Differ\Diff\Chunk;
use Bootgly\ABI\Differ\Diff\Line;


/**
 * Unified-diff parser. Turns a unified-diff string into a list of `Diff` objects.
 */
final class Parser
{
   /**
    * @return list<Diff>
    */
   public function parse (string $string): array
   {
      $lines = preg_split('(\r\n|\r|\n)', $string);

      if ($lines === false) {
         return [];
      }

      if ($lines !== []
         && $lines[count($lines) - 1] === ''
      ) {
         array_pop($lines);
      }

      $count     = count($lines);
      $diffs     = [];
      $Diff      = null;
      $collected = [];

      for ($i = 0; $i < $count; $i++) {
         // ? A new git file section ends the still-open diff — extended headers
         //   after it must never feed the previous file's chunks
         if (str_starts_with($lines[$i], 'diff --git ')) {
            if ($Diff !== null) {
               $this->fill($Diff, $collected);

               $diffs[] = $Diff;
               $Diff    = null;
            }

            $collected = [];

            continue;
         }

         if (
            preg_match('#^---\h+"?(?P<file>[^\v\t"]+)#', $lines[$i], $fromMatch)
            && isset($lines[$i + 1])
            && preg_match('#^\+\+\+\h+"?(?P<file>[^\v\t"]+)#', $lines[$i + 1], $toMatch)
         ) {
            if ($Diff !== null) {
               $this->fill($Diff, $collected);

               $diffs[] = $Diff;
            }

            $Diff      = new Diff($fromMatch['file'], $toMatch['file']);
            $collected = [];

            $i++;
         }
         else {
            if (preg_match(
               '/^(?:index [\da-f.]+|[+-]{3} [ab]'
               . '|(?:old|new) mode |(?:new|deleted) file mode '
               . '|(?:copy|rename) (?:from|to) |(?:similarity|dissimilarity) index '
               . '|Binary files |GIT binary patch)/',
               $lines[$i]
            )) {
               continue;
            }

            $collected[] = $lines[$i];
         }
      }

      if ($Diff !== null && $collected !== []) {
         $this->fill($Diff, $collected);

         $diffs[] = $Diff;
      }

      return $diffs;
   }

   /**
    * @param array<int, string> $lines
    */
   private function fill (Diff $Diff, array $lines): void
   {
      $chunks    = [];
      $Chunk     = null;
      $diffLines = [];

      foreach ($lines as $line) {
         if (preg_match(
            '/^@@\s+-(?P<start>\d+)(?:,\s*(?P<startrange>\d+))?'
            . '\s+\+(?P<end>\d+)(?:,\s*(?P<endrange>\d+))?\s+@@/',
            $line,
            $match,
            PREG_UNMATCHED_AS_NULL
         )) {
            $Chunk = new Chunk(
               (int) $match['start'],
               isset($match['startrange']) ? max(0, (int) $match['startrange']) : 1,
               (int) $match['end'],
               isset($match['endrange']) ? max(0, (int) $match['endrange']) : 1,
            );

            $chunks[]  = $Chunk;
            $diffLines = [];

            continue;
         }

         if (preg_match('/^(?P<type>[+ -])?(?P<line>.*)/', $line, $match)) {
            $type = Line::UNCHANGED;

            if ($match['type'] === '+') {
               $type = Line::ADDED;
            }
            else if ($match['type'] === '-') {
               $type = Line::REMOVED;
            }

            $diffLines[] = new Line($type, $match['line']);

            $Chunk?->update($diffLines);
         }
      }

      $Diff->update($chunks);
   }
}
