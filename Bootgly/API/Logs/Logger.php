<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Logs;


use Bootgly\API\Logs;


class Logger extends Logs
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

   public function log ($message) : bool
   {
      return error_log(
         message: $message,
         message_type: 3,
         destination: BOOTGLY_WORKABLES_BASE . '/workspace/logs/bootgly.log',
         additional_headers: null
      );
   }
}
