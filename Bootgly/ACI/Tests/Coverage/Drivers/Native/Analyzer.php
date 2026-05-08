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
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_DOUBLE_COLON;
use const T_ELSE;
use const T_ELSEIF;
use const T_ENUM;
use const T_EXTENDS;
use const T_FINAL;
use const T_FINALLY;
use const T_FUNCTION;
use const T_IMPLEMENTS;
use const T_INLINE_HTML;
use const T_INTERFACE;
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
use function is_array;
use function token_get_all;


/**
 * Token-based executable-line analyzer.
 *
 * Given a PHP source string, produces the set of line numbers that
 * are considered "executable statements" — used as denominator for
 * line coverage and as injection anchors for the `Compiler`.
 */
final class Analyzer
{
   /**
    * Tokens that mark a statement as a *declaration only* (not executable).
    * When such a token starts a logical statement, no hit marker is injected
    * before it.
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
      T_ELSE       => true,
      T_ELSEIF     => true,
      T_CATCH      => true,
      T_FINALLY    => true,
      T_WHILE      => true,
   ];

   /**
    * Scan a PHP source string and return its executable line numbers.
    *
    * @return array<int, int> [line => 0] map (zero-hit denominator).
    */
   public static function scan (string $source): array
   {
      $tokens = token_get_all($source);
      $lines = [];
      $depthCurly  = 0;      // generic { } depth
      $depthRound  = 0;      // ( ) depth — skip statements inside signatures
      $depthSquare = 0;      // [ ] depth — array/attribute literals
      $depthInterp = 0;      // {$var} / ${var} string interpolation depth
      $atStatementStart = true;
      $afterLabel = false;
      // True when last significant token was `->`, `?->`, or `::` so
      // that a bare `{` is a dynamic accessor, not a code block.
      $afterAccessor = false;

      foreach ($tokens as $token) {
         if (is_array($token)) {
            [$id, , $line] = $token;

            // Skip non-code tokens entirely.
            if (
               $id === T_OPEN_TAG
               || $id === T_OPEN_TAG_WITH_ECHO
               || $id === T_CLOSE_TAG
               || $id === T_INLINE_HTML
               || $id === T_WHITESPACE
               || $id === T_COMMENT
               || $id === T_DOC_COMMENT
            ) {
               continue;
            }

            // T_ATTRIBUTE is the literal `#[` opener.
            if ($id === T_ATTRIBUTE) {
               $depthSquare++;
               continue;
            }

            // String interpolation openers `{$var}` and `${var}` are
            // not code-block braces. Track depth with a separate counter
            // so the matching `}` is consumed without resetting
            // atStatementStart or corrupting $depthCurly.
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
               $depthInterp++;
               // Do NOT set atStatementStart — we are inside a string.
               continue;
            }

            if (
               $atStatementStart
               && $depthRound === 0
               && $depthSquare === 0
               && ! isset(self::DECLARATIVE[$id])
            ) {
               $lines[$line] = 0;
            }

            if ($id === T_CASE || $id === T_DEFAULT) {
               $afterLabel = true;
            }

            $atStatementStart = false;
            $afterAccessor = (
               $id === T_OBJECT_OPERATOR
               || $id === T_NULLSAFE_OBJECT_OPERATOR
               || $id === T_DOUBLE_COLON
            );
            continue;
         }

         // Single-character tokens (string).
         switch ($token) {
            case '{':
               if ($afterAccessor) {
                  // Dynamic accessor `$obj->{$p}` or `Foo::{$m}()`.
                  // Use $depthInterp so the matching `}` does not
                  // reset atStatementStart.
                  $depthInterp++;
                  $afterAccessor = false;
                  break;
               }
               $afterAccessor = false;
               $depthCurly++;
               $atStatementStart = true;
               break;
            case '}':
               if ($depthInterp > 0) {
                  $depthInterp--;
                  // Inside string interpolation — do not reset boundary.
               }
               else {
                  $depthCurly--;
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
      }

      return $lines;
   }
}
