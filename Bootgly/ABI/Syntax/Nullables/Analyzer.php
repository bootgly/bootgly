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


use const T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG;
use const T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;
use const T_ARRAY;
use const T_CALLABLE;
use const T_CONST;
use const T_DOUBLE_ARROW;
use const T_ELLIPSIS;
use const T_FINAL;
use const T_FN;
use const T_FUNCTION;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAME_RELATIVE;
use const T_PRIVATE;
use const T_PRIVATE_SET;
use const T_PROTECTED;
use const T_PROTECTED_SET;
use const T_PUBLIC;
use const T_PUBLIC_SET;
use const T_READONLY;
use const T_STATIC;
use const T_STRING;
use const T_VAR;
use const T_VARIABLE;
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
 * Reports every nullable shorthand — `?T` — in a parameter, return or property
 * type. Bootgly writes `null|T`.
 *
 * A `?` is a shorthand only when PHP parsed it as one: the token before it opens
 * a type position (`(`, `,`, `:`, a modifier, or the `]` closing a parameter
 * attribute), the token after it is a type, and the token after the type closes
 * the declaration (the variable of a parameter or property; the body, `;` or `=>`
 * of a return). Ternaries (`$a ? $b : $c`, `$a ?: $b`), null coalescing (`??`)
 * and nullsafe access (`?->`) never satisfy all three, so they are never reported.
 */
class Analyzer extends Analyzers
{
   // * Metadata
   /** Tokens that open a property type — or a promoted parameter's */
   private const array MODIFIERS = [
      T_PUBLIC, T_PROTECTED, T_PRIVATE,
      T_PUBLIC_SET, T_PROTECTED_SET, T_PRIVATE_SET,
      T_STATIC, T_READONLY, T_VAR, T_FINAL,
      T_CONST,
   ];
   /** Tokens a type may be spelled with after the `?` */
   private const array TYPES = [
      T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE,
      T_ARRAY, T_CALLABLE, T_STATIC,
   ];


   /**
    * Analyze a PHP file for nullable shorthands.
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

      // ! A promoted parameter shares its modifiers with a property, so the
      //   signature ranges tell the two apart
      $signature = $this->map($tokens, $count);

      /** @var array<int,Issue> */
      $issues = [];

      // ! Offsets are tracked inline so the walk stays linear
      $offset = 0;

      // @
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];
         $at = $offset;
         $offset += strlen(is_array($token) ? $token[1] : $token);

         if ($token !== '?') {
            continue;
         }

         // ? The `?` must be followed by a single type
         $type = $this->advance($tokens, $i, $count);
         $typed = $tokens[$type] ?? null;
         if ($type === -1 || is_array($typed) === false
            || in_array($typed[0], self::TYPES, true) === false
         ) {
            continue;
         }

         // ? ...and preceded by something that opens a type position
         $previous = $tokens[$this->rewind($tokens, $i)] ?? null;
         $modifier = is_array($previous) && in_array($previous[0], self::MODIFIERS, true);
         $constant = is_array($previous) && $previous[0] === T_CONST;
         $return = $previous === ':';
         if ($modifier === false && $return === false
            && $previous !== '(' && $previous !== ',' && $previous !== ']'
         ) {
            continue;
         }

         // ? ...and the type must close the declaration the way its position does:
         //   `instanceof static ? $a : $b` passes the two checks above and fails here
         $next = $tokens[$this->advance($tokens, $type, $count)] ?? null;
         if ($return) {
            $closes = $next === '{' || $next === ';'
               || (is_array($next) && $next[0] === T_DOUBLE_ARROW);
         }
         else if ($constant) {
            // ? A typed class constant: `const ?int NAME = …`
            $closes = is_array($next) && $next[0] === T_STRING;
         }
         else {
            $closes = is_array($next) && (
               $next[0] === T_VARIABLE
               || $next[0] === T_ELLIPSIS
               || $next[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
            );
         }
         if ($closes === false) {
            continue;
         }

         $kind = match (true) {
            $return               => 'return',
            $constant             => 'constant',
            isset($signature[$i]) => 'parameter',
            $modifier             => 'property',
            default               => 'parameter',
         };
         /** @var string $name */
         $name = $typed[1];
         /** @var int $line */
         $line = $typed[2];

         $issues[] = new Issue(
            type: 'nullable_shorthand',
            symbol: $name,
            kind: $kind,
            line: $line,
            message: "Nullable shorthand in {$kind} type: ?{$name} → use null|{$name}",
            offset: $at
         );
      }

      return new Result(file: $file, source: $source, issues: $issues);
   }

   /**
    * Mark every token index inside a function signature's parentheses.
    *
    * @param array<int,mixed> $tokens
    * @param int $count
    *
    * @return array<int,true>
    */
   private function map (array $tokens, int $count): array
   {
      $signature = [];

      // @@
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];
         if (is_array($token) === false || ($token[0] !== T_FUNCTION && $token[0] !== T_FN)) {
            continue;
         }

         // @ The `(` comes after an optional by-ref `&` and an optional name
         $open = $this->advance($tokens, $i, $count);
         while ($open !== -1 && $tokens[$open] !== '(') {
            $head = $tokens[$open];
            if (is_array($head) === false || (
               $head[0] !== T_STRING && $head[0] !== T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG
            )) {
               $open = -1;
               break;
            }
            $open = $this->advance($tokens, $open, $count);
         }
         if ($open === -1) {
            continue;
         }

         // @ ...and closes at its matching `)`
         $depth = 0;
         for ($j = $open; $j < $count; $j++) {
            if ($tokens[$j] === '(') {
               $depth++;
            }
            else if ($tokens[$j] === ')') {
               $depth--;
               if ($depth === 0) {
                  break;
               }
            }
            $signature[$j] = true;
         }
      }

      return $signature;
   }
}
