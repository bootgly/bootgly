<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components;


use Bootgly\__String;
use Bootgly\CLI\Terminal\components\Table\Columns;
use Bootgly\CLI\Terminal\components\Table\Row;
use Bootgly\CLI\Terminal\components\Table\Rows;
use Bootgly\CLI\Terminal\Output;


class Table
{
   private Output $Output;

   // * Config

   // * Data
   private $headers;
   private $footers;
   // @ Style
   protected array $borders;

   // * Meta
   // ! Cells
   private int $alignment;

   public Columns $Columns;

   public Row $Row;
   public Rows $Rows;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config

      // * Data
      $this->headers = [];
      $this->footers = [];
      // @ Style
      $this->borders = [
         'top'          => '═',
         'top-left'     => '╔',
         'top-mid'      => '╤',
         'top-right'    => '╗',

         'bottom'       => '═',
         'bottom-left'  => '╚',
         'bottom-mid'   => '╧',
         'bottom-right' => '╝',

         'mid'          => '─',
         'mid-left'     => '╟',
         'mid-mid'      => '┼',
         'mid-right'    => '╢',
         'middle'       => '│ ',

         'left'         => '║',
         'right'        => '║',
      ];

      // * Meta
      $this->columns = 0;
      // ! Cells
      $this->alignment = 1;


      $this->Columns = new Columns($this);

      $this->Row = new Row($this);
      $this->Rows = new Rows($this);
   }
   public function __get ($name)
   {
      return $this->$name;
   }
   public function __set ($name, $value)
   {
      switch ($name) {
         case 'alignment':
            $this->alignment = match ($value) {
               1, 'left' => 1,
               0, 'right' => 0,
               2, 'center' => 2,

               default => 1
            };

            break;
      }
   }
   public function __call ($name, $arguments)
   {
      return $this->$name(...$arguments);
   }

   // ! Header
   public function setHeaders (array $headers)
   {
      $this->headers = $headers;
   }
   // ! Body

   // ! Footer
   public function setFooters (array $footers)
   {
      $this->footers = $footers;
   }

   // @ Border
   private function printHorizontalLine (string $position)
   {
      $line = match ($position) {
         'top' => $this->borders['top-left'],
         'mid' => $this->borders['mid-left'],
         'bottom' => $this->borders['bottom-left']
      };

      foreach ($this->Columns->widths as $index => $width) {
         if ($index > 0) {
            $line .= match($position) {
               'top' => $this->borders['top-mid'],
               'mid' => $this->borders['mid-mid'],
               'bottom' => $this->borders['bottom-mid']
            };
         }

         $border = match($position) {
            'top' => $this->borders['top'],
            'mid' => $this->borders['mid'],
            'bottom' => $this->borders['bottom']
         };
         $line .= str_repeat($border, $width + 2);
      }

      $line .= match ($position) {
         'top' => $this->borders['top-right'],
         'mid' => $this->borders['mid-right'],
         'bottom' => $this->borders['bottom-right']
      };
      $line .= "\n";

      $this->Output->write($line);
   }

   public function render ()
   {
      // ! Columns
      // Calculate columns width and count
      $this->Columns->calculate(); // TODO rename???

      // ! Header
      if (count($this->headers) > 0) {
         $this->printHorizontalLine('top');
         $this->Rows->render([$this->headers]);
      }

      // ! Body
      $this->printHorizontalLine('top');
      $this->Rows->render();

      // ! Footer
      if (count($this->footers) > 0) {
         $this->printHorizontalLine('bottom');
         $this->Rows->render([$this->footers]);
      }

      $this->printHorizontalLine('bottom');
   }
}
