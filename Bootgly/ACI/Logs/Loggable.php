<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs;


trait Loggable
{
   public Logger $Logger;

   // * Config
   // @ Levels
   public const LOG_EMERGENCY_LEVEL = 1;
   public const LOG_ALERT_LEVEL     = 2;
   public const LOG_CRITICAL_LEVEL  = 3;
   public const LOG_ERROR_LEVEL     = 4;
   public const LOG_WARNING_LEVEL   = 5;
   public const LOG_NOTICE_LEVEL    = 6;
   public const LOG_INFO_LEVEL      = 7;
   public const LOG_DEBUG_LEVEL     = 8;

   // * Data
   // ...

   // * Metadata
   // ...

   public function log ($message) : bool
   {
      return error_log(
         message: $message,
         message_type: 3,
         destination: BOOTGLY_WORKING_BASE . '/workdata/logs/bootgly.log',
         additional_headers: null
      );
   }
}
