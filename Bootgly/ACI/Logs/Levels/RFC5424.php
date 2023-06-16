<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Levels;


use Bootgly\ACI\Logs\Levels;


class RFC5424 extends Levels
{
   public const LOG_EMERGENCY_LEVEL = 1;
   public const LOG_ALERT_LEVEL     = 2;
   public const LOG_CRITICAL_LEVEL  = 3;
   public const LOG_ERROR_LEVEL     = 4;
   public const LOG_WARNING_LEVEL   = 5;
   public const LOG_NOTICE_LEVEL    = 6;
   public const LOG_INFO_LEVEL      = 7;
   public const LOG_DEBUG_LEVEL     = 8;
}
