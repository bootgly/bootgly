<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Promotions;


use const T_FUNCTION;
use const T_PRIVATE;
use const T_PRIVATE_SET;
use const T_PROTECTED;
use const T_PROTECTED_SET;
use const T_PUBLIC;
use const T_PUBLIC_SET;
use const T_READONLY;
use const T_STRING;
use const T_VARIABLE;
use function count;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function strtolower;
use function token_get_all;

use Bootgly\ABI\Syntax\Analyzers;
use Bootgly\ABI\Syntax\Analyzers\Issue;
use Bootgly\ABI\Syntax\Analyzers\Result;


/**
 * Reports every promoted constructor property — a `__construct` parameter that
 * carries a visibility, `readonly` or asymmetric-set modifier. Bootgly declares
 * the property and assigns it in the constructor body.
 *
 * Only the modifiers at the parameter list's own depth count: a `(` inside a
 * default value opens a nested level the walk ignores. Check-only — the
 * property declaration a fix would have to write needs a docblock, a section
 * and a place in the class no rewrite can choose.
 */
class Analyzer extends Analyzers
{
   // * Metadata
   /** The modifiers that turn a constructor parameter into a property */
   private const array MODIFIERS = [
      T_PUBLIC, T_PROTECTED, T_PRIVATE,
      T_PUBLIC_SET, T_PROTECTED_SET, T_PRIVATE_SET,
      T_READONLY,
   ];


   /**
    * Analyze a PHP file for promoted constructor properties.
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

      /** @var array<int,Issue> */
      $issues = [];

      // @
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];
         if (is_array($token) === false || $token[0] !== T_FUNCTION) {
            continue;
         }

         // ? Only a constructor promotes
         $name = $this->advance($tokens, $i, $count);
         $named = $tokens[$name] ?? null;
         if (is_array($named) === false || $named[0] !== T_STRING
            || strtolower($named[1]) !== '__construct'
         ) {
            continue;
         }
         $open = $this->advance($tokens, $name, $count);
         if ($open === -1 || ($tokens[$open] ?? null) !== '(') {
            continue;
         }

         // @@ Walk the parameter list: a parameter is promoted when a modifier
         //    precedes its variable at depth 1
         $depth = 0;
         /** @var array<int,string> */
         $modifiers = [];
         for ($j = $open; $j < $count; $j++) {
            $current = $tokens[$j];

            if ($current === '(') {
               $depth++;
               continue;
            }
            if ($current === ')') {
               $depth--;
               if ($depth === 0) {
                  break;
               }
               continue;
            }
            if ($depth !== 1) {
               continue;
            }

            if ($current === ',') {
               $modifiers = [];
               continue;
            }
            if (is_array($current) === false) {
               continue;
            }

            if (in_array($current[0], self::MODIFIERS, true)) {
               $modifiers[] = $current[1];
               continue;
            }

            if ($current[0] === T_VARIABLE && $modifiers !== []) {
               $kind = implode(' ', $modifiers);
               /** @var string $variable */
               $variable = $current[1];
               /** @var int $line */
               $line = $current[2];

               $issues[] = new Issue(
                  type: 'promoted_property',
                  symbol: $variable,
                  kind: $kind,
                  line: $line,
                  message: "Promoted constructor property: {$kind} {$variable} → "
                     . 'declare the property and assign it in the constructor body'
               );

               $modifiers = [];
            }
         }

         // ! Resume after the list — its tokens hold no other constructor
         $i = $j;
      }

      return new Result(file: $file, source: $source, issues: $issues);
   }
}
