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
   public const TOKEN_DEFAULT = 'token_default';
   public const TOKEN_VARIABLE = 'token_variable';
   public const TOKEN_NUMBER = 'token_number';

   public const TOKEN_PONTUATION = 'token_pontuation';
   public const TOKEN_DELIMITERS = 'token_delimiters';

   public const TOKEN_FUNCTION = 'token_function';
   public const TOKEN_COMMENT = 'token_comment';
   public const TOKEN_STRING = 'token_string';
   public const TOKEN_HTML = 'token_html';
   public const TOKEN_KEYWORD = 'token_keyword';


   public function tokenize (string $source) : array
   {
      // * Data
      $tokens = \token_get_all($source);
      // * Meta
      $output = [];
      $token_buffer = '';
      $token_type_current = null;
      $token_type_new = null;

      foreach ($tokens as $token) {
         if (\is_array($token)) {
            $token_type_new = match ($token[0]) {
               \T_WHITESPACE => null,

               \T_STRING,
               \T_NAME_FULLY_QUALIFIED,
               \T_DIR,
               \T_FILE,
               \T_NS_C,
               \T_LINE,
               \T_CLASS_C,
               \T_TRAIT_C => self::TOKEN_DEFAULT,

               \T_LNUMBER,
               \T_DNUMBER => self::TOKEN_NUMBER,

               \T_VARIABLE => self::TOKEN_VARIABLE,

               \T_METHOD_C,
               \T_FUNC_C => self::TOKEN_FUNCTION,

               \T_COMMENT,
               \T_DOC_COMMENT => self::TOKEN_COMMENT,

               \T_ENCAPSED_AND_WHITESPACE,
               \T_CONSTANT_ENCAPSED_STRING => self::TOKEN_STRING,

               \T_INLINE_HTML => self::TOKEN_HTML,

               \T_OPEN_TAG,
               \T_OPEN_TAG_WITH_ECHO,
               \T_CLOSE_TAG => null,

               default => self::TOKEN_KEYWORD,
            };
         } else {
            $token_type_new = match ($token) {
               ',', ';' => self::TOKEN_PONTUATION,
               '(', ')', '[', ']' => self::TOKEN_DELIMITERS,
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
