<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ\Outputs;


use function array_unshift;
use function count;
use function max;
use function mb_str_pad;
use function mb_str_split;
use function mb_strimwidth;
use function mb_strlen;
use function mb_strwidth;
use function preg_match_all;
use function rtrim;
use function sprintf;
use function str_repeat;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Differ\Calculators\Memory;
use Bootgly\ABI\Differ\Calculators\Time;
use Bootgly\ABI\Differ\Codes;
use Bootgly\ABI\Differ\Output;


/**
 * Builds a side-by-side (two-column) diff with line numbers, common in
 * code review UIs. Removed lines on the left, added lines on the right.
 */
final class SideBySide implements Output
{
   use Formattable;


   private const int INLINE_DIFF_TOKEN_LIMIT = 2048;
   private const int INLINE_DIFF_TIME_LIMIT = 65536;


   // * Config
   public int $width;
   public int $gutter;
   public bool $colored;
   public string $fromFile;
   public string $toFile;
   public bool $intraLineHighlight;
   public int $fromStart;
   public int $toStart;


   public function __construct (
      int $width = 160,
      int $gutter = 4,
      bool $colored = false,
      string $fromFile = 'Original',
      string $toFile = 'New',
      bool $intraLineHighlight = true,
      int $fromStart = 1,
      int $toStart = 1
   ) {
      $this->width              = $width;
      $this->gutter             = $gutter;
      $this->colored            = $colored;
      $this->fromFile           = $fromFile;
      $this->toFile             = $toFile;
      $this->intraLineHighlight = $intraLineHighlight;
      $this->fromStart          = $fromStart;
      $this->toStart            = $toStart;
   }

   public function render (array $diff): string
   {
      $colWidth = (int) (($this->width - ($this->gutter * 2) - 9) / 2);

      if ($colWidth < 10) {
         $colWidth = 10;
      }

      $hasRemoved = false;
      $hasAdded   = false;

      foreach ($diff as $entry) {
         if ($entry[1] === Codes::REMOVED->value) {
            $hasRemoved = true;
         }
         else if ($entry[1] === Codes::ADDED->value) {
            $hasAdded = true;
         }
      }

      // @ Header row — single label, left-aligned, spans full width
      $buffer = '';
      $rule   = str_repeat('=', ($this->gutter * 2) + ($colWidth * 2) + 9);
      $label  = $this->fromFile === $this->toFile
         ? $this->fromFile
         : $this->fromFile . ' -> ' . $this->toFile;
      $marker = '';

      if ($hasRemoved) {
         $marker .= $this->colored
            ? self::wrap(self::_RED_BRIGHT_FOREGROUND) . '■' . self::_RESET_FORMAT
            : '■';
      }

      if ($hasAdded) {
         $marker .= $this->colored
            ? self::wrap(self::_GREEN_BRIGHT_FOREGROUND) . '■' . self::_RESET_FORMAT
            : '■';
      }

      $buffer .= ($this->colored ? self::wrap(self::_YELLOW_PALE_DIM) . $rule . self::_RESET_FORMAT : $rule) . "\n";

      if ($this->colored) {
         $buffer .= $marker === ''
            ? self::wrap(self::_YELLOW_SOFT_FOREGROUND) . ' ' . $label . self::_RESET_FORMAT . "\n"
            : ' ' . $marker . self::wrap(self::_YELLOW_SOFT_FOREGROUND) . ' ' . $label . self::_RESET_FORMAT . "\n";
      }
      else {
         $buffer .= ($marker === '' ? ' ' . $label : ' ' . $marker . ' ' . $label) . "\n";
      }

      $buffer .= ($this->colored ? self::wrap(self::_YELLOW_PALE_DIM) . $rule . self::_RESET_FORMAT : $rule) . "\n";

      if (count($diff) === 0) {
         return $buffer;
      }

      $diff = $this->normalize($diff);

      $oldNum         = $this->fromStart;
      $newNum         = $this->toStart;
      $pendingRemoved = [];
      $pendingAdded   = [];

      foreach ($diff as $entry) {
         [$content, $code] = $entry;
         $line = rtrim($content, "\n\r");

         if ($code === Codes::OLD->value) {
            $buffer        .= $this->flush($pendingRemoved, $pendingAdded, $colWidth);
            $pendingRemoved = [];
            $pendingAdded   = [];

            $buffer .= $this->row($oldNum, '  ' . $line, $newNum, '  ' . $line, $colWidth, context: true);
            $oldNum++;
            $newNum++;

            continue;
         }

         if ($code === Codes::REMOVED->value) {
            $pendingRemoved[] = [$oldNum, $line];
            $oldNum++;

            continue;
         }

         if ($code === Codes::ADDED->value) {
            $pendingAdded[] = [$newNum, $line];
            $newNum++;
         }

         // LINE_END_WARNING / LINE_END_EOF_WARNING ignored in side-by-side layout
      }

      $buffer .= $this->flush($pendingRemoved, $pendingAdded, $colWidth);

      return $buffer;
   }

