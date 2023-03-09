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
   // * Meta
   public const _CURSOR_HIDDEN = '?25l';
   public const _CURSOR_VISIBLE = '?25h';

   public const _CURSOR_BLINKING_DISABLED = '?12l';
   public const _CURSOR_BLINKING_ENABLED = '?12h';
}
