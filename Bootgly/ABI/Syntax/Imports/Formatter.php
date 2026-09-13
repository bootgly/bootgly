<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Imports;


use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_STRING;
use const T_WHITESPACE;
use function array_filter;
use function count;
use function implode;
use function in_array;
use function is_array;
use function rsort;
use function str_contains;
use function str_replace;
use function strcasecmp;
use function strlen;
use function strpos;
use function substr;
use function substr_replace;
use function token_get_all;
use function usort;

use Bootgly\ABI\Syntax\Imports\Analyzer\Result;


class Formatter
{
   // * Metadata
   /** The tokens between a keyword and the name it declares */
   private const array BLANKS = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

   /**
    * Format the import block of a file based on analysis result.
    *
    * @param Result $Result
    *
    * @return string The corrected source code
    */
   public function format (Result $Result): string
   {
      $source = $Result->source;

      // @ Remove backslash prefixes from body (process in reverse offset order)
      $offsets = [];
      foreach ($Result->issues as $Issue) {
         if ($Issue->type === 'backslash_prefix' && $Issue->offset >= 0) {
            $offsets[] = $Issue->offset;
         }
      }

      if (count($offsets) > 0) {
         rsort($offsets);
         foreach ($offsets as $offset) {
            $source = substr_replace($source, '', $offset, 1);
         }
      }

      // ! Drop what nothing in the body names — keyed by kind + symbol, so an
      //   alias that repeats across kinds never removes the wrong statement
      $unused = [];
      foreach ($Result->issues as $Issue) {
         if ($Issue->type === 'unused_import') {
            $unused[$Issue->kind . ':' . $Issue->symbol] = true;
         }
      }

      // @ Collect all imports (existing minus unused, plus missing from issues)
      $allImports = [];
      foreach ($Result->imports as $import) {
         if (isset($unused[$import['kind'] . ':' . $import['symbol']])) {
            continue;
         }

         $allImports[] = $import;
      }

      // @ Add missing imports from issues
      foreach ($Result->issues as $Issue) {
         if ($Issue->type !== 'missing_import') {
            continue;
         }

         $symbol = $Issue->symbol;
         $kind = $Issue->kind;

         // @ Build the full import symbol
         $importSymbol = $symbol;

         $allImports[] = [
            'symbol' => $importSymbol,
            'kind'   => $kind,
            'global' => !str_contains($importSymbol, '\\'),
            'line'   => 0,
            'alias'  => $symbol,
         ];
      }

      // @ Sort into 6 buckets
      $buckets = [
         'const_global'       => [],
         'const_namespaced'   => [],
         'function_global'    => [],
         'function_namespaced'=> [],
         'class_global'       => [],
         'class_namespaced'   => [],
      ];

      foreach ($allImports as $import) {
         $key = $import['kind'] . '_' . ($import['global'] ? 'global' : 'namespaced');
         $buckets[$key][] = $import;
      }

      // @ Sort each bucket alphabetically by symbol (case-insensitive)
      foreach ($buckets as &$bucket) {
         usort($bucket, function (array $a, array $b): int {
            return strcasecmp($a['symbol'], $b['symbol']);
         });

         // @ Remove duplicates
         $seen = [];
         $bucket = array_filter($bucket, function (array $import) use (&$seen): bool {
            $key = $import['kind'] . ':' . $import['symbol'];
            if (isset($seen[$key])) {
               return false;
            }
            $seen[$key] = true;
            return true;
         });
      }
      unset($bucket);

      // @ Generate import block: globals together, then blank line, then namespaced together
      $globalLines = [];
      $namespacedLines = [];

      // @ Global group: const → function → class (no blank lines between types)
      foreach (['const_global', 'function_global', 'class_global'] as $key) {
         foreach ($buckets[$key] as $import) {
            $globalLines[] = $this->render($import);
         }
      }

      // @ Namespaced group: const → function → class (no blank lines between types)
      foreach (['const_namespaced', 'function_namespaced', 'class_namespaced'] as $key) {
         foreach ($buckets[$key] as $import) {
            $namespacedLines[] = $this->render($import);
         }
      }

      // @ Build import block with 1 blank line between global and namespaced
      $sections = [];
      if (count($globalLines) > 0) {
         $sections[] = implode("\n", $globalLines);
      }
      if (count($namespacedLines) > 0) {
         $sections[] = implode("\n", $namespacedLines);
      }
      $importBlock = implode("\n\n", $sections);
      // ! The line ending in force where the lines are added — the first
      //   break at or after the block, or after the namespace declaration
      $from = $Result->importRange['start'] !== -1 ? $Result->importRange['start'] : ($this->locate($source) ?? 0);
      $break = strpos($source, "\n", $from);
      $eol = $break !== false && $break > 0 && $source[$break - 1] === "\r" ? "\r\n" : "\n";
      if ($eol !== "\n") {
         $importBlock = str_replace("\n", $eol, $importBlock);
      }

      // ? The block carries something a rewrite cannot place — leave it to a human
      //   (the backslash fixes above are outside it and still stand)
      foreach ($Result->issues as $Issue) {
         if ($Issue->type === 'comment_in_imports') {
            return $source;
         }
      }

      // @ Replace in source
      if ($Result->importRange['start'] !== -1 && $Result->importRange['end'] !== -1) {
         $start = $Result->importRange['start'];
         $end = $Result->importRange['end'];

         // @ Adjust offsets for removed backslash chars before import range
         foreach ($offsets as $offset) {
            if ($offset < $start) {
               $start--;
               $end--;
            }
            else if ($offset < $end) {
               $end--;
            }
         }

         // @ Walk back to start of line
         while ($start > 0 && $source[$start - 1] !== "\n") {
            $start--;
         }

         // @ Walk back further to consume blank lines (to reach namespace line end)
         //   — in the file's own line ending, `\r\n` included
         while ($start > 0 && ($source[$start - 1] === "\n" || $source[$start - 1] === "\r")) {
            $start--;
         }
         // @ Keep exactly at the char after namespace line's ending
         if ($start > 0) {
            $start += strlen($eol);
         }

         // @ Walk forward past any trailing line breaks after last import
         $sourceLen = strlen($source);
         while ($end < $sourceLen && ($source[$end] === "\n" || $source[$end] === "\r")) {
            $end++;
         }

         $before = substr($source, 0, $start);
         $after = substr($source, $end);

         // ?: Every import was unused — the file now carries none, so it takes
         //    the shape of one that never had any: 2 blank lines, then the code
         if ($importBlock === '') {
            return $before . $eol . $eol . $after;
         }

         // @ 2 blank lines after namespace, 2 blank lines after imports
         return $before . $eol . $eol . $importBlock . $eol . $eol . $eol . $after;
      }

      // ? Nothing to insert — never rewrite the namespace spacing for an empty
      //   block (reachable when the only issues are outside the import block)
      if ($importBlock === '') {
         return $source;
      }

      // @ No existing imports: insert after the namespace declaration — found
      //   by token, so a space before the `;` or a comment inside it is no obstacle
      $semiPos = $this->locate($source);
      if ($semiPos !== null) {
         $insertPos = $semiPos + 1;

         // @ Skip any line breaks after the namespace — in the file's own ending
         $sourceLen = strlen($source);
         while ($insertPos < $sourceLen && ($source[$insertPos] === "\n" || $source[$insertPos] === "\r")) {
            $insertPos++;
         }

         $before = substr($source, 0, $semiPos + 1);
         $after = substr($source, $insertPos);

         // @ 2 blank lines after namespace, 2 blank lines after imports
         return $before . $eol . $eol . $eol . $importBlock . $eol . $eol . $eol . $after;
      }

      return $source;
   }

