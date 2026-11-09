<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Sections;


use const T_CLASS;
use const T_COMMENT;
use const T_CURLY_OPEN;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_ENUM;
use const T_FUNCTION;
use const T_INTERFACE;
use const T_OBJECT_OPERATOR;
use const T_PAAMAYIM_NEKUDOTAYIM;
use const T_PRIVATE;
use const T_PRIVATE_SET;
use const T_PROTECTED;
use const T_PROTECTED_SET;
use const T_PUBLIC;
use const T_READONLY;
use const T_STRING;
use const T_TRAIT;
use const T_VAR;
use const T_VARIABLE;
use function array_pop;
use function count;
use function file_get_contents;
use function in_array;
use function is_array;
use function preg_match;
use function substr;
use function token_get_all;

use Bootgly\ABI\Syntax\Analyzers;
use Bootgly\ABI\Syntax\Analyzers\Issue;
use Bootgly\ABI\Syntax\Analyzers\Result;


/**
 * Reports how a class groups its properties under the `// * Config`,
 * `// * Data` and `// * Metadata` headers:
 *
 * - the headers come in that order, each once (`section_order`);
 * - every property sits under one of them (`unsectioned_property`);
 * - a Config property is publicly readable — read visibility is decisive,
 *   a hidden input is Data (`config_hidden`);
 * - a Metadata property is never written from outside: not publicly
 *   writable unless it is a virtual `get` hook (`metadata_writable`).
 *
 * Classes, anonymous classes and traits are read; interfaces and enums
 * hold no sectioned properties. Only headers directly in the class body
 * count — a constructor's `// * Config` before its assignments does not.
 * Check-only.
 */
class Analyzer extends Analyzers
{
   // * Metadata
   /** The sections, in order */
   private const array SECTIONS = ['Config' => 0, 'Data' => 1, 'Metadata' => 2];


   /**
    * Analyze a PHP file for property sections.
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

      // ! Every `{` pushes a frame: a class-like body with its section state, or nothing
      /** @var array<int,null|array{section:null|string,rank:int,parens:int}> */
      $frames = [];
      /** @var array<int,bool> Class-like headers waiting for their body, by paren depth: whether it is read */
      $pendings = [];
      $parens = 0;

