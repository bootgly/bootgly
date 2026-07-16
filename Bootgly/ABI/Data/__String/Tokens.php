<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\__String;


use const T_CLASS;
use const T_CLASS_C;
use const T_CLOSE_TAG;
use const T_COALESCE;
use const T_COMMENT;
use const T_CONST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_DIR;
use const T_DNUMBER;
use const T_DOC_COMMENT;
use const T_DOUBLE_COLON;
use const T_ENCAPSED_AND_WHITESPACE;
use const T_ENUM;
use const T_EXTENDS;
use const T_FILE;
use const T_FN;
use const T_FUNC_C;
use const T_FUNCTION;
use const T_IMPLEMENTS;
use const T_INLINE_HTML;
use const T_INTERFACE;
use const T_IS_EQUAL;
use const T_IS_GREATER_OR_EQUAL;
use const T_IS_IDENTICAL;
use const T_IS_NOT_EQUAL;
use const T_IS_NOT_IDENTICAL;
use const T_IS_SMALLER_OR_EQUAL;
use const T_LINE;
use const T_LNUMBER;
use const T_METHOD_C;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAME_RELATIVE;
use const T_NAMESPACE;
use const T_NEW;
use const T_NS_C;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;
use const T_OPEN_TAG;
use const T_OPEN_TAG_WITH_ECHO;
use const T_SPACESHIP;
use const T_STRING;
use const T_TRAIT;
use const T_TRAIT_C;
use const T_USE;
use const T_VARIABLE;
use const T_WHITESPACE;
use function count;
use function explode;
use function is_array;
use function strrpos;
use function substr;
use function token_get_all;
use function token_name;


class Tokens
{
   // * Config
   public const AS_TOKEN_GROUP = 0; // 
   public const AS_TOKEN_ID = 1;
   public const AS_TOKEN_NAME = 2;

   // * Metadata
   // @ Groups
   public const TOKEN_DEFAULT = 'token_default';
   public const TOKEN_PATH = 'token_path';
   public const TOKEN_CLASS = 'token_class';

   public const TOKEN_VARIABLE = 'token_variable';
   public const TOKEN_PROPERTY = 'token_property';
   public const TOKEN_NUMBER = 'token_number';

   public const TOKEN_DECLARATION = 'token_declaration';
   public const TOKEN_ACCESS = 'token_access';
   public const TOKEN_OPERATOR = 'token_operator';
   public const TOKEN_PONTUATION = 'token_pontuation';
   public const TOKEN_DELIMITER = 'token_delimiter';

   public const TOKEN_FUNCTION = 'token_function';
   public const TOKEN_COMMENT = 'token_comment';
   public const TOKEN_STRING = 'token_string';
   public const TOKEN_HTML = 'token_html';

   public const TOKEN_KEYWORD = 'token_keyword';


