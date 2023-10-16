<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\__String\Tokens;


use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Data\__String\Theme;
use Bootgly\ABI\Data\__String\Tokens;


class Highlighter extends Tokens
{
   use Formattable;


   public Theme $Theme;

   public const ACTUAL_LINE_MARK = 'actual_line_mark';
   public const LINE_NUMBER = 'line_number';

   private const ARROW_SYMBOL = '▶'; // >,➜, ▶

   private const DELIMITER = '▕'; // |,▕

   private const NO_MARK = ' ';

   private const LINE_NUMBER_DIVIDER = 'line_divider';
   private const MARKED_LINE_NUMBER = 'marked_line';
   private const WIDTH = 3;

   private const DEFAULT_THEME = [
      'CLI' => [
         'values' => [
            self::TOKEN_STRING     => self::_GREEN_BRIGHT_FOREGROUND,
            self::TOKEN_COMMENT    => self::_BLACK_BRIGHT_FOREGROUND,
            self::TOKEN_FUNCTION   => self::_YELLOW_FOREGROUND,
            self::TOKEN_VARIABLE   => self::_CYAN_BRIGHT_FOREGROUND,
            self::TOKEN_NUMBER     => self::_YELLOW_FOREGROUND,

            self::TOKEN_OPERATOR   => self::_RED_BRIGHT_FOREGROUND,
            self::TOKEN_PONTUATION => self::_WHITE_FOREGROUND,
            self::TOKEN_DELIMITER  => self::_YELLOW_BRIGHT_FOREGROUND,

            self::TOKEN_HTML       => self::_CYAN_FOREGROUND,

            self::TOKEN_KEYWORD    => self::_CYAN_FOREGROUND,
            self::TOKEN_DEFAULT    => self::_RED_BRIGHT_FOREGROUND,

            self::ACTUAL_LINE_MARK    => [self::_BLINK_STYLE, self::_RED_FOREGROUND],
            self::LINE_NUMBER         => self::_BLACK_BRIGHT_FOREGROUND,
            self::MARKED_LINE_NUMBER  => self::_RED_BRIGHT_FOREGROUND,
            self::LINE_NUMBER_DIVIDER => self::_BLACK_BRIGHT_FOREGROUND,
         ]
      ]
   ];

   // * Config
   // * Data
   // * Meta
   // ...


   public function __construct (array $theme = self::DEFAULT_THEME)
   {
      // !
      if ($theme === self::DEFAULT_THEME) {
         $theme['CLI']['options'] = [
            'prepending' => [
               'type'  => 'callback',
               'value' => self::wrap(...)
            ],
            'appending' => [
               'type' => 'string',
               'value' => self::_RESET_FORMAT
            ]
         ];
      }
      // ---
      $Theme = new Theme;
      $Theme->add($theme);
      $Theme->select();

      $this->Theme = $Theme;

      // * Config
      // * Data
      // * Meta
      // ...
   }

   public function highlight (string $source, ? int $marked_line = null, int $lines_before = 4, int $lines_after = 4) : string
   {
      // <<
      $source = str_replace(["\r\n", "\r"], "\n", $source);
      $tokens = $this->tokenize($source);

      // |:|
      if ($marked_line !== null) {
         // @ Offset lines - x before and x after marked line number
         $offset = max($marked_line - $lines_before - 1, 0);
         $length = $lines_after + $lines_before + 1;
         $tokens = array_slice($tokens, $offset, $length, true);
      }

      // >
      $highlighted = $this->color($tokens);
      $highlighted = $this->number($highlighted, $marked_line);

      return $highlighted;
   }

   // @ Lines
   private function color (array $token_lines) : array
   {
      $lines = [];
      foreach ($token_lines as $index => $token_line) {
         $line_number = $index + 1;

         $line = '';
         foreach ($token_line as $token) {
            [$token_type, $token_value] = $token;
            $line .= $this->Theme->apply($token_type, $token_value);
         }
         $lines[$line_number] = $line;
      }
      return $lines;
   }
   private function number (array $lines, ? int $marked_line = null) : string
   {
      // * Config
      $mark = ' ' . self::ARROW_SYMBOL . ' ';
      // * Data
      $line_string_length = \strlen((string) ((int) array_key_last($lines) + 1));
      $line_string_length = ($line_string_length < self::WIDTH
         ? self::WIDTH
         : $line_string_length
      );
      // * Meta
      $output = '';

      foreach ($lines as $line_number => $line_content) {
         $number = (string) ($line_number);

         // @ Pad line
         $number = \str_pad(
            string: (string) $number,
            length: $line_string_length,
            pad_string: ' ',
            pad_type: STR_PAD_LEFT
         );

         // @ Mark target line
         if ($marked_line !== null) {
            // Marked symbol / No marked symbol
            $output .= ($marked_line === $line_number
               ? $this->Theme->apply(self::ACTUAL_LINE_MARK, $mark)
               : \str_repeat(self::NO_MARK, $line_string_length)
            );
            // Marked line number / No marked line number
            $number = ($marked_line === $line_number
               ? $this->Theme->apply(self::MARKED_LINE_NUMBER, $number)
               : $this->Theme->apply(self::LINE_NUMBER, $number)
            );
            // Marked line content / No marked line content
            /*
            $line = ($marked_line === $line_number
               ? self::wrap(self::_RED_BRIGHT_BACKGROUND) . $line . self::_RESET_FORMAT
               : $line
            );
            */
         }

         // Line number
         $output .= $number;
         // Line divider
         $output .= $this->Theme->apply(self::LINE_NUMBER_DIVIDER, self::DELIMITER);
         // Line content
         $output .= ' ' . $line_content . PHP_EOL;
      }

      return $output;
   }
}
