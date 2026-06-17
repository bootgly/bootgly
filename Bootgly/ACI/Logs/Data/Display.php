<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Data;


class Display
{
   // * Config
   // @ Verbosity of the default (Line) terminal output
   public const int NONE = 0;
   public const int MESSAGE = 1;
   public const int MESSAGE_WHEN = 2;
   public const int MESSAGE_WHEN_ID = 4;

   public static int $mode = self::MESSAGE;
}
