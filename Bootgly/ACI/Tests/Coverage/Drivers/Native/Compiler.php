<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Coverage\Drivers\Native;


use const T_ABSTRACT;
use const T_AS;
use const T_ATTRIBUTE;
use const T_CASE;
use const T_CATCH;
use const T_CLASS;
use const T_CLOSE_TAG;
use const T_COMMENT;
use const T_CONST;
use const T_CURLY_OPEN;
use const T_DECLARE;
use const T_DEFAULT;
use const T_DIR;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_DOUBLE_COLON;
use const T_ELSE;
use const T_ELSEIF;
use const T_ENUM;
use const T_EXTENDS;
use const T_FILE;
use const T_FINAL;
use const T_FINALLY;
use const T_FN;
use const T_FUNCTION;
use const T_IMPLEMENTS;
use const T_INLINE_HTML;
use const T_INTERFACE;
use const T_MATCH;
use const T_NAMESPACE;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;
use const T_OPEN_TAG;
use const T_OPEN_TAG_WITH_ECHO;
use const T_PRIVATE;
use const T_PROTECTED;
use const T_PUBLIC;
use const T_READONLY;
use const T_STATIC;
use const T_TRAIT;
use const T_USE;
use const T_VAR;
use const T_WHILE;
use const T_WHITESPACE;
use function array_pop;
use function dirname;
use function end;
use function is_array;
use function ksort;
use function token_get_all;
use function var_export;

use Bootgly\ACI\Tests\Coverage\Drivers\Native;


/**
 * Token-based source rewriter.
 *
 * Given a PHP source string and its canonical file path, produces an
 * equivalent source where:
 *
 *   - Each executable statement is preceded by a call to
 *     `\Bootgly\ACI\Tests\Coverage::hit('file', line)`.
 *   - Magic constants `__FILE__` and `__DIR__` are replaced with the
 *     original path/dir literals so php://filter loading is transparent.
 *
 * Block context is tracked via a stack so injection only happens
 * inside actual executable contexts (function bodies and the file
 * top level), never inside class bodies, property hooks, match
 * expressions, or argument lists.
 */
final class Compiler
{
   /**
    * Function body or top-level frame; statements are instrumented here.
    */
   private const string BODY = 'body';   // function body or top level — INJECT
   /**
    * Class/interface/trait/enum body frame.
    */
   private const string CLS  = 'class';  // class/interface/trait/enum body
   /**
    * Property-hook list frame.
    */
   private const string HOOK = 'hook';   // property-hook list (between get/set)
   /**
    * Expression block frame that must not be instrumented directly.
    */
   private const string SKIP = 'skip';   // match block — no injection
   /**
    * String-interpolation frame.
    */
   private const string STR  = 'str';    // string interpolation `{$var}` / `${var}`

   /**
    * Tokens that mark a logical statement as a *declaration only*
    * (do not anchor a hit marker).
    *
    * @var array<int, true>
    */
   private const array DECLARATIVE = [
      T_NAMESPACE  => true,
      T_USE        => true,
      T_DECLARE    => true,
      T_CLASS      => true,
      T_INTERFACE  => true,
      T_TRAIT      => true,
      T_ENUM       => true,
      T_FUNCTION   => true,
      T_ABSTRACT   => true,
      T_FINAL      => true,
      T_READONLY   => true,
      T_PUBLIC     => true,
      T_PROTECTED  => true,
      T_PRIVATE    => true,
      T_STATIC     => true,
      T_VAR        => true,
      T_CONST      => true,
      T_CASE       => true,
      T_DEFAULT    => true,
      T_EXTENDS    => true,
      T_IMPLEMENTS => true,
      T_AS         => true,
      // Control-flow continuations — must not be split from their
      // preceding `}` by an injected statement.
      T_ELSE       => true,
      T_ELSEIF     => true,
      T_CATCH      => true,
      T_FINALLY    => true,
      T_WHILE      => true,
   ];


