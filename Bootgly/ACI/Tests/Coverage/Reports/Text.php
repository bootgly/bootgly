<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Coverage\Reports;


use const BOOTGLY_WORKING_DIR;
use const PHP_EOL;
use const STR_PAD_LEFT;
use function array_keys;
use function array_sum;
use function count;
use function explode;
use function file_get_contents;
use function is_file;
use function is_readable;
use function ksort;
use function ltrim;
use function max;
use function rtrim;
use function sprintf;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use Bootgly\ABI\Differ\Codes;
use Bootgly\ABI\Differ\Outputs\Only;
use Bootgly\ACI\Tests\Coverage\Report;


/**
 * Plain-text per-file coverage breakdown for the CLI.
 */
final class Text extends Report
{
   /**
    * Render coverage data as a plain-text summary.
    *
    * @param array<string, array<int, int>> $data
    */
   public function render (array $data): string
   {
      $out  = "Coverage report\n";
      $out .= "----------------------------------------\n";

      $cwd = rtrim(str_replace('\\', '/', BOOTGLY_WORKING_DIR), '/') . '/';
      $totalLines = 0;
      $totalHit = 0;

      foreach ($data as $file => $lines) {
         $lineCount = count($lines);
         $hit = array_sum($lines);
         $totalLines += $lineCount;
         $totalHit += $hit;

         $rel = str_starts_with($file, $cwd)
            ? ltrim(substr($file, strlen($cwd)), '/')
            : $file;

         $pct = $lineCount > 0 ? ($hit / $lineCount) * 100 : 0.0;
         $out .= sprintf("%3d/%-3d  %5.1f%%  %s\n", $hit, $lineCount, $pct, $rel);

         if ($this->diff) {
            $out .= $this->diff($file, $lines, $rel);
         }
      }

      $totalPct = $totalLines > 0 ? ($totalHit / $totalLines) * 100 : 0.0;
      $out .= "----------------------------------------\n";
      $out .= sprintf("TOTAL  %3d/%-3d  %5.1f%%\n", $totalHit, $totalLines, $totalPct);
      $out .= PHP_EOL;

      return $out;
   }

   /**
    * @param array<int, int> $lines
    */
   private function diff (string $file, array $lines, string $rel): string
   {
      $header = "--- uncovered: {$rel}\n+++ covered: {$rel}\n";

      if ($lines === []) {
         return $header . "# No executable lines recorded.\n\n";
      }

      if (! is_file($file) || ! is_readable($file)) {
         return $header . "# Source unavailable: {$file}\n\n";
      }

      $source = $this->source($file);
      ksort($lines);

      /** @var array<int, array{0: string, 1: int}> $diff */
      $diff = [];
      $width = strlen((string) max(1, ...array_keys($lines)));

      foreach ($lines as $line => $hits) {
         $line = (int) $line;
         $code = (int) $hits > 0 ? Codes::ADDED->value : Codes::REMOVED->value;
         $diff[] = [$this->line($line, $source[$line] ?? '', $width), $code];
      }

      $Output = new Only($header);

      return $Output->render($diff) . "\n";
   }

   /**
    * @return array<int, string>
    */
   private function source (string $file): array
   {
      $contents = file_get_contents($file);
      if ($contents === false) {
         return [];
      }

      $rows = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));
      $source = [];
      foreach ($rows as $index => $row) {
         $source[$index + 1] = $row;
      }

      return $source;
   }

   /**
    * Prefix a source line with its line number for diff rendering.
    */
   private function line (int $line, string $source, int $width): string
   {
      return str_pad((string) $line, $width, ' ', STR_PAD_LEFT)
         . ' | '
         . rtrim($source, "\r\n")
         . "\n";
   }
}