   /**
    * Locate the `;` that ends the file's namespace declaration.
    *
    * The keyword is also a legal argument label (`f(namespace: 'x')`) and a
    * relative-name prefix, so only a `namespace` followed by a name (or `{`)
    * counts — and the braced form has no `;` to insert after.
    *
    * @param string $source The PHP source
    *
    * @return null|int The byte offset of the `;`, null when there is none
    */
   private function locate (string $source): null|int
   {
      $tokens = token_get_all($source);
      $count = count($tokens);
      $offset = 0;

      // @
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];
         $at = $offset;
         $offset += strlen(is_array($token) ? $token[1] : $token);

         if (is_array($token) === false || $token[0] !== T_NAMESPACE) {
            continue;
         }

         // ? A declaration names a namespace — a label is followed by `:`
         $j = $i + 1;
         while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], self::BLANKS, true)) {
            $j++;
         }
         $named = $tokens[$j] ?? null;
         if (is_array($named) === false || in_array($named[0], [T_STRING, T_NAME_QUALIFIED], true) === false) {
            continue;
         }

         // @ The statement ends at its `;` — or opens a block, which is not insertable
         $end = $at;
         for ($k = $i; $k < $count; $k++) {
            $piece = $tokens[$k];
            if ($piece === ';') {
               return $end;
            }
            if ($piece === '{') {
               return null;
            }
            $end += strlen(is_array($piece) ? $piece[1] : $piece);
         }

         return null;
      }

      // :
      return null;
   }

   /**
    * Render a single import line.
    *
    * @param array{symbol:string,kind:string,global:bool,line:int,alias:string} $import
    *
    * @return string
    */
   private function render (array $import): string
   {
      $line = 'use ';
      if ($import['kind'] === 'const') {
         $line .= 'const ';
      }
      else if ($import['kind'] === 'function') {
         $line .= 'function ';
      }
      $line .= $import['symbol'] . ';';
      return $line;
   }
}
