<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Output;


use Bootgly\CLI;

use Bootgly\CLI\Escaping\cursor\Positioning;
use Bootgly\CLI\Escaping\cursor\Visualizing;
use Bootgly\CLI\Escaping\cursor\Shaping;

use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;


class Cursor
{
   use Positioning;
   use Visualizing;
   use Shaping;


   private Output $Output;


   public function __construct (Output &$Output)
   {
      $this->Output = $Output;
   }

   // @ Positioning
   // Moving
   public function up (int $lines) : Output
   {
      $this->Output->write(CLI::_START_ESCAPE . $lines . self::_CURSOR_UP);

      return $this->Output;
   }
   public function right (int $columns) : Output
   {
      $this->Output->write(CLI::_START_ESCAPE . $columns . self::_CURSOR_RIGHT);

      return $this->Output;
   }
   public function down (int $lines) : Output
   {
      $this->Output->write(CLI::_START_ESCAPE . $lines . self::_CURSOR_DOWN);

      return $this->Output;
   }
   public function left (int $columns) : Output
   {
      $this->Output->write(CLI::_START_ESCAPE . $columns . self::_CURSOR_LEFT);

      return $this->Output;
   }

   public function moveTo (? int $line = null, ? int $column = null) : Output
   {
      if ($line === null && $column >= 0) {
         return $this->Output->write(CLI::_START_ESCAPE . $column . self::_CURSOR_LEFT_ABSOLUTE);
      }
      if ($line === null && $column < 0) {
         $c = $column + Terminal::$columns;
         return $this->Output->write(CLI::_START_ESCAPE . $c . self::_CURSOR_LEFT_ABSOLUTE);
      }

      if ($column === null && $line >= 0) {
         return $this->Output->write(CLI::_START_ESCAPE . $line . self::_CURSOR_UP_ABSOLUTE);
      }
      if ($column === null && $line < 0) {
         $l = $line + Terminal::$lines;
         return $this->Output->write(CLI::_START_ESCAPE . $l . self::_CURSOR_UP_ABSOLUTE);
      }

      return $this->Output->write(CLI::_START_ESCAPE . $line . $column . self::_CURSOR_POSITION);
   }

   public function advance (int $lines = 1)
   {
      return $this->Output->write(CLI::_START_ESCAPE . $lines . self::_CURSOR_NEXT_LINE);
   }
   public function back (int $lines = 1)
   {
      return $this->Output->write(CLI::_START_ESCAPE . $lines . self::_CURSOR_PREVIOUS_LINE);
   }
   // Memorizing
   public function save ()
   {
      return $this->Output->write(CLI::_START_ESCAPE . self::_CURSOR_SAVED);
   }
   public function restore ()
   {
      return $this->Output->write(CLI::_START_ESCAPE . self::_CURSOR_RESTORED);
   }

   // @ Visualizing
   public function blink (bool $status) : Output
   {
      return match ($status) {
         false => $this->Output->write(CLI::_START_ESCAPE . self::_CURSOR_BLINKING_DISABLED),
         true => $this->Output->write(CLI::_START_ESCAPE . self::_CURSOR_BLINKING_ENABLED),
      };
   }

   public function show () : Output
   {
      return $this->Output->write(CLI::_START_ESCAPE . self::_CURSOR_VISIBLE);
   }
   public function hide () : Output
   {
      return $this->Output->write(CLI::_START_ESCAPE . self::_CURSOR_HIDDEN);
   }

   // @ Shaping
   public function shape (? string $style = '@user')
   {
      return match ($style) {
         'block' => $this->Output->write(CLI::_START_ESCAPE . self::_CURSOR_BLINKING_BLOCK_SHAPE),

         'underline' => $this->Output->write(CLI::_START_ESCAPE . self::_CURSOR_BLINKING_UNDERLINE_SHAPE),

         'bar' => $this->Output->write(CLI::_START_ESCAPE . self::_CURSOR_BLINKING_BAR_SHAPE),

         default => $this->Output->write(CLI::_START_ESCAPE . self::_CURSOR_USER_SHAPE)
      };
   }
}
