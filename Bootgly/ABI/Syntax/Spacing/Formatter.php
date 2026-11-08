<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Spacing;


use function krsort;
use function strlen;
use function substr_replace;

use Bootgly\ABI\Syntax\Analyzers\Result;


/**
 * Rewrites the gap before every reported parameter or argument list: one
 * space for a declaration, none for a call.
 */
class Formatter
{
   /**
    * Rewrite the spacing of a file based on its analysis result.
    *
    * Each issue carries the byte offset where its gap starts; the gap is the
    * whitespace from there to the `(`. A gap that is not whitespace up to a
    * `(` (a stale result, a comment) is left alone. Offsets are applied from
    * the end of the file backwards so an edit never moves the ones pending.
    *
    * @param Result $Result
    *
    * @return string The corrected source code
    */
   public function format (Result $Result): string
   {
      $source = $Result->source;

      // ! Highest offset first — the replacement each one takes
      /** @var array<int,string> */
      $edits = [];
      foreach ($Result->issues as $Issue) {
         if ($Issue->offset < 0) {
            continue;
         }
         if ($Issue->type === 'declaration_spacing') {
            $edits[$Issue->offset] = ' ';
         }
         else if ($Issue->type === 'call_spacing') {
            $edits[$Issue->offset] = '';
         }
      }
      krsort($edits);

      // @@
      $length = strlen($source);
      foreach ($edits as $offset => $replacement) {
         // @ The whitespace run up to the `(`
         $end = $offset;
         while ($end < $length && (
            $source[$end] === ' ' || $source[$end] === "\t"
            || $source[$end] === "\n" || $source[$end] === "\r"
         )) {
            $end++;
         }

         // ? It must end at the `(` — a stale result is left alone
         if ($end >= $length || $source[$end] !== '(') {
            continue;
         }

         $source = substr_replace($source, $replacement, $offset, $end - $offset);
      }

      // :
      return $source;
   }
}
