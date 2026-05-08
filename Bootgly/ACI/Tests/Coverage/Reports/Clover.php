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
use const ENT_XML1;
use function array_sum;
use function count;
use function htmlspecialchars;
use function time;

use Bootgly\ACI\Tests\Coverage\Report;


/**
 * Clover XML — minimal CI-friendly format consumed by Codecov, Coveralls, etc.
 */
final class Clover extends Report
{
   /**
    * Render coverage data as Clover XML.
    *
    * @param array<string, array<int, int>> $data
    */
   public function render (array $data): string
   {
      $totalLines = 0;
      $totalHit = 0;
      $files = '';

      foreach ($data as $file => $lines) {
         $totalLines += count($lines);
         $totalHit += array_sum($lines);

         $linesXml = '';
         foreach ($lines as $line => $hits) {
            $linesXml .= "      <line num=\"{$line}\" type=\"stmt\" count=\"{$hits}\"/>\n";
         }

         $files .= "    <file name=\""
            . htmlspecialchars($file, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "\">\n" . $linesXml . "    </file>\n";
      }

      $time = time();

      return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
         . "<coverage generated=\"{$time}\">\n"
         . "  <project timestamp=\"{$time}\">\n"
         . $files
         . "    <metrics statements=\"{$totalLines}\" coveredstatements=\"{$totalHit}\"/>\n"
         . "  </project>\n"
         . "</coverage>\n";
   }
}
