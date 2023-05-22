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


   // * Config
   // Mode Extensions
   public bool $SGT;
   public bool $URXVT;

   private Input $Input;
   private Output $Output;


   public function __construct (Input $Input, Output $Output)
   {
      // * Config
      $this->SGT = true;
      $this->URXVT = true;


      $this->Input = $Input;
      $this->Output = $Output;
   }

   public function reporting (bool $enabled)
   {
      if ($this->SGT) {
         $this->Output->escape(self::_MOUSE_SET_SGR_EXT_MODE);
      }

      if ($this->URXVT) {
         $this->Output->escape(self::_MOUSE_SET_URXVT_EXT_MODE);
      }

      match ($enabled) {
         true  => $this->Output->escape(self::_MOUSE_ENABLE_ALL_EVENT_REPORTING),
         false => $this->Output->escape(self::_MOUSE_DISABLE_ALL_EVENT_REPORTING)
      };

      if ($enabled === false) {
         $this->Output->escape(self::_MOUSE_UNSET_SGR_EXT_MODE);
         $this->Output->escape(self::_MOUSE_UNSET_URXVT_EXT_MODE);
      }
   }
}