   /**
    * Bubble REMOVED/ADDED entries leftward across same-content OLD entries
    * when sandwiched within a change block. Improves visual pairing for cases
    * where the LCS matched a trivial common line (often empty) instead of
    * pairing the deleted/added counterpart on the same row.
    *
    * @param array<int, array{0: string, 1: int}> $diff
    * @return array<int, array{0: string, 1: int}>
    */
   private function normalize (array $diff): array
   {
      $n = count($diff);

      do {
         $changed = false;

         for ($i = 1; $i < $n - 1; $i++) {
            $prev = $diff[$i - 1][1];
            $curr = $diff[$i][1];
            $next = $diff[$i + 1][1];

            if (
               $curr === Codes::OLD->value
               && $prev !== Codes::OLD->value
               && $next !== Codes::OLD->value
               && $diff[$i][0] === $diff[$i + 1][0]
            ) {
               [$diff[$i], $diff[$i + 1]] = [$diff[$i + 1], $diff[$i]];
               $changed = true;
            }
         }
      } while ($changed);

      return $diff;
   }

   /**
    * @param array<int, array{0: int, 1: string}> $removed
    * @param array<int, array{0: int, 1: string}> $added
    */
   private function flush (array $removed, array $added, int $colWidth): string
   {
      if ($removed === [] || $added === []) {
         return $this->pair($removed, $added, $colWidth);
      }

      $removedLines = [];
      $addedLines   = [];

      foreach ($removed as [, $line]) {
         $removedLines[] = $line;
      }

      foreach ($added as [, $line]) {
         $addedLines[] = $line;
      }

      $Calculator = count($removedLines) * count($addedLines) > self::INLINE_DIFF_TIME_LIMIT
         ? new Memory
         : new Time;
      $common = $Calculator->calculate($removedLines, $addedLines);

      if ($common === []) {
         return $this->pair($removed, $added, $colWidth);
      }

      $output       = '';
      $removedCount = count($removed);
      $addedCount   = count($added);
      $removedIndex = 0;
      $addedIndex   = 0;

      foreach ($common as $line) {
         $chunkRemoved = [];
         $chunkAdded   = [];

         while ($removedIndex < $removedCount && $removed[$removedIndex][1] !== $line) {
            $chunkRemoved[] = $removed[$removedIndex];
            $removedIndex++;
         }

         while ($addedIndex < $addedCount && $added[$addedIndex][1] !== $line) {
            $chunkAdded[] = $added[$addedIndex];
            $addedIndex++;
         }

         $output .= $this->pair($chunkRemoved, $chunkAdded, $colWidth);

         if ($removedIndex < $removedCount && $addedIndex < $addedCount) {
            $output .= $this->row(
               $removed[$removedIndex][0],
               '  ' . $line,
               $added[$addedIndex][0],
               '  ' . $line,
               $colWidth,
               context: true
            );

            $removedIndex++;
            $addedIndex++;
         }
      }

      $chunkRemoved = [];
      $chunkAdded   = [];

      while ($removedIndex < $removedCount) {
         $chunkRemoved[] = $removed[$removedIndex];
         $removedIndex++;
      }

      while ($addedIndex < $addedCount) {
         $chunkAdded[] = $added[$addedIndex];
         $addedIndex++;
      }

      return $output . $this->pair($chunkRemoved, $chunkAdded, $colWidth);
   }

   /**
    * @param array<int, array{0: int, 1: string}> $removed
    * @param array<int, array{0: int, 1: string}> $added
    */
   private function pair (array $removed, array $added, int $colWidth): string
   {
      $rows   = max(count($removed), count($added));
      $output = '';

      for ($i = 0; $i < $rows; $i++) {
         [$lNum, $lLine] = $removed[$i] ?? [null, ''];
         [$rNum, $rLine] = $added[$i]   ?? [null, ''];

         $lContent  = $lNum === null || $lLine === '' ? '' : '- ' . $lLine;
         $rContent  = $rNum === null || $rLine === '' ? '' : '+ ' . $rLine;
         $lSegments = null;
         $rSegments = null;

         if (
            $this->colored
            && $this->intraLineHighlight
            && $lNum !== null
            && $rNum !== null
            && ($lLine !== '' || $rLine !== '')
         ) {
            [$lSegments, $rSegments] = $this->segment($lLine, $rLine);
         }

         $output .= $this->row(
            $lNum,
            $lContent,
            $rNum,
            $rContent,
            $colWidth,
            oldSegments: $lSegments,
            newSegments: $rSegments
         );
      }

      return $output;
   }