   /**
    * Compile a source string with line-hit instrumentation.
    *
    * @param string $source Original PHP source.
    * @param string $file Canonical file path (used in hit markers and
    *                     `__FILE__`/`__DIR__` substitution).
    * @param array<int, int> &$lines OUT: executable lines map [line => 0].
    * @param string $mode Instrumentation mode (`strict` or `parity`).
    * @param array<int, array<int, true>> &$spans OUT: statement span projections.
    * @param array<int, int> &$labels OUT: case/default label to statement-start map.
    * @param array<int, true> &$declarations OUT: declaration lines (class/interface/trait/enum).
    */
   public static function compile (
      string $source,
      string $file,
      array &$lines = [],
      string $mode = Native::MODE_STRICT,
      array &$spans = [],
      array &$labels = [],
      array &$declarations = []
   ): string
   {
      $tokens = token_get_all($source);
      $out = '';
      $lines = [];
      $spans = [];
      $labels = [];
      $declarations = [];

      $fileLit = var_export($file, true);
      $dirLit = var_export(dirname($file), true);
      $marker = '\Bootgly\ACI\Tests\Coverage::hit(' . $fileLit . ',';

      // Block stack: top frame describes the current context.
      $stack = [self::BODY];
      // What kind of block the next `{` will open.
      $pending = null;
      // Parenthesis / bracket depth (zero = statement level).
      $depthRound = 0;
      $depthSquare = 0;
      // True when the next non-trivia code token is a statement start.
      $atStatementStart = true;
      // True when the last significant token was `->`, `?->`, or `::`,
      // so that a bare `{` is a dynamic accessor, not a code block.
      $afterAccessor = false;
      /** @var null|int $statementStart */
      $statementStart = null;
      /** @var array<int, true> $statementLines */
      $statementLines = [];
      /** @var null|int $pendingLabel */
      $pendingLabel = null;
      $afterLabel = false;

      foreach ($tokens as $token) {
         if (is_array($token)) {
            [$id, $text, $line] = $token;
            $context = end($stack);

            if (($id === T_CASE || $id === T_DEFAULT) && $context !== self::SKIP) {
               $afterLabel = true;
            }

            if ($mode === Native::MODE_PARITY) {
               if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
                  $declarations[(int) $line] = true;
               }

               if (($id === T_CASE || $id === T_DEFAULT) && $context !== self::SKIP) {
                  $pendingLabel = (int) $line;
               }
            }

            // Magic constant rewriting.
            if ($id === T_FILE) {
               $out .= $fileLit;
               if ($mode === Native::MODE_PARITY && $statementStart !== null) {
                  $statementLines[(int) $line] = true;
               }
               $atStatementStart = false;
               continue;
            }
            if ($id === T_DIR) {
               $out .= $dirLit;
               if ($mode === Native::MODE_PARITY && $statementStart !== null) {
                  $statementLines[(int) $line] = true;
               }
               $atStatementStart = false;
               continue;
            }

            // Pass-through trivia.
            if (
               $id === T_OPEN_TAG
               || $id === T_OPEN_TAG_WITH_ECHO
               || $id === T_CLOSE_TAG
               || $id === T_INLINE_HTML
               || $id === T_WHITESPACE
               || $id === T_COMMENT
               || $id === T_DOC_COMMENT
            ) {
               $out .= $text;
               continue;
            }

            // T_ATTRIBUTE is the literal `#[` opener — track it as a
            // square-bracket depth so its contents are never anchored.
            if ($id === T_ATTRIBUTE) {
               $depthSquare++;
               $out .= $text;
               continue;
            }

            // String interpolation openers `{$var}` (T_CURLY_OPEN) and
            // `${var}` (T_DOLLAR_OPEN_CURLY_BRACES) must NOT be treated
            // as code-block braces. Push a STR frame so the matching
            // plain `}` is consumed without setting atStatementStart.
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
               $stack[] = self::STR;
               $out .= $text;
               continue;
            }

            // Track upcoming block kind.
            if ($id === T_FUNCTION || $id === T_FN) {
               $pending = self::BODY;
            }
            else if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
               $pending = self::CLS;
            }
            else if ($id === T_MATCH) {
               $pending = self::SKIP;
            }

            $top = end($stack);
            $injectable = ($top === self::BODY);

