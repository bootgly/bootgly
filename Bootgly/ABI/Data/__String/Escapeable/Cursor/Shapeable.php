<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\__String\Escapeable\Cursor;


use Bootgly\ABI\Data\__String\Escapeable\Cursor;


trait Shapeable
{
   use Cursor;


   /**
    * [0q] (User Shape) "Default cursor shape configured by the user"
    */
   public const _CURSOR_USER_SHAPE = '0 q';

   /**
    * [1q] (Blinking Block) "Blinking block cursor shape -> ▮"
    */
   public const _CURSOR_BLINKING_BLOCK_SHAPE = '1 q';
   /**
    * [2q] (Steady Block) "Steady block cursor shape -> ▮"
    */
   public const _CURSOR_STEADY_BLOCK_SHAPE = '2 q';

   /**
    * [3q] (Blinking Underline) "Blinking underline cursor shape -> _"
    */
   public const _CURSOR_BLINKING_UNDERLINE_SHAPE = '3 q';
   /**
    * [4q] (Steady Underline) "Steady underline cursor shape -> _"
    */
   public const _CURSOR_STEADY_UNDERLINE_SHAPE = '4 q';

   /**
    * [5q] (Blinking Bar) "Blinking bar cursor shape -> |"
    */
   public const _CURSOR_BLINKING_BAR_SHAPE = '5 q';
   /**
    * [6q] (Steady Bar) "Steady bar cursor shape -> |"
    */
   public const _CURSOR_STEADY_BAR_SHAPE = '6 q';
}
