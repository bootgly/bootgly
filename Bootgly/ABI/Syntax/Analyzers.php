<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax;


use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_WHITESPACE;
use function is_array;

use Bootgly\ABI\Syntax\Analyzers\Result;


/**
 * The contract every `bootgly lint` submodule analyzes through.
 *
 * An analyzer reads one PHP file and reports every style violation it is
 * responsible for as a `Result` — the file, its source and its issues. The
 * submodule facades (`Imports`, `Nullables`, `Promotions`, `Methods`) and the
 * analyzers behind them all extend it, so the lint command holds them by this
 * type alone.
 *
 * Every analyzer works on `token_get_all()` output — never on the raw source
 * with a regular expression — so a construct is recognized by what PHP parsed
 * it as, not by what it looks like.
 */
abstract class Analyzers
{
   /**
    * Analyze a PHP file for style violations.
    *
    * @param string $file Absolute path to the PHP file
    *
    * @return Result
    */
   abstract public function analyze (string $file): Result;

   /**
    * Index of the previous significant token — whitespace and comments skipped.
    *
    * @param array<int,mixed> $tokens `token_get_all()` output
    * @param int $i Current index
    *
    * @return int The index, or -1 when nothing significant precedes `$i`
    */
   protected function rewind (array $tokens, int $i): int
   {
      for ($j = $i - 1; $j >= 0; $j--) {
         if ($this->skip($tokens[$j]) === false) {
            return $j;
         }
      }

      return -1;
   }

   /**
    * Index of the next significant token — whitespace and comments skipped.
    *
    * @param array<int,mixed> $tokens `token_get_all()` output
    * @param int $i Current index
    * @param int $count Token count
    *
    * @return int The index, or -1 when nothing significant follows `$i`
    */
   protected function advance (array $tokens, int $i, int $count): int
   {
      for ($j = $i + 1; $j < $count; $j++) {
         if ($this->skip($tokens[$j]) === false) {
            return $j;
         }
      }

      return -1;
   }

   /**
    * Whether a token carries no syntax — whitespace or a comment.
    *
    * @param mixed $token
    */
   private function skip (mixed $token): bool
   {
      return is_array($token)
         && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT);
   }
}
