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


trait Logging
{
   public Logger $Logger;

   // * Config
   // @ Levels
   public const LOG_DEBUG_LEVEL = 0;

   public const LOG_INFO_LEVEL = 1;
   public const LOG_NOTICE_LEVEL = 2;

   public const LOG_WARNING_LEVEL = 3;
   public const LOG_ERROR_LEVEL = 4;

   public const LOG_CRITICAL_LEVEL = 5;
   public const LOG_ALERT_LEVEL = 6;
   public const LOG_EMERGENCY_LEVEL = 7;
   // @ Sublevels
   public const LOG_CRITICAL_ERROR_LEVEL = 5;
   public const LOG_FATAL_ERROR_LEVEL = 6;
   public const LOG_CATASTROPHIC_ERROR_LEVEL = 7;

   // * Data
   // ...
   // * Meta
   // ...
}
