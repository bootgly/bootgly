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


trait Visualizing
{
   /**
    * [?25l] (Text Cursor Enable Mode Hide) "Hide the cursor"
    */
   public const _CURSOR_HIDDEN = '?25l';
   /**
    * [?25h] (Text Cursor Enable Mode Show) "Show the cursor"
    */
   public const _CURSOR_VISIBLE = '?25h';

   /**
    * [?12l] (Text Cursor Disable Blinking) "Stop blinking the cursor"
    */
   public const _CURSOR_BLINKING_DISABLED = '?12l';
   /**
    * [?12h] (Text Cursor Enable Blinking) "Start the cursor blinking"
    */
   public const _CURSOR_BLINKING_ENABLED = '?12h';
}
