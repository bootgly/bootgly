<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Reporting;


use Bootgly\__String\Escapeable\mouse\Reportable;

use Bootgly\CLI\Terminal\Reporting;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


class Mouse implements Reporting
{
   use Reportable;


   private Input $Input;
   private Output $Output;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;
   }

   public function reporting (bool $clicks, bool $movements)
   {
      // @ Configure Input mode
      $this->Output->escape(self::_MOUSE_SET_SGR_EXT_MODE);
      $this->Output->escape(self::_MOUSE_SET_URXVT_EXT_MODE);

      $any = $clicks && $movements;

      match ($any) {
         true  => $this->Output->escape(self::_MOUSE_ENABLE_ALL_EVENT_REPORTING),
         false => $this->Output->escape(self::_MOUSE_DISABLE_ALL_EVENT_REPORTING)
      };

      if ($any) {
         return;
      }

      match ($clicks) {
         true  => $this->Output->escape(self::_MOUSE_ENABLE_CLICK_REPORTING),
         false => $this->Output->escape(self::_MOUSE_DISABLE_CLICK_REPORTING)
      };
   }
}
