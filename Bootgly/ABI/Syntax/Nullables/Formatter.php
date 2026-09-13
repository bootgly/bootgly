<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Nullables;


use function rsort;
use function strlen;
use function substr_replace;

use Bootgly\ABI\Syntax\Analyzers\Result;


/**
 * Rewrites every reported `?T` as `null|T`.
 */
class Formatter
{
   /**
    * Rewrite the nullable shorthands of a file based on its analysis result.
    *
    * Each issue carries the byte offset of its `?`; the `?` and any whitespace
    * after it (`? int` is legal) become `null|`. Offsets are applied from the
    * end of the file backwards so an edit never moves the ones still pending.
    *
    * @param Result $Result
    *
    * @return string The corrected source code
    */
   public function format (Result $Result): string
   {
      $source = $Result->source;

      // ! Highest offset first
      $offsets = [];
      foreach ($Result->issues as $Issue) {
         if ($Issue->type === 'nullable_shorthand' && $Issue->offset >= 0) {
            $offsets[] = $Issue->offset;
         }
      }
      rsort($offsets);

      // @
      $length = strlen($source);
      foreach ($offsets as $offset) {
         // ? The offset must still point at a `?` — a stale result is left alone
         if ($offset >= $length || $source[$offset] !== '?') {
            continue;
         }

         // @ Consume the whitespace between the `?` and its type
         $end = $offset + 1;
         while ($end < $length && (
            $source[$end] === ' ' || $source[$end] === "\t"
            || $source[$end] === "\n" || $source[$end] === "\r"
         )) {
            $end++;
         }

         $source = substr_replace($source, 'null|', $offset, $end - $offset);
      }

      // :
      return $source;
   }
}