   /**
    * Tokenize a source code
    * 
    * @param string $source
    * @param int $fallback
    *
    * @return array<int,array<int,array<int,int|string|null>>>
    */
   public function tokenize (string $source, int $fallback = self::AS_TOKEN_GROUP): array
   {
      // * Data
      $tokens = token_get_all($source);
      $count = count($tokens);

      // * Metadata
      $output = [];
      $token_buffer = '';
      $token_type_current = null;
      $token_type_new = null;
      $previous = null;

      // @
      for ($index = 0; $index < $count; $index++) {
         $token = $tokens[$index];

         if (is_array($token) === true) {
            $token_type_new = match ($token[0]) {
               T_WHITESPACE => null, # 392

               T_STRING, # 262
               T_NAME_FULLY_QUALIFIED, # 263
               T_NAME_RELATIVE, # 264
               T_NAME_QUALIFIED, # 265
               T_LINE, # 343
               T_FILE, # 344
               T_DIR, # 345
               T_CLASS_C, # 346
               T_TRAIT_C, # 347
               T_NS_C, # 350
                  => self::TOKEN_DEFAULT, 

               // Operators
               T_IS_EQUAL, # 366
               T_IS_NOT_EQUAL, # 367
               T_IS_IDENTICAL, # 368
               T_IS_NOT_IDENTICAL, # 369
               T_IS_SMALLER_OR_EQUAL, # 370
               T_IS_GREATER_OR_EQUAL, # 371
               T_SPACESHIP, # 372
               T_COALESCE, # 400
                  => self::TOKEN_OPERATOR,

               T_LNUMBER, # 260
               T_DNUMBER, # 261
                  => self::TOKEN_NUMBER,

               T_VARIABLE, # 266
                  => self::TOKEN_VARIABLE,

               T_METHOD_C, # 348
               T_FUNC_C, # 349
                  => self::TOKEN_FUNCTION, 

               T_COMMENT, # 387
               T_DOC_COMMENT, # 388
                  => self::TOKEN_COMMENT,

               T_ENCAPSED_AND_WHITESPACE, # 268
               T_CONSTANT_ENCAPSED_STRING, # 269
                  => self::TOKEN_STRING,

               T_INLINE_HTML, # 267
                  => self::TOKEN_HTML,

               T_OBJECT_OPERATOR, # 388
               T_NULLSAFE_OBJECT_OPERATOR, # 389
               T_DOUBLE_COLON, # 401
                  => self::TOKEN_ACCESS,

               // Declarations / structure keywords
               T_NEW, # 284
               T_FUNCTION, # 310
               T_FN, # 311
               T_CONST, # 312
               T_USE, # 318
               T_CLASS, # 336
               T_TRAIT, # 337
               T_INTERFACE, # 338
               T_ENUM, # 339
               T_EXTENDS, # 340
               T_IMPLEMENTS, # 341
               T_NAMESPACE, # 342
                  => self::TOKEN_DECLARATION,

               T_OPEN_TAG, # 389
               T_OPEN_TAG_WITH_ECHO, # 390
               T_CLOSE_TAG, # 391
                  => null,

               #\T_FUNCTION, # 310
               default => false
            };

            if ($token_type_new === false) {
               $token_type_new = match ($fallback) {
                  self::AS_TOKEN_ID => $token[0],
                  self::AS_TOKEN_NAME => token_name($token[0]),
                  default => self::TOKEN_KEYWORD
               };
            }

            // ? Name resolution — instantiations, member accesses and calls differentiate
            $resolved = $token_type_new;
            if ($token_type_new === self::TOKEN_DEFAULT) {
               // Call lookahead — a name directly before `(` paints as a function
               $next = $index + 1;
               while (
                  $next < $count
                  && is_array($tokens[$next]) === true
                  && $tokens[$next][0] === T_WHITESPACE
               ) {
                  $next++;
               }
               $called = ($tokens[$next] ?? null) === '(';

               $resolved = match (true) {
                  $previous === T_NEW
                     => self::TOKEN_CLASS,
                  $previous === T_OBJECT_OPERATOR
                  || $previous === T_NULLSAFE_OBJECT_OPERATOR
                  || $previous === T_DOUBLE_COLON
                     => $called === true ? self::TOKEN_FUNCTION : self::TOKEN_PROPERTY,
                  $called === true
                     => self::TOKEN_FUNCTION,
                  default => self::TOKEN_DEFAULT
               };
            }

            // ? Qualified names split at the last separator — namespace path + final node
            $position = match ($token[0]) {
               T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NAME_QUALIFIED
                  => strrpos($token[1], '\\'),
               default => false
            };
            $parts = ($position === false
               ? [[$resolved, $token[1]]]
               : [
                  [self::TOKEN_PATH, substr($token[1], 0, $position + 1)],
                  [$resolved, substr($token[1], $position + 1)]
               ]
            );
         }
         else {
            $token_type_new = match ($token) {
               ',', ';', '=' => self::TOKEN_PONTUATION,
               '(', ')', '[', ']' => self::TOKEN_DELIMITER,
               '"' => self::TOKEN_STRING,
               default => self::TOKEN_KEYWORD
            };
            $parts = [[$token_type_new, $token]];
         }

         // @ Track the previous significant token — whitespace and comments are transparent
         if (is_array($token) === false) {
            $previous = $token;
         }
         else if (
            $token[0] !== T_WHITESPACE
            && $token[0] !== T_COMMENT
            && $token[0] !== T_DOC_COMMENT
         ) {
            $previous = $token[0];
         }

         // @@ Coalesce consecutive same-type parts into segments
         foreach ($parts as $part) {
            [$token_type_new, $token_text] = $part;

            if ($token_type_current === null) {
               $token_type_current = $token_type_new;
            }
            if ($token_type_current !== $token_type_new) {
               $output[] = [$token_type_current, $token_buffer];
               $token_buffer = '';
               $token_type_current = $token_type_new;
            }
            $token_buffer .= $token_text;
         }
      }
      // ? Flush the trailing buffer — null-typed segments (whitespace, tags) included
      if ($token_buffer !== '') {
         $output[] = [$token_type_current, $token_buffer];
      }

      // @ Split to lines
      $lines = [];
      $line = [];
      foreach ($output as $token) {
         $token_type = $token[0];
         $token_content = $token[1];
         foreach (explode("\n", $token_content) as $line_count => $token_line) {
            if ($line_count > 0) {
               $lines[] = $line;
               $line = [];
            }
            if ($token_line === '') continue;
            $line[] = [$token_type, $token_line];
         }
      }
      $lines[] = $line;

      return $lines;
   }
}
