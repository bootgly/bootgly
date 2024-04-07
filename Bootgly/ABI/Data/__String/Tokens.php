<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\__String;


class Tokens
{
   // * Config
   public const AS_TOKEN_GROUP = 0; // 
   public const AS_TOKEN_ID = 1;
   public const AS_TOKEN_NAME = 2;

   // * Metadata
   // @ Groups
   public const TOKEN_DEFAULT = 'token_default';

   public const TOKEN_VARIABLE = 'token_variable';
   public const TOKEN_NUMBER = 'token_number';

   public const TOKEN_NEW = 'token_new';
   public const TOKEN_ACCESS = 'token_access';
   public const TOKEN_OPERATOR = 'token_operator';
   public const TOKEN_PONTUATION = 'token_pontuation';
   public const TOKEN_DELIMITER = 'token_delimiter';

   public const TOKEN_FUNCTION = 'token_function';
   public const TOKEN_SPREAD = 'token_spread';
   public const TOKEN_COMMENT = 'token_comment';
   public const TOKEN_STRING = 'token_string';
   public const TOKEN_HTML = 'token_html';

   public const TOKEN_KEYWORD = 'token_keyword';


   public function tokenize (string $source, int $fallback = self::AS_TOKEN_GROUP) : array
   {
      // * Data
      $tokens = \token_get_all($source);

      // * Metadata
      $output = [];
      $token_buffer = '';
      $token_type_current = null;
      $token_type_new = null;

      // @
      foreach ($tokens as $token) {
         if (\is_array($token) === true) {
            $token_type_new = match ($token[0]) {
               \T_WHITESPACE => null, # 392

               \T_STRING, # 262
               \T_NAME_FULLY_QUALIFIED, # 263
               \T_LINE, # 343
               \T_FILE, # 344
               \T_DIR, # 345
               \T_CLASS_C, # 346
               \T_TRAIT_C, # 347
               \T_NS_C, # 350
                  => self::TOKEN_DEFAULT, 

               // Operators
               \T_IS_EQUAL, # 366
               \T_IS_NOT_EQUAL, # 367
               \T_IS_IDENTICAL, # 368
               \T_IS_NOT_IDENTICAL, # 369
               \T_IS_SMALLER_OR_EQUAL, # 370
               \T_IS_GREATER_OR_EQUAL, # 371
               \T_SPACESHIP, # 372
               \T_COALESCE, # 400
                  => self::TOKEN_OPERATOR,

               \T_LNUMBER, # 260
               \T_DNUMBER, # 261
                  => self::TOKEN_NUMBER,

               \T_VARIABLE, # 266
                  => self::TOKEN_VARIABLE,

               \T_METHOD_C, # 348
               \T_FUNC_C, # 349
                  => self::TOKEN_FUNCTION, 

               \T_COMMENT, # 387
               \T_DOC_COMMENT, # 388
                  => self::TOKEN_COMMENT,

               \T_ENCAPSED_AND_WHITESPACE, # 268
               \T_CONSTANT_ENCAPSED_STRING, # 269
                  => self::TOKEN_STRING,

               \T_INLINE_HTML, # 267
                  => self::TOKEN_HTML,

               \T_OBJECT_OPERATOR, # 384
                  => self::TOKEN_ACCESS,

               \T_OPEN_TAG, # 389
               \T_OPEN_TAG_WITH_ECHO, # 390
               \T_CLOSE_TAG, # 391
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
         } else {
            $token_type_new = match ($token) {
               ',', ';' => self::TOKEN_PONTUATION,
               '(', ')', '[', ']' => self::TOKEN_DELIMITER,
               '"' => self::TOKEN_STRING,
               default => self::TOKEN_KEYWORD
            };
         }
         if ($token_type_current === null) {
            $token_type_current = $token_type_new;
         }
         if ($token_type_current !== $token_type_new) {
            $output[] = [$token_type_current, $token_buffer];
            $token_buffer = '';
            $token_type_current = $token_type_new;
         }
         $token_buffer .= (\is_array($token)
            ? $token[1]
            : $token
         );
      }
      if (isSet($token_type_new)) {
         $output[] = [$token_type_new, $token_buffer];
      }

      // @ Split to lines
      $lines = [];
      $line = [];
      foreach ($output as $token) {
         $token_type = $token[0];
         $token_content = $token[1];
         foreach (\explode("\n", $token_content) as $line_count => $token_line) {
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
