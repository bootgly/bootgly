<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Imports;


use function array_filter;
use function count;
use function implode;
use function rsort;
use function str_contains;
use function strcasecmp;
use function strlen;
use function strpos;
use function substr;
use function substr_replace;
use function usort;

use Bootgly\ABI\Syntax\Imports\Analyzer\Result;


class Formatter
{
   /**
    * Format the import block of a file based on analysis result.
    *
    * @param Result $result
    *
    * @return string The corrected source code
    */
   public function format (Result $result): string
   {
      $source = $result->source;

      // @ Remove backslash prefixes from body (process in reverse offset order)
      $offsets = [];
      foreach ($result->issues as $Issue) {
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

      // @ Collect all imports (existing + missing from issues)
      $allImports = $result->imports;

      // @ Add missing imports from issues
      foreach ($result->issues as $Issue) {
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

      // @ Replace in source
      if ($result->importRange['start'] !== -1 && $result->importRange['end'] !== -1) {
         $start = $result->importRange['start'];
         $end = $result->importRange['end'];

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
         while ($start > 0 && $source[$start - 1] === "\n") {
            $start--;
         }
         // @ Keep exactly at the char after namespace line's \n
         if ($start > 0) {
            $start++;
         }

         // @ Walk forward past any trailing newlines after last import
         $sourceLen = strlen($source);
         while ($end < $sourceLen && $source[$end] === "\n") {
            $end++;
         }

         $before = substr($source, 0, $start);
         $after = substr($source, $end);

         // @ 2 blank lines after namespace, 2 blank lines after imports
         return $before . "\n\n" . $importBlock . "\n\n\n" . $after;
      }

      // @ No existing imports: insert after namespace declaration
      $nsPos = strpos($source, 'namespace ' . $result->namespace . ';');
      if ($nsPos !== false) {
         $semiPos = strpos($source, ';', $nsPos);
         $insertPos = $semiPos + 1;

         // @ Skip any newlines after the namespace;
         $sourceLen = strlen($source);
         while ($insertPos < $sourceLen && $source[$insertPos] === "\n") {
            $insertPos++;
         }

         $before = substr($source, 0, $insertPos);
         $after = substr($source, $insertPos);

         // @ 2 blank lines after namespace, 2 blank lines after imports
         return $before . "\n\n" . $importBlock . "\n\n\n" . $after;
      }

      return $source;
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