            if (
               $injectable
               && $atStatementStart
               && $depthRound === 0
               && $depthSquare === 0
               && ! isset(self::DECLARATIVE[$id])
            ) {
               $line = (int) $line;
               $lines[$line] = 0;
               $out .= "$marker$line); ";

               $statementStart = $line;
               $statementLines = [$line => true];

               if ($pendingLabel !== null && $mode === Native::MODE_PARITY) {
                  $labels[$pendingLabel] = $line;
                  $pendingLabel = null;
               }
            }

            $out .= $text;
            if ($mode === Native::MODE_PARITY && $statementStart !== null) {
               $statementLines[(int) $line] = true;
            }
            $atStatementStart = false;
            $afterAccessor = (
               $id === T_OBJECT_OPERATOR
               || $id === T_NULLSAFE_OBJECT_OPERATOR
               || $id === T_DOUBLE_COLON
            );
            continue;
         }

         // Single-character token.
         switch ($token) {
            case '{':
               if ($afterAccessor) {
                  // Dynamic property/method accessor `$obj->{$p}` or
                  // `Foo::{$m}()`. The braces wrap an expression, not
                  // a new statement block. Track as STR so the `}` is
                  // consumed correctly.
                  $stack[] = self::STR;
                  $afterAccessor = false;
                  break;
               }
               $afterAccessor = false;
               if ($statementStart !== null) {
                  self::seal($lines, $spans, $statementStart, $statementLines, $mode);
                  $statementStart = null;
                  $statementLines = [];
               }
               $top = end($stack);
               if ($pending !== null) {
                  $stack[] = $pending;
                  $pending = null;
               }
               else if ($top === self::CLS) {
                  // `{` after a property declaration inside a class
                  // body opens the property-hook list.
                  $stack[] = self::HOOK;
               }
               else if ($top === self::HOOK) {
                  // get/set body.
                  $stack[] = self::BODY;
               }
               else {
                  // Control-flow body or arbitrary block — inherit.
                  $stack[] = $top;
               }
               $atStatementStart = true;
               break;
            case '}':
               $popped = array_pop($stack);
               // String interpolation `}` must not reset statement
               // boundary — we are still inside the enclosing string.
               if ($popped === self::SKIP) {
                  // `match (...) { ... }` is an expression, not a block
                  // boundary. The next token may still be part of the same
                  // statement (e.g. a ternary false arm), so do not anchor.
                  $atStatementStart = false;
               }
               else if ($popped !== self::STR) {
                  $atStatementStart = true;
               }
               break;
            case '(':
               $depthRound++;
               break;
            case ')':
               $depthRound--;
               break;
            case '[':
               $depthSquare++;
               break;
            case ']':
               $depthSquare--;
               break;
            case ';':
               if ($statementStart !== null) {
                  self::seal($lines, $spans, $statementStart, $statementLines, $mode);
                  $statementStart = null;
                  $statementLines = [];
               }
               $atStatementStart = true;
               break;
            case ':':
               if ($afterLabel) {
                  $atStatementStart = true;
                  $afterLabel = false;
               }
               break;
            default:
               $atStatementStart = false;
         }

         $out .= $token;
      }

      if ($statementStart !== null) {
         self::seal($lines, $spans, $statementStart, $statementLines, $mode);
      }

      ksort($lines);

      return $out;
   }

   /**
    * Seal the current statement projection into the executable-line map.
    *
    * @param array<int, int> $lines
    * @param array<int, array<int, true>> $spans
    * @param array<int, true> $statementLines
    */
   private static function seal (
      array &$lines,
      array &$spans,
      int $statementStart,
      array $statementLines,
      string $mode
   ): void
   {
      if ($mode === Native::MODE_PARITY) {
         foreach ($statementLines as $line => $_) {
            $line = (int) $line;
            $lines[$line] ??= 0;
         }

         if (! isset($spans[$statementStart])) {
            $spans[$statementStart] = [];
         }
         foreach ($statementLines as $line => $_) {
            $spans[$statementStart][(int) $line] = true;
         }
      }
   }
}
