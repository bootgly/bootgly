<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Methods;


use const T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;
use const T_CLASS;
use const T_CURLY_OPEN;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_ENUM;
use const T_EXTENDS;
use const T_FUNCTION;
use const T_IMPLEMENTS;
use const T_INTERFACE;
use const T_PAAMAYIM_NEKUDOTAYIM;
use const T_STRING;
use const T_TRAIT;
use function array_pop;
use function count;
use function file_get_contents;
use function is_array;
use function str_starts_with;
use function strtolower;
use function substr;
use function token_get_all;

use Bootgly\ABI\Syntax\Analyzers;
use Bootgly\ABI\Syntax\Analyzers\Issue;
use Bootgly\ABI\Syntax\Analyzers\Result;


/**
 * Reports every method whose name is more than one word — an uppercase letter
 * after the first character, the camelCase seam. Bootgly names methods with a
 * single verb.
 *
 * Only methods count: functions declared in the body of a class, interface,
 * trait or enum (anonymous classes included). Closures, arrow functions and
 * plain functions are never reported, nor are calls — `$this->fooBar()` is a
 * use, not a declaration. Two families of names are imposed on a class from
 * outside and are exempt: the magic methods (`__*`) and the names PHP's own
 * interfaces and base classes require (`IMPOSED`). Check-only — renaming a
 * method means renaming every caller.
 */
class Analyzer extends Analyzers
{
   // * Metadata
   /**
    * Method names PHP itself imposes — implementing one of its interfaces or
    * extending one of its classes leaves no choice of name. Lowercase, as PHP
    * resolves method names.
    *
    * The single place the exception list lives: `IteratorAggregate`,
    * `ArrayAccess`, `JsonSerializable`, `Throwable`, `Generator`,
    * `DateTimeInterface`, `RecursiveIterator`, `OuterIterator`,
    * `ArrayObject`/`ArrayIterator`, `SplFileInfo`/`SplFileObject` and
    * `CachingIterator`.
    */
   private const array IMPOSED = [
      // # IteratorAggregate
      'getiterator' => true,
      // # ArrayAccess
      'offsetexists' => true, 'offsetget' => true, 'offsetset' => true, 'offsetunset' => true,
      // # JsonSerializable
      'jsonserialize' => true,
      // # Throwable
      'getmessage' => true, 'getcode' => true, 'getfile' => true, 'getline' => true,
      'gettrace' => true, 'gettraceasstring' => true, 'getprevious' => true,
      // # Generator
      'getreturn' => true,
      // # DateTimeInterface
      'gettimestamp' => true, 'gettimezone' => true, 'getoffset' => true,
      'settimezone' => true, 'settimestamp' => true, 'setdate' => true, 'settime' => true,
      // # RecursiveIterator / OuterIterator / CachingIterator
      'getchildren' => true, 'haschildren' => true, 'getflags' => true, 'setflags' => true,
      'getsubiterator' => true, 'getinneriterator' => true, 'hasnext' => true,
      // # ArrayObject / ArrayIterator
      'getarraycopy' => true,
      // # SplFileInfo / SplFileObject
      'getrealpath' => true, 'getpathname' => true, 'getfilename' => true,
      'getextension' => true, 'isdir' => true, 'isfile' => true, 'islink' => true,
      'getsize' => true, 'getmtime' => true, 'getctime' => true, 'getatime' => true,
      'getperms' => true, 'getinode' => true, 'getowner' => true, 'getgroup' => true,
      'gettype' => true, 'isreadable' => true, 'iswritable' => true, 'isexecutable' => true,
      'getbasename' => true, 'getpath' => true, 'getpathinfo' => true, 'getfileinfo' => true,
      'openfile' => true, 'setfileclass' => true, 'setinfoclass' => true,
   ];


   /**
    * Analyze a PHP file for multi-word method names.
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

      // ! Every `{` pushes what it opens — a class-like body or anything else —
      //   so a `function` is a method exactly when the innermost frame is a body.
      //   Interpolation braces (`"{$x}"`, `"${x}"`) close with a plain `}` and
      //   push a frame of their own to keep the stack balanced.
      /** @var array<int,array{bool,bool}> Per frame: is it a class-like body, and does that class inherit anything */
      $frames = [];
      /**
       * The class-like headers still waiting for their body, by the paren
       * depth they were declared at — an anonymous class's arguments may
       * carry braces of their own (a `match`, a closure) at a deeper depth.
       *
       * @var array<int,bool> depth => whether the header inherits anything
       */
      $pendings = [];
      $parens = 0;

      // @
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];

         // # Braces
         if ($token === '{') {
            $body = isset($pendings[$parens]);
            $frames[] = [$body, $body && $pendings[$parens]];
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
            $frames[] = [false, false];
            continue;
         }

         // # A header that inherits — only there can a name be PHP's, not the author's
         if (isset($pendings[$parens]) && ($token[0] === T_EXTENDS || $token[0] === T_IMPLEMENTS)) {
            $pendings[$parens] = true;
            continue;
         }

         // # Class-like declarations — the next `{` at this paren depth is a body
         if ($token[0] === T_INTERFACE || $token[0] === T_TRAIT || $token[0] === T_ENUM || $token[0] === T_CLASS) {
            // ? The keyword is also a legal argument label (`f(trait: 1)`) and
            //   a legal method name (`function class ()`) — neither declares;
            //   nor does the constant fetch `Foo::class`
            $previous = $tokens[$this->rewind($tokens, $i)] ?? null;
            $following = $tokens[$this->advance($tokens, $i, $count)] ?? null;
            if ($following === ':'
               || (is_array($previous) && ($previous[0] === T_PAAMAYIM_NEKUDOTAYIM || $previous[0] === T_FUNCTION))
            ) {
               continue;
            }
            // ? A trait declares no inheritance of its own, but is mixed into
            //   classes that may implement the interface a name comes from
            $pendings[$parens] = $token[0] === T_TRAIT;
            continue;
         }

         if ($token[0] !== T_FUNCTION) {
            continue;
         }

         // ? A function is a method only directly inside a class-like body
         $frame = $frames[count($frames) - 1] ?? [false, false];
         if ($frame[0] === false) {
            continue;
         }

         // ? A closure has no name — `function (` or `function & (`
         $at = $this->advance($tokens, $i, $count);
         $named = $tokens[$at] ?? null;
         if (is_array($named) && $named[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
            $named = $tokens[$this->advance($tokens, $at, $count)] ?? null;
         }
         if (is_array($named) === false || $named[0] !== T_STRING) {
            continue;
         }

         /** @var string $name */
         $name = $named[1];
         /** @var int $line */
         $line = $named[2];

         if ($this->check($name, $frame[1]) === false) {
            continue;
         }

         $issues[] = new Issue(
            type: 'multiword_method',
            symbol: $name,
            kind: 'method',
            line: $line,
            message: "Multi-word method name: {$name}() → use a single-word verb"
         );
      }

      return new Result(file: $file, source: $source, issues: $issues);
   }

   /**
    * Whether a method name breaks the single-word rule.
    *
    * @param string $name The declared name
    * @param bool $inherits Whether the class extends or implements something — only then can a name be PHP's
    */
   private function check (string $name, bool $inherits): bool
   {
      // ? Magic methods are never the author's choice; a PHP-imposed name is
      //   not either — but only in a class that inherits it from somewhere
      if (str_starts_with($name, '__') || ($inherits && isset(self::IMPOSED[strtolower($name)]))) {
         return false;
      }

      // : An uppercase letter after the first character is a second word
      $rest = substr($name, 1);

      return $rest !== strtolower($rest);
   }
}
