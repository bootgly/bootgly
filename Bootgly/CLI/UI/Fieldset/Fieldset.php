<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Fieldset;


use Bootgly\ABI\Data\__String;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;

use Bootgly\API\Component;

use Bootgly\CLI\Terminal\Output;


class Fieldset extends Component
{
   private Output $Output;

   // * Config
   // @ Dimension
   public ? int $width;
   // @ Style
   // Color
   public string $color;
   // Border
   public const DEFAULT_BORDERS = [
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
   public array $borders;
   // * Data
   protected ? string $title;
   public ? string $content;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      // @ Dimension
      $this->width = null;
      // @ Style
      $this->color = '@#Black:';
      $this->borders = self::DEFAULT_BORDERS;
      // * Data
      $this->title = null;
      $this->content = null;
   }
   public function __set ($name, $value)
   {
      // TODO emit event
      match ($name) {
         'title'   => $this->title = ($value
            ? TemplateEscaped::render(" $value ")
            : ''
         ),
         default   => null
      };
   }

   public function border (string $position, ? int $length = null)
   {
      $Output = $this->Output;

      $color = $this->color;
      $borders = $this->borders;

      switch ($position) {
         case 'top':
            $title = $this->title;

            $border_top_left = $borders['top-left'];
            $border_top_right = $borders['top-right'];

            $line = \str_repeat('─', $length);

            $Output->render(<<<OUTPUT
            {$color}{$border_top_left}@;{$title}{$color}{$line}{$border_top_right}@;\n
            OUTPUT);

            break;
         case 'left':
            $border_left = $borders['left'];

            $Output->render(<<<OUTPUT
            {$color}{$border_left}@; 
            OUTPUT);

            break;
         case 'right':
            $border_right = $borders['right'];

            if ($length) {
               $Output->Cursor->moveTo(null, $length + 3);
            }

            $Output->render(<<<OUTPUT
             {$color}{$border_right}@;\n
            OUTPUT);

            break;
         case 'bottom':
            $border_bottom_left = $borders['bottom-left'];
            $border_bottom = $borders['bottom'];
            $border_bottom_right = $borders['bottom-right'];

            $line = \str_repeat($border_bottom, $length);

            $Output->render(<<<OUTPUT
            {$color}{$border_bottom_left}{$line}{$border_bottom_right}\n
            OUTPUT);
            break;
      }
   }
   public function separate (int $length)
   {
      $Output = $this->Output;

      $Output->write($this->borders['mid'], $length);
   }

   public function render (int $mode = self::WRITE_OUTPUT)
   {
      $Output = $this->Output;
      $Text = $Output->Text;

      // * Config
      // @ Dimension
      $width = $this->width;
      // * Data
      $title = $this->title ?? '';
      $content = $this->content ?? '';
      // * Metadata
      $title_length = \mb_strlen(
         \preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $title)
      );
      // ---
      $content = TemplateEscaped::render($content);
      $content_lines = \explode("\n", $content);

      $line_length = $width;
      if ($line_length === null) {
         // @ Determine max line length (based on content line and title)
         $line_length = $title_length;
         foreach ($content_lines as $content_line) {
            $content_line_length = \mb_strlen($content_line);
            if ($content_line_length > $line_length) {
               $line_length = $content_line_length;
            }
         }
      }

      $this->border('top', ($line_length - $title_length) + 2);
      // @ Render content lines
      foreach ($content_lines as $content_line) {
         $this->border('left');

         match ($content_line) {
            '@---;' => $this->separate($line_length),
            default => ($width !== null
               ? $Output->write($content_line)
               : $Output->pad($content_line, $line_length)
            )
         };

         $this->border('right', $width);
      }
      $this->border('bottom', $line_length + 2);

      // @ Reset Text
      $Text->stylize();
   }
}
