<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Escaping\viewport;


trait Positioning
{
   // * Meta
   // ! Scrolling
   /**
   * [\<n\>S] (Scroll Up)
   * "Scroll text up by <n>. Also known as pan down, new lines fill in from the bottom of the screen"
   */
   public const _VIEWPORT_SCROLL_UP = 'S';
   /**
   * [\<n\>T] (Scroll Down) "Scroll down by <n>. Also known as pan up, new lines fill in from the top of the screen"
   */
   public const _VIEWPORT_SCROLL_DOWN = 'T';
}
