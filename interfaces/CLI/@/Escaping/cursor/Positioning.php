<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Escaping\cursor;


trait Positioning
{
   // ! Moving
   // ? Vertical (<l>) "<l> = line"
   /**
    * [\<n\>A] (Cursor Up) "Cursor up by \<n\>"
    */
   public const _CURSOR_UP = 'A';
   /**
    * [\<n\>B] (Cursor Down) "Cursor down by \<n\>"
    */
   public const _CURSOR_DOWN = 'B';

   /**
    * [\<l\>d] (Cursor Up Absolute)
    */
   public const _CURSOR_UP_ABSOLUTE = 'd';

   // ? Horizontal (<c>) "<c> = column"
   /**
    * [\<n\>C] (Cursor Forward) "Cursor forward (right) by \<n\>"
    */
   public const _CURSOR_RIGHT = 'C';
   /**
    * [\<n\>D] (Cursor Backward) "Cursor backward (left) by \<n\>"
    */
   public const _CURSOR_LEFT = 'D';

   /**
    * [\<n\>E] (Cursor Next Line) "Cursor down \<n\> lines from current position"
    */
   public const _CURSOR_NEXT_LINE = 'E';
   /**
    * [\<n\>F] (Cursor Previous Line) "Cursor up \<n\> lines from current position"
    */
   public const _CURSOR_PREVIOUS_LINE = 'F';

   /**
    * [\<c\>G] (Cursor Left Absolute)
    */
   public const _CURSOR_LEFT_ABSOLUTE = 'G';
   // ? Coordinate (<r>;<c>) "<c> = column, <r> = row"
   /**
    * [\<r\>;\<c\>H] (Cursor Position) "Cursor moves to <r>;<c> coordinate within the viewport"
    */
   public const _CURSOR_POSITION = 'H';
   /**
    * [\<r\>;\<c\>H] (Cursor Position) "Cursor moves to <r>;<c> coordinate within the viewport"
    */
   public const _CURSOR_HORIZONTAL_VERTICAL_POSITION = 'f';


   // ! Memorizing
   /**
    * [\<n\>s] (Save Cursor) "Save the current position of cursor to memory array"
    */
   public const _CURSOR_SAVED = 's';
   /**
    * [\<n\>u] (Restore Cursor) "Retrieve the last saved cursor position and set it as the current position"
    */
   public const _CURSOR_RESTORED = 'u';


   // ! Reporting
   /**
    * [6n] (Report Cursor Position)
    * "Emit the cursor position as: ESC [\<r\>;\<c\>R Where \<r\> = cursor row and \<c\> = cursor column"
    */
   public const _CURSOR_REPORT_POSITION = '6n';
}
