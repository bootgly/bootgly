<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Casing;


use const T_ABSTRACT;
use const T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG;
use const T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;
use const T_ARRAY;
use const T_CALLABLE;
use const T_CATCH;
use const T_DOC_COMMENT;
use const T_ELLIPSIS;
use const T_FINAL;
use const T_FN;
use const T_FUNCTION;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAME_RELATIVE;
use const T_NEW;
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
use function ctype_upper;
use function file_get_contents;
use function in_array;
use function is_array;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strrchr;
use function strtolower;
use function strtoupper;
use function substr;
use function token_get_all;
use function ucfirst;

use Bootgly\ABI\Syntax\Analyzers;
use Bootgly\ABI\Syntax\Analyzers\Issue;
use Bootgly\ABI\Syntax\Analyzers\Result;


/**
 * Reports names whose casing contradicts what they hold, where the tokens
 * make it knowable:
 *
 * - an object or an object collection starts uppercase — a property or
 *   parameter typed with a class (`Request $request`), an `array` property
 *   or parameter documented as a collection of a class
 *   (`@var array<int,Request>`), a variable assigned `new X`;
 * - an acronym is all uppercase — a property, parameter or variable named
 *   exactly after one, or its plural (`$url`, `$Html`, `$urls`).
 *
 * The type must name a class for certain: `object`, unions with a scalar and
 * plural type names — the convention for enums, abstract classes and
 * collections, whose cases and values stay lowercase — are not judged.
 * A variable is reported once per file. Check-only — a parameter name is
 * part of a public API under named arguments.
 */
class Analyzer extends Analyzers
{
   // * Metadata
   /** Type keywords that never name a class (lowercase) */
   private const array SCALARS = [
      'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never',
      'null', 'object', 'resource', 'string', 'true', 'void',
   ];
   /** Acronyms and initialisms, in their one written form */
   private const array ACRONYMS = [
      'ABI', 'ACI', 'ACL', 'ADI', 'API', 'CLI', 'CORS', 'CPU', 'CRLF', 'CSRF', 'CSS', 'CSV',
      'DNS', 'EOL', 'FTP', 'GUI', 'HTML', 'HTTP', 'HTTPS', 'IO', 'IP', 'JSON', 'JWT', 'MCP',
      'MIME', 'ORM', 'PDF', 'PEM', 'PHP', 'RAM', 'RPC', 'SMTP', 'SQL', 'SSE', 'SSH', 'SSL',
      'TCP', 'TLS', 'TTL', 'UDP', 'UI', 'URI', 'URL', 'UTF', 'UUID', 'UX', 'WPI', 'XML', 'YAML',
   ];
   /** Modifiers of a property (or a promoted parameter) */
   private const array MODIFIERS = [
      T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_STATIC, T_READONLY, T_FINAL, T_ABSTRACT,
      T_PUBLIC_SET, T_PROTECTED_SET, T_PRIVATE_SET,
   ];


   /**
    * Analyze a PHP file for object and acronym casing.
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
      /** @var array<string,true> Variables already reported in this file */
      $reported = [];
      /** @var array<string,string> `@param` types of the function being read, by name */
      $documented = [];

