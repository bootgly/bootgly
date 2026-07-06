<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Reporting;


use function explode;
use function function_exists;
use function intval;
use function pcntl_signal_dispatch;
use function strlen;
use function substr;
use Closure;

use Bootgly\ABI\Data\__String\Escapeable\Mouse\Reportable;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Mousestrokes;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Reporting;


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
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->SGT = true;
      $this->URXVT = true;
   }

   public function report (bool $enabled): void
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

   public function reporting (Closure $callback): void
   {
      $this->report(true);

      $Input = &$this->Input;
      $Input->configure(blocking: false, canonical: false, echo: false);

      while ($continue = true) {
         // ? Signal dispatching requires pcntl (absent on embedded runtimes)
         if (function_exists('pcntl_signal_dispatch') === true) {
            pcntl_signal_dispatch();
         }

         $input = $Input->read(1);

         // @ Check if the input have an SGR mouse trace ANSI escape code
         if ($input === "\033") {
            $input .= $Input->read(2);

            // @ Check if the code matches a mouse movement position
            if ($input === "\033[<") {
               // @@ Accumulate the SGR payload until its M/m terminator
               // A fixed-length read desyncs the parser when bursts deliver two
               // sequences back-to-back (the next \e would be swallowed).
               $input = '';
               while (strlen($input) < 24) {
                  $byte = $Input->read(1);
                  if ($byte === false || $byte === '') {
                     break;
                  }

                  $input .= $byte;

                  if ($byte === 'M' || $byte === 'm') {
                     break;
                  }
               }

               // ? Skip truncated payloads (no terminator)
               $characters = strlen($input);
               if ($characters < 6) {
                  continue;
               }
               $last = $input[$characters - 1];
               if ($last !== 'M' && $last !== 'm') {
                  continue;
               }
               $input = substr($input, 0, $characters - 1);

               $reports = explode(';', $input);
               $reports[] = $last;

               // @ Extracts mouse movement data from the code
               $col = intval($reports[1]);
               $row = intval($reports[2]);

               // ? Skip codes not mapped by the enum (terminal-specific modifiers)
               $action = Mousestrokes::tryFrom($reports[0]);
               if ($action === null) {
                  continue;
               }

               $clicking = match ($reports[3]) {
                  'M' => true,
                  'm' => false,
                  default => false
               };

               // @ Callback
               $continue = $callback($action, [$col, $row], $clicking);
            }
         }

         if ($continue === false) {
            break;
         }
      }

      $this->report(false);
      $Input->configure();
   }
}
