<?php

namespace Bootgly\ABI\Data\__String\Tokens;


use function assert;
use function in_array;
use function is_int;
use function str_contains;

use Bootgly\ABI\Data\__String\Tokens;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should group PHP tokens into semantic types',
   test: function () {
      $Tokens = new Tokens;

      // @ Every mainstream group appears
      $lines = $Tokens->tokenize("<?php\n\$a = [1, 2]; // c\n\$s = 'x';");

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

      // @ Qualified names — import paths paint as names, not keywords
      $qualified = false;
      foreach ($Tokens->tokenize('<?php use Bootgly\CLI\Terminal;') as $line) {
         foreach ($line as $segment) {
            if (
               $segment[0] === Tokens::TOKEN_DEFAULT
               && str_contains((string) $segment[1], 'Bootgly\CLI\Terminal') === true
            ) {
               $qualified = true;
            }
         }
      }

      yield assert(
         assertion: $qualified === true,
         description: 'Qualified names group as TOKEN_DEFAULT'
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
