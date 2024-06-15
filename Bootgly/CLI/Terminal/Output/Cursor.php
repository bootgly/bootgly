<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Output;


use Bootgly\ABI\Data\__String\Escapeable\Cursor\Positionable;
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Visualizable;
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Shapeable;

use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;


class Cursor
{
   use Positionable;
   use Visualizable;
   use Shapeable;


   private Output $Output;

   // * Metadata
   private bool $hidden;
   private array $position;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;

      // * Metadata
      $this->hidden = false;
   }

   public function __get (string $name)
   {
      switch ($name) {
         // TODO test/add more methods to retrieve the current cursor position
         // * Metadata
         case 'hidden':
            return $this->hidden;
         case 'position':
            if (! \function_exists('shell_exec') ) {
               return [];
            }

            // Run stty command to get cursor position
            $output = \shell_exec('stty -g');
            // Disable canonical mode and echo
            \shell_exec('stty -echo -icanon -icrnl');

            // Send ANSI code to retrieve cursor position
            $this->Output->escape(self::_CURSOR_REPORT_POSITION);

            // Read response from terminal
            $input = \fread(STDIN, 15);
            // Parse cursor position from response
            \preg_match('/\x1b\[(\d+);(\d+)R/', $input, $matches);

            // Restore terminal settings
            \shell_exec(sprintf('stty %s', $output));

            $row = \intval(@$matches[1]);
            $column = \intval(@$matches[2]);

            return [
               $row,
               $column,

               'row' => $row,
               'column' => $column
            ];
      }
   }

   // @ Positionable
   // Moving
   public function up (int $lines, ? int $column = null) : Output
   {
      if ($column > 1 || $column < 0) {
         $this->moveTo(null, $column);
      }

      return match ($column) {
         1 => $this->Output->escape($lines . self::_CURSOR_PREVIOUS_LINE),
         default => $this->Output->escape($lines . self::_CURSOR_UP)
      };
   }
   public function right (int $columns) : Output
   {
      return $this->Output->escape($columns . self::_CURSOR_RIGHT);
   }
   public function down (int $lines, ? int $column = null) : Output
   {
      if ($column > 1 || $column < 0) {
         $this->moveTo(null, $column);
      }

      return match ($column) {
         1 => $this->Output->escape($lines . self::_CURSOR_NEXT_LINE),
         default => $this->Output->escape($lines . self::_CURSOR_DOWN)
      };
   }
   public function left (int $columns) : Output
   {
      return $this->Output->escape($columns . self::_CURSOR_LEFT);
   }

   public function moveTo (? int $line = null, ? int $column = null) : Output
   {
      if ($line === null && $column >= 0) {
         return $this->Output->escape($column . self::_CURSOR_LEFT_ABSOLUTE);
      }
      if ($line === null && $column < 0) {
         $c = $column + Terminal::$columns;
         return $this->Output->escape($c . self::_CURSOR_LEFT_ABSOLUTE);
      }

      if ($column === null && $line >= 0) {
         return $this->Output->escape($line . self::_CURSOR_UP_ABSOLUTE);
      }
      if ($column === null && $line < 0) {
         $l = $line + Terminal::$lines;
         return $this->Output->escape($l . self::_CURSOR_UP_ABSOLUTE);
      }

      return $this->Output->escape($line . ';' . $column . self::_CURSOR_POSITION);
   }

   // Memorizing
   public function save ()
   {
      return $this->Output->escape(self::_CURSOR_SAVED);
   }
   public function restore ()
   {
      return $this->Output->escape(self::_CURSOR_RESTORED);
   }

   // Reportable
   /**
    * Emit the cursor position as: ESC [ <r> ; <c> R Where <r> = cursor row and <c> = cursor column
    */
   public function report ()
   {
      return $this->Output->escape(self::_CURSOR_REPORT_POSITION);
   }

   // @ Shapeable
   public function shape (? string $style = '@user')
   {
      return match ($style) {
         'block' => $this->Output->escape(self::_CURSOR_BLINKING_BLOCK_SHAPE),

         'underline' => $this->Output->escape(self::_CURSOR_BLINKING_UNDERLINE_SHAPE),

         'bar' => $this->Output->escape(self::_CURSOR_BLINKING_BAR_SHAPE),

         default => $this->Output->escape(self::_CURSOR_USER_SHAPE)
      };
   }

   // @ Visualizable
   public function blink (bool $status) : Output
   {
      return match ($status) {
         false => $this->Output->escape(self::_CURSOR_BLINKING_DISABLED),
         true => $this->Output->escape(self::_CURSOR_BLINKING_ENABLED),
      };
   }

   public function show () : Output
   {
      $this->hidden = false;
      return $this->Output->escape(self::_CURSOR_VISIBLE);
   }
   public function hide () : Output
   {
      $this->hidden = true;
      return $this->Output->escape(self::_CURSOR_HIDDEN);
   }


   function __destruct()
   {
      $this->shape();
      $this->show();
   }
}
