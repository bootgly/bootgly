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


use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use function array_sum;
use function count;
use function htmlspecialchars;
use function sprintf;

use Bootgly\ACI\Tests\Coverage\Report;


/**
 * Single-page HTML coverage summary.
 */
final class HTML extends Report
{
   /**
    * Render coverage data as a single HTML table.
    *
    * @param array<string, array<int, int>> $data
    */
   public function render (array $data): string
   {
      $rows = '';
      $totalLines = 0;
      $totalHit = 0;

      foreach ($data as $file => $lines) {
         $count = count($lines);
         $hit = array_sum($lines);
         $totalLines += $count;
         $totalHit += $hit;
         $pct = $count > 0 ? ($hit / $count) * 100 : 0.0;
         $rows .= sprintf(
            "<tr><td>%s</td><td>%d</td><td>%d</td><td>%.1f%%</td></tr>\n",
            htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $hit,
            $count,
            $pct
         );
      }

      $totalPct = $totalLines > 0 ? ($totalHit / $totalLines) * 100 : 0.0;

      return "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>Coverage</title>"
         . "<style>body{font-family:monospace}table{border-collapse:collapse}td,th{padding:4px 12px;border:1px solid #ccc}</style>"
         . "</head><body><h1>Coverage report</h1>"
         . "<table><thead><tr><th>File</th><th>Hit</th><th>Total</th><th>%</th></tr></thead><tbody>\n"
         . $rows
         . sprintf("<tr><th>TOTAL</th><th>%d</th><th>%d</th><th>%.1f%%</th></tr>", $totalHit, $totalLines, $totalPct)
         . "</tbody></table></body></html>";
   }
}
