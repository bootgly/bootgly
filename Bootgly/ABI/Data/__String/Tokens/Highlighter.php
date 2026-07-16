<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\__String\Tokens;


use const PHP_EOL;
use const STR_PAD_LEFT;
use function array_key_last;
use function array_slice;
use function implode;
use function max;
use function str_contains;
use function str_pad;
use function str_repeat;
use function str_replace;
use function stripos;
use function strlen;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Data\__String\Theme;
use Bootgly\ABI\Data\__String\Tokens;


class Highlighter extends Tokens
{
   use Formattable;


   public Theme $Theme;
   public const ACTUAL_LINE_MARK = 'actual_line_mark';
   public const LINE_NUMBER = 'line_number';
   private const LINE_NUMBER_DIVIDER = 'line_divider';
   private const MARKED_LINE_NUMBER = 'marked_line';

   public const DEFAULT_THEME = [
      'CLI' => [
         'values' => [
            self::TOKEN_STRING     => self::_GREEN_BRIGHT_FOREGROUND,
            self::TOKEN_COMMENT    => self::_BLACK_BRIGHT_FOREGROUND,
            self::TOKEN_FUNCTION   => self::_YELLOW_FOREGROUND,
            self::TOKEN_VARIABLE   => self::_CYAN_BRIGHT_FOREGROUND,
            self::TOKEN_NUMBER     => self::_ORANGE_FOREGROUND,

            self::TOKEN_DECLARATION => self::_MAGENTA_BRIGHT_FOREGROUND,
            self::TOKEN_ACCESS     => self::_WHITE_FOREGROUND,
            self::TOKEN_OPERATOR   => self::_RED_BRIGHT_FOREGROUND,
            self::TOKEN_PONTUATION => self::_WHITE_FOREGROUND,
            self::TOKEN_DELIMITER  => self::_YELLOW_BRIGHT_FOREGROUND,

            self::TOKEN_HTML       => self::_CYAN_FOREGROUND,

            self::TOKEN_KEYWORD    => self::_CYAN_FOREGROUND,
            self::TOKEN_DEFAULT    => self::_RED_BRIGHT_FOREGROUND,
            self::TOKEN_PATH       => self::_WHITE_FOREGROUND,

            self::ACTUAL_LINE_MARK    => [self::_BLINK_STYLE, self::_RED_FOREGROUND],
            self::LINE_NUMBER         => self::_BLACK_BRIGHT_FOREGROUND,
            self::MARKED_LINE_NUMBER  => self::_RED_BRIGHT_FOREGROUND,
            self::LINE_NUMBER_DIVIDER => self::_BLACK_BRIGHT_FOREGROUND,
         ]
      ]
   ];
   // * Config
   private const ARROW_SYMBOL = '▶'; // >,➜, ▶
   private const DELIMITER = '▕'; // |,▕
   private const NO_MARK = ' ';
   private const WIDTH = 3;
   // * Data
   // ...
   // * Metadata
   // ...


   /** @param array<mixed> $theme */
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
      // * Metadata
      // ...
   }

   /**
    * Highlight a PHP source
    *
    * @param string $source Source code — sources without a PHP open tag are colorized as pure PHP.
    * @param null|int $marked_line Line to mark — windows the output around it.
    * @param int $lines_before Window lines before the marked line.
    * @param int $lines_after Window lines after the marked line.
    * @param bool $gutter Render the gutter (line numbers, divider, line marker).
    *                     When false, returns bare colored lines joined by "\n".
    *
    * @return string
    */
   public function highlight (
      string $source,
      null|int $marked_line = null,
      int $lines_before = 4,
      int $lines_after = 4,
      bool $gutter = true
   ): string
   {
      // <<
      $source = str_replace(["\r\n", "\r"], "\n", $source);

      // ? Sources without an open tag tokenize as inline HTML — prepend a
      // synthetic tag and drop its line so snippets colorize as pure PHP
      $prepended = stripos($source, '<?php') === false && str_contains($source, '<?=') === false;
      if ($prepended === true) {
         $source = "<?php\n{$source}";
      }

      $tokens = $this->tokenize($source);

      if ($prepended === true) {
         $tokens = array_slice($tokens, 1);
      }

      // |:|
      if ($marked_line !== null) {
         // @ Offset lines - x before and x after marked line number
         $offset = max($marked_line - $lines_before - 1, 0);
         $length = $lines_after + $lines_before + 1;
         $tokens = array_slice($tokens, $offset, $length, true);
      }

      // >
      $highlighted = $this->color($tokens);

      // ?: Gutterless — bare colored lines (fences, embeds)
      if ($gutter === false) {
         return implode("\n", $highlighted);
      }

      // :
      return $this->number($highlighted, $marked_line);
   }

   // @ Lines
   /**
    * Colorize tokens in lines
    * 
    * @param array<int,array<int,array<int,int|string|null>>> $token_lines
    *
    * @return array<int,string>
    */
   private function color (array $token_lines): array
   {
      $lines = [];
      foreach ($token_lines as $index => $token_line) {
         $line_number = $index + 1;

         $line = '';
         foreach ($token_line as $token) {
            [$token_type, $token_value] = $token;
            $line .= $this->Theme->apply((string) $token_type, (string) $token_value);
         }
         $lines[$line_number] = $line;
      }
      return $lines;
   }
   /**
    * Number lines
    * 
    * @param array<int,string> $lines
    * @param null|int $marked_line
    *
    * @return string
    */
   private function number (array $lines, null|int $marked_line = null): string
   {
      // * Config
      $mark = ' ' . self::ARROW_SYMBOL . ' ';
      // * Data
      $line_string_length = strlen((string) ((int) array_key_last($lines) + 1));
      $line_string_length = ($line_string_length < self::WIDTH
         ? self::WIDTH
         : $line_string_length
      );
      // * Metadata
      $output = '';

      foreach ($lines as $line_number => $line_content) {
         $number = (string) ($line_number);

         // @ Pad line
         $number = str_pad(
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
               : str_repeat(self::NO_MARK, $line_string_length)
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
