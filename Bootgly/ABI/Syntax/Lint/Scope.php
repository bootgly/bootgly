<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Lint;


use const T_CLASS;
use const T_COMMENT;
use const T_CURLY_OPEN;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_ENUM;
use const T_INTERFACE;
use const T_STRING;
use const T_TRAIT;
use const T_WHITESPACE;
use function array_pop;
use function count;
use function is_array;
use function substr_count;
use function token_get_all;


/**
 * Which named class-like declaration encloses each line of a PHP source —
 * the `Class` an exemption symbol `Class::name` is matched against.
 *
 * Classes, interfaces, traits and enums count; an anonymous class does not
 * (an issue inside it belongs to the named class around it). Built from
 * `token_get_all()`, never from the raw text.
 */
class Scope
{
   // * Metadata
   /** @var array<int,array{int,int,string}> Per declaration: first line, last line, short name */
   public private(set) array $ranges = [];


   /**
    * @param string $source The PHP source
    */
   public function __construct (string $source)
   {
      $tokens = token_get_all($source);
      $count = count($tokens);

      // ! The declaration waiting for its body, and the open braces:
      //   each frame is the range index it closes, or -1
      $pending = null;
      /** @var array<int,int> */
      $frames = [];
      $line = 1;

      // @@
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];

         if (is_array($token)) {
            $line = $token[2];
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
               $frames[] = -1;
            }
            else if ($token[0] === T_CLASS || $token[0] === T_INTERFACE || $token[0] === T_TRAIT || $token[0] === T_ENUM) {
               // ? Only a declaration names the next significant token
               $next = $this->advance($tokens, $i, $count);
               $named = $tokens[$next] ?? null;
               if (is_array($named) && $named[0] === T_STRING && $this->declare($tokens, $i)) {
                  $pending = [$line, $named[1]];
               }
            }
            $line += substr_count($token[1], "\n");
            continue;
         }

         if ($token === '{') {
            if ($pending !== null) {
               $this->ranges[] = [$pending[0], $pending[0], $pending[1]];
               $frames[] = count($this->ranges) - 1;
               $pending = null;
            }
            else {
               $frames[] = -1;
            }
         }
         else if ($token === '}') {
            $index = array_pop($frames);
            if ($index !== null && $index >= 0) {
               $this->ranges[$index][1] = $line;
            }
         }
         else if ($token === ';') {
            // ? A declaration never meets a `;` before its body — whatever was
            //   pending is not one, and must not claim the next brace
            $pending = null;
         }
      }
   }

   /**
    * Find the short name of the innermost named class-like declaration that
    * encloses a line.
    *
    * @param int $line The 1-based source line
    *
    * @return string The short class name, or '' outside every declaration
    */
   public function find (int $line): string
   {
      $found = '';
      $width = -1;

      // @@ The narrowest range wins — a nested declaration over its host
      foreach ($this->ranges as [$first, $last, $name]) {
         if ($line >= $first && $line <= $last && ($width === -1 || $last - $first < $width)) {
            $found = $name;
            $width = $last - $first;
         }
      }

      return $found;
   }

   /**
    * Tell whether the class-like keyword at `$i` declares — not `Foo::class`,
    * an argument label or a method named after the keyword.
    *
    * @param array<int,mixed> $tokens
    */
   private function declare (array $tokens, int $i): bool
   {
      for ($j = $i - 1; $j >= 0; $j--) {
         $token = $tokens[$j];
         if (is_array($token) && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            continue;
         }

         // :
         return $token !== '::' && (is_array($token) === false || $token[1] !== '::')
            && (is_array($token) === false || $token[1] !== 'function')
            && $token !== '->';
      }

      return true;
   }

   /**
    * Index of the next significant token.
    *
    * @param array<int,mixed> $tokens
    */
   private function advance (array $tokens, int $i, int $count): int
   {
      for ($j = $i + 1; $j < $count; $j++) {
         $token = $tokens[$j];
         if (is_array($token) && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            continue;
         }

         return $j;
      }

      return -1;
   }
}
