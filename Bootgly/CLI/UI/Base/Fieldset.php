<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Base;


use function explode;
use function max;
use function mb_strlen;
use function preg_replace;
use function str_repeat;

use Bootgly\ABI\Data\__String;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


/**
 * Fieldset — a bordered box around markup content, with an optional title
 * embedded in the top border. Frames are composed as raw strings (cursor-free),
 * so hosts can embed, reposition or repaint them.
 */
class Fieldset extends Component
{
   private Output $Output;

   // * Config
   // # Dimension
   /** Inner content columns — `null` derives from the widest content/title line */
   public null|int $width;
   // # Style
   /** Border color (markup token) */
   public string $color;
   public const array DEFAULT_BORDERS = [
      'top'          => '─',
      'top-left'     => '┌',
      'top-right'    => '┐',

      'mid'          => '─',
      'left'         => '│',
      'right'        => '│',

      'bottom'       => '─',
      'bottom-left'  => '└',
      'bottom-right' => '┘',
   ];
   /** @var array<string,string> */
   public array $borders;

   // * Data
   public null|string $title = null {
      get {
         return $this->title;
      }
      set {
         $this->title = ($value
            ? TemplateEscaped::render(" $value ")
            : ''
         );
      }
   }
   public null|string $content;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      // # Dimension
      $this->width = null;
      // # Style
      $this->color = '@#Black:';
      $this->borders = self::DEFAULT_BORDERS;

      // * Data
      $this->title = null;
      $this->content = null;
   }


   /**
    * Render the fieldset box.
    *
    * @param int $mode `WRITE_OUTPUT` writes the box to the Output;
    *                  `RETURN_OUTPUT` returns the raw frame instead.
    *
    * @return null|string The raw frame on `RETURN_OUTPUT`; `null` otherwise.
    */
   public function render (int $mode = self::WRITE_OUTPUT): null|string
   {
      // !
      $edge = TemplateEscaped::render($this->color);
      $reset = TemplateEscaped::render('@;');
      $borders = $this->borders;

      $title = $this->title ?? '';
      $entitled = $this->measure($title);

      // ! Resolve the content markup upfront — `@---;` lines stay separators
      $lines = explode("\n", TemplateEscaped::render($this->content ?? ''));

      // ? Inner width — explicit, or derived from the widest line/title
      $inner = $this->width;
      if ($inner === null) {
         $inner = $entitled;
         foreach ($lines as $line) {
            $length = $this->measure($line);
            if ($length > $inner) {
               $inner = $length;
            }
         }

         $this->width = $inner;
      }

      // @ Frame
      // # Top border — the title interrupts it
      $frame = "{$edge}{$borders['top-left']}{$reset}{$title}{$edge}"
         . str_repeat($borders['top'], max(0, $inner - $entitled + 2))
         . "{$borders['top-right']}{$reset}\n";

      // # Content rows
      foreach ($lines as $line) {
         // ? Separator row
         if ($line === '@---;') {
            $frame .= "{$edge}{$borders['left']}{$reset} "
               . str_repeat($borders['mid'], $inner)
               . " {$edge}{$borders['right']}{$reset}\n";

            continue;
         }

         $padding = str_repeat(' ', max(0, $inner - $this->measure($line)));
         $frame .= "{$edge}{$borders['left']}{$reset} {$line}{$padding} {$edge}{$borders['right']}{$reset}\n";
      }

      // # Bottom border
      $frame .= "{$edge}{$borders['bottom-left']}"
         . str_repeat($borders['bottom'], $inner + 2)
         . "{$borders['bottom-right']}{$reset}\n";

      // ?: Return — raw frame, the host positions it
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $frame;
      }

      // @ Write
      $this->Output->write($frame);

      // :
      return null;
   }

   /**
    * Measure the visible columns of a resolved line (escapes occupy none).
    */
   private function measure (string $line): int
   {
      // :
      return mb_strlen((string) preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $line));
   }
}
