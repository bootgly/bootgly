<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Field;


use Bootgly\CLI\Terminal\Output;


class Field
{
   private Output $Output;

   // * Config
   // @ Style
   public string $color;
   public const DEFAULT_BORDERS = [
      'top'          => '─',
      'top-left'     => '┌',
      'top-right'    => '┐',

      'left'         => '│',
      'right'        => '│',

      'bottom'       => '─',
      'bottom-left'  => '└',
      'bottom-right' => '┘',
   ];
   public array $borders;
   // * Data
   protected ? string $title;
   protected ? string $content;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->title = null;
      // @ Style
      $this->color = '@#Black:';
      $this->borders = self::DEFAULT_BORDERS;
      // * Data
      $this->content = null;
   }
   public function __set ($name, $value)
   {
      // TODO emit event
      match ($name) {
         'content' => $this->content = $value,
         'title'   => $this->title = ($value
            ? " $value "
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

            $line = str_repeat('─', $length);

            $Output->render(<<<OUTPUT
            {$color}{$border_top_left}{$title}{$line}{$border_top_right}@;\n
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

            $Output->render(<<<OUTPUT
             {$color}{$border_right}@;\n
            OUTPUT);

            break;
         case 'bottom':
            $border_bottom_left = $borders['bottom-left'];
            $border_bottom = $borders['bottom'];
            $border_bottom_right = $borders['bottom-right'];

            $line = str_repeat($border_bottom, $length);

            $Output->render(<<<OUTPUT
            {$color}{$border_bottom_left}{$line}{$border_bottom_right}\n
            OUTPUT);
            break;
      }
   }
   public function render (? string $content = null)
   {
      $Output = $this->Output;
      $Text = $Output->Text;

      // * Meta
      $title_length = mb_strlen($this->title);
      $content_lines = explode(PHP_EOL, $content ?? $this->content);

      // @ Determine max line length (based on content line and title)
      $line_length = $title_length;
      foreach ($content_lines as $content_line) {
         $content_line_length = mb_strlen($content_line);
         if ($content_line_length > $line_length) {
            $line_length = $content_line_length;
         }
      }

      $this->border('top', ($line_length - $title_length) + 2);
      // @ Render content lines
      foreach ($content_lines as $content_line) {
         $this->border('left');
         $Output->pad($content_line, $line_length);
         $this->border('right');
      }
      $this->border('bottom', $line_length + 2);

      // @ Reset Text
      $Text->stylize();
   }
}
