<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\__String\Escapeable\Text;


use Bootgly\ABI\Data\__String\Escapeable\Text;


trait Modifiable
{
   use Text;


   // * Metadata
   /** [\<n\>@] (Insert Character)
    * Insert <n> spaces at the current cursor position, shifting all existing text to the right.
    * Text exiting the screen to the right is removed.
    */
   public const _TEXT_INSERT_CHARACTER = '@';

   /** [\<n\>P] (Delete Character)
    * Delete <n> characters at the current cursor position, shifting in space characters from the right edge of the screen.
    */
   public const _TEXT_DELETE_CHARACTER = 'P';

   /** [\<n\>X]  (Erase Character)
    * Erase <n> characters from the current cursor position by overwriting them with a space character.
    */
   public const _TEXT_ERASE_CHARACTER = 'X';


   /** [\<n\>L]  (Insert Line)
    * Inserts <n> lines into the buffer at the cursor position.
    * The line the cursor is on, and lines below it, will be shifted downwards.
    */
   public const _TEXT_INSERT_LINE = 'L';
   /** [\<n\>M]  (Delete Line)
    * Deletes <n> lines from the buffer, starting with the row the cursor is on.
    */
   public const _TEXT_DELETE_LINE = 'M';
   /** [\<n\>K] (Erase in Line)
    * Replace all text on the line with the cursor specified by \<n\> with space characters
    */
   public const _TEXT_ERASE_IN_LINE = 'K';

   /** [\<n\>J] (Erase in Display)
    * Replace all text in the current viewport/screen specified by \<n\> with space characters
    */
   public const _TEXT_ERASE_IN_DISPLAY = 'J';

   /**
    * Clear the lines below the current cursor position
    */
   public const _TEXT_ERASE_IN_DISPLAY_0 = '0J';
   /**
    * Clear the lines above the current cursor position
    */
   public const _TEXT_ERASE_IN_DISPLAY_1 = '1J';
   /**
    * Clear the entire screen / display
    */
   public const _TEXT_ERASE_IN_DISPLAY_2 = '2J';
   /**
    * Clear the entire screen / display and delete all lines saved in the scrollback buffer
    */
   public const _TEXT_ERASE_IN_DISPLAY_3 = '3J';

   /**
    * Clear the characters from cursor to the right
    */
   public const _TEXT_ERASE_IN_LINE_0 = '0K';
   /**
    * Clear the characters from cursor to the left
    */
   public const _TEXT_ERASE_IN_LINE_1 = '1K';
   /**
    * Clear the characteres from cursor to the left and right (entire line)
    */
   public const _TEXT_ERASE_IN_LINE_2 = '2K';
}
