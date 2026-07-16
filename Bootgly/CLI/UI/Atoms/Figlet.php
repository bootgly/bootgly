<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Atoms;


use function array_fill;
use function count;
use function explode;
use function implode;
use function is_string;
use function max;
use function mb_str_split;
use function mb_strlen;
use function str_repeat;
use function strtoupper;
use ValueError;

use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


/**
 * Figlet — large block-drawing text (banners, scores, clocks). Glyphs come
 * from a named figlet font: A-Z and 0-9 ship in the builtin `shadow` font
 * (absorbed from the retired Header component). Characters without a glyph
 * render as spaces — the art never crashes on user input.
 */
class Figlet extends Component
{
   /** Synthetic space glyph width, in columns */
   private const int SPACE = 3;


   private Output $Output;

   // * Config
   /**
    * Named figlet fonts — a glyph map (char => multiline art) or a PHP file
    * path returning one. Register a new font here, then select it by name.
    *
    * @var array<string,string|array<string,string>>
    */
   public static array $Fonts = [
      'shadow' => __DIR__ . '/Figlet/fonts/shadow.php'
   ];
   /** Named figlet font — resolved from the registry */
   public string $font;
   /** Stack one glyph block per character instead of composing side by side */
   public bool $stacked;
   /** Columns between side-by-side glyphs */
   public string $gap;

   // * Data
   /** The text to enlarge — lowercase maps to the uppercase glyphs */
   public string $text;

   // * Metadata
   /** @var array<string,array<int,string>> Resolved glyphs — char => padded rows */
   private array $glyphs;
   /** Rows per glyph in the resolved font */
   private int $rows;
   /** The resolved font name — guards the lazy resolve */
   private string $resolved;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->font = 'shadow';
      $this->stacked = false;
      $this->gap = ' ';

      // * Data
      $this->text = '';

      // * Metadata
      $this->glyphs = [];
      $this->rows = 0;
      $this->resolved = '';
   }

   /**
    * Render the text as large glyph art.
    *
    * @param int $mode `WRITE_OUTPUT` writes to the Output; `RETURN_OUTPUT` returns the string.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // !
      $this->resolve();

      $chars = mb_str_split(strtoupper($this->text));
      $space = $this->glyphs[' '];

      // @ Compose
      $output = '';
      if ($this->stacked === true) {
         // @@ One glyph block per character
         foreach ($chars as $char) {
            $glyph = $this->glyphs[$char] ?? $space;
            $output .= implode("\n", $glyph) . "\n";
         }
      }
      else {
         // @@ Side by side — one output row per glyph row
         for ($row = 0; $row < $this->rows; $row++) {
            $line = [];
            foreach ($chars as $char) {
               $glyph = $this->glyphs[$char] ?? $space;
               $line[] = $glyph[$row];
            }

            $output .= implode($this->gap, $line) . "\n";
         }
      }

      // ?: Return
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $output;
      }

      // @ Write
      $this->Output->write($output);

      // :
      return null;
   }

   /**
    * Resolve the selected font — load the glyph map, pad every glyph to its
    * own width and synthesize the space glyph. Cached per font name.
    */
   private function resolve (): void
   {
      // ?
      if ($this->resolved === $this->font) {
         return;
      }

      // ! Font source — a glyph map or a PHP file returning one
      $source = self::$Fonts[$this->font]
         ?? throw new ValueError("Unknown figlet font: `{$this->font}`");
      /** @var array<string,string> $art */
      $art = is_string($source) === true ? (require $source) : $source;

      // @@ Pad every glyph row to the glyph width (columns stay aligned)
      $glyphs = [];
      $widths = [];
      $height = 0;
      foreach ($art as $char => $block) {
         $rows = explode("\n", $block);

         $width = 0;
         foreach ($rows as $row) {
            $width = max($width, mb_strlen($row));
         }
         foreach ($rows as $index => $row) {
            $rows[$index] = $row . str_repeat(' ', $width - mb_strlen($row));
         }

         $glyphs[(string) $char] = $rows;
         $widths[(string) $char] = $width;
         $height = max($height, count($rows));
      }
      // @@ Pad every glyph to the font height (short glyphs gain blank rows)
      foreach ($glyphs as $char => $rows) {
         while (count($rows) < $height) {
            $rows[] = str_repeat(' ', $widths[$char]);
         }

         $glyphs[$char] = $rows;
      }

      // ! Space — synthetic, never trimmed away by editors
      $glyphs[' '] ??= array_fill(0, $height, str_repeat(' ', self::SPACE));

      // * Metadata
      $this->glyphs = $glyphs;
      $this->rows = $height;
      $this->resolved = $this->font;
   }
}