   /**
    * @param array<int, array{0: string, 1: int}>|null $oldSegments
    * @param array<int, array{0: string, 1: int}>|null $newSegments
    */
   private function row (
      ?int $oldNum,
      string $oldContent,
      ?int $newNum,
      string $newContent,
      int $colWidth,
      bool $context = false,
      ?array $oldSegments = null,
      ?array $newSegments = null
   ): string {
      // @ Line numbers
      $lNum = $oldNum === null
         ? str_repeat(' ', $this->gutter)
         : sprintf('%' . $this->gutter . 'd', $oldNum);
      $rNum = $newNum === null
         ? str_repeat(' ', $this->gutter)
         : sprintf('%' . $this->gutter . 'd', $newNum);

      // @ Detect blank cells (no counterpart for this added/removed line)
      $lBlank = $oldNum === null && $oldContent === '';
      $rBlank = $newNum === null && $newContent === '';

      // @ Content (slash fill missing cells, otherwise truncate + pad)
      $lContent = $lBlank
         ? str_repeat('/', $colWidth)
         : $this->pad($oldContent, $colWidth);
      $rContent = $rBlank
         ? str_repeat('/', $colWidth)
         : $this->pad($newContent, $colWidth);

      // @ Coloring
      if ($this->colored) {
         if ($context) {
            $lNum = self::wrap(self::_BLACK_SOFT_FOREGROUND) . $lNum . self::_RESET_FORMAT;
            $rNum = self::wrap(self::_BLACK_SOFT_FOREGROUND) . $rNum . self::_RESET_FORMAT;
         }
         else {
            $lNum = $oldNum === null
               ? self::wrap(self::_BLACK_SOFT_FOREGROUND) . $lNum . self::_RESET_FORMAT
               : self::wrap(self::_RED_DIM) . $lNum . self::_RESET_FORMAT;
            $rNum = $newNum === null
               ? self::wrap(self::_BLACK_SOFT_FOREGROUND) . $rNum . self::_RESET_FORMAT
               : self::wrap(self::_GREEN_DIM) . $rNum . self::_RESET_FORMAT;
         }

         if (! $context) {
            if ($lBlank) {
               $lContent = self::wrap(self::_BLACK_SOFT_FOREGROUND) . $lContent . self::_RESET_FORMAT;
            }
            else if ($oldNum !== null) {
               $lContent = $this->paint(
                  $oldContent,
                  $colWidth,
                  self::_RED_SOFT,
                  self::_RED_PALE,
                  Codes::REMOVED->value,
                  $oldSegments
               );
            }

            if ($rBlank) {
               $rContent = self::wrap(self::_BLACK_SOFT_FOREGROUND) . $rContent . self::_RESET_FORMAT;
            }

            if ($newNum !== null) {
               $rContent = $this->paint(
                  $newContent,
                  $colWidth,
                  self::_GREEN_SOFT,
                  self::_GREEN_PALE,
                  Codes::ADDED->value,
                  $newSegments
               );
            }
         }
      }

      $sep = $this->colored
         ? self::wrap(self::_YELLOW_PALE_DIM) . '│' . self::_RESET_FORMAT
         : '│';
      $mid = $this->colored
         ? self::wrap(self::_YELLOW_PALE_DIM) . '║' . self::_RESET_FORMAT
         : '║';

      return sprintf("%s %s %s %s %s %s %s\n", $lNum, $sep, $lContent, $mid, $rNum, $sep, $rContent);
   }

   private function pad (string $content, int $colWidth): string
   {
      return mb_str_pad(mb_strimwidth($content, 0, $colWidth, '…'), $colWidth);
   }

