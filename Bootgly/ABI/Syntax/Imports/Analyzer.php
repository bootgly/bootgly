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


use const T_AS;
use const T_CASE;
use const T_CLASS;
use const T_COMMENT;
use const T_CONST;
use const T_DOC_COMMENT;
use const T_ENUM;
use const T_EXTENDS;
use const T_FUNCTION;
use const T_IMPLEMENTS;
use const T_INSTANCEOF;
use const T_INTERFACE;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_NEW;
use const T_NS_SEPARATOR;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;
use const T_PAAMAYIM_NEKUDOTAYIM;
use const T_STRING;
use const T_TRAIT;
use const T_USE;
use function count;
use function explode;
use function file_get_contents;
use function in_array;
use function is_array;
use function preg_match;
use function str_contains;
use function strcasecmp;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function token_get_all;
use function trim;

use Bootgly\ABI\Syntax\Builtins;
use Bootgly\ABI\Syntax\Imports\Analyzer\Issue;
use Bootgly\ABI\Syntax\Imports\Analyzer\Result;
use Bootgly\ABI\Syntax\Imports\Analyzer\Tokens;


class Analyzer
{
   // * Config
   // * Data
   // * Metadata

   // @ Token IDs for skipping function-call detection
   private const array SKIP_BEFORE_FUNCTION = [
      T_OBJECT_OPERATOR,
      T_PAAMAYIM_NEKUDOTAYIM,
      T_NULLSAFE_OBJECT_OPERATOR,
      T_FUNCTION,
      T_CLASS,
      T_INTERFACE,
      T_TRAIT,
      T_ENUM,
      T_NEW,
      T_CONST,
   ];

   // @ Token IDs for class-reference contexts
   private const array CLASS_CONTEXT_TOKENS = [
      T_NEW,
      T_EXTENDS,
      T_IMPLEMENTS,
      T_INSTANCEOF,
   ];


   /**
    * Analyze a PHP file for import violations.
    *
    * @param string $file Absolute path to PHP file
    *
    * @return Result
    */
   public function analyze (string $file): Result
   {
      $source = file_get_contents($file);
      if ($source === false) {
         return new Result(
            file: $file,
            source: '',
            namespace: '',
            imports: [],
            importRange: ['start' => -1, 'end' => -1],
            symbols: [],
            issues: []
         );
      }

      $tokens = token_get_all($source);
      $count = count($tokens);
      $Tokens = new Tokens($tokens, $count);

      // * Data
      $namespace = '';
      /** @var array<int,array{symbol:string,kind:string,global:bool,line:int,alias:string}> */
      $imports = [];
      $importStart = -1;
      $importEnd = -1;
      /** @var array<string,array{kind:string,lines:array<int>}> */
      $usedSymbols = [];
      /** @var array<int,Issue> */
      $issues = [];

      // * Metadata
      $importedFunctions = [];
      $importedConstants = [];
      $importedClasses = [];
      $inClassBody = 0;
      $namespaceCount = 0;

      // ---
      // Phase 1: Extract namespace and imports
      // ---
      $bodyStart = 0;
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];

         if (!is_array($token)) {
            continue;
         }

         // @ Namespace
         if ($token[0] === T_NAMESPACE) {
            $namespaceCount++;

            // @ Skip files with multiple namespace blocks
            if ($namespaceCount > 1) {
               return new Result(
                  file: $file,
                  source: $source,
                  namespace: $namespace,
                  imports: [],
                  importRange: ['start' => -1, 'end' => -1],
                  symbols: [],
                  issues: []
               );
            }

            $namespace = $this->parse($Tokens, $i);
            continue;
         }

         // @ Use statements (top-level only)
         if ($token[0] === T_USE) {
            // ? Skip closure `use` (e.g., `function () use ($var)`)
            if ($Tokens->rewind($i) === ')') {
               continue;
            }

            // ? Skip trait `use` inside class body
            if ($inClassBody > 0) {
               continue;
            }

            $import = $this->extract($Tokens, $i);
            if ($import === null) {
               continue;
            }

            foreach ($import['items'] as $item) {
               $imports[] = $item;

               match ($item['kind']) {
                  'function' => $importedFunctions[strtolower($item['alias'])] = true,
                  'const'    => $importedConstants[$item['alias']] = true,
                  'class'    => $importedClasses[strtolower($item['alias'])] = true,
                  default    => null,
               };
            }

            // @ Track import block range
            if ($importStart === -1) {
               $importStart = $import['byteStart'];
            }
            $importEnd = $import['byteEnd'];
            $bodyStart = $i;

            continue;
         }

