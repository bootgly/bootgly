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


use const BOOTGLY_TTY;
use function implode;
use function max;
use function mb_strlen;
use function preg_replace;
use function str_repeat;

use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;


/**
 * Statusbar — a single-row status bar: left segments separated by a divider,
 * right segments aligned to the edge (the Bubbles `help` footer equivalent —
 * keybinding hints are just segments). The bar renders as one raw row for
 * hosts that position it themselves (fixed last row, embedded panes) and
 * degrades to an escape-free row on non-interactive output.
 */
class Statusbar extends Component
{
   use Formattable;


   // ! ANSI escape sequence matcher (escape-aware measuring)
   private const string ANSI = '/\e\[[0-9;]*m/';


   private Output $Output;

   // * Config
   /** SGR decoration — null follows the TTY, false forces plain, true forces styled */
   public null|bool $decoration;
   /** @var array<int,string> Left segments, divider-separated */
   public array $left;
   /** @var array<int,string> Right segments, aligned to the edge */
   public array $right;
   /** Divider between the left segments */
   public string $divider;
   /** Bar width, in columns — null follows the terminal */
   public null|int $width;
   /** @var array<int,string> SGR codes painting the bar (background + foreground) */
   public array $style;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->decoration = null;
      $this->left = [];
      $this->right = [];
      $this->divider = '  ▏ ';
      $this->width = null;
      // 256-color dark gray background + bright white text — bright-black
      // (SGR 100) is theme-dependent and renders LIGHT gray in some themes
      $this->style = [
         self::_EXTENDED_BACKGROUND, '5', '236',
         self::_WHITE_BRIGHT_FOREGROUND
      ];
   }

   /**
    * Render the status bar row.
    *
    * @param int $mode `WRITE_OUTPUT` writes the row (+ newline) to the Output;
    *                  `RETURN_OUTPUT` returns the raw row for the host to position.
    *
    * @return null|string
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // !
      $plain = ($this->decoration ?? BOOTGLY_TTY) === false;
      $width = $this->width
         ?? (isSet(Terminal::$width) === true ? Terminal::$width : 80);

      // @ Segments
      $left = ' ' . implode($this->divider, $this->left);
      $right = $this->right === []
         ? ''
         : implode('  ', $this->right) . ' ';

      // @ Pad the gap so the right segments align to the edge (escape-aware)
      $gap = max(1, $width - $this->measure($left) - $this->measure($right));

      $bar = $left . str_repeat(' ', $gap) . $right;

      // ?: Plain — strip any embedded escapes, no bar paint
      $output = $plain === true
         ? (string) preg_replace(self::ANSI, '', $bar)
         : self::wrap(...$this->style) . $bar . self::_RESET_FORMAT;

      // ?: Return — raw row, the host positions it
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $output;
      }

      // @ Write
      $this->Output->write("{$output}\n");

      // :
      return null;
   }

   /**
    * Measure the visible columns of a segment string (escapes occupy none).
    */
   private function measure (string $string): int
   {
      return mb_strlen((string) preg_replace(self::ANSI, '', $string));
   }
}
