<?php

namespace Bootgly\ABI\Code\__String\Tokens;


use function assert;
use function in_array;
use function is_int;
use function str_contains;

use Bootgly\ABI\Code\__String\Tokens;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should group PHP tokens into semantic types',
   test: function () {
      $Tokens = new Tokens;

      // @ Every mainstream group appears
      $lines = $Tokens->tokenize("<?php\n\$a = [1, 2]; // c\necho 'x';");

      $types = [];
      foreach ($lines as $line) {
         foreach ($line as $segment) {
            $types[] = $segment[0];
         }
      }

      yield assert(
         assertion: in_array(Tokens::TOKEN_VARIABLE, $types, true) === true
            && in_array(Tokens::TOKEN_NUMBER, $types, true) === true
            && in_array(Tokens::TOKEN_COMMENT, $types, true) === true
            && in_array(Tokens::TOKEN_STRING, $types, true) === true
            && in_array(Tokens::TOKEN_DELIMITER, $types, true) === true
            && in_array(Tokens::TOKEN_PONTUATION, $types, true) === true
            && in_array(Tokens::TOKEN_KEYWORD, $types, true) === true,
         description: 'Variables, numbers, comments, strings, delimiters, pontuation and keywords are grouped'
      );

      // @ Access group — object, nullsafe and static operators
      $access = 0;
      foreach ($Tokens->tokenize('<?php $a->b(); $a?->c(); A::D;') as $line) {
         foreach ($line as $segment) {
            if ($segment[0] === Tokens::TOKEN_ACCESS) {
               $access++;
            }
         }
      }

      yield assert(
         assertion: $access === 3,
         description: 'The object, nullsafe and static operators group as TOKEN_ACCESS'
      );

      // @ Qualified names — the namespace path splits from the final node
      $path = false;
      $node = false;
      foreach ($Tokens->tokenize('<?php use Bootgly\CLI\Terminal;') as $line) {
         foreach ($line as $segment) {
            if (
               $segment[0] === Tokens::TOKEN_PATH
               && str_contains((string) $segment[1], 'Bootgly\CLI\\') === true
            ) {
               $path = true;
            }
            if ($segment[0] === Tokens::TOKEN_DEFAULT && $segment[1] === 'Terminal') {
               $node = true;
            }
         }
      }

      yield assert(
         assertion: $path === true && $node === true,
         description: 'Qualified names split into a path segment and a final node'
      );

      // @ Function calls — a name directly before `(` paints as function
      $called = false;
      foreach ($Tokens->tokenize('<?php $a->b(1);') as $line) {
         foreach ($line as $segment) {
            if ($segment[0] === Tokens::TOKEN_FUNCTION && $segment[1] === 'b') {
               $called = true;
            }
         }
      }

      yield assert(
         assertion: $called === true,
         description: 'A called name groups as TOKEN_FUNCTION'
      );

      // @ Instantiations and member accesses — class and property differentiate
      $class = false;
      $property = false;
      foreach ($Tokens->tokenize('<?php $A = new Foo(1); $A->bar;') as $line) {
         foreach ($line as $segment) {
            if ($segment[0] === Tokens::TOKEN_CLASS && str_contains((string) $segment[1], 'Foo') === true) {
               $class = true;
            }
            if ($segment[0] === Tokens::TOKEN_PROPERTY && $segment[1] === 'bar') {
               $property = true;
            }
         }
      }

      yield assert(
         assertion: $class === true,
         description: 'A name after `new` groups as TOKEN_CLASS'
      );

      yield assert(
         assertion: $property === true,
         description: 'An accessed name without a call groups as TOKEN_PROPERTY'
      );

      // @ Fallbacks — unmapped tokens degrade by mode
      $ids = [];
      foreach ($Tokens->tokenize('<?php echo 1;', Tokens::AS_TOKEN_ID) as $line) {
         foreach ($line as $segment) {
            $ids[] = $segment[0];
         }
      }

      $intFound = false;
      foreach ($ids as $id) {
         if (is_int($id) === true) {
            $intFound = true;
         }
      }

      yield assert(
         assertion: $intFound === true,
         description: 'AS_TOKEN_ID yields numeric token ids for unmapped tokens'
      );

      $names = [];
      foreach ($Tokens->tokenize('<?php echo 1;', Tokens::AS_TOKEN_NAME) as $line) {
         foreach ($line as $segment) {
            $names[] = $segment[0];
         }
      }

      yield assert(
         assertion: in_array('T_ECHO', $names, true) === true,
         description: 'AS_TOKEN_NAME yields token names for unmapped tokens'
      );
   }
);
