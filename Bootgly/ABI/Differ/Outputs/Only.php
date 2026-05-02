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


use function assert;
use function fclose;
use function fopen;
use function fwrite;
use function is_resource;
use function str_ends_with;
use function stream_get_contents;
use function substr;

use Bootgly\ABI\Differ\Codes;
use Bootgly\ABI\Differ\Output;


/**
 * Builds a loose unified-diff representation that contains
 * only changed lines (no hunk headers, no line numbers).
 */
final class Only implements Output
{
   // * Config
   public string $header;


   public function __construct (string $header = "--- Original\n+++ New\n")
   {
      $this->header = $header;
   }

   public function render (array $diff): string
   {
      $buffer = fopen('php://memory', 'r+b');

      assert(is_resource($buffer));

      if ($this->header !== '') {
         fwrite($buffer, $this->header);

         if (! str_ends_with($this->header, "\n")) {
            fwrite($buffer, "\n");
         }
      }

      foreach ($diff as $entry) {
         if ($entry[1] === Codes::ADDED->value) {
            fwrite($buffer, '+' . $entry[0]);
         }
         else if ($entry[1] === Codes::REMOVED->value) {
            fwrite($buffer, '-' . $entry[0]);
         }
         else if ($entry[1] === Codes::LINE_END_WARNING->value) {
            fwrite($buffer, ' ' . $entry[0]);
            // Warnings keep their own line break — do not re-test.
            continue;
         }
         else {
            // Unchanged lines are not rendered in this builder.
            continue;
         }

         $lc = substr($entry[0], -1);

         if ($lc !== "\n" && $lc !== "\r") {
            fwrite($buffer, "\n");
         }
      }

      $output = stream_get_contents($buffer, -1, 0);
      fclose($buffer);

      return $output;
   }
}
