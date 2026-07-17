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
use function str_ends_with;

use Bootgly\ABI;
use Bootgly\ABI\Differ\Outputs\Escaped;
use Bootgly\ABI\Differ\Outputs\SideBySide;
use Bootgly\ABI\Differ\Outputs\Unified;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;


/**
 * Diff view — renders the difference between two texts as colored terminal
 * output via the framework's native diff engine (ABI Differ): unified hunks
 * by default, side-by-side columns with line numbers and intra-line word
 * highlight when split, and plain (escape-free) degradation on
 * non-interactive output (set $decoration to force).
 */
class Differ extends Component
{
   private Output $Output;

   // * Config
   /** SGR decoration — null follows the TTY, false forces plain, true forces styled */
   public null|bool $decoration;
   /** Split view — false renders unified hunks, true renders side-by-side columns */
   public bool $split;
   /** @var positive-int Context lines around each hunk (unified view) */
   public int $context;
   /** View width, in columns (split view) — null follows the terminal */
   public null|int $width;
   /** Line-number gutter width, in digits (split view) */
   public int $gutter;
   /** Intra-line word highlight on paired changed lines (split view) */
   public bool $highlight;
   /** Label of the original side */
   public string $fromFile;
   /** Label of the new side */
   public string $toFile;

   // * Data
   /** @var list<string>|string The original text — string or list of lines */
   public array|string $from;
   /** @var list<string>|string The new text — string or list of lines */
   public array|string $to;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->decoration = null;
      $this->split = false;
      $this->context = 3;
      $this->width = null;
      $this->gutter = 4;
      $this->highlight = true;
      $this->fromFile = 'Original';
      $this->toFile = 'New';

      // * Data
      $this->from = '';
      $this->to = '';
   }

   /**
    * Renders the diff between the two texts.
    *
    * @param int $mode The render mode (WRITE_OUTPUT or RETURN_OUTPUT).
    *
    * @return null|string The rendered output when returning output.
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // ! Decoration — null follows the interactive TTY
      $decorated = ($this->decoration ?? BOOTGLY_TTY) === true;

      // ! View builder
      if ($this->split === true) {
         $width = $this->width
            ?? (isSet(Terminal::$width) === true ? Terminal::$width : 80);

         $View = new SideBySide(
            width: $width,
            gutter: $this->gutter,
            colored: $decorated,
            fromFile: $this->fromFile,
            toFile: $this->toFile,
            intraLineHighlight: $this->highlight
         );
      }
      else {
         $View = new Unified(
            header: "--- {$this->fromFile}\n+++ {$this->toFile}\n",
            numbered: true,
            context: $this->context
         );

         if ($decorated === true) {
            $View = new Escaped($View);
         }
      }

      // @
      $output = new ABI\Differ($View)->diff($this->from, $this->to);

      // ? Builders end with an EOL on content; keep the guarantee on empty edges
      if (str_ends_with($output, "\n") === false) {
         $output .= "\n";
      }

      // ?: Output as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $output;
      }

      // ! write, not render — diffed sources must never feed Template markup
      $this->Output->write($output);

      // :
      return null;
   }
}