      // @@
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];

         // # Braces and parentheses
         if ($token === '{') {
            $frames[] = isset($pendings[$parens]) && $pendings[$parens]
               ? ['section' => null, 'rank' => -1, 'parens' => $parens]
               : null;
            unset($pendings[$parens]);
            continue;
         }
         if ($token === '}') {
            array_pop($frames);
            continue;
         }
         if ($token === '(') {
            $parens++;
            continue;
         }
         if ($token === ')') {
            $parens--;
            continue;
         }
         if (is_array($token) === false) {
            continue;
         }
         if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
            $frames[] = null;
            continue;
         }

         // # Class-like declarations — classes and traits are read, interfaces and enums are not
         if ($token[0] === T_CLASS || $token[0] === T_TRAIT || $token[0] === T_INTERFACE || $token[0] === T_ENUM) {
            $previous = $tokens[$this->rewind($tokens, $i)] ?? null;
            $following = $tokens[$this->advance($tokens, $i, $count)] ?? null;
            if ($following === ':'
               || (is_array($previous) && ($previous[0] === T_PAAMAYIM_NEKUDOTAYIM || $previous[0] === T_FUNCTION || $previous[0] === T_OBJECT_OPERATOR))
            ) {
               continue;
            }
            $pendings[$parens] = $token[0] === T_CLASS || $token[0] === T_TRAIT;
            continue;
         }

         // ? Only the class body itself — not a method, not a parameter list
         $top = count($frames) - 1;
         $frame = $frames[$top] ?? null;
         if ($frame === null || $parens !== $frame['parens']) {
            continue;
         }

         // # Section headers
         if ($token[0] === T_COMMENT && preg_match('#^//\s*\*\s*(Config|Data|Metadata)\b#', $token[1], $match) === 1) {
            $section = $match[1];
            $rank = self::SECTIONS[$section];
            if ($rank <= $frame['rank']) {
               $issues[] = new Issue(
                  type: 'section_order',
                  symbol: $section,
                  kind: 'section',
                  line: $token[2],
                  message: $rank === $frame['rank']
                     ? "Section repeated: // * {$section} → group its properties under one header"
                     : "Section out of order: // * {$section} after // * {$frame['section']} → Config, Data, Metadata"
               );
            }
            // ! The header names the section that follows; the order goes on
            //   from the highest rank met
            $frames[$top] = [
               'section' => $section,
               'rank'    => $rank > $frame['rank'] ? $rank : $frame['rank'],
               'parens'  => $frame['parens'],
            ];
            continue;
         }

         // # Properties — a variable directly in the class body
         if ($token[0] !== T_VARIABLE) {
            continue;
         }

         $variable = $token[1];
         $line = $token[2];
         $section = $frame['section'];

         if ($section === null) {
            $issues[] = new Issue(
               type: 'unsectioned_property',
               symbol: $variable,
               kind: 'property',
               line: $line,
               message: "Property outside a section: {$variable} → group it under // * Config, // * Data or // * Metadata"
            );
            continue;
         }

         [$readable, $writable] = $this->expose($tokens, $i, $count, $variable);

         if ($section === 'Config' && $readable === false) {
            $issues[] = new Issue(
               type: 'config_hidden',
               symbol: $variable,
               kind: 'property',
               line: $line,
               message: "Config property is not publicly readable: {$variable} → move it to // * Data"
            );
         }
         else if ($section === 'Metadata' && $writable) {
            $issues[] = new Issue(
               type: 'metadata_writable',
               symbol: $variable,
               kind: 'property',
               line: $line,
               message: "Metadata property is publicly writable: {$variable} → restrict its writes (private(set), protected(set), readonly) or move it"
            );
         }
      }

      return new Result(file: $file, source: $source, issues: $issues);
   }

   /**
    * Tell how the property at `$i` is exposed: whether it is publicly
    * readable, and whether it is publicly writable.
    *
    * @param array<int,array{0:int,1:string,2:int}|string> $tokens
    *
    * @param string $variable The property, `$`-prefixed
    *
    * @return array{bool,bool}
    */
   private function expose (array $tokens, int $i, int $count, string $variable): array
   {
      // ! The modifiers, walking back to the end of the previous member
      $visibility = null;
      $restricted = false;
      for ($j = $i - 1; $j >= 0; $j--) {
         $token = $tokens[$j];
         if ($token === ';' || $token === '{' || $token === '}') {
            break;
         }
         if (is_array($token) === false) {
            continue;
         }
         if (in_array($token[0], [T_PUBLIC, T_VAR], true)) {
            $visibility ??= 'public';
         }
         else if ($token[0] === T_PROTECTED || $token[0] === T_PRIVATE) {
            $visibility ??= 'hidden';
         }
         else if ($token[0] === T_PROTECTED_SET || $token[0] === T_PRIVATE_SET || $token[0] === T_READONLY) {
            $restricted = true;
         }
      }
      // ? No visibility keyword (`readonly int $x`) is public
      $readable = $visibility !== 'hidden';

      // ! Hooks — `{ get => ... }` right after the name (or its default)
      $writable = $readable && $restricted === false;
      $at = $this->advance($tokens, $i, $count);
      if ($writable && ($tokens[$at] ?? null) === '{') {
         // @@ Read the hook block: a `set` hook writes; a `get` hook that never
         //   touches its own backing value makes the property virtual
         $name = substr($variable, 1);
         $depth = 0;
         $set = false;
         $backed = false;
         for ($j = $at; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token === '{') {
               $depth++;
            }
            else if ($token === '}') {
               $depth--;
               if ($depth === 0) {
                  break;
               }
            }
            else if (is_array($token) && $token[0] === T_STRING) {
               if ($depth === 1 && $token[1] === 'set') {
                  $set = true;
               }
               // ? Only `$this->name` reaches the backing value — `$this->Other->name`
               //   reads another object's property
               if ($token[1] === $name) {
                  $arrow = $this->rewind($tokens, $j);
                  $owner = $tokens[$this->rewind($tokens, $arrow)] ?? null;
                  if (is_array($tokens[$arrow] ?? null) && $tokens[$arrow][0] === T_OBJECT_OPERATOR
                     && is_array($owner) && $owner[0] === T_VARIABLE && $owner[1] === '$this') {
                     $backed = true;
                  }
               }
            }
         }
         $writable = $set || $backed;
      }

      // :
      return [$readable, $writable];
   }
}