   /**
    * @return array{
    *    0: array<int, array{0: string, 1: int}>|null,
    *    1: array<int, array{0: string, 1: int}>|null
    * }
    */
   private function segment (string $removed, string $added): array
   {
      if (
         mb_strlen($removed) > self::INLINE_DIFF_TOKEN_LIMIT
         || mb_strlen($added) > self::INLINE_DIFF_TOKEN_LIMIT
      ) {
         return [null, null];
      }

      $removedTokens = $this->tokenize($removed);
      $addedTokens   = $this->tokenize($added);
      $removedCount  = count($removedTokens);
      $addedCount    = count($addedTokens);

      $Calculator = $removedCount * $addedCount > self::INLINE_DIFF_TIME_LIMIT
         ? new Memory
         : new Time;

      $common          = $Calculator->calculate($removedTokens, $addedTokens);
      $removedSegments = [];
      $addedSegments   = [];
      $removedIndex    = 0;
      $addedIndex      = 0;
      $changedTokens   = 0;
      $sameTokens      = count($common);

      foreach ($common as $token) {
         while ($removedIndex < $removedCount && $removedTokens[$removedIndex] !== $token) {
            $this->push($removedSegments, $removedTokens[$removedIndex], Codes::REMOVED->value);
            $removedIndex++;
            $changedTokens++;
         }

         while ($addedIndex < $addedCount && $addedTokens[$addedIndex] !== $token) {
            $this->push($addedSegments, $addedTokens[$addedIndex], Codes::ADDED->value);
            $addedIndex++;
            $changedTokens++;
         }

         if ($removedIndex < $removedCount && $addedIndex < $addedCount) {
            $this->push($removedSegments, $token, Codes::OLD->value);
            $this->push($addedSegments, $token, Codes::OLD->value);
            $removedIndex++;
            $addedIndex++;
         }
      }

      while ($removedIndex < $removedCount) {
         $this->push($removedSegments, $removedTokens[$removedIndex], Codes::REMOVED->value);
         $removedIndex++;
         $changedTokens++;
      }

      while ($addedIndex < $addedCount) {
         $this->push($addedSegments, $addedTokens[$addedIndex], Codes::ADDED->value);
         $addedIndex++;
         $changedTokens++;
      }

      // Same heuristic as git-split-diffs: when the changed tokens outweigh
      // unchanged context, granular spans add noise instead of clarity.
      if ($changedTokens > $sameTokens) {
         return [null, null];
      }

      if ($removed !== '') {
         $this->prefix($removedSegments, '- ');
      }

      if ($added !== '') {
         $this->prefix($addedSegments, '+ ');
      }

      return [$removedSegments, $addedSegments];
   }

   /**
    * @return list<string>
    */
   private function tokenize (string $line): array
   {
      if ($line === '') {
         return [];
      }

      if (preg_match_all('/[\p{L}\p{N}_]+|[^\p{L}\p{N}_]+/u', $line, $matches) === false) {
         return mb_str_split($line);
      }

      return $matches[0];
   }

   /**
    * @param array<int, array{0: string, 1: int}>|null $segments
    */
   private function paint (
      string $content,
      int $colWidth,
      string $baseBackground,
      string $highlightBackground,
      int $highlightCode,
      ?array $segments
   ): string {
      $segments ??= [[$content, Codes::OLD->value]];

      [$segments, $width] = $this->fit($segments, $colWidth);

      if ($width < $colWidth) {
         $this->push($segments, str_repeat(' ', $colWidth - $width), Codes::OLD->value);
      }

      $output = self::wrap($baseBackground);

      foreach ($segments as [$segment, $code]) {
         if ($code === $highlightCode) {
            $output .= self::wrap($highlightBackground) . $segment . self::wrap($baseBackground);
         }
         else {
            $output .= $segment;
         }
      }

      return $output . self::_RESET_FORMAT;
   }

   /**
    * @param  array<int, array{0: string, 1: int}> $segments
    * @return array{0: array<int, array{0: string, 1: int}>, 1: int}
    */
   private function fit (array $segments, int $colWidth): array
   {
      $width          = 0;
      $visibleWidth   = $this->measure($segments);
      $truncated      = $visibleWidth > $colWidth;
      $ellipsis       = '…';
      $ellipsisWidth  = mb_strwidth($ellipsis);
      $contentWidth   = $truncated ? max(0, $colWidth - $ellipsisWidth) : $colWidth;
      $fittedSegments = [];

      foreach ($segments as [$segment, $code]) {
         foreach (mb_str_split($segment) as $char) {
            $charWidth = mb_strwidth($char);

            if ($width + $charWidth > $contentWidth) {
               break 2;
            }

            $this->push($fittedSegments, $char, $code);
            $width += $charWidth;
         }
      }

      if ($truncated) {
         $this->push($fittedSegments, $ellipsis, Codes::OLD->value);
         $width += $ellipsisWidth;
      }

      return [$fittedSegments, $width];
   }

   /**
    * @param array<int, array{0: string, 1: int}> $segments
    */
   private function measure (array $segments): int
   {
      $width = 0;

      foreach ($segments as [$segment]) {
         $width += mb_strwidth($segment);
      }

      return $width;
   }

   /**
    * @param array<int, array{0: string, 1: int}> $segments
    */
   private function prefix (array &$segments, string $prefix): void
   {
      if ($segments === []) {
         $segments[] = [$prefix, Codes::OLD->value];

         return;
      }

      if ($segments[0][1] === Codes::OLD->value) {
         $segments[0][0] = $prefix . $segments[0][0];

         return;
      }

      array_unshift($segments, [$prefix, Codes::OLD->value]);
   }

   /**
    * @param array<int, array{0: string, 1: int}> $segments
    */
   private function push (array &$segments, string $segment, int $code): void
   {
      if ($segment === '') {
         return;
      }

      $last = count($segments) - 1;

      if ($last >= 0 && $segments[$last][1] === $code) {
         $segments[$last][0] .= $segment;

         return;
      }

      $segments[] = [$segment, $code];
   }
}
