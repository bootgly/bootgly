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


use const T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;
use const T_ARRAY;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_EMPTY;
use const T_EVAL;
use const T_FINAL;
use const T_FN;
use const T_FUNCTION;
use const T_ISSET;
use const T_LIST;
use const T_MATCH;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAME_RELATIVE;
use const T_STRING;
use const T_UNSET;
use const T_VARIABLE;
use const T_WHITESPACE;
use function count;
use function file_get_contents;
use function in_array;
use function is_array;
use function strlen;
use function token_get_all;

use Bootgly\ABI\Syntax\Analyzers;
use Bootgly\ABI\Syntax\Analyzers\Issue;
use Bootgly\ABI\Syntax\Analyzers\Result;


/**
 * Reports the spacing before a parameter or argument list: a declaration
 * takes exactly one space between its name (or `function` / `fn` keyword)
 * and `(`; a call takes none.
 *
 * Declarations: named functions and methods, closures and arrow functions.
 * Calls: names, qualified names and variables called, `new X (`, and the
 * function-like constructs `isset`, `empty`, `unset`, `array`, `list` and
 * `eval`. Control structures (`if (`, `foreach (`, `match (`) are neither.
 *
 * Each issue carries the byte offset where the gap starts (the `(` itself
 * when there is none); a gap holding a comment is reported with offset -1
 * and never rewritten.
 */
class Analyzer extends Analyzers
{
   /**
    * Analyze a PHP file for spacing before parameter and argument lists.
    *
    * @param string $file Absolute path to the PHP file
    *
    * @return Result
    */
   public function analyze (string $file): Result
   {
      $source = file_get_contents($file);
      if ($source === false) {
         return new Result(file: $file, source: '', issues: []);
      }

      $tokens = token_get_all($source);
      $count = count($tokens);

      // ! Byte offset of every token
      /** @var array<int,int> */
      $offsets = [];
      $offset = 0;
      for ($i = 0; $i < $count; $i++) {
         $offsets[$i] = $offset;
         $token = $tokens[$i];
         $offset += strlen(is_array($token) ? $token[1] : $token);
      }

      /** @var array<int,Issue> */
      $issues = [];
      /** @var array<int,true> The names already judged as declarations */
      $declared = [];

      // @@
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];
         if (is_array($token) === false) {
            continue;
         }

         // # Declarations — `function name (`, `function (`, `function & (`, `fn (`
         if ($token[0] === T_FUNCTION || $token[0] === T_FN) {
            $at = $this->advance($tokens, $i, $count);
            $before = $i;
            $symbol = $token[0] === T_FN ? 'fn' : 'function';
            // ? By-reference return
            $next = $tokens[$at] ?? null;
            if ($next === '&' || (is_array($next) && $next[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)) {
               $before = $at;
               $at = $this->advance($tokens, $at, $count);
               $next = $tokens[$at] ?? null;
            }
            // ? A named declaration — the name is whatever stands before `(`
            if ($next !== '(' && $next !== null) {
               $paren = $this->advance($tokens, $at, $count);
               // ? `use function foo;` imports, it does not declare
               if (($tokens[$paren] ?? null) !== '(') {
                  continue;
               }
               $before = $at;
               $declared[$at] = true;
               $symbol = is_array($next) ? $next[1] : $next;
               $at = $paren;
            }
            if ($at < 0) {
               continue;
            }

            $Issue = $this->judge($tokens, $offsets, $before, $at, $symbol, 'declaration', true);
            if ($Issue !== null) {
               $issues[] = $Issue;
            }
            continue;
         }

         // # Calls — a callee directly followed by a gap and `(`
         if (isset($declared[$i]) || in_array($token[0], [
            T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_VARIABLE,
            T_ISSET, T_EMPTY, T_UNSET, T_ARRAY, T_LIST, T_EVAL,
         ], true) === false) {
            continue;
         }
         $gap = $tokens[$i + 1] ?? null;
         if ($gap === '(' && $token[0] === T_STRING && ($token[1] === 'set' || $token[1] === 'get')
            && $this->hook($tokens, $i, $i + 1, $count)
         ) {
            $Issue = $this->judge($tokens, $offsets, $i, $i + 1, $token[1], 'declaration', true);
            if ($Issue !== null) {
               $issues[] = $Issue;
            }
            continue;
         }
         if (is_array($gap) === false || ($gap[0] !== T_WHITESPACE && $gap[0] !== T_COMMENT && $gap[0] !== T_DOC_COMMENT)) {
            continue;
         }
         $at = $this->advance($tokens, $i, $count);
         if (($tokens[$at] ?? null) !== '(') {
            continue;
         }
         // ? A property hook with parameters (`set (string $value) {`) declares
         $hook = $token[0] === T_STRING && ($token[1] === 'set' || $token[1] === 'get')
            && $this->hook($tokens, $i, $at, $count);

         $Issue = $this->judge($tokens, $offsets, $i, $at, $token[1], $hook ? 'declaration' : 'call', $hook);
         if ($Issue !== null) {
            $issues[] = $Issue;
         }
      }