         // @ Track class body depth for trait `use` detection
         if ($token[0] === T_CLASS || $token[0] === T_INTERFACE
            || $token[0] === T_TRAIT || $token[0] === T_ENUM
         ) {
            // Find opening brace
            for ($j = $i + 1; $j < $count; $j++) {
               if ($tokens[$j] === '{') {
                  $inClassBody++;
                  $i = $j;
                  break;
               }
            }
            $bodyStart = $i;
            continue;
         }
      }

      // @ Skip files without a namespace declaration
      if ($namespace === '') {
         return new Result(
            file: $file,
            source: $source,
            namespace: '',
            imports: [],
            importRange: ['start' => -1, 'end' => -1],
            symbols: [],
            issues: []
         );
      }

      // ---
      // Phase 1.5: Pre-scan for locally-defined functions
      // ---
      $localFunctions = [];
      $classDepth = 0;
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];

         if ($token === '{') {
            $classDepth++;
            continue;
         }
         if ($token === '}') {
            $classDepth--;
            continue;
         }

         if (!is_array($token)) {
            continue;
         }

         // @ Track class/interface/trait/enum bodies
         if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            for ($j = $i + 1; $j < $count; $j++) {
               if ($tokens[$j] === '{') {
                  $classDepth++;
                  $i = $j;
                  break;
               }
            }
            continue;
         }

         // @ Find function definitions outside class bodies
         if ($token[0] === T_FUNCTION && $classDepth === 0) {
            $nextToken = $Tokens->advance($i);
            if ($nextToken !== null && is_array($nextToken) && $nextToken[0] === T_STRING) {
               $localFunctions[strtolower($nextToken[1])] = true;
            }
         }
      }

      // ---
      // Phase 2: Scan body for symbol usage
      // ---
      $inClassBody = 0;
      for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];

         // @ Track brace depth
         if ($token === '{') {
            continue;
         }
         if ($token === '}') {
            continue;
         }

         if (!is_array($token)) {
            continue;
         }

         // @ Skip comments
         if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
            continue;
         }

         // @ Skip use statements, namespace decl
         if ($token[0] === T_USE || $token[0] === T_NAMESPACE) {
            continue;
         }

         // @ Detect backslash-prefixed global symbols: \func(), \CONST, \Class::
         if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            $fullName = $token[1]; // e.g. "\str_pad"
            $bsLine = $token[2];

            // @ Only handle single-segment: \name (not \Foo\Bar)
            $stripped = substr($fullName, 1);
            if (str_contains($stripped, '\\')) {
               continue;
            }

            $bsName = $stripped;
            $afterToken = $Tokens->advance($i);
            $bsOffset = $Tokens->locate($i);
            $bsKind = null;

            // * function: \func(
            if ($afterToken === '(') {
               if (Builtins::check($bsName, 'function')
                  || isset($importedFunctions[strtolower($bsName)])
                  || isset($localFunctions[strtolower($bsName)])
               ) {
                  $bsKind = 'function';
               }
            }
            // * constant: \ALL_CAPS
            else if ($this->detect($bsName)) {
               if (Builtins::check($bsName, 'const')
                  || isset($importedConstants[$bsName])
               ) {
                  $bsKind = 'const';
               }
            }
            // * class: \Class::
            else if (is_array($afterToken) && $afterToken[0] === T_PAAMAYIM_NEKUDOTAYIM) {
               if (Builtins::check($bsName, 'class')
                  || isset($importedClasses[strtolower($bsName)])
               ) {
                  $bsKind = 'class';
               }
            }

            if ($bsKind !== null) {
               $this->track($usedSymbols, $bsName, $bsKind, $bsLine);

               // @ backslash_prefix issue (for body fix)
               $issues[] = new Issue(
                  type: 'backslash_prefix',
                  symbol: $bsName,
                  kind: $bsKind,
                  line: $bsLine,
                  message: "Remove \\ prefix: \\{$bsName} → use explicit import",
                  offset: $bsOffset
               );

               // @ missing_import issue (if not already imported or locally defined)
               $imported = match ($bsKind) {
                  'function' => isset($importedFunctions[strtolower($bsName)])
                     || isset($localFunctions[strtolower($bsName)]),
                  'const'    => isset($importedConstants[$bsName]),
                  'class'    => isset($importedClasses[strtolower($bsName)]),
               };

               if (!$imported) {
                  $importLabel = match ($bsKind) {
                     'function' => "use function {$bsName};",
                     'const'    => "use const {$bsName};",
                     'class'    => "use {$bsName};",
                  };

                  $issues[] = new Issue(
                     type: 'missing_import',
                     symbol: $bsName,
                     kind: $bsKind,
                     line: $bsLine,
                     message: "Missing import: {$importLabel}"
                  );
               }
            }

            continue;
         }

         if ($token[0] !== T_STRING) {
            continue;
         }

         $name = $token[1];
         $line = $token[2];

         // @ Get previous meaningful token
         $prevToken = $Tokens->rewind($i);
         // @ Get next meaningful token
         $nextToken = $Tokens->advance($i);

         // @ Detect function calls: T_STRING followed by `(`
         if ($nextToken === '(') {
            // ? Skip if preceded by object/static access, function/class/const declaration
            if ($prevToken !== null && is_array($prevToken)
               && in_array($prevToken[0], self::SKIP_BEFORE_FUNCTION, true)
            ) {
               continue;
            }

            // ? Skip if already imported
            if (isset($importedFunctions[strtolower($name)])) {
               $this->track($usedSymbols, $name, 'function', $line);
               continue;
            }

            // ? Skip locally-defined functions
            if (isset($localFunctions[strtolower($name)])) {
               $this->track($usedSymbols, $name, 'function', $line);
               continue;
            }

            // ? Check if it's a builtin function
            if (Builtins::check($name, 'function')) {
               $this->track($usedSymbols, $name, 'function', $line);
               $issues[] = new Issue(
                  type: 'missing_import',
                  symbol: $name,
                  kind: 'function',
                  line: $line,
                  message: "Missing import: use function {$name};"
               );
            }

            continue;
         }

         // @ Detect constant usage: ALL_CAPS T_STRING
         if ($this->detect($name)) {
            // ? Skip if preceded by const keyword, namespace/use, or object access
            if ($prevToken !== null && is_array($prevToken)
               && in_array($prevToken[0], [
                  T_CONST, T_OBJECT_OPERATOR, T_PAAMAYIM_NEKUDOTAYIM,
                  T_NULLSAFE_OBJECT_OPERATOR, T_NAMESPACE, T_USE,
                  T_CASE,
               ], true)
            ) {
               continue;
            }

            // ? Skip if this looks like a class constant (Class::CONST)
            if ($prevToken !== null && is_array($prevToken)
               && $prevToken[0] === T_PAAMAYIM_NEKUDOTAYIM
            ) {
               continue;
            }

            // ? Skip if already imported
            if (isset($importedConstants[$name])) {
               $this->track($usedSymbols, $name, 'const', $line);
               continue;
            }

            // ? Check if it's a builtin constant
            if (Builtins::check($name, 'const')) {
               $this->track($usedSymbols, $name, 'const', $line);
               $issues[] = new Issue(
                  type: 'missing_import',
                  symbol: $name,
                  kind: 'const',
                  line: $line,
                  message: "Missing import: use const {$name};"
               );
            }

            continue;
         }

         // @ Detect class reference: `new X`, `X::`, type hints, catch, extends, implements
         if ($prevToken !== null && is_array($prevToken)
            && in_array($prevToken[0], self::CLASS_CONTEXT_TOKENS, true)
         ) {
            // ? Skip if already imported
            if (isset($importedClasses[strtolower($name)])) {
               $this->track($usedSymbols, $name, 'class', $line);
               continue;
            }

            // ? Check if it's a builtin class
            if (Builtins::check($name, 'class')) {
               $this->track($usedSymbols, $name, 'class', $line);
               $issues[] = new Issue(
                  type: 'missing_import',
                  symbol: $name,
                  kind: 'class',
                  line: $line,
                  message: "Missing import: use {$name};"
               );
            }

            continue;
         }

         // @ Detect static access: `X::`
         if (is_array($nextToken) && $nextToken[0] === T_PAAMAYIM_NEKUDOTAYIM) {
            if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
               continue;
            }

            if (isset($importedClasses[strtolower($name)])) {
               $this->track($usedSymbols, $name, 'class', $line);
               continue;
            }

            if (Builtins::check($name, 'class')) {
               $this->track($usedSymbols, $name, 'class', $line);
               $issues[] = new Issue(
                  type: 'missing_import',
                  symbol: $name,
                  kind: 'class',
                  line: $line,
                  message: "Missing import: use {$name};"
               );
            }
         }
      }

      // ---
      // Phase 3: Validate import ordering
      // ---
      $this->validate($imports, $issues);

      // @ Build import range
      $importRange = [
         'start' => $importStart,
         'end'   => $importEnd,
      ];

      return new Result(
         file: $file,
         source: $source,
         namespace: $namespace,
         imports: $imports,
         importRange: $importRange,
         symbols: $usedSymbols,
         issues: $issues
      );
   }

   /**
    * Parse namespace name from tokens.
    *
    * @param Tokens $Tokens
    * @param int $i Current position (at T_NAMESPACE)
    *
    * @return string
    */
   private function parse (Tokens $Tokens, int &$i): string
   {
      $tokens = &$Tokens->items;
      $count = $Tokens->count;

      $name = '';
      $i++;
      while ($i < $count) {
         $token = $tokens[$i];
         if ($token === ';' || $token === '{') {
            break;
         }
         if (is_array($token)) {
            if ($token[0] === T_STRING || $token[0] === T_NAME_QUALIFIED
               || $token[0] === T_NAME_FULLY_QUALIFIED || $token[0] === T_NS_SEPARATOR
            ) {
               $name .= $token[1];
            }
         }
         $i++;
      }

      return trim($name);
   }

   /**
    * Extract a use statement (single or grouped).
    *
    * @param Tokens $Tokens
    * @param int $i Current position (at T_USE)
    *
    * @return null|array{items:array<int,array{symbol:string,kind:string,global:bool,line:int,alias:string}>,byteStart:int,byteEnd:int}
    */
   private function extract (Tokens $Tokens, int &$i): null|array
   {
      $tokens = &$Tokens->items;
      $count = $Tokens->count;

      $startToken = $tokens[$i];
      $byteStart = $Tokens->locate($i);
      /** @var array{int,string,int} $startToken */
      $line = $startToken[2];

      $i++;
      $kind = 'class';
      $parts = '';
      $items = [];
      $grouped = false;
      $prefix = '';

      while ($i < $count) {
         $token = $tokens[$i];

         if ($token === ';') {
            $byteEnd = $byteStart;
            // Calculate byte end from source position
            for ($k = 0; $k <= $i; $k++) {
               // just use the final semicolon position
            }
            $byteEnd = $Tokens->locate($i, true);

            if (trim($parts) !== '') {
               $symbol = trim($parts);
               $alias = $this->resolve($symbol);
               $items[] = [
                  'symbol' => $symbol,
                  'kind'   => $kind,
                  'global' => !str_contains($symbol, '\\'),
                  'line'   => $line,
                  'alias'  => $alias,
               ];
            }
            break;
         }

         if ($token === '{') {
            $grouped = true;
            $prefix = trim($parts);
            $parts = '';
            $i++;
            continue;
         }

         if ($token === '}') {
            $i++;
            continue;
         }

         if ($token === ',') {
            $symbol = trim($parts);
            if ($symbol !== '') {
               $fullSymbol = $grouped ? $prefix . $symbol : $symbol;
               $alias = $this->resolve($fullSymbol);
               $items[] = [
                  'symbol' => $fullSymbol,
                  'kind'   => $kind,
                  'global' => !str_contains($fullSymbol, '\\'),
                  'line'   => $line,
                  'alias'  => $alias,
               ];
            }
            $parts = '';
            $i++;
            continue;
         }

         if (is_array($token)) {
            if ($token[0] === T_FUNCTION) {
               $kind = 'function';
            }
            else if ($token[0] === T_CONST) {
               $kind = 'const';
            }
            else if ($token[0] === T_STRING || $token[0] === T_NAME_QUALIFIED
               || $token[0] === T_NAME_FULLY_QUALIFIED || $token[0] === T_NS_SEPARATOR
            ) {
               $parts .= $token[1];
            }
            else if ($token[0] === T_AS) {
               $parts .= ' as ';
            }
         }

         $i++;
      }

      if ($items === []) {
         return null;
      }

      return [
         'items'     => $items,
         'byteStart' => $byteStart,
         'byteEnd'   => $byteEnd ?? $byteStart,
      ];
   }

   /**
    * Get the alias (short name) of an import symbol.
    *
    * @param string $symbol Full symbol (e.g., 'Bootgly\CLI\Command')
    *
    * @return string Alias (e.g., 'Command')
    */
   private function resolve (string $symbol): string
   {
      // @ Handle "as" aliases
      if (str_contains($symbol, ' as ')) {
         $parts = explode(' as ', $symbol);
         return trim($parts[1]);
      }

      $pos = strrpos($symbol, '\\');
      if ($pos === false) {
         return $symbol;
      }

      return substr($symbol, $pos + 1);
   }

   /**
    * Detect if a name looks like a constant (ALL_CAPS with underscores).
    *
    * @param string $name
    *
    * @return bool
    */
   private function detect (string $name): bool
   {
      if (strlen($name) < 2) {
         return false;
      }

      // @ Must be ALL_CAPS (allow digits and underscores)
      return preg_match('/^[A-Z][A-Z0-9_]+$/', $name) === 1;
   }

   /**
    * Track a used symbol.
    *
    * @param array<string,array{kind:string,lines:array<int>}> $symbols
    * @param string $name
    * @param string $kind
    * @param int $line
    */
   private function track (array &$symbols, string $name, string $kind, int $line): void
   {
      if (!isset($symbols[$name])) {
         $symbols[$name] = ['kind' => $kind, 'lines' => []];
      }
      $symbols[$name]['lines'][] = $line;
   }

   /**
    * Validate import ordering and generate issues.
    *
    * @param array<int,array{symbol:string,kind:string,global:bool,line:int,alias:string}> $imports
    * @param array<int,Issue> $issues
    */
   private function validate (array &$imports, array &$issues): void
   {
      if (count($imports) < 2) {
         return;
      }

      // @ Define expected order: const → function → class
      $kindOrder = ['const' => 0, 'function' => 1, 'class' => 2];

      $prevKindIndex = -1;
      $prevGlobal = true;
      $prevSymbol = '';
      $prevKind = '';

      foreach ($imports as $import) {
         $kindIndex = $kindOrder[$import['kind']];
         $isGlobal = $import['global'];

         // @ Reset kind tracking when crossing the global/namespaced boundary
         if ($isGlobal !== $prevGlobal) {
            $prevKindIndex = -1;
            $prevSymbol = '';
         }

         // @ Check kind ordering (const before function before class), within same group
         if ($kindIndex < $prevKindIndex) {
            $issues[] = new Issue(
               type: 'wrong_order',
               symbol: $import['symbol'],
               kind: $import['kind'],
               line: $import['line'],
               message: "Wrong import order: use {$import['kind']} should come before use {$prevKind}"
            );
         }

         // @ Within same kind: globals should come before namespaced
         if ($kindIndex === $prevKindIndex && $isGlobal && !$prevGlobal) {
            $issues[] = new Issue(
               type: 'global_not_first',
               symbol: $import['symbol'],
               kind: $import['kind'],
               line: $import['line'],
               message: "Global import should come before namespaced: {$import['symbol']}"
            );
         }

         // @ Within same kind and same global/namespaced group: alphabetical
         if ($kindIndex === $prevKindIndex && $isGlobal === $prevGlobal && $prevSymbol !== '') {
            if (strcasecmp($import['symbol'], $prevSymbol) < 0) {
               $issues[] = new Issue(
                  type: 'not_alphabetical',
                  symbol: $import['symbol'],
                  kind: $import['kind'],
                  line: $import['line'],
                  message: "Import not alphabetically ordered: {$import['symbol']} should come before {$prevSymbol}"
               );
            }
         }

         $prevKindIndex = $kindIndex;
         $prevGlobal = $isGlobal;
         $prevSymbol = $import['symbol'];
         $prevKind = $import['kind'];
      }
   }
}
