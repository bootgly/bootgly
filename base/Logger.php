<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


class Logger implements Logs
{
   // * Config
   // @ Display
   public const DISPLAY_NONE = 0;
   public const DISPLAY_MESSAGE = 1;
   public const DISPLAY_MESSAGE_DATETIME = 2;
   public const DISPLAY_MESSAGE_DATETIME_LEVEL = 4;
   public static $display = self::DISPLAY_MESSAGE;


   public function log ($message)
   {}
}