      // ! The parameter lists — of functions, closures, arrow functions,
      //   `catch` and property hooks (`set (Foo $Value) {`)
      /** @var array<int,true> Indexes of the tokens inside one */
      $parameters = [];
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];
         if (is_array($token) === false) {
            continue;
         }
         $hook = false;
         if ($token[0] === T_STRING && ($token[1] === 'set' || $token[1] === 'get')) {
            // ! Past a `final` modifier
            $previous = $this->rewind($tokens, $i);
            if (is_array($tokens[$previous] ?? null) && $tokens[$previous][0] === T_FINAL) {
               $previous = $this->rewind($tokens, $previous);
            }
            $hook = in_array($tokens[$previous] ?? null, ['{', ';', '}', ']'], true);
         }
         if ($hook === false && $token[0] !== T_FUNCTION && $token[0] !== T_FN && $token[0] !== T_CATCH) {
            continue;
         }
         // ? Its `(` — `use function foo;` has none
         $open = $i + 1;
         while ($open < $count && $tokens[$open] !== '(' && $tokens[$open] !== ';' && $tokens[$open] !== '{') {
            $open++;
         }
         if (($tokens[$open] ?? null) !== '(') {
            continue;
         }
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
            else {
               $parameters[$j] = true;
            }
         }
      }

      // @@
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];
         if (is_array($token) === false) {
            continue;
         }

         // # A function's `@param` types — for its `array` parameters
         if ($token[0] === T_FUNCTION || $token[0] === T_FN) {
            $documented = $this->document($tokens, $i);
            continue;
         }

         if ($token[0] !== T_VARIABLE || $token[1] === '$this') {
            continue;
         }

         /** @var string $variable */
         $variable = $token[1];
         $name = substr($variable, 1);
         /** @var int $line */
         $line = $token[2];

         // ! What declares it: a typed property/parameter, an assignment of `new`
         [$kind, $type] = $this->declare($tokens, $i, isset($parameters[$i]));
         if ($kind === null) {
            $assigned = $this->assign($tokens, $i, $count);
            if ($assigned === null || isset($reported[$name])) {
               continue;
            }
            $kind = 'variable';
            $type = $assigned ? 'new' : null;
         }

         // @ Acronyms — whatever the type
         $acronym = $this->abbreviate($name);
         if ($acronym !== null) {
            $issues[] = new Issue(
               type: 'acronym_case',
               symbol: $variable,
               kind: $kind,
               line: $line,
               message: "Acronym {$kind} name: {$variable} → \${$acronym}"
            );
            if ($kind === 'variable') {
               $reported[$name] = true;
            }
            continue;
         }

         // @ Objects — a class type, a documented collection of one, or `new`
         $bare = strtolower(str_replace(['?', 'null|', '|null'], '', (string) $type));
         $object = match ($kind) {
            'variable' => $type === 'new',
            default => $this->classify($type)
               || ($bare === 'array' && $this->classify($this->collect(
                  $kind === 'property' ? $this->describe($tokens, $i) : ($documented[$variable] ?? null)
               )))
         };
         if ($object === false || ctype_upper($name[0] ?? '') || ($name[0] ?? '_') === '_') {
            continue;
         }

         $fixed = ucfirst($name);
         $issues[] = new Issue(
            type: 'object_case',
            symbol: $variable,
            kind: $kind,
            line: $line,
            message: "Object {$kind} name starts lowercase: {$variable} → \${$fixed}"
         );
         if ($kind === 'variable') {
            $reported[$name] = true;
         }
      }

      return new Result(file: $file, source: $source, issues: $issues);
   }

   /**
    * Tell what declares the variable at `$i`, and with which type.
    *
    * @param array<int,array{int,string,int}|string> $tokens
    * @param bool $listed Whether the variable sits in a parameter list — a
    *   type before a variable anywhere else (`FLAG | $flags`) is an operand
    *
    * @return array{0:null|string,1:null|string} [`property`|`parameter`|`variable`|null, the type written or `new`]
    */
   private function declare (array $tokens, int $i, bool $listed): array
   {
      // # A type before it — `Type $x`, `Type ...$x`, `Type &$x`
      $at = $this->rewind($tokens, $i);
      $token = $tokens[$at] ?? null;
      if (is_array($token) && ($token[0] === T_ELLIPSIS || $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG)) {
         $at = $this->rewind($tokens, $at);
      }
      $type = '';
      while ($at >= 0) {
         $token = $tokens[$at];
         $piece = match (true) {
            $token === '|' || $token === '?' => $token,
            is_array($token) && $token[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG => '&',
            is_array($token) && in_array($token[0], [
               T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_ARRAY, T_CALLABLE,
            ], true) => $token[1],
            default => null
         };
         if ($piece === null) {
            break;
         }
         $type = "{$piece}{$type}";
         $at = $this->rewind($tokens, $at);
      }
      if ($type === '') {
         return [null, null];
      }

      // ? A type ends with a name — `FLAG | $x`, `MASK & $x`, `A ? $x` are operators
      $last = $type[strlen($type) - 1];
      if ($last === '|' || $last === '&' || $last === '?' || $type[0] === '|' || $type[0] === '&') {
         return [null, null];
      }

      // ! What precedes the type: modifiers (a property or a promoted parameter), `(`, `,` or an attribute's `]`
      $before = $tokens[$at] ?? null;
      $modified = false;
      while (is_array($before) && in_array($before[0], self::MODIFIERS, true)) {
         $modified = true;
         $at = $this->rewind($tokens, $at);
         $before = $tokens[$at] ?? null;
      }

      // :
      return match (true) {
         $listed && ($modified || $before === '(' || $before === ',' || $before === ']') => ['parameter', $type],
         $modified && $listed === false => ['property', $type],
         default => [null, null]
      };
   }

   /**
    * Tell whether the variable at `$i` is assigned, and whether with `new`.
    *
    * @param array<int,array{int,string,int}|string> $tokens
    *
    * @return null|bool null when it is not assigned here; whether the value is `new ...`
    */
   private function assign (array $tokens, int $i, int $count): null|bool
   {
      $next = $this->advance($tokens, $i, $count);
      if (($tokens[$next] ?? null) !== '=') {
         return null;
      }
      // ? A property fetch (`$a->$b = `) or a static one (`A::$b = `) is not this variable
      $previous = $tokens[$this->rewind($tokens, $i)] ?? null;
      if ($previous === '->' || (is_array($previous) && ($previous[1] === '::' || $previous[1] === '->' || $previous[1] === '?->'))) {
         return null;
      }

      // ? The value must be `new ...`
      $at = $this->advance($tokens, $next, $count);
      $value = $tokens[$at] ?? null;
      if (is_array($value) === false || $value[0] !== T_NEW) {
         return false;
      }

      // @@ ...and nothing after it — `new X()->y()` assigns what `y()` returns
      $depth = 0;
      for ($j = $at + 1; $j < $count; $j++) {
         $token = $tokens[$j];
         if ($token === '(' || $token === '[' || $token === '{') {
            $depth++;
         }
         else if ($token === ')' || $token === ']' || $token === '}') {
            $depth--;
            if ($depth < 0) {
               return true;
            }
         }
         else if ($depth === 0 && ($token === ';' || $token === ',')) {
            return true;
         }
         else if ($depth === 0 && ($token === '?' || (is_array($token) && in_array($token[1], ['->', '?->', '::', '??', '?:'], true)))) {
            return false;
         }
         else if ($depth === 0 && $token !== '&' && is_array($token) === false) {
            // ? An operator — the value is an expression over the object
            return false;
         }
      }

      return true;
   }

   /**
    * Tell whether a written type names a class for certain.
    *
    * @param null|string $type
    */
   private function classify (null|string $type): bool
   {
      if ($type === null || $type === '' || str_ends_with($type, ']') || preg_match('/[()&]/', $type) === 1) {
         return false;
      }

      $members = 0;
      foreach (preg_match_all('/[^|?]+/', $type, $matches) > 0 ? $matches[0] : [] as $member) {
         $lower = strtolower($member);
         if ($lower === 'null') {
            continue;
         }
         // ? A scalar member — the value may not be an object
         if (in_array($lower, self::SCALARS, true)) {
            return false;
         }
         // ? A plural name — an enum, an abstract class or a collection
         if ($lower !== 'self' && $lower !== 'static' && $lower !== 'parent') {
            $short = strtolower(ltrim((string) (strrchr("\\{$member}", '\\')), '\\'));
            if (str_ends_with($short, 's') && str_ends_with($short, 'ss') === false
               && str_ends_with($short, 'us') === false && str_ends_with($short, 'is') === false
            ) {
               return false;
            }
         }
         $members++;
      }

      return $members > 0;
   }

   /**
    * The element type of a documented collection — `array<K,T>`,
    * `list<T>`, `iterable<T>`, `T[]` — or null.
    */
   private function collect (null|string $type): null|string
   {
      if ($type === null) {
         return null;
      }
      if (preg_match('/^(?:array|list|iterable|non-empty-array|non-empty-list)<(?:[^,<>]+,\s*)?([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)>$/', $type, $match) === 1) {
         return $match[1];
      }
      if (preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\[\]$/', $type, $match) === 1) {
         return $match[1];
      }

      return null;
   }

   /**
    * The `@var` type of the doc comment right before a property declaration.
    *
    * @param array<int,array{int,string,int}|string> $tokens
    */
   private function describe (array $tokens, int $i): null|string
   {
      // @@ Back over the type and the modifiers to the doc comment, if any
      for ($j = $i - 1; $j >= 0; $j--) {
         $token = $tokens[$j];
         if ($token === ';' || $token === '{' || $token === '}') {
            return null;
         }
         if (is_array($token) && $token[0] === T_DOC_COMMENT) {
            return preg_match('/@var\s+([^\s*]+)/', $token[1], $match) === 1 ? $match[1] : null;
         }
      }

      return null;
   }

   /**
    * The `@param` types of the doc comment right before a function, by name.
    *
    * @param array<int,array{int,string,int}|string> $tokens
    *
    * @return array<string,string>
    */
   private function document (array $tokens, int $i): array
   {
      // @@ Back over the modifiers and attributes to the doc comment, if any
      for ($j = $i - 1; $j >= 0; $j--) {
         $token = $tokens[$j];
         if ($token === ';' || $token === '{' || $token === '}' || $token === '(' || $token === ',' || $token === '=') {
            return [];
         }
         if (is_array($token) && $token[0] === T_DOC_COMMENT) {
            $types = [];
            if (preg_match_all('/@param\s+([^\s*]+)\s+(?:\.\.\.)?(\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)/', $token[1], $matches) > 0) {
               foreach ($matches[2] as $index => $variable) {
                  $types[$variable] = $matches[1][$index];
               }
            }

            return $types;
         }
      }

      return [];
   }

   /**
    * The acronym a name is written after in the wrong case, or null.
    *
    * @return null|string The name as it must be written
    */
   private function abbreviate (string $name): null|string
   {
      $plural = str_ends_with($name, 's') ? substr($name, 0, -1) : null;

      // @@ The name itself first — `$https` is `$HTTPS`, not the plural `$HTTPs`
      foreach (self::ACRONYMS as $acronym) {
         if (strtoupper($name) === $acronym && $name !== $acronym) {
            return $acronym;
         }
      }
      foreach (self::ACRONYMS as $acronym) {
         if ($plural !== null && strtoupper($plural) === $acronym && $plural !== $acronym) {
            return "{$acronym}s";
         }
      }

      return null;
   }
}
