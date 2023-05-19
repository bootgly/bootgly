<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Input;


use Bootgly\__String\Escapeable;
use Bootgly\__String\Escapeable\mouse\Reportable;


class Mouse
{
   use Escapeable;
   use Reportable;


   public function report (bool $clicks, bool $movements)
   {
      $any = $clicks && $movements;

      match ($any) {
         true  => self::_START_ESCAPE . self::_MOUSE_ENABLE_ALL_EVENT_REPORTING,
         false => self::_START_ESCAPE . self::_MOUSE_DISABLE_ALL_EVENT_REPORTING
      };

      if ($any) {
         return;
      }

      match ($clicks) {
         true  => self::_START_ESCAPE . self::_MOUSE_ENABLE_CLICK_REPORTING,
         false => self::_START_ESCAPE . self::_MOUSE_DISABLE_CLICK_REPORTING
      };
   }
}
