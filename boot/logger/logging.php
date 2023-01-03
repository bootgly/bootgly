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
   #public Logger $Logger = new Logger;

   // * Config
   // @ Level
   public const LOG_DEFAULT_LEVEL = 0;
   public const LOG_NOTICE_LEVEL = 1;
   public const LOG_INFO_LEVEL = 2;
   public const LOG_WARNING_LEVEL = 3;
   public const LOG_ERROR_LEVEL = 4;
   public const LOG_SUCCESS_LEVEL = 5;
   // * Data
   // * Meta


   protected function log ($message)
   {}
}
