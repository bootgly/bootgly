<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\cursor;


use Bootgly\CLI;


trait Positioning
{
   // * Meta
   // ! Moving
   // ? Vertical (<l>) "<l> = line"
   // [<n>A] (Cursor Up) "Cursor up by <n>"
   public const _CURSOR_UP = 'A';
   // [<n>B] (Cursor Down) "Cursor down by <n>"
   public const _CURSOR_DOWN = 'B';

   // [<l>d] (Cursor Up Absolute)
   public const _CURSOR_UP_ABSOLUTE = 'd';

   // ? Horizontal (<c>) "<c> = column"
   // [<n>C] (Cursor Forward) "Cursor forward (right) by <n>"
   public const _CURSOR_RIGHT = 'C';
   // [<n>D] (Cursor Backward) "Cursor backward (left) by <n>"
   public const _CURSOR_LEFT = 'D';

   // [<n>E] (Cursor Next Line) "Cursor down <n> lines from current position"
   public const _CURSOR_NEXT = 'E';
   // [<n>F] (Cursor Previous Line) "Cursor up <n> lines from current position"
   public const _CURSOR_PREVIOUS = 'F';

   // [<c>G] (Cursor Left Absolute)
   public const _CURSOR_LEFT_ABSOLUTE = 'G';
   // ? Coordinate (<l>;<c>) "<c> = column, <l> = line"
   // [<l>;<c>H] (Cursor Position) "Cursor moves to <l>;<c> coordinate within the viewport"
   public const _CURSOR_POSITION = 'H';
   // [<l>;<c>H] (Cursor Position) "Cursor moves to <l>;<c> coordinate within the viewport"
   public const _CURSOR_HORIZONTAL_VERTICAL_POSITION = 'f';
   // ! Memorizing
   // [<n>s] (Save Cursor) "Save the current position of cursor to memory array"
   public const _CURSOR_SAVED = 's';
   // [<n>u] (Restore Cursor) "Retrieve the last saved cursor position and set it as the current position"
   public const _CURSOR_RESTORED = 'u';
}
