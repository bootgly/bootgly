<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Logger;


use Bootgly\Logs;


class Logger implements Logs
{
   // * Config
   // @ Display
   public const DISPLAY_NONE = 0;
   public const DISPLAY_MESSAGE = 1;
   public const DISPLAY_MESSAGE_WHEN = 2;
   public const DISPLAY_MESSAGE_WHEN_ID = 4;
   public static $display = self::DISPLAY_MESSAGE;

   // * Data
   public string $channel;

   // * Meta
   // ...


   public function __construct (string $channel = '')
   {
      // * Data
      $this->channel = $channel;
   }

   public function log ($message)
   {
      error_log($message, 3, BOOTGLY_WORKABLES_BASE . '/workspace/logs/bootgly.log');
   }
}