      return new Result(file: $file, source: $source, issues: $issues);
   }

   /**
    * Tell whether the `get` / `set` at `$name` declares a property hook: it
    * opens a hook list or follows another hook (`{`, `;`, `}`, an attribute's
    * `]`, `final`, a by-reference `&`), and the `)` of the `(` at `$paren` is
    * followed by the hook body (`{`) or an arrow (`=>`). A call is never both
    * — `$x->get(1) => …` in a match arm follows `->`, `[get(1) => …]` follows `[`.
    *
    * @param array<int,array{0:int,1:string,2:int}|string> $tokens
    */
   private function hook (array $tokens, int $name, int $paren, int $count): bool
   {
      // ? Where a hook may stand
      $previous = $tokens[$this->rewind($tokens, $name)] ?? null;
      if (is_array($previous)) {
         if ($previous[0] !== T_FINAL && $previous[0] !== T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
            return false;
         }
      }
      else if (in_array($previous, ['{', ';', '}', ']', '&'], true) === false) {
         return false;
      }
      // ? The `{` of a `match (…)` opens arms, not hooks — `match ($x) { get(1) => …`
      if ($previous === '{') {
         $close = $this->rewind($tokens, $this->rewind($tokens, $name));
         if (($tokens[$close] ?? null) === ')') {
            $level = 0;
            for ($j = $close; $j >= 0; $j--) {
               if ($tokens[$j] === ')') {
                  $level++;
               }
               else if ($tokens[$j] === '(') {
                  $level--;
                  if ($level === 0) {
                     $keyword = $tokens[$this->rewind($tokens, $j)] ?? null;
                     if (is_array($keyword) && $keyword[0] === T_MATCH) {
                        return false;
                     }
                     break;
                  }
               }
            }
         }
      }

      $depth = 0;
      for ($j = $paren; $j < $count; $j++) {
         if ($tokens[$j] === '(') {
            $depth++;
         }
         else if ($tokens[$j] === ')') {
            $depth--;
            if ($depth === 0) {
               $next = $tokens[$this->advance($tokens, $j, $count)] ?? null;

               return $next === '{' || (is_array($next) && $next[1] === '=>');
            }
         }
      }

      return false;
   }

   /**
    * Judge the gap between a name and its `(`.
    *
    * @param array<int,array{0:int,1:string,2:int}|string> $tokens
    * @param array<int,int> $offsets Byte offset of each token
    * @param int $before Index of the token before the gap
    * @param int $paren Index of the `(`
    * @param string $symbol What the issue is about
    * @param string $kind `declaration` or `call`
    * @param bool $spaced Whether the gap must be exactly one space (true) or empty (false)
    */
   private function judge (array $tokens, array $offsets, int $before, int $paren, string $symbol, string $kind, bool $spaced): null|Issue
   {
      // ! What lies between — whitespace only, or a comment
      $gap = '';
      $commented = false;
      for ($j = $before + 1; $j < $paren; $j++) {
         $token = $tokens[$j];
         if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            $commented = true;
         }
         $gap .= is_array($token) ? $token[1] : $token;
      }

      // ?: As it must be
      if ($commented === false && (($spaced && $gap === ' ') || ($spaced === false && $gap === ''))) {
         return null;
      }

      // ! The line of the `(` — the last array token before it carries one
      $line = 1;
      for ($j = $paren; $j >= 0; $j--) {
         if (is_array($tokens[$j])) {
            $line = $tokens[$j][2];
            break;
         }
      }
      $start = $offsets[$before + 1] ?? $offsets[$paren];

      // :
      return $spaced
         ? new Issue(
            type: 'declaration_spacing',
            symbol: $symbol,
            kind: $kind,
            line: $line,
            message: "Declaration spacing: {$symbol}(...) → one space before the parameter list",
            offset: $commented ? -1 : $start
         )
         : new Issue(
            type: 'call_spacing',
            symbol: $symbol,
            kind: $kind,
            line: $line,
            message: "Call spacing: {$symbol} (...) → no space before the argument list",
            offset: $commented ? -1 : $start
         );
   }
}
