<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Logs;


use Bootgly\Logs;
use Bootgly\Logs\Levels\RFC5424;


class Logger extends Logs
{
   use Loggable;

   // * Config
   // @ Display
   public const DISPLAY_NONE = 0;
   public const DISPLAY_MESSAGE = 1;
   public const DISPLAY_MESSAGE_WHEN = 2;
   public const DISPLAY_MESSAGE_WHEN_ID = 4;
   public static $display = self::DISPLAY_MESSAGE;

   // * Data
   public string $channel;
   // @ Levels
   public static Levels $Levels;

   // * Meta
   // ...


   public function __construct (string $channel = '')
   {
      // * Data
      $this->channel = $channel;
      // @ Levels
      // ...static
   }
}

// @ Boot
Logger::$Levels = new RFC5424;